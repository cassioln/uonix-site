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

if (failures > 0) {
  console.error(`\nTotal de falhas: ${failures}`);
  process.exit(1);
}

console.log('\nTodos os testes de conversao e resistencia a mutacao passaram com sucesso!');
