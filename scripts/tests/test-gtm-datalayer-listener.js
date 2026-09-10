/**
 * Testes unitários e de mutação para o listener do dataLayer de conversão estrita.
 *
 * Garante que:
 * 1. Apenas form_assunto === 'orcamento' emite o evento uonix_solicitar_orcamento;
 * 2. Todos os assuntos alternativos (info, produtos, apoio, garantia, laudos, parcerias,
 *    fornecedores, rh, feedback, lgpd, outros, vazio, indefinido) NÃO emitem evento;
 * 3. Suporta tanto eventos disparados via jQuery quanto via CustomEvent DOM nativo;
 * 4. Uma mutação trocando a guarda por true é detectada e reprovada com erro.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const phpPath = path.resolve(__dirname, '../../mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php');
const phpCode = fs.readFileSync(phpPath, 'utf8');

const match = phpCode.match(/<script id="uonix-conversao-orcamento-datalayer">([\s\S]*?)<\/script>/);
if (!match) {
  console.error('FAIL: Script uonix-conversao-orcamento-datalayer nao encontrado no PHP!');
  process.exit(1);
}

const scriptCode = match[1];

function simulateSubmission(codeToRun, assunto, useJQuery) {
  const events = [];
  let currentAssunto = assunto;

  const formElem = {
    querySelector: (sel) => {
      if (sel && sel.includes('form_assunto')) {
        return currentAssunto !== null ? { value: currentAssunto } : null;
      }
      return null;
    }
  };

  const docListeners = {};
  const doc = {
    readyState: 'complete',
    addEventListener: (type, fn) => {
      docListeners[type] = fn;
    },
    querySelector: (sel) => {
      if (sel === '#fluentform_3') return formElem;
      return null;
    }
  };

  const jqHandlers = {};
  const jqObj = {
    off: () => jqObj,
    on: (evt, handler) => {
      jqHandlers[evt] = handler;
      return jqObj;
    }
  };

  const win = {
    dataLayer: events,
    document: doc,
    jQuery: useJQuery ? () => jqObj : undefined
  };

  const ctx = vm.createContext({
    window: win,
    document: doc,
    setTimeout,
    clearTimeout
  });

  vm.runInContext(codeToRun, ctx);

  if (useJQuery) {
    const handler = jqHandlers['fluentform_submission_success.uonixOrcamento'];
    if (!handler) throw new Error('Handler jQuery nao registrado');
    handler({}, { formId: '3', form_id: 3 });
  } else {
    const handler = docListeners['fluentform_submission_success'];
    if (!handler) throw new Error('Listener DOM nativo nao registrado');
    handler({ detail: { formId: '3', form: formElem } });
  }

  return events;
}

function simulateCrossFormSubmission(codeToRun) {
  const events = [];
  const docListeners = {};
  const jqHandlers = {};
  const budgetSubject = { name: 'form_assunto', value: 'orcamento' };
  const newsletterForm = { querySelector: () => null };
  const doc = {
    readyState: 'complete',
    addEventListener: (type, fn) => {
      docListeners[type] = fn;
    },
    querySelector: (sel) => sel === '#fluentform_99' ? newsletterForm : null
  };
  const jqObj = {
    off: () => jqObj,
    on: (evt, handler) => {
      jqHandlers[evt] = handler;
      return jqObj;
    }
  };
  const win = {
    dataLayer: events,
    document: doc,
    jQuery: () => jqObj
  };
  const ctx = vm.createContext({
    window: win,
    document: doc,
    setTimeout,
    clearTimeout
  });

  vm.runInContext(codeToRun, ctx);
  if (docListeners.change) {
    docListeners.change({ target: budgetSubject });
  }
  jqHandlers['fluentform_submission_success.uonixOrcamento']({}, { formId: '99', form_id: 99 });

  return events;
}

let failures = 0;
function assert(condition, message) {
  if (!condition) {
    failures++;
    console.error(`FAIL: ${message}`);
  } else {
    console.log(`PASS: ${message}`);
  }
}

// 1. Caminho positivo: orcamento deve emitir evento
const evJqOrcamento = simulateSubmission(scriptCode, 'orcamento', true);
assert(
  evJqOrcamento.length === 1 &&
  evJqOrcamento[0].event === 'uonix_solicitar_orcamento' &&
  evJqOrcamento[0].origem_conversao === 'fluentform_orcamento' &&
  evJqOrcamento[0].form_id === '3',
  'jQuery: form_assunto=orcamento dispara uonix_solicitar_orcamento com metadados corretos'
);

const evDomOrcamento = simulateSubmission(scriptCode, 'orcamento', false);
assert(
  evDomOrcamento.length === 1 &&
  evDomOrcamento[0].event === 'uonix_solicitar_orcamento' &&
  evDomOrcamento[0].origem_conversao === 'fluentform_orcamento',
  'DOM nativo: form_assunto=orcamento dispara uonix_solicitar_orcamento'
);

// 2. Caminhos negativos: TODOS os outros assuntos NAO devem emitir evento
const nonOrcamentoAssuntos = [
  'info',
  'produtos',
  'apoio',
  'garantia',
  'laudos',
  'parcerias',
  'fornecedores',
  'rh',
  'feedback',
  'lgpd',
  'outros',
  'duvidas',
  'comercial',
  '',
  '   ',
  null
];

for (const assunto of nonOrcamentoAssuntos) {
  const evJq = simulateSubmission(scriptCode, assunto, true);
  assert(
    evJq.length === 0,
    `jQuery: form_assunto="${assunto}" NAO dispara evento (bloqueado estritamente)`
  );

  const evDom = simulateSubmission(scriptCode, assunto, false);
  assert(
    evDom.length === 0,
    `DOM nativo: form_assunto="${assunto}" NAO dispara evento (bloqueado estritamente)`
  );
}

const crossFormEvents = simulateCrossFormSubmission(scriptCode);
assert(
  crossFormEvents.every(event => !event || event.event !== 'uonix_solicitar_orcamento'),
  'jQuery: assunto abandonado em outro formulario nao gera conversao no formulario concluido'
);

// 3. Teste de mutação: enfraquecer a guarda precisa ser detectado
const mutatedScript = scriptCode.replace(/val === ['"]orcamento['"]/g, 'true');
if (mutatedScript === scriptCode) {
  console.error('FAIL: Falha ao aplicar mutacao de teste (padrao val === "orcamento" nao encontrado)');
  process.exit(1);
}

const mutatedEvents = simulateSubmission(mutatedScript, 'info', true);
assert(
  mutatedEvents.length > 0,
  'Teste de mutacao: trocar guarda por "true" quebra o teste negativo de form_assunto=info'
);

// =============================================================================
// 4. Testes de integridade e revogacao LGPD da ponte AdOpt (window.acceptedTags)
// =============================================================================
const bridgeMatch = phpCode.match(/<script id="uonix-adopt-categories-bridge">([\s\S]*?)<\/script>/);
if (!bridgeMatch) {
  console.error('FAIL: Script uonix-adopt-categories-bridge nao encontrado no PHP!');
  process.exit(1);
}
const bridgeCode = bridgeMatch[1];

function createBridgeEnvironment(initialLocalStorage = {}, initialAcceptedTags = [], options = {}) {
  const store = { ...initialLocalStorage };
  const storageListeners = [];
  const events = [];

  const localStorage = {
    getItem: (key) => (key in store ? store[key] : null),
    setItem: (key, val) => {
      if (options.ignoreWrites) return;
      store[key] = String(val);
      storageListeners.forEach(fn => fn({ key }));
    },
    removeItem: (key) => {
      if (options.ignoreWrites) return;
      delete store[key];
      storageListeners.forEach(fn => fn({ key }));
    }
  };

  const doc = {
    readyState: 'complete',
    addEventListener: () => {}
  };

  const win = {
    localStorage,
    dataLayer: events,
    acceptedTags: [...initialAcceptedTags],
    addEventListener: (type, fn) => {
      if (type === 'storage') storageListeners.push(fn);
    }
  };

  const ctx = vm.createContext({
    window: win,
    document: doc,
    localStorage,
    setTimeout,
    clearTimeout
  });

  return { ctx, win, events, localStorage };
}

// 4.1 Inicialização sem consentimento
const envInit = createBridgeEnvironment();
vm.runInContext(bridgeCode, envInit.ctx);
assert(!envInit.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: inicializacao sem consentimento nao inclui marketing');
assert(!envInit.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: inicializacao sem consentimento nao inclui statistics');

// 4.2 Aceite de marketing via evento dataLayer
envInit.win.dataLayer.push('adopt-accept-marketing');
assert(envInit.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: adopt-accept-marketing adiciona marketing a window.acceptedTags');

// 4.3 Revogação de marketing após aceite
envInit.localStorage.setItem('adoptConsentMode', JSON.stringify({ marketing: false, statistics: true }));
assert(!envInit.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: revogacao de marketing remove estritamente marketing de window.acceptedTags');
assert(envInit.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: statistics e adicionado quando concedido');

// 4.4 Revogação de statistics após aceite
envInit.localStorage.setItem('adoptConsentMode', JSON.stringify({ marketing: false, statistics: false }));
assert(!envInit.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: revogacao de statistics remove estritamente statistics de window.acceptedTags');

// 4.5 Rejeição total via _adoptReject === '1'
const envReject = createBridgeEnvironment({ adoptConsentMode: JSON.stringify({ marketing: true, statistics: true }) });
vm.runInContext(bridgeCode, envReject.ctx);
assert(envReject.win.acceptedTags.includes('marketing') && envReject.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: consentimento previo inicializa com marketing e statistics');
envReject.localStorage.setItem('_adoptReject', '1');
assert(!envReject.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: _adoptReject=1 remove marketing de window.acceptedTags');
assert(!envReject.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: _adoptReject=1 remove statistics de window.acceptedTags');

// 4.6 Preservação de categorias/tags de terceiros não gerenciadas
const thirdPartyTags = ['ExClwcP566', 'uXT4q-bT28'];
const envThirdParty = createBridgeEnvironment({ adoptConsentMode: JSON.stringify({ marketing: true, statistics: false }) }, thirdPartyTags);
vm.runInContext(bridgeCode, envThirdParty.ctx);
assert(
  envThirdParty.win.acceptedTags.includes('ExClwcP566') && envThirdParty.win.acceptedTags.includes('uXT4q-bT28'),
  'Ponte AdOpt: tags de terceiros nao gerenciadas sao preservadas na inicializacao'
);
assert(envThirdParty.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: marketing adicionado junto com terceiros');

// Revoga marketing em ambiente com tags de terceiros
envThirdParty.localStorage.setItem('adoptConsentMode', JSON.stringify({ marketing: false, statistics: false }));
assert(
  envThirdParty.win.acceptedTags.includes('ExClwcP566') && envThirdParty.win.acceptedTags.includes('uXT4q-bT28'),
  'Ponte AdOpt: tags de terceiros permanecem intactas apos revogacao de consentimento'
);
assert(!envThirdParty.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: marketing removido sem afetar terceiros');

// 4.7 Emissão do evento de sincronização adopt_consent_updated no dataLayer
const syncEvents = envThirdParty.events.filter(e => e && e.event === 'adopt_consent_updated');
assert(syncEvents.length > 0, 'Ponte AdOpt: dispara adopt_consent_updated no dataLayer apos mudanca');
const lastSync = syncEvents[syncEvents.length - 1];
assert(
  lastSync.adopt_marketing === false && lastSync.adopt_statistics === false,
  'Ponte AdOpt: adopt_consent_updated reflete estado revogado de marketing e statistics'
);

// 4.8 Teste de mutação LGPD: remover a remoção de marketing deve falhar na revogação
const mutatedBridge = bridgeCode.replace("tag !== 'marketing' && tag !== 'statistics'", "true");
if (mutatedBridge === bridgeCode) {
  console.error('FAIL: Falha ao aplicar mutacao na ponte AdOpt');
  process.exit(1);
}
const envMutBridge = createBridgeEnvironment();
vm.runInContext(mutatedBridge, envMutBridge.ctx);
envMutBridge.win.dataLayer.push('adopt-accept-marketing');
// Com a mutacao, simula revogacao
envMutBridge.localStorage.setItem('adoptConsentMode', JSON.stringify({ marketing: false, statistics: false }));
const mutationDetected = envMutBridge.win.acceptedTags.includes('marketing');
assert(
  mutationDetected,
  'Teste de mutacao LGPD: supressao da remocao de marketing deixa residuo em acceptedTags (detectado com sucesso)'
);

// 4.9 Integração AdOpt: Evento adopt-reject-all com storage residual (cenário exato do revisor)
const envRejectAllEvent = createBridgeEnvironment(
  { adoptConsentMode: JSON.stringify({ marketing: true, statistics: true }) },
  ['ExClwcP566']
);
vm.runInContext(bridgeCode, envRejectAllEvent.ctx);
assert(
  envRejectAllEvent.win.acceptedTags.includes('marketing') &&
  envRejectAllEvent.win.acceptedTags.includes('statistics') &&
  envRejectAllEvent.win.acceptedTags.includes('ExClwcP566'),
  'Ponte AdOpt: inicializa com marketing, statistics e terceiros'
);

// Dispara adopt-reject-all pelo dataLayer
envRejectAllEvent.win.dataLayer.push('adopt-reject-all');

// (1) Ambas as categorias removidas imediatamente
assert(!envRejectAllEvent.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: adopt-reject-all remove marketing imediatamente');
assert(!envRejectAllEvent.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: adopt-reject-all remove statistics imediatamente');

// (2) Categorias de terceiros preservadas
assert(envRejectAllEvent.win.acceptedTags.includes('ExClwcP566'), 'Ponte AdOpt: adopt-reject-all preserva categorias de terceiros');

// (3) Evento de atualizacao emitido com false/false
const rejectAllSync = envRejectAllEvent.events.filter(e => e && e.event === 'adopt_consent_updated');
assert(rejectAllSync.length > 0, 'Ponte AdOpt: adopt-reject-all dispara adopt_consent_updated');
const lastRejectSync = rejectAllSync[rejectAllSync.length - 1];
assert(
  lastRejectSync.adopt_marketing === false && lastRejectSync.adopt_statistics === false,
  'Ponte AdOpt: adopt_consent_updated reflete false/false apos adopt-reject-all'
);

// (4) Nova aceitação volta a habilitar APENAS a categoria aceita
envRejectAllEvent.win.dataLayer.push('adopt-accept-marketing');
assert(
  envRejectAllEvent.win.acceptedTags.includes('marketing'),
  'Ponte AdOpt: nova aceitacao com adopt-accept-marketing reabilita marketing'
);
assert(
  !envRejectAllEvent.win.acceptedTags.includes('statistics'),
  'Ponte AdOpt: nova aceitacao com adopt-accept-marketing mantem statistics revogado'
);
assert(
  envRejectAllEvent.win.acceptedTags.includes('ExClwcP566'),
  'Ponte AdOpt: terceiros permanecem preservados apos re-aceite'
);

// (5) Disparo de adopt-reject revoga novamente
envRejectAllEvent.win.dataLayer.push('adopt-reject');
assert(!envRejectAllEvent.win.acceptedTags.includes('marketing'), 'Ponte AdOpt: adopt-reject revoga marketing novamente');
assert(!envRejectAllEvent.win.acceptedTags.includes('statistics'), 'Ponte AdOpt: adopt-reject mantem statistics revogado');

// 4.10 Teste de mutação LGPD para adopt-reject-all: remover a revogação explícita deve falhar
const mutatedRejectBridge = bridgeCode
  .replace(
    'window._adoptExplicitlyRejected = true;',
    '/* mutacao: omitir rejeicao explicita */'
  )
  .replace(
    'window._adoptConsentOverride = { marketing: false, statistics: false };',
    '/* mutacao: omitir override de categorias revogadas */'
  );
if (mutatedRejectBridge === bridgeCode) {
  console.error('FAIL: Falha ao aplicar mutacao de adopt-reject-all na ponte AdOpt');
  process.exit(1);
}

const envMutReject = createBridgeEnvironment(
  { adoptConsentMode: JSON.stringify({ marketing: true, statistics: true }) }
);
// Criamos um localStorage onde setItem('adoptConsentMode') é ignorado durante a mutação
// para simular a CMP que ainda não atualizou o storage
const rawStorage = { adoptConsentMode: JSON.stringify({ marketing: true, statistics: true }) };
const fakeStorage = {
  getItem: (k) => rawStorage[k] || null,
  setItem: () => {},
  removeItem: () => {}
};
envMutReject.win.localStorage = fakeStorage;
envMutReject.ctx.localStorage = fakeStorage;

vm.runInContext(mutatedRejectBridge, envMutReject.ctx);
envMutReject.win.dataLayer.push('adopt-reject-all');
// Na mutação, como a rejeição explícita foi removida e o storage simulava atraso,
// o array continuaria contendo marketing!
const mutationRejectDetected = envMutReject.win.acceptedTags.includes('marketing');
assert(
  mutationRejectDetected,
  'Teste de mutacao LGPD: omitir rejeicao explicita em adopt-reject-all deixa marketing ativo com storage residual (detectado com sucesso)'
);

// 4.11 Reaceite individual deve prevalecer sobre storage residual sem ressuscitar outra categoria
const envStaleConsent = createBridgeEnvironment(
  { adoptConsentMode: JSON.stringify({ marketing: true, statistics: true }) },
  ['ExClwcP566'],
  { ignoreWrites: true }
);
vm.runInContext(bridgeCode, envStaleConsent.ctx);
envStaleConsent.win.dataLayer.push('adopt-reject-all');
assert(
  !envStaleConsent.win.acceptedTags.includes('marketing') &&
  !envStaleConsent.win.acceptedTags.includes('statistics'),
  'Ponte AdOpt: rejeicao explicita prevalece quando o storage residual nao aceita escrita'
);
envStaleConsent.win.dataLayer.push('adopt-accept-statistics');
assert(
  envStaleConsent.win.acceptedTags.includes('statistics'),
  'Ponte AdOpt: reaceite de statistics funciona mesmo com storage residual'
);
assert(
  !envStaleConsent.win.acceptedTags.includes('marketing'),
  'Ponte AdOpt: reaceite de statistics nao ressuscita marketing residual'
);
assert(
  envStaleConsent.win.acceptedTags.includes('ExClwcP566'),
  'Ponte AdOpt: reaceite com storage residual preserva tags de terceiros'
);

if (failures > 0) {
  console.error(`\nTotal de falhas: ${failures}`);
  process.exit(1);
}

console.log('\nTodos os testes de conversao, ponte AdOpt LGPD e resistencia a mutacao passaram com sucesso!');
