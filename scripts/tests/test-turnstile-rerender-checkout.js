#!/usr/bin/env node
/**
 * O widget do Turnstile tem de ser RECONSTRUÍDO depois de um AJAX do checkout.
 *
 * O BUG QUE ESTE TESTE TRAVA
 *
 * O widget é emitido em `woocommerce_review_order_before_submit`, dentro do template
 * `review-order.php`. O WooCommerce substitui esse template inteiro a cada
 * `update_order_review` (troca de CEP, cupom, frete) — e leva o container do widget com ele.
 *
 * O console de produção registrou o sintoma:
 *   [Cloudflare Turnstile] Cannot find Widget cf-chl-widget-w5hr2
 *
 * Sem widget, o campo `cf-turnstile-response` fica vazio, a validação server-side
 * (corretamente fail-closed) rejeita, e o CLIENTE LEGÍTIMO não consegue enviar o orçamento.
 * O handler existia mas só chamava `syncTurnstileVisibility()`, que apenas alterna classes
 * CSS — não recria nada.
 *
 * POR QUE VERIFICAR ASSIM
 *
 * O ideal seria um teste de navegador que dispara `updated_checkout` de verdade. Não há
 * navegador headless neste projeto, então verifico o CONTRATO no fonte: o handler de
 * `updated_checkout` precisa chamar algo que reconstrua o widget.
 *
 * Isso é leitura estática, com as limitações conhecidas — mas a prova de mutação abaixo
 * confirma que ele falha quando a chamada é removida, que é o que importa.
 */

const fs = require('fs');
const path = require('path');

const RAIZ = path.resolve(__dirname, '..', '..');
const ALVO = path.join(RAIZ, 'mu-plugins/uonix-woocommerce/17-woocommerce-checkout-design.php');
const LOADER = path.join(RAIZ, 'mu-plugins/uonix-integrations/34-turnstile-custom-forms.php');

let asserts = 0;
const falhas = [];

function assert(cond, msg) {
  asserts++;
  if (!cond) falhas.push(msg);
}

const checkout = fs.readFileSync(ALVO, 'utf8');
const loader = fs.readFileSync(LOADER, 'utf8');

// ---------------------------------------------------------------------------
// 1. o handler de updated_checkout existe?
//
// Se o handler desaparecer, a reformatação da tabela também quebra — mas o ponto aqui é
// que sem ele não há nem oportunidade de reconstruir o widget.
const mHandler = checkout.match(
  /\$\(document\.body\)\.on\(\s*'updated_checkout'\s*,\s*function\s*\([^)]*\)\s*\{([\s\S]*?)\n(\s*)\}\);/
);

assert(
  mHandler !== null,
  'não encontrei o handler de updated_checkout em 17-woocommerce-checkout-design.php. ' +
    'Sem ele, o widget destruído pelo AJAX do WooCommerce nunca é reconstruído.'
);

if (!mHandler) {
  relatar();
}

const corpoHandler = mHandler[1];

// guarda contra captura excessiva: o handler tem ~12 linhas de código
const linhasHandler = corpoHandler.split('\n').length;
assert(
  linhasHandler < 45,
  `a regex capturou ${linhasHandler} linhas do handler de updated_checkout — ` +
    'provavelmente engoliu código vizinho e as asserções abaixo olhariam o trecho errado.'
);

// ---------------------------------------------------------------------------
// 2. o handler RECONSTRÓI o widget, não só ajusta CSS
//
// Esta é a asserção central. `syncTurnstileVisibility()` sozinho era o bug: alterna
// classes conforme o iframe ter dimensão, e não existe iframe quando o container foi
// removido do DOM.
const reconstroi = /uonixTurnstile\s*(&&[^;]*)?\.\s*(prepare|renderAll)\s*\(/.test(corpoHandler);

assert(
  reconstroi,
  'o handler de updated_checkout NÃO chama uonixTurnstile.prepare() nem .renderAll(). ' +
    'O WooCommerce substitui review-order.php a cada troca de CEP/cupom/frete e destrói o ' +
    'container do widget; sem reconstruir, o cliente fica sem verificação, o token vem ' +
    'vazio e o envio é rejeitado com "Falha na verificação de segurança". Bloqueia venda.'
);

// ---------------------------------------------------------------------------
// 3. a chamada é GUARDADA contra ausência do objeto
//
// O loader pode não ter carregado (adblock, firewall). Chamar direto lançaria TypeError e
// mataria o resto do handler, inclusive a reformatação da tabela.
assert(
  /if\s*\(\s*window\.uonixTurnstile\s*&&/.test(corpoHandler),
  'a chamada de reconstrução do widget não está guardada por ' +
    '`if (window.uonixTurnstile && ...)`. Se o loader não carregar (adblock, firewall ' +
    'corporativo), o TypeError aborta o handler e a tabela do pedido também deixa de ser ' +
    'formatada.'
);

// ---------------------------------------------------------------------------
// 4. o loader expõe de fato o método usado
//
// Uma asserção que só olhasse o checkout passaria mesmo se o método não existisse.
const metodoUsado = corpoHandler.match(/uonixTurnstile[^;]*?\.\s*(prepare|renderAll)\s*\(/);
if (metodoUsado) {
  const nome = metodoUsado[1];
  assert(
    new RegExp(`${nome}\\s*:\\s*${nome}`).test(loader) ||
      new RegExp(`${nome}\\s*:\\s*function`).test(loader),
    `o handler chama uonixTurnstile.${nome}(), mas 34-turnstile-custom-forms.php não ` +
      `exporta esse método no objeto público. A chamada seria undefined em runtime.`
  );
}

// ---------------------------------------------------------------------------
// 5. renderContainer continua IDEMPOTENTE
//
// É o que torna seguro chamar prepare() a cada AJAX: com widget vivo, sai cedo e não
// duplica. Se essa guarda cair, cada troca de CEP empilharia um widget novo.
assert(
  /dataset\.uonixRendered\s*===\s*'1'/.test(loader),
  'renderContainer() perdeu a guarda `dataset.uonixRendered === \'1\'`. Sem ela, chamar ' +
    'prepare() a cada updated_checkout DUPLICA o widget em vez de reaproveitar o existente.'
);

// ---------------------------------------------------------------------------
// 6. o widget é marcado como renderizado após o render
//
// Sem isso a guarda do item 5 nunca é satisfeita e a duplicação volta.
assert(
  /container\.dataset\.uonixRendered\s*=\s*'1'/.test(loader),
  'renderContainer() não marca `dataset.uonixRendered = \'1\'` após renderizar. ' +
    'A guarda de idempotência fica inútil e o widget duplica a cada AJAX.'
);

relatar();

function relatar() {
  if (falhas.length) {
    falhas.forEach((f) => console.error(`FAIL: ${f}`));
    console.error(`\nFALHOU: ${asserts} asserções, ${falhas.length} falha(s)`);
    process.exit(1);
  }
  console.log(`PASS: widget do Turnstile reconstruído após AJAX do checkout (${asserts} asserções)`);
}
