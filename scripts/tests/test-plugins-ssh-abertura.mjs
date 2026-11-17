#!/usr/bin/env node
// Operação sintética: não acessa Locaweb nem executa sonda SSH real.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { tmpdir } from 'node:os';
import vm from 'node:vm';
import http from 'node:http';
import { spawnSync } from 'node:child_process';
import { prepararRuntime, abrirChromePipe } from '../plugins/ssh-painel-chrome.mjs';
import { URL_PAINEL, sondarSSH } from '../plugins/ssh-painel.mjs';
import { habilitarJanela, expressaoHabilitar, aguardarConfirmacao, recarregarPainel, CONFIRMACAO } from '../plugins/ssh-abertura.mjs';
let leituraConfirmacao = 0;
await aguardarConfirmacao({ avaliar: async () => ({ url: URL_PAINEL,
  texto: `uonix.com.br\nStatus\n${leituraConfirmacao++ ? 'Habilitado' : 'Desabilitado'}`,
  botoes: [leituraConfirmacao > 1 ? 'Desabilitar' : 'Habilitar'], modal: null }) }, undefined, 1000);
assert.equal(leituraConfirmacao, 2, 'não recarregar enquanto alteração ainda não concluiu');
await assert.rejects(aguardarConfirmacao({ avaliar: async () => ({ url: URL_PAINEL,
  texto: 'uonix.com.br\nStatus\nDesabilitado', botoes: ['Habilitar'], modal: null }) }, undefined, 20), /não concluiu/);
let reloads = 0, leiturasLoader = 0;
await recarregarPainel({ comando: async metodo => {
  if (metodo === 'Page.reload') { reloads++; return {}; }
  assert.equal(metodo, 'Page.getFrameTree');
  return { frameTree: { frame: { loaderId: leiturasLoader++ < 2 ? 'antes' : 'depois' } } };
} }, undefined, 1000);
assert.equal(reloads, 1); assert.equal(leiturasLoader, 3);
await assert.rejects(recarregarPainel({ comando: async () => ({ frameTree: { frame: { loaderId: 'igual' } } }) }, undefined, 20), /recarga/);
for (const fase of ['abrir', 'confirmar']) {
  const expression = expressaoHabilitar(fase);
  new vm.Script(expression);
  let acessos = 0;
  assert.throws(() => vm.runInNewContext(expression, { URL, location: { href: URL_PAINEL.replace('73381366', '123') },
    document: new Proxy({}, { get() { acessos++; throw new Error('DOM alheio'); } }) }), /alvo/);
  assert.equal(acessos, 0, 'ação valida URL antes de DOM e clique no mesmo turno');
}
assert.throws(() => expressaoHabilitar('desabilitar'), /fase/);
const pasta = fs.mkdtempSync(path.join(tmpdir(), 'ssh-abertura-'));
try {
  const fixture = (aberto = false) => {
    const chamadas = []; let modal = false;
    return { chamadas, dependencias: {
      estado: async () => ({ status: aberto ? 'habilitado' : 'desabilitado', modal }),
      acionar: async fase => { chamadas.push(fase); if (fase === 'abrir') modal = true; else { aberto = true; modal = false; } },
      recarregar: async () => { chamadas.push('reload'); },
      sondar: async () => { chamadas.push('sonda'); return true; },
    } };
  };
  const executar = (f, ciclo, extras = {}) => habilitarJanela({ base: pasta, ciclo, confirmacao: CONFIRMACAO,
    ...f.dependencias, ...extras });
  const aberto = fixture(true);
  assert.equal((await executar(aberto, 'ja-aberto')).alterou_ssh, false);
  assert.deepEqual(aberto.chamadas, ['reload', 'sonda']);
  const negado = fixture();
  await assert.rejects(executar(negado, 'negado', { confirmacao: '' }), /autorização/);
  assert.deepEqual(negado.chamadas, []);
  const normal = fixture();
  const resultado = await executar(normal, 'ciclo-valido');
  assert.equal(resultado.alterou_ssh, true);
  assert.equal(resultado.ssh_autenticado, true);
  assert.deepEqual(normal.chamadas, ['abrir', 'confirmar', 'reload', 'sonda']);
  await assert.rejects(executar(fixture(), 'ciclo-valido'), /ciclo|recente/);
  const comModal = fixture();
  await assert.rejects(executar(comModal, 'modal-inicial', { estado: async () => ({ status: 'desabilitado', modal: true }) }), /modal/);
  assert.deepEqual(comModal.chamadas, []);
  const baseEstado = path.join(pasta, 'estado-pos'); fs.mkdirSync(baseEstado, { mode: 0o700 });
  let sondouEstadoVermelho = false;
  await assert.rejects(habilitarJanela({ base: baseEstado, ciclo: 'falha', confirmacao: CONFIRMACAO,
    estado: async () => ({ status: 'desabilitado', modal: false }), acionar: async () => {}, recarregar: async () => {},
    sondar: async () => { sondouEstadoVermelho = true; return true; } }), /após recarga/);
  assert.equal(sondouEstadoVermelho, false, 'sonda positiva não pode esconder estado pós-recarga desabilitado');
  const cancelada = new AbortController(); cancelada.abort();
  const semAcao = fixture();
  await assert.rejects(executar(semAcao, 'cancelada', { signal: cancelada.signal }), /abort/i);
  assert.deepEqual(semAcao.chamadas, []);
  for (const falha of ['reload', 'sonda']) {
    const base = path.join(pasta, 'leitura-' + falha); fs.mkdirSync(base, { mode: 0o700 });
    const f = fixture(true);
    const deps = { ...f.dependencias };
    if (falha === 'reload') deps.recarregar = async () => { throw new Error('leitura indisponível'); };
    else deps.sondar = async () => false;
    await assert.rejects(habilitarJanela({ base, ciclo: 'primeiro', confirmacao: CONFIRMACAO, ...deps }));
    const proxima = fixture(true);
    assert.equal((await habilitarJanela({ base, ciclo: 'segundo', confirmacao: CONFIRMACAO, ...proxima.dependencias })).alterou_ssh, false,
      'falha sem clique não pode bloquear toda manutenção futura');
    assert.deepEqual(proxima.chamadas, ['reload', 'sonda']);
  }
  // Nova pasta isola os cenários que deixam intenção pendente propositalmente.
  for (const falha of ['confirmar', 'reload', 'sonda']) {
    const base = path.join(pasta, falha); fs.mkdirSync(base, { mode: 0o700 });
    const f = fixture();
    const deps = { ...f.dependencias };
    if (falha === 'confirmar') deps.acionar = async fase => { f.chamadas.push(fase); if (fase === 'confirmar') throw new Error('recusa sintética'); };
    if (falha === 'reload') deps.recarregar = async () => { throw new Error('falha sintética'); };
    if (falha === 'sonda') deps.sondar = async () => false;
    await assert.rejects(habilitarJanela({ base, ciclo: 'falha', confirmacao: CONFIRMACAO, ...deps }));
    const outra = fixture();
    await assert.rejects(habilitarJanela({ base, ciclo: 'outro', confirmacao: CONFIRMACAO, ...outra.dependencias }), /pendente/);
    assert.deepEqual(outra.chamadas, []);
  }
  const cancelamento = new AbortController();
  const baseCancelada = path.join(pasta, 'cancelamento'); fs.mkdirSync(baseCancelada, { mode: 0o700 });
  const chamadasCanceladas = [];
  await assert.rejects(habilitarJanela({ base: baseCancelada, ciclo: 'ciclo', confirmacao: CONFIRMACAO,
    signal: cancelamento.signal, estado: async () => ({ status: 'desabilitado', modal: false }),
    acionar: async fase => { chamadasCanceladas.push(fase); cancelamento.abort(); },
    recarregar: async () => assert.fail('não continuar após cancelamento'),
    sondar: async () => assert.fail('não sondar após cancelamento') }), /abort/i);
  assert.deepEqual(chamadasCanceladas, ['abrir']);
  console.log('OK: autorização, já habilitado sem clique, tentativa única, reload/sonda e falha pendente');
} finally { fs.rmSync(pasta, { recursive: true, force: true }); }

const pastaSonda = fs.mkdtempSync(path.join(tmpdir(), 'ssh-sonda-fixture-'));
try {
  const pid = path.join(pastaSonda, 'filho.pid');
  const script = path.join(pastaSonda, 'pendente.sh');
  fs.writeFileSync(script, `#!/bin/bash\ntrap 'exit 30' TERM\nsleep 120 &\nprintf '%s' "$!" > '${pid}'\nwait\n`);
  assert.equal(await sondarSSH(new AbortController().signal, { script, limiteMs: 300 }), false);
  const filho = Number(fs.readFileSync(pid, 'utf8'));
  const estado = spawnSync('ps', ['-p', String(filho), '-o', 'stat='], { encoding: 'utf8' }).stdout.trim();
  assert.ok(!estado || estado.startsWith('Z'), 'sonda com timeout não deixa descendente vivo');
  const ok = path.join(pastaSonda, 'ok.sh'); fs.writeFileSync(ok, "printf 'SSH_AUTENTICADO_OK\\n'\n");
  assert.equal(await sondarSSH(new AbortController().signal, { script: ok }), true);
  await assert.rejects(sondarSSH(new AbortController().signal, { script: ok,
    executarPS: (bin, args, opcoes, callback) => callback(new Error('ps indisponível')) }),
  erro => erro.preservarTrava === true && /limpeza/.test(erro.message), 'falha ao conferir grupo precisa preservar gate e trava');
  console.log('OK: sonda sintética limitada e grupo de processos encerrado');
} finally { fs.rmSync(pastaSonda, { recursive: true, force: true }); }

const servidor = http.createServer((req, res) => res.end(`<!doctype html><body>
<div>uonix.com.br</div><div>Status</div><div id="estado">Desabilitado</div>
<button id="abrir" onclick="document.getElementById('modal').showModal()">Habilitar</button>
<dialog id="modal" role="dialog"><div>Habilitar acesso SSH</div><p>Tempo habilitado: 3 horas</p>
<button onclick="document.getElementById('modal').close()">Cancelar</button>
<button onclick="document.getElementById('estado').textContent='Habilitado';document.getElementById('abrir').textContent='Desabilitar';document.getElementById('modal').close()">Habilitar</button></dialog>
</body>`));
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
const origem = `http://127.0.0.1:${servidor.address().port}`;
const pastaChrome = fs.mkdtempSync(path.join(tmpdir(), 'ssh-abertura-browser-'));
let rt, chrome;
try {
  rt = prepararRuntime(pastaChrome); chrome = await abrirChromePipe(rt);
  // Fixture servida por loopback com origem virtual autorizada: zero tráfego Locaweb.
  await chrome.comando('Page.navigate', { url: origem });
  for (let n = 0; n < 50; n++) {
    if (await chrome.avaliar('Boolean(document.getElementById("abrir"))')) break;
    await new Promise(r => setTimeout(r, 50));
  }
  // Testa a expressão de produção substituindo só a URL fixa na cópia da fixture.
  const executar = fase => chrome.avaliar(expressaoHabilitar(fase).replaceAll(URL_PAINEL, origem + '/'));
  await chrome.avaliar('document.getElementById("abrir").setAttribute("aria-disabled", "true")');
  await assert.rejects(executar('abrir'), /recusada/);
  assert.equal(await chrome.avaliar('document.querySelector("dialog").open'), false);
  await chrome.avaliar('document.getElementById("abrir").removeAttribute("aria-disabled")');
  assert.equal(await executar('abrir'), true);
  assert.equal(await chrome.avaliar('document.querySelector("dialog").open'), true);
  assert.equal(await executar('confirmar'), true);
  assert.equal(await chrome.avaliar('document.getElementById("estado").textContent'), 'Habilitado');
  await assert.rejects(executar('abrir'), /recusada/);
  console.log('OK: Chrome real valida tela/modal e clique de habilitação na fixture loopback');
} finally {
  if (chrome) await chrome.fechar(); if (rt) rt.liberar();
  await new Promise(r => servidor.close(r));
  fs.rmSync(pastaChrome, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}
