const { gtmRequest } = require('./gtm-client.js');

async function updateAndPublish() {
  const base = '/accounts/6348960683/containers/248910884';
  const wsList = await gtmRequest('GET', base + '/workspaces');
  const wsId = wsList.workspace[0].workspaceId;
  const ws = base + '/workspaces/' + wsId;
  console.log('Using active workspace:', wsId);

  // 1. Triggers
  const triggers = await gtmRequest('GET', ws + '/triggers');
  const tr38 = triggers.trigger.find(t => t.triggerId === '38' || t.name.includes('Iniciar Finalização'));
  console.log('Found tr38:', tr38.triggerId, tr38.name);

  // Configure tr38 for AdOpt
  const updatedTr38 = {
    name: 'Página - Iniciar Finalização Checkout AdOpt',
    type: 'customEvent',
    customEventFilter: [
      {
        type: 'matchRegex',
        parameter: [
          { type: 'template', key: 'arg0', value: '{{_event}}' },
          { type: 'template', key: 'arg1', value: '^(adopt-accept-marketing|adopt-visitor-consent-ready|adopt_consent_updated)$' }
        ]
      }
    ],
    filter: [
      {
        type: 'contains',
        parameter: [
          { type: 'template', key: 'arg0', value: '{{Page URL}}' },
          { type: 'template', key: 'arg1', value: '/finalizar-orcamento/' }
        ]
      },
      {
        type: 'contains',
        negate: true,
        parameter: [
          { type: 'template', key: 'arg0', value: '{{Page URL}}' },
          { type: 'template', key: 'arg1', value: 'solicitacao-recebida' }
        ]
      },
      {
        type: 'contains',
        negate: true,
        parameter: [
          { type: 'template', key: 'arg0', value: '{{Page URL}}' },
          { type: 'template', key: 'arg1', value: 'order-received' }
        ]
      },
      {
        type: 'contains',
        parameter: [
          { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
          { type: 'template', key: 'arg1', value: 'marketing' }
        ]
      }
    ],
    fingerprint: tr38.fingerprint
  };
  await gtmRequest('PUT', ws + '/triggers/' + tr38.triggerId, updatedTr38);
  console.log('Trigger 38 atualizado com sucesso!');

  // 2. Tag 41 (Iniciar Finalizacao) -> oncePerLoad
  const tag41 = await gtmRequest('GET', ws + '/tags/41');
  tag41.tagFiringOption = 'oncePerLoad';
  await gtmRequest('PUT', ws + '/tags/41', tag41);
  console.log('Tag 41 atualizada com oncePerLoad!');

  // 3. Criar e Publicar Versao 24
  console.log('Criando Versão 24...');
  const resVer = await gtmRequest('POST', ws + ':create_version', {
    name: 'v24 - Otimizacao Disparo Iniciar Finalizacao AdOpt',
    notes: 'Configura Iniciar Finalizacao para sincronizar com eventos AdOpt no checkout /finalizar-orcamento/ respeitando LGPD.'
  });
  const versionId = resVer.containerVersion?.containerVersionId;
  console.log(`Versão criada: ${versionId}`);

  console.log('Publicando Versão 24 live...');
  await gtmRequest('POST', base + '/versions/' + versionId + ':publish');
  console.log('Versão 24 publicada com sucesso no contêiner live!');
}

updateAndPublish().catch(err => {
  console.error('ERRO:', err);
  process.exit(1);
});
