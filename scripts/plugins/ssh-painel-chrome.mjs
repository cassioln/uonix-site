// Chrome exclusivo por pipe: nenhuma porta CDP TCP, cookie ou senha exportada.
import fs from 'node:fs';
import path from 'node:path';
import { spawn, execFile } from 'node:child_process';
import { randomUUID } from 'node:crypto';

function pastaPrivada(diretorio, criar = false) {
  const alvo = path.resolve(diretorio);
  let atual = path.parse(alvo).root;
  for (const parte of alvo.slice(atual.length).split(path.sep).filter(Boolean)) {
    atual = path.join(atual, parte);
    if (fs.existsSync(atual) && fs.lstatSync(atual).isSymbolicLink()) throw new Error('symlink no runtime recusado');
  }
  if (criar && !fs.existsSync(alvo)) fs.mkdirSync(alvo, { mode: 0o700 });
  const s = fs.lstatSync(alvo);
  if (!s.isDirectory() || (s.mode & 0o077) || s.uid !== process.getuid()) throw new Error('runtime precisa ser privado e do usuário atual');
  return alvo;
}

export function prepararRuntime(home) {
  const raiz = pastaPrivada(home);
  const base = pastaPrivada(path.join(raiz, 'locaweb-ssh'), true);
  const perfil = pastaPrivada(path.join(base, 'perfil'), true);
  const trava = path.join(base, 'execucao.lock');
  try { fs.mkdirSync(trava, { mode: 0o700 }); }
  catch (e) { if (e.code === 'EEXIST') throw new Error('execução concorrente ou trava pendente; conferir manualmente'); throw e; }
  // A posse é um diretório vazio de nome imprevisível, não um arquivo compartilhado
  // validado e depois apagado. rmdir é atômico e recusa qualquer conteúdo alheio.
  const dono = `dono-${process.pid}-${randomUUID()}`;
  const marcador = path.join(trava, dono);
  try { fs.mkdirSync(marcador, { mode: 0o700 }); }
  catch (e) { fs.rmdirSync(trava); throw e; }
  let liberada = false;
  return { base, perfil, liberar() {
    if (liberada) return;
    const entradas = fs.readdirSync(trava);
    if (fs.lstatSync(trava).isSymbolicLink() || entradas.length !== 1 || entradas[0] !== dono
        || !fs.lstatSync(marcador).isDirectory() || fs.lstatSync(marcador).isSymbolicLink()) {
      throw new Error('propriedade da trava divergente');
    }
    fs.rmdirSync(marcador);
    fs.rmdirSync(trava);
    liberada = true;
  } };
}

export async function abrirChromePipe(runtime, { visivel = false, executavel = process.env.CHROME_BIN, limiteMs = 15000, executarPS = execFile } = {}) {
  const bin = executavel || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find(p => fs.existsSync(p));
  if (!bin || !path.isAbsolute(bin) || !fs.existsSync(bin)) throw new Error('Chrome indisponível');
  const env = Object.fromEntries(Object.entries(process.env).filter(([k]) => ['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL', 'DISPLAY', 'XAUTHORITY', 'DBUS_SESSION_BUS_ADDRESS'].includes(k)));
  const argumentos = ['--remote-debugging-pipe', `--user-data-dir=${runtime.perfil}`, '--no-first-run', '--no-default-browser-check',
    '--disable-sync', '--disable-extensions', '--disable-background-networking', '--disable-component-update', '--window-size=1280,900',
    '--restore-last-session'];
  if (!visivel) argumentos.push('--headless=new');
  argumentos.push('about:blank');
  const processo = spawn(bin, argumentos, { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'], env, detached: true });
  const terminou = new Promise(resolve => { processo.once('exit', resolve); processo.once('error', resolve); });
  const pendentes = new Map();
  let id = 0, buffer = '', fechado = false;
  const rejeitarTodos = () => { for (const p of pendentes.values()) { clearTimeout(p.timer); p.reject(new Error('Chrome/pipe encerrado')); } pendentes.clear(); };
  processo.once('exit', rejeitarTodos); processo.once('error', rejeitarTodos);
  processo.stdio[3].on('error', rejeitarTodos); processo.stdio[4].on('error', rejeitarTodos);
  processo.stdio[4].on('data', b => {
    buffer += b.toString();
    if (buffer.length > 2 ** 21) { rejeitarTodos(); buffer = ''; return; }
    let fim;
    while ((fim = buffer.indexOf('\0')) >= 0) {
      const parte = buffer.slice(0, fim); buffer = buffer.slice(fim + 1);
      let m;
      try { m = JSON.parse(parte); } catch { rejeitarTodos(); continue; }
      const p = pendentes.get(m.id);
      if (p) { clearTimeout(p.timer); pendentes.delete(m.id); if (m.error) p.reject(new Error('comando CDP recusado')); else p.resolve(m.result || {}); }
    }
  });
  const enviar = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    if (fechado || processo.exitCode !== null || processo.signalCode !== null) { reject(new Error('Chrome encerrado')); return; }
    const n = ++id;
    const timer = setTimeout(() => { pendentes.delete(n); reject(new Error('tempo do comando CDP excedido')); }, limiteMs);
    pendentes.set(n, { resolve, reject, timer });
    processo.stdio[3].write(JSON.stringify({ id: n, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0', e => {
      if (e && pendentes.has(n)) { clearTimeout(timer); pendentes.delete(n); reject(new Error('pipe CDP indisponível')); }
    });
  });
  const matar = sinal => { try { process.kill(-processo.pid, sinal); } catch { processo.kill(sinal); } };
  const grupoAtivo = () => new Promise((resolve, reject) => {
    if (!processo.pid) { resolve(false); return; }
    executarPS('ps', ['-Ao', 'pgid=,stat='], { timeout: 2000, maxBuffer: 2 ** 21 }, (erro, texto) => {
      if (erro) { reject(new Error('não foi possível conferir processos do Chrome')); return; }
      resolve(texto.split('\n').some(linha => {
        const [grupo, estado] = linha.trim().split(/\s+/);
        return Number(grupo) === processo.pid && estado && !estado.startsWith('Z');
      }));
    });
  });
  const aguardar = ms => { let t; return Promise.race([terminou.then(() => true), new Promise(resolve => { t = setTimeout(() => resolve(false), ms); })]).finally(() => clearTimeout(t)); };
  let fechamento;
  const fechar = () => fechamento ||= (async () => {
    if (fechado) return;
    if (processo.exitCode === null && processo.signalCode === null) {
      enviar('Browser.close').catch(() => {});
      if (!await aguardar(3000)) { matar('SIGTERM'); if (!await aguardar(2000)) { matar('SIGKILL'); await terminou; } }
    }
    // A saída do líder não comprova a saída de renderers no grupo próprio.
    if (await grupoAtivo()) {
      matar('SIGTERM');
      const fim = Date.now() + 3000;
      while (await grupoAtivo()) {
        if (Date.now() >= fim) { matar('SIGKILL'); break; }
        await new Promise(r => setTimeout(r, 50));
      }
      const limite = Date.now() + 3000;
      while (await grupoAtivo()) {
        if (Date.now() >= limite) throw new Error('processos Chrome remanescentes; trava preservada');
        await new Promise(r => setTimeout(r, 50));
      }
    }
    fechado = true; rejeitarTodos();
  })().catch(erro => {
    // Inclusive na inicialização, o chamador ainda não recebeu o handle Chrome.
    // Qualquer limpeza incerta mantém o perfil bloqueado para outra execução.
    matar('SIGKILL');
    erro.preservarTrava = true;
    throw erro;
  });
  try {
    await enviar('Browser.getVersion');
    const { targetId } = await enviar('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await enviar('Target.attachToTarget', { targetId, flatten: true });
    const comando = (metodo, params) => enviar(metodo, params, sessionId);
    await comando('Page.enable');
    return { comando, fechar, processo, async avaliar(expression) {
      const r = await comando('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error('leitura/ação da página recusada');
      return r.result?.value;
    } };
  } catch (e) { await fechar(); throw e; }
}
