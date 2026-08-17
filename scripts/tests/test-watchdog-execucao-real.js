#!/usr/bin/env node
/**
 * O watchdog é limpo nos DOIS ramos? Provado por EXECUÇÃO, não por leitura de texto.
 *
 * POR QUE ESTE TESTE SUBSTITUI A ABORDAGEM ANTERIOR
 *
 * A asserção equivalente em test-turnstile-consistencia.js foi furada TRÊS vezes por
 * revisores independentes:
 *
 *   1ª tentativa — exigia linha de código ativo (não comentada).
 *      Burlada com:  if (false) { clearTimeout(tsTimer); }
 *
 *   2ª tentativa — media INDENTAÇÃO (chamada no nível do ramo).
 *      Burlada escrevendo o corpo do if SEM indentar, na mesma coluna.
 *
 *   3ª tentativa — contava CHAVES (profundidade zero).
 *      Burlada com:  if (algo) return;  na linha anterior — não abre chave nenhuma.
 *      E produziu 3 FALSOS POSITIVOS: comentário de bloco multi-linha, regex com chave
 *      desbalanceada e template literal multi-linha.
 *
 * O padrão não é azar. "Esta linha sempre executa?" é uma pergunta de ANÁLISE DE FLUXO — uma
 * propriedade do grafo de execução, não do texto. Regex não decide isso. Cada correção
 * fechava um caso sintático e abria outro.
 *
 * A SAÍDA: em vez de perguntar "o texto parece certo?", EXECUTAR o ramo com um espião em
 * clearTimeout e perguntar "o timer foi limpo?". Isso é imune a como o código está escrito:
 * `if (false)`, `return` antes, indentação, comentário — nada disso engana um spy.
 *
 * COMO
 *   1. extrai o <script> do arquivo PHP
 *   2. isola o corpo de cada ramo (.then e .catch) do fetch
 *   3. executa cada corpo em `vm` com stubs de DOM e um espião em clearTimeout
 *   4. afirma que o espião foi chamado com o timer certo
 *
 * A extração ainda é por regex — mas agora ela só DELIMITA o trecho. Quem decide se a
 * limpeza acontece é a execução. E há guarda para o caso da extração falhar (teste vazio).
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const RAIZ = path.resolve(__dirname, '..', '..');

const FORMULARIOS = [
  'mu-plugins/uonix-forms/29-form-captura-lead.php',
  'mu-plugins/uonix-forms/32-form-newsletter.php',
  'mu-plugins/uonix-forms/33-form-trabalhe-conosco.php',
];

let asserts = 0;
const falhas = [];

function assert(cond, msg) {
  asserts++;
  if (!cond) falhas.push(msg);
}

/**
 * Executa o corpo de um ramo num sandbox, com clearTimeout espionado.
 *
 * Devolve { limpou, erro }. Qualquer exceção do corpo é capturada: o objetivo não é validar
 * o resto da lógica, só saber se a limpeza aconteceu antes de qualquer falha.
 */
function executaRamo(corpo, nomeRamo) {
  const limpezas = [];

  // stub permissivo: qualquer propriedade lida devolve outro stub chamável, para que o
  // corpo do ramo rode sem depender do DOM real
  const permissivo = () => new Proxy(function () {}, {
    get: (alvo, prop) => {
      if (prop === Symbol.toPrimitive || prop === 'toString') return () => '';
      if (prop === 'then' || prop === 'catch') return undefined;
      return permissivo();
    },
    apply: () => permissivo(),
    set: () => true,
  });

  const sandbox = {
    tsTimer: 'TIMER_SENTINELA',
    clearTimeout: (id) => limpezas.push(id),
    setTimeout: () => 'OUTRO_TIMER',
    console: { log() {}, warn() {}, error() {} },
    JSON,
    Object,
    Array,
    String,
    Number,
    Boolean,
    Math,
    Date,
    Error,
    RegExp,
    encodeURIComponent,
    decodeURIComponent,
  };

  // identificadores que o corpo do ramo costuma tocar
  for (const nome of [
    'document', 'window', 'form', 'btn', 'data', 'error', 'response', 'feedback',
    'feedbackError', 'feedbackOk', 'resetUonixTurnstile', 'uonixTurnstile', 'grecaptcha',
    'jQuery', '$', 'fetch', 'FormData', 'alert', 'location', 'navigator', 'tsAbort',
  ]) {
    if (!(nome in sandbox)) sandbox[nome] = permissivo();
  }

  const ctx = vm.createContext(sandbox);

  let erro = null;
  try {
    // `with` não é permitido em strict mode, e o corpo pode declarar var — envolvo em função
    vm.runInContext(`(function(){\n${corpo}\n})();`, ctx, { timeout: 2000 });
  } catch (e) {
    erro = e;
  }

  return { limpou: limpezas.includes('TIMER_SENTINELA'), erro, limpezas };
}

for (const rel of FORMULARIOS) {
  const arquivo = path.join(RAIZ, rel);
  const nome = path.basename(rel);

  assert(fs.existsSync(arquivo), `${nome}: arquivo não encontrado em ${rel}`);
  if (!fs.existsSync(arquivo)) continue;

  const php = fs.readFileSync(arquivo, 'utf8');
  const js = (php.match(/<script[^>]*>([\s\S]*?)<\/script>/g) || []).join('\n');

  // GUARDA ANTI-TESTE-VAZIO: se a extração do <script> falhar, o laço abaixo não roda e o
  // teste passaria sem verificar nada.
  assert(
    js.length > 500,
    `${nome}: extraí apenas ${js.length} caracteres de <script>. A extração falhou e as ` +
      `asserções seguintes não exercitariam nada — falso verde.`
  );
  if (js.length <= 500) continue;

  // nome do parâmetro é livre: não é contrato (lição do PR #115)
  const ramos = {
    then: js.match(/\.then\(\s*\w+\s*=>\s*\{([\s\S]*?)\n\s*\}\)/),
    catch: js.match(/\.catch\(\s*\w+\s*=>\s*\{([\s\S]*?)\n\s*\}\)/),
  };

  for (const [qual, m] of Object.entries(ramos)) {
    assert(
      m !== null,
      `${nome}: não encontrei o ramo .${qual}(param => { ... }) do fetch. Sem ele não há o ` +
        `que executar, e a limpeza do watchdog fica sem verificação.`
    );
    if (!m) continue;

    const corpo = m[1];

    // o corpo tem de conter código de verdade, não só espaço
    assert(
      corpo.trim().length > 20,
      `${nome}: o ramo .${qual} capturado tem ${corpo.trim().length} caracteres úteis. ` +
        `A regex pegou trecho vazio ou errado.`
    );

    const { limpou, erro, limpezas } = executaRamo(corpo, qual);

    assert(
      limpou,
      `${nome}: EXECUTEI o corpo do ramo .${qual} e clearTimeout(tsTimer) NÃO foi chamado. ` +
        `O watchdog sobrevive ao ramo e vai abortar uma requisição posterior do usuário. ` +
        `clearTimeout recebeu: ${JSON.stringify(limpezas)}. ` +
        (erro ? `O corpo lançou ${erro.name}: ${String(erro.message).slice(0, 90)} — se a ` +
          `exceção vem ANTES da limpeza, o timer fica pendurado de verdade.` : '')
    );
  }
}

// ---------------------------------------------------------------------------
// META-ASSERÇÃO: o próprio espião funciona?
//
// Se o mecanismo de execução estivesse quebrado (sandbox mal montado, spy não registrando),
// TODAS as asserções acima falhariam junto — mas o inverso também vale: um spy que registra
// qualquer coisa daria falso verde. Verifico as duas pontas com corpos sintéticos.
{
  const positivo = executaRamo('clearTimeout(tsTimer);', 'sintetico');
  assert(
    positivo.limpou,
    'META: o corpo sintético `clearTimeout(tsTimer);` não foi detectado. O sandbox ou o ' +
      'espião está quebrado, e todas as asserções acima são inválidas.'
  );

  const negativo = executaRamo('var x = 1;', 'sintetico');
  assert(
    !negativo.limpou,
    'META: o corpo sintético SEM clearTimeout foi contado como limpeza. O espião registra ' +
      'qualquer coisa e o teste daria falso verde sempre.'
  );

  // as três burlas que furaram as tentativas anteriores devem ser pegas
  const burlas = [
    ['if (false) { clearTimeout(tsTimer); }', 'if de condição falsa'],
    ['if (true) return;\nclearTimeout(tsTimer);', 'return antes'],
    ['while (false) { clearTimeout(tsTimer); }', 'while de condição falsa'],
  ];

  for (const [corpo, desc] of burlas) {
    const r = executaRamo(corpo, 'sintetico');
    assert(
      !r.limpou,
      `META: a burla "${desc}" passou pelo espião. Esta é exatamente a classe de defeito ` +
        `que furou as três tentativas anteriores por leitura estática.`
    );
  }
}

if (falhas.length) {
  falhas.forEach((f) => console.error(`FAIL: ${f}`));
  console.error(`\nFALHOU: ${asserts} asserções, ${falhas.length} falha(s)`);
  process.exit(1);
}

console.log(`PASS: watchdog limpo nos dois ramos, provado por EXECUÇÃO (${asserts} asserções)`);
