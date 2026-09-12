const {
  gtmRequest,
  getVerifiedWorkspaceId,
  isApplyRequested,
  isPublishRequested,
  isPublishConfirmed
} = require('./gtm-client.js');

async function main() {
  const accountId = '6348960683';
  const containerId = '248910884';
  const base = `/accounts/${accountId}/containers/${containerId}`;

  // 1. Validar e obter Workspace explicitamente
  const wsObj = await getVerifiedWorkspaceId(accountId, containerId);
  const wsId = wsObj.workspaceId;
  const ws = `${base}/workspaces/${wsId}`;
  const apply = isApplyRequested();

  console.log('========================================================================');
  console.log(`✉️  SETUP CONVERSÃO NEWSLETTER — WORKSPACE ${wsId} ("${wsObj.name}")`);
  console.log(`MODO: ${apply ? 'APLICAR MUTAÇÕES (--apply)' : 'DRY-RUN (somente auditoria/planejamento)'}`);
  console.log('========================================================================\n');

  // 1. Variável: Constante - Label Assinatura Newsletter
  console.log('1. Verificando variável Constante - Label Assinatura Newsletter...');
  const existingVars = (await gtmRequest('GET', ws + '/variables')).variable || [];
  let existingVar = existingVars.find(v => v.name === 'Constante - Label Assinatura Newsletter');
  const expectedVarValue = 'PFrxCKf1w_QcENifv9pE';

  if (!existingVar) {
    if (!apply) {
      console.log('  [DRY-RUN] Variável não existe. Seria criada com valor:', expectedVarValue);
    } else {
      const varData = {
        name: 'Constante - Label Assinatura Newsletter',
        type: 'c',
        parameter: [{ type: 'template', key: 'value', value: expectedVarValue }]
      };
      existingVar = await gtmRequest('POST', ws + '/variables', varData);
      console.log('  Variável criada com ID:', existingVar.variableId);
    }
  } else {
    const curVal = (existingVar.parameter || []).find(p => p.key === 'value')?.value;
    if (curVal !== expectedVarValue) {
      console.log(`  [DIVERGÊNCIA] Variável ID ${existingVar.variableId} possui valor '${curVal}' (esperado: '${expectedVarValue}')`);
      if (apply) {
        existingVar.parameter = [{ type: 'template', key: 'value', value: expectedVarValue }];
        await gtmRequest('PUT', ws + '/variables/' + existingVar.variableId, existingVar);
        console.log('  Variável corrigida com sucesso.');
      }
    } else {
      console.log(`  Variável existe e está 100% aderente (ID: ${existingVar.variableId}).`);
    }
  }

  // 2. Trigger: Evento - Assinatura Newsletter Uônix
  console.log('\n2. Verificando trigger Evento - Assinatura Newsletter Uônix...');
  const existingTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  let existingTrigger = existingTriggers.find(t => t.name === 'Evento - Assinatura Newsletter Uônix');

  const expectedTriggerData = {
    name: 'Evento - Assinatura Newsletter Uônix',
    type: 'customEvent',
    customEventFilter: [
      {
        type: 'equals',
        negate: false,
        parameter: [
          { type: 'template', key: 'arg0', value: '{{_event}}' },
          { type: 'template', key: 'arg1', value: 'uonix_assinatura_newsletter' }
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

  if (!existingTrigger) {
    if (!apply) {
      console.log('  [DRY-RUN] Trigger não existe. Seria criado com evento uonix_assinatura_newsletter.');
    } else {
      existingTrigger = await gtmRequest('POST', ws + '/triggers', expectedTriggerData);
      console.log('  Trigger criado com ID:', existingTrigger.triggerId);
    }
  } else {
    let trDivergent = false;
    const evtParam = existingTrigger.customEventFilter?.[0]?.parameter?.find(p => p.key === 'arg1')?.value;
    if (evtParam !== 'uonix_assinatura_newsletter') trDivergent = true;

    const hasMarketing = (existingTrigger.filter || []).some(f =>
      f.parameter?.some(p => p.value?.includes('Tags_Aceitas_AdOpt')) &&
      f.parameter?.some(p => p.value === 'marketing')
    );
    if (!hasMarketing) trDivergent = true;

    if (trDivergent) {
      console.log(`  [DIVERGÊNCIA] Trigger ID ${existingTrigger.triggerId} diverge do contrato canônico.`);
      if (apply) {
        existingTrigger.customEventFilter = expectedTriggerData.customEventFilter;
        existingTrigger.filter = expectedTriggerData.filter;
        await gtmRequest('PUT', ws + '/triggers/' + existingTrigger.triggerId, existingTrigger);
        console.log('  Trigger atualizado para contrato canônico.');
      }
    } else {
      console.log(`  Trigger existe e está 100% aderente (ID: ${existingTrigger.triggerId}).`);
    }
  }

  // 3. Tag: Google Ads - Conversão - Assinatura Newsletter
  console.log('\n3. Verificando tag Google Ads - Conversão - Assinatura Newsletter...');
  const existingTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  let existingTag = existingTags.find(t => t.name === 'Google Ads - Conversão - Assinatura Newsletter');

  const firingId = existingTrigger ? existingTrigger.triggerId : '46';
  const expectedTagData = {
    name: 'Google Ads - Conversão - Assinatura Newsletter',
    type: 'awct',
    parameter: [
      {
        type: 'template',
        key: 'orderId',
        value: '{{DLV - transaction_id}}'
      },
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
    firingTriggerId: [firingId],
    tagFiringOption: 'oncePerEvent',
    consentSettings: {
      consentStatus: 'needed',
      consentType: {
        type: 'list',
        list: [{ type: 'template', value: 'ad_storage' }]
      }
    }
  };

  if (!existingTag) {
    if (!apply) {
      console.log('  [DRY-RUN] Tag não existe. Seria criada com deduplicação orderId e consentimento ad_storage.');
    } else {
      existingTag = await gtmRequest('POST', ws + '/tags', expectedTagData);
      console.log('  Tag criada com ID:', existingTag.tagId);
    }
  } else {
    let tagDivergent = false;
    const hasOrderId = (existingTag.parameter || []).some(p => p.key === 'orderId' && p.value === '{{DLV - transaction_id}}');
    if (!hasOrderId) tagDivergent = true;

    const hasAdStorage = existingTag.consentSettings?.consentStatus === 'needed';
    if (!hasAdStorage) tagDivergent = true;

    if (tagDivergent) {
      console.log(`  [DIVERGÊNCIA] Tag ID ${existingTag.tagId} diverge do contrato (orderId/consentimento).`);
      if (apply) {
        existingTag.parameter = expectedTagData.parameter;
        existingTag.consentSettings = expectedTagData.consentSettings;
        existingTag.firingTriggerId = [firingId];
        await gtmRequest('PUT', ws + '/tags/' + existingTag.tagId, existingTag);
        console.log('  Tag atualizada para contrato canônico com orderId.');
      }
    } else {
      console.log(`  Tag existe e está 100% aderente com orderId e ad_storage (ID: ${existingTag.tagId}).`);
    }
  }

  // 4. Publicação sob demanda com dupla confirmação
  if (isPublishRequested()) {
    if (!isPublishConfirmed()) {
      console.warn(
        '\n⚠️ Flag --publish fornecida, mas requer confirmação via --confirm-publish para publicar live. ' +
        'Publicação bloqueada fail-closed.'
      );
    } else {
      console.log('\nCriando e publicando versão no GTM...');
      const resVer = await gtmRequest('POST', ws + ':create_version', {
        name: 'v31 - Conversao Assinatura Newsletter com Deduplicação Google Ads',
        notes: 'Garante orderId: {{DLV - transaction_id}} e consentimento estrito ad_storage na conversão de newsletter.'
      });
      const versionId = resVer.containerVersion?.containerVersionId;
      console.log('Versão criada:', versionId);
      await gtmRequest('POST', base + '/versions/' + versionId + ':publish');
      console.log(`Versão ${versionId} publicada com sucesso no GTM!`);
    }
  } else {
    console.log('\nPublicação live não solicitada. Para publicar, use: --publish --confirm-publish --apply');
  }
}

if (require.main === module) {
  main().catch(err => {
    console.error('ERRO:', err.message);
    process.exit(1);
  });
}

module.exports = { main };
