/**
 * Chrome headless falado pelo protocolo DevTools, sem dependências de npm.
 *
 * Extraído do teste de navegador das Atualizações Sistêmicas
 * (test-admin-atualizacoes-sistemicas-navegador.mjs), com o mesmo ciclo de
 * vida: grupo de processos próprio, Browser.close antes do SIGKILL e limpeza do
 * perfil tolerante ao ENOTEMPTY do runner Linux. Sem Chrome, quem chama falha;
 * não pula. CHROME_BIN aponta para um Chrome fora dos caminhos conhecidos.
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

/**
 * Abre o Chrome e devolve a página controlada.
 *
 * @param {string} prefixo Prefixo do diretório temporário (perfil e arquivos).
 * @returns {Promise<{dt: DevTools, tmp: string, versao: string, fechar: () => Promise<void>}>}
 */
export async function abrirChrome(prefixo) {
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

  let ws;
  let enderecoNavegador;
  const fechar = async () => {
    if (ws) {
      ws.close();
    }
    const saiu = () => chrome.exitCode !== null || chrome.signalCode !== null;
    if (!saiu()) {
      const saida = new Promise((resolver) => chrome.once('exit', resolver));
      if (enderecoNavegador) {
        try {
          const navegador = new WebSocket(enderecoNavegador);
          await new Promise((resolver, rejeitar) => {
            navegador.addEventListener('open', resolver, { once: true });
            navegador.addEventListener('error', rejeitar, { once: true });
          });
          navegador.send(JSON.stringify({ id: 1, method: 'Browser.close' }));
        } catch {
          // Sem DevTools, cai no SIGKILL abaixo.
        }
      }
      const tempo = await Promise.race([saida.then(() => 'saiu'), new Promise((r) => setTimeout(() => r('tempo'), 5000))]);
      if ('tempo' === tempo) {
        matar();
        await Promise.race([saida, new Promise((r) => setTimeout(r, 2000))]);
      }
    }
    limparTmp();
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
