/**
 * Prova que a lógica de bloqueio do Turnstile no checkout decide certo.
 *
 * Extrai as funções reais do mu-plugin (não reimplementa) e roda contra cenários
 * com um jQuery mínimo simulado. Se este teste passar mas o comportamento real
 * divergir, é porque o stub não é fiel — os stubs cobrem apenas val(), length e each().
 */

const fs = require('fs');
const path = require('path');

const arquivo = path.join(__dirname, '../../mu-plugins/uonix-woocommerce/17-woocommerce-checkout-design.php');
const src = fs.readFileSync(arquivo, 'utf8');

const blocos = [...src.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
if (!blocos.length) {
  console.error('FAIL: nenhum bloco <script> encontrado em ' + arquivo);
  process.exit(1);
}
const js = blocos.reduce((a, b) => (b.length > a.length ? b : a), '');

/* extrai só a função sob teste, para não arrastar o resto do arquivo */
function extrairFuncao(nome) {
  const inicio = js.indexOf('function ' + nome);
  if (inicio === -1) {
    console.error(`FAIL: função ${nome} não encontrada no mu-plugin`);
    process.exit(1);
  }
  let i = js.indexOf('{', inicio);
  let nivel = 0;
  for (let k = i; k < js.length; k++) {
    if (js[k] === '{') nivel++;
    else if (js[k] === '}') {
      nivel--;
      if (nivel === 0) return js.slice(inicio, k + 1);
    }
  }
  console.error(`FAIL: não consegui delimitar a função ${nome}`);
  process.exit(1);
}

const fonteTurnstilePendente = extrairFuncao('turnstilePendente');

let assercoes = 0;
function assert(cond, msg) {
  assercoes++;
  if (!cond) {
    console.error('FAIL: ' + msg);
    process.exit(1);
  }
}

/**
 * jQuery mínimo: só o que turnstilePendente() usa.
 * @param {Array<string|null>} valores valores dos campos cf-turnstile-response
 */
function montarJQuery(valores) {
  const colecao = {
    length: valores.length,
    each(cb) {
      valores.forEach((v, i) => cb.call({ __v: v }, i, { __v: v }));
      return this;
    },
  };
  const $ = function (sel) {
    if (typeof sel === 'object' && sel !== null && '__v' in sel) {
      return { val: () => sel.__v };
    }
    return colecao;
  };
  $.trim = (s) => String(s == null ? '' : s).trim();
  return $;
}

function turnstilePendenteCom(valores) {
  const $ = montarJQuery(valores);
  // eslint-disable-next-line no-new-func
  const fn = new Function('$', fonteTurnstilePendente + '; return turnstilePendente();');
  return fn($);
}

/* ---------------- casos ---------------- */

// 1. campo vazio -> pendente (bloqueia o envio)
assert(turnstilePendenteCom(['']) === true, 'campo vazio deveria ser pendente');

// 2. campo preenchido -> não pendente (deixa enviar)
assert(turnstilePendenteCom(['0.abc-token-valido']) === false, 'token preenchido não deveria bloquear');

// 3. só espaços -> pendente (o Turnstile nunca gera token em branco)
assert(turnstilePendenteCom(['   ']) === true, 'valor só com espaços deveria ser pendente');

// 4. null -> pendente
assert(turnstilePendenteCom([null]) === true, 'valor null deveria ser pendente');

// 5. SEM o campo no DOM -> NÃO bloqueia.
//    Se o widget não carregar (Turnstile fora do ar, sitekey errada, bloqueio de rede),
//    travar o checkout no cliente deixaria o site sem receber orçamento nenhum.
//    A validação do servidor continua sendo a barreira de segurança.
assert(turnstilePendenteCom([]) === false, 'sem campo no DOM não deveria bloquear o checkout');

// 6. múltiplos campos, um preenchido -> não pendente
assert(turnstilePendenteCom(['', '0.token']) === false, 'um campo preenchido basta');

// 7. múltiplos campos, todos vazios -> pendente
assert(turnstilePendenteCom(['', '']) === true, 'todos vazios deveria ser pendente');

/* ---------------- contratos no código ---------------- */

// o veto do WooCommerce depende de `return false` dentro do checkout_place_order
assert(
  /checkout_place_order[\s\S]{0,400}turnstilePendente\(\)[\s\S]{0,120}return false/.test(js),
  'checkout_place_order precisa retornar false quando o Turnstile está pendente'
);

// a rede de segurança do loading precisa existir e limpar a classe
assert(/loadingWatchdog\s*=\s*setTimeout/.test(js), 'watchdog do loading ausente');
assert(
  /loadingWatchdog[\s\S]{0,300}removeClass\('uonix-loading'\)/.test(js),
  'watchdog precisa remover a classe uonix-loading'
);

// checkout_error precisa cancelar o watchdog para não competir com o fluxo normal
assert(
  /checkout_error[\s\S]{0,300}clearTimeout\(loadingWatchdog\)/.test(js),
  'checkout_error precisa cancelar o watchdog'
);

// o destaque precisa rolar até o widget. Atenção: `scrollIntoView` aparece 2x dentro de
// destacarTurnstile (a checagem `typeof` e a chamada), então asserir só o nome deixaria
// passar a remoção da chamada — foi o que uma mutação provou. Aqui exigimos a CHAMADA
// com os parâmetros de rolagem suave.
assert(
  /function destacarTurnstile\(\)[\s\S]*?alvo\.scrollIntoView\(\s*\{[^}]*behavior/.test(js),
  'destacarTurnstile precisa CHAMAR alvo.scrollIntoView({ behavior: ... })'
);

// a mensagem antiga não pode voltar
assert(
  !/Recarregue a página e tente novamente/.test(src),
  'mensagem "Recarregue a página" não deveria existir mais neste arquivo'
);

console.log(`PASS: bloqueio do Turnstile no checkout (${assercoes} asserções)`);
