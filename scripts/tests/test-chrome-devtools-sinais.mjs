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
 *    abriu e fechou o Chrome e segue vivo morre pelo sinal padrão (rc 143), sem o código da lib;
 *  - as janelas em que o sinal costuma se perder (revisão do PR #483): o sinal DURANTE a abertura (o Chrome já
 *    lançado e o `abrirChrome` sem retornar; uma vez mandado de fora e outra pelo próprio filho, de dentro do
 *    `aoIniciar`, que é determinístico), DURANTE o `fechar()`, e depois de abrir, fechar tudo e abrir de novo;
 *  - o `aoIniciar` do chamador que LANÇA: a abertura rejeita e o Chrome já lançado não pode ficar órfão;
 *  - o perfil que o Chrome ainda está gravando: `removerDiretorio()` repete a remoção POR INTEIRO, relistando,
 *    porque o `rmSync` do Node só repete o `rmdir` e desistia depois de ~11 s com `perfil/` cheio. Um escritor
 *    que cria arquivos novos durante a janela torna a corrida determinística (a remoção antiga falhou em 8 de 8).
 *
 * Todo cenário de sinal exige que o filho saia em menos de 5 s. O teste leva de 35 a 55 s numa máquina local.
 *
 * Sem Chrome o teste FALHA, não pula (CHROME_BIN aponta para outro Chrome).
 */

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { removerDiretorio } from './fixtures/lib-chrome-devtools.mjs';

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

// Diretório sobrante que é só o esqueleto vazio do perfil (`perfil/`, sem nenhum arquivo dentro). Medido em 3.120 sinais durante a
// subida do Chrome: 2 diretórios (0,06%), sem nenhum processo vivo, que não consegui reproduzir à vontade. Só o cenário "durante a
// abertura" o tolera, e processos continuam sendo exigência rígida.
function soEsqueletoVazio(nomes) {
  return nomes.every((nome) => {
    const dir = path.join(tmpdir(), nome);
    try {
      const itens = readdirSync(dir);
      if (itens.length === 0) return true;
      if (itens.length !== 1 || itens[0] !== 'perfil') return false;
      return readdirSync(path.join(dir, 'perfil')).length === 0;
    } catch {
      return true;
    }
  });
}

function limparTudo() {
  try {
    execFileSync('sh', ['-c', `ps -e -ww -o pid=,command= | grep -F -- '${prefixo}' | grep -v grep | awk '{print $1}' | xargs kill -9 2>/dev/null; true`]);
  } catch { /* nada a matar */ }
  for (const nome of diretoriosDoPrefixo()) rmSync(path.join(tmpdir(), nome), { recursive: true, force: true });
}

// Escritor que mantém o perfil em obras: grava 3.000 arquivos de uma vez (a remoção que o encontrar terá de apagar tudo isso, e
// leva uma centena de ms) e, depois do PRONTO, continua criando arquivos NOVOS, um a cada ~2 ms, por até UOX_MS ms. Reproduz de forma
// determinística o que o Chrome faz ao morrer durante a subida: criar arquivo DEPOIS da listagem da remoção. O `rmSync` do Node
// lista uma vez, apaga o que listou e tenta o `rmdir`: com o escritor ainda vivo, sempre sobra um arquivo criado depois da listagem,
// e as repetições (que só refazem o `rmdir`) falham com ENOTEMPTY até desistir. Uma remoção que RELISTA acompanha o escritor e
// termina logo depois que ele para. O escritor continua gravando durante todo o prazo, e não apenas até um número de arquivos:
// quando o sinal chega alguns centésimos depois do PRONTO (o teste lê a saída do filho de 250 em 250 ms), ele ainda está escrevendo.
// Cria o diretório UMA vez: sem `mkdir` depois, ele nunca ressuscita o que a remoção já levou, e para na primeira falha.
const ESCRITOR = `
  import { mkdirSync, writeFileSync } from 'node:fs';
  import path from 'node:path';
  const pasta = path.join(process.env.UOX_DIR, 'perfil', 'Default');
  const espera = new Int32Array(new SharedArrayBuffer(4));
  try { mkdirSync(pasta, { recursive: true }); for (let i = 0; i < 3000; i += 1) writeFileSync(path.join(pasta, 'a' + i), 'x'); } catch {}
  console.log('PRONTO');
  const fim = Date.now() + Number(process.env.UOX_MS);
  for (let n = 0; n < 3000 && Date.now() < fim; n += 1) {
    try { writeFileSync(path.join(pasta, 'b' + n), 'x'); } catch { break; }
    Atomics.wait(espera, 0, 0, 2);
  }
`;

// Filho: abre `n` Chromes pela lib e, conforme `modo`, fica vivo (`vivo`), fecha todos (`fecha`), fecha só o primeiro e deixa os
// outros vivos (`um-fecha`), ou exercita uma janela em que o sinal costuma se perder. Depois imprime PRONTO e espera o sinal.
//  - `durante-abertura`: o PRONTO sai de dentro do `aoIniciar`, isto é, com o Chrome JÁ lançado e o `abrirChrome` ainda a meio
//    caminho do handshake do DevTools. É o caso do runner que mata o job por tempo quando o Chrome trava na subida: o registro do
//    encerramento tem de existir ANTES do primeiro `await`.
//  - `durante-abertura-sinal-proprio`: o sinal sai do PRÓPRIO filho, de dentro do `aoIniciar`: o ponto mais cedo da janela (depois do
//    spawn e antes de qualquer `await` da lib). É determinístico, ao contrário do sinal que o teste manda de fora, que só chega depois de
//    até 250 ms (o PRONTO é lido de 250 em 250 ms) e pode cair depois do handshake do DevTools. Pega o registro do encerramento que só
//    acontece depois de algum `await` (mutantes L1, L1b e L1d).
//  - `durante-abertura-escrita`: como `durante-abertura`, mas com um escritor reescrevendo o perfil enquanto o sinal chega. O PRONTO
//    só sai depois da primeira escrita do escritor, então a remoção do diretório encontra SEMPRE um arquivo novo depois da listagem.
//  - `durante-fechar`: o PRONTO sai quando o `fechar()` já começou e ainda não terminou (o Chrome sai, mas o diretório está sendo limpo).
//  - `reabre`: abre, fecha tudo e abre de novo. O registro do processo tem de voltar a existir na segunda abertura.
function codigoDoFilho(n, modo) {
  return `
    import { abrirChrome } from ${JSON.stringify(lib)};
    import { spawn } from 'node:child_process';
    import { readdirSync } from 'node:fs';
    import { tmpdir } from 'node:os';
    import path from 'node:path';
    const modo = ${JSON.stringify(modo)};
    const abertos = [];
    if (modo === 'ao-iniciar-lanca') {
      // O aoIniciar do chamador lança com o Chrome já lançado: a abertura rejeita e o filho segue vivo. O Chrome não pode
      // ficar órfão, e o sinal seguinte tem de encontrar o encerramento registrado.
      let rejeitou = false;
      try {
        await abrirChrome(${JSON.stringify(prefixo)}, () => { throw new Error('aoIniciar quebrou'); });
      } catch (e) { rejeitou = /aoIniciar quebrou/.test(String(e && e.message)); }
      console.log(rejeitou ? 'PRONTO' : 'NAO-REJEITOU');
      // Sai sozinho, sem sinal: se a lib deixou o Chrome órfão, ele sobrevive à saída do filho e o teste o vê. Um sinal aqui
      // esconderia o defeito, porque o tratador (se ainda registrado) mataria o Chrome.
      setTimeout(() => process.exit(0), 300);
    } else if (modo === 'durante-abertura-sinal-proprio') {
      abrirChrome(${JSON.stringify(prefixo)}, () => { console.log('PRONTO'); process.kill(process.pid, 'SIGTERM'); }).catch(() => {});
      setInterval(() => {}, 1000);
    } else if (modo === 'durante-abertura-escrita') {
      // O diretório temporário já existe quando o aoIniciar roda (o mkdtemp vem antes do spawn). O PRONTO sai do escritor.
      abrirChrome(${JSON.stringify(prefixo)}, () => {
        const nome = readdirSync(tmpdir()).find((n) => n.startsWith(${JSON.stringify(prefixo)}));
        const escritor = spawn(process.execPath, ['--input-type=module', '-e', ${JSON.stringify(ESCRITOR)}], { stdio: ['ignore', 'pipe', 'ignore'], env: { ...process.env, UOX_DIR: path.join(tmpdir(), nome), UOX_MS: '3000' } });
        escritor.stdout.once('data', () => console.log('PRONTO'));
        escritor.unref();
      }).catch(() => {});
      setInterval(() => {}, 1000);
    } else if (modo === 'durante-abertura') {
      // Nunca resolve: o filho morre pelo sinal, ou pelo teto do teste.
      abrirChrome(${JSON.stringify(prefixo)}, () => { console.log('PRONTO'); }).catch(() => {});
      setInterval(() => {}, 1000);
    } else {
      for (let i = 0; i < ${n}; i += 1) abertos.push(await abrirChrome(${JSON.stringify(prefixo)}));
      if (modo === 'fecha') for (const a of abertos) await a.fechar();
      // O tratamento de sinal tem de continuar valendo para o Chrome que segue vivo.
      if (modo === 'um-fecha') await abertos[0].fechar();
      if (modo === 'reabre') {
        for (const a of abertos) await a.fechar();
        abertos.length = 0;
        abertos.push(await abrirChrome(${JSON.stringify(prefixo)}));
      }
      if (modo === 'durante-fechar') {
        const fechando = abertos[0].fechar();
        console.log('PRONTO');
        await fechando;
      } else {
        console.log('PRONTO');
      }
      setInterval(() => {}, 1000);
    }
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
  const t0 = Date.now();
  if (sinal) filho.kill(sinal);
  const fim = await Promise.race([saiu, dormir(15000).then(() => ({ codigo: null, sig: 'TIMEOUT' }))]);
  const duracaoMs = Date.now() - t0;
  if (fim.sig === 'TIMEOUT') filho.kill('SIGKILL');
  await dormir(1500);
  const diretorios = diretoriosDoPrefixo();
  return { pronto: true, ...fim, duracaoMs, processos: processosDoPrefixo(), diretorios, esqueletoVazio: diretorios.length > 0 && soEsqueletoVazio(diretorios) };
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

// Árvore parecida com a que o Chrome deixa no perfil.
function arvore(dir) {
  mkdirSync(path.join(dir, 'perfil', 'Default'), { recursive: true });
  writeFileSync(path.join(dir, 'perfil', 'Default', 'Cookies'), 'x');
  writeFileSync(path.join(dir, 'perfil', 'Local State'), 'x');
}
const erroCom = (codigo) => Object.assign(new Error(codigo), { code: codigo });
const prefixoRm = `uox-rmdir-${process.pid}-`;

try {
  // ---- removerDiretorio: a remoção é repetida por INTEIRO, relistando (revisão do PR #483) -------------------------------
  // O `rmSync` do Node só repete o `rmdir`, e nunca relista o diretório. Um arquivo criado DEPOIS da listagem (o `Local State`
  // que o Chrome ainda grava ao morrer, com o sinal chegando enquanto ele sobe) fazia o `rmdir` falhar com ENOTEMPTY em todas as
  // repetições: ~11 s de espera inútil dentro do tratador de sinal e `perfil/Local State` sobrando. Medido em 50 rodadas: 3 falhas
  // na lib commitada e 5 na minha.
  {
    const d1 = mkdtempSync(path.join(tmpdir(), prefixoRm));
    arvore(d1);
    const r1 = removerDiretorio(d1);
    checar(r1.ok && r1.passadas === 1 && !existsSync(d1), 'removerDiretorio: a árvore normal sai em UMA passada', r1);

    // Arquivo tardio: na 1.ª chamada ele aparece depois da listagem e o rmdir falha; a 2.ª relista e leva tudo.
    const d2 = mkdtempSync(path.join(tmpdir(), prefixoRm));
    arvore(d2);
    let chamadas2 = 0;
    let dormiu2 = 0;
    const r2 = removerDiretorio(d2, {
      remover: (alvo, opcoes) => {
        chamadas2 += 1;
        if (chamadas2 === 1) {
          writeFileSync(path.join(alvo, 'perfil', 'Local State'), 'tardio');
          throw erroCom('ENOTEMPTY');
        }
        rmSync(alvo, opcoes);
      },
      dormir: () => { dormiu2 += 1; },
    });
    checar(r2.ok && r2.passadas === 2 && chamadas2 === 2 && dormiu2 === 1 && !existsSync(d2), 'removerDiretorio: o arquivo tardio é levado pela 2.ª passada, que relista', { r2, chamadas2, dormiu2 });

    // Escritor que não para: esgota as passadas, devolve o erro, e NÃO dorme depois da última (sairia atrasado à toa).
    const d3 = mkdtempSync(path.join(tmpdir(), prefixoRm));
    arvore(d3);
    let chamadas3 = 0;
    let dormiu3 = 0;
    const r3 = removerDiretorio(d3, {
      passadas: 3,
      remover: () => { chamadas3 += 1; throw erroCom('ENOTEMPTY'); },
      dormir: () => { dormiu3 += 1; },
    });
    checar(!r3.ok && r3.erro && r3.erro.code === 'ENOTEMPTY' && chamadas3 === 3, 'removerDiretorio: sem sucesso em N passadas devolve o erro, sem lançar', { ok: r3.ok, codigo: r3.erro && r3.erro.code, chamadas3 });
    checar(dormiu3 === 2, 'removerDiretorio: dorme entre as passadas e não depois da última', { dormiu3 });
    rmSync(d3, { recursive: true, force: true });

    // Os PADRÕES (100 passadas de 50 ms, cerca de 5 s) são o que `limparTmp` usa: só são vistos de verdade quando o Chrome ainda
    // grava ao morrer (0,06% das rodadas), então ficam presos aqui, com o `dormir` trocado para o teste não esperar.
    const d7 = mkdtempSync(path.join(tmpdir(), prefixoRm));
    const dormidos7 = [];
    let chamadas7 = 0;
    const r7 = removerDiretorio(d7, { remover: () => { chamadas7 += 1; throw erroCom('ENOTEMPTY'); }, dormir: (ms) => { dormidos7.push(ms); } });
    checar(!r7.ok && r7.passadas === 100 && chamadas7 === 100, 'removerDiretorio: o padrão são 100 passadas', { passadas: r7.passadas, chamadas7 });
    checar(dormidos7.length === 99 && dormidos7.every((ms) => ms === 50), 'removerDiretorio: o padrão dorme 50 ms entre as passadas (99 pausas, cerca de 5 s no pior caso)', { pausas: dormidos7.length, valores: [...new Set(dormidos7)] });
    rmSync(d7, { recursive: true, force: true });

    // Erro que não adianta repetir (permissão): devolve na hora.
    const d4 = mkdtempSync(path.join(tmpdir(), prefixoRm));
    let chamadas4 = 0;
    let dormiu4 = 0;
    const r4 = removerDiretorio(d4, { remover: () => { chamadas4 += 1; throw erroCom('EACCES'); }, dormir: () => { dormiu4 += 1; } });
    checar(!r4.ok && r4.erro.code === 'EACCES' && chamadas4 === 1 && dormiu4 === 0, 'removerDiretorio: erro que não é de corrida (EACCES) não é repetido', { chamadas4, dormiu4 });
    rmSync(d4, { recursive: true, force: true });

    // Diretório que já não existe: sucesso, sem erro.
    const r5 = removerDiretorio(path.join(tmpdir(), `${prefixoRm}inexistente`));
    checar(r5.ok && r5.passadas === 1, 'removerDiretorio: diretório ausente é sucesso', r5);

    // A corrida de verdade: um processo que cria 5.000 arquivos novos no perfil e o sinal (aqui, a chamada) chega com 2.000. O `rmSync`
    // do Node sozinho falha com ENOTEMPTY depois de ~11 s (o que o escritor cria depois da listagem nunca é relistado: 8 de 8 rodadas
    // medidas); a remoção por passadas relista e termina em poucas centenas de ms (8 de 8, em 3 passadas).
    const d6 = mkdtempSync(path.join(tmpdir(), prefixoRm));
    arvore(d6);
    const escritor = spawn(process.execPath, ['--input-type=module', '-e', ESCRITOR], { stdio: ['ignore', 'pipe', 'ignore'], env: { ...process.env, UOX_DIR: d6, UOX_MS: '3000' } });
    await new Promise((resolver) => escritor.stdout.once('data', resolver));
    const t6 = Date.now();
    const r6 = removerDiretorio(d6, { passadas: 200, intervaloMs: 20 });
    const dur6 = Date.now() - t6;
    checar(r6.ok && !existsSync(d6), 'removerDiretorio: com um escritor reescrevendo o perfil, a remoção por passadas termina com o diretório removido', { r6: { ok: r6.ok, passadas: r6.passadas }, dur6 });
    checar(dur6 < 5000, 'removerDiretorio: termina em poucos segundos (o rmSync sozinho levava ~11 s para desistir)', { dur6 });
    await new Promise((resolver) => escritor.once('exit', resolver));
    rmSync(d6, { recursive: true, force: true });
  }

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
    checar(r.duracaoMs < 5000, `${caso.rotulo}: o filho sai em menos de 5 s depois do sinal`, { duracaoMs: r.duracaoMs });
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

  // O sinal chega com o Chrome JÁ lançado e o `abrirChrome` ainda sem retornar (Chrome travado na subida, que é quando o runner
  // mata o job por tempo). O registro do encerramento tem de existir antes do primeiro `await` da lib; se vier depois do
  // handshake, o filho morre pelo sinal padrão (código nulo) e o Chrome fica órfão. Revisão do PR #483, mutante L1.
  const duranteAbertura = await rodar(1, 'durante-abertura', 'SIGTERM');
  checar(duranteAbertura.pronto, 'durante-abertura+SIGTERM: o Chrome foi lançado e o filho chegou ao ponto da subida', duranteAbertura.saida);
  if (duranteAbertura.pronto) {
    checar(duranteAbertura.codigo === 143, 'durante-abertura+SIGTERM: o filho sai com 143 mesmo antes de abrirChrome retornar', { codigo: duranteAbertura.codigo, sinal: duranteAbertura.sig });
    checar(duranteAbertura.duracaoMs < 5000, 'durante-abertura+SIGTERM: o filho sai em menos de 5 s (a remoção do perfil que o Chrome ainda grava não pode esperar ~11 s)', { duracaoMs: duranteAbertura.duracaoMs });
    checar(duranteAbertura.processos === 0, 'durante-abertura+SIGTERM: nenhum processo do Chrome sobra', { p: duranteAbertura.processos });
    checar(duranteAbertura.diretorios.length === 0 || duranteAbertura.esqueletoVazio, 'durante-abertura+SIGTERM: nenhum diretório sobra, a não ser o esqueleto vazio do perfil (residual medido de 2 em 3.120)', { d: duranteAbertura.diretorios });
  }
  limparTudo();

  // O sinal sai do próprio filho, de dentro do `aoIniciar`, o ponto mais cedo da janela. Se a lib registrasse o encerramento só depois
  // de algum `await` (do handshake do DevTools, por exemplo), o filho morreria pelo sinal padrão (código nulo) e o Chrome ficaria órfão.
  const sinalProprio = await rodar(1, 'durante-abertura-sinal-proprio', null);
  checar(sinalProprio.pronto, 'durante-abertura-sinal-proprio: o filho lançou o Chrome e mandou o sinal a si mesmo', sinalProprio.saida);
  if (sinalProprio.pronto) {
    checar(sinalProprio.codigo === 143, 'durante-abertura-sinal-proprio: o filho sai com 143 (o encerramento já estava registrado antes de qualquer await)', { codigo: sinalProprio.codigo, sinal: sinalProprio.sig });
    checar(sinalProprio.processos === 0, 'durante-abertura-sinal-proprio: nenhum processo do Chrome sobra', { p: sinalProprio.processos });
    checar(sinalProprio.diretorios.length === 0 || sinalProprio.esqueletoVazio, 'durante-abertura-sinal-proprio: nenhum diretório sobra, a não ser o esqueleto vazio do perfil', { d: sinalProprio.diretorios });
  }
  limparTudo();

  // O sinal chega com alguém ainda GRAVANDO no perfil: é a corrida que o `maxRetries` do Node perdia (ele só repete o `rmdir`, nunca
  // relista), e que `removerDiretorio` ganha porque refaz a remoção por inteiro. Aqui a corrida é determinística (um escritor que só
  // para depois de 800 ms), e não as 3 e 5 falhas em 50 rodadas que medi sem ele; é o teste que liga `limparTmp` a `removerDiretorio`. Com a
  // lib anterior, `perfil/Local State` sobra e o filho leva ~11 s para sair.
  const duranteEscrita = await rodar(1, 'durante-abertura-escrita', 'SIGTERM');
  checar(duranteEscrita.pronto, 'durante-abertura-escrita+SIGTERM: o escritor começou a gravar no perfil antes do sinal', duranteEscrita.saida);
  if (duranteEscrita.pronto) {
    checar(duranteEscrita.codigo === 143, 'durante-abertura-escrita+SIGTERM: o filho sai com 143', { codigo: duranteEscrita.codigo, sinal: duranteEscrita.sig });
    checar(duranteEscrita.duracaoMs < 5000, 'durante-abertura-escrita+SIGTERM: o filho sai em menos de 5 s (o rmSync com maxRetries levava ~11 s)', { duracaoMs: duranteEscrita.duracaoMs });
    checar(duranteEscrita.processos === 0, 'durante-abertura-escrita+SIGTERM: nenhum processo do Chrome sobra', { p: duranteEscrita.processos });
    checar(duranteEscrita.diretorios.length === 0 || duranteEscrita.esqueletoVazio, 'durante-abertura-escrita+SIGTERM: o perfil que ainda estava sendo gravado é removido (nenhum diretório sobra, a não ser o esqueleto vazio)', { d: duranteEscrita.diretorios });
  }
  limparTudo();

  // O `aoIniciar` do chamador lança com o Chrome já lançado. Antes, a abertura rejeitava e o Chrome ficava órfão: o registro do
  // encerramento vinha DEPOIS do `aoIniciar`, que roda fora do `try`. Revisão do PR #483, BAIXO 5.
  const aoIniciarLanca = await rodar(1, 'ao-iniciar-lanca', null);
  checar(aoIniciarLanca.pronto, 'ao-iniciar-lanca: a abertura rejeita com o erro do chamador', aoIniciarLanca.saida);
  if (aoIniciarLanca.pronto) {
    checar(aoIniciarLanca.processos === 0 && aoIniciarLanca.diretorios.length === 0, 'ao-iniciar-lanca: o Chrome lançado NÃO fica órfão quando o aoIniciar lança (a abertura o encerra)', { p: aoIniciarLanca.processos, d: aoIniciarLanca.diretorios });
  }
  limparTudo();

  // O sinal chega enquanto `fechar()` está em andamento: o tratamento só pode sair DEPOIS que o fechamento termina. Se sair no
  // começo, um sinal no meio do fechamento mata o filho sem limpar nada. Mutante L2.
  const duranteFechar = await rodar(1, 'durante-fechar', 'SIGTERM');
  checar(duranteFechar.pronto, 'durante-fechar+SIGTERM: o filho começou a fechar o Chrome', duranteFechar.saida);
  if (duranteFechar.pronto) {
    checar(duranteFechar.processos === 0 && duranteFechar.diretorios.length === 0, 'durante-fechar+SIGTERM: nenhum processo nem diretório sobra', { p: duranteFechar.processos, d: duranteFechar.diretorios, codigo: duranteFechar.codigo, sinal: duranteFechar.sig });
  }
  limparTudo();

  // Abre, fecha tudo e abre de novo: a segunda abertura tem de registrar o encerramento outra vez. Mutante L3 (`clear()` ausente).
  const reabre = await rodar(1, 'reabre', 'SIGTERM');
  checar(reabre.pronto, 'reabre+SIGTERM: o filho abriu, fechou e abriu de novo', reabre.saida);
  if (reabre.pronto) {
    checar(reabre.codigo === 143, 'reabre+SIGTERM: o filho sai com 143 com o Chrome da SEGUNDA abertura', { codigo: reabre.codigo, sinal: reabre.sig });
    checar(reabre.processos === 0 && reabre.diretorios.length === 0, 'reabre+SIGTERM: nada sobra da segunda abertura', { p: reabre.processos, d: reabre.diretorios });
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
  for (const nome of readdirSync(tmpdir()).filter((x) => x.startsWith(prefixoRm))) rmSync(path.join(tmpdir(), nome), { recursive: true, force: true });
}

if (falhas > 0) {
  console.error(`${falhas} falha(s).`);
  process.exit(1);
}
console.log('PASS: SIGTERM e SIGINT não deixam o Chrome dos testes de navegador órfão, e o handler sai em fechar().');
process.exit(0);
