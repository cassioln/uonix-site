#!/usr/bin/env node
// Contratos da janela SSH: nenhum acesso à Locaweb neste teste.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { tmpdir } from 'node:os';
import http from 'node:http';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';
import { prepararRuntime, abrirChromePipe } from '../plugins/ssh-painel-chrome.mjs';
import { analisarTela, aguardarTela, lerTela, URL_PAINEL } from '../plugins/ssh-painel.mjs';

const tela = status => ({ url: URL_PAINEL,
  texto: `Hospedagem\nuonix.com.br\nSSH\nStatus\n${status}\nDuração\n3 horas\nRenovação\nManual`,
  botoes: [status === 'Habilitado' ? 'Desabilitar' : 'Habilitar'], modal: null });
let leiturasIdentidade = 0;
const identidadeTardia = { avaliar: async expression => {
  if (expression === 'location.href') return URL_PAINEL;
  const atual = tela('Habilitado');
  if (leiturasIdentidade++ === 0) atual.texto = atual.texto.replace('uonix.com.br\n', '');
  return atual;
} };
assert.equal((await aguardarTela(identidadeTardia, 2000)).status, 'habilitado', 'status pode carregar antes da identidade; nunca aceitar sem ela');
assert.equal(leiturasIdentidade, 2);
await assert.rejects(aguardarTela({ avaliar: async expression => expression === 'location.href' ? URL_PAINEL
  : { ...tela('Habilitado'), texto: tela('Habilitado').texto.replace('uonix.com.br', 'outro.test') } }, 50), /prazo/);
assert.equal(analisarTela(tela('Habilitado')).status, 'habilitado');
assert.equal(analisarTela(tela('Desabilitado')).status, 'desabilitado');
assert.throws(() => analisarTela({ ...tela('Habilitado'), url: URL_PAINEL.replace('73381366', '123') }), /alvo/);
assert.throws(() => analisarTela({ ...tela('Habilitado'), texto: tela('Habilitado').texto.replace('uonix.com.br', 'outro.test') }), /identidade/);
assert.throws(() => analisarTela({ ...tela('Habilitado'), texto: tela('Habilitado').texto + '\nStatus\nDesabilitado' }), /ambíguo/);
assert.throws(() => analisarTela({ ...tela('Habilitado'), botoes: ['Habilitar'] }), /controle/);
assert.throws(() => analisarTela({ ...tela('Habilitado'), modal: { titulo: 'Excluir hospedagem', botoes: ['Confirmar'] } }), /modal/);
assert.throws(() => analisarTela({ url: 'https://login-new.locaweb.com.br/login', texto: '', botoes: [], modal: null }), /autenticação/);
await assert.rejects(aguardarTela({ avaliar: async () => 'https://login-new.locaweb.com.br/login' }, 1000), /autenticação/);
assert.throws(() => analisarTela({ ...tela('Habilitado'), url: URL_PAINEL + '?outro=1' }), /alvo/);
// Exercita a expressão REAL enviada ao CDP, não somente o parser.
const paginaSimulada = endereco => ({ avaliar: async expression => vm.runInNewContext(expression, {
  location: { href: endereco, origin: new URL(endereco).origin, pathname: new URL(endereco).pathname },
  document: { body: { innerText: tela('Habilitado').texto }, querySelectorAll: seletor => seletor === 'button'
    ? [{ innerText: 'Desabilitar', getClientRects: () => [1] }] : [] },
}) });
for (const sufixo of ['?outro=1', '#outro']) {
  const chromeFalso = paginaSimulada(URL_PAINEL + sufixo);
  const lida = await lerTela(chromeFalso);
  assert.equal(lida.url, URL_PAINEL + sufixo, 'leitura CDP precisa preservar URL completa em memória');
  await assert.rejects(aguardarTela(chromeFalso, 50), /alvo/);
}
let leiturasDoConteudoAlheio = 0;
await assert.rejects(aguardarTela({ avaliar: async expression => {
  if (expression === 'location.href') return URL_PAINEL.replace('73381366', '123');
  leiturasDoConteudoAlheio += 1;
  return tela('Habilitado');
} }, 50), /alvo/);
assert.equal(leiturasDoConteudoAlheio, 0, 'validar URL antes de ler conteúdo de outro recurso');
// Retorno SSO observado: login -> raiz do painel com parâmetros -> recurso exato.
let enderecoSSO = 'https://login-new.locaweb.com.br/login';
let leiturasSSO = 0;
const navegacoesSSO = [];
const raizSSO = new URL(URL_PAINEL).origin + '/?retorno=sintetico';
const retornoSSO = { avaliar: async expression => {
  if (expression === 'location.href') {
    leiturasSSO += 1;
    const atual = enderecoSSO;
    if (leiturasSSO === 1) enderecoSSO = raizSSO;
    return atual;
  }
  assert.equal(enderecoSSO, URL_PAINEL, 'nenhuma leitura DOM durante retorno SSO');
  return tela('Habilitado');
}, comando: async (metodo, params) => {
  assert.equal(metodo, 'Page.navigate');
  assert.equal(params.url, URL_PAINEL, 'nunca seguir parâmetros ou redirecionamentos extraídos');
  navegacoesSSO.push(params.url);
  enderecoSSO = URL_PAINEL;
} };
assert.equal((await aguardarTela(retornoSSO, 5000, { loginManual: true })).status, 'habilitado');
assert.deepEqual(navegacoesSSO, [URL_PAINEL], 'retomar alvo uma única vez após retorno SSO estável');
for (const loginManual of [false, true]) {
  await assert.rejects(aguardarTela({ avaliar: async () => raizSSO,
    comando: async () => assert.fail('raiz sem login observado não autoriza navegação') }, 50, { loginManual }), /alvo/);
}
let etapaPrazo = 0;
await assert.rejects(aguardarTela({ avaliar: async () => etapaPrazo++ === 0
  ? 'https://login-new.locaweb.com.br/login' : raizSSO,
  comando: async () => assert.fail('retorno ainda instável não pode interromper o SSO') }, 350, { loginManual: true }), /prazo/);
for (const divergente of [URL_PAINEL.replace('73381366', '123'), URL_PAINEL + '?outro=1',
  new URL(URL_PAINEL).origin + '/outro', raizSSO + '#outro', 'https://outro.test/']) {
  let etapa = 0;
  await assert.rejects(aguardarTela({ avaliar: async () => etapa++ === 0
    ? 'https://login-new.locaweb.com.br/login' : divergente,
    comando: async () => assert.fail('somente a raiz observada de retorno SSO pode retomar o alvo') }, 1000,
  { loginManual: true }), /alvo/);
}
let cicloSSO = 0, retomadas = 0;
await assert.rejects(aguardarTela({ avaliar: async () => cicloSSO++ === 0
  ? 'https://login-new.locaweb.com.br/login' : raizSSO,
  comando: async () => { retomadas += 1; } }, 5000, { loginManual: true }), /alvo/);
assert.equal(retomadas, 1, 'retorno que não conclui não gera loop de navegação');
console.log('OK: retorno SSO limitado ao login manual, sem DOM nem parâmetros no destino');
assert.equal(analisarTela(await lerTela(paginaSimulada(URL_PAINEL))).status, 'habilitado');
// Navegação real pode trocar o documento ENTRE os dois comandos CDP.
for (const endereco of [URL_PAINEL.replace('73381366', '123'), URL_PAINEL + '?outro=1',
  URL_PAINEL + '#outro', 'https://login-new.locaweb.com.br/login']) {
  let acessosAoDocumento = 0;
  const chromeEmNavegacao = { avaliar: async expression => {
    if (expression === 'location.href') return URL_PAINEL;
    return vm.runInNewContext(expression, {
      location: { href: endereco },
      document: new Proxy({}, { get() { acessosAoDocumento += 1; throw new Error('conteúdo divergente acessado'); } }),
    });
  } };
  await assert.rejects(aguardarTela(chromeEmNavegacao, 50), /alvo|autenticação/);
  assert.equal(acessosAoDocumento, 0, 'a própria leitura CDP deve validar URL antes de tocar no documento');
  const lida = await lerTela(chromeEmNavegacao);
  assert.deepEqual(Object.keys(lida), ['url'], 'documento divergente retorna somente URL, sem conteúdo');
}
console.log('OK: identidade, estados e falha fechada da tela SSH');

const tmp = fs.mkdtempSync(path.join(tmpdir(), 'ssh-painel-test-'));
try {
  const home = path.join(tmp, 'rocky');
  fs.mkdirSync(home, { mode: 0o700 });
  const runtime = prepararRuntime(home);
  assert.equal(fs.statSync(runtime.perfil).mode & 0o777, 0o700);
  assert.throws(() => prepararRuntime(home), /concorrente/);
  runtime.liberar();
  const segundo = prepararRuntime(home);
  segundo.liberar();
  const corrida = prepararRuntime(home);
  const remover = fs.unlinkSync;
  let removeuArquivoCompartilhado = false;
  fs.unlinkSync = arquivo => {
    if (String(arquivo).startsWith(path.join(corrida.base, 'execucao.lock') + path.sep)) {
      removeuArquivoCompartilhado = true;
      fs.writeFileSync(arquivo, JSON.stringify({ id: 'dono-trocado-entre-check-e-unlink' }));
    }
    return remover(arquivo);
  };
  try { corrida.liberar(); } finally { fs.unlinkSync = remover; }
  assert.equal(removeuArquivoCompartilhado, false, 'release não pode fazer unlink de marcador compartilhado após validar dono');
  fs.chmodSync(runtime.perfil, 0o755);
  assert.throws(() => prepararRuntime(home), /privado/);
  fs.chmodSync(runtime.perfil, 0o700);
  const falso = path.join(tmp, 'symlink');
  fs.symlinkSync(home, falso);
  assert.throws(() => prepararRuntime(falso), /symlink/);
  const invasora = prepararRuntime(home);
  const lock = path.join(invasora.base, 'execucao.lock');
  const original = fs.readdirSync(lock)[0];
  fs.renameSync(path.join(lock, original), path.join(lock, 'dono-divergente'));
  assert.throws(() => invasora.liberar(), /propriedade/);
  assert.equal(fs.existsSync(path.join(lock, 'dono-divergente')), true);
  fs.renameSync(path.join(lock, 'dono-divergente'), path.join(lock, original));
  invasora.liberar();
  const substituida = prepararRuntime(home);
  const rmdir = fs.rmdirSync;
  const pai = path.join(substituida.base, 'execucao.lock');
  fs.rmdirSync = arquivo => {
    if (String(arquivo) === pai) fs.mkdirSync(path.join(pai, 'novo-dono'), { mode: 0o700 });
    return rmdir(arquivo);
  };
  try { assert.throws(() => substituida.liberar(), /ENOTEMPTY|EEXIST/); }
  finally { fs.rmdirSync = rmdir; }
  assert.equal(fs.statSync(path.join(pai, 'novo-dono')).isDirectory(), true, 'concorrente inserido durante cleanup não pode ser removido');
  fs.rmdirSync(path.join(pai, 'novo-dono')); fs.rmdirSync(pai);
  console.log('OK: perfil privado, lock exclusivo e recusa de symlink');
} finally { fs.rmSync(tmp, { recursive: true, force: true }); }

const servidor = http.createServer((req, res) => {
  if (req.url === '/entrar') res.setHeader('Set-Cookie', [
    'fixture=autorizada; Max-Age=3600; SameSite=Strict; Path=/',
    'fixture_sessao=autorizada; SameSite=Strict; Path=/',
  ]);
  const autorizado = req.url === '/entrar' || (req.headers.cookie?.includes('fixture=autorizada')
    && req.headers.cookie?.includes('fixture_sessao=autorizada'));
  res.end(`<title>Fixture SSH</title><body>${autorizado ? 'autenticado' : 'login'}</body>`);
});
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
const url = `http://127.0.0.1:${servidor.address().port}`;
const tmpChrome = fs.mkdtempSync(path.join(tmpdir(), 'ssh-painel-chrome-'));
let rt, chrome;
try {
  rt = prepararRuntime(tmpChrome);
  chrome = await abrirChromePipe(rt);
  assert.ok(!chrome.processo.spawnargs.some(s => s.includes('remote-debugging-port') || s.includes('no-sandbox')));
  await chrome.comando('Page.navigate', { url: `${url}/entrar` });
  for (let n = 0; n < 50; n += 1) {
    if ((await chrome.avaliar('document.body?.innerText')) === 'autenticado') break;
    await new Promise(r => setTimeout(r, 50));
  }
  assert.equal(await chrome.avaliar('document.body.innerText'), 'autenticado');
  const fechamento = chrome.fechar();
  assert.equal(chrome.fechar(), fechamento, 'encerramentos concorrentes compartilham a mesma promessa');
  await fechamento;
  assert.ok(chrome.processo.exitCode !== null || chrome.processo.signalCode !== null);
  rt.liberar();
  rt = prepararRuntime(tmpChrome);
  chrome = await abrirChromePipe(rt);
  await chrome.comando('Page.navigate', { url });
  for (let n = 0; n < 50; n += 1) {
    if ((await chrome.avaliar('document.body?.innerText')) === 'autenticado') break;
    await new Promise(r => setTimeout(r, 50));
  }
  assert.equal(await chrome.avaliar('document.body.innerText'), 'autenticado', 'novo processo restaura sessão sintética nativa, inclusive cookie sem expiração');
  console.log('OK: Chrome real por pipe e sessão sintética persistente entre processos');
} finally {
  if (chrome) await chrome.fechar();
  if (rt) rt.liberar();
  await new Promise(r => servidor.close(r));
  fs.rmSync(tmpChrome, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}

const cli = spawnSync(process.execPath, ['scripts/plugins/ssh-painel.mjs', '--help'], { encoding: 'utf8' });
assert.equal(cli.status, 0);
assert.match(cli.stdout, /login.*status/s, 'a CLI precisa oferecer preparo e leitura independente');

const pastaFilhos = fs.mkdtempSync(path.join(tmpdir(), 'ssh-filhos-'));
const pidFile = path.join(pastaFilhos, 'filho.pid');
const wrapper = path.join(pastaFilhos, 'chrome-falso');
const fixtureFilhos = path.resolve('scripts/tests/fixtures/ssh-chrome-processo.mjs');
const shell = s => `'${s.replaceAll("'", "'\\''")}'`;
fs.writeFileSync(wrapper, `#!/bin/sh\nexec ${shell(process.execPath)} ${shell(fixtureFilhos)} ${shell(pidFile)}\n`, { mode: 0o700 });
let rtFilhos, falso, pidFilho;
try {
  rtFilhos = prepararRuntime(pastaFilhos);
  falso = await abrirChromePipe(rtFilhos, { executavel: wrapper });
  pidFilho = Number(fs.readFileSync(pidFile, 'utf8'));
  await falso.fechar();
  const vivo = spawnSync('ps', ['-p', String(pidFilho), '-o', 'stat='], { encoding: 'utf8' }).stdout.trim();
  assert.ok(!vivo || vivo.startsWith('Z'), 'fechamento precisa encerrar descendentes que sobrevivem ao líder');
} finally {
  if (pidFilho) { try { process.kill(pidFilho, 'SIGKILL'); } catch {} }
  if (falso) await falso.fechar();
  if (rtFilhos) rtFilhos.liberar();
  fs.rmSync(pastaFilhos, { recursive: true, force: true });
}

const pastaFalha = fs.mkdtempSync(path.join(tmpdir(), 'ssh-launch-falha-'));
const pidFalha = path.join(pastaFalha, 'filho.pid');
const wrapperFalha = path.join(pastaFalha, 'chrome-falso');
fs.writeFileSync(wrapperFalha, `#!/bin/sh\nexec ${shell(process.execPath)} ${shell(fixtureFilhos)} ${shell(pidFalha)} falhar-abertura\n`, { mode: 0o700 });
let rtFalha;
try {
  rtFalha = prepararRuntime(pastaFalha);
  await assert.rejects(abrirChromePipe(rtFalha, { executavel: wrapperFalha,
    executarPS: (bin, args, opcoes, callback) => callback(new Error('ps indisponível')) }),
  erro => erro.preservarTrava === true, 'falha durante abertura com cleanup incerto deve preservar trava');
  assert.equal(fs.existsSync(path.join(rtFalha.base, 'execucao.lock')), true);
} finally {
  if (fs.existsSync(pidFalha)) { try { process.kill(Number(fs.readFileSync(pidFalha, 'utf8')), 'SIGKILL'); } catch {} }
  if (rtFalha) rtFalha.liberar();
  fs.rmSync(pastaFalha, { recursive: true, force: true });
}
