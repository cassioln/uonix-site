const { gtmRequest } = require('./gtm-client.js');

async function setup() {
  const base = '/accounts/6348960683/containers/248910884/workspaces/23';

  console.log('1. Verificando/Criando Variáveis de Constante de Label...');
  const variablesToCreate = [
    { name: 'Constante - Label Adicionar Carrinho', value: 'Wsw-CMnruPQcENifv9pE' },
    { name: 'Constante - Label Iniciar Finalizacao', value: 'q9A-CMzruPQcENifv9pE' },
    { name: 'Constante - Label Telefone', value: 'tcD8CM_ruPQcENifv9pE' },
    { name: 'Constante - Label Email', value: '3nxjCNLruPQcENifv9pE' }
  ];

  const existingVars = await gtmRequest('GET', `${base}/variables`);
  const varMap = {};
  for (const v of (existingVars.variable || [])) {
    varMap[v.name] = v;
  }

  for (const vDef of variablesToCreate) {
    if (varMap[vDef.name]) {
      console.log(`- Variável já existe: ${vDef.name} (id: ${varMap[vDef.name].variableId})`);
    } else {
      const created = await gtmRequest('POST', `${base}/variables`, {
        name: vDef.name,
        type: 'c',
        parameter: [
          { type: 'template', key: 'value', value: vDef.value }
        ]
      });
      console.log(`- Variável criada: ${vDef.name} (id: ${created.variableId})`);
      varMap[vDef.name] = created;
    }
  }

  console.log('\n2. Verificando/Criando Triggers...');
  const triggersToCreate = [
    {
      name: 'Clique - Telefone tel',
      type: 'linkClick',
      filter: [
        {
          type: 'matchRegex',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Click URL}}' },
            { type: 'template', key: 'arg1', value: '^tel:' },
            { type: 'boolean', key: 'ignore_case', value: 'true' }
          ]
        },
        {
          type: 'contains',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
            { type: 'template', key: 'arg1', value: 'marketing' }
          ]
        }
      ],
      waitForTags: { type: 'boolean', value: 'false' },
      checkValidation: { type: 'boolean', value: 'false' }
    },
    {
      name: 'Clique - Email mailto',
      type: 'linkClick',
      filter: [
        {
          type: 'matchRegex',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Click URL}}' },
            { type: 'template', key: 'arg1', value: '^mailto:' },
            { type: 'boolean', key: 'ignore_case', value: 'true' }
          ]
        },
        {
          type: 'contains',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
            { type: 'template', key: 'arg1', value: 'marketing' }
          ]
        }
      ],
      waitForTags: { type: 'boolean', value: 'false' },
      checkValidation: { type: 'boolean', value: 'false' }
    },
    {
      name: 'Evento - Iniciar Finalização Uônix',
      type: 'customEvent',
      customEventFilter: [
        {
          type: 'equals',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{_event}}' },
            { type: 'template', key: 'arg1', value: 'uonix_iniciar_finalizacao' }
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
    },
    {
      name: 'Evento - Adicionar ao Carrinho Uônix',
      type: 'customEvent',
      customEventFilter: [
        {
          type: 'equals',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{_event}}' },
            { type: 'template', key: 'arg1', value: 'uonix_adicionar_ao_carrinho' }
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
    }
  ];

  const existingTriggers = await gtmRequest('GET', `${base}/triggers`);
  const trMap = {};
  for (const tr of (existingTriggers.trigger || [])) {
    trMap[tr.name] = tr;
  }

  for (const trDef of triggersToCreate) {
    if (trMap[trDef.name]) {
      console.log(`- Trigger já existe: ${trDef.name} (id: ${trMap[trDef.name].triggerId})`);
    } else {
      const created = await gtmRequest('POST', `${base}/triggers`, trDef);
      console.log(`- Trigger criada: ${trDef.name} (id: ${created.triggerId})`);
      trMap[trDef.name] = created;
    }
  }

  console.log('\n3. Verificando/Criando Tags...');
  const tagsToCreate = [
    {
      name: 'Google Ads - Conversão - Adicionar ao Carrinho',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Adicionar Carrinho}}' }
      ],
      firingTriggerId: [trMap['Evento - Adicionar ao Carrinho Uônix'].triggerId],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      }
    },
    {
      name: 'Google Ads - Conversão - Iniciar Finalização',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Iniciar Finalizacao}}' }
      ],
      firingTriggerId: [trMap['Evento - Iniciar Finalização Uônix'].triggerId],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      }
    },
    {
      name: 'Google Ads - Conversão - Contato Telefone',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Telefone}}' }
      ],
      firingTriggerId: [trMap['Clique - Telefone tel'].triggerId],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      }
    },
    {
      name: 'Google Ads - Conversão - Contato Email',
      type: 'awct',
      parameter: [
        { type: 'template', key: 'conversionId', value: '{{Constante - Google Ads ID}}' },
        { type: 'template', key: 'conversionLabel', value: '{{Constante - Label Email}}' }
      ],
      firingTriggerId: [trMap['Clique - Email mailto'].triggerId],
      tagFiringOption: 'oncePerEvent',
      consentSettings: {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      }
    }
  ];

  const existingTags = await gtmRequest('GET', `${base}/tags`);
  const tagMap = {};
  for (const t of (existingTags.tag || [])) {
    tagMap[t.name] = t;
  }

  for (const tDef of tagsToCreate) {
    if (tagMap[tDef.name]) {
      console.log(`- Tag já existe: ${tDef.name} (id: ${tagMap[tDef.name].tagId})`);
    } else {
      const created = await gtmRequest('POST', `${base}/tags`, tDef);
      console.log(`- Tag criada: ${tDef.name} (id: ${created.tagId})`);
      tagMap[tDef.name] = created;
    }
  }

  console.log('\nConfiguração do Workspace concluída com sucesso!');
}

setup().catch(err => {
  console.error('ERRO:', err);
  process.exit(1);
});
