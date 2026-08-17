/**
 * Prova as correções de consistência do Turnstile nas 6 superfícies.
 *
 * Lê os arquivos reais (não reimplementa) e verifica contratos que devem valer em
 * TODAS as superfícies. A ideia é impedir regressão silenciosa: se alguém reintroduzir
 * "Recarregue a página" no fluxo de Turnstile, ou remover um watchdog, o CI falha.
 *
 * Não substitui teste de navegador — verifica contratos no código-fonte.
 */

const fs = require('fs');
const path = require('path');

const raiz = path.join(__dirname, '../..');
const ler = (p) => fs.readFileSync(path.join(raiz, p), 'utf8');

const FORMS = [
  'mu-plugins/uonix-forms/29-form-captura-lead.php',
  'mu-plugins/uonix-forms/32-form-newsletter.php',
  'mu-plugins/uonix-forms/33-form-trabalhe-conosco.php',
];
const LOGIN = 'mu-plugins/uonix-admin/51-login-turnstile.php';
const COMENTARIOS = 'mu-plugins/uonix-content/10-comentarios-master.php';
const VALIDADOR = 'mu-plugins/uonix-integrations/34-turnstile-custom-forms.php';
const CHECKOUT = 'mu-plugins/uonix-woocommerce/17-woocommerce-checkout-design.php';

let n = 0;
function assert(cond, msg) {
  n++;
  if (!cond) {
    console.error('FAIL: ' + msg);
    process.exit(1);
  }
}

/* extrai o maior bloco <script>, trocando PHP embutido por literal JS válido */
function jsDe(arquivo) {
  const src = ler(arquivo);
  const blocos = [...src.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
  if (!blocos.length) return '';
  const maior = blocos.reduce((a, b) => (b.length > a.length ? b : a), '');
  return maior.replace(/<\?php[\s\S]*?\?>/g, '"__PHP__"');
}

/* ---------- 1. nenhuma mensagem de TURNSTILE pede recarregar ---------- */

// O validador compartilhado e o login precisam falar a mesma língua. Antes, o login
// tinha mensagem própria pedindo reload — inconsistência entre superfícies do mesmo site.
for (const arq of [VALIDADOR, LOGIN, CHECKOUT, ...FORMS]) {
  const src = ler(arq);
  const linhas = src.split('\n');

  linhas.forEach((linha, i) => {
    // ignora comentários (documentam o histórico da correção de propósito)
    const limpa = linha.replace(/^\s*(\/\/|\*|#|\/\*).*/, '');
    if (!/Recarregue a p[áa]gina/i.test(limpa)) return;

    // exceção legítima: nonce expirado REALMENTE exige nova página
    const contexto = linhas.slice(Math.max(0, i - 6), i + 1).join('\n');
    const eNonce = /nonce|sess[ãa]o expir/i.test(contexto);

    assert(
      eNonce,
      `${arq}:${i + 1} pede "Recarregue a página" fora do caso de nonce — o widget do ` +
        `Turnstile é resetado por JS, então reload é burocracia desnecessária`
    );
  });
}

/* ---------- 2. o login usa a mensagem alinhada ---------- */

const login = ler(LOGIN);
assert(
  /confirme a verifica[çc][ãa]o de seguran[çc]a para continuar/i.test(login),
  'o login precisa usar a mensagem alinhada com o validador compartilhado'
);

/* ---------- 3. o login tem escape hatch contra lockout ---------- */

// Cenário não coberto pelos fail-opens: secret PRESENTE mas ERRADO. A Cloudflare
// responde success=false e o login bloqueia para todos, sem via de recuperação pelo
// navegador. A constante permite destravar pelo wp-config.php.
assert(
  /UONIX_LOGIN_TURNSTILE_DISABLE/.test(login),
  'o login precisa de escape hatch (UONIX_LOGIN_TURNSTILE_DISABLE) contra lockout'
);
assert(
  /defined\(\s*'UONIX_LOGIN_TURNSTILE_DISABLE'\s*\)[\s\S]{0,120}return false/.test(login),
  'o escape hatch precisa efetivamente desativar (return false), não só existir'
);

/* ---------- 4. os fail-opens existentes não foram removidos ---------- */

assert(
  /uonix_turnstile_request_failed[\s\S]{0,200}return \$user/.test(login),
  'fail-open para falha de TRANSPORTE não pode ser removido: Cloudflare fora do ar ' +
    'trancaria todos os administradores fora do wp-admin'
);

/* ---------- 5. o escopo do login segue restrito ao formulário nativo ---------- */

assert(
  /'wp-login\.php' !== \$pagenow \|\| 'POST' !== \$request_method[\s\S]{0,80}return \$user/.test(login),
  'a validação do login deve rodar só no formulário nativo em POST — validar em ' +
    'REST/XML-RPC/WP-CLI ou em fluxos sem widget causa lockout silencioso'
);

/* ---------- 6. o erro AJAX devolve o código, não só a mensagem ---------- */

const validador = ler(VALIDADOR);
assert(
  /wp_send_json_error\([\s\S]{0,200}'code'\s*=>\s*\$validation->get_error_code\(\)/.test(validador),
  'o erro AJAX precisa devolver o code para o JS distinguir "faltou o captcha" de ' +
    '"captcha inválido"'
);

/* ---------- 7. timeout de verificação é ajustável e não excessivo ---------- */

const mTimeout = validador.match(
  /'timeout'\s*=>\s*\(int\) apply_filters\(\s*'uonix_turnstile_verify_timeout',\s*(\d+)\s*\)/
);
assert(mTimeout, 'o timeout do siteverify precisa ser filtrável (uonix_turnstile_verify_timeout)');
assert(
  Number(mTimeout[1]) <= 5,
  `timeout padrão de ${mTimeout[1]}s é alto: o siteverify responde em <500ms e o ` +
    `usuário fica esperando antes de ver o erro`
);

/* ---------- 8. cada formulário tem watchdog E bloqueio preventivo ---------- */

for (const arq of FORMS) {
  const js = jsDe(arq);
  const nome = path.basename(arq);

  assert(js.length > 0, `${nome}: não encontrei bloco <script>`);

  // watchdog: fetch sem timeout pode pendurar o botão para sempre
  assert(
    /new AbortController\(\)/.test(js),
    `${nome}: precisa de AbortController — fetch que nunca responde não dispara .catch() ` +
      `e o botão fica desabilitado indefinidamente`
  );
  assert(/signal:\s*tsAbort\.signal/.test(js), `${nome}: o signal precisa ser passado ao fetch`);

  const mTimer = js.match(/setTimeout\(function \(\) \{ tsAbort\.abort\(\); \}, (\d+)\)/);
  assert(mTimer, `${nome}: o watchdog precisa abortar o fetch`);
  const ms = Number(mTimer[1]);
  assert(ms >= 5000 && ms <= 60000, `${nome}: watchdog de ${ms}ms fora da faixa razoável`);

  // o timer precisa ser limpo nos DOIS ramos, senão fica pendurado
  const limpezas = (js.match(/clearTimeout\(tsTimer\)/g) || []).length;
  assert(
    limpezas >= 2,
    `${nome}: clearTimeout(tsTimer) aparece ${limpezas}x — precisa estar no .then() E no ` +
      `.catch(), senão o timer sobrevive ao fluxo`
  );

  // bloqueio preventivo do Turnstile
  assert(
    /cf-turnstile-response/.test(js),
    `${nome}: precisa checar o campo cf-turnstile-response antes de enviar`
  );
  assert(
    /scrollIntoView/.test(js),
    `${nome}: precisa rolar até o widget quando o desafio está pendente`
  );

  // FAIL-OPEN no cliente: se o widget não carregou, NÃO bloquear.
  // Bloquear aqui deixaria o site sem captar nada quando a Cloudflare cai.
  assert(
    /tsCampo && !\(tsCampo\.value \|\| ''\)\.trim\(\)/.test(js),
    `${nome}: o bloqueio deve exigir que o campo EXISTA (tsCampo &&) — bloquear quando o ` +
      `widget não carregou impediria qualquer envio`
  );

  // mensagem de timeout distinta da de rede
  assert(/AbortError/.test(js), `${nome}: precisa distinguir timeout de falha de rede`);
}

/* ---------- 9. comentários preservam o texto digitado ---------- */

const comentarios = ler(COMENTARIOS);
// Atenção: buscar só o NOME da função é asserção fraca — ela continua DEFINIDA mesmo
// que a CHAMADA no fluxo de erro seja removida (uma mutação provou isso). Exigimos a
// chamada dentro do bloco que trata o erro de captcha.
assert(
  /\$is_captcha_error\)\s*\{[\s\S]{0,600}uonix_comment_guardar_rascunho\(\);/.test(comentarios),
  'o rascunho precisa ser GRAVADO dentro do bloco de erro de captcha, não apenas ter a ' +
    'função definida'
);
assert(
  /\$is_captcha_error\)\s*\{[\s\S]{0,900}add_query_arg\(\s*'comment_draft'/.test(comentarios),
  'a chave do rascunho precisa ir na URL do redirect, senão o texto não volta'
);
assert(
  /set_transient\(\s*'uonix_cmt_draft_'/.test(comentarios),
  'o rascunho deve ir para transient, não para a query string (vazaria em logs e Referer)'
);
// o conteúdo do comentário NUNCA deve viajar na URL
assert(
  !/add_query_arg\(\s*['"]comment['"]/.test(comentarios),
  'o texto do comentário não pode ir na query string'
);
assert(
  /comment_form_field_comment/.test(comentarios),
  'o texto preservado precisa voltar ao textarea via filtro oficial do WordPress'
);

console.log(`PASS: consistência do Turnstile nas 6 superfícies (${n} asserções)`);
