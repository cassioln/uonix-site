import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');
const scriptPath = path.join(
  repositoryRoot,
  'mu-plugins',
  'uonix-woocommerce',
  'assets',
  'js',
  'admin-product-excerpt-editor.js'
);

function createHarness({
  hidden = false,
  tinymceReady = true,
  textareaValue = 'texto digitado no modo Código',
  visualContent = '<p>conteúdo Visual anterior</p>',
} = {}) {
  const calls = [];
  const jqueryHandlers = new Map();
  const nativeHandlers = new Map();
  const timers = [];
  const editorSettings = { selector: '#excerpt', plugins: 'wordpress' };
  const textarea = { nodeName: 'TEXTAREA', value: textareaValue };

  const editor = {
    isHidden: () => hidden,
    save() {
      calls.push('save');
      textarea.value = visualContent;
    },
    remove() {
      calls.push('remove');
      textarea.value = visualContent;
    },
  };

  const document = {
    addEventListener(type, handler, capture) {
      nativeHandlers.set(`${type}:${Boolean(capture)}`, handler);
    },
    getElementById(id) {
      return id === 'excerpt' ? textarea : null;
    },
  };

  function jQuery(target) {
    assert.equal(target, document, 'produção registra eventos jQuery no document');

    return {
      on(eventNames, selector, handler) {
        assert.equal(selector, '.meta-box-sortables');
        for (const eventName of eventNames.split(/\s+/)) {
          jqueryHandlers.set(eventName.split('.')[0], handler);
        }
        return this;
      },
    };
  }

  const tinymce = {
    get(id) {
      assert.equal(id, 'excerpt');
      return editor;
    },
    init(settings) {
      calls.push('init');
      assert.equal(settings, editorSettings);
    },
  };

  const window = {
    document,
    jQuery,
    setTimeout(callback) {
      timers.push(callback);
      return timers.length;
    },
    tinymce: tinymceReady ? tinymce : undefined,
    tinyMCEPreInit: {
      mceInit: {
        excerpt: editorSettings,
      },
    },
  };

  function item(id) {
    return [{ id }];
  }

  function orderButton({ boxId = 'postexcerpt', disabled = false } = {}) {
    const box = { id: boxId };

    return {
      getAttribute(name) {
        return name === 'aria-disabled' ? String(disabled) : null;
      },
      closest(selector) {
        if (selector === '.handle-order-higher, .handle-order-lower') {
          return this;
        }
        if (selector === '#postexcerpt') {
          return boxId === 'postexcerpt' ? box : null;
        }
        return null;
      },
    };
  }

  return {
    calls,
    context: vm.createContext({ window, document, jQuery, console }),
    makeTinymceReady() {
      window.tinymce = tinymce;
    },
    textareaValue() {
      return textarea.value;
    },
    triggerSort(type, id = 'postexcerpt') {
      const handler = jqueryHandlers.get(type);
      assert.equal(typeof handler, 'function', `handler ${type} registrado`);
      handler({ type }, { item: item(id) });
    },
    triggerOrderClick(options) {
      const handler = nativeHandlers.get('click:true');
      assert.equal(typeof handler, 'function', 'click de captura registrado');
      handler({ target: orderButton(options) });
    },
    flushTimers() {
      while (timers.length) {
        timers.shift()();
      }
    },
  };
}

async function loadProduction(harness) {
  const source = await readFile(scriptPath, 'utf8');
  vm.runInContext(source, harness.context, { filename: scriptPath });
}

test('registra handlers antes do TinyMCE e o resolve somente no movimento', async () => {
  const harness = createHarness({ hidden: false, tinymceReady: false });
  await loadProduction(harness);

  harness.makeTinymceReady();
  harness.triggerSort('sortstart');
  harness.triggerSort('sortstop');

  assert.deepEqual(harness.calls, ['save', 'remove', 'init']);
});

test('arraste salva e remove o Visual antes de reinicializar depois do movimento', async () => {
  const harness = createHarness({ hidden: false });
  await loadProduction(harness);

  harness.triggerSort('sortstart');
  assert.deepEqual(harness.calls, ['save', 'remove']);

  harness.calls.push('move');
  harness.triggerSort('sortstop');
  assert.deepEqual(harness.calls, ['save', 'remove', 'move', 'init']);
});

test('modo Código remove iframe sem sobrescrever textarea nem reinicializar', async () => {
  const codeContent = '<strong>alteração feita no modo Código</strong>';
  const harness = createHarness({ hidden: true, textareaValue: codeContent });
  await loadProduction(harness);

  harness.triggerSort('sortstart');
  harness.triggerSort('sortstop');

  assert.deepEqual(harness.calls, ['remove']);
  assert.equal(harness.textareaValue(), codeContent);
});

test('setas de ordem preparam no capture e restauram depois do handler do core', async () => {
  const harness = createHarness({ hidden: false });
  await loadProduction(harness);

  harness.triggerOrderClick();
  assert.deepEqual(harness.calls, ['save', 'remove']);

  harness.calls.push('core-move');
  harness.flushTimers();
  assert.deepEqual(harness.calls, ['save', 'remove', 'core-move', 'init']);
});

test('outras metaboxes e seta desabilitada não alteram o editor', async () => {
  const harness = createHarness({ hidden: false });
  await loadProduction(harness);

  harness.triggerSort('sortstart', 'submitdiv');
  harness.triggerSort('sortstop', 'submitdiv');
  harness.triggerOrderClick({ boxId: 'submitdiv' });
  harness.triggerOrderClick({ disabled: true });
  harness.flushTimers();

  assert.deepEqual(harness.calls, []);
});
