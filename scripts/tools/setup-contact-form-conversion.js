const { gtmRequest } = require('./gtm-client.js');

async function main() {
  const base = '/accounts/6348960683/containers/248910884';
  const wsList = await gtmRequest('GET', base + '/workspaces');
  const wsId = wsList.workspace[0].workspaceId;
  const ws = base + '/workspaces/' + wsId;
  console.log('Using active workspace:', wsId);

  // 1. Criar/Verificar Variavel: Constante - Label Contato Formulario
  console.log('1. Verificando/Criando variavel Constante - Label Contato Formulario...');
  const existingVars = (await gtmRequest('GET', ws + '/variables')).variable || [];
  let createdVar = existingVars.find(v => v.name === 'Constante - Label Contato Formulario');
  if (!createdVar) {
    const varData = {
      name: 'Constante - Label Contato Formulario',
      type: 'c',
      parameter: [
        { type: 'template', key: 'value', value: 'RVZmCKznyfQcENifv9pE' }
      ]
    };
    createdVar = await gtmRequest('POST', ws + '/variables', varData);
    console.log('Variavel criada com ID:', createdVar.variableId);
  } else {
    console.log('Variavel ja existe com ID:', createdVar.variableId);
  }

  // 2. Criar/Verificar Trigger: Evento - Contato via Formulário
  console.log('2. Verificando/Criando trigger Evento - Contato via Formulário...');
  const existingTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  let createdTrigger = existingTriggers.find(t => t.name === 'Evento - Contato via Formulário');
  if (!createdTrigger) {
    const triggerData = {
      name: 'Evento - Contato via Formulário',
      type: 'customEvent',
      customEventFilter: [
        {
          type: 'equals',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{_event}}' },
            { type: 'template', key: 'arg1', value: 'uonix_contato_formulario' }
          ]
        }
      ],
      filter: [
        {
          type: 'contains',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
            { type: 'template', key: 'arg1', value: 'marketing' }
          ]
        }
      ]
    };
    createdTrigger = await gtmRequest('POST', ws + '/triggers', triggerData);
    console.log('Trigger criado com ID:', createdTrigger.triggerId);
  } else {
    console.log('Trigger ja existe com ID:', createdTrigger.triggerId);
  }

  // 3. Criar/Verificar Tag de Conversao Google Ads: Google Ads - Conversão - Contato Formulário
  console.log('3. Verificando/Criando tag Google Ads - Conversão - Contato Formulário...');
  const existingTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  let createdTag = existingTags.find(t => t.name === 'Google Ads - Conversão - Contato Formulário');
  if (!createdTag) {
    const tagData = {
      name: 'Google Ads - Conversão - Contato Formulário',
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
          value: '{{Constante - Label Contato Formulario}}'
        }
      ],
      firingTriggerId: [createdTrigger.triggerId],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [
            { type: 'template', value: 'ad_storage' }
          ]
        }
      }
    };
    createdTag = await gtmRequest('POST', ws + '/tags', tagData);
    console.log('Tag criada com ID:', createdTag.tagId);
  } else {
    console.log('Tag ja existe com ID:', createdTag.tagId);
  }

  // 4. Publicação opcional via flag --publish
  if (process.argv.includes('--publish')) {
    console.log('4. Criando e publicando versão no GTM...');
    const resVer = await gtmRequest('POST', ws + ':create_version', {
      name: 'v29 - Conversao Contato via Formulario (Google Ads Secundária)',
      notes: 'Configura acao secundaria de conversao Contato via Formulario (awct) com respeito a LGPD/AdOpt.'
    });
    const versionId = resVer.containerVersion?.containerVersionId;
    console.log('Versao criada:', versionId);
    await gtmRequest('POST', base + '/versions/' + versionId + ':publish');
    console.log(`Versao ${versionId} publicada com sucesso no GTM!`);
  } else {
    console.log('Publicação não solicitada (use --publish para versionar e publicar). Sincronização concluída.');
  }
}

if (require.main === module) {
  main().catch(err => {
    console.error('ERRO:', err);
    process.exit(1);
  });
}

module.exports = { main };
