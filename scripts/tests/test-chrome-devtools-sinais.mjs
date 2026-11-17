#!/usr/bin/env node
/**
 * O Chrome dos testes de navegador não pode ficar órfão quando o `node` recebe um sinal (#471).
 *
 * `abrirChrome()` (fixtures/lib-chrome-devtools.mjs) lança o Chrome com `detached: true`, num
 * grupo de processos próprio, para que o encerramento leve os filhos junto. O efeito colateral:
 * o Ctrl-C do terminal (SIGINT) e o SIGTERM de um runner que mata o job por tempo não alcançavam
 * o Chrome. Medido em 2026-11-17 contra a lib sem tratamento de sinal: 1 Chrome aberto, SIGTERM ou
 * SIGINT, e sobravam 10 processos e 1 diretório temporário (rc=143 e rc=130).
 *
 * O teste abre o Chrome pela lib num `node` filho, manda o sinal, e confere (processos E diretório):
 *  - SIGTERM e SIGINT: o filho sai com o código convencional (143 e 130), sem processo nem diretório
 *    restantes, com um Chrome e com dois (os handlers não podem acumular nem sobrescrever);
 *  - fechamento normal (sem sinal): continua sem sobrar nada, e o `fechar()` remove o handler;
 *  - depois de `fechar()`, um sinal NÃO encerra o filho por conta da lib (o handler saiu): um filho que
 *    abriu e fechou o Chrome e segue vivo morre pelo sinal padrão (rc 143), sem o código da lib.
 *
 * Sem Chrome o teste FALHA, não pula (CHROME_BIN aponta para outro Chrome).
 */

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = path.dirname(fileURLToPath(import.meta.url));
const lib = path.join(aqui, 'fixtures', 'lib-chrome-devtools.mjs');
const prefixo = `uox-sinais-${process.pid}-`;
let falhas = 0;

function checar(condicao, mensagem, detalhe) {
  if (!condicao) {
    falhas += 1;
    console.error(`FAIL: ${mensagem}${detalhe === undefined ? '' : ` — ${JSON.stringify(detalhe)}`}`);
  }
}

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function processosDoPrefixo() {
  try {
    return execFileSync('ps', ['-e', '-ww', '-o', 'pid=,command='], { encoding: 'utf8' })
      .split('\n').filter((l) => l.includes(prefixo)).length;
  } catch {
    return -1;
  }
}

function diretoriosDoPrefixo() {
  return readdirSync(tmpdir()).filter((n) => n.startsWith(prefixo));
}

function limparTudo() {
  try {
    execFileSync('sh', ['-c', `ps -e -ww -o pid=,command= | grep -F -- '${prefixo}' | grep -v grep | awk '{print $1}' | xargs kill -9 2>/dev/null; true`]);
  } catch { /* nada a matar */ }
  for (const nome of diretoriosDoPrefixo()) rmSync(path.join(tmpdir(), nome), { recursive: true, force: true });
}

// Filho: abre `n` Chromes pela lib e, conforme `modo`, fica vivo (`vivo`), fecha todos (`fecha`)
// ou fecha só o primeiro e deixa os outros vivos (`um-fecha`). Depois imprime PRONTO e espera o sinal.
function codigoDoFilho(n, modo) {
  return `
    import { abrirChrome } from ${JSON.stringify(lib)};
    const abertos = [];
    for (let i = 0; i < ${n}; i += 1) abertos.push(await abrirChrome(${JSON.stringify(prefixo)}));
    if (${JSON.stringify(modo)} === 'fecha') for (const a of abertos) await a.fechar();
    // O tratamento de sinal tem de continuar valendo para o Chrome que segue vivo.
    if (${JSON.stringify(modo)} === 'um-fecha') await abertos[0].fechar();
    console.log('PRONTO');
    setInterval(() => {}, 1000);
  `;
}

async function rodar(n, modo, sinal) {
  const filho = spawn(process.execPath, ['--input-type=module', '-e', codigoDoFilho(n, modo)], { stdio: ['ignore', 'pipe', 'pipe'] });
  let saida = '';
  filho.stdout.on('data', (p) => { saida += p; });
  filho.stderr.on('data', (p) => { saida += p; });
  const saiu = new Promise((resolver) => filho.once('exit', (codigo, sig) => resolver({ codigo, sig })));
  for (let i = 0; i < 120 && !saida.includes('PRONTO'); i += 1) await dormir(250);
  if (!saida.includes('PRONTO')) {
    filho.kill('SIGKILL');
    return { pronto: false, saida };
  }
  if (sinal) filho.kill(sinal);
  const fim = await Promise.race([saiu, dormir(15000).then(() => ({ codigo: null, sig: 'TIMEOUT' }))]);
  if (fim.sig === 'TIMEOUT') filho.kill('SIGKILL');
  await dormir(1500);
  return { pronto: true, ...fim, processos: processosDoPrefixo(), diretorios: diretoriosDoPrefixo() };
}

async function contarTratadores() {
  const codigo = `
    import { abrirChrome } from ${JSON.stringify(lib)};
    const n = () => ({ int: process.listenerCount('SIGINT'), term: process.listenerCount('SIGTERM'), hup: process.listenerCount('SIGHUP') });
    const antes = n();
    const a = await abrirChrome(${JSON.stringify(prefixo)});
    const com1 = n();
    const b = await abrirChrome(${JSON.stringify(prefixo)});
    const com2 = n();
    await a.fechar(); await b.fechar();
    const depois = n();
    console.log('RESULTADO ' + JSON.stringify({ antes, com1, com2, depois }));
    process.exit(0);
  `;
  const filho = spawn(process.execPath, ['--input-type=module', '-e', codigo], { stdio: ['ignore', 'pipe', 'pipe'] });
  let saida = '';
  filho.stdout.on('data', (p) => { saida += p; });
  filho.stderr.on('data', (p) => { saida += p; });
  await new Promise((resolver) => filho.once('exit', resolver));
  const achado = saida.match(/RESULTADO (\{.*\})/);
  if (!achado) return { erro: saida };
  const r = JSON.parse(achado[1]);
  const base = r.antes;
  const delta = (o) => Math.max(o.int - base.int, o.term - base.term);
  return { com1: delta(r.com1), com2: delta(r.com2), depois: Math.max(r.depois.int - base.int, r.depois.term - base.term), sighup: r.com2.hup - base.hup };
}

const limite = setTimeout(() => {
  console.error('FAIL: teste de sinais passou de 150s');
  limparTudo();
  process.exit(1);
}, 150000);

try {
  const casos = [
    { rotulo: 'SIGTERM, 1 Chrome', n: 1, modo: 'vivo', sinal: 'SIGTERM', codigo: 143 },
    { rotulo: 'SIGINT, 1 Chrome', n: 1, modo: 'vivo', sinal: 'SIGINT', codigo: 130 },
    { rotulo: 'SIGTERM, 2 Chromes', n: 2, modo: 'vivo', sinal: 'SIGTERM', codigo: 143 },
    { rotulo: 'SIGINT, 2 Chromes', n: 2, modo: 'vivo', sinal: 'SIGINT', codigo: 130 },
  ];
  for (const caso of casos) {
    const r = await rodar(caso.n, caso.modo, caso.sinal);
    checar(r.pronto, `${caso.rotulo}: o filho abriu o Chrome`, r.saida);
    if (!r.pronto) continue;
    checar(r.codigo === caso.codigo, `${caso.rotulo}: o filho sai com o código convencional ${caso.codigo}`, { codigo: r.codigo, sinal: r.sig });
    checar(r.processos === 0, `${caso.rotulo}: nenhum processo do Chrome sobra`, r.processos);
    checar(r.diretorios.length === 0, `${caso.rotulo}: nenhum diretório temporário sobra`, r.diretorios);
    limparTudo();
  }

  // Depois do fechamento normal não sobra nada, e o handler da lib já saiu: um SIGTERM mata o filho
  // pelo sinal padrão (sig SIGTERM, sem código), e não por `process.exit(143)` da lib.
  const fechado = await rodar(1, 'fecha', 'SIGTERM');
  checar(fechado.pronto, 'fecha+SIGTERM: o filho abriu e fechou o Chrome', fechado.saida);
  if (fechado.pronto) {
    checar(fechado.processos === 0 && fechado.diretorios.length === 0, 'fecha+SIGTERM: nada sobra depois de fechar()', { p: fechado.processos, d: fechado.diretorios });
    checar(fechado.sig === 'SIGTERM' && fechado.codigo === null, 'fecha+SIGTERM: o handler da lib saiu em fechar() (o filho morre pelo sinal padrão)', { codigo: fechado.codigo, sinal: fechado.sig });
  }
  limparTudo();

  // Dois Chromes, o primeiro fecha e o segundo segue vivo: o sinal ainda tem de encerrar o segundo.
  // Pega o tratamento removido cedo demais (ao fechar o primeiro) e o sobrescrito por abertura.
  const umFecha = await rodar(2, 'um-fecha', 'SIGTERM');
  checar(umFecha.pronto, 'um-fecha+SIGTERM: o filho abriu dois e fechou um', umFecha.saida);
  if (umFecha.pronto) {
    checar(umFecha.codigo === 143, 'um-fecha+SIGTERM: o filho sai com 143 mesmo depois de fechar o primeiro Chrome', { codigo: umFecha.codigo, sinal: umFecha.sig });
    checar(umFecha.processos === 0 && umFecha.diretorios.length === 0, 'um-fecha+SIGTERM: o Chrome que seguia vivo é encerrado', { p: umFecha.processos, d: umFecha.diretorios });
  }
  limparTudo();

  // Um tratador por sinal no processo, mesmo com dois Chromes abertos (não acumula por abertura), e
  // nenhum depois de fechar todos. SIGHUP não é registrado.
  const contagem = await contarTratadores();
  checar(contagem.com2 === contagem.com1 && contagem.com1 === 1, 'um tratador por sinal, mesmo com 2 Chromes abertos (não acumula)', contagem);
  checar(contagem.depois === 0, 'depois de fechar todos, nenhum tratador de SIGINT/SIGTERM fica registrado', contagem);
  checar(contagem.sighup === 0, 'a lib não registra SIGHUP', contagem);
  limparTudo();
} catch (erro) {
  falhas += 1;
  console.error(`FAIL: ${erro.stack || erro}`);
} finally {
  clearTimeout(limite);
  limparTudo();
}

if (falhas > 0) {
  console.error(`${falhas} falha(s).`);
  process.exit(1);
}
console.log('PASS: SIGTERM e SIGINT não deixam o Chrome dos testes de navegador órfão, e o handler sai em fechar().');
process.exit(0);
