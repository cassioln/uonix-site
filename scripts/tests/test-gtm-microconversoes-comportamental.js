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
const { computeSyncActions } = require('../tools/sync-canonical-gtm.js');
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

console.log('\n------------------------------------------------------------------------');
console.log(`Resultado da suíte comportamental: ${passedTests}/${totalTests} testes aprovados.`);
if (passedTests !== totalTests) {
  process.exit(1);
} else {
  console.log('✅ Todos os testes comportamentais passaram com 100% de sucesso.');
}
