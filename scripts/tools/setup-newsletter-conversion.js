const { gtmRequest } = require('./gtm-client.js');

async function main() {
  const base = '/accounts/6348960683/containers/248910884';
  const wsList = await gtmRequest('GET', base + '/workspaces');
  const wsId = wsList.workspace[0].workspaceId;
  const ws = base + '/workspaces/' + wsId;
  console.log('Using active workspace:', wsId);

  // 1. Criar Variavel: Constante - Label Assinatura Newsletter
  console.log('1. Criando variavel Constante - Label Assinatura Newsletter...');
  const varData = {
    name: 'Constante - Label Assinatura Newsletter',
    type: 'c',
    parameter: [
      { type: 'template', key: 'value', value: 'PFrxCKf1w_QcENifv9pE' }
    ]
  };
  const createdVar = await gtmRequest('POST', ws + '/variables', varData);
  console.log('Variavel criada com ID:', createdVar.variableId);

  // 2. Criar Trigger: Evento - Assinatura Newsletter Uonix
  console.log('2. Criando trigger Evento - Assinatura Newsletter Uonix...');
  const triggerData = {
    name: 'Evento - Assinatura Newsletter Uônix',
    type: 'customEvent',
    customEventFilter: [
      {
        type: 'equals',
        parameter: [
          { type: 'template', key: 'arg0', value: '{{_event}}' },
          { type: 'template', key: 'arg1', value: 'uonix_assinatura_newsletter' }
        ]
      }
    ],
    filter: [
      {
        type: 'contains',
        parameter: [
          { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
          { type: 'template', key: 'arg1', value: 'marketing' }
        ]
      }
    ]
  };
  const createdTrigger = await gtmRequest('POST', ws + '/triggers', triggerData);
  console.log('Trigger criado com ID:', createdTrigger.triggerId);

  // 3. Criar Tag de Conversao Google Ads: Google Ads - Conversão - Assinatura Newsletter
  console.log('3. Criando tag Google Ads - Conversao - Assinatura Newsletter...');
  const tagData = {
    name: 'Google Ads - Conversão - Assinatura Newsletter',
    type: 'awct',
    parameter: [
      {
        type: 'template',
        key: 'conversionId',
        value: '{{Constante - Google Ads ID}}'
      },
      {
        type: 'template',
        key: 'conversionLabel',
        value: '{{Constante - Label Assinatura Newsletter}}'
      }
    ],
    firingTriggerId: [createdTrigger.triggerId]
  };
  const createdTag = await gtmRequest('POST', ws + '/tags', tagData);
  console.log('Tag de Conversao criada com ID:', createdTag.tagId);

  // 4. Criar Tag de Listener Custom HTML para garantir deteccao imediata no frontend
  console.log('4. Criando tag Custom HTML - Listener Assinatura Newsletter...');
  const listenerHtml = `<script>
(function() {
  var disparado = false;
  function emitirConversaoNewsletter(origem, formId) {
    if (disparado) return;
    disparado = true;
    setTimeout(function() { disparado = false; }, 3000);
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      'event': 'uonix_assinatura_newsletter',
      'origem_conversao': origem || 'newsletter_geral',
      'form_id': formId || null
    });
  }

  // 1. Fluent Forms Listener
  function registrarFluentForms() {
    function processarEnvio(form, formId) {
      if (!form && formId) form = document.querySelector('#fluentform_' + formId);
      var idStr = String(formId || '');
      // Se formId for 2 (Assinantes Newsletters)
      if (idStr === '2') {
        emitirConversaoNewsletter('fluentform_id_2', idStr);
        return;
      }
      // Se checkbox de newsletter estiver marcado
      if (form) {
        var newsChecked = form.querySelector('input[name="form_newsletters"]:checked') ||
                           form.querySelector('input[name*="newsletter"]:checked') ||
                           form.querySelector('input[name="form_newsletters"][value="sim"]:checked');
        if (newsChecked) {
          emitirConversaoNewsletter('fluentform_checkbox', idStr);
        }
      }
    }

    if (window.jQuery) {
      window.jQuery(document).on('fluentform_submission_success', function(e, data) {
        try {
          var form = data && (data.form || (data.data && data.data.form));
          var fId = data && (data.formId || data.form_id || (data.data && data.data.formId));
          processarEnvio(form, fId);
        } catch(err) {}
      });
    }

    document.addEventListener('fluentform_submission_success', function(e) {
      try {
        var detail = e && e.detail;
        var form = detail && detail.form;
        var fId = detail && (detail.formId || detail.form_id);
        processarEnvio(form, fId);
      } catch(err) {}
    });
  }

  // 2. Formulario Customizado de Newsletter (Rodape / Shortcode [uonix_form_newsletter])
  function registrarFormularioCustomizado() {
    // Interceptar submissoes bem-sucedidas via fetch ou MutationObserver no wrapper de sucesso
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
          var target = mutation.target;
          if (target && target.classList && target.classList.contains('uonix-success-wrapper')) {
            if (window.getComputedStyle(target).display !== 'none') {
              emitirConversaoNewsletter('form_newsletter_customizado');
            }
          }
        }
      });
    });

    document.querySelectorAll('.uonix-success-wrapper').forEach(function(el) {
      observer.observe(el, { attributes: true, attributeFilter: ['style', 'class'] });
    });

    // Observer para novos elementos caso o rodape carregue dinamicamente
    var bodyObserver = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node.nodeType === 1) {
            if (node.classList && node.classList.contains('uonix-success-wrapper')) {
              observer.observe(node, { attributes: true, attributeFilter: ['style', 'class'] });
            } else {
              var wrappers = node.querySelectorAll && node.querySelectorAll('.uonix-success-wrapper');
              if (wrappers) {
                wrappers.forEach(function(w) {
                  observer.observe(w, { attributes: true, attributeFilter: ['style', 'class'] });
                });
              }
            }
          }
        });
      });
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });
  }

  // 3. WooCommerce Checkout (se o checkbox billing_newsletters estiver marcado na finalizacao)
  function registrarWooCommerce() {
    if (window.location.pathname.indexOf('finalizar-orcamento') !== -1 &&
        (window.location.pathname.indexOf('solicitacao-recebida') !== -1 || window.location.pathname.indexOf('order-received') !== -1)) {
      // Se houver indicador ou parametro
      try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('news') === '1' || sessionStorage.getItem('uonix_billing_newsletters') === '1') {
          sessionStorage.removeItem('uonix_billing_newsletters');
          emitirConversaoNewsletter('woocommerce_checkout_newsletter');
        }
      } catch(err) {}
    } else {
      // No checkout, salvar na sessao se o usuario marcar
      document.addEventListener('change', function(e) {
        if (e.target && (e.target.id === 'billing_newsletters' || e.target.name === 'billing_newsletters')) {
          if (e.target.checked) {
            sessionStorage.setItem('uonix_billing_newsletters', '1');
          } else {
            sessionStorage.removeItem('uonix_billing_newsletters');
          }
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      registrarFluentForms();
      registrarFormularioCustomizado();
      registrarWooCommerce();
    });
  } else {
    registrarFluentForms();
    registrarFormularioCustomizado();
    registrarWooCommerce();
  }
})();
</script>`;

  // Acionador All Pages (ID padrao no GTM e 2147479553)
  const listenerTagData = {
    name: 'Custom HTML - Listener Assinatura Newsletter',
    type: 'html',
    parameter: [
      { type: 'template', key: 'html', value: listenerHtml }
    ],
    firingTriggerId: ['2147479553']
  };
  const createdListenerTag = await gtmRequest('POST', ws + '/tags', listenerTagData);
  console.log('Tag Listener criada com ID:', createdListenerTag.tagId);

  // 5. Criar e Publicar Versao 27
  console.log('5. Criando versao no GTM...');
  const resVer = await gtmRequest('POST', ws + ':create_version', {
    name: 'v27 - Conversao Assinatura Newsletter (Google Ads Secundária)',
    notes: 'Configura acao secundaria de conversao Assinatura de Newsletter (awct) para Fluent Forms ID 2, checkbox de newsletter e formulario customizado do rodape, com respeito a LGPD/AdOpt.'
  });
  const versionId = resVer.containerVersion?.containerVersionId;
  console.log('Versao criada:', versionId);

  console.log('Publicando versao live...');
  await gtmRequest('POST', base + '/versions/' + versionId + ':publish');
  console.log(`Versao ${versionId} publicada com sucesso no GTM!`);
}

main().catch(err => {
  console.error('ERRO:', err);
  process.exit(1);
});
