const {
  gtmRequest,
  getVerifiedWorkspaceId,
  isApplyRequested,
  isPublishRequested,
  isPublishConfirmed,
  validateAwctTagContract,
  validateCustomEventTriggerContract
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
  console.log(`📥 SETUP CONVERSÃO DOWNLOAD CHECKLIST — WORKSPACE ${wsId} ("${wsObj.name}")`);
  console.log(`MODO: ${apply ? 'APLICAR MUTAÇÕES (--apply)' : 'DRY-RUN (somente auditoria/planejamento)'}`);
  console.log('========================================================================\n');

  // 1. Variável: Constante - Label Download Checklist
  console.log('1. Verificando variável Constante - Label Download Checklist...');
  const existingVars = (await gtmRequest('GET', ws + '/variables')).variable || [];
  let existingVar = existingVars.find(v => v.name === 'Constante - Label Download Checklist');
  const expectedVarValue = 'nXYDCKKayPQcENifv9pE';

  if (!existingVar) {
    if (!apply) {
      console.log('  [DRY-RUN] Variável não existe. Seria criada com valor:', expectedVarValue);
    } else {
      const varData = {
        name: 'Constante - Label Download Checklist',
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

  // 2. Trigger: Evento - Download Checklist Técnico
  console.log('\n2. Verificando trigger Evento - Download Checklist Técnico...');
  const existingTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  let existingTrigger = existingTriggers.find(t => t.name === 'Evento - Download Checklist Técnico');

  const expectedTriggerData = {
    name: 'Evento - Download Checklist Técnico',
    type: 'customEvent',
    customEventFilter: [
      {
        type: 'equals',
        negate: false,
        parameter: [
          { type: 'template', key: 'arg0', value: '{{_event}}' },
          { type: 'template', key: 'arg1', value: 'uonix_download_checklist' }
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
      console.log('  [DRY-RUN] Trigger não existe. Seria criado com evento uonix_download_checklist.');
    } else {
      existingTrigger = await gtmRequest('POST', ws + '/triggers', expectedTriggerData);
      console.log('  Trigger criado com ID:', existingTrigger.triggerId);
    }
  } else {
    const trValidation = validateCustomEventTriggerContract(existingTrigger, 'uonix_download_checklist');
    if (!trValidation.isAdherent) {
      console.log(`  [DIVERGÊNCIA] Trigger ID ${existingTrigger.triggerId} diverge do contrato canônico:`);
      trValidation.differences.forEach(d => console.log(`    - ${d}`));
      if (apply) {
        existingTrigger.type = expectedTriggerData.type;
        existingTrigger.customEventFilter = expectedTriggerData.customEventFilter;
        existingTrigger.filter = expectedTriggerData.filter;
        await gtmRequest('PUT', ws + '/triggers/' + existingTrigger.triggerId, existingTrigger);
        console.log('  Trigger atualizado para contrato canônico.');
      }
    } else {
      console.log(`  Trigger existe e está 100% aderente (ID: ${existingTrigger.triggerId}).`);
    }
  }

  // 3. Tag: Google Ads - Conversão - Download Checklist Técnico
  console.log('\n3. Verificando tag Google Ads - Conversão - Download Checklist Técnico...');
  const existingTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  let existingTag = existingTags.find(t => t.name === 'Google Ads - Conversão - Download Checklist Técnico');

  const firingId = existingTrigger ? existingTrigger.triggerId : '50';
  const expectedTagData = {
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
      console.log('  [DRY-RUN] Tag não existe. Seria criada com ad_storage needed.');
    } else {
      existingTag = await gtmRequest('POST', ws + '/tags', expectedTagData);
      console.log('  Tag criada com ID:', existingTag.tagId);
    }
  } else {
    const tagValidation = validateAwctTagContract(existingTag, expectedTagData);

    if (!tagValidation.isAdherent) {
      console.log(`  [DIVERGÊNCIA] Tag ID ${existingTag.tagId} diverge do contrato canônico:`);
      tagValidation.differences.forEach(d => console.log(`    - ${d}`));
      if (apply) {
        existingTag.type = expectedTagData.type;
        existingTag.parameter = expectedTagData.parameter;
        existingTag.tagFiringOption = expectedTagData.tagFiringOption;
        existingTag.consentSettings = expectedTagData.consentSettings;
        existingTag.firingTriggerId = [firingId];
        await gtmRequest('PUT', ws + '/tags/' + existingTag.tagId, existingTag);
        console.log('  Tag atualizada para contrato canônico.');
      }
    } else {
      console.log(`  Tag existe e está 100% aderente com ad_storage (ID: ${existingTag.tagId}).`);
    }
  }

  // 4. Publicação sob demanda com dupla confirmação e exigência estrita de --apply e integridade
  if (isPublishRequested()) {
    if (!apply) {
      console.warn(
        '\n[DRY-RUN] Flag --publish requer explicitamente a flag --apply para executar criação e publicação de versão. ' +
        'Publicação bloqueada fail-closed em modo somente leitura.'
      );
    } else if (!isPublishConfirmed()) {
      console.warn(
        '\n⚠️ Flag --publish fornecida, mas requer confirmação via --confirm-publish para publicar live. ' +
        'Publicação bloqueada fail-closed.'
      );
    } else {
      const finalValidation = validateAwctTagContract(existingTag, expectedTagData);
      if (!finalValidation.isAdherent) {
        console.error('\n[FAIL-CLOSED] Publicação bloqueada: a Tag continua divergente do contrato canônico:');
        finalValidation.differences.forEach(d => console.error(`  - ${d}`));
        process.exit(1);
      }

      console.log('\nCriando e publicando versão no GTM...');
      const resVer = await gtmRequest('POST', ws + ':create_version', {
        name: 'v31 - Conversao Download Checklist Tecnico',
        notes: 'Garante conformidade estrita da conversão de download de checklist técnico no GTM.'
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
