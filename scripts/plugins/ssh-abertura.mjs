// Uma abertura por ciclo; falha deixa intenção pendente para revisão humana.
import fs from 'node:fs';
import path from 'node:path';
import { analisarTela, expressaoTela, lerTela } from './ssh-painel.mjs';
export const CONFIRMACAO = 'HABILITAR SSH 73381366 uonix.com.br';
const TRES_HORAS = 3 * 60 * 60 * 1000;
export function expressaoHabilitar(fase) {
  if (!['abrir', 'confirmar'].includes(fase)) throw new Error('fase inválida');
  return `(() => {
    const tela = ${expressaoTela()};
    const validar = ${analisarTela.toString()};
    const URL_PAINEL = 'https://painelhospedagem.locaweb.com.br/tools/73381366/ssh';
    const estado = validar(tela);
    if (estado.status !== 'desabilitado' || estado.modal !== ${fase === 'confirmar'}) throw new Error('estado incompatível');
    const visivel = e => Boolean(e.getClientRects().length);
    const modais = [...document.querySelectorAll('[role=dialog],dialog[open]')].filter(visivel);
    if (modais.length !== ${fase === 'confirmar' ? 1 : 0}) throw new Error('modal ambíguo');
    const escopo = ${fase === 'confirmar' ? 'modais[0]' : 'document'};
    const botoes = [...escopo.querySelectorAll('button')].filter(e => visivel(e) && e.innerText.trim() === 'Habilitar'
      && !e.disabled && e.getAttribute('aria-disabled') !== 'true');
    if (botoes.length !== 1) throw new Error('controle ambíguo');
    botoes[0].click();
    return true;
  })()`;
}
export async function aguardarConfirmacao(chrome, signal, limiteMs = 15000) {
  const fim = Date.now() + limiteMs;
  while (Date.now() < fim) {
    signal?.throwIfAborted();
    const estado = analisarTela(await lerTela(chrome));
    if (estado.status === 'habilitado' && !estado.modal) return;
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('habilitação não concluiu antes da recarga');
}
export async function recarregarPainel(chrome, signal, limiteMs = 10000) {
  signal?.throwIfAborted();
  const anterior = (await chrome.comando('Page.getFrameTree')).frameTree?.frame?.loaderId;
  if (!anterior) throw new Error('documento anterior da recarga ausente');
  await chrome.comando('Page.reload', { ignoreCache: true });
  const fim = Date.now() + limiteMs;
  while (Date.now() < fim) {
    signal?.throwIfAborted();
    const atual = (await chrome.comando('Page.getFrameTree')).frameTree?.frame?.loaderId;
    if (atual && atual !== anterior) return;
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('recarga não confirmada em novo documento');
}
function diretorio(alvo) {
  if (!fs.existsSync(alvo)) fs.mkdirSync(alvo, { mode: 0o700 });
  const s = fs.lstatSync(alvo);
  if (!s.isDirectory() || s.isSymbolicLink() || (s.mode & 0o077) || s.uid !== process.getuid()) throw new Error('recibos precisam ser privados');
}
function ler(alvo) {
  const s = fs.lstatSync(alvo);
  if (!s.isFile() || s.isSymbolicLink() || (s.mode & 0o077) || s.uid !== process.getuid()) throw new Error('recibo inválido');
  const r = JSON.parse(fs.readFileSync(alvo, 'utf8'));
  if (r.alvo !== '73381366' || r.dominio !== 'uonix.com.br' || !Number.isFinite(r.inicio)
      || !['pendente', 'concluido', 'leitura'].includes(r.estado)) throw new Error('recibo inválido');
  return r;
}
export async function habilitarJanela({ base, ciclo, confirmacao, estado, acionar, recarregar, sondar, signal }) {
  const conferir = () => signal?.throwIfAborted();
  conferir();
  if (confirmacao !== CONFIRMACAO) throw new Error('autorização exata de abertura ausente');
  if (typeof ciclo !== 'string' || !/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/.test(ciclo)) throw new Error('ciclo inválido');
  diretorio(base);
  const pasta = path.join(base, 'ciclos'); diretorio(pasta);
  const recibos = fs.readdirSync(pasta).map(nome => {
    if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\.json$/.test(nome)) throw new Error('recibo inesperado');
    return ler(path.join(pasta, nome));
  });
  if (recibos.some(r => r.estado === 'pendente')) throw new Error('abertura pendente: conferir painel e recibo antes de outro ciclo');
  const arquivo = path.join(pasta, `${ciclo}.json`);
  if (fs.existsSync(arquivo)) throw new Error('ciclo já utilizado');
  const validar = r => {
    if (!r || !['habilitado', 'desabilitado'].includes(r.status)) throw new Error('estado não confirmado');
    if (r.modal) throw new Error('modal inesperado antes da operação');
    return r;
  };
  const antes = validar(await estado());
  conferir();
  const alterar = antes.status === 'desabilitado';
  if (alterar && recibos.some(r => r.alterou_ssh && Date.now() - (r.fim || r.inicio) < TRES_HORAS)) {
    throw new Error('abertura recente: não renovar automaticamente');
  }
  const recibo = { alvo: '73381366', dominio: 'uonix.com.br', ciclo, inicio: Date.now(),
    estado: alterar ? 'pendente' : 'leitura', alterou_ssh: alterar, status_inicial: antes.status };
  fs.writeFileSync(arquivo, JSON.stringify(recibo) + '\n', { flag: 'wx', mode: 0o600 });
  if (alterar) { conferir(); await acionar('abrir'); conferir(); await acionar('confirmar'); conferir(); }
  await recarregar();
  conferir();
  const depois = validar(await estado());
  if (depois.status !== 'habilitado') throw new Error('habilitação não confirmada após recarga');
  if (await sondar() !== true) throw new Error('sonda SSH autenticada não confirmou acesso');
  conferir();
  const resultado = { ...recibo, estado: 'concluido', status: 'habilitado', ssh_autenticado: true, fim: Date.now() };
  const temporario = arquivo + '.novo';
  fs.writeFileSync(temporario, JSON.stringify(resultado) + '\n', { flag: 'wx', mode: 0o600 });
  fs.renameSync(temporario, arquivo);
  return resultado;
}
