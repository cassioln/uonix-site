#!/usr/bin/env node
/**
 * Executa num Chrome headless de verdade o clique dos menus de grupo do editor
 * ("Seções do Site", "Políticas e LGPD"; mu-plugins/uonix-admin/67-admin-editor-menus.php).
 *
 * O teste PHP (test-admin-editor-menus.php) só confere o texto do script: na
 * revisão do #391, apagar a alternância de wp-not-current-submenu passou verde
 * (issue #392). Aqui o HTML vem do PHP real (fixtures/menus-grupo-cenario.php),
 * o clique é um clique de mouse do Chrome e o resultado é medido no layout:
 *
 * - largura normal: o 1º clique abre o submenu em linha, o 2º fecha, sem navegar;
 * - grupo da tela atual (já aberto): o 1º clique fecha, o 2º reabre;
 * - menu recolhido (folded), recolhido automático (auto-fold, 900px) e tela
 *   estreita (400px, com e sem auto-fold): o clique não navega nem muda
 *   classes, e o submenu segue fora da tela (flyout do núcleo);
 * - controle: "Blog", que não é grupo, navega no clique.
 *
 * "Navegou" vem do evento Page.frameStartedLoading do Chrome, não de tempo.
 *
 * Sem Chrome o teste FALHA, não pula (CHROME_BIN aponta para outro Chrome).
 * PHP_BIN escolhe o PHP usado para gerar os cenários.
 */

import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { abrirChrome } from './fixtures/lib-chrome-devtools.mjs';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const gerador = path.join(raiz, 'scripts/tests/fixtures/menus-grupo-cenario.php');
const php = process.env.PHP_BIN || 'php';
const GRUPOS = ['toplevel_page_widgets', 'toplevel_page_politicas'];

let falhas = 0;

function checar(condicao, mensagem, detalhe) {
  if (!condicao) {
    falhas += 1;
    console.error(`FAIL: ${mensagem}${detalhe === undefined ? '' : ` — ${JSON.stringify(detalhe)}`}`);
  }
}

let navegador;
// Registrado logo depois do spawn: vale mesmo se travar dentro de abrirChrome.
let encerrar = () => {};
const limite = setTimeout(() => {
  console.error('FAIL: teste de navegador passou de 120s');
  encerrar();
  process.exit(1);
}, 120000);

try {
  navegador = await abrirChrome('uox-menus-grupo-', (funcao) => {
    encerrar = funcao;
  });
  const { dt, tmp } = navegador;

  const arquivos = {};
  for (const nome of ['normal', 'folded', 'unfold', 'atual']) {
    arquivos[nome] = path.join(tmp, `${nome}.html`);
    writeFileSync(arquivos[nome], execFileSync(php, [gerador, nome]));
  }
  writeFileSync(path.join(tmp, 'destino.html'), '<!doctype html><title>destino</title><p>navegou</p>');

  const tamanho = (largura) => dt.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: 900, deviceScaleFactor: 1, mobile: false });
  const abrir = async (nome) => {
    const carregou = dt.umaVez('Page.loadEventFired');
    await dt.enviar('Page.navigate', { url: pathToFileURL(arquivos[nome]).href });
    await carregou;
  };
  const avaliar = async (expressao) => {
    const resposta = await dt.enviar('Runtime.evaluate', { expression: expressao, returnByValue: true });
    if (resposta.exceptionDetails) {
      throw new Error(resposta.exceptionDetails.exception?.description || resposta.exceptionDetails.text);
    }
    return resposta.result.value;
  };
  // Estado medido no layout: classes, posição do submenu e página atual.
  const estado = (id) => avaliar(`(() => {
    const li = document.getElementById(${JSON.stringify(id)});
    // Se o clique navegou, o menu não existe mais: o estado diz só a página.
    if (!li) {
      return { pagina: location.pathname.split('/').pop(), aberto: false, linkIgual: false, classes: null, aria: null, emLinha: false, foraDaTela: false, altura: 0 };
    }
    const a = li.querySelector(':scope > a');
    const sub = li.querySelector('.wp-submenu').getBoundingClientRect();
    const caixa = li.getBoundingClientRect();
    return {
      pagina: location.pathname.split('/').pop(),
      aberto: li.classList.contains('wp-menu-open') && li.classList.contains('wp-has-current-submenu') && !li.classList.contains('wp-not-current-submenu'),
      linkIgual: a.className === li.className,
      classes: li.className,
      aria: a.getAttribute('aria-expanded'),
      emLinha: sub.top >= caixa.top && sub.top < caixa.bottom && sub.left >= caixa.left - 1 && sub.left < caixa.left + 2,
      foraDaTela: sub.bottom < 0,
      altura: Math.round(caixa.height),
    };
  })()`);
  // Clique de mouse de verdade no centro do link do menu pai.
  const clicar = async (id) => {
    const ponto = await avaliar(`(() => {
      const r = document.getElementById(${JSON.stringify(id)}).querySelector(':scope > a').getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + Math.min(r.height, 30) / 2 };
    })()`);
    // Navegação detectada pelo evento do Chrome, não por tempo: num runner lento,
    // uma regressão que volte a navegar não passa lendo a página antiga.
    let navegou = false;
    dt.umaVez('Page.frameStartedLoading').then(() => {
      navegou = true;
    });
    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
      await dt.enviar('Input.dispatchMouseEvent', { type, x: ponto.x, y: ponto.y, button: 'left', clickCount: 1 });
    }
    await new Promise((r) => setTimeout(r, 400));
    if (navegou) {
      // Deixa a navegação terminar antes de medir.
      await new Promise((r) => setTimeout(r, 600));
    }
    return navegou;
  };

  // Largura normal: abre em linha, fecha, sem navegar.
  await tamanho(1280);
  await abrir('normal');
  for (const id of GRUPOS) {
    const antes = await estado(id);
    checar(!antes.aberto && antes.foraDaTela, `${id}: começa fechado, submenu fora da tela`, antes);

    const navegou1 = await clicar(id);
    const aberto = await estado(id);
    checar(!navegou1 && aberto.pagina === 'normal.html', `${id}: o 1º clique não navega`, aberto.pagina);
    checar(aberto.aberto && aberto.linkIgual && aberto.aria === 'true', `${id}: o 1º clique abre o grupo (li e link)`, aberto);
    checar(aberto.emLinha && aberto.altura > antes.altura + 50, `${id}: o submenu aparece em linha, empurrando o menu`, { antes: antes.altura, depois: aberto });

    const navegou2 = await clicar(id);
    const fechado = await estado(id);
    checar(!navegou2 && fechado.pagina === 'normal.html', `${id}: o 2º clique não navega`, fechado.pagina);
    checar(!fechado.aberto && fechado.linkIgual && fechado.aria === 'false' && fechado.foraDaTela, `${id}: o 2º clique fecha o grupo`, fechado);
    checar(fechado.altura === antes.altura, `${id}: fechado, o menu volta à altura inicial`, { antes: antes.altura, depois: fechado.altura });
  }

  // Abrir um grupo não mexe no outro.
  await clicar(GRUPOS[0]);
  const outro = await estado(GRUPOS[1]);
  checar(!outro.aberto && outro.foraDaTela, 'abrir Seções do Site não abre Políticas e LGPD', outro);

  // Grupo que já começa aberto (tela atual é um item dele): o 1º clique fecha.
  await abrir('atual');
  const atualAntes = await estado(GRUPOS[0]);
  checar(atualAntes.aberto && atualAntes.emLinha, 'atual: o grupo da tela atual começa aberto, em linha', atualAntes);
  const navegouAtual1 = await clicar(GRUPOS[0]);
  const atualFechado = await estado(GRUPOS[0]);
  checar(!navegouAtual1 && !atualFechado.aberto && atualFechado.foraDaTela && atualFechado.aria === 'false', 'atual: o 1º clique fecha o grupo, sem navegar', atualFechado);
  const navegouAtual2 = await clicar(GRUPOS[0]);
  const atualReaberto = await estado(GRUPOS[0]);
  checar(!navegouAtual2 && atualReaberto.aberto && atualReaberto.emLinha, 'atual: o 2º clique reabre, sem navegar', atualReaberto);

  // Recolhido, recolhido automático e estreito: não navega nem muda classes.
  // "estreito sem auto-fold" é o usuário com o menu fixado aberto (unfold): só a
  // guarda de 782px segura o clique.
  for (const [nome, arquivo, largura] of [['folded 1280px', 'folded', 1280], ['auto-fold 900px', 'normal', 900], ['estreito 400px', 'normal', 400], ['estreito sem auto-fold 400px', 'unfold', 400]]) {
    await tamanho(largura);
    await abrir(arquivo);
    for (const id of GRUPOS) {
      const antes = await estado(id);
      const navegou = await clicar(id);
      const depois = await estado(id);
      checar(!navegou && depois.pagina === `${arquivo}.html`, `${nome}, ${id}: o clique não navega`, depois.pagina);
      checar(depois.classes === antes.classes && depois.aria === antes.aria, `${nome}, ${id}: o clique não muda classes`, { antes: antes.classes, depois: depois.classes });
      checar(depois.foraDaTela, `${nome}, ${id}: o submenu segue fora da tela (flyout do núcleo)`, depois);
    }
  }

  // Controle: o que não é grupo navega. Sem isto, "não navegou" não provaria nada.
  await tamanho(1280);
  await abrir('normal');
  const carregou = dt.umaVez('Page.loadEventFired');
  const navegouControle = await clicar('menu-posts');
  await Promise.race([carregou, new Promise((r) => setTimeout(r, 3000))]);
  const destino = await avaliar('location.pathname.split("/").pop()');
  checar(navegouControle && destino === 'destino.html', 'controle: o clique em Blog (não é grupo) navega', { navegouControle, destino });

  if (falhas === 0) {
    console.log(`PASS: menus de grupo do editor no navegador (${navegador.versao})`);
  }
} catch (erro) {
  falhas += 1;
  console.error(`FAIL: ${erro.stack || erro}`);
} finally {
  clearTimeout(limite);
  if (navegador) {
    await navegador.fechar();
  }
}

process.exit(falhas === 0 ? 0 : 1);
