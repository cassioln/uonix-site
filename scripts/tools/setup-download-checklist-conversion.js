const { gtmRequest } = require('./gtm-client.js');

async function main() {
  const base = '/accounts/6348960683/containers/248910884';
  const wsList = await gtmRequest('GET', base + '/workspaces');
  const wsId = wsList.workspace[0].workspaceId;
  const ws = base + '/workspaces/' + wsId;
  console.log('Using active workspace:', wsId);

  // 1. Criar Variavel: Constante - Label Download Checklist
  console.log('1. Criando variavel Constante - Label Download Checklist...');
  const varData = {
    name: 'Constante - Label Download Checklist',
    type: 'c',
    parameter: [
      { type: 'template', key: 'value', value: 'nXYDCKKayPQcENifv9pE' }
    ]
  };
  const createdVar = await gtmRequest('POST', ws + '/variables', varData);
  console.log('Variavel criada com ID:', createdVar.variableId);

  // 2. Criar Trigger: Evento - Download Checklist Tecnico
  console.log('2. Criando trigger Evento - Download Checklist Tecnico...');
  const triggerData = {
    name: 'Evento - Download Checklist Técnico',
    type: 'customEvent',
    customEventFilter: [
      {
        type: 'equals',
        parameter: [
          { type: 'template', key: 'arg0', value: '{{_event}}' },
          { type: 'template', key: 'arg1', value: 'uonix_download_checklist' }
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

  // 3. Criar Tag de Conversao Google Ads: Google Ads - Conversao - Download Checklist Tecnico
  console.log('3. Criando tag Google Ads - Conversao - Download Checklist Tecnico...');
  const tagData = {
    name: 'Google Ads - Conversão - Download Checklist Técnico',
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
        value: '{{Constante - Label Download Checklist}}'
      }
    ],
    firingTriggerId: [createdTrigger.triggerId]
  };
  const createdTag = await gtmRequest('POST', ws + '/tags', tagData);
  console.log('Tag de Conversao criada com ID:', createdTag.tagId);

  // 4. Atualizar Tag Listener (Tag 48) para monitorar tambem o form de captura de lead no frontend
  console.log('4. Atualizando tag Listener Custom HTML...');
  const tag48 = await gtmRequest('GET', ws + '/tags/48');
  const listenerHtml = `<script>
(function() {
  var disparadoNews = false;
  var disparadoLead = false;

  function emitirConversaoNewsletter(origem, formId) {
    if (disparadoNews) return;
    disparadoNews = true;
    setTimeout(function() { disparadoNews = false; }, 3000);
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      'event': 'uonix_assinatura_newsletter',
      'origem_conversao': origem || 'newsletter_geral',
      'form_id': formId || null
    });
  }

  function emitirConversaoDownload(origem) {
    if (disparadoLead) return;
    disparadoLead = true;
    setTimeout(function() { disparadoLead = false; }, 3000);
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      'event': 'uonix_download_checklist',
      'origem_conversao': origem || 'download_checklist'
    });
  }

  // 1. Fluent Forms Listener
  function registrarFluentForms() {
    function processarEnvio(form, formId) {
      if (!form && formId) form = document.querySelector('#fluentform_' + formId);
      var idStr = String(formId || '');
      if (idStr === '2') {
        emitirConversaoNewsletter('fluentform_id_2', idStr);
        return;
      }
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

  // 2. Formulario Customizado de Newsletter (Rodape) + Formulario de Captura de Lead (Download Checklist)
  function registrarFormulariosCustomizados() {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
          var target = mutation.target;
          if (target && target.classList) {
            // Newsletter rodape
            if (target.classList.contains('uonix-success-wrapper') && window.getComputedStyle(target).display !== 'none') {
              emitirConversaoNewsletter('form_newsletter_customizado');
            }
            // Captura de lead download checklist
            if (target.id === 'ucf_success_state' && window.getComputedStyle(target).display !== 'none') {
              emitirConversaoDownload('form_captura_lead');
              var leadForm = document.getElementById('ucf_lead_form');
              var newsOpt = leadForm ? leadForm.querySelector('input[name="newsletters"]') : null;
              if (newsOpt && newsOpt.checked) {
                emitirConversaoNewsletter('form_captura_lead_optin');
              }
            }
          }
        }
      });
    });

    document.querySelectorAll('.uonix-success-wrapper, #ucf_success_state').forEach(function(el) {
      observer.observe(el, { attributes: true, attributeFilter: ['style', 'class'] });
    });

    var bodyObserver = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node.nodeType === 1) {
            if (node.classList && (node.classList.contains('uonix-success-wrapper') || node.id === 'ucf_success_state')) {
              observer.observe(node, { attributes: true, attributeFilter: ['style', 'class'] });
            } else {
              var targets = node.querySelectorAll && node.querySelectorAll('.uonix-success-wrapper, #ucf_success_state');
              if (targets) {
                targets.forEach(function(t) {
                  observer.observe(t, { attributes: true, attributeFilter: ['style', 'class'] });
                });
              }
            }
          }
        });
      });
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });
  }

  // 3. WooCommerce Checkout
  function registrarWooCommerce() {
    if (window.location.pathname.indexOf('finalizar-orcamento') !== -1 &&
        (window.location.pathname.indexOf('solicitacao-recebida') !== -1 || window.location.pathname.indexOf('order-received') !== -1)) {
      try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('news') === '1' || sessionStorage.getItem('uonix_billing_newsletters') === '1') {
          sessionStorage.removeItem('uonix_billing_newsletters');
          emitirConversaoNewsletter('woocommerce_checkout_newsletter');
        }
      } catch(err) {}
    } else {
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
      registrarFormulariosCustomizados();
      registrarWooCommerce();
    });
  } else {
    registrarFluentForms();
    registrarFormulariosCustomizados();
    registrarWooCommerce();
  }
})();
</script>`;

  tag48.name = 'Custom HTML - Listener Conversoes Customizadas';
  tag48.parameter = [
    { type: 'template', key: 'html', value: listenerHtml }
  ];
  await gtmRequest('PUT', ws + '/tags/48', tag48);
  console.log('Tag 48 atualizada com sucesso!');

  // 5. Criar e Publicar Versao 28
  console.log('5. Criando versao 28 no GTM...');
  const resVer = await gtmRequest('POST', ws + ':create_version', {
    name: 'v28 - Conversao Download Checklist Tecnico (Google Ads Secundária)',
    notes: 'Configura acao secundaria de conversao Download Checklist Tecnico (awct) para formulario de captura com material rico / download e opt-in de newsletter, com respeito a LGPD/AdOpt.'
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
