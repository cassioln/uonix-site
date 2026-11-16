import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');
const scriptPath = path.join(repositoryRoot, 'mu-plugins', 'uonix-admin', 'assets', 'js', 'admin-widgets-lote.js');
const source = await readFile(scriptPath, 'utf8');

// #365: o api-fetch.js REAL do núcleo (cópia em fixtures/wp-core, ver o LEIA-ME de lá), não um stub.
const nucleoDir = path.join(repositoryRoot, 'scripts', 'tests', 'fixtures', 'wp-core');
const pacotesNucleo = {};
for (const pacote of ['hooks', 'private-apis', 'url', 'i18n', 'api-fetch']) {
  pacotesNucleo[pacote] = await readFile(path.join(nucleoDir, `${pacote}.js`), 'utf8');
}

function carregar(wp) {
  const middlewares = [];
  const janela = { wp };
  if (wp && wp.apiFetch) {
    wp.apiFetch.use = (middleware) => middlewares.push(middleware);
  }
  vm.runInNewContext(source, { window: janela, Object, String, RegExp });
  return middlewares;
}

function passar(middleware, options) {
  const recebidos = [];
  const retorno = middleware(options, (opcoes) => {
    recebidos.push(opcoes);
    return 'resultado-do-next';
  });
  return { retorno, recebido: recebidos[0], chamadas: recebidos.length };
}

test('registra exatamente um middleware no apiFetch', () => {
  assert.equal(carregar({ apiFetch: {} }).length, 1);
});

test('POST /batch/v1 vai para /uonix/v1/lote, preservando o resto da requisição', () => {
  const [mw] = carregar({ apiFetch: {} });
  const original = { path: '/batch/v1?_locale=user', method: 'POST', data: { requests: [{ path: '/wp/v2/widgets/block-1' }] } };
  const copia = structuredClone(original);
  const { retorno, recebido, chamadas } = passar(mw, original);
  assert.equal(chamadas, 1);
  assert.equal(retorno, 'resultado-do-next');
  assert.equal(recebido.path, '/uonix/v1/lote?_locale=user');
  assert.equal(recebido.method, 'POST');
  assert.deepEqual(recebido.data, copia.data);
  assert.deepEqual(original, copia, 'não deve alterar o objeto original');
});

test('aceita método em minúsculas e caminho com barra final', () => {
  const [mw] = carregar({ apiFetch: {} });
  assert.equal(passar(mw, { path: '/batch/v1', method: 'post' }).recebido.path, '/uonix/v1/lote');
  assert.equal(passar(mw, { path: '/batch/v1/', method: 'POST' }).recebido.path, '/uonix/v1/lote/');
});

test('não mexe no que não é POST para /batch/v1', () => {
  const [mw] = carregar({ apiFetch: {} });
  const intactos = [
    { path: '/batch/v1', method: 'OPTIONS' },
    { path: '/batch/v1' },
    { path: '/batch/v10', method: 'POST' },
    { path: '/batch/v1x', method: 'POST' },
    { path: '/wp/v2/widgets', method: 'POST' },
    { path: '/wp/v2/batch/v1', method: 'POST' },
    { url: 'https://exemplo.test/wp-json/batch/v1', method: 'POST' },
  ];
  for (const options of intactos) {
    const { recebido } = passar(mw, options);
    assert.equal(recebido, options, `deveria passar intacto: ${JSON.stringify(options)}`);
  }
});

test('sem wp.apiFetch, não quebra nem registra nada', () => {
  assert.doesNotThrow(() => carregar(undefined));
  assert.doesNotThrow(() => carregar({}));
});


// ---------------------------------------------------------------------------------------
// #365: ordem do middleware contra o api-fetch.js real do WordPress.
//
// O desvio recebe `path` (relativo) e não `url`, e por isso tem de rodar ANTES do middleware
// de rootURL, que transforma `path` em `url`. O núcleo registra middlewares com `unshift` e
// executa com `reduceRight`, então o último registrado roda primeiro. Aqui o api-fetch real é
// carregado num vm, com o rootURL e o nonce registrados como o WordPress registra
// (wp_add_inline_script do wp-api-fetch), e o desvio é registrado DEPOIS, como o
// admin_enqueue_scripts faz. A URL que chega ao fetch é a final.
// ---------------------------------------------------------------------------------------
const ROOT = 'https://exemplo.test/wp-json/';

function carregarComNucleo({ registrarDesvio = true, desvioAntesDoRoot = false } = {}) {
  const chamadas = [];
  const fetchFalso = async (url, init) => {
    chamadas.push({ url: String(url), metodo: init && init.method, corpo: init && init.body });
    return new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  const janela = {};
  const contexto = vm.createContext({
    window: janela, globalThis: undefined, Object, String, RegExp, JSON, Promise, Array, Error, Symbol, Map, Set, WeakMap,
    Response, URL, URLSearchParams, setTimeout, clearTimeout, console, encodeURIComponent, decodeURIComponent, Math, Number,
    Boolean, parseInt, parseFloat, isNaN, Date, TypeError, RangeError, SyntaxError, structuredClone,
  });
  contexto.globalThis = contexto;
  contexto.fetch = fetchFalso;
  contexto.navigator = { onLine: true };
  contexto.wp = undefined;
  contexto.window = contexto;
  for (const pacote of ['hooks', 'private-apis', 'url', 'i18n', 'api-fetch']) {
    vm.runInContext(pacotesNucleo[pacote], contexto, { filename: `wp-core/${pacote}.js` });
  }
  const apiFetch = contexto.wp.apiFetch;
  const registrar = () => vm.runInContext(source, contexto, { filename: 'admin-widgets-lote.js' });
  if (desvioAntesDoRoot) {
    registrar();
  }
  // Como o núcleo, na ordem do script-loader: rootURL, depois nonce.
  apiFetch.use(apiFetch.createRootURLMiddleware(ROOT));
  apiFetch.use(apiFetch.createNonceMiddleware('nonce-teste'));
  if (registrarDesvio && !desvioAntesDoRoot) {
    registrar();
  }
  return { apiFetch, chamadas };
}

test('com o api-fetch real, POST /batch/v1 sai para /uonix/v1/lote com a URL final', async () => {
  const { apiFetch, chamadas } = carregarComNucleo();
  await apiFetch({ path: '/batch/v1?_locale=user', method: 'POST', data: { requests: [{ path: '/wp/v2/widgets/block-1' }] } });
  assert.equal(chamadas.length, 1);
  assert.equal(chamadas[0].url, `${ROOT}uonix/v1/lote?_locale=user`);
  assert.equal(chamadas[0].metodo, 'POST');
  assert.ok(!chamadas[0].url.includes('batch/v1'), 'nenhuma requisição pode conter batch/v1: o nginx da Locaweb devolve 403');
});

test('com o api-fetch real, o que não é POST /batch/v1 segue para o próprio caminho', async () => {
  const { apiFetch, chamadas } = carregarComNucleo();
  await apiFetch({ path: '/wp/v2/widgets', method: 'POST', data: {} });
  await apiFetch({ path: '/wp/v2/widgets' });
  // O núcleo acrescenta ?_locale=user a toda requisição (middleware de locale): é a URL real.
  assert.deepEqual(chamadas.map((c) => c.url), [`${ROOT}wp/v2/widgets?_locale=user`, `${ROOT}wp/v2/widgets?_locale=user`]);
});

test('sem o desvio, o api-fetch real manda o POST para batch/v1 (controle: o desvio é quem muda a URL)', async () => {
  const { apiFetch, chamadas } = carregarComNucleo({ registrarDesvio: false });
  await apiFetch({ path: '/batch/v1', method: 'POST', data: {} });
  assert.equal(chamadas[0].url, `${ROOT}batch/v1?_locale=user`);
});

test('registrado ANTES do rootURL, o desvio não age: recebe url e não path (a ordem importa)', async () => {
  const { apiFetch, chamadas } = carregarComNucleo({ desvioAntesDoRoot: true });
  await apiFetch({ path: '/batch/v1', method: 'POST', data: {} });
  assert.equal(chamadas[0].url, `${ROOT}batch/v1?_locale=user`, 'registrado antes do rootURL, ele roda depois e perde o `path`: a ordem de registro é o que faz o desvio funcionar');
});
