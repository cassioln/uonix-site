/**
 * Teste de Contrato de Acessibilidade (a11y) - Toggle de Filtros do Catálogo
 *
 * Valida o cumprimento dos critérios de acessibilidade exigidos na auditoria:
 * 1. Atribuição de ID estável à coluna de filtros (uonix-sidebar-filtros).
 * 2. Associação dos botões de recolher e expandir via aria-controls com o ID da coluna.
 * 3. Gerenciamento e sincronização de aria-expanded em ambos os botões:
 *    - "true" quando os filtros estão visíveis / expandidos.
 *    - "false" quando os filtros estão recolhidos / colapsados.
 * 4. Refletir o estado acessível após:
 *    - Inicialização padrão.
 *    - Restauração de preferência salva no localStorage.
 *    - Disparo de clique nos botões de recolher e expandir.
 *    - Redimensionamento de tela (resize entre desktop e mobile).
 * 5. SVGs decorativos com aria-hidden="true" para não poluir leitores de tela.
 */

const fs = require('fs');
const path = require('path');

const snippetPath = path.join(__dirname, '../../themes/kadence-child/snippets/05-catalogo-filtros-husky-mobile.php');
const snippetSource = fs.readFileSync(snippetPath, 'utf8');

let assertions = 0;
function assert(condition, message) {
  assertions++;
  if (!condition) {
    console.error('FAIL: ' + message);
    process.exit(1);
  }
}

// -----------------------------------------------------------------------------
// FASE 1: Verificação Estática de Marcação e Contratos de Código
// -----------------------------------------------------------------------------

// 1.1 ID estável definido e referenciado
assert(
  snippetSource.includes("var UONIX_FILTER_SIDEBAR_ID = 'uonix-sidebar-filtros'") ||
  snippetSource.includes("uonix-sidebar-filtros"),
  'O ID estável "uonix-sidebar-filtros" deve estar definido no script de filtros'
);

// 1.2 aria-controls presente na marcação injetada do botão de recolher
assert(
  snippetSource.includes('aria-controls="\' + filterColId + \'"') ||
  snippetSource.includes('aria-controls="uonix-sidebar-filtros"'),
  'Os botões de toggle devem conter o atributo aria-controls associado ao ID estável da coluna de filtros'
);

// 1.3 aria-expanded e aria-label presentes no botão de recolher
assert(
  /class="[^"]*uonix-btn-collapse[^"]*"[^>]*aria-expanded="true"/i.test(snippetSource) ||
  snippetSource.includes('aria-expanded="true"'),
  'O botão de recolher deve conter aria-expanded="true" na marcação inicial'
);

assert(
  snippetSource.includes('aria-label="Ocultar barra de filtros"'),
  'O botão de recolher deve conter aria-label="Ocultar barra de filtros"'
);

// 1.4 aria-expanded e aria-label presentes no botão de expandir
assert(
  snippetSource.includes('aria-label="Exibir filtros laterais"'),
  'O botão de expandir deve conter aria-label="Exibir filtros laterais"'
);

// 1.5 SVGs decorativos com aria-hidden="true"
assert(
  snippetSource.includes('stroke-linejoin="round" aria-hidden="true"'),
  'Ícones SVG decorativos dos botões de filtro devem ter aria-hidden="true"'
);

// 1.6 Função canônica syncFilterToggleState definida e atualizando aria-expanded
assert(
  snippetSource.includes('function syncFilterToggleState'),
  'A função syncFilterToggleState deve estar declarada no script'
);

assert(
  snippetSource.includes("attr('aria-expanded', isCollapsed ? 'false' : 'true')") ||
  snippetSource.includes('attr("aria-expanded", isCollapsed ? "false" : "true")'),
  'syncFilterToggleState deve alternar aria-expanded entre "false" e "true" conforme isCollapsed'
);

// -----------------------------------------------------------------------------
// FASE 2: Simulação de Execução e Ciclo de Vida do DOM
// -----------------------------------------------------------------------------

function extrairScriptDeFiltros() {
  const matches = [...snippetSource.matchAll(/<script id="uonix-husky-logic-js">([\s\S]*?)<\/script>/g)];
  if (!matches.length) {
    console.error('FAIL: Script <script id="uonix-husky-logic-js"> não encontrado em ' + snippetPath);
    process.exit(1);
  }
  return matches[0][1];
}

const scriptCode = extrairScriptDeFiltros();

/**
 * Cria um ambiente de teste com DOM e jQuery simulados para testar o comportamento real.
 */
function criarAmbienteTeste({ initialWidth = 1200, localStorageValue = null } = {}) {
  const storage = {};
  if (localStorageValue !== null) {
    storage['uonix_catalog_filters_collapsed'] = String(localStorageValue);
  }

  const localStorageMock = {
    getItem(k) { return Object.prototype.hasOwnProperty.call(storage, k) ? storage[k] : null; },
    setItem(k, v) { storage[k] = String(v); },
    removeItem(k) { delete storage[k]; }
  };

  const filterCol = {
    _tag: 'div',
    _classes: new Set(['kadence-column7150_89634e-21']),
    _attrs: {},
    _children: [],
    innerCol: {
      _tag: 'div',
      _classes: new Set(['kt-inside-inner-col']),
      _attrs: {},
      _children: []
    }
  };
  filterCol._children.push(filterCol.innerCol);

  const productsCol = {
    _tag: 'div',
    _classes: new Set(['kadence-column7150_82068b-b2']),
    _attrs: {},
    _children: [],
    ajaxWrap: {
      _tag: 'div',
      _classes: new Set(['woof_results_by_ajax_shortcode']),
      _attrs: {},
      _children: []
    },
    innerCol: {
      _tag: 'div',
      _classes: new Set(['kt-inside-inner-col']),
      _attrs: {},
      _children: []
    }
  };
  productsCol._children.push(productsCol.innerCol);
  productsCol.innerCol._children.push(productsCol.ajaxWrap);

  const catalog = {
    _tag: 'div',
    _id: 'catalogo-produtos',
    _classes: new Set(),
    _attrs: {},
    _children: [filterCol, productsCol]
  };

  const documentListeners = {};
  const windowListeners = {};

  let currentWidth = initialWidth;

  const windowMock = {
    get innerWidth() { return currentWidth; },
    set innerWidth(val) { currentWidth = val; },
    on(evt, handler) {
      if (!windowListeners[evt]) windowListeners[evt] = [];
      windowListeners[evt].push(handler);
    }
  };

  function parseHtmlToElement(html) {
    const innerTags = [...html.matchAll(/<([a-zA-Z0-9]+)([^>]*)>/g)];
    if (!innerTags.length) return null;

    function criarNodo(tag, attrsStr) {
      const el = {
        _tag: tag.toLowerCase(),
        _classes: new Set(),
        _attrs: {},
        _children: []
      };
      const classMatch = attrsStr.match(/class="([^"]+)"/i);
      if (classMatch) {
        classMatch[1].split(/\s+/).forEach(c => c && el._classes.add(c));
      }
      const idMatch = attrsStr.match(/id="([^"]+)"/i);
      if (idMatch) el._id = idMatch[1];
      const ariaMatches = [...attrsStr.matchAll(/(aria-[a-z]+)="([^"]*)"/gi)];
      for (const m of ariaMatches) {
        el._attrs[m[1].toLowerCase()] = m[2];
      }
      return el;
    }

    const root = criarNodo(innerTags[0][1], innerTags[0][2]);
    for (let i = 1; i < innerTags.length; i++) {
      const t = innerTags[i];
      if (!t[1].startsWith("/")) {
        const child = criarNodo(t[1], t[2]);
        root._children.push(child);
      }
    }
    return root;
  }

  function coletarDescendentes(nodo) {
    const list = [nodo];
    if (nodo._children && nodo._children.length) {
      for (const filho of nodo._children) {
        list.push(...coletarDescendentes(filho));
      }
    }
    return list;
  }

  function casarSeletor(el, sel) {
    sel = sel.trim();
    if (sel.startsWith('#')) {
      return el._id === sel.slice(1);
    }
    if (sel.startsWith('.')) {
      return el._classes && el._classes.has(sel.slice(1));
    }
    return false;
  }

  function encontrarPorSeletor(raiz, seletor) {
    const todos = coletarDescendentes(raiz);
    const partes = seletor.split(',').map(s => s.trim());
    return todos.filter(el => partes.some(p => casarSeletor(el, p)));
  }

  function jQueryMock(target) {
    if (target === 'body') {
      return { length: 1, is: () => true };
    }
    if (target === windowMock) {
      return windowMock;
    }
    if (target === 'document' || target === catalogDocument) {
      return {
        ready(fn) { fn(); },
        on(evt, subSel, handler) {
          if (!documentListeners[evt]) documentListeners[evt] = [];
          documentListeners[evt].push({ subSel, handler });
        }
      };
    }

    let elementos = [];
    if (typeof target === 'string') {
      elementos = encontrarPorSeletor(catalog, target);
    } else if (target && target._tag) {
      elementos = [target];
    } else if (Array.isArray(target)) {
      elementos = target;
    }

    const wrapper = {
      length: elementos.length,
      attr(name, val) {
        if (arguments.length === 1) {
          if (!elementos.length) return undefined;
          if (name === 'id') return elementos[0]._id;
          return elementos[0]._attrs[name];
        }
        for (const el of elementos) {
          if (name === 'id') {
            el._id = val;
          } else {
            el._attrs[name] = String(val);
          }
        }
        return wrapper;
      },
      addClass(cls) {
        for (const el of elementos) el._classes.add(cls);
        return wrapper;
      },
      removeClass(cls) {
        for (const el of elementos) el._classes.delete(cls);
        return wrapper;
      },
      hasClass(cls) {
        return elementos.some(el => el._classes.has(cls));
      },
      find(sel) {
        const encontrados = [];
        for (const el of elementos) {
          encontrados.push(...encontrarPorSeletor(el, sel));
        }
        return jQueryMock(encontrados);
      },
      parent() {
        return { is: () => false };
      },
      prepend(html) {
        const novo = parseHtmlToElement(html);
        if (elementos[0] && novo) {
          elementos[0]._children.unshift(novo);
        }
        return wrapper;
      },
      before(html) {
        const novo = parseHtmlToElement(html);
        if (productsCol.innerCol && novo) {
          productsCol.innerCol._children.unshift(novo);
        }
        return wrapper;
      },
      trigger(evt) {
        return wrapper;
      }
    };

    return wrapper;
  }

  const catalogDocument = {
    addEventListener: () => {}
  };

  const sandbox = {
    jQuery: jQueryMock,
    $: jQueryMock,
    window: windowMock,
    document: catalogDocument,
    localStorage: localStorageMock,
    setTimeout: (fn) => fn(),
    woof_is_mobile: 0
  };

  const executor = new Function(
    'jQuery', '$', 'window', 'document', 'localStorage', 'setTimeout', 'woof_is_mobile',
    scriptCode
  );

  executor(
    sandbox.jQuery,
    sandbox.$,
    sandbox.window,
    sandbox.document,
    sandbox.localStorage,
    sandbox.setTimeout,
    sandbox.woof_is_mobile
  );

  return {
    catalog,
    filterCol,
    productsCol,
    storage,
    windowMock,
    documentListeners,
    windowListeners,
    jQueryMock
  };
}

// -----------------------------------------------------------------------------
// FASE 3: Testes de Integração de Estado e Acessibilidade
// -----------------------------------------------------------------------------

console.log('Iniciando validação de acessibilidade e sincronização do filtro...');

// Cenário 1: Inicialização padrão em desktop (painel expandido)
{
  const env = criarAmbienteTeste({ initialWidth: 1200, localStorageValue: null });
  
  assert(env.filterCol._id === 'uonix-sidebar-filtros', 'Cenário 1: Coluna de filtros deve receber o id estável "uonix-sidebar-filtros"');

  const $collapseBtn = env.jQueryMock('.uonix-btn-collapse');
  const $expandBtn = env.jQueryMock('.uonix-btn-expand');

  assert($collapseBtn.length > 0, 'Cenário 1: Botão de recolher (.uonix-btn-collapse) deve ser injetado');
  assert($expandBtn.length > 0, 'Cenário 1: Botão de expandir (.uonix-btn-expand) deve ser injetado');

  assert($collapseBtn.attr('aria-controls') === 'uonix-sidebar-filtros', 'Cenário 1: .uonix-btn-collapse deve ter aria-controls="uonix-sidebar-filtros"');
  assert($expandBtn.attr('aria-controls') === 'uonix-sidebar-filtros', 'Cenário 1: .uonix-btn-expand deve ter aria-controls="uonix-sidebar-filtros"');

  assert($collapseBtn.attr('aria-expanded') === 'true', 'Cenário 1: .uonix-btn-collapse deve ter aria-expanded="true" quando expandido');
  assert($expandBtn.attr('aria-expanded') === 'true', 'Cenário 1: .uonix-btn-expand deve ter aria-expanded="true" quando expandido');
  assert(!env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 1: Catálogo não deve ter a classe uonix-filters-collapsed');
}

// Cenário 2: Restauração de preferência do localStorage (inicializa colapsado)
{
  const env = criarAmbienteTeste({ initialWidth: 1200, localStorageValue: '1' });
  const $collapseBtn = env.jQueryMock('.uonix-btn-collapse');
  const $expandBtn = env.jQueryMock('.uonix-btn-expand');

  assert(env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 2: Catálogo deve ter uonix-filters-collapsed restaurado do localStorage');
  assert($collapseBtn.attr('aria-expanded') === 'false', 'Cenário 2: .uonix-btn-collapse deve ter aria-expanded="false" restaurado');
  assert($expandBtn.attr('aria-expanded') === 'false', 'Cenário 2: .uonix-btn-expand deve ter aria-expanded="false" restaurado');
}

// Cenário 3: Transição via clique no botão de recolher
{
  const env = criarAmbienteTeste({ initialWidth: 1200, localStorageValue: '0' });
  const clickListeners = env.documentListeners['click'] || [];
  const collapseHandler = clickListeners.find(l => l.subSel === '.uonix-btn-collapse');
  assert(!!collapseHandler, 'Cenário 3: Handler de clique para .uonix-btn-collapse deve estar registrado');

  collapseHandler.handler({ preventDefault: () => {} });

  const $collapseBtn = env.jQueryMock('.uonix-btn-collapse');
  const $expandBtn = env.jQueryMock('.uonix-btn-expand');

  assert(env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 3: Catálogo deve receber uonix-filters-collapsed após clique em recolher');
  assert($collapseBtn.attr('aria-expanded') === 'false', 'Cenário 3: .uonix-btn-collapse deve ter aria-expanded="false" após clique');
  assert($expandBtn.attr('aria-expanded') === 'false', 'Cenário 3: .uonix-btn-expand deve ter aria-expanded="false" após clique');
  assert(env.storage['uonix_catalog_filters_collapsed'] === '1', 'Cenário 3: localStorage deve salvar "1" após recolher');
}

// Cenário 4: Transição via clique no botão de expandir
{
  const env = criarAmbienteTeste({ initialWidth: 1200, localStorageValue: '1' });
  const clickListeners = env.documentListeners['click'] || [];
  const expandHandler = clickListeners.find(l => l.subSel === '.uonix-btn-expand');
  assert(!!expandHandler, 'Cenário 4: Handler de clique para .uonix-btn-expand deve estar registrado');

  expandHandler.handler({ preventDefault: () => {} });

  const $collapseBtn = env.jQueryMock('.uonix-btn-collapse');
  const $expandBtn = env.jQueryMock('.uonix-btn-expand');

  assert(!env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 4: Catálogo deve perder uonix-filters-collapsed após clique em expandir');
  assert($collapseBtn.attr('aria-expanded') === 'true', 'Cenário 4: .uonix-btn-collapse deve ter aria-expanded="true" após expandir');
  assert($expandBtn.attr('aria-expanded') === 'true', 'Cenário 4: .uonix-btn-expand deve ter aria-expanded="true" após expandir');
  assert(env.storage['uonix_catalog_filters_collapsed'] === '0', 'Cenário 4: localStorage deve salvar "0" após expandir');
}

// Cenário 5: Resize para mobile (< 768px) limpa estado colapsado e atualiza semântica
{
  const env = criarAmbienteTeste({ initialWidth: 1200, localStorageValue: '1' });
  assert(env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 5: Inicialmente colapsado no desktop');

  const resizeListeners = env.windowListeners['resize'] || [];
  assert(resizeListeners.length > 0, 'Cenário 5: Handler de resize da janela deve estar registrado');

  env.windowMock.innerWidth = 375;
  resizeListeners[0]();

  const $collapseBtn = env.jQueryMock('.uonix-btn-collapse');
  const $expandBtn = env.jQueryMock('.uonix-btn-expand');

  assert(!env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 5: No mobile (< 768px), o catálogo não deve ficar colapsado');
  assert($collapseBtn.attr('aria-expanded') === 'true', 'Cenário 5: aria-expanded deve refletir estado ativo/não-colapsado no mobile');
  assert($expandBtn.attr('aria-expanded') === 'true', 'Cenário 5: aria-expanded deve refletir estado ativo/não-colapsado no mobile');
}

// Cenário 6: Resize de volta para desktop (>= 768px) restaura preferência salva
{
  const env = criarAmbienteTeste({ initialWidth: 375, localStorageValue: '1' });
  const resizeListeners = env.windowListeners['resize'] || [];

  env.windowMock.innerWidth = 1280;
  resizeListeners[0]();

  const $collapseBtn = env.jQueryMock('.uonix-btn-collapse');
  const $expandBtn = env.jQueryMock('.uonix-btn-expand');

  assert(env.catalog._classes.has('uonix-filters-collapsed'), 'Cenário 6: Ao voltar para desktop, deve restaurar uonix-filters-collapsed do localStorage');
  assert($collapseBtn.attr('aria-expanded') === 'false', 'Cenário 6: aria-expanded deve sincronizar para "false" no desktop');
  assert($expandBtn.attr('aria-expanded') === 'false', 'Cenário 6: aria-expanded deve sincronizar para "false" no desktop');
}

console.log(`PASS: Todos os contratos de acessibilidade (a11y) do toggle de filtros foram validados com sucesso (${assertions} asserções).`);
