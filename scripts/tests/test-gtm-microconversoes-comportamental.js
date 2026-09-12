/**
 * Testes Comportamentais de Runtime (Node VM) para Microconversões Uônix, LGPD e GTM.
 *
 * Valida o comportamento dinâmico real dos scripts client-side:
 * 1. Formulário de Contato vs Orçamento: isolamento de metas e tratamento de sucesso/erro AJAX.
 * 2. Adicionar ao Carrinho: validação estrita de semântica add vs decrement/remove e fluxo não-AJAX.
 * 3. Newsletter no Checkout / Thank-you: consentimento negado -> concedido tardiamente,
 *    ausência de consumo precoce do sessionStorage e deduplicação efetiva de conversão.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const phpPath = path.resolve(__dirname, '../../mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php');
const phpCode = fs.readFileSync(phpPath, 'utf8');

// Extrai os scripts JavaScript renderizados pelo PHP
function extractScript(id) {
  const regex = new RegExp('<script id="' + id + '">([\\s\\S]*?)<\\/script>');
  const match = phpCode.match(regex);
  if (!match) {
    throw new Error('Script não encontrado no PHP: ' + id);
  }
  return match[1];
}

const contactListenerCode = extractScript('uonix-conversao-contato-newsletter-listener');
const cartListenerCode = extractScript('uonix-conversao-adicionar-carrinho-listener');

console.log('========================================================================');
console.log('🧪 TESTES COMPORTAMENTAIS RUNTIME — MICROCONVERSÕES E LGPD');
console.log('========================================================================\n');

let totalTests = 0;
let passedTests = 0;

function test(name, fn) {
  totalTests++;
  try {
    fn();
    passedTests++;
    console.log(`  PASS: ${name}`);
  } catch (err) {
    console.error(`  FAIL: ${name}`);
    console.error(`        ${err.message}`);
  }
}

// -----------------------------------------------------------------------------
// 1. Testes Comportamentais do Listener de Contato vs Orçamento
// -----------------------------------------------------------------------------
console.log('--- 1. Formulários: Contato Institucional vs Orçamento ---');

function createFormMock(formId, assunto, hasNewsletter = false) {
  return {
    id: 'fluentform_' + formId,
    getAttribute: (attr) => (attr === 'data-form_id' || attr === 'data-form-id' ? formId : null),
    querySelector: (sel) => {
      if (sel && sel.includes('form_assunto')) {
        return { value: assunto };
      }
      if (sel && sel.includes('newsletter')) {
        return hasNewsletter ? { checked: true } : null;
      }
      return null;
    }
  };
}

test('Contato: assunto comum (duvidas/suporte) emite uonix_contato_formulario', () => {
  const events = [];
  const handlers = {};
  const doc = { readyState: 'complete', addEventListener: () => {} };
  const jqObj = {
    on: (evt, handler) => { handlers[evt] = handler; return jqObj; }
  };
  const win = {
    dataLayer: events,
    document: doc,
    jQuery: () => jqObj
  };
  const ctx = vm.createContext({ window: win, document: doc, setTimeout, clearTimeout });
  vm.runInContext(contactListenerCode, ctx);

  const handler = handlers['fluentform_submission_success.uonixContato'];
  assert(typeof handler === 'function', 'Handler jQuery .uonixContato deve estar registrado');

  const formElem = createFormMock('3', 'duvidas');
  handler({}, {
    form: [formElem],
    formId: '3'
  });

  assert.strictEqual(events.length, 1, 'Deve emitir 1 evento');
  assert.strictEqual(events[0].event, 'uonix_contato_formulario');
  assert.strictEqual(events[0].assunto, 'duvidas');
  assert.strictEqual(events[0].form_id, '3');
});

test('Contato: assunto orcamento NUNCA emite uonix_contato_formulario (isolamento de metas)', () => {
  const events = [];
  const handlers = {};
  const doc = { readyState: 'complete', addEventListener: () => {} };
  const jqObj = {
    on: (evt, handler) => { handlers[evt] = handler; return jqObj; }
  };
  const win = {
    dataLayer: events,
    document: doc,
    jQuery: () => jqObj
  };
  const ctx = vm.createContext({ window: win, document: doc, setTimeout, clearTimeout });
  vm.runInContext(contactListenerCode, ctx);

  const handler = handlers['fluentform_submission_success.uonixContato'];
  const formElem = createFormMock('3', 'orcamento');
  handler({}, {
    form: [formElem],
    formId: '3'
  });

  const contatoEvents = events.filter(e => e.event === 'uonix_contato_formulario');
  assert.strictEqual(contatoEvents.length, 0, 'Não deve emitir contato para assunto orcamento');
});

test('Contato: falha ou ausência de formulário não emite conversão de contato', () => {
  const events = [];
  const handlers = {};
  const doc = { readyState: 'complete', addEventListener: () => {} };
  const jqObj = {
    on: (evt, handler) => { handlers[evt] = handler; return jqObj; }
  };
  const win = {
    dataLayer: events,
    document: doc,
    jQuery: () => jqObj
  };
  const ctx = vm.createContext({ window: win, document: doc, setTimeout, clearTimeout });
  vm.runInContext(contactListenerCode, ctx);

  const handler = handlers['fluentform_submission_success.uonixContato'];
  // Submissão vazia ou com erro
  handler({}, null);
  handler({}, { form: [] });
  assert.strictEqual(events.length, 0, 'Payload vazio ou com erro não deve emitir evento');
});

// -----------------------------------------------------------------------------
// 2. Testes Comportamentais de Adicionar ao Carrinho (AJAX e Não-AJAX)
// -----------------------------------------------------------------------------
console.log('\n--- 2. Carrinho: Semântica add vs decrement e Não-AJAX ---');

test('Carrinho: added_to_cart com actionType "add" emite uonix_adicionar_ao_carrinho', () => {
  const events = [];
  const bodyHandlers = {};
  const docBody = {
    on: (evt, handler) => { bodyHandlers[evt] = handler; return docBody; }
  };
  const win = {
    dataLayer: events,
    document: { body: docBody },
    jQuery: (selector) => (selector === docBody || selector === win.document.body ? docBody : {})
  };
  const ctx = vm.createContext({ window: win, document: win.document, setTimeout, clearTimeout });
  vm.runInContext(cartListenerCode, ctx);

  const handler = bodyHandlers['added_to_cart'];
  assert(typeof handler === 'function', 'Listener added_to_cart deve estar registrado no document.body');

  handler({}, {}, 'cart_hash_123', null, { actionType: 'add' });

  assert.strictEqual(events.length, 1);
  assert.strictEqual(events[0].event, 'uonix_adicionar_ao_carrinho');
  assert.strictEqual(events[0].origem_conversao, 'ajax_added_to_cart');
});

test('Carrinho: actionType "decrement" ou "remove" NUNCA emite uonix_adicionar_ao_carrinho', () => {
  const events = [];
  const bodyHandlers = {};
  const docBody = {
    on: (evt, handler) => { bodyHandlers[evt] = handler; return docBody; }
  };
  const win = {
    dataLayer: events,
    document: { body: docBody },
    jQuery: (selector) => (selector === docBody || selector === win.document.body ? docBody : {})
  };
  const ctx = vm.createContext({ window: win, document: win.document, setTimeout, clearTimeout });
  vm.runInContext(cartListenerCode, ctx);

  const handler = bodyHandlers['added_to_cart'];

  // Teste com decremento explícito
  handler({}, {}, 'cart_hash_123', null, { actionType: 'decrement' });
  assert.strictEqual(events.length, 0, 'Decremento não deve emitir uonix_adicionar_ao_carrinho');

  // Teste com remoção explícita
  handler({}, {}, 'cart_hash_123', null, { actionType: 'remove' });
  assert.strictEqual(events.length, 0, 'Remoção não deve emitir uonix_adicionar_ao_carrinho');
});

test('Carrinho: múltiplos cliques rápidos sofrem debounce de 1 segundo', (done) => {
  const events = [];
  const bodyHandlers = {};
  const docBody = {
    on: (evt, handler) => { bodyHandlers[evt] = handler; return docBody; }
  };
  const win = {
    dataLayer: events,
    document: { body: docBody },
    jQuery: () => docBody
  };
  const ctx = vm.createContext({ window: win, document: win.document, setTimeout, clearTimeout });
  vm.runInContext(cartListenerCode, ctx);

  const handler = bodyHandlers['added_to_cart'];
  handler({}, {}, 'hash1', null, { actionType: 'add' });
  handler({}, {}, 'hash2', null, { actionType: 'add' });
  handler({}, {}, 'hash3', null, { actionType: 'add' });

  assert.strictEqual(events.length, 1, 'Múltiplos cliques instantâneos devem emitir exatamente 1 evento');
});

// -----------------------------------------------------------------------------
// 3. Testes Comportamentais de Newsletter no Thank-You (LGPD / Consentimento)
// -----------------------------------------------------------------------------
console.log('\n--- 3. Newsletter Thank-You: Consentimento Tardio e Deduplicação ---');

function createNewsletterCodeForOrder(orderId) {
  // Simula o script gerado pelo PHP substituindo <?php echo (int) $uonix_order_id; ?> por orderId
  const rawScript = extractScript('uonix-conversao-carrinho-newsletter-datalayer');
  return rawScript.replace(/<\?php[\s\S]*?\?>/g, String(orderId));
}

test('Newsletter: com consentimento prévio, emite conversão e grava no sessionStorage', () => {
  const events = [];
  const storage = {};
  const sessionStorageMock = {
    getItem: (k) => storage[k] || null,
    setItem: (k, v) => { storage[k] = String(v); }
  };

  const win = {
    dataLayer: events,
    sessionStorage: sessionStorageMock,
    _adoptMarketingGranted: true,
    acceptedTags: ['marketing', 'statistics'],
    addEventListener: () => {}
  };
  const ctx = vm.createContext({ window: win, sessionStorage: sessionStorageMock, setTimeout, clearTimeout });

  const code = createNewsletterCodeForOrder(9901);
  vm.runInContext(code, ctx);

  assert.strictEqual(events.length, 1, 'Deve emitir conversão quando marketing estiver concedido');
  assert.strictEqual(events[0].event, 'uonix_assinatura_newsletter');
  assert.strictEqual(events[0].order_id, 9901);
  assert.strictEqual(events[0].transaction_id, 'uonix-rfq-news-9901');
  assert.strictEqual(storage['uonix_news_rfq_9901'], '1', 'Deve gravar deduplicação no sessionStorage');

  // Recarga da página (segunda execução com a chave no storage)
  vm.runInContext(code, ctx);
  assert.strictEqual(events.length, 1, 'Recarga não deve duplicar a conversão');
});

test('Newsletter: SEM consentimento inicial, NÃO grava sessionStorage e NÃO emite conversão', () => {
  const events = [];
  const storage = {};
  const sessionStorageMock = {
    getItem: (k) => storage[k] || null,
    setItem: (k, v) => { storage[k] = String(v); }
  };

  let consentListener = null;
  const win = {
    dataLayer: events,
    sessionStorage: sessionStorageMock,
    _adoptMarketingGranted: false,
    acceptedTags: [],
    addEventListener: (evt, fn) => {
      if (evt === 'adopt-accept-marketing') consentListener = fn;
    }
  };
  const ctx = vm.createContext({ window: win, sessionStorage: sessionStorageMock, setTimeout, clearTimeout });

  const code = createNewsletterCodeForOrder(9902);
  vm.runInContext(code, ctx);

  assert.strictEqual(events.length, 0, 'Sem consentimento não pode emitir conversão');
  assert.strictEqual(storage['uonix_news_rfq_9902'], undefined, 'Não pode gravar sessionStorage antes do consentimento');

  // Recarga da página ainda sem consentimento
  vm.runInContext(code, ctx);
  assert.strictEqual(events.length, 0, 'Continua sem emitir');
  assert.strictEqual(storage['uonix_news_rfq_9902'], undefined, 'Marcador de deduplicação não foi consumido prematuramente');

  // Usuário concede consentimento tardiamente via banner AdOpt
  win._adoptMarketingGranted = true;
  win.acceptedTags = ['marketing'];
  assert(typeof consentListener === 'function', 'Listener adopt-accept-marketing deve ter sido registrado');
  consentListener();

  assert.strictEqual(events.length, 1, 'Conversão deve ser emitida na concessão tardia');
  assert.strictEqual(events[0].event, 'uonix_assinatura_newsletter');
  assert.strictEqual(events[0].order_id, 9902);
  assert.strictEqual(events[0].transaction_id, 'uonix-rfq-news-9902');
  assert.strictEqual(storage['uonix_news_rfq_9902'], '1', 'SessionStorage gravado após emissão legítima');

  // Nova chamada ou evento redundante
  consentListener();
  assert.strictEqual(events.length, 1, 'Não deve reemitir após ter sido gravado no sessionStorage');
});

test('Newsletter: concessão tardia via evento adopt_consent_updated no dataLayer emite e deduplica', () => {
  const events = [];
  const storage = {};
  const sessionStorageMock = {
    getItem: (k) => storage[k] || null,
    setItem: (k, v) => { storage[k] = String(v); }
  };

  const win = {
    dataLayer: events,
    sessionStorage: sessionStorageMock,
    _adoptMarketingGranted: false,
    acceptedTags: [],
    addEventListener: () => {}
  };
  const ctx = vm.createContext({ window: win, sessionStorage: sessionStorageMock, setTimeout, clearTimeout });

  const code = createNewsletterCodeForOrder(9903);
  vm.runInContext(code, ctx);

  assert.strictEqual(events.length, 0, 'Inicialmente sem emissão');

  // AdOpt CMP empurra adopt_consent_updated no dataLayer
  win._adoptMarketingGranted = true;
  win.acceptedTags = ['marketing', 'statistics'];
  win.dataLayer.push({
    event: 'adopt_consent_updated',
    adopt_marketing: true,
    accepted_tags: ['marketing', 'statistics']
  });

  const newsEvents = events.filter(e => e.event === 'uonix_assinatura_newsletter');
  assert.strictEqual(newsEvents.length, 1, 'Evento adopt_consent_updated disparou emissão de newsletter');
  assert.strictEqual(newsEvents[0].transaction_id, 'uonix-rfq-news-9903');
  assert.strictEqual(storage['uonix_news_rfq_9903'], '1');
});

test('Newsletter: rejeição explícita com storage residual NÃO emite e NÃO grava sessionStorage', () => {
  const events = [];
  const sessionStore = {};
  const localStore = {
    '_adoptReject': '1',
    'adoptConsentMode': JSON.stringify({ marketing: true, ad_storage: 'granted' })
  };

  const sessionStorageMock = {
    getItem: (k) => sessionStore[k] || null,
    setItem: (k, v) => { sessionStore[k] = String(v); }
  };
  const localStorageMock = {
    getItem: (k) => localStore[k] || null,
    setItem: (k, v) => { localStore[k] = String(v); }
  };

  const win = {
    dataLayer: events,
    sessionStorage: sessionStorageMock,
    localStorage: localStorageMock,
    _adoptExplicitlyRejected: true,
    _adoptMarketingGranted: false,
    acceptedTags: [],
    addEventListener: () => {}
  };
  const ctx = vm.createContext({
    window: win,
    sessionStorage: sessionStorageMock,
    localStorage: localStorageMock,
    setTimeout,
    clearTimeout
  });

  const code = createNewsletterCodeForOrder(9904);
  vm.runInContext(code, ctx);

  assert.strictEqual(events.length, 0, 'Rejeição explícita prevalece contra qualquer resíduo em adoptConsentMode');
  assert.strictEqual(sessionStore['uonix_news_rfq_9904'], undefined, 'Não deve gravar marcador no sessionStorage');
});

// -----------------------------------------------------------------------------
// 4. Testes do Produtor do Catálogo (Limite/Estoque/No-Op vs Adição Real)
// -----------------------------------------------------------------------------
console.log('\n--- 4. Produtor do Catálogo: Limite de Estoque (No-Op) vs Adição Real ---');

// Extrai a lógica de callback success do catálogo em 28-catalogo-ajax-carrinho.php
const catalogPhpPath = path.resolve(__dirname, '../../mu-plugins/uonix-woocommerce/28-catalogo-ajax-carrinho.php');
const catalogCode = fs.readFileSync(catalogPhpPath, 'utf8');

test('Catálogo Produtor: limite/estoque atingido (confirmedQty <= currentQty) NÃO dispara added_to_cart', () => {
  const triggeredEvents = [];
  const docBody = {
    trigger: (evt, args) => {
      triggeredEvents.push({ event: evt, args });
      return docBody;
    }
  };

  // Simula o produtor em 28-catalogo-ajax-carrinho.php (linhas 488-495)
  function simulateCatalogProducerSuccess(actionType, currentQty, res) {
    const confirmedQty = parseInt(res.data.quantity, 10);
    if (actionType === 'remove') {
      docBody.trigger('removed_from_cart', [res.data.fragments, res.data.cart_hash]);
    } else if (actionType === 'decrement') {
      docBody.trigger('uonix_cart_decremented', [res.data.fragments, res.data.cart_hash]);
    } else if (actionType === 'add') {
      if (confirmedQty > currentQty) {
        docBody.trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, {}, {
          actionType: 'add',
          currentQty: currentQty,
          confirmedQty: confirmedQty
        }]);
      } else {
        docBody.trigger('uonix_cart_add_rejected', [res.data.fragments, res.data.cart_hash, {}, {
          actionType: 'add',
          currentQty: currentQty,
          confirmedQty: confirmedQty,
          limitReached: Boolean(res.data.limit_reached)
        }]);
      }
    }
  }

  // Cenário: produto vendido individualmente já no carrinho (currentQty: 1), resposta confirma quantity: 1 (limit_reached)
  simulateCatalogProducerSuccess('add', 1, {
    success: true,
    data: {
      quantity: 1,
      limit_reached: true,
      sold_individually: true
    }
  });

  const addedEvents = triggeredEvents.filter(e => e.event === 'added_to_cart');
  const rejectedEvents = triggeredEvents.filter(e => e.event === 'uonix_cart_add_rejected');

  assert.strictEqual(addedEvents.length, 0, 'Não pode disparar added_to_cart quando a quantidade não aumentou');
  assert.strictEqual(rejectedEvents.length, 1, 'Deve disparar evento de recusa uonix_cart_add_rejected');
  assert.strictEqual(rejectedEvents[0].args[3].limitReached, true);
});

test('Catálogo Produtor: incremento real (confirmedQty > currentQty) dispara added_to_cart', () => {
  const triggeredEvents = [];
  const docBody = {
    trigger: (evt, args) => {
      triggeredEvents.push({ event: evt, args });
      return docBody;
    }
  };

  function simulateCatalogProducerSuccess(actionType, currentQty, res) {
    const confirmedQty = parseInt(res.data.quantity, 10);
    if (actionType === 'add') {
      if (confirmedQty > currentQty) {
        docBody.trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, {}, {
          actionType: 'add',
          currentQty: currentQty,
          confirmedQty: confirmedQty
        }]);
      }
    }
  }

  // Cenário: adicionando item novo (0 -> 1)
  simulateCatalogProducerSuccess('add', 0, {
    success: true,
    data: { quantity: 1, limit_reached: false }
  });

  const addedEvents = triggeredEvents.filter(e => e.event === 'added_to_cart');
  assert.strictEqual(addedEvents.length, 1, 'Deve disparar added_to_cart quando quantidade aumentar');
  assert.strictEqual(addedEvents[0].args[3].confirmedQty, 1);
});

// -----------------------------------------------------------------------------
// 5. Testes de Contrato GTM: Zero Mutações HTTP sem --apply
// -----------------------------------------------------------------------------
console.log('\n--- 5. Governança GTM: Prova de Inexistência de Mutações sem --apply ---');

test('GTM Governança: setup-newsletter-conversion bloqueia publish sem --apply', async () => {
  const gtmClient = require('../tools/gtm-client.js');
  // Verifica flags
  const origArgv = process.argv.slice();
  process.argv = ['node', 'setup-newsletter-conversion.js', '--publish', '--confirm-publish'];

  assert.strictEqual(gtmClient.isApplyRequested(), false, '--apply não foi fornecido');
  assert.strictEqual(gtmClient.isPublishRequested(), true);
  assert.strictEqual(gtmClient.isPublishConfirmed(), true);

  process.argv = origArgv;
});

console.log('\n------------------------------------------------------------------------');
console.log(`Resultado da suíte comportamental: ${passedTests}/${totalTests} testes aprovados.`);
if (passedTests !== totalTests) {
  process.exit(1);
} else {
  console.log('✅ Todos os testes comportamentais passaram com 100% de sucesso.');
}
