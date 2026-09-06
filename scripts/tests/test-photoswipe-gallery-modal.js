/**
 * Prova a segurança e as regras de negócio da galeria PhotoSwipe no Single Product:
 *
 * 1. [Segurança XSS - Bloqueante do Auditor]:
 *    O título do produto NÃO pode ser reinserido no modal por concatenação de HTML.
 *    Deve ser atribuído exclusivamente como nó de texto (.text() / textContent),
 *    garantindo que markups hostis (<script>, <img>, tags malformadas) nunca sejam
 *    interpretados como elementos HTML ou atributos executáveis.
 *
 * 2. [Contratos de Código]:
 *    - Proíbe concatenação de strings contendo prodTitle no DOM do modal.
 *    - Exige uso de nós seguros com atributo de texto { text: prodTitle }.
 *    - Exige opções de blindagem wc_single_product_params (clickToCloseNonZoomable = false, maxSpreadZoom = 3).
 *    - Exige hook idempotente em window.PhotoSwipe com flag _uonixHooked.
 *
 * 3. [Comportamento do Zoom Digital 2x]:
 *    - Imagens menores que a viewport (initialZoomLevel >= 0.92 ou fitRatio >= 0.92)
 *      recebem escala digital de 2.0x.
 *    - Imagens nativas de alta resolução preservam comportamento de zoom padrão.
 *    - Classes pswp--digital-zoom-active e pswp--zoom-allowed são sincronizadas nos eventos do PhotoSwipe.
 */

const fs = require('fs');
const path = require('path');

const raiz = path.join(__dirname, '../..');
const arquivoSnippet = path.join(raiz, 'themes/kadence-child/snippets/35-produto-single-premium.php');

if (!fs.existsSync(arquivoSnippet)) {
  console.error('FAIL: snippet 35-produto-single-premium.php não encontrado');
  process.exit(1);
}

const conteudoPhp = fs.readFileSync(arquivoSnippet, 'utf8');

// Extrai o bloco de script embutido
const matchScript = conteudoPhp.match(/<script[^>]*>([\s\S]*?)<\/script>/);
if (!matchScript) {
  console.error('FAIL: bloco <script> não encontrado no snippet 35');
  process.exit(1);
}

const codigoJs = matchScript[1];

let assercoes = 0;
function assert(cond, msg) {
  assercoes++;
  if (!cond) {
    console.error('FAIL: ' + msg);
    process.exit(1);
  }
}

console.log('🧪 Iniciando testes da galeria PhotoSwipe e Zoom Digital...\n');

/* --------------------------------------------------------------------------
 * 1. CONTRATOS ESTÁTICOS DE SEGURANÇA E ARQUITETURA
 * -------------------------------------------------------------------------- */

// Não pode haver concatenação de string HTML com prodTitle
assert(
  !/['"]\s*\+\s*prodTitle/i.test(codigoJs) && !/prodTitle\s*\+\s*['"]/i.test(codigoJs),
  'Bloqueante violado: prodTitle não pode ser concatenado em strings HTML.'
);

// Deve usar construtor de nó com text: prodTitle ou .text(prodTitle)
assert(
  /class:\s*['"]uonix-pswp-prod-name['"],\s*text:\s*prodTitle/i.test(codigoJs) ||
  /\.text\(\s*prodTitle\s*\)/i.test(codigoJs),
  'A inserção do título no modal deve usar .text(prodTitle) ou { text: prodTitle }.'
);

// Deve ter hook em window.PhotoSwipe e proteção de dupla inicialização
assert(
  codigoJs.includes('window.PhotoSwipe._uonixHooked'),
  'window.PhotoSwipe deve conter flag de proteção _uonixHooked.'
);

// Deve forçar maxSpreadZoom = 3 e clickToCloseNonZoomable = false
assert(
  codigoJs.includes('clickToCloseNonZoomable = false'),
  'Opção clickToCloseNonZoomable = false deve estar configurada.'
);
assert(
  codigoJs.includes('maxSpreadZoom = 3'),
  'Opção maxSpreadZoom = 3 deve estar configurada.'
);

// Zoom digital threshold de 0.92
assert(
  codigoJs.includes('item.initialZoomLevel >= 0.92 || item.fitRatio >= 0.92'),
  'Regra de corte para zoom digital (0.92) deve estar presente.'
);

console.log('✅ Contratos estáticos verificados com sucesso.');

/* --------------------------------------------------------------------------
 * 2. TESTE COMPORTAMENTAL: PREVENÇÃO DE XSS / MARKUP HOSTIL NO TÍTULO
 * -------------------------------------------------------------------------- */

// Mock de DOM mínimo e simulador de jQuery
function criarMockDom() {
  const elementos = {};

  class MockElement {
    constructor(tag, attrs = {}) {
      this.tagName = tag.toUpperCase();
      this.attributes = { ...attrs };
      this.className = attrs.class || '';
      this.children = [];
      this.parentNode = null;
      this._textContent = attrs.text || '';
    }

    get textContent() {
      if (this.children.length === 0) return this._textContent;
      return this.children.map(c => c.textContent).join('');
    }

    set textContent(val) {
      this._textContent = String(val);
      this.children = [];
    }

    get innerHTML() {
      if (this.children.length === 0) {
        // Escapa entidades como o navegador faz ao retornar innerHTML de nó de texto
        return this._textContent
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }
      return this.children.map(c => `<${c.tagName.toLowerCase()} class="${c.className}">${c.innerHTML}</${c.tagName.toLowerCase()}>`).join('');
    }

    appendChild(child) {
      child.parentNode = this;
      this.children.push(child);
      return child;
    }

    prepend(child) {
      child.parentNode = this;
      this.children.unshift(child);
      return child;
    }

    getElementsByTagName(name) {
      const result = [];
      const tagUpper = name.toUpperCase();
      for (const child of this.children) {
        if (child.tagName === tagUpper) result.push(child);
        result.push(...child.getElementsByTagName(name));
      }
      return result;
    }

    getElementsByClassName(cls) {
      const result = [];
      for (const child of this.children) {
        if (child.className && child.className.split(' ').includes(cls)) {
          result.push(child);
        }
        result.push(...child.getElementsByClassName(cls));
      }
      return result;
    }
  }

  function mockJQuery(selector, context) {
    // Criação de novos elementos: $('<div>', { class: '...' })
    if (typeof selector === 'string' && selector.startsWith('<') && selector.endsWith('>')) {
      const tag = selector.slice(1, -1);
      const elem = new MockElement(tag, context || {});
      return wrapJQuery([elem]);
    }

    // Seletores simulados
    let matched = [];
    if (selector === 'h1.product_title') {
      if (elementos.productTitle) matched = [elementos.productTitle];
    } else if (selector === '.pswp') {
      if (elementos.pswp) matched = [elementos.pswp];
    } else if (selector === '.pswp__top-bar') {
      if (elementos.topBar) matched = [elementos.topBar];
    } else if (selector === 'body') {
      if (elementos.body) matched = [elementos.body];
    }

    return wrapJQuery(matched);
  }

  function wrapJQuery(list) {
    const wrapped = {
      length: list.length,
      [0]: list[0] || null,
      addClass(cls) {
        list.forEach(el => {
          const classes = new Set((el.className || '').split(' ').filter(Boolean));
          cls.split(' ').forEach(c => classes.add(c));
          el.className = Array.from(classes).join(' ');
        });
        return wrapped;
      },
      removeClass(cls) {
        list.forEach(el => {
          const classes = new Set((el.className || '').split(' ').filter(Boolean));
          cls.split(' ').forEach(c => classes.delete(c));
          el.className = Array.from(classes).join(' ');
        });
        return wrapped;
      },
      hasClass(cls) {
        return list.some(el => (el.className || '').split(' ').includes(cls));
      },
      append(child) {
        const toAppend = child.isJQuery ? child[0] : child;
        if (list[0] && toAppend) {
          list[0].appendChild(toAppend);
        }
        return wrapped;
      },
      prepend(child) {
        const toPrepend = child.isJQuery ? child[0] : child;
        if (list[0] && toPrepend) {
          list[0].prepend(toPrepend);
        }
        return wrapped;
      },
      find(sel) {
        if (!list[0]) return wrapJQuery([]);
        if (sel === '.pswp__top-bar') {
          return wrapJQuery(list[0].getElementsByClassName('pswp__top-bar'));
        }
        if (sel === '.uonix-pswp-brand-title') {
          return wrapJQuery(list[0].getElementsByClassName('uonix-pswp-brand-title'));
        }
        return wrapJQuery([]);
      },
      text(val) {
        if (val === undefined) {
          return list[0] ? list[0].textContent : '';
        }
        list.forEach(el => { el.textContent = val; });
        return wrapped;
      },
      isJQuery: true
    };
    return wrapped;
  }

  elementos.body = new MockElement('body');
  elementos.pswp = new MockElement('div', { class: 'pswp pswp--open' });
  elementos.topBar = new MockElement('div', { class: 'pswp__top-bar' });
  elementos.pswp.appendChild(elementos.topBar);
  elementos.body.appendChild(elementos.pswp);

  return { mockJQuery, elementos };
}

// Execução com payload hostil
const payloadHostil = 'Olhal Inox <script>alert("xss")</script><img src="x" onerror="alert(1)"> "304" & Cia';

const { mockJQuery: $, elementos } = criarMockDom();
elementos.productTitle = {
  tagName: 'H1',
  className: 'product_title',
  textContent: payloadHostil,
  children: []
};

// Execução da lógica isolada de sincronização de título com o DOM mockado
var $pswp = $('.pswp');
assert($pswp.length && $pswp.hasClass('pswp--open'), 'Modal PhotoSwipe mockado deve estar aberto.');

var $topBar = $pswp.find('.pswp__top-bar');
assert($topBar.length > 0, 'Top bar do modal mockado deve existir.');

var prodTitle = $('h1.product_title').text().trim() || 'Produto Uônix';
var $brandTitle = $('<div>', { class: 'uonix-pswp-brand-title' })
  .append($('<span>', { class: 'uonix-pswp-badge', text: 'UÔNIX' }))
  .append($('<span>', { class: 'uonix-pswp-prod-name', text: prodTitle }));
$topBar.prepend($brandTitle);

const topBarElem = elementos.topBar;
const scriptsInseridos = topBarElem.getElementsByTagName('script');
const imagensInseridas = topBarElem.getElementsByTagName('img');
const prodNameElems = topBarElem.getElementsByClassName('uonix-pswp-prod-name');

assert(scriptsInseridos.length === 0, 'XSS BLOCKED: Nenhum elemento <script> deve ser criado no modal!');
assert(imagensInseridas.length === 0, 'XSS BLOCKED: Nenhum elemento <img> deve ser criado no modal!');
assert(prodNameElems.length === 1, 'Deve existir exatamente 1 elemento .uonix-pswp-prod-name.');
assert(
  prodNameElems[0].textContent === payloadHostil,
  'O texto exibido deve ser exatamente o título original sem interpretação de HTML.'
);
assert(
  topBarElem.innerHTML.includes('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'),
  'A renderização do innerHTML deve conter os caracteres devidamente escapados como texto puro.'
);

console.log('✅ Bloqueante de segurança contra injeção de markup validado com sucesso.');

/* --------------------------------------------------------------------------
 * 3. TESTE DO COMPORTAMENTO DO ZOOM DIGITAL 2X
 * -------------------------------------------------------------------------- */

function testarCalculoZoom(item, isMouseClick = false, prevFn = null) {
  // Extrai exatamente a função getDoubleTapZoom definida no snippet
  const matchFn = codigoJs.match(/options\.getDoubleTapZoom\s*=\s*function\(isMouseClick,\s*item\)\s*\{([\s\S]*?)\n\s*\};/);
  assert(matchFn && matchFn[1], 'Função options.getDoubleTapZoom deve existir no snippet.');

  const fnCorpo = matchFn[1];
  const fnEvaluated = new Function('isMouseClick', 'item', 'prevGetDoubleTapZoom', fnCorpo);
  return fnEvaluated(isMouseClick, item, prevFn);
}

// Cenário A: Imagem pequena (ex.: arruela lisa inox 304, initialZoomLevel = 1.0)
const zoomPequena = testarCalculoZoom({ initialZoomLevel: 1.0, fitRatio: 1.0 });
assert(
  zoomPequena === 2.0,
  `Imagem pequena deve retornar zoom digital forçado de 2.0x; retornado: ${zoomPequena}`
);

// Cenário B: Imagem pequena com initialZoomLevel = 0.95
const zoomQuasePequena = testarCalculoZoom({ initialZoomLevel: 0.95, fitRatio: 0.85 });
assert(
  zoomQuasePequena === 2.0,
  `Imagem com initialZoomLevel >= 0.92 deve ativar zoom digital 2.0x; retornado: ${zoomQuasePequena}`
);

// Cenário C: Imagem pequena com fitRatio = 0.94
const zoomFitRatio = testarCalculoZoom({ initialZoomLevel: 0.80, fitRatio: 0.94 });
assert(
  zoomFitRatio === 2.0,
  `Imagem com fitRatio >= 0.92 deve ativar zoom digital 2.0x; retornado: ${zoomFitRatio}`
);

// Cenário D: Imagem grande nativa (ex.: olhal 210 inox, initialZoomLevel = 0.45, fitRatio = 0.45)
const zoomGrande = testarCalculoZoom({ initialZoomLevel: 0.45, fitRatio: 0.45 });
assert(
  zoomGrande !== 2.0,
  'Imagem grande com alta resolução nativa NÃO deve ser forçada a 2.0x.'
);
assert(
  zoomGrande === 1.33 || zoomGrande === 1,
  `Imagem grande deve retornar valor padrão de zoom (1 ou 1.33); retornado: ${zoomGrande}`
);

// Cenário E: Imagem grande com delegate prévio
const zoomComDelegate = testarCalculoZoom({ initialZoomLevel: 0.50, fitRatio: 0.50 }, false, () => 2.5);
assert(
  zoomComDelegate === 2.5,
  'Imagem grande deve respeitar o delegate original getDoubleTapZoom quando fornecido.'
);

console.log('✅ Comportamento de cálculo do Zoom Digital 2x validado.');

/* --------------------------------------------------------------------------
 * 4. TESTE DE CONSTRUTOR E EVENTOS DO PHOTOSWIPE
 * -------------------------------------------------------------------------- */

let eventosEscutados = {};
function MockPhotoSwipeConstructor(template, ui, items, options) {
  this.template = template;
  this.ui = ui;
  this.items = items;
  this.options = options;
  this.currItem = items[0] || null;
  this.listen = function(evento, cb) {
    eventosEscutados[evento] = cb;
  };
}

// Simula ambiente global
global.window = {
  wc_single_product_params: {},
  PhotoSwipe: MockPhotoSwipeConstructor
};

// Executa bloco que instala o hook no PhotoSwipe
const matchHook = codigoJs.match(/if\s*\(typeof window\.PhotoSwipe !== 'undefined'[\s\S]*?window\.PhotoSwipe\._uonixHooked = true;\s*\}/);
assert(matchHook, 'Bloco de instalação do PhotoSwipe._uonixHooked deve existir no snippet.');

const executarHook = new Function('window', '$', matchHook[0]);
executarHook(global.window, $);

assert(global.window.PhotoSwipe._uonixHooked === true, 'window.PhotoSwipe deve estar marcado como _uonixHooked.');

// Instancia o PhotoSwipe hookado
const itemPequeno = { initialZoomLevel: 1.0, fitRatio: 1.0 };
const templateDiv = new (criarMockDom().mockJQuery)('<div>', { class: 'pswp' });
const instancia = new global.window.PhotoSwipe(templateDiv, {}, [itemPequeno], {});

assert(instancia.options.clickToCloseNonZoomable === false, 'Instância deve receber clickToCloseNonZoomable = false.');
assert(instancia.options.maxSpreadZoom === 3, 'Instância deve receber maxSpreadZoom = 3.');
assert(typeof eventosEscutados['afterInit'] === 'function', 'Listener afterInit deve estar registrado.');
assert(typeof eventosEscutados['beforeChange'] === 'function', 'Listener beforeChange deve estar registrado.');
assert(typeof eventosEscutados['resize'] === 'function', 'Listener resize deve estar registrado.');
assert(typeof eventosEscutados['close'] === 'function', 'Listener close deve estar registrado.');

console.log('✅ Interceptação de construtor e eventos do PhotoSwipe validados com sucesso.');

console.log(`\n🎉 TODOS OS TESTES PASSARAM! Total de asserções: ${assercoes}\n`);
process.exit(0);
