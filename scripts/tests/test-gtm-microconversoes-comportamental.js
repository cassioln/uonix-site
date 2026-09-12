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
const { syncGtmGovernance, computeSyncActions } = require('../tools/sync-canonical-gtm.js');
const { main: setupContact } = require('../tools/setup-contact-form-conversion.js');
const { main: setupDownload } = require('../tools/setup-download-checklist-conversion.js');
const { main: setupNewsletter } = require('../tools/setup-newsletter-conversion.js');
const {
  main: setupMetaPixel,
  EXPECTED_META_TAGS,
  validateMetaHtmlTag: validateMetaHtmlTagFn
} = require('../tools/setup-meta-pixel-conversions.js');

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

async function testAsync(name, fn) {
  totalTests++;
  try {
    await fn();
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
// -----------------------------------------------------------------------------
// 4. Testes do Produtor do Catálogo e Consumidor Real (Limite vs Adição Real)
// -----------------------------------------------------------------------------
console.log('\n--- 4. Produtor do Catálogo & Consumidor Real: Limite de Estoque vs Adição Real ---');

// Extrai o código JavaScript REAL do produtor no catálogo (28-catalogo-ajax-carrinho.php)
const catalogPhpPath = path.resolve(__dirname, '../../mu-plugins/uonix-woocommerce/28-catalogo-ajax-carrinho.php');
const catalogCode = fs.readFileSync(catalogPhpPath, 'utf8');
const catalogProducerMatch = catalogCode.match(/action:\s*['"]uonix_update_loop_cart_qty['"][\s\S]*?success:\s*function\s*\(([^)]+)\)\s*\{([\s\S]*?)\n\t\t\t\t\},\n\t\t\t\terror:/);
assert.ok(catalogProducerMatch, 'Código da callback success do catálogo deve ser extraído do PHP real');
const realCatalogProducerBody = catalogProducerMatch[2];

// Extrai o código JavaScript REAL do consumidor de analytics (38-integracoes-analytics-lgpd.php)
const integrationsPhpPath = path.resolve(__dirname, '../../mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php');
const integrationsCode = fs.readFileSync(integrationsPhpPath, 'utf8');
const cartConsumerMatch = integrationsCode.match(/window\.jQuery\(document\.body\)\.on\('added_to_cart',\s*function\(([^)]+)\)\s*\{([\s\S]*?)\n\s*\}\);/);
assert.ok(cartConsumerMatch, 'Código do listener added_to_cart deve ser extraído do PHP real');
const realCartConsumerBody = cartConsumerMatch[2];

function runRealCatalogProducer(actionType, currentQty, res, customBody) {
  const codeToRun = customBody || realCatalogProducerBody;
  const triggeredEvents = [];
  const wrapMock = {
    replaceWith: () => {},
    attr: () => {},
    removeClass: () => {},
    trigger: (evt, args) => {
      triggeredEvents.push({ event: evt, args });
    }
  };
  const sandbox = {
    res,
    actionType,
    currentQty,
    parseInt,
    Boolean,
    updateControlState: () => {},
    window: {},
    alert: () => {},
    $wrap: wrapMock,
    $: (selector) => {
      if (selector === 'document.body' || selector === sandbox.document?.body) {
        return sandbox.docBody;
      }
      return wrapMock;
    },
    docBody: {
      trigger: (evt, args) => {
        triggeredEvents.push({ event: evt, args });
      }
    }
  };
  sandbox.document = { body: sandbox.docBody };
  sandbox.$.each = () => {};
  const script = new vm.Script('(function() {\n' + codeToRun + '\n})();');
  const context = vm.createContext(sandbox);
  script.runInContext(context);
  return triggeredEvents;
}

function runRealCartConsumer(extraPayload, customBody) {
  const codeToRun = customBody || realCartConsumerBody;
  const dataLayer = [];
  const sandbox = {
    event: {},
    fragments: {},
    cart_hash: 'hash123',
    button: {},
    extra: extraPayload,
    dispararAdicionarCarrinho: (origem) => {
      dataLayer.push({ event: 'uonix_adicionar_ao_carrinho', origem });
    }
  };
  const script = new vm.Script('(function() {\n' + codeToRun + '\n})();');
  const context = vm.createContext(sandbox);
  script.runInContext(context);
  return dataLayer;
}

test('Produtor Real Catálogo: limite de estoque (confirmedQty <= currentQty) NÃO dispara added_to_cart', () => {
  const resLimit = { success: true, data: { quantity: 1, limit_reached: true, sold_individually: true } };
  const events = runRealCatalogProducer('add', 1, resLimit);

  const addedEvents = events.filter(e => e.event === 'added_to_cart');
  const rejectedEvents = events.filter(e => e.event === 'uonix_cart_add_rejected');

  assert.strictEqual(addedEvents.length, 0, 'Produtor real NÃO pode disparar added_to_cart quando quantidade não aumentou');
  assert.strictEqual(rejectedEvents.length, 1, 'Produtor real DEVE disparar uonix_cart_add_rejected');
  assert.strictEqual(rejectedEvents[0].args[3].limitReached, true, 'limitReached deve ser true');
});

test('Produtor Real Catálogo: incremento real (confirmedQty > currentQty) dispara added_to_cart', () => {
  const resAdd = { success: true, data: { quantity: 1, limit_reached: false } };
  const events = runRealCatalogProducer('add', 0, resAdd);

  const addedEvents = events.filter(e => e.event === 'added_to_cart');
  assert.strictEqual(addedEvents.length, 1, 'Produtor real DEVE disparar added_to_cart em adição real');
  assert.strictEqual(addedEvents[0].args[3].confirmedQty, 1);
});

test('Prova de Mutação do Produtor: afrouxamento da guarda (>=) falha a asserção de limite', () => {
  const mutatedBody = realCatalogProducerBody.replace('confirmedQty > currentQty', 'confirmedQty >= currentQty');
  const resLimit = { success: true, data: { quantity: 1, limit_reached: true, sold_individually: true } };
  const mutantEvents = runRealCatalogProducer('add', 1, resLimit, mutatedBody);

  const mutantAdded = mutantEvents.filter(e => e.event === 'added_to_cart');
  assert.strictEqual(mutantAdded.length, 1, 'Mutante gerou added_to_cart indevido no limite');
  // Prova de resistência: a asserção que exige 0 added_to_cart detectaria este mutante
  assert.notStrictEqual(mutantAdded.length, 0, 'Mutante é rejeitado pela asserção canônica');
});

test('Consumidor Real Analytics: bloqueia no-op (confirmedQty <= currentQty)', () => {
  const dataLayer = runRealCartConsumer({ actionType: 'add', currentQty: 1, confirmedQty: 1 });
  assert.strictEqual(dataLayer.length, 0, 'Consumidor real bloqueia evento de carrinho se confirmedQty <= currentQty');
});

test('Consumidor Real Analytics: emite conversão em adição real (confirmedQty > currentQty)', () => {
  const dataLayer = runRealCartConsumer({ actionType: 'add', currentQty: 0, confirmedQty: 1 });
  assert.strictEqual(dataLayer.length, 1, 'Consumidor real emite conversão em adição real');
  assert.strictEqual(dataLayer[0].event, 'uonix_adicionar_ao_carrinho');
});

test('Prova de Mutação do Consumidor: remoção da guarda faria o consumidor aceitar no-op', () => {
  const mutatedConsumer = realCartConsumerBody.replace('if (options.confirmedQty <= options.currentQty) {', 'if (false) {');
  const mutantDataLayer = runRealCartConsumer({ actionType: 'add', currentQty: 1, confirmedQty: 1 }, mutatedConsumer);
  assert.strictEqual(mutantDataLayer.length, 1, 'Mutante sem guarda deixaria passar conversão indevida');
  assert.notStrictEqual(mutantDataLayer.length, 0, 'Mutante do consumidor é detectado');
});

// -----------------------------------------------------------------------------
// 5. Governança e Contratos GTM: Validação Estrutural e Provas de Mutação
// -----------------------------------------------------------------------------
console.log('\n--- 5. Governança GTM: Validação Estrutural Estrita e Provas de Mutação ---');

const gtmClient = require('../tools/gtm-client.js');
const manifest = require('../../docs/gtm/uonix-google-ads-gtm-import.json');

test('GTM Governança: bloqueia publish sem --apply', async () => {
  const origArgv = process.argv.slice();
  process.argv = ['node', 'setup-newsletter-conversion.js', '--publish', '--confirm-publish'];

  assert.strictEqual(gtmClient.isApplyRequested(), false, '--apply não foi fornecido');
  assert.strictEqual(gtmClient.isPublishRequested(), true);
  assert.strictEqual(gtmClient.isPublishConfirmed(), true);

  process.argv = origArgv;
});

test('GTM Sincronizador: workspace vazio gera obrigatoriamente 39 ações canônicas', () => {
  const actions = computeSyncActions(manifest, { tags: [], triggers: [], variables: [] });
  assert.strictEqual(actions.length, 39, 'Workspace vazio deve gerar 39 ações de criação');
  assert.strictEqual(actions.filter(a => a.type === 'CREATE_TAG').length, 15, '15 CREATE_TAG');
  assert.strictEqual(actions.filter(a => a.type === 'CREATE_TRIGGER').length, 12, '12 CREATE_TRIGGER');
  assert.strictEqual(actions.filter(a => a.type === 'CREATE_VARIABLE').length, 12, '12 CREATE_VARIABLE');
});

test('GTM Sincronizador: workspace canônico idêntico gera 0 ações (100% aderente)', () => {
  const actions = computeSyncActions(manifest, {
    tags: manifest.containerVersion.tag,
    triggers: manifest.containerVersion.trigger,
    variables: manifest.containerVersion.variable
  });
  assert.strictEqual(actions.length, 0, 'Workspace canônico completo gera 0 ações');
});

test('Contrato Tag 47: validação estrutural canônica aprova a Tag 47 original', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  assert.ok(tag47, 'Tag 47 deve existir no manifesto');
  const res = gtmClient.validateAwctTagContract(tag47, tag47);
  assert.strictEqual(res.isAdherent, true, 'Tag 47 canônica é 100% aderente');
});

test('Prova de Mutação Contrato Tag 47: rejeita trigger adicional', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const mutated = JSON.parse(JSON.stringify(tag47));
  mutated.firingTriggerId.push('999');
  const res = gtmClient.validateAwctTagContract(mutated, tag47);
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar trigger adicional');
  assert.ok(res.differences.some(d => d.includes('firingTriggerId')));
});

test('Prova de Mutação Contrato Tag 47: rejeita parâmetro extra não canônico', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const mutated = JSON.parse(JSON.stringify(tag47));
  mutated.parameter.push({ type: 'template', key: 'extraParamNaoAutorizado', value: 'hack' });
  const res = gtmClient.validateAwctTagContract(mutated, tag47);
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar parâmetro extra');
});

test('Prova de Mutação Contrato Tag 47: rejeita parâmetro com tipo errado', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const mutated = JSON.parse(JSON.stringify(tag47));
  mutated.parameter[0].type = 'integer';
  const res = gtmClient.validateAwctTagContract(mutated, tag47);
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar tipo de parâmetro não-template');
});

test('Prova de Mutação Contrato Tag 47: rejeita consentType.type divergente de list', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const mutated = JSON.parse(JSON.stringify(tag47));
  mutated.consentSettings.consentType.type = 'string';
  const res = gtmClient.validateAwctTagContract(mutated, tag47);
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar consentType.type diferente de list');
});

test('Prova de Mutação Contrato Tag 47: rejeita item de consentimento com valor diferente de ad_storage', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const mutated = JSON.parse(JSON.stringify(tag47));
  mutated.consentSettings.consentType.list[0].value = 'analytics_storage';
  const res = gtmClient.validateAwctTagContract(mutated, tag47);
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar consentimento diferente de ad_storage');
});

test('Contrato Trigger 46: validação estrutural canônica aprova Trigger 46 original', () => {
  const tr46 = manifest.containerVersion.trigger.find(t => t.triggerId === '46');
  assert.ok(tr46, 'Trigger 46 deve existir no manifesto');
  const res = gtmClient.validateCustomEventTriggerContract(tr46, 'uonix_assinatura_newsletter');
  assert.strictEqual(res.isAdherent, true, 'Trigger 46 é 100% aderente');
});

test('Prova de Mutação Trigger 46: rejeita customEventFilter com negate !== false', () => {
  const tr46 = manifest.containerVersion.trigger.find(t => t.triggerId === '46');
  const mutated = JSON.parse(JSON.stringify(tr46));
  mutated.customEventFilter[0].negate = true;
  const res = gtmClient.validateCustomEventTriggerContract(mutated, 'uonix_assinatura_newsletter');
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar negate true em customEventFilter');
});

test('Prova de Mutação Trigger 46: rejeita arg0 divergente de {{_event}}', () => {
  const tr46 = manifest.containerVersion.trigger.find(t => t.triggerId === '46');
  const mutated = JSON.parse(JSON.stringify(tr46));
  mutated.customEventFilter[0].parameter.find(p => p.key === 'arg0').value = '{{evento}}';
  const res = gtmClient.validateCustomEventTriggerContract(mutated, 'uonix_assinatura_newsletter');
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar arg0 divergente de {{_event}}');
});

test('Prova de Mutação Trigger 46: rejeita filtro AdOpt com tipo divergente de contains', () => {
  const tr46 = manifest.containerVersion.trigger.find(t => t.triggerId === '46');
  const mutated = JSON.parse(JSON.stringify(tr46));
  mutated.filter[0].type = 'equals';
  const res = gtmClient.validateCustomEventTriggerContract(mutated, 'uonix_assinatura_newsletter');
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar filtro AdOpt com tipo diferente de contains');
});

test('Prova de Mutação Contrato Tag 47: rejeita tag com paused: true', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const mutated = JSON.parse(JSON.stringify(tag47));
  mutated.paused = true;
  const res = gtmClient.validateAwctTagContract(mutated, tag47);
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar tag pausada');
  assert.ok(res.differences.some(d => d.includes('pausada')));
});

test('Prova de Mutação Trigger 46: rejeita arg0 AdOpt não exato', () => {
  const tr46 = manifest.containerVersion.trigger.find(t => t.triggerId === '46');
  const mutated = JSON.parse(JSON.stringify(tr46));
  mutated.filter[0].parameter.find(p => p.key === 'arg0').value = '{{Outro_Tags_Aceitas_AdOpt}}';
  const res = gtmClient.validateCustomEventTriggerContract(mutated, 'uonix_assinatura_newsletter');
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar arg0 AdOpt não exato');
});

test('Prova de Mutação Trigger 46: rejeita filtros AdOpt duplicados ou extras', () => {
  const tr46 = manifest.containerVersion.trigger.find(t => t.triggerId === '46');
  const mutated = JSON.parse(JSON.stringify(tr46));
  mutated.filter.push(JSON.parse(JSON.stringify(mutated.filter[0])));
  const res = gtmClient.validateCustomEventTriggerContract(mutated, 'uonix_assinatura_newsletter');
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar filtros AdOpt duplicados/extras');
});

test('GTM Sincronizador: workspace vazio gera ações estritamente ordenadas por dependência', () => {
  const actions = computeSyncActions(manifest, { tags: [], triggers: [], variables: [] });
  assert.ok(actions.length > 0, 'Workspace vazio gera ações de criação');

  const varIndices = actions.map((a, idx) => a.type === 'CREATE_VARIABLE' ? idx : -1).filter(i => i >= 0);
  const triggerIndices = actions.map((a, idx) => a.type === 'CREATE_TRIGGER' ? idx : -1).filter(i => i >= 0);
  const tagIndices = actions.map((a, idx) => a.type === 'CREATE_TAG' ? idx : -1).filter(i => i >= 0);

  const maxVarIndex = Math.max(...varIndices);
  const minTriggerIndex = Math.min(...triggerIndices);
  const maxTriggerIndex = Math.max(...triggerIndices);
  const minTagIndex = Math.min(...tagIndices);

  assert.ok(maxVarIndex < minTriggerIndex, 'Todas as Variáveis devem ser criadas antes de qualquer Trigger');
  assert.ok(maxTriggerIndex < minTagIndex, 'Todos os Triggers devem ser criados antes de qualquer Tag');
});

test('GTM Sincronizador: detecta duplicatas de nome sob outro ID e agenda DELETE', () => {
  const tag47 = manifest.containerVersion.tag.find(t => t.tagId === '47');
  const duplicateTag = JSON.parse(JSON.stringify(tag47));
  duplicateTag.tagId = '999';

  const actions = computeSyncActions(manifest, {
    tags: [...manifest.containerVersion.tag, duplicateTag],
    triggers: manifest.containerVersion.trigger,
    variables: manifest.containerVersion.variable
  });

  const deleteAction = actions.find(a => a.type === 'DELETE_TAG' && a.id === '999');
  assert.ok(deleteAction, 'Deve agendar DELETE_TAG para a tag duplicada ID 999');
  assert.ok(deleteAction.description.includes('duplicada'));
});

test('GTM Sincronizador: detecta tag pausada no workspace e agenda atualização', () => {
  const tagsWithPaused = JSON.parse(JSON.stringify(manifest.containerVersion.tag));
  tagsWithPaused.find(t => t.tagId === '47').paused = true;

  const actions = computeSyncActions(manifest, {
    tags: tagsWithPaused,
    triggers: manifest.containerVersion.trigger,
    variables: manifest.containerVersion.variable
  });

  const updateAction = actions.find(a => a.type === 'UPDATE_TAG' && a.id === '47');
  assert.ok(updateAction, 'Deve agendar UPDATE_TAG para despausar a Tag 47');
  assert.ok(updateAction.differences.some(d => d.includes('pausada')));
});

test('Setup Newsletter: chamada de validação real rejeita tag incompleta', () => {
  const incompleteTag = {
    tagId: '47',
    type: 'awct',
    name: 'Google Ads - Conversão Assinatura Newsletter',
    parameter: [
      { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
      { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Assinatura Newsletter}}' }
      // falta orderId
    ],
    tagFiringOption: 'oncePerEvent',
    consentSettings: {
      consentStatus: 'needed',
      consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] }
    },
    firingTriggerId: ['46']
  };

  const expectedData = {
    name: 'Google Ads - Conversão Assinatura Newsletter',
    type: 'awct',
    parameter: [
      { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
      { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Assinatura Newsletter}}' },
      { type: 'template', key: 'orderId', value: '{{DLV - transaction_id}}' }
    ],
    firingTriggerId: ['46'],
    tagFiringOption: 'oncePerEvent',
    consentSettings: {
      consentStatus: 'needed',
      consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] }
    }
  };

  const res = gtmClient.validateAwctTagContract(incompleteTag, expectedData);
  assert.strictEqual(res.isAdherent, false, 'Validação real no setup deve acusar falta de orderId');
  assert.ok(res.differences.some(d => d.includes('orderId')));
});

test('Mock GTM Dangling Trigger Probe: execução sequencial em workspace vazio não deixa triggers pendentes', () => {
  const mockWorkspaceTriggers = new Set(['2147479553']); // Trigger nativo 'All Pages' do GTM
  const mockCreateTrigger = (t) => {
    mockWorkspaceTriggers.add(String(t.triggerId || t.name));
  };
  const mockCreateTag = (t) => {
    for (const tid of t.firingTriggerId || []) {
      if (!mockWorkspaceTriggers.has(String(tid))) {
        throw new Error(`MOCK_GTM_REJECT_DANGLING_TRIGGER:${tid}`);
      }
    }
  };

  const actions = computeSyncActions(manifest, { tags: [], triggers: [], variables: [] });
  for (const act of actions) {
    if (act.type === 'CREATE_TRIGGER') {
      mockCreateTrigger(act.expected);
    } else if (act.type === 'CREATE_TAG') {
      mockCreateTag(act.expected);
    }
  }
});

test('GTM Sincronizador: remoção ou alteração de priority na Tag AdOpt agenda UPDATE_TAG', () => {
  const tagsWithoutPriority = JSON.parse(JSON.stringify(manifest.containerVersion.tag));
  const tagAdopt = tagsWithoutPriority.find(t => t.name === 'Tag AdOpt');
  delete tagAdopt.priority;

  const actions = computeSyncActions(manifest, {
    tags: tagsWithoutPriority,
    triggers: manifest.containerVersion.trigger,
    variables: manifest.containerVersion.variable
  });

  const updateAction = actions.find(a => a.type === 'UPDATE_TAG' && a.name === 'Tag AdOpt');
  assert.ok(updateAction, 'Deve agendar UPDATE_TAG para restaurar a prioridade da Tag AdOpt');
  assert.ok(updateAction.differences.some(d => d.includes('priority')), 'Divergência de prioridade deve ser listada');
});

test('Validador AWCT: rejeita contrato com lista de parâmetros vazia (fail-closed)', () => {
  const sampleTag = {
    tagId: '54',
    name: 'Google Ads - Conversão - Contato Formulário',
    type: 'awct',
    parameter: [{ type: 'template', key: 'conversionId', value: '123' }],
    tagFiringOption: 'oncePerEvent',
    consentSettings: { consentStatus: 'needed', consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] } },
    firingTriggerId: ['53']
  };
  const res = gtmClient.validateAwctTagContract(sampleTag, { parameter: [] });
  assert.strictEqual(res.isAdherent, false, 'Deve rejeitar contrato com parâmetros vazios');
  assert.ok(res.differences.some(d => d.includes('vazia')), 'Deve reportar erro de lista vazia');
});

test('Validador AWCT: detecta divergência de conversionLabel com formato solto ou completo', () => {
  const tagWithWrongLabel = {
    tagId: '54',
    name: 'Google Ads - Conversão - Contato Formulário',
    type: 'awct',
    parameter: [
      { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
      { type: 'template', key: 'conversionLabel', value: 'LABEL_ERRADO_DELIBERADO' }
    ],
    tagFiringOption: 'oncePerEvent',
    consentSettings: { consentStatus: 'needed', consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] } },
    firingTriggerId: ['53']
  };

  // 1. Passando campos soltos
  const resLoose = gtmClient.validateAwctTagContract(tagWithWrongLabel, {
    conversionId: '{{Constante - Google Ads ID}}',
    conversionLabel: '{{Constante - Label Contato Formulario}}',
    firingTriggerId: '53'
  });
  assert.strictEqual(resLoose.isAdherent, false, 'Deve rejeitar label divergente mesmo com campos soltos');
  assert.ok(resLoose.differences.some(d => d.includes('conversionLabel') && d.includes('divergente')));

  // 2. Passando expectedTagData completo
  const expectedTagData = {
    name: 'Google Ads - Conversão - Contato Formulário',
    type: 'awct',
    parameter: [
      { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
      { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Contato Formulario}}' }
    ],
    firingTriggerId: ['53'],
    tagFiringOption: 'oncePerEvent',
    consentSettings: { consentStatus: 'needed', consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] } }
  };
  const resFull = gtmClient.validateAwctTagContract(tagWithWrongLabel, expectedTagData);
  assert.strictEqual(resFull.isAdherent, false, 'Deve rejeitar label divergente com expectedTagData completo');
  assert.ok(resFull.differences.some(d => d.includes('conversionLabel') && d.includes('divergente')));
});

(async () => {
  // -----------------------------------------------------------------------------
  // 6. Testes Comportamentais Reais de Governança GTM e Rollback Transacional
  // -----------------------------------------------------------------------------
  console.log('\n--- 6. Governança GTM: Rollback Transacional e Readback do Servidor ---');

  await testAsync('Sincronizador GTM: Rollback Transacional REAL reverte mutações em caso de falha durante aplicação', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-rollback-real';

    const manifestPath = path.resolve(__dirname, '../../docs/gtm/uonix-google-ads-gtm-import.json');
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

    const canonicalVars = manifest.containerVersion?.variable || [];
    const canonicalTriggers = manifest.containerVersion?.trigger || [];
    const canonicalTags = manifest.containerVersion?.tag || [];

    // Omitimos 1 variável e 1 trigger para que syncGtmGovernance planeje CREATE
    const initialVars = canonicalVars.slice(1);
    const initialTriggers = canonicalTriggers.slice(1);
    const initialTags = canonicalTags;

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        if (urlPath.endsWith('/variables')) return { variable: initialVars };
        if (urlPath.endsWith('/triggers')) return { trigger: initialTriggers };
        if (urlPath.endsWith('/tags')) return { tag: initialTags };
        return {};
      }

      if (method === 'POST') {
        if (urlPath.endsWith('/variables')) {
          return { variableId: '9991', name: body.name };
        }
        if (urlPath.endsWith('/triggers')) {
          throw new Error('SIMULATED_TRIGGER_MUTATION_FAILURE');
        }
      }

      if (method === 'DELETE') {
        return { success: true };
      }

      return {};
    };

    let caughtErr = null;
    try {
      await syncGtmGovernance({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        workspaceId: fakeWs,
        publish: false
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'syncGtmGovernance deve lançar exceção ao falhar uma mutação no servidor');
    assert.strictEqual(caughtErr.message, 'SIMULATED_TRIGGER_MUTATION_FAILURE');

    // PROVA DE MUTAÇÃO: A função REAL syncGtmGovernance deve ter acionado o rollback compensatório
    const deleteOps = operationsLog.filter(op => op.method === 'DELETE');
    assert.ok(deleteOps.length >= 1, 'Deve ter ocorrido pelo menos 1 DELETE compensatório');
    assert.ok(
      deleteOps.some(op => op.urlPath.includes('/variables/9991')),
      'A variável 9991 criada antes da falha deve ter sido revertida via DELETE compensatório'
    );
  });

  await testAsync('Sincronizador GTM: Readback pós-aplicação falho aciona rollback transacional de todas as mutações', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-readback-rollback';

    const manifestPath = path.resolve(__dirname, '../../docs/gtm/uonix-google-ads-gtm-import.json');
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

    const canonicalVars = manifest.containerVersion?.variable || [];
    const canonicalTriggers = manifest.containerVersion?.trigger || [];
    const canonicalTags = manifest.containerVersion?.tag || [];

    // Omitimos 1 variável para forçar CREATE
    const initialVars = canonicalVars.slice(1);
    const initialTriggers = canonicalTriggers;
    const initialTags = canonicalTags;

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        // No readback pós-aplicação, simula que o servidor GTM ainda não reflete a variável criada
        if (urlPath.endsWith('/variables')) return { variable: initialVars };
        if (urlPath.endsWith('/triggers')) return { trigger: initialTriggers };
        if (urlPath.endsWith('/tags')) return { tag: initialTags };
        return {};
      }

      if (method === 'POST') {
        if (urlPath.endsWith('/variables')) {
          return { variableId: '9995', name: body.name };
        }
      }

      if (method === 'DELETE') {
        return { success: true };
      }

      return {};
    };

    let caughtErr = null;
    try {
      await syncGtmGovernance({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        workspaceId: fakeWs,
        publish: false
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'Readback divergente deve lançar erro fail-closed');
    assert.ok(caughtErr.message.includes('[FAIL-CLOSED] Readback pós-aplicação detectou ações pendentes'));

    // PROVA DE ROLLBACK: Como o readback está dentro do try transacional, a entidade 9995 deve ter sofrido DELETE
    const deleteOps = operationsLog.filter(op => op.method === 'DELETE');
    assert.strictEqual(deleteOps.length, 1, 'Deve executar exatamente 1 DELETE compensatório');
    assert.ok(
      deleteOps[0].urlPath.includes('/variables/9995'),
      'DELETE compensatório executado para a variável 9995 devido a falha no readback'
    );
  });

  await testAsync('Setup Contato Form: Bloqueia publicação se o servidor GTM divergir do contrato no readback', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-contact-readback';

    const wrongServerTag = {
      tagId: '54',
      name: 'Google Ads - Conversão - Contato Formulário',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: 'WRONG_LABEL_STILL_ON_SERVER' }
      ],
      firingTriggerId: ['53'],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] }
      }
    };

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        if (urlPath.endsWith('/variables')) {
          return { variable: [{ name: 'Constante - Label Contato Formulario', variableId: '52', parameter: [{ key: 'value', value: 'RVZmCKznyfQcENifv9pE' }] }] };
        }
        if (urlPath.endsWith('/triggers')) {
          return { trigger: [{ name: 'Evento - Contato via Formulário', triggerId: '53', type: 'customEvent', customEventFilter: [{ type: 'equals', negate: false, parameter: [{ key: 'arg0', value: '{{_event}}' }, { key: 'arg1', value: 'uonix_contato_formulario' }] }] }] };
        }
        if (urlPath.endsWith('/tags')) {
          return { tag: [JSON.parse(JSON.stringify(wrongServerTag))] };
        }
        return {};
      }

      if (method === 'PUT') return body;

      if (urlPath.includes(':create_version') || urlPath.includes(':publish')) {
        throw new Error('SECURITY_BREACH: create_version ou publish NUNCA deveriam ser chamados em readback divergente!');
      }

      return {};
    };

    let caughtErr = null;
    try {
      await setupContact({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        publish: true,
        confirmPublish: true,
        workspaceId: fakeWs,
        throwOnError: true
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'Deve lançar erro ao detectar divergência no readback do servidor');
    assert.ok(caughtErr.message.includes('[FAIL-CLOSED] Publicação bloqueada'), 'Mensagem de erro esperada');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':create_version')), 'Jamais deve criar versão');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':publish')), 'Jamais deve publicar');
  });

  await testAsync('Setup Download Checklist: Bloqueia publicação se o servidor GTM divergir do contrato no readback', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-download-readback';

    const wrongServerTag = {
      tagId: '51',
      name: 'Google Ads - Conversão - Download Checklist Técnico',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: 'WRONG_LABEL_STILL_ON_SERVER' }
      ],
      firingTriggerId: ['50'],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] }
      }
    };

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        if (urlPath.endsWith('/variables')) {
          return { variable: [{ name: 'Constante - Label Download Checklist', variableId: '49', parameter: [{ key: 'value', value: 'nXYDCKKayPQcENifv9pE' }] }] };
        }
        if (urlPath.endsWith('/triggers')) {
          return { trigger: [{ name: 'Evento - Download Checklist Técnico', triggerId: '50', type: 'customEvent', customEventFilter: [{ type: 'equals', negate: false, parameter: [{ key: 'arg0', value: '{{_event}}' }, { key: 'arg1', value: 'uonix_download_checklist_tecnico' }] }] }] };
        }
        if (urlPath.endsWith('/tags')) {
          return { tag: [JSON.parse(JSON.stringify(wrongServerTag))] };
        }
        return {};
      }

      if (method === 'PUT') return body;

      if (urlPath.includes(':create_version') || urlPath.includes(':publish')) {
        throw new Error('SECURITY_BREACH: create_version ou publish NUNCA deveriam ser chamados em readback divergente!');
      }

      return {};
    };

    let caughtErr = null;
    try {
      await setupDownload({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        publish: true,
        confirmPublish: true,
        workspaceId: fakeWs,
        throwOnError: true
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'Deve lançar erro ao detectar divergência no readback do servidor');
    assert.ok(caughtErr.message.includes('[FAIL-CLOSED] Publicação bloqueada'), 'Mensagem de erro esperada');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':create_version')), 'Jamais deve criar versão');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':publish')), 'Jamais deve publicar');
  });

  await testAsync('Setup Newsletter: Bloqueia publicação se o servidor GTM divergir do contrato no readback', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-newsletter-readback';

    const wrongServerTag = {
      tagId: '47',
      name: 'Google Ads - Conversão - Assinatura Newsletter',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: 'WRONG_LABEL_STILL_ON_SERVER' }
      ],
      firingTriggerId: ['46'],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] }
      }
    };

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        if (urlPath.endsWith('/variables')) {
          return { variable: [{ name: 'Constante - Label Assinatura Newsletter', variableId: '45', parameter: [{ key: 'value', value: 'PFrxCKf1w_QcENifv9pE' }] }] };
        }
        if (urlPath.endsWith('/triggers')) {
          return { trigger: [{ name: 'Evento - Assinatura Newsletter Uônix', triggerId: '46', type: 'customEvent', customEventFilter: [{ type: 'equals', negate: false, parameter: [{ key: 'arg0', value: '{{_event}}' }, { key: 'arg1', value: 'uonix_assinatura_newsletter' }] }] }] };
        }
        if (urlPath.endsWith('/tags')) {
          return { tag: [JSON.parse(JSON.stringify(wrongServerTag))] };
        }
        return {};
      }

      if (method === 'PUT') return body;

      if (urlPath.includes(':create_version') || urlPath.includes(':publish')) {
        throw new Error('SECURITY_BREACH: create_version ou publish NUNCA deveriam ser chamados em readback divergente!');
      }

      return {};
    };

    let caughtErr = null;
    try {
      await setupNewsletter({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        publish: true,
        confirmPublish: true,
        workspaceId: fakeWs,
        throwOnError: true
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'Deve lançar erro ao detectar divergência no readback do servidor');
    assert.ok(caughtErr.message.includes('[FAIL-CLOSED] Publicação bloqueada'), 'Mensagem de erro esperada');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':create_version')), 'Jamais deve criar versão');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':publish')), 'Jamais deve publicar');
  });

  // -----------------------------------------------------------------------------
  // 6. Automação e Validação do Meta Pixel (Facebook Ads)
  // -----------------------------------------------------------------------------
  console.log('\n--- 6. Automação e Validação do Meta Pixel (Facebook Ads) ---');

  test('Meta Pixel: EXPECTED_META_TAGS declara exatamente 9 eventos padrão com governança LGPD e triggers mapeados', () => {
    assert.strictEqual(EXPECTED_META_TAGS.length, 9, 'Devem existir exatamente 9 definições de tags');
    const expectedNames = [
      'Meta Pixel - Conversão - Solicitar Orçamento',
      'Meta Pixel - Conversão - Contato Formulário',
      'Meta Pixel - Conversão - Download Checklist Técnico',
      'Meta Pixel - Conversão - Assinatura Newsletter',
      'Meta Pixel - Conversão - Adicionar ao Carrinho',
      'Meta Pixel - Conversão - Iniciar Finalização',
      'Meta Pixel - Conversão - WhatsApp',
      'Meta Pixel - Conversão - Telefone',
      'Meta Pixel - Conversão - Email'
    ];
    for (const name of expectedNames) {
      const found = EXPECTED_META_TAGS.find(t => t.name === name);
      assert.ok(found, `Tag ${name} deve estar definida em EXPECTED_META_TAGS`);
      assert.ok(found.html.includes("if (typeof fbq !== 'function') return;"), `Tag ${name} deve conter guarda de segurança fbq`);
      assert.ok(found.triggerName && found.fallbackTriggerId, `Tag ${name} deve ter gatilho e fallback definidos`);
    }

    // Deduplicação via eventID para orçamentos e newsletters
    const leadTag = EXPECTED_META_TAGS.find(t => t.name === 'Meta Pixel - Conversão - Solicitar Orçamento');
    assert.ok(leadTag.html.includes('eventID: txId || (orderId ? \'uonix-rfq-\' + orderId : undefined)'), 'Lead deve incluir eventID para deduplicação CAPI');

    const newsTag = EXPECTED_META_TAGS.find(t => t.name === 'Meta Pixel - Conversão - Assinatura Newsletter');
    assert.ok(newsTag.html.includes('eventID: txId'), 'Subscribe deve incluir eventID para deduplicação CAPI');
  });

  test('Meta Pixel validateMetaHtmlTag: aprova tag perfeitamente aderente ao contrato', () => {
    const sampleDef = EXPECTED_META_TAGS[0];
    const validTag = {
      name: sampleDef.name,
      type: 'html',
      parameter: [
        { type: 'template', key: 'html', value: sampleDef.html },
        { type: 'boolean', key: 'supportDocumentWrite', value: 'false' }
      ],
      firingTriggerId: [sampleDef.fallbackTriggerId],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      }
    };
    const res = validateMetaHtmlTagFn(validTag, sampleDef, sampleDef.fallbackTriggerId);
    assert.strictEqual(res.isAdherent, true, 'Tag perfeitamente aderente deve ser aprovada');
    assert.strictEqual(res.differences.length, 0, 'Não deve haver diferenças');
  });

  test('Meta Pixel validateMetaHtmlTag: rejeita tag com snippet divergente, tipo incorreto ou gatilho errado', () => {
    const sampleDef = EXPECTED_META_TAGS[0];
    const invalidTag = {
      name: sampleDef.name,
      type: 'awct', // incorreto: deveria ser html
      parameter: [
        { type: 'template', key: 'html', value: '<script>alert(1);</script>' }
      ],
      firingTriggerId: ['999'], // incorreto
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      }
    };
    const res = validateMetaHtmlTagFn(invalidTag, sampleDef, sampleDef.fallbackTriggerId);
    assert.strictEqual(res.isAdherent, false, 'Tag inválida deve ser rejeitada');
    assert.ok(res.differences.some(d => d.includes('Tipo divergente')), 'Deve reportar tipo divergente');
    assert.ok(res.differences.some(d => d.includes('Snippet HTML do Meta Pixel diverge')), 'Deve reportar snippet divergente');
    assert.ok(res.differences.some(d => d.includes('firingTriggerId divergente')), 'Deve reportar trigger divergente');
  });

  test('Meta Pixel validateMetaHtmlTag: rejeita tag com consentimento ausente ou sem ad_storage', () => {
    const sampleDef = EXPECTED_META_TAGS[0];
    const noConsentTag = {
      name: sampleDef.name,
      type: 'html',
      parameter: [{ type: 'template', key: 'html', value: sampleDef.html }],
      firingTriggerId: [sampleDef.fallbackTriggerId],
      tagFiringOption: 'oncePerEvent'
    };
    const resNoConsent = validateMetaHtmlTagFn(noConsentTag, sampleDef, sampleDef.fallbackTriggerId);
    assert.strictEqual(resNoConsent.isAdherent, false);
    assert.ok(resNoConsent.differences.some(d => d.includes('consentSettings.consentStatus')));

    const wrongConsentTag = {
      ...noConsentTag,
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'analytics_storage' }]
        }
      }
    };
    const resWrongConsent = validateMetaHtmlTagFn(wrongConsentTag, sampleDef, sampleDef.fallbackTriggerId);
    assert.strictEqual(resWrongConsent.isAdherent, false);
    assert.ok(resWrongConsent.differences.some(d => d.includes('consentType deve exigir "ad_storage"')));
  });

  await testAsync('Setup Meta Pixel: Rollback transacional compensatório desfaz mutações se erro ocorrer durante a criação', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-meta-rollback';
    let tagCreationCount = 0;

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        if (urlPath.endsWith('/variables')) {
          return { variable: [] };
        }
        if (urlPath.endsWith('/triggers')) {
          return {
            trigger: [
              { triggerId: '52', name: 'Evento - Solicitar Orçamento Uônix' },
              { triggerId: '53', name: 'Evento - Contato via Formulário' },
              { triggerId: '50', name: 'Evento - Download Checklist Técnico' },
              { triggerId: '46', name: 'Evento - Assinatura Newsletter Uônix' },
              { triggerId: '55', name: 'Evento - Adicionar ao Carrinho Uônix' },
              { triggerId: '56', name: 'Evento - Iniciar Finalização Uônix' },
              { triggerId: '10', name: 'Clique - WhatsApp Links' },
              { triggerId: '36', name: 'Clique - Telefone tel' },
              { triggerId: '37', name: 'Clique - Email mailto' }
            ]
          };
        }
        if (urlPath.endsWith('/tags')) {
          return { tag: [] };
        }
        return {};
      }

      if (method === 'POST') {
        if (urlPath.endsWith('/variables')) {
          return { ...body, variableId: 'var-meta-id-created' };
        }
        if (urlPath.endsWith('/tags')) {
          tagCreationCount++;
          if (tagCreationCount >= 3) {
            throw new Error('SIMULATED_GTM_API_NETWORK_FAILURE_ON_TAG_3');
          }
          return { ...body, tagId: `tag-meta-${tagCreationCount}` };
        }
      }

      if (method === 'DELETE') {
        return {};
      }

      return {};
    };

    let caughtErr = null;
    try {
      await setupMetaPixel({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        publish: false,
        workspaceId: fakeWs,
        throwOnError: true
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'Deve lançar exceção no erro simulado');
    assert.ok(caughtErr.message.includes('SIMULATED_GTM_API_NETWORK_FAILURE_ON_TAG_3'));

    const deletes = operationsLog.filter(op => op.method === 'DELETE');
    assert.ok(deletes.some(d => d.urlPath.includes('/tags/tag-meta-1')), 'Tag 1 criada deve ser desfeita via DELETE');
    assert.ok(deletes.some(d => d.urlPath.includes('/tags/tag-meta-2')), 'Tag 2 criada deve ser desfeita via DELETE');
    assert.ok(deletes.some(d => d.urlPath.includes('/variables/var-meta-id-created')), 'Variável criada deve ser desfeita via DELETE');
  });

  await testAsync('Setup Meta Pixel: Bloqueia publicação se o servidor GTM divergir do contrato no readback (fail-closed)', async () => {
    const operationsLog = [];
    const fakeWs = 'ws-test-meta-readback';

    const corruptedServerTag = {
      tagId: 'meta-corrupted-tag',
      name: 'Meta Pixel - Conversão - Solicitar Orçamento',
      type: 'html',
      parameter: [
        { type: 'template', key: 'html', value: '<script>// Corrupted tag payload</script>' }
      ],
      firingTriggerId: ['52'],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: { type: 'list', list: [{ type: 'template', value: 'ad_storage' }] }
      }
    };

    let serverTags = [JSON.parse(JSON.stringify(corruptedServerTag))];
    let createdCount = 0;

    const fakeGtmRequest = async (method, urlPath, body) => {
      operationsLog.push({ method, urlPath, body });

      if (method === 'GET') {
        if (urlPath.endsWith('/variables')) {
          return { variable: [{ name: 'Constante - Meta Pixel ID', variableId: '461', parameter: [{ key: 'value', value: '461430774437593' }] }] };
        }
        if (urlPath.endsWith('/triggers')) {
          return {
            trigger: [
              { triggerId: '52', name: 'Evento - Solicitar Orçamento Uônix' },
              { triggerId: '53', name: 'Evento - Contato via Formulário' },
              { triggerId: '50', name: 'Evento - Download Checklist Técnico' },
              { triggerId: '46', name: 'Evento - Assinatura Newsletter Uônix' },
              { triggerId: '55', name: 'Evento - Adicionar ao Carrinho Uônix' },
              { triggerId: '56', name: 'Evento - Iniciar Finalização Uônix' },
              { triggerId: '10', name: 'Clique - WhatsApp Links' },
              { triggerId: '36', name: 'Clique - Telefone tel' },
              { triggerId: '37', name: 'Clique - Email mailto' }
            ]
          };
        }
        if (urlPath.endsWith('/tags')) {
          // No readback, simula que o servidor manteve a tag 1 corrompida mesmo após PUT
          return { tag: JSON.parse(JSON.stringify(serverTags)) };
        }
        return {};
      }

      if (method === 'POST' && urlPath.endsWith('/tags')) {
        createdCount++;
        const newTag = { ...body, tagId: `tag-created-${createdCount}` };
        serverTags.push(newTag);
        return newTag;
      }

      if (method === 'PUT' && urlPath.includes('/tags/')) {
        // Simula falha silenciosa do servidor remoto que não persistiu o PUT
        return corruptedServerTag;
      }

      if (method === 'DELETE') {
        return {};
      }

      if (urlPath.includes(':create_version') || urlPath.includes(':publish')) {
        throw new Error('SECURITY_BREACH: create_version ou publish NUNCA deveriam ser chamados em readback divergente do Meta Pixel!');
      }

      return {};
    };

    let caughtErr = null;
    try {
      await setupMetaPixel({
        customGtmRequest: fakeGtmRequest,
        apply: true,
        publish: true,
        confirmPublish: true,
        workspaceId: fakeWs,
        throwOnError: true
      });
    } catch (err) {
      caughtErr = err;
    }

    assert.ok(caughtErr, 'Deve lançar erro ao detectar divergência no readback do Meta Pixel');
    assert.ok(caughtErr.message.includes('[FAIL-CLOSED] Readback pós-aplicação falhou'), 'Mensagem de erro esperada');
    assert.ok(caughtErr.message.includes('Snippet HTML do Meta Pixel diverge'), 'Deve reportar a divergência de snippet');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':create_version')), 'Jamais deve criar versão');
    assert.ok(!operationsLog.some(op => op.urlPath.includes(':publish')), 'Jamais deve publicar');
  });

  console.log('\n------------------------------------------------------------------------');
  console.log(`Resultado da suíte comportamental: ${passedTests}/${totalTests} testes aprovados.`);
  if (passedTests !== totalTests) {
    process.exit(1);
  } else {
    console.log('✅ Todos os testes comportamentais passaram com 100% de sucesso.');
  }
})();
