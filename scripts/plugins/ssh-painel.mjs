#!/usr/bin/env node
// Contrato do painel Locaweb. Nunca recebe senha, cookie ou token.
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { spawn, execFile } from 'node:child_process';
import { prepararRuntime, abrirChromePipe } from './ssh-painel-chrome.mjs';
export const URL_PAINEL = 'https://painelhospedagem.locaweb.com.br/tools/73381366/ssh';

export function analisarTela(tela) {
  const url = new URL(tela.url);
  if (url.origin === 'https://login-new.locaweb.com.br' || url.origin === 'https://myaccount.locaweb.com.br') {
    throw new Error('autenticação manual necessária no perfil dedicado');
  }
  if (url.href !== URL_PAINEL) throw new Error('alvo do painel divergente');
  const linhas = tela.texto.split('\n').map(s => s.trim()).filter(Boolean);
  if (!linhas.includes('uonix.com.br')) throw new Error('identidade da hospedagem divergente');
  const estados = linhas.flatMap((s, n) => s === 'Status' ? [linhas[n + 1]] : []);
  if (estados.length !== 1 || !['Habilitado', 'Desabilitado'].includes(estados[0])) {
    throw new Error('estado SSH ausente ou ambíguo');
  }
  const habilitado = estados[0] === 'Habilitado';
  const acao = habilitado ? 'Desabilitar' : 'Habilitar';
  if (tela.botoes.filter(s => s === acao).length !== 1) throw new Error('controle SSH ausente ou ambíguo');
  if (tela.modal && (tela.modal.titulo !== `${acao} acesso SSH`
      || tela.modal.botoes.filter(s => s === acao).length !== 1
      || !tela.modal.botoes.includes('Cancelar') || !tela.modal.texto.includes('3 horas'))) {
    throw new Error('modal SSH inesperado');
  }
  return { status: habilitado ? 'habilitado' : 'desabilitado', acao, modal: Boolean(tela.modal) };
}

export function expressaoTela() {
  return `(() => {
    const url = location.href;
    // Validação e leitura no mesmo turno JS: nenhuma navegação entre dois awaits.
    if (url !== ${JSON.stringify(URL_PAINEL)}) return { url };
    const visivel = e => Boolean(e.getClientRects().length);
    const modal = [...document.querySelectorAll('[role=dialog],dialog[open]')].find(visivel);
    const texto = e => (e.innerText || '').trim();
    return { url, texto: document.body?.innerText || '',
      botoes: [...document.querySelectorAll('button')].filter(e => visivel(e) && !modal?.contains(e)).map(texto),
      modal: modal ? { titulo: texto(modal).split('\\n').map(s => s.trim()).find(Boolean),
        texto: texto(modal), botoes: [...modal.querySelectorAll('button')].filter(visivel).map(texto) } : null };
  })()`;
}

export async function lerTela(chrome) {
  return chrome.avaliar(expressaoTela());
}

export async function aguardarTela(chrome, limiteMs = 30000, { loginManual = false, signal } = {}) {
  const fim = Date.now() + limiteMs;
  let viuLogin = false, retomouAlvo = false, retorno = '', inicioRetorno = 0;
  while (Date.now() < fim) {
    signal?.throwIfAborted();
    const endereco = await chrome.avaliar('location.href');
    const login = typeof endereco === 'string' && /^https:\/\/(login-new|myaccount)\.locaweb\.com\.br\//.test(endereco);
    if (login && !loginManual) throw new Error('autenticação manual necessária no perfil dedicado');
    if (login) viuLogin = true;
    const url = new URL(endereco);
    // O SSO pode retornar à raiz antes de concluir. Só no login supervisionado,
    // sem ler esse documento nem usar seus parâmetros como destino de navegação.
    if (loginManual && viuLogin && !retomouAlvo && url.origin === new URL(URL_PAINEL).origin
        && url.pathname === '/' && !url.hash && !url.username && !url.password) {
      if (retorno !== endereco) { retorno = endereco; inicioRetorno = Date.now(); }
      if (Date.now() - inicioRetorno >= 2000) {
        retomouAlvo = true;
        await chrome.comando('Page.navigate', { url: URL_PAINEL });
      }
      await new Promise(r => setTimeout(r, 250));
      continue;
    }
    retorno = ''; inicioRetorno = 0;
    if (endereco !== URL_PAINEL && endereco !== 'about:blank' && endereco !== 'nullblank' && !login) {
      throw new Error('alvo do painel divergente');
    }
    if (endereco !== URL_PAINEL) { await new Promise(r => setTimeout(r, 250)); continue; }
    const tela = await lerTela(chrome);
    if (tela.url !== URL_PAINEL) return analisarTela(tela);
    // A identidade é carregada separadamente do cartão SSH. Nunca qualificar
    // apenas pelo status ou relaxar a comparação quando o breadcrumb demora.
    if (tela.texto.split('\n').some(s => s.trim() === 'uonix.com.br') && tela.texto.includes('Status')) {
      return analisarTela(tela);
    }
    await new Promise(r => setTimeout(r, 250));
  }
  throw new Error(loginManual ? 'login manual não concluído no prazo' : 'estado do painel não confirmado no prazo');
}

async function main(args) {
  if (args.length === 1 && args[0] === '--help') {
    console.log('Uso: node scripts/plugins/ssh-painel.mjs login|status|habilitar --ciclo=ID --confirmacao="HABILITAR SSH 73381366 uonix.com.br"\nlogin: perfil visível; status: leitura headless; habilitar: tentativa única por ciclo, sem fechamento ou renovação.');
    return;
  }
  const comando = args[0];
  let ciclo, confirmacao;
  if (comando === 'habilitar') {
    if (args.length !== 3 || !args[1].startsWith('--ciclo=') || !args[2].startsWith('--confirmacao=')) throw new Error('opções inválidas');
    ciclo = args[1].slice('--ciclo='.length); confirmacao = args[2].slice('--confirmacao='.length);
    const { CONFIRMACAO } = await import('./ssh-abertura.mjs');
    if (confirmacao !== CONFIRMACAO || !/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/.test(ciclo)) throw new Error('autorização/ciclo inválido');
  } else if (args.length !== 1 || !['login', 'status'].includes(comando)) throw new Error('comando inválido');
  const home = process.env.HERMES_HOME;
  if (home !== '/Users/cassio/.hermes/profiles/rocky') throw new Error('executor exige profile Rocky explícito');
  const runtime = prepararRuntime(home);
  const controle = new AbortController();
  let chrome, liberarSeguro = true;
  const cancelar = () => {
    controle.abort(new Error('execução cancelada'));
    chrome?.fechar().catch(() => {});
  };
  const sinais = ['SIGTERM', 'SIGINT', 'SIGHUP'];
  for (const sinal of sinais) process.once(sinal, cancelar);
  const timer = setTimeout(cancelar, comando === 'login' ? 620000 : 240000);
  try {
    chrome = await abrirChromePipe(runtime, { visivel: args[0] === 'login' });
    controle.signal.throwIfAborted();
    await chrome.comando('Page.navigate', { url: URL_PAINEL });
    if (args[0] === 'login') console.log('LOGIN_MANUAL: use a janela Chrome dedicada; não envie senha/código no chat. Nenhum botão SSH será acionado.');
    if (comando === 'habilitar') {
      const { habilitarJanela, expressaoHabilitar, aguardarConfirmacao, recarregarPainel } = await import('./ssh-abertura.mjs');
      const resultado = await habilitarJanela({ base: runtime.base, ciclo, confirmacao, signal: controle.signal,
        estado: () => aguardarTela(chrome, 30000, { signal: controle.signal }),
        acionar: async fase => {
          controle.signal.throwIfAborted();
          if (fase === 'confirmar') {
            const fim = Date.now() + 5000;
            let pronto = false;
            while (Date.now() < fim) {
              controle.signal.throwIfAborted();
              if (analisarTela(await lerTela(chrome)).modal) { pronto = true; break; }
              await new Promise(r => setTimeout(r, 100));
            }
            if (!pronto) throw new Error('modal não apareceu');
          }
          controle.signal.throwIfAborted();
          if (await chrome.avaliar(expressaoHabilitar(fase)) !== true) throw new Error('ação não confirmada');
          if (fase === 'confirmar') await aguardarConfirmacao(chrome, controle.signal);
        },
        recarregar: () => recarregarPainel(chrome, controle.signal),
        sondar: () => sondarSSH(controle.signal),
      });
      console.log(JSON.stringify(resultado));
    } else {
      const estado = await aguardarTela(chrome, comando === 'login' ? 600000 : 30000, { loginManual: comando === 'login', signal: controle.signal });
      console.log(JSON.stringify({ alvo: '73381366', dominio: 'uonix.com.br', ...estado, alterou_ssh: false }));
    }
  } catch (e) {
    if (e.preservarTrava) liberarSeguro = false;
    throw e;
  } finally {
    clearTimeout(timer);
    for (const sinal of sinais) process.removeListener(sinal, cancelar);
    if (chrome) await chrome.fechar();
    if (liberarSeguro) runtime.liberar();
  }
}

export function sondarSSH(signal, { script = fileURLToPath(new URL('./ssh-sonda.sh', import.meta.url)), limiteMs = 45000, executarPS = execFile } = {}) {
  signal.throwIfAborted();
  return new Promise((resolve, reject) => {

    const env = Object.fromEntries(Object.entries(process.env).filter(([k]) => ['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL', 'HERMES_HOME'].includes(k)));
    const filho = spawn('/bin/bash', [script], { env, stdio: ['ignore', 'pipe', 'ignore'], detached: true });
    let saida = '';
    filho.stdout.on('data', b => { if (saida.length < 1024) saida += b.toString(); });
    let forcar;
    const sinalizar = sinal => { try { process.kill(-filho.pid, sinal); } catch { filho.kill(sinal); } };
    const cancelar = () => {
      sinalizar('SIGTERM');
      forcar ||= setTimeout(() => sinalizar('SIGKILL'), 2000);
    };
    const timer = setTimeout(cancelar, limiteMs);
    signal.addEventListener('abort', cancelar, { once: true });
    const limpar = () => { clearTimeout(timer); clearTimeout(forcar); signal.removeEventListener('abort', cancelar); };
    filho.once('error', () => { limpar(); resolve(false); });
    filho.once('exit', codigo => {
      limpar();
      let residual = false;
      const fim = Date.now() + 3000;
      const falhaLimpeza = () => {
        sinalizar('SIGKILL');
        reject(Object.assign(new Error('limpeza da sonda não confirmada; trava preservada'), { preservarTrava: true }));
      };
      const conferir = () => executarPS('ps', ['-Ao', 'pgid=,stat='], { timeout: 2000 }, (erro, texto) => {
        if (erro) { falhaLimpeza(); return; }
        const ativo = texto.split('\n').some(linha => {
          const [grupo, estado] = linha.trim().split(/\s+/);
          return Number(grupo) === filho.pid && estado && !estado.startsWith('Z');
        });
        if (ativo) {
          residual = true; sinalizar('SIGKILL');
          if (Date.now() >= fim) { falhaLimpeza(); return; }
          setTimeout(conferir, 50); return;
        }
        resolve(!residual && !signal.aborted && codigo === 0 && saida.trim() === 'SSH_AUTENTICADO_OK');
      });
      conferir();
    });
  });
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main(process.argv.slice(2)).catch(() => {
    console.error('Execução não concluída: confira sessão/login, runtime privado, alvo e recibo do ciclo. Não repita uma abertura pendente.');
    process.exitCode = 30;
  });
}
