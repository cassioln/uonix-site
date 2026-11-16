// Navegador real, sessão descartável e somente a origem local autorizada.
import fs from 'node:fs';
import path from 'node:path';
import { abrirChrome } from '../tests/fixtures/lib-chrome-devtools.mjs';

function aguardar(promessa, signal) {
  signal.throwIfAborted();
  return new Promise((resolver, rejeitar) => {
    const cancelar = () => rejeitar(signal.reason);
    signal.addEventListener('abort', cancelar, { once: true });
    Promise.resolve(promessa).then(resolver, rejeitar).finally(() => signal.removeEventListener('abort', cancelar));
  });
}

export async function executarCheckout(config, { abrir = abrirChrome, limiteMs = 90000 } = {}) {
  const controle = new AbortController();
  let encerrar = () => {};
  const limiteGlobal = setTimeout(() => {
    controle.abort(new Error('tempo global do navegador excedido'));
    encerrar();
  }, limiteMs);
  try {
    return await conduzirCheckout(config, fechar => {
      encerrar = fechar;
      if (controle.signal.aborted) encerrar();
    }, controle, abrir);
  } finally { clearTimeout(limiteGlobal); }
}

async function conduzirCheckout(config, aoIniciar, controle, abrir) {
  const { signal } = controle;
  const abertura = abrir('uonix-jornada-', aoIniciar);
  // Um launcher que resolver depois do cancelamento só pode fechar, nunca navegar.
  abertura.then(chrome => { if (signal.aborted) return chrome.fechar(); }).catch(() => {});
  const chrome = await aguardar(abertura, signal);
  const { dt } = chrome;
  const enviar = (metodo, parametros = {}) => {
    signal.throwIfAborted();
    return aguardar(dt.enviar(metodo, parametros), signal);
  };
  const pausar = ms => aguardar(new Promise(r => setTimeout(r, ms)), signal);
  const bloqueadas = new Set();
  const avaliar = async codigo => {
    const r = await enviar('Runtime.evaluate', { expression: codigo, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('erro de JavaScript na jornada');
    return r.result.value;
  };
  const esperar = async (codigo, limite = 20000) => {
    const inicio = Date.now();
    while (Date.now() - inicio < limite) {
      if (await avaliar(codigo)) return;
      await pausar(200);
    }
    throw new Error('interface não atingiu a condição obrigatória no prazo');
  };
  const navegar = async url => {
    await enviar('Page.navigate', { url });
    await esperar(`document.readyState === 'complete' && location.href.startsWith(${JSON.stringify(url)})`);
  };
  const clicar = async seletor => {
    const ponto = await avaliar(`(() => { const el = document.querySelector(${JSON.stringify(seletor)}); if (!el) return null; el.scrollIntoView({block:'center'}); const r = el.getBoundingClientRect(); return {x:r.x+r.width/2,y:r.y+r.height/2}; })()`);
    if (!ponto) throw new Error(`controle obrigatório ausente: ${seletor}`);
    await enviar('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...ponto });
    await enviar('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...ponto });
  };
  try {
    await enviar('Network.enable');
    await enviar('Network.setCacheDisabled', { cacheDisabled: true });
    await enviar('Network.setExtraHTTPHeaders', { headers: { 'X-Uonix-Jornada': config.run_id } });
    // Fetch antes da primeira navegação: bloqueia analytics/CEP/Turnstile externos.
    let interceptacaoFalhou = false;
    const ouvir = () => dt.umaVez('Fetch.requestPaused').then(async evento => {
      if (signal.aborted) return;
      ouvir(); // rearmar antes de continuar a requisição, sem perder eventos
      const url = new URL(evento.request.url);
      if (url.origin === config.base_url || ['data:', 'blob:'].includes(url.protocol)) {
        await enviar('Fetch.continueRequest', { requestId: evento.requestId });
      } else {
        bloqueadas.add(url.origin); // sem query, cookies ou parâmetros
        await enviar('Fetch.failRequest', { requestId: evento.requestId, errorReason: 'BlockedByClient' });
      }
    }).catch(() => {
      interceptacaoFalhou = true;
      controle.abort(new Error('interceptação de rede incompleta'));
    });
    ouvir();
    await enviar('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    await navegar(config.produto_url);
    await esperar(`Boolean(document.querySelector('form.cart .single_add_to_cart_button'))`);
    await clicar('form.cart .single_add_to_cart_button');
    // A resposta do CTA pode ser navegação ou AJAX; aguardar o botão desbloquear.
    await pausar(1500);
    await navegar(config.checkout_url);
    await esperar(`Boolean(document.querySelector('form.checkout #billing_email'))`);
    const campos = {
      billing_complete_name: config.esperado.nome, billing_phone: config.esperado.telefone,
      billing_email: config.email, billing_cnpj: '11.222.333/0001-81', billing_company: config.esperado.empresa,
      billing_postcode: '01001-000', billing_address_1: 'Rua de Teste', billing_address_3: '123',
      billing_city: 'São Paulo', billing_state: 'SP',
    };
    await avaliar(`(() => {
      const dados = ${JSON.stringify(campos)};
      for (const [nome, valor] of Object.entries(dados)) {
        const el = document.querySelector('[name="' + nome + '"]');
        if (!el) throw new Error('campo obrigatório ausente');
        el.value = valor;
        el.dispatchEvent(new Event('input', {bubbles:true}));
        el.dispatchEvent(new Event('change', {bubbles:true}));
      }
      for (const nome of ['billing_newsletters', 'createaccount']) {
        const el = document.querySelector('[name="' + nome + '"]');
        if (el) { el.checked = false; el.dispatchEvent(new Event('change', {bubbles:true})); }
      }
      const termos = document.querySelector('[name="terms"]');
      if (!termos) throw new Error('termos ausentes');
      termos.checked = true; termos.dispatchEvent(new Event('change', {bubbles:true}));
      return true;
    })()`);
    await pausar(1500);
    await esperar(`!document.querySelector('form.checkout .blockUI') && Boolean(document.querySelector('#place_order'))`);
    await clicar('#place_order');
    await esperar(`location.pathname.includes('order-received') || Boolean(document.querySelector('.woocommerce-order-received'))`, 45000);
    const comprovante = await avaliar(`({caminho: location.pathname, recebido: Boolean(document.querySelector('.woocommerce-order, .woocommerce-order-received'))})`);
    if (interceptacaoFalhou) throw new Error('interceptação de rede incompleta');
    const imagem = await enviar('Page.captureScreenshot', { format: 'png' });
    signal.throwIfAborted();
    fs.writeFileSync(path.join(config.saida, 'cotacao.png'), Buffer.from(imagem.data, 'base64'));
    return { ...comprovante, origens_bloqueadas: [...bloqueadas].sort() };
  } finally {
    await chrome.fechar();
  }
}
