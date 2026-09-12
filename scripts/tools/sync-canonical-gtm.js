const fs = require('fs');
const path = require('path');
const {
  gtmRequest,
  getVerifiedWorkspaceId,
  isApplyRequested,
  isPublishRequested,
  isPublishConfirmed
} = require('./gtm-client.js');

/**
 * Calcula estruturalmente todas as ações necessárias para reconciliar o workspace
 * contra o manifesto canônico auditado (docs/gtm/uonix-google-ads-gtm-import.json).
 *
 * @param {object} manifest Conteúdo do manifesto canônico
 * @param {object} currentState { tags, triggers, variables } do workspace
 * @returns {Array} Lista de ações planejadas (CREATE, UPDATE, DELETE)
 */
function computeSyncActions(manifest, currentState) {
  const plannedActions = [];

  const canonicalTags = manifest.containerVersion?.tag || [];
  const canonicalTriggers = manifest.containerVersion?.trigger || [];
  const canonicalVariables = manifest.containerVersion?.variable || [];

  const currentTags = currentState.tags || [];
  const currentTriggers = currentState.triggers || [];
  const currentVariables = currentState.variables || [];

  // Mapeamentos
  const currentTagById = new Map(currentTags.map(t => [String(t.tagId), t]));
  const currentTagByName = new Map(currentTags.map(t => [t.name, t]));

  const currentTriggerById = new Map(currentTriggers.map(t => [String(t.triggerId), t]));
  const currentTriggerByName = new Map(currentTriggers.map(t => [t.name, t]));

  const currentVariableById = new Map(currentVariables.map(v => [String(v.variableId), v]));
  const currentVariableByName = new Map(currentVariables.map(v => [v.name, v]));

  const canonicalTagById = new Map(canonicalTags.map(t => [String(t.tagId), t]));
  const canonicalTagByName = new Map(canonicalTags.map(t => [t.name, t]));

  const canonicalTriggerById = new Map(canonicalTriggers.map(t => [String(t.triggerId), t]));
  const canonicalTriggerByName = new Map(canonicalTriggers.map(t => [t.name, t]));

  const canonicalVariableById = new Map(canonicalVariables.map(v => [String(v.variableId), v]));
  const canonicalVariableByName = new Map(canonicalVariables.map(v => [v.name, v]));

  // 1. Entidades não autorizadas no workspace -> DELETE
  for (const tag of currentTags) {
    const isAuthorized = canonicalTagById.has(String(tag.tagId)) || canonicalTagByName.has(tag.name);
    if (!isAuthorized) {
      plannedActions.push({
        type: 'DELETE_TAG',
        id: tag.tagId,
        name: tag.name,
        target: tag,
        description: `Remover tag não autorizada pelo manifesto canônico (ID: ${tag.tagId}, Nome: "${tag.name}")`
      });
    }
  }

  for (const tr of currentTriggers) {
    const isAuthorized = canonicalTriggerById.has(String(tr.triggerId)) || canonicalTriggerByName.has(tr.name);
    if (!isAuthorized) {
      plannedActions.push({
        type: 'DELETE_TRIGGER',
        id: tr.triggerId,
        name: tr.name,
        target: tr,
        description: `Remover trigger não autorizado pelo manifesto canônico (ID: ${tr.triggerId}, Nome: "${tr.name}")`
      });
    }
  }

  for (const v of currentVariables) {
    const isAuthorized = canonicalVariableById.has(String(v.variableId)) || canonicalVariableByName.has(v.name);
    if (!isAuthorized) {
      plannedActions.push({
        type: 'DELETE_VARIABLE',
        id: v.variableId,
        name: v.name,
        target: v,
        description: `Remover variável não autorizada pelo manifesto canônico (ID: ${v.variableId}, Nome: "${v.name}")`
      });
    }
  }

  // 2. Tags canônicas obrigatórias: verificar ausência (CREATE_TAG) ou divergência (UPDATE_TAG)
  for (const expected of canonicalTags) {
    const current = currentTagById.get(String(expected.tagId)) || currentTagByName.get(expected.name);
    if (!current) {
      plannedActions.push({
        type: 'CREATE_TAG',
        name: expected.name,
        expected,
        description: `Criar Tag canônica ausente: "${expected.name}" (ID canônico: ${expected.tagId})`
      });
      continue;
    }

    const diffs = [];
    if (current.type !== expected.type) {
      diffs.push(`Tipo divergente: esperado "${expected.type}", atual "${current.type}"`);
    }
    const expFiring = expected.tagFiringOption || 'oncePerEvent';
    if ((current.tagFiringOption || 'oncePerEvent') !== expFiring) {
      diffs.push(`tagFiringOption divergente: esperado "${expFiring}", atual "${current.tagFiringOption}"`);
    }

    // Parâmetros exatos sem extras/duplicados/tipos divergentes
    const expParams = expected.parameter || [];
    const curParams = current.parameter || [];
    const curKeys = curParams.map(p => p.key);
    if (new Set(curKeys).size !== curKeys.length) {
      diffs.push(`Parâmetros duplicados encontrados na Tag: ${curKeys.join(', ')}`);
    }
    if (expParams.length !== curParams.length) {
      diffs.push(`Quantidade de parâmetros divergente: esperado ${expParams.length}, atual ${curParams.length}`);
    }
    for (const ep of expParams) {
      const cp = curParams.find(p => p.key === ep.key);
      if (!cp) {
        diffs.push(`Parâmetro obrigatório "${ep.key}" ausente`);
      } else {
        if (cp.type !== ep.type) {
          diffs.push(`Tipo do parâmetro "${ep.key}" divergente: esperado "${ep.type}", atual "${cp.type}"`);
        }
        if (cp.value !== ep.value) {
          diffs.push(`Valor do parâmetro "${ep.key}" divergente: esperado "${ep.value}", atual "${cp.value}"`);
        }
      }
    }
    for (const cp of curParams) {
      if (!expParams.some(ep => ep.key === cp.key)) {
        diffs.push(`Parâmetro não canônico/extra "${cp.key}" presente na Tag`);
      }
    }

    // Consent Settings
    if (expected.consentSettings) {
      if (current.consentSettings?.consentStatus !== expected.consentSettings.consentStatus) {
        diffs.push(`consentStatus divergente: esperado "${expected.consentSettings.consentStatus}", atual "${current.consentSettings?.consentStatus}"`);
      }
      if (expected.consentSettings.consentType) {
        if (current.consentSettings?.consentType?.type !== expected.consentSettings.consentType.type) {
          diffs.push(`consentType.type divergente: esperado "${expected.consentSettings.consentType.type}", atual "${current.consentSettings?.consentType?.type}"`);
        }
        const expList = (expected.consentSettings.consentType.list || []).map(i => `${i.type}:${i.value}`);
        const curList = (current.consentSettings?.consentType?.list || []).map(i => `${i.type}:${i.value}`);
        if (expList.length !== curList.length || !expList.every(v => curList.includes(v))) {
          diffs.push(`consentType.list divergente: esperado [${expList.join(', ')}], atual [${curList.join(', ')}]`);
        }
      }
    }

    // Firing Triggers
    if (expected.firingTriggerId) {
      const expTriggers = (expected.firingTriggerId || []).map(String).sort();
      const curTriggers = (current.firingTriggerId || []).map(String).sort();
      if (expTriggers.length !== curTriggers.length || !expTriggers.every((id, idx) => id === curTriggers[idx])) {
        diffs.push(`firingTriggerId divergente: esperado [${expTriggers.join(', ')}], atual [${curTriggers.join(', ')}]`);
      }
    }

    if (diffs.length > 0) {
      plannedActions.push({
        type: 'UPDATE_TAG',
        id: current.tagId,
        name: current.name,
        target: current,
        expected,
        differences: diffs,
        description: `Sincronizar Tag ${current.tagId} (${current.name}): ${diffs.join('; ')}`
      });
    }
  }

  // 3. Triggers canônicos obrigatórios: verificar ausência (CREATE_TRIGGER) ou divergência (UPDATE_TRIGGER)
  for (const expected of canonicalTriggers) {
    const current = currentTriggerById.get(String(expected.triggerId)) || currentTriggerByName.get(expected.name);
    if (!current) {
      plannedActions.push({
        type: 'CREATE_TRIGGER',
        name: expected.name,
        expected,
        description: `Criar Trigger canônico ausente: "${expected.name}" (ID canônico: ${expected.triggerId})`
      });
      continue;
    }

    const diffs = [];
    if (current.type !== expected.type) {
      diffs.push(`Tipo de trigger divergente: esperado "${expected.type}", atual "${current.type}"`);
    }

    // customEventFilter
    const expCef = expected.customEventFilter || [];
    const curCef = current.customEventFilter || [];
    if (expCef.length !== curCef.length) {
      diffs.push(`Quantidade de customEventFilter divergente: esperado ${expCef.length}, atual ${curCef.length}`);
    } else {
      for (let i = 0; i < expCef.length; i++) {
        const ef = expCef[i];
        const cf = curCef[i];
        if (cf.type !== ef.type) diffs.push(`customEventFilter[${i}].type divergente: esperado "${ef.type}", atual "${cf.type}"`);
        if (cf.negate !== false) diffs.push(`customEventFilter[${i}] sem negate:false explícito (encontrado: ${cf.negate})`);
        const eParams = ef.parameter || [];
        const cParams = cf.parameter || [];
        for (const ep of eParams) {
          const cp = cParams.find(p => p.key === ep.key);
          if (!cp || cp.value !== ep.value || cp.type !== ep.type) {
            diffs.push(`customEventFilter[${i}] parâmetro "${ep.key}" divergente: esperado ${JSON.stringify(ep)}, atual ${JSON.stringify(cp)}`);
          }
        }
      }
    }

    // filter (ex: AdOpt)
    const expF = expected.filter || [];
    const curF = current.filter || [];
    if (expF.length !== curF.length) {
      diffs.push(`Quantidade de filtros divergente: esperado ${expF.length}, atual ${curF.length}`);
    } else {
      for (let i = 0; i < expF.length; i++) {
        const ef = expF[i];
        const cf = curF[i];
        if (cf.type !== ef.type) diffs.push(`filter[${i}].type divergente: esperado "${ef.type}", atual "${cf.type}"`);
        if (cf.negate !== false) diffs.push(`filter[${i}] sem negate:false explícito (encontrado: ${cf.negate})`);
        const eParams = ef.parameter || [];
        const cParams = cf.parameter || [];
        for (const ep of eParams) {
          const cp = cParams.find(p => p.key === ep.key);
          if (!cp || cp.value !== ep.value || cp.type !== ep.type) {
            diffs.push(`filter[${i}] parâmetro "${ep.key}" divergente: esperado ${JSON.stringify(ep)}, atual ${JSON.stringify(cp)}`);
          }
        }
      }
    }

    if (diffs.length > 0) {
      plannedActions.push({
        type: 'UPDATE_TRIGGER',
        id: current.triggerId,
        name: current.name,
        target: current,
        expected,
        differences: diffs,
        description: `Sincronizar Trigger ${current.triggerId} (${current.name}): ${diffs.join('; ')}`
      });
    }
  }

  // 4. Variáveis canônicas obrigatórias: verificar ausência (CREATE_VARIABLE) ou divergência (UPDATE_VARIABLE)
  for (const expected of canonicalVariables) {
    const current = currentVariableById.get(String(expected.variableId)) || currentVariableByName.get(expected.name);
    if (!current) {
      plannedActions.push({
        type: 'CREATE_VARIABLE',
        name: expected.name,
        expected,
        description: `Criar Variável canônica ausente: "${expected.name}" (ID canônico: ${expected.variableId})`
      });
      continue;
    }

    const diffs = [];
    if (current.type !== expected.type) {
      diffs.push(`Tipo de variável divergente: esperado "${expected.type}", atual "${current.type}"`);
    }

    const expParams = expected.parameter || [];
    const curParams = current.parameter || [];
    for (const ep of expParams) {
      const cp = curParams.find(p => p.key === ep.key);
      if (!cp || cp.value !== ep.value || cp.type !== ep.type) {
        diffs.push(`Variável parâmetro "${ep.key}" divergente: esperado ${JSON.stringify(ep)}, atual ${JSON.stringify(cp)}`);
      }
    }

    if (diffs.length > 0) {
      plannedActions.push({
        type: 'UPDATE_VARIABLE',
        id: current.variableId,
        name: current.name,
        target: current,
        expected,
        differences: diffs,
        description: `Sincronizar Variável ${current.variableId} (${current.name}): ${diffs.join('; ')}`
      });
    }
  }

  return plannedActions;
}

async function syncGtmGovernance() {
  const accountId = '6348960683';
  const containerId = '248910884';
  const base = `/accounts/${accountId}/containers/${containerId}`;

  // 1. Obter e validar workspace explicitamente
  const wsObj = await getVerifiedWorkspaceId(accountId, containerId);
  const wsId = wsObj.workspaceId;
  const ws = `${base}/workspaces/${wsId}`;
  const apply = isApplyRequested();

  console.log('========================================================================');
  console.log(`🧭 GOVERNANÇA GTM CANÔNICA — WORKSPACE ${wsId} ("${wsObj.name}")`);
  console.log(`MODO: ${apply ? 'APLICAR MUTAÇÕES (--apply)' : 'DRY-RUN (somente planejamento/auditoria)'}`);
  console.log('========================================================================\n');

  // 2. Carregar o manifesto canônico auditado
  const manifestPath = path.resolve(__dirname, '../../docs/gtm/uonix-google-ads-gtm-import.json');
  if (!fs.existsSync(manifestPath)) {
    throw new Error(`[FAIL-CLOSED] Manifesto canônico não encontrado em: ${manifestPath}`);
  }
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

  // 3. Obter estado atual do workspace
  const currentTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  const currentTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  const currentVariables = (await gtmRequest('GET', ws + '/variables')).variable || [];

  console.log(`Estado atual do Workspace: ${currentTags.length} tags, ${currentTriggers.length} triggers, ${currentVariables.length} variáveis.`);
  console.log(`Manifesto canônico: ${(manifest.containerVersion?.tag || []).length} tags, ${(manifest.containerVersion?.trigger || []).length} triggers, ${(manifest.containerVersion?.variable || []).length} variáveis.\n`);

  // 4. Calcular ações estruturais
  const plannedActions = computeSyncActions(manifest, {
    tags: currentTags,
    triggers: currentTriggers,
    variables: currentVariables
  });

  console.log(`📋 Total de ações identificadas: ${plannedActions.length}`);
  if (plannedActions.length === 0) {
    console.log('✅ O workspace já está 100% aderente ao contrato canônico de governança.');
    if (!isPublishRequested()) {
      return;
    }
  }

  for (let i = 0; i < plannedActions.length; i++) {
    const act = plannedActions[i];
    console.log(`  [${i + 1}] [${act.type}] ${act.name} (ID: ${act.id || 'NOVO'}): ${act.description}`);
  }
  console.log('');

  // 5. Se não houver --apply, encerra como DRY-RUN fail-closed
  if (!apply) {
    console.log('🛡️  NENHUMA mutação foi executada (modo DRY-RUN padrão).');
    if (isPublishRequested()) {
      console.error(
        '[FAIL-CLOSED] Flag --publish requer explicitamente a flag --apply e 100% de aderência comprovada. ' +
        'Publicação bloqueada.'
      );
      process.exit(1);
    }
    if (plannedActions.length > 0) {
      console.log(`Para aplicar as ${plannedActions.length} ações acima no Workspace ${wsId}, execute:`);
      console.log(`  node scripts/tools/sync-canonical-gtm.js --workspace-id=${wsId} --apply\n`);
    }
    return;
  }

  // 6. Execução no modo --apply
  console.log('🚀 Executando mutações planejadas no Workspace...');
  for (const act of plannedActions) {
    if (act.type === 'DELETE_TAG') {
      console.log(`- Deletando Tag não autorizada ${act.id}...`);
      await gtmRequest('DELETE', ws + '/tags/' + act.id);
    } else if (act.type === 'DELETE_TRIGGER') {
      console.log(`- Deletando Trigger não autorizado ${act.id}...`);
      await gtmRequest('DELETE', ws + '/triggers/' + act.id);
    } else if (act.type === 'DELETE_VARIABLE') {
      console.log(`- Deletando Variável não autorizada ${act.id}...`);
      await gtmRequest('DELETE', ws + '/variables/' + act.id);
    } else if (act.type === 'CREATE_TAG') {
      console.log(`- Criando Tag canônica "${act.name}"...`);
      const payload = {
        name: act.expected.name,
        type: act.expected.type,
        parameter: act.expected.parameter,
        tagFiringOption: act.expected.tagFiringOption || 'oncePerEvent',
        consentSettings: act.expected.consentSettings,
        firingTriggerId: act.expected.firingTriggerId
      };
      await gtmRequest('POST', ws + '/tags', payload);
    } else if (act.type === 'CREATE_TRIGGER') {
      console.log(`- Criando Trigger canônico "${act.name}"...`);
      const payload = {
        name: act.expected.name,
        type: act.expected.type,
        customEventFilter: act.expected.customEventFilter,
        filter: act.expected.filter
      };
      await gtmRequest('POST', ws + '/triggers', payload);
    } else if (act.type === 'CREATE_VARIABLE') {
      console.log(`- Criando Variável canônica "${act.name}"...`);
      const payload = {
        name: act.expected.name,
        type: act.expected.type,
        parameter: act.expected.parameter
      };
      await gtmRequest('POST', ws + '/variables', payload);
    } else if (act.type === 'UPDATE_TAG') {
      console.log(`- Sincronizando Tag ${act.id} com contrato canônico...`);
      const payload = {
        ...act.target,
        type: act.expected.type,
        parameter: act.expected.parameter,
        tagFiringOption: act.expected.tagFiringOption || act.target.tagFiringOption || 'oncePerEvent',
        consentSettings: act.expected.consentSettings || act.target.consentSettings,
        firingTriggerId: act.expected.firingTriggerId || act.target.firingTriggerId
      };
      await gtmRequest('PUT', ws + '/tags/' + act.id, payload);
    } else if (act.type === 'UPDATE_TRIGGER') {
      console.log(`- Sincronizando Trigger ${act.id} com contrato canônico...`);
      const payload = {
        ...act.target,
        type: act.expected.type,
        customEventFilter: act.expected.customEventFilter || act.target.customEventFilter,
        filter: act.expected.filter || act.target.filter
      };
      await gtmRequest('PUT', ws + '/triggers/' + act.id, payload);
    } else if (act.type === 'UPDATE_VARIABLE') {
      console.log(`- Sincronizando Variável ${act.id} com contrato canônico...`);
      const payload = {
        ...act.target,
        type: act.expected.type,
        parameter: act.expected.parameter
      };
      await gtmRequest('PUT', ws + '/variables/' + act.id, payload);
    }
  }

  console.log('\n✅ Mutações enviadas. Iniciando READBACK pós-aplicação no Workspace ' + wsId + '...');

  // 7. READBACK OBRIGATÓRIO: re-consulta o workspace e certifica 100% de paridade antes de qualquer publicação
  const freshTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  const freshTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  const freshVariables = (await gtmRequest('GET', ws + '/variables')).variable || [];

  const remainingActions = computeSyncActions(manifest, {
    tags: freshTags,
    triggers: freshTriggers,
    variables: freshVariables
  });

  if (remainingActions.length > 0) {
    console.error(`\n[FAIL-CLOSED] Readback pós-aplicação falhou: ainda restam ${remainingActions.length} ações pendentes!`);
    for (const ra of remainingActions) {
      console.error(`  - [${ra.type}] ${ra.name}: ${ra.description}`);
    }
    process.exit(1);
  }

  console.log('🛡️  READBACK CONFIRMADO: Workspace 100% aderente ao manifesto canônico!');

  // 8. Publicação sob demanda com dupla confirmação e exigência estrita de --apply e readback verde
  if (isPublishRequested()) {
    if (!isPublishConfirmed()) {
      console.warn(
        '\n⚠️ Publicação solicitada com --publish, mas requer confirmação explícita via --confirm-publish. ' +
        'A versão NÃO foi publicada automaticamente.'
      );
    } else {
      console.log('\n📦 Criando nova versão a partir do Workspace verificado...');
      const versionRes = await gtmRequest('POST', ws + ':create_version', {
        name: 'v31 - Governança Estrita Deduplicação Newsletter e Carrinho',
        notes: 'Adiciona orderId: {{DLV - transaction_id}} na Tag 47 para deduplicação server-side e sincroniza regras AdOpt/negate:false.'
      });
      const newVersionId = versionRes.containerVersion?.containerVersionId;
      console.log(`Versão criada: ${newVersionId}`);

      console.log('🚀 Publicando versão live...');
      await gtmRequest('POST', base + '/versions/' + newVersionId + ':publish');
      console.log(`🎉 Versão ${newVersionId} publicada com sucesso no container live!`);
    }
  }
}

if (require.main === module) {
  syncGtmGovernance().catch(err => {
    console.error('ERRO:', err.message);
    process.exit(1);
  });
}

module.exports = { syncGtmGovernance, computeSyncActions };
