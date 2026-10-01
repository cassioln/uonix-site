#!/usr/bin/env node
/**
 * Executa num Chrome headless de verdade o JavaScript das "Atualizações
 * Sistêmicas" (card de manutenção, mu-plugins/uonix-admin/39-admin-editor-dashboard.php).
 *
 * O teste PHP (test-admin-atualizacoes-pendentes.php) só lê o HTML e procura
 * trechos do JS como texto. Dois defeitos MÉDIOs passaram por essa lacuna no
 * PR #352 — altura travada em 0 com o widget recolhido, e página com nome longo
 * maior que a altura travada, cortando a paginação — e só apareceram no Chrome.
 * Aqui o HTML vem do PHP real (fixtures/atualizacoes-sistemicas-cenario.php) e o
 * comportamento é medido no layout: altura fixa entre abas e páginas, nada
 * cortado, paginação no fundo, reset de página ao trocar de aba, teclado.
 *
 * Sem dependências: o Chrome já vem na imagem ubuntu-latest e o Node 22 tem
 * WebSocket nativo, então o protocolo DevTools é falado direto. Sem Chrome o
 * teste FALHA, não pula — informe CHROME_BIN se ele estiver fora dos caminhos
 * conhecidos. PHP_BIN escolhe o PHP usado para gerar os cenários.
 */

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const gerador = path.join(raiz, 'scripts/tests/fixtures/atualizacoes-sistemicas-cenario.php');
const php = process.env.PHP_BIN || 'php';

let falhas = 0;

function checar(condicao, mensagem, detalhe) {
  if (!condicao) {
    falhas += 1;
    console.error(`FAIL: ${mensagem}${detalhe === undefined ? '' : ` — ${JSON.stringify(detalhe)}`}`);
  }
}

function acharChrome() {
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

// Roda dentro da página: atalhos de leitura e navegação do bloco.
function ajudantes() {
  const w = document.getElementById('uox-atualizacoes-sistemicas');
  const caixa = w && w.querySelector('.uox-atualizacoes-paineis');
  const visivel = (el) => el.getClientRects().length > 0;
  const quadros = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  const painel = (chave) => w.querySelector(`[data-uox-painel="${chave}"]`);
  const aba = (chave) => w.querySelector(`[data-uox-aba="${chave}"]`);
  const painelVisivel = () => [...w.querySelectorAll('[data-uox-painel]')].find(visivel);
  const estado = () => {
    const p = painelVisivel();
    const barra = p && p.querySelector('.uox-atualizacoes-paginacao');
    const itens = p ? [...p.querySelectorAll('li')] : [];
    return {
      aba: w.querySelector('.uox-atualizacoes-tab.is-active')?.dataset.uoxAba ?? null,
      painel: p?.dataset.uoxPainel ?? null,
      paineisVisiveis: [...w.querySelectorAll('[data-uox-painel]')].filter(visivel).length,
      pagina: p ? Number(p.dataset.uoxPaginaAtual) : null,
      paginas: p ? Number(p.dataset.uoxPaginas) : null,
      itensVisiveis: itens.filter(visivel).length,
      itensSemHidden: itens.filter((li) => !li.hidden).length,
      bloco: w.offsetHeight,
      travada: caixa.style.height,
      corta: caixa.scrollHeight > caixa.clientHeight + 1,
      folgaBarra: barra ? Math.round(caixa.getBoundingClientRect().bottom - barra.getBoundingClientRect().bottom) : null,
      anteriorDesabilitado: barra ? barra.querySelector('[data-uox-pagina-acao="anterior"]').disabled : null,
      proximaDesabilitado: barra ? barra.querySelector('[data-uox-pagina-acao="proxima"]').disabled : null,
      status: barra ? barra.querySelector('[data-uox-pagina-status]').textContent : null,
      foco: document.activeElement?.dataset?.uoxAba ?? null,
      tabindex: [...w.querySelectorAll('[data-uox-aba]')].map((b) => b.tabIndex).join(','),
    };
  };
  const proxima = () => painelVisivel().querySelector('[data-uox-pagina-acao="proxima"]').click();
  // Todas as abas e todas as páginas, na ordem; termina de volta na primeira aba.
  const percorrer = () => {
    const passos = [];
    const abas = [...w.querySelectorAll('[data-uox-aba]')];
    for (const botao of abas) {
      botao.click();
      const p = painel(botao.dataset.uoxAba);
      while (Number(p.dataset.uoxPaginaAtual) > 1) {
        p.querySelector('[data-uox-pagina-acao="anterior"]').click();
      }
      for (let pagina = 1; pagina <= Number(p.dataset.uoxPaginas); pagina += 1) {
        if (pagina > 1) {
          p.querySelector('[data-uox-pagina-acao="proxima"]').click();
        }
        passos.push({ passo: `${botao.dataset.uoxAba} p${pagina}`, ...estado() });
      }
    }
    abas[0].click();
    return passos;
  };
  return { w, caixa, quadros, painel, aba, estado, proxima, percorrer };
}

// Mesma altura em todas as abas e páginas, nada cortado, nada escondido à vista.
function checarLayoutEstavel(passos, cenario) {
  const alturas = [...new Set(passos.map((p) => p.bloco))];
  checar(alturas.length === 1, `${cenario}: o bloco deve ter a mesma altura em todas as abas e páginas`, alturas);
  const cortados = passos.filter((p) => p.corta).map((p) => p.passo);
  checar(cortados.length === 0, `${cenario}: nenhuma página pode passar da altura travada`, cortados);
  const comHiddenVisivel = passos.filter((p) => p.itensVisiveis !== p.itensSemHidden).map((p) => p.passo);
  checar(comHiddenVisivel.length === 0, `${cenario}: item com [hidden] não pode aparecer`, comHiddenVisivel);
  const multiplos = passos.filter((p) => p.paineisVisiveis !== 1).map((p) => p.passo);
  checar(multiplos.length === 0, `${cenario}: exatamente um painel visível por vez`, multiplos);
  const longe = passos.filter((p) => p.folgaBarra !== null && Math.abs(p.folgaBarra) > 1).map((p) => `${p.passo}=${p.folgaBarra}`);
  checar(longe.length === 0, `${cenario}: a paginação deve ficar colada no fundo da área de altura fixa`, longe);
  const demais = passos.filter((p) => p.itensVisiveis > 10).map((p) => p.passo);
  checar(demais.length === 0, `${cenario}: no máximo 10 itens por página`, demais);
  // Pontas e status em todo passo, inclusive páginas 1 alcançadas pelo JS (voltar
  // ou trocar de aba), não só o estado que o servidor renderizou.
  const pontasErradas = passos
    .filter((p) => p.status !== null)
    .filter((p) => p.anteriorDesabilitado !== (p.pagina === 1)
      || p.proximaDesabilitado !== (p.pagina === p.paginas)
      || p.status !== `Página ${p.pagina} de ${p.paginas}`)
    .map((p) => p.passo);
  checar(pontasErradas.length === 0, `${cenario}: "Anterior"/"Próxima" desabilitam só nas pontas e o status acompanha a página`, pontasErradas);
}

const chromeBin = acharChrome();
if (!chromeBin) {
  console.error('FAIL: Chrome não encontrado — defina CHROME_BIN (o teste não pula sem navegador)');
  process.exit(1);
}

const tmp = mkdtempSync(path.join(tmpdir(), 'uox-atualizacoes-'));
const perfil = path.join(tmp, 'perfil');
const cenarios = {};
for (const nome of ['vazio', 'sete', 'paginado', 'fechado', 'longo', 'so-plugins']) {
  cenarios[nome] = path.join(tmp, `${nome}.html`);
  writeFileSync(cenarios[nome], execFileSync(php, [gerador, nome]));
}

const argumentos = [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  '--disable-extensions',
  '--remote-debugging-port=0',
  `--user-data-dir=${perfil}`,
  '--window-size=1200,1600',
  'about:blank',
];
// No runner Linux o sandbox do Chrome esbarra no AppArmor de namespaces.
if (process.platform === 'linux') {
  argumentos.unshift('--no-sandbox');
}

const chrome = spawn(chromeBin, argumentos, { stdio: ['ignore', 'ignore', 'pipe'] });
const limite = setTimeout(() => {
  console.error('FAIL: teste de navegador passou de 120s');
  chrome.kill('SIGKILL');
  process.exit(1);
}, 120000);

let ws;

try {
  const enderecoNavegador = await new Promise((resolver, rejeitar) => {
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
  const alvos = await (await fetch(`http://127.0.0.1:${porta}/json/list`)).json();
  const pagina = alvos.find((alvo) => alvo.type === 'page');

  ws = new WebSocket(pagina.webSocketDebuggerUrl);
  await new Promise((resolver, rejeitar) => {
    ws.addEventListener('open', resolver, { once: true });
    ws.addEventListener('error', rejeitar, { once: true });
  });
  const dt = new DevTools(ws);
  await dt.enviar('Page.enable');
  await dt.enviar('Emulation.setDeviceMetricsOverride', { width: 1200, height: 1600, deviceScaleFactor: 1, mobile: false });

  const abrir = async (nome) => {
    const carregou = dt.umaVez('Page.loadEventFired');
    await dt.enviar('Page.navigate', { url: pathToFileURL(cenarios[nome]).href });
    await carregou;
  };
  const naPagina = async (funcao, ...args) => {
    const resposta = await dt.enviar('Runtime.evaluate', {
      expression: `(async () => { const u = (${ajudantes})(); return (${funcao})(u, ...${JSON.stringify(args)}); })()`,
      awaitPromise: true,
      returnByValue: true,
    });
    if (resposta.exceptionDetails) {
      throw new Error(resposta.exceptionDetails.exception?.description || resposta.exceptionDetails.text);
    }
    return resposta.result.value;
  };
  const tecla = async (key, keyCode) => {
    for (const type of ['keyDown', 'keyUp']) {
      await dt.enviar('Input.dispatchKeyEvent', { type, key, code: key, windowsVirtualKeyCode: keyCode });
    }
  };

  // Nenhuma pendência: o bloco inteiro não é renderizado.
  await abrir('vazio');
  checar(await naPagina((u) => u.w === null), 'vazio: sem pendências o bloco não deve existir na página');

  // 7 itens, página única.
  await abrir('sete');
  const sete = await naPagina((u) => ({ inicio: u.estado(), passos: u.percorrer() }));
  checar(sete.inicio.aba === 'todos' && sete.inicio.painel === 'todos', 'sete: Todos abre ativa e visível', sete.inicio);
  checar(sete.inicio.itensVisiveis === 7 && sete.inicio.folgaBarra === null, 'sete: 7 itens à vista e sem paginação', sete.inicio);
  checarLayoutEstavel(sete.passos, 'sete');

  // 27 itens em 3 páginas: altura, paginação no fundo, pontas, reset, teclado.
  await abrir('paginado');
  const pag = await naPagina((u) => ({ inicio: u.estado(), passos: u.percorrer() }));
  checar(
    pag.inicio.pagina === 1 && pag.inicio.paginas === 3 && pag.inicio.status === 'Página 1 de 3'
      && pag.inicio.anteriorDesabilitado === true && pag.inicio.proximaDesabilitado === false,
    'paginado: abre na página 1 de 3, com "Anterior" desabilitado',
    pag.inicio,
  );
  checar(pag.inicio.itensVisiveis === 10, 'paginado: a página 1 mostra 10 itens', pag.inicio.itensVisiveis);
  checarLayoutEstavel(pag.passos, 'paginado');
  const ultimas = pag.passos.filter((p) => p.paginas > 1 && p.pagina === p.paginas);
  checar(
    ultimas.length === 2 && ultimas.every((p) => p.proximaDesabilitado && !p.anteriorDesabilitado),
    'paginado: na última página "Próxima" desabilita e "Anterior" habilita',
    ultimas.map((p) => p.passo),
  );

  const reset = await naPagina((u) => {
    const r = {};
    u.proxima(); u.proxima();
    r.todosNaPagina3 = u.estado().pagina;
    u.aba('plugins').click();
    r.aoTrocarParaPlugins = u.estado().pagina;
    u.proxima();
    r.pluginsNaPagina2 = u.estado().pagina;
    u.aba('plugins').click();
    r.cliqueNaAbaAtiva = u.estado().pagina;
    u.aba('todos').click();
    r.voltaParaTodos = u.estado().pagina;
    r.pluginsResetado = Number(u.painel('plugins').dataset.uoxPaginaAtual);
    return r;
  });
  checar(reset.todosNaPagina3 === 3 && reset.aoTrocarParaPlugins === 1, 'paginado: trocar de aba volta para a página 1', reset);
  checar(reset.pluginsNaPagina2 === 2 && reset.cliqueNaAbaAtiva === 2, 'paginado: clicar na aba já ativa não reseta a página', reset);
  checar(reset.voltaParaTodos === 1 && reset.pluginsResetado === 1, 'paginado: trocar de aba reseta todas as abas', reset);

  // Teclado no padrão ARIA de abas, com eventos de tecla reais do Chrome.
  await naPagina((u) => u.aba('todos').focus());
  const sequencia = [['ArrowRight', 39, 'plugins'], ['ArrowRight', 39, 'core'], ['End', 35, 'temas'], ['ArrowRight', 39, 'todos'], ['ArrowLeft', 37, 'temas'], ['Home', 36, 'todos']];
  for (const [key, codigo, esperada] of sequencia) {
    await tecla(key, codigo);
    const e = await naPagina((u) => u.estado());
    const indice = ['todos', 'plugins', 'core', 'temas'].indexOf(esperada);
    const tabindex = ['-1', '-1', '-1', '-1'].map((v, i) => (i === indice ? '0' : v)).join(',');
    checar(
      e.foco === esperada && e.aba === esperada && e.painel === esperada && e.tabindex === tabindex,
      `teclado: ${key} deve levar foco, aba ativa e painel para "${esperada}" com tabindex itinerante`,
      { foco: e.foco, aba: e.aba, painel: e.painel, tabindex: e.tabindex },
    );
  }

  // Card estreitado sem resize da janela (widget arrastado de coluna): remede.
  const larguras = await naPagina(async (u) => {
    const moldura = document.getElementById('moldura');
    const original = u.caixa.style.height;
    moldura.style.width = '220px';
    await u.quadros();
    const estreito = { travada: u.caixa.style.height, passos: u.percorrer() };
    moldura.style.width = '520px';
    await u.quadros();
    return { original, estreito, devolvida: u.caixa.style.height };
  });
  checarLayoutEstavel(larguras.estreito.passos, 'estreitado sem resize');
  checar(larguras.estreito.travada !== larguras.original, 'estreitado sem resize: a altura travada deve ser remedida', larguras);
  checar(larguras.devolvida === larguras.original, 'alargado de volta: a trava deve encolher para a altura original', larguras);

  // Widget recolhido no carregamento: não trava em 0; ao abrir, mede.
  await abrir('fechado');
  const fechado = await naPagina(async (u) => {
    const antes = u.caixa.style.height;
    document.getElementById('moldura').style.display = '';
    await u.quadros();
    return { antes, depois: u.caixa.style.height, passos: u.percorrer() };
  });
  checar(fechado.antes === '', 'fechado: com o widget recolhido a altura não pode ser travada (nem em 0)', fechado.antes);
  checar(/^[1-9]\d*px$/.test(fechado.depois), 'fechado: ao abrir o widget, a altura deve ser medida e travada', fechado.depois);
  checarLayoutEstavel(fechado.passos, 'fechado');

  // Nome longo: uma página de categoria fica maior que a página 1 de Todos.
  await abrir('longo');
  checarLayoutEstavel(await naPagina((u) => u.percorrer()), 'longo');

  // Uma categoria só: sem a aba Todos, a aba única abre ativa.
  await abrir('so-plugins');
  const so = await naPagina((u) => ({
    abas: [...u.w.querySelectorAll('[data-uox-aba]')].map((b) => b.dataset.uoxAba),
    inicio: u.estado(),
    passos: u.percorrer(),
  }));
  checar(so.abas.join(',') === 'plugins', 'so-plugins: a única aba deve ser Plugins', so.abas);
  checar(so.inicio.aba === 'plugins' && so.inicio.painel === 'plugins' && so.inicio.paginas === 2, 'so-plugins: Plugins abre ativa, com 2 páginas', so.inicio);
  checarLayoutEstavel(so.passos, 'so-plugins');

  if (falhas === 0) {
    console.log(`PASS: atualizações sistêmicas no navegador (${versao.Browser})`);
  }
} catch (erro) {
  falhas += 1;
  console.error(`FAIL: ${erro.stack || erro}`);
} finally {
  clearTimeout(limite);
  if (ws) {
    ws.close();
  }
  // O perfil só sai depois que o Chrome soltar os arquivos dele.
  await new Promise((resolver) => {
    if (chrome.exitCode !== null || chrome.signalCode !== null) {
      resolver();
      return;
    }
    chrome.once('exit', resolver);
    chrome.kill('SIGKILL');
    setTimeout(resolver, 5000);
  });
  rmSync(tmp, { recursive: true, force: true });
}

process.exit(falhas === 0 ? 0 : 1);
