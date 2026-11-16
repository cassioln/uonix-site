#!/usr/bin/env node
// Jornada vital local; nenhum caminho deste módulo conecta em produção.
import { pathToFileURL } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';
import { createHash, randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { parseArgs } from 'node:util';
import { executarCheckout } from './jornada-navegador.mjs';

export function validarUrlLocal(texto) {
  let url;
  try { url = new URL(texto); } catch { throw new Error('URL local inválida'); }
  if (url.protocol !== 'http:' || !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
      || url.username || url.password || url.search || url.hash) {
    throw new Error('URL deve ser HTTP local/loopback, sem credenciais ou parâmetros');
  }
  return url;
}

export function verificarEvidencias(dados) {
  const pedido = dados.pedido;
  const cotacao = Boolean(pedido?.id && pedido.email === dados.email
    && String(pedido.status ?? '').replace(/^wc-/, '') === 'gplsquote-req' && pedido.pagamento === 'gpls-rfq'
    && pedido.itens?.length === 1 && pedido.itens[0].produto_id === dados.produto_id
    && pedido.itens[0].quantidade === 1);
  const lead = dados.leads?.length === 1 && dados.leads.every(l => l.form_id === 4
    && l.email === dados.email && l.origem === 'FORMULÁRIO DE SOLICITAÇÃO DE ORÇAMENTO'
    && l.newsletter === 'nao' && ['nome', 'empresa'].every(k => String(l[k]).trim().toUpperCase() === dados.esperado[k].trim().toUpperCase())
    && String(l.telefone).replace(/\D/g, '') === dados.esperado.telefone.replace(/\D/g, ''));
  const notificacoes = Boolean(dados.notificacoes?.length && dados.notificacoes.every(n =>
    n.destinatarios.length && dados.mensagens?.some(m => m.run_id === dados.run_id && m.tipo === n.id
      && m.corpo === true && m.contem_pedido === true
      && n.destinatarios.every(d => m.destinatarios.includes(d)))));
  return { cotacao, lead: Boolean(lead), notificacoes };
}

const sha = texto => createHash('sha256').update(texto.trim().toLowerCase()).digest('hex');

function controleWp(modo, cfg) {
  const entrada = fs.readFileSync(new URL('./jornada-wp.php', import.meta.url), 'utf8');
  const r = spawnSync('podman', ['exec', '-i', cfg.container, 'php', '-d', 'memory_limit=512M',
    '/tmp/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval-file', '-', modo,
    Buffer.from(JSON.stringify(cfg)).toString('base64')], { input: entrada, encoding: 'utf8', timeout: 45000, maxBuffer: 1024 * 1024 });
  const marcado = r.stdout?.match(/@@JORNADA([\s\S]*?)@@JORNADA/);
  if (!marcado) throw new Error(`controle WordPress sem retorno no modo ${modo} (saída ${r.status ?? r.error?.code})`);
  const dados = JSON.parse(marcado[1]);
  if (r.status !== 0 || dados.erro) throw new Error(dados.erro || `controle WordPress recusou ${modo}`);
  return dados;
}

function apiMailpit(base) {
  return async (caminho, opcoes = {}) => {
    const r = await fetch(new URL(caminho, base), { ...opcoes, redirect: 'error', signal: AbortSignal.timeout(10000) });
    if (!r.ok) throw new Error(`Mailpit HTTP ${r.status}`);
    const texto = await r.text();
    return r.headers.get('content-type')?.includes('application/json') ? JSON.parse(texto) : texto;
  };
}

export async function lerMensagens(api, runId, numero = '') {
  const mensagens = new Map();
  let quantidade = 0;
  do {
    const pagina = await api(`/api/v1/search?query=${encodeURIComponent(runId)}&start=${mensagens.size}&limit=100`);
    if (!Number.isInteger(pagina.messages_count) || !Array.isArray(pagina.messages)) throw new Error('resposta Mailpit incompleta');
    quantidade = pagina.messages_count;
    if (mensagens.size < quantidade && !pagina.messages.length) throw new Error('paginação Mailpit incompleta');
    for (const item of pagina.messages) {
      if (mensagens.has(item.ID)) throw new Error('paginação Mailpit repetiu mensagem');
      const mensagem = await api(`/api/v1/message/${encodeURIComponent(item.ID)}`);
      const cabecalhos = await api(`/api/v1/message/${encodeURIComponent(item.ID)}/headers`);
      const cabecalho = nome => Object.entries(cabecalhos).filter(([k]) => k.toLowerCase() === nome).flatMap(([, v]) => v);
      // Busca textual sozinha nunca autoriza exclusão de uma mensagem.
      if (!cabecalho('x-uonix-jornada').includes(runId)) throw new Error('mensagem sem propriedade comprovada');
      const corpo = `${mensagem.Text || ''}\n${mensagem.HTML || ''}`;
      mensagens.set(item.ID, { id: item.ID, run_id: runId,
        tipo: cabecalho('x-uonix-jornada-email')[0] || 'adicional',
        destinatarios: [...(mensagem.To || []), ...(mensagem.Cc || []), ...(mensagem.Bcc || [])].map(d => sha(d.Address || d.Email || '')),
        contem_pedido: Boolean(numero && corpo.includes(String(numero))), corpo: corpo.trim().length > 20 });
    }
  } while (mensagens.size < quantidade);
  if (mensagens.size !== quantidade) throw new Error('contagem Mailpit divergente');
  return [...mensagens.values()];
}

export async function limparMensagens(api, runId) {
  const mensagens = await lerMensagens(api, runId);
  const ids = mensagens.map(m => m.id);
  // A API apaga a caixa inteira se IDs estiver vazio/ausente. Nunca fazer isso.
  if (ids.length) await api('/api/v1/messages', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ IDs: ids }) });
  if ((await lerMensagens(api, runId)).length) throw new Error('limpeza Mailpit incompleta');
  return ids.length;
}

export async function executarJornada(opcoes, adaptadores = {}) {
  const base = validarUrlLocal(opcoes.base_url);
  const mailpit = validarUrlLocal(opcoes.mailpit_url);
  if (base.pathname !== '/' || mailpit.pathname !== '/' || opcoes.container !== 'uonix-local-app'
      || !Number.isInteger(opcoes.produto_id) || opcoes.produto_id <= 0) throw new Error('alvo local obrigatório inválido');
  const saida = path.resolve(opcoes.saida);
  fs.mkdirSync(saida, { recursive: true });
  const runId = 'uonix-jornada-' + randomBytes(16).toString('hex');
  const cfg = { ...opcoes, base_url: base.origin, saida, run_id: runId, email: runId + '@example.invalid',
    esperado: { nome: ('JORNADA TESTE ' + runId).toUpperCase(), empresa: ('EMPRESA TESTE ' + runId).toUpperCase(), telefone: '11999990000' },
    guarda: fs.readFileSync(new URL('./jornada-guarda.php', import.meta.url)).toString('base64') };
  const wp = adaptadores.wp || controleWp;
  const api = adaptadores.api || apiMailpit(mailpit);
  const checkout = adaptadores.checkout || executarCheckout;
  const relatorio = { versao: 1, run_id: runId, inicio: new Date().toISOString(), ok: false, limpeza: false,
    checks: { cotacao: false, lead: false, notificacoes: false }, detalhe: 'jornada incompleta' };
  let prepararIniciado = false;
  try {
    if ((await lerMensagens(api, runId)).length) throw new Error('marcador de e-mail já existe');
    prepararIniciado = true;
    const preparado = await wp('preparar', cfg);
    for (const chave of ['produto_url', 'checkout_url']) {
      if (validarUrlLocal(preparado[chave]).origin !== base.origin) throw new Error('WordPress devolveu URL de outra origem');
    }
    relatorio.notificacoes_habilitadas = preparado.habilitadas;
    relatorio.navegador = await checkout({ ...cfg, ...preparado });
    if (relatorio.navegador?.recebido !== true) throw new Error('interface não confirmou a solicitação de orçamento');
    const dados = await wp('verificar', cfg);
    const esperadas = [...(preparado.habilitadas || [])].sort();
    const verificadas = (dados.notificacoes || []).map(n => n.id).sort();
    if (!esperadas.length || JSON.stringify(esperadas) !== JSON.stringify(verificadas)) {
      throw new Error('tipos de notificação divergiram entre preparação e verificação');
    }
    const inicio = Date.now();
    do {
      dados.mensagens = await lerMensagens(api, runId, dados.pedido?.numero);
      relatorio.checks = verificarEvidencias({ ...cfg, ...dados });
      if (Object.values(relatorio.checks).every(v => v === true)) break;
      if (!relatorio.checks.cotacao || !relatorio.checks.lead) break;
      await new Promise(r => setTimeout(r, 1000));
    } while (Date.now() - inicio < 30000);
    relatorio.evidencias = dados;
    relatorio.detalhe = Object.values(relatorio.checks).every(v => v === true) ? 'jornada concluída' : 'pedido, lead ou notificação reprovados';
  } catch (erro) {
    relatorio.detalhe = erro.message;
  } finally {
    const erros = [];
    if (prepararIniciado) {
      try { relatorio.limpeza_wordpress = await wp('limpar', cfg); }
      catch (erro) { erros.push(`WordPress: ${erro.message}`); }
      try { relatorio.mensagens_removidas = await limparMensagens(api, runId); }
      catch (erro) { erros.push(`Mailpit: ${erro.message}`); }
    }
    relatorio.limpeza = erros.length === 0 && (!prepararIniciado || relatorio.limpeza_wordpress?.limpeza === true);
    if (erros.length) relatorio.erros_limpeza = erros;
    relatorio.ok = relatorio.limpeza && Object.values(relatorio.checks).every(v => v === true);
    relatorio.fim = new Date().toISOString();
    fs.writeFileSync(path.join(saida, 'relatorio.json'), JSON.stringify(relatorio, null, 2) + '\n');
  }
  return relatorio;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  try {
    const { values } = parseArgs({ options: {
      'base-url': { type: 'string', default: 'http://localhost:8080' },
      'mailpit-url': { type: 'string', default: 'http://localhost:8025' },
      container: { type: 'string', default: 'uonix-local-app' },
      'produto-id': { type: 'string' }, saida: { type: 'string' },
    } });
    if (!values.saida) throw new Error('--saida obrigatório');
    const r = await executarJornada({ base_url: values['base-url'], mailpit_url: values['mailpit-url'],
      container: values.container, produto_id: Number(values['produto-id']), saida: values.saida });
    console.log(`Jornada: ${r.ok ? 'verde' : 'REPROVADA'}; limpeza: ${r.limpeza}; ${r.detalhe}`);
    process.exitCode = r.ok ? 0 : 20;
  } catch (erro) { console.error(erro.message); process.exitCode = 30; }
}
