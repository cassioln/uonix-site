import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const modulePath = new URL('../../mu-plugins/uonix-integrations/39-rastreamento-utm-atribuicao.php', import.meta.url);
const moduleSource = readFileSync(modulePath, 'utf8');
const scriptMatch = moduleSource.match(/<script id="uonix-attribution-tracker">\s*([\s\S]*?)\s*<\/script>/);
const analyticsModulePath = new URL('../../mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php', import.meta.url);
const analyticsModuleSource = readFileSync(analyticsModulePath, 'utf8');
const bridgeMatch = analyticsModuleSource.match(/<script id="uonix-adopt-categories-bridge">\s*([\s\S]*?)\s*<\/script>/);

assert.ok(scriptMatch, 'o módulo contém o runtime client-side de atribuição');
assert.ok(bridgeMatch, 'a ponte AdOpt para categorias existe');

function createStorage() {
  const data = new Map();
  return {
    getItem(key) { return data.has(key) ? data.get(key) : null; },
    setItem(key, value) { data.set(key, String(value)); },
    removeItem(key) { data.delete(key); },
  };
}

function createEnvironment(search, bridgeSource = null) {
  const cookies = new Map();
  const windowListeners = new Map();
  const documentListeners = new Map();
  const inputs = [];

  const form = {
    children: inputs,
    matches(selector) {
      return selector.includes('form.frm-fluent-form');
    },
    querySelector(selector) {
      const match = selector.match(/^input\[name="(.+)"\]$/);
      return match ? inputs.find((input) => input.name === match[1]) ?? null : null;
    },
    appendChild(input) {
      input.parentNode = this;
      inputs.push(input);
    },
  };

  const document = {
    readyState: 'complete',
    querySelectorAll(selector) {
      if (selector === 'form') return [form];
      if (selector === '[data-uonix-attribution-field="1"]') {
        return inputs.filter((input) => input.attributes['data-uonix-attribution-field'] === '1');
      }
      return [];
    },
    createElement() {
      return {
        attributes: {},
        type: '',
        name: '',
        value: '',
        setAttribute(name, value) { this.attributes[name] = String(value); },
        remove() {
          const index = inputs.indexOf(this);
          if (index >= 0) inputs.splice(index, 1);
        },
      };
    },
    addEventListener(type, callback) {
      documentListeners.set(type, callback);
    },
  };

  Object.defineProperty(document, 'cookie', {
    get() {
      return [...cookies.entries()].map(([key, value]) => `${key}=${value}`).join('; ');
    },
    set(value) {
      const [pair, ...parts] = String(value).split(';');
      const separator = pair.indexOf('=');
      const key = pair.slice(0, separator);
      const cookieValue = pair.slice(separator + 1);
      const expired = parts.some((part) => /^\s*Max-Age=0\s*$/i.test(part));
      if (expired) cookies.delete(key);
      else cookies.set(key, cookieValue);
    },
  });

  const window = {
    location: { search, protocol: 'https:' },
    localStorage: createStorage(),
    sessionStorage: createStorage(),
    dataLayer: [],
    CustomEvent: function CustomEvent(type, init = {}) {
      this.type = type;
      this.detail = init.detail;
    },
    addEventListener(type, callback) {
      windowListeners.set(type, callback);
    },
    dispatchEvent(event) {
      const listener = windowListeners.get(event.type);
      if (listener) listener(event);
    },
  };

  const context = {
    window,
    document,
    localStorage: window.localStorage,
    CustomEvent: window.CustomEvent,
    URLSearchParams,
    RegExp,
    Object,
    Array,
    JSON,
    Date,
    String,
    Number,
    console,
  };
  const sandbox = vm.createContext(context);
  if (bridgeSource) {
    vm.runInContext(bridgeSource, sandbox, { filename: 'uonix-adopt-categories-bridge.js' });
  }
  vm.runInContext(scriptMatch[1], sandbox, { filename: 'uonix-attribution-tracker.js' });

  return { cookies, form, inputs, window };
}

const denied = createEnvironment('?utm_source=meta&utm_medium=cpc&utm_campaign=campanha-01&fbclid=fb_test-01');
assert.equal(denied.cookies.size, 0, 'consentimento negado não grava cookies de atribuição');
assert.equal(denied.inputs.length, 0, 'consentimento negado não injeta campos ocultos');
assert.equal(denied.window.dataLayer.length, 0, 'consentimento negado não emite evento de atribuição');

denied.window._adoptMarketingGranted = true;
denied.window.dispatchEvent(new denied.window.CustomEvent('uonix_adopt_consent_updated', { detail: { marketing: true } }));
assert.equal(decodeURIComponent(denied.cookies.get('uonix_attribution_utm_source')), 'meta', 'consentimento marketing grava UTM validada');
assert.equal(decodeURIComponent(denied.cookies.get('uonix_attribution_marketing')), '1', 'consentimento marketing grava marcador server-side');
assert.match(decodeURIComponent(denied.cookies.get('_fbc')), /^fb\.1\.\d+\.fb_test-01$/, 'consentimento marketing cria _fbc canônico');
assert.equal(denied.form.querySelector('input[name="uonix_attribution_utm_campaign"]').value, 'campanha-01', 'consentimento marketing injeta campo seguro no formulário');
assert.equal(denied.window.dataLayer.filter((item) => item.event === 'uonix_attribution_loaded').length, 1, 'consentimento marketing emite exatamente um evento de atribuição');

denied.window.dispatchEvent(new denied.window.CustomEvent('uonix_adopt_consent_updated', { detail: { marketing: false } }));
assert.equal(denied.cookies.has('uonix_attribution_utm_source'), false, 'revogação remove cookie de atribuição');
assert.equal(denied.cookies.has('_fbc'), false, 'revogação remove _fbc criado pelo módulo');
assert.equal(denied.inputs.length, 0, 'revogação remove campos ocultos injetados');

const invalid = createEnvironment('?utm_source=%3Cscript%3Ealert(1)%3C%2Fscript%3E&fbclid=' + 'a'.repeat(256));
invalid.window._adoptMarketingGranted = true;
invalid.window.dispatchEvent(new invalid.window.CustomEvent('uonix_adopt_consent_updated', { detail: { marketing: true } }));
assert.equal(invalid.cookies.size, 0, 'valores inválidos ou longos não são persistidos após consentimento');

const bridged = createEnvironment('?utm_source=meta&utm_medium=cpc&fbclid=fb_bridge-01', bridgeMatch[1]);
bridged.window.dataLayer.push({ event: 'adopt-accept-marketing' });
assert.equal(decodeURIComponent(bridged.cookies.get('uonix_attribution_utm_source')), 'meta', 'evento real de aceitação AdOpt persiste atribuição após consentimento');
assert.equal(bridged.window.dataLayer.filter((item) => item.event === 'uonix_attribution_loaded').length, 1, 'ponte AdOpt aciona uma única carga de atribuição');
bridged.window.dataLayer.push({ event: 'adopt-reject-all' });
assert.equal(bridged.cookies.has('uonix_attribution_utm_source'), false, 'evento real de revogação AdOpt remove atribuição persistida');

const bridgeWithoutCustomEvent = bridgeMatch[1].replace(
  /if \(typeof window\.CustomEvent === 'function'\) \{\s*window\.dispatchEvent\(new CustomEvent\('uonix_adopt_consent_updated', \{\s*detail: \{ marketing: marketingGranted, statistics: statisticsGranted \}\s*\}\)\);\s*\}/,
  '',
);
assert.notEqual(bridgeWithoutCustomEvent, bridgeMatch[1], 'mutação remove o único evento que comunica consentimento ao tracker');
const unbridged = createEnvironment('?utm_source=meta&utm_medium=cpc', bridgeWithoutCustomEvent);
unbridged.window.dataLayer.push({ event: 'adopt-accept-marketing' });
assert.equal(unbridged.cookies.size, 0, 'mutação sem evento da ponte não persiste atribuição, provando que o teste cobre a integração');

console.log('PASS: atribuição client-side respeita consentimento, ponte AdOpt, revogação e limites.');
