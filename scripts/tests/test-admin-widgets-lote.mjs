import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');
const scriptPath = path.join(repositoryRoot, 'mu-plugins', 'uonix-admin', 'assets', 'js', 'admin-widgets-lote.js');
const source = await readFile(scriptPath, 'utf8');

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
