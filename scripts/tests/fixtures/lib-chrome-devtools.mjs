/**
 * Chrome headless falado pelo protocolo DevTools, sem dependências de npm.
 *
 * Extraído do teste de navegador das Atualizações Sistêmicas
 * (test-admin-atualizacoes-sistemicas-navegador.mjs), com o mesmo ciclo de
 * vida: grupo de processos próprio, Browser.close antes do SIGKILL e limpeza do
 * perfil tolerante ao ENOTEMPTY do runner Linux. Sem Chrome, quem chama falha;
 * não pula. CHROME_BIN aponta para um Chrome fora dos caminhos conhecidos.
 *
 * Sinais (#471): o Chrome sobe em grupo de processos próprio (`detached`), então o Ctrl-C do
 * terminal (SIGINT) e o SIGTERM de um runner que mata o job por tempo não o alcançam. Enquanto
 * algum Chrome aberto por esta lib estiver vivo, SIGINT e SIGTERM encerram TODOS eles (mata o
 * grupo, apaga o diretório temporário) e terminam o processo com 130 e 143. O tratamento sai
 * quando o último Chrome fecha em `fechar()`. Quem registrar um tratamento próprio desses sinais
 * depois de abrir o Chrome não chega a rodá-lo: o da lib termina o processo antes. A limpeza do
 * chamador entra em `process.on('exit')`, que `process.exit` ainda executa. SIGHUP (terminal
 * fechado) não é tratado: só SIGINT e SIGTERM, que são os que o issue #471 mediu e reproduziu.
 *
 * Não é teste (prefixo lib-): o guarda de cobertura do CI não o exige.
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

export function acharChrome() {
  const candidatos = [
    process.env.CHROME_BIN,
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ].filter(Boolean);
  return candidatos.find((candidato) => existsSync(candidato));
}

// Nunca liberar o chamador para limpar dados enquanto o processo ainda vive.
// Se nem SIGKILL produzir saída, o watchdog externo encerra o ensaio sem lock;
// não transformar esse caso em fechamento bem-sucedido por expiração de prazo.
export async function aguardarSaidaChrome(chrome, matar, toleranciaMs = 5000) {
  if (chrome.exitCode !== null || chrome.signalCode !== null) return;
  let timer;
  const saida = new Promise(resolver => chrome.once('exit', resolver));
  try {
    const terminou = await Promise.race([
      saida.then(() => true),
      new Promise(resolver => { timer = setTimeout(() => resolver(false), toleranciaMs); }),
    ]);
    if (!terminou) {
      matar();
      await saida;
    }
  } finally { clearTimeout(timer); }
}

// Cliente mínimo do protocolo DevTools sobre o WebSocket de uma página.
class DevTools {
  constructor(ws) {
    this.ws = ws;
    this.proximoId = 0;
    this.pendentes = new Map();
    this.ouvintes = new Map();
    ws.addEventListener('message', (evento) => {
      const mensagem = JSON.parse(evento.data);
      if (mensagem.id && this.pendentes.has(mensagem.id)) {
        const { resolver, rejeitar } = this.pendentes.get(mensagem.id);
        this.pendentes.delete(mensagem.id);
        if (mensagem.error) {
          rejeitar(new Error(mensagem.error.message));
        } else {
          resolver(mensagem.result);
        }
      } else if (mensagem.method) {
        const lista = this.ouvintes.get(mensagem.method) || [];
        this.ouvintes.set(mensagem.method, []);
        lista.forEach((ouvinte) => ouvinte(mensagem.params));
      }
    });
  }

  enviar(method, params = {}) {
    const id = ++this.proximoId;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolver, rejeitar) => this.pendentes.set(id, { resolver, rejeitar }));
  }

  umaVez(method) {
    return new Promise((resolver) => {
      this.ouvintes.set(method, [...(this.ouvintes.get(method) || []), resolver]);
    });
  }
}

// Encerramentos síncronos dos Chromes vivos deste processo, e os tratadores de sinal que os
// executam. Um registro só, e não um tratador por abertura: com dois Chromes, o primeiro tratador
// chamaria `process.exit` e o segundo nunca rodaria, deixando aquele Chrome órfão (#471).
const CODIGOS_DE_SINAL = { SIGINT: 130, SIGTERM: 143 };
const encerramentosAtivos = new Set();
const tratadoresDeSinal = new Map();

function aoReceberSinal(sinal) {
  for (const encerrar of encerramentosAtivos) encerrar();
  process.exit(CODIGOS_DE_SINAL[sinal]);
}

function registrarEncerramento(encerrar) {
  encerramentosAtivos.add(encerrar);
  if (tratadoresDeSinal.size === 0) {
    for (const sinal of Object.keys(CODIGOS_DE_SINAL)) {
      const tratador = () => aoReceberSinal(sinal);
      process.on(sinal, tratador);
      tratadoresDeSinal.set(sinal, tratador);
    }
  }
}

function removerEncerramento(encerrar) {
  encerramentosAtivos.delete(encerrar);
  if (encerramentosAtivos.size === 0) {
    for (const [sinal, tratador] of tratadoresDeSinal) process.removeListener(sinal, tratador);
    tratadoresDeSinal.clear();
  }
}

/**
 * Abre o Chrome e devolve a página controlada.
 *
 * `aoIniciar` recebe, logo depois do spawn, um encerramento SÍNCRONO (mata o
 * grupo de processos e apaga o diretório temporário). O tempo-limite de quem
 * chama deve usá-lo: se algo travar ainda dentro desta função, o `fechar`
 * devolvido nunca chega, e o Chrome (detached) ficaria órfão.
 *
 * @param {string} prefixo Prefixo do diretório temporário (perfil e arquivos).
 * @param {(encerrar: () => void) => void} [aoIniciar]
 * @returns {Promise<{dt: DevTools, tmp: string, versao: string, fechar: () => Promise<void>}>}
 */
export async function abrirChrome(prefixo, aoIniciar = () => {}) {
  const chromeBin = acharChrome();
  if (!chromeBin) {
    throw new Error('Chrome não encontrado — defina CHROME_BIN (o teste não pula sem navegador)');
  }

  const tmp = mkdtempSync(path.join(tmpdir(), prefixo));
  const argumentos = [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    // Nenhuma chamada de serviço do Google por conta própria: só file:// e 127.0.0.1.
    '--disable-background-networking',
    '--disable-component-update',
    '--disable-sync',
    '--remote-debugging-port=0',
    `--user-data-dir=${path.join(tmp, 'perfil')}`,
    '--window-size=1280,900',
    'about:blank',
  ];
  // No runner Linux o sandbox do Chrome esbarra no AppArmor de namespaces.
  if (process.platform === 'linux') {
    argumentos.unshift('--no-sandbox');
  }

  // Grupo de processos próprio: os filhos do Chrome saem junto quando for preciso matá-lo.
  const chrome = spawn(chromeBin, argumentos, { stdio: ['ignore', 'ignore', 'pipe'], detached: true });
  const matar = () => {
    try {
      process.kill(-chrome.pid, 'SIGKILL');
    } catch {
      chrome.kill('SIGKILL');
    }
  };
  // Filhos do Chrome ainda podem estar soltando o perfil (ENOTEMPTY no runner).
  const limparTmp = () => {
    try {
      rmSync(tmp, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    } catch (erro) {
      console.warn(`AVISO: não foi possível remover ${tmp}: ${erro.code || erro.message}`);
    }
  };

  const encerrarSincrono = () => {
    matar();
    limparTmp();
  };

  aoIniciar(encerrarSincrono);
  registrarEncerramento(encerrarSincrono);

  let ws;
  let enderecoNavegador;
  const fechar = async () => {
    if (ws) {
      ws.close();
    }
    const saiu = () => chrome.exitCode !== null || chrome.signalCode !== null;
    if (!saiu()) {

      if (enderecoNavegador) {
        try {
          const navegador = new WebSocket(enderecoNavegador);
          // Com prazo: um Chrome travado não pode segurar o fechamento.
          await new Promise((resolver, rejeitar) => {
            navegador.addEventListener('open', resolver, { once: true });
            navegador.addEventListener('error', rejeitar, { once: true });
            setTimeout(() => rejeitar(new Error('DevTools não abriu em 3s')), 3000);
          });
          navegador.send(JSON.stringify({ id: 1, method: 'Browser.close' }));
        } catch {
          // Sem DevTools, cai no SIGKILL abaixo.
        }
      }
      await aguardarSaidaChrome(chrome, matar);
    }
    limparTmp();
    removerEncerramento(encerrarSincrono);
  };

  try {
    enderecoNavegador = await new Promise((resolver, rejeitar) => {
      let saida = '';
      chrome.stderr.on('data', (pedaco) => {
        saida += pedaco;
        const achado = saida.match(/DevTools listening on (ws:\/\/\S+)/);
        if (achado) {
          resolver(achado[1]);
        }
      });
      chrome.on('exit', (codigo) => rejeitar(new Error(`Chrome saiu (código ${codigo}): ${saida.slice(-800)}`)));
    });

    const porta = new URL(enderecoNavegador).port;
    const versao = await (await fetch(`http://127.0.0.1:${porta}/json/version`)).json();
    // A aba inicial pode ainda não estar listada logo que o DevTools abre.
    let pagina;
    for (let tentativa = 0; tentativa < 50 && !pagina; tentativa += 1) {
      const alvos = await (await fetch(`http://127.0.0.1:${porta}/json/list`)).json();
      pagina = alvos.find((alvo) => alvo.type === 'page');
      if (!pagina) {
        await new Promise((resolver) => setTimeout(resolver, 100));
      }
    }
    if (!pagina) {
      throw new Error('o Chrome não listou nenhuma página em 5s');
    }

    ws = new WebSocket(pagina.webSocketDebuggerUrl);
    await new Promise((resolver, rejeitar) => {
      ws.addEventListener('open', resolver, { once: true });
      ws.addEventListener('error', rejeitar, { once: true });
    });
    const dt = new DevTools(ws);
    await dt.enviar('Page.enable');

    return { dt, tmp, versao: versao.Browser, fechar };
  } catch (erro) {
    await fechar();
    throw erro;
  }
}
