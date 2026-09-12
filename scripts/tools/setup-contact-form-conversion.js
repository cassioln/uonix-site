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
  console.log(`📝 SETUP CONVERSÃO CONTATO FORMULÁRIO — WORKSPACE ${wsId} ("${wsObj.name}")`);
  console.log(`MODO: ${apply ? 'APLICAR MUTAÇÕES (--apply)' : 'DRY-RUN (somente auditoria/planejamento)'}`);
  console.log('========================================================================\n');

  // 1. Variável: Constante - Label Contato Formulario
  console.log('1. Verificando variável Constante - Label Contato Formulario...');
  const existingVars = (await gtmRequest('GET', ws + '/variables')).variable || [];
  let existingVar = existingVars.find(v => v.name === 'Constante - Label Contato Formulario');
  const expectedVarValue = 'RVZmCKznyfQcENifv9pE';

  if (!existingVar) {
    if (!apply) {
      console.log('  [DRY-RUN] Variável não existe. Seria criada com valor:', expectedVarValue);
    } else {
      const varData = {
        name: 'Constante - Label Contato Formulario',
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

  // 2. Trigger: Evento - Contato via Formulário
  console.log('\n2. Verificando trigger Evento - Contato via Formulário...');
  const existingTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  let existingTrigger = existingTriggers.find(t => t.name === 'Evento - Contato via Formulário');

  const expectedTriggerData = {
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

  if (!existingTrigger) {
    if (!apply) {
      console.log('  [DRY-RUN] Trigger não existe. Seria criado com evento uonix_contato_formulario.');
    } else {
      existingTrigger = await gtmRequest('POST', ws + '/triggers', expectedTriggerData);
      console.log('  Trigger criado com ID:', existingTrigger.triggerId);
    }
  } else {
    const trValidation = validateCustomEventTriggerContract(existingTrigger, 'uonix_contato_formulario');
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

  // 3. Tag: Google Ads - Conversão - Contato Formulário
  console.log('\n3. Verificando tag Google Ads - Conversão - Contato Formulário...');
  const existingTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  let existingTag = existingTags.find(t => t.name === 'Google Ads - Conversão - Contato Formulário');

  const firingId = existingTrigger ? existingTrigger.triggerId : '53';
  const expectedTagData = {
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
        name: 'v31 - Conversao Contato via Formulario',
        notes: 'Garante conformidade estrita da conversão de contato via formulário no GTM.'
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
