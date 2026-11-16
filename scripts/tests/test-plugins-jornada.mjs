#!/usr/bin/env node
// Contratos locais da jornada; fixtures sintéticas, sem WordPress/produção.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { tmpdir } from 'node:os';
import http from 'node:http';
import { createHash } from 'node:crypto';
import { EventEmitter } from 'node:events';
import { aguardarSaidaChrome } from './fixtures/lib-chrome-devtools.mjs';
import { validarUrlLocal, verificarEvidencias, limparMensagens, executarJornada } from '../plugins/jornada.mjs';
import { executarCheckout } from '../plugins/jornada-navegador.mjs';

const processoLento = Object.assign(new EventEmitter(), { exitCode: null, signalCode: null });
let matou = false;
await aguardarSaidaChrome(processoLento, () => {
  matou = true;
  setTimeout(() => { processoLento.signalCode = 'SIGKILL'; processoLento.emit('exit'); }, 25);
}, 5);
assert.equal(matou, true);
assert.equal(processoLento.signalCode, 'SIGKILL', 'fechar só termina depois da saída confirmada, não pelo prazo pós-kill');

// Timeout não pode liberar limpeza enquanto um launcher tardio ainda pode navegar.
let abriuTarde = false, navegouTarde = false, fechouTarde = false;
const tardio = executarCheckout({ base_url: 'http://127.0.0.1:1', produto_url: 'http://127.0.0.1:1/produto/' }, {
  limiteMs: 5,
  abrir: async (_prefixo, aoIniciar) => {
    aoIniciar(() => {});
    await new Promise(r => setTimeout(r, 25));
    abriuTarde = true;
    return { dt: { enviar: async () => { navegouTarde = true; return {}; } }, fechar: async () => { fechouTarde = true; } };
  },
});
await assert.rejects(tardio, /tempo global do navegador excedido/);
await new Promise(r => setTimeout(r, 40));
assert.equal(abriuTarde, true);
assert.equal(navegouTarde, false, 'nenhum comando DevTools pode continuar depois do timeout');
assert.equal(fechouTarde, true, 'launcher tardio precisa fechar sem navegar');
console.log('OK: timeout cancela launcher tardio antes de qualquer navegação');

let comandosTimeout = 0, fechamentoTimeout = false;
await assert.rejects(executarCheckout({}, {
  limiteMs: 5,
  abrir: async (_prefixo, aoIniciar) => {
    aoIniciar(() => {});
    return {
      dt: { enviar: () => { comandosTimeout += 1; return new Promise(r => setTimeout(() => r({}), 30)); } },
      fechar: async () => { await new Promise(r => setTimeout(r, 10)); fechamentoTimeout = true; },
    };
  },
}), /tempo global do navegador excedido/);
assert.equal(fechamentoTimeout, true, 'aguardar fechamento antes de liberar limpeza do WordPress');
await new Promise(r => setTimeout(r, 40));
assert.equal(comandosTimeout, 1, 'comando tardio não pode continuar a jornada após abortar');

let eventoEmitido = false, fechouInterceptacao = false;
await assert.rejects(executarCheckout({ base_url: 'http://127.0.0.1:1', produto_url: 'http://127.0.0.1:1/' }, {
  limiteMs: 100,
  abrir: async (_prefixo, aoIniciar) => {
    aoIniciar(() => {});
    return {
      dt: {
        umaVez: () => new Promise(resolver => {
          if (!eventoEmitido) { eventoEmitido = true; setTimeout(() => resolver({ request: { url: 'URL inválida' } }), 1); }
        }),
        enviar: async metodo => metodo === 'Page.navigate' ? new Promise(() => {}) : {},
      },
      fechar: async () => { fechouInterceptacao = true; },
    };
  },
}), /interceptação de rede incompleta/);
assert.equal(fechouInterceptacao, true, 'erro de interceptação aborta e fecha, sem esperar timeout global');

// Uma chamada DevTools já enviada pode resolver depois do aborto; a limpeza só
// pode começar depois de fechar a sessão, e nenhuma continuação pode navegar.
let continuarPendente = false, sessaoFechada = false, comandosDepois = 0, emitiuPendente = false;
await assert.rejects(executarCheckout({ base_url: 'http://127.0.0.1:1', produto_url: 'http://127.0.0.1:1/' }, {
  limiteMs: 10,
  abrir: async (_prefixo, aoIniciar) => {
    aoIniciar(() => {});
    return {
      dt: {
        umaVez: () => new Promise(resolver => {
          if (!emitiuPendente) { emitiuPendente = true; setTimeout(() => resolver({ requestId: 'propria', request: { url: 'http://127.0.0.1:1/' } }), 1); }
        }),
        enviar: async metodo => {
          if (sessaoFechada) comandosDepois += 1;
          if (metodo === 'Page.navigate') return new Promise(() => {});
          if (metodo === 'Fetch.continueRequest') { continuarPendente = true; return new Promise(r => setTimeout(() => r({}), 40)); }
          return {};
        },
      },
      fechar: async () => { await new Promise(r => setTimeout(r, 15)); sessaoFechada = true; },
    };
  },
}), /tempo global do navegador excedido/);
assert.equal(continuarPendente, true, 'teste precisa alcançar a operação de interceptação pendente');
assert.equal(sessaoFechada, true, 'encerramento aguarda fechar a sessão antes da limpeza');
await new Promise(r => setTimeout(r, 50));
assert.equal(comandosDepois, 0, 'resposta DevTools tardia não reabre a sequência');

for (const url of ['https://uonix.com.br', 'http://localhost.exemplo.test:8080', 'http://usuario:senha@localhost:8080', 'file:///tmp/teste', 'http://localhost:8080/?x=1']) {
  assert.throws(() => validarUrlLocal(url), /local|URL|loopback/);
}
assert.equal(validarUrlLocal('http://localhost:8080').origin, 'http://localhost:8080');

const run = 'uonix-jornada-' + 'a'.repeat(32);
const dados = {
  run_id: run, email: `${run}@example.invalid`, produto_id: 17,
  pedido: { id: 91, numero: '91', status: 'gplsquote-req', pagamento: 'gpls-rfq', email: `${run}@example.invalid`, itens: [{ produto_id: 17, quantidade: 1 }] },
  leads: [{ form_id: 4, email: `${run}@example.invalid`, origem: 'FORMULÁRIO DE SOLICITAÇÃO DE ORÇAMENTO', newsletter: 'nao', nome: run, empresa: run, telefone: '11999990000' }],
  esperado: { nome: run, empresa: run, telefone: '11999990000' },
  notificacoes: [{ id: 'customer_rfq', destinatarios: ['hash-cliente'] }, { id: 'new_rfq', destinatarios: ['hash-admin'] }],
  mensagens: [
    { id: 'm1', run_id: run, tipo: 'customer_rfq', destinatarios: ['hash-cliente'], contem_pedido: true, corpo: true },
    { id: 'm2', run_id: run, tipo: 'new_rfq', destinatarios: ['hash-admin'], contem_pedido: true, corpo: true },
  ],
};
assert.deepEqual(verificarEvidencias(dados), { cotacao: true, lead: true, notificacoes: true });
for (const [campo, valor, check] of [
  ['pedido', null, 'cotacao'], ['leads', [], 'lead'], ['mensagens', [], 'notificacoes'],
]) {
  assert.equal(verificarEvidencias({ ...dados, [campo]: valor })[check], false, `ausência de ${campo} precisa reprovar`);
}
for (const [campo, valor] of [['status', 'pending'], ['pagamento', 'bacs'], ['email', 'outro@example.invalid'], ['itens', []]]) {
  assert.equal(verificarEvidencias({ ...dados, pedido: { ...dados.pedido, [campo]: valor } }).cotacao, false, campo);
}
for (const [campo, valor] of [['form_id', 2], ['origem', 'Outra origem'], ['newsletter', 'sim'], ['empresa', 'Outra'], ['email', 'outro@example.invalid'], ['telefone', '0000']]) {
  assert.equal(verificarEvidencias({ ...dados, leads: [{ ...dados.leads[0], [campo]: valor }] }).lead, false, campo);
}
assert.equal(verificarEvidencias({ ...dados, notificacoes: [] }).notificacoes, false, 'nenhuma notificação configurada não equivale a prova');
assert.equal(verificarEvidencias({ ...dados, mensagens: [dados.mensagens[0]] }).notificacoes, false);
assert.equal(verificarEvidencias({ ...dados, mensagens: dados.mensagens.map(m => ({ ...m, run_id: 'outro' })) }).notificacoes, false);
assert.equal(verificarEvidencias({ ...dados, mensagens: dados.mensagens.map(m => ({ ...m, destinatarios: [] })) }).notificacoes, false);
assert.equal(verificarEvidencias({ ...dados, mensagens: dados.mensagens.map(m => ({ ...m, contem_pedido: false })) }).notificacoes, false);
// Não assumir uma quantidade fixa: respeitar as notificações habilitadas e tolerar extras correlacionados.
assert.equal(verificarEvidencias({ ...dados, notificacoes: [dados.notificacoes[0]], mensagens: [dados.mensagens[0]] }).notificacoes, true);
assert.equal(verificarEvidencias({ ...dados, mensagens: [...dados.mensagens, { ...dados.mensagens[0], id: 'extra' }] }).notificacoes, true);
console.log('OK: contratos da jornada, pedido, lead e notificações');

let apagou = false;
await limparMensagens(async (url, opcoes = {}) => {
  if (opcoes.method === 'DELETE') apagou = true;
  return { messages_count: 0, messages: [] };
}, run);
assert.equal(apagou, false, 'nunca enviar DELETE com IDs vazios (apagaria a caixa inteira)');
await assert.rejects(limparMensagens(async url => {
  if (url.includes('/search?')) return { messages_count: 1, messages: [{ ID: 'nao-pertence' }] };
  if (url.endsWith('/headers')) return { 'X-Uonix-Jornada': ['outro'] };
  return { Text: 'mensagem de outro registro' };
}, run), /propriedade/);

const tmp = fs.mkdtempSync(path.join(tmpdir(), 'uonix-jornada-teste-'));
const opcoes = { base_url: 'http://localhost:8080', mailpit_url: 'http://localhost:8025', container: 'uonix-local-app', produto_id: 17, saida: tmp };
await assert.rejects(executarJornada({ ...opcoes, base_url: 'https://uonix.com.br' }), /loopback/);
await assert.rejects(executarJornada({ ...opcoes, container: 'outro-projeto' }), /alvo local/);

// Ciclo de vida isolado: controle verde, depois limpeza vermelha com as mesmas provas.
function adaptadores(falharLimpeza = false, recebido = true) {
  let cfg, emitido = false, removido = false;
  return {
    checkout: async () => ({ recebido, caminho: '/order-received/91/' }),
    wp: async (modo, config) => {
      cfg = config;
      if (modo === 'preparar') return { produto_url: config.base_url + '/produto/', checkout_url: config.base_url + '/checkout/', habilitadas: ['customer_rfq'] };
      if (modo === 'limpar') {
        if (falharLimpeza) throw new Error('limpeza incompleta intencional');
        return { limpeza: true };
      }
      emitido = true;
      return { pedido: { ...dados.pedido, email: cfg.email },
        leads: [{ ...dados.leads[0], ...cfg.esperado, email: cfg.email }],
        notificacoes: [{ id: 'customer_rfq', destinatarios: [createHash('sha256').update(cfg.email).digest('hex')] }] };
    },
    api: async (url, opts = {}) => {
      if (opts.method === 'DELETE') {
        assert.deepEqual(JSON.parse(opts.body).IDs, ['mensagem-propria']);
        removido = true;
        return 'ok';
      }
      if (url.includes('/search?')) return { messages_count: emitido && !removido ? 1 : 0, messages: emitido && !removido ? [{ ID: 'mensagem-propria' }] : [] };
      if (url.endsWith('/headers')) return { 'X-Uonix-Jornada': [cfg.run_id], 'X-Uonix-Jornada-Email': ['customer_rfq'] };
      return { To: [{ Address: cfg.email }], Text: 'Orçamento 91 — mensagem de teste correlacionada' };
    },
  };
}
try {
  const controle = await executarJornada({ ...opcoes, saida: path.join(tmp, 'controle') }, adaptadores());
  assert.equal(controle.ok, true, JSON.stringify(controle));
  const incompleta = await executarJornada({ ...opcoes, saida: path.join(tmp, 'limpeza-falha') }, adaptadores(true));
  assert.deepEqual(incompleta.checks, controle.checks);
  assert.equal(incompleta.limpeza, false);
  assert.equal(incompleta.ok, false, 'limpeza incompleta bloqueia mesmo com jornada funcional verde');
  const semConfirmacao = await executarJornada({ ...opcoes, saida: path.join(tmp, 'sem-confirmacao') }, adaptadores(false, false));
  assert.equal(semConfirmacao.ok, false, 'persistência não substitui confirmação de sucesso na interface');
  const divergente = adaptadores();
  const wpOriginal = divergente.wp;
  divergente.wp = async (modo, cfg) => {
    const r = await wpOriginal(modo, cfg);
    return modo === 'preparar' ? { ...r, habilitadas: ['customer_rfq', 'new_rfq'] } : r;
  };
  const semTipo = await executarJornada({ ...opcoes, saida: path.join(tmp, 'sem-tipo') }, divergente);
  assert.equal(semTipo.ok, false, 'lista esperada não pode diminuir entre preparar e verificar');
} finally { fs.rmSync(tmp, { recursive: true, force: true }); }
console.log('OK: propriedade das mensagens, limpeza restrita e falha fechada');

// Fixture de navegador: cliques reais e POST real; não finge ser prova WordPress.
const pasta = fs.mkdtempSync(path.join(tmpdir(), 'uonix-jornada-browser-'));
let carrinho = false, formulario = null, requisicoesExternas = 0;
const espiao = http.createServer((req, res) => { requisicoesExternas += 1; res.end('não deveria chegar'); });
await new Promise(r => espiao.listen(0, '127.0.0.1', r));
const origemEspiao = `http://127.0.0.1:${espiao.address().port}`;
const servidor = http.createServer(async (req, res) => {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  if (req.url === '/produto/') {
    return res.end(`<form class="cart" action="/adicionado" method="post"><button class="single_add_to_cart_button">Adicionar</button></form><script src="${origemEspiao}/externo.js"></script>`);
  }
  if (req.url === '/adicionado') { carrinho = true; return res.end('Adicionado'); }
  if (req.url === '/checkout/' && req.method === 'POST') {
    const partes = [];
    for await (const parte of req) partes.push(parte);
    formulario = new URLSearchParams(Buffer.concat(partes).toString());
    res.statusCode = 303;
    res.setHeader('Location', '/order-received/91/');
    return res.end();
  }
  if (req.url === '/checkout/' && carrinho) {
    const campos = ['billing_complete_name', 'billing_phone', 'billing_email', 'billing_cnpj', 'billing_company', 'billing_postcode', 'billing_address_1', 'billing_address_3', 'billing_city'];
    return res.end(`<form class="checkout" action="/checkout/" method="post">${campos.map(n => `<input id="${n}" name="${n}">`).join('')}<select name="billing_state"><option value="SP">SP</option></select><input type="checkbox" name="billing_newsletters" checked><input type="checkbox" name="createaccount" checked><input type="checkbox" name="terms"><button id="place_order">Solicitar</button></form>`);
  }
  res.end('<body class="woocommerce-order-received"><div class="woocommerce-order">Orçamento de teste 91</div></body>');
});
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
try {
  const base = `http://127.0.0.1:${servidor.address().port}`;
  const r = await executarCheckout({ base_url: base, produto_url: base + '/produto/', checkout_url: base + '/checkout/', run_id: run,
    email: dados.email, esperado: dados.esperado, saida: pasta });
  assert.equal(r.recebido, true);
  assert.equal(formulario.get('billing_email'), dados.email);
  assert.equal(formulario.has('billing_newsletters'), false);
  assert.equal(formulario.has('createaccount'), false);
  assert.equal(formulario.get('terms'), 'on');
  assert.equal(requisicoesExternas, 0, 'origem diferente precisa ser bloqueada antes da chamada');
  assert(r.origens_bloqueadas.includes(origemEspiao));
  assert(fs.existsSync(path.join(pasta, 'cotacao.png')));
} finally {
  await Promise.all([new Promise(r => servidor.close(r)), new Promise(r => espiao.close(r))]);
  fs.rmSync(pasta, { recursive: true, force: true });
}
console.log('OK: navegador real em fixture, POST, opt-out e bloqueio de origem externa');
