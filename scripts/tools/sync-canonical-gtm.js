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
 * Garante:
 * 1. Ordem estrita de criação: 1º Variáveis -> 2º Triggers -> 3º Tags.
 * 2. Detecção e remoção de duplicatas sob outro ID (mesmo nome canônico replicado).
 * 3. Detecção e remoção de entidades não autorizadas.
 * 4. Verificação estrita de estado ativo (paused: false).
 * 5. Verificação estrita de parâmetros e filtros sem extras nem duplicados.
 * 6. Resolução e remapeamento de dependências de triggers para tags.
 *
 * @param {object} manifest Conteúdo do manifesto canônico
 * @param {object} currentState { tags, triggers, variables } do workspace
 * @returns {Array} Lista de ações planejadas ordenadas por dependência estrita
 */
function computeSyncActions(manifest, currentState) {
  const canonicalTags = manifest.containerVersion?.tag || [];
  const canonicalTriggers = manifest.containerVersion?.trigger || [];
  const canonicalVariables = manifest.containerVersion?.variable || [];

  const currentTags = currentState.tags || [];
  const currentTriggers = currentState.triggers || [];
  const currentVariables = currentState.variables || [];

  // Mapeamentos canônicos por nome e ID
  const canonicalTagByName = new Map(canonicalTags.map(t => [t.name, t]));
  const canonicalTagById = new Map(canonicalTags.map(t => [String(t.tagId), t]));

  const canonicalTriggerByName = new Map(canonicalTriggers.map(t => [t.name, t]));
  const canonicalTriggerById = new Map(canonicalTriggers.map(t => [String(t.triggerId), t]));

  const canonicalVariableByName = new Map(canonicalVariables.map(v => [v.name, v]));
  const canonicalVariableById = new Map(canonicalVariables.map(v => [String(v.variableId), v]));

  // Agrupamento de entidades atuais por nome para detecção de duplicatas
  const currentTagsByName = new Map();
  for (const t of currentTags) {
    if (!currentTagsByName.has(t.name)) currentTagsByName.set(t.name, []);
    currentTagsByName.get(t.name).push(t);
  }

  const currentTriggersByName = new Map();
  for (const tr of currentTriggers) {
    if (!currentTriggersByName.has(tr.name)) currentTriggersByName.set(tr.name, []);
    currentTriggersByName.get(tr.name).push(tr);
  }

  const currentVariablesByName = new Map();
  for (const v of currentVariables) {
    if (!currentVariablesByName.has(v.name)) currentVariablesByName.set(v.name, []);
    currentVariablesByName.get(v.name).push(v);
  }

  // Mapeamento primário (se houver duplicatas, prioriza ID canônico; senão o primeiro)
  const primaryTagByName = new Map();
  const duplicateTagsToDelete = [];
  for (const [name, list] of currentTagsByName.entries()) {
    const expected = canonicalTagByName.get(name);
    let primary = list[0];
    if (expected) {
      const matchId = list.find(t => String(t.tagId) === String(expected.tagId));
      if (matchId) primary = matchId;
    }
    primaryTagByName.set(name, primary);
    for (const t of list) {
      if (t !== primary) {
        duplicateTagsToDelete.push(t);
      }
    }
  }

  const primaryTriggerByName = new Map();
  const duplicateTriggersToDelete = [];
  for (const [name, list] of currentTriggersByName.entries()) {
    const expected = canonicalTriggerByName.get(name);
    let primary = list[0];
    if (expected) {
      const matchId = list.find(tr => String(tr.triggerId) === String(expected.triggerId));
      if (matchId) primary = matchId;
    }
    primaryTriggerByName.set(name, primary);
    for (const tr of list) {
      if (tr !== primary) {
        duplicateTriggersToDelete.push(tr);
      }
    }
  }

  const primaryVariableByName = new Map();
  const duplicateVariablesToDelete = [];
  for (const [name, list] of currentVariablesByName.entries()) {
    const expected = canonicalVariableByName.get(name);
    let primary = list[0];
    if (expected) {
      const matchId = list.find(v => String(v.variableId) === String(expected.variableId));
      if (matchId) primary = matchId;
    }
    primaryVariableByName.set(name, primary);
    for (const v of list) {
      if (v !== primary) {
        duplicateVariablesToDelete.push(v);
      }
    }
  }

  // Mapa de resolução de Trigger IDs: canonicalTriggerId -> actualWorkspaceTriggerId
  const triggerIdMap = new Map();
  for (const expTr of canonicalTriggers) {
    const actual = primaryTriggerByName.get(expTr.name) || currentTriggers.find(tr => String(tr.triggerId) === String(expTr.triggerId));
    if (actual) {
      triggerIdMap.set(String(expTr.triggerId), String(actual.triggerId));
    }
  }

  const actionsDeleteTags = [];
  const actionsDeleteTriggers = [];
  const actionsDeleteVariables = [];

  const actionsCreateVariables = [];
  const actionsUpdateVariables = [];

  const actionsCreateTriggers = [];
  const actionsUpdateTriggers = [];

  const actionsCreateTags = [];
  const actionsUpdateTags = [];

  // 1. Deleção de duplicatas não autorizadas por nome
  for (const tag of duplicateTagsToDelete) {
    actionsDeleteTags.push({
      type: 'DELETE_TAG',
      id: tag.tagId,
      name: tag.name,
      target: tag,
      description: `Remover tag duplicada não autorizada com mesmo nome "${tag.name}" (ID: ${tag.tagId})`
    });
  }
  for (const tr of duplicateTriggersToDelete) {
    actionsDeleteTriggers.push({
      type: 'DELETE_TRIGGER',
      id: tr.triggerId,
      name: tr.name,
      target: tr,
      description: `Remover trigger duplicado não autorizado com mesmo nome "${tr.name}" (ID: ${tr.triggerId})`
    });
  }
  for (const v of duplicateVariablesToDelete) {
    actionsDeleteVariables.push({
      type: 'DELETE_VARIABLE',
      id: v.variableId,
      name: v.name,
      target: v,
      description: `Remover variável duplicada não autorizada com mesmo nome "${v.name}" (ID: ${v.variableId})`
    });
  }

  // 2. Deleção de entidades totalmente não autorizadas (nome e ID não canônicos)
  for (const tag of currentTags) {
    if (duplicateTagsToDelete.includes(tag)) continue;
    const isAuthorized = canonicalTagByName.has(tag.name) || canonicalTagById.has(String(tag.tagId));
    if (!isAuthorized) {
      actionsDeleteTags.push({
        type: 'DELETE_TAG',
        id: tag.tagId,
        name: tag.name,
        target: tag,
        description: `Remover tag não autorizada pelo manifesto canônico (ID: ${tag.tagId}, Nome: "${tag.name}")`
      });
    }
  }
  for (const tr of currentTriggers) {
    if (duplicateTriggersToDelete.includes(tr)) continue;
    const isAuthorized = canonicalTriggerByName.has(tr.name) || canonicalTriggerById.has(String(tr.triggerId));
    if (!isAuthorized) {
      actionsDeleteTriggers.push({
        type: 'DELETE_TRIGGER',
        id: tr.triggerId,
        name: tr.name,
        target: tr,
        description: `Remover trigger não autorizado pelo manifesto canônico (ID: ${tr.triggerId}, Nome: "${tr.name}")`
      });
    }
  }
  for (const v of currentVariables) {
    if (duplicateVariablesToDelete.includes(v)) continue;
    const isAuthorized = canonicalVariableByName.has(v.name) || canonicalVariableById.has(String(v.variableId));
    if (!isAuthorized) {
      actionsDeleteVariables.push({
        type: 'DELETE_VARIABLE',
        id: v.variableId,
        name: v.name,
        target: v,
        description: `Remover variável não autorizada pelo manifesto canônico (ID: ${v.variableId}, Nome: "${v.name}")`
      });
    }
  }

  // 3. VARIÁVEIS CANÔNICAS: ausência (CREATE) ou divergência (UPDATE)
  for (const expected of canonicalVariables) {
    const current = primaryVariableByName.get(expected.name) || currentVariables.find(v => String(v.variableId) === String(expected.variableId));
    if (!current) {
      actionsCreateVariables.push({
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
    const curKeys = curParams.map(p => p.key);
    if (new Set(curKeys).size !== curKeys.length) {
      diffs.push(`Parâmetros duplicados na variável: ${curKeys.join(', ')}`);
    }
    if (curParams.length !== expParams.length) {
      diffs.push(`Quantidade de parâmetros na variável divergente: esperado ${expParams.length}, atual ${curParams.length}`);
    }
    for (const ep of expParams) {
      const cp = curParams.find(p => p.key === ep.key);
      if (!cp) {
        diffs.push(`Parâmetro da variável "${ep.key}" ausente`);
      } else {
        if (cp.type !== ep.type) diffs.push(`Tipo do parâmetro "${ep.key}" divergente: esperado "${ep.type}", atual "${cp.type}"`);
        if (cp.value !== ep.value) diffs.push(`Valor do parâmetro "${ep.key}" divergente: esperado "${ep.value}", atual "${cp.value}"`);
      }
    }
    for (const cp of curParams) {
      if (!expParams.some(ep => ep.key === cp.key)) {
        diffs.push(`Parâmetro extra não canônico "${cp.key}" na variável`);
      }
    }

    if (diffs.length > 0) {
      actionsUpdateVariables.push({
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

  // 4. TRIGGERS CANÔNICOS: ausência (CREATE) ou divergência (UPDATE)
  for (const expected of canonicalTriggers) {
    const current = primaryTriggerByName.get(expected.name) || currentTriggers.find(tr => String(tr.triggerId) === String(expected.triggerId));
    if (!current) {
      actionsCreateTriggers.push({
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
        const cKeys = cParams.map(p => p.key);
        if (new Set(cKeys).size !== cKeys.length) diffs.push(`Parâmetros duplicados em customEventFilter[${i}]: ${cKeys.join(', ')}`);
        if (cParams.length !== eParams.length) diffs.push(`Quantidade de parâmetros em customEventFilter[${i}] divergente: esperado ${eParams.length}, atual ${cParams.length}`);
        for (const ep of eParams) {
          const cp = cParams.find(p => p.key === ep.key);
          if (!cp || cp.value !== ep.value || cp.type !== ep.type) {
            diffs.push(`customEventFilter[${i}] parâmetro "${ep.key}" divergente: esperado ${JSON.stringify(ep)}, atual ${JSON.stringify(cp)}`);
          }
        }
        for (const cp of cParams) {
          if (!eParams.some(ep => ep.key === cp.key)) {
            diffs.push(`customEventFilter[${i}] parâmetro extra "${cp.key}" não autorizado`);
          }
        }
      }
    }

    // filter (AdOpt)
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
        const cKeys = cParams.map(p => p.key);
        if (new Set(cKeys).size !== cKeys.length) diffs.push(`Parâmetros duplicados em filter[${i}]: ${cKeys.join(', ')}`);
        if (cParams.length !== eParams.length) diffs.push(`Quantidade de parâmetros em filter[${i}] divergente: esperado ${eParams.length}, atual ${cParams.length}`);
        for (const ep of eParams) {
          const cp = cParams.find(p => p.key === ep.key);
          if (!cp || cp.value !== ep.value || cp.type !== ep.type) {
            diffs.push(`filter[${i}] parâmetro "${ep.key}" divergente: esperado ${JSON.stringify(ep)}, atual ${JSON.stringify(cp)}`);
          }
        }
        for (const cp of cParams) {
          if (!eParams.some(ep => ep.key === cp.key)) {
            diffs.push(`filter[${i}] parâmetro extra "${cp.key}" não autorizado`);
          }
        }
      }
    }

    if (diffs.length > 0) {
      actionsUpdateTriggers.push({
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

  // 5. TAGS CANÔNICAS: ausência (CREATE) ou divergência (UPDATE)
  for (const expected of canonicalTags) {
    const current = primaryTagByName.get(expected.name) || currentTags.find(t => String(t.tagId) === String(expected.tagId));
    if (!current) {
      actionsCreateTags.push({
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
    if (current.paused === true) {
      diffs.push(`Tag está pausada (paused: true); esperado ativa (paused: false)`);
    }
    const expFiring = expected.tagFiringOption || 'oncePerEvent';
    if ((current.tagFiringOption || 'oncePerEvent') !== expFiring) {
      diffs.push(`tagFiringOption divergente: esperado "${expFiring}", atual "${current.tagFiringOption}"`);
    }

    // Parâmetros
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

    // Firing Triggers (com remapeamento de ID se os triggers do workspace tiverem IDs diferentes do snapshot)
    if (expected.firingTriggerId) {
      const expTriggers = (expected.firingTriggerId || []).map(id => triggerIdMap.get(String(id)) || String(id)).sort();
      const curTriggers = (current.firingTriggerId || []).map(String).sort();
      if (expTriggers.length !== curTriggers.length || !expTriggers.every((id, idx) => id === curTriggers[idx])) {
        diffs.push(`firingTriggerId divergente: esperado [${expTriggers.join(', ')}], atual [${curTriggers.join(', ')}]`);
      }
    }

    if (diffs.length > 0) {
      actionsUpdateTags.push({
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

  // ORDEM ESTRITA DE DEPENDÊNCIAS:
  // 1. DELETE Tags não autorizadas / duplicadas
  // 2. DELETE Triggers não autorizados / duplicados
  // 3. DELETE Variáveis não autorizadas / duplicadas
  // 4. CREATE Variáveis
  // 5. UPDATE Variáveis
  // 6. CREATE Triggers
  // 7. UPDATE Triggers
  // 8. CREATE Tags
  // 9. UPDATE Tags
  return [
    ...actionsDeleteTags,
    ...actionsDeleteTriggers,
    ...actionsDeleteVariables,
    ...actionsCreateVariables,
    ...actionsUpdateVariables,
    ...actionsCreateTriggers,
    ...actionsUpdateTriggers,
    ...actionsCreateTags,
    ...actionsUpdateTags
  ];
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

  // 6. Execução no modo --apply em ordem estrita de dependências
  console.log('🚀 Executando mutações planejadas no Workspace...');

  // Mapeamento dinâmico de Trigger IDs: canonicalTriggerId -> actualWorkspaceTriggerId
  const runtimeTriggerIdMap = new Map();
  for (const tr of currentTriggers) {
    const canonical = (manifest.containerVersion?.trigger || []).find(ct => ct.name === tr.name || String(ct.triggerId) === String(tr.triggerId));
    if (canonical) {
      runtimeTriggerIdMap.set(String(canonical.triggerId), String(tr.triggerId));
    }
  }

  const existingTriggerIds = new Set(currentTriggers.map(tr => String(tr.triggerId)));

  for (const act of plannedActions) {
    if (act.type === 'DELETE_TAG') {
      console.log(`- Deletando Tag ${act.id} ("${act.name}")...`);
      await gtmRequest('DELETE', ws + '/tags/' + act.id);
    } else if (act.type === 'DELETE_TRIGGER') {
      console.log(`- Deletando Trigger ${act.id} ("${act.name}")...`);
      await gtmRequest('DELETE', ws + '/triggers/' + act.id);
      existingTriggerIds.delete(String(act.id));
    } else if (act.type === 'DELETE_VARIABLE') {
      console.log(`- Deletando Variável ${act.id} ("${act.name}")...`);
      await gtmRequest('DELETE', ws + '/variables/' + act.id);
    } else if (act.type === 'CREATE_VARIABLE') {
      console.log(`- Criando Variável canônica "${act.name}"...`);
      const payload = {
        name: act.expected.name,
        type: act.expected.type,
        parameter: act.expected.parameter
      };
      await gtmRequest('POST', ws + '/variables', payload);
    } else if (act.type === 'UPDATE_VARIABLE') {
      console.log(`- Sincronizando Variável ${act.id} com contrato canônico...`);
      const payload = {
        ...act.target,
        type: act.expected.type,
        parameter: act.expected.parameter
      };
      await gtmRequest('PUT', ws + '/variables/' + act.id, payload);
    } else if (act.type === 'CREATE_TRIGGER') {
      console.log(`- Criando Trigger canônico "${act.name}"...`);
      const payload = {
        name: act.expected.name,
        type: act.expected.type,
        customEventFilter: act.expected.customEventFilter,
        filter: act.expected.filter
      };
      const created = await gtmRequest('POST', ws + '/triggers', payload);
      const newTriggerId = String(created?.triggerId || act.expected.triggerId);
      runtimeTriggerIdMap.set(String(act.expected.triggerId), newTriggerId);
      existingTriggerIds.add(newTriggerId);
    } else if (act.type === 'UPDATE_TRIGGER') {
      console.log(`- Sincronizando Trigger ${act.id} com contrato canônico...`);
      const payload = {
        ...act.target,
        type: act.expected.type,
        customEventFilter: act.expected.customEventFilter || act.target.customEventFilter,
        filter: act.expected.filter || act.target.filter
      };
      await gtmRequest('PUT', ws + '/triggers/' + act.id, payload);
      runtimeTriggerIdMap.set(String(act.expected.triggerId), String(act.id));
      existingTriggerIds.add(String(act.id));
    } else if (act.type === 'CREATE_TAG') {
      console.log(`- Criando Tag canônica "${act.name}"...`);
      const resolvedTriggers = (act.expected.firingTriggerId || []).map(id => runtimeTriggerIdMap.get(String(id)) || String(id));
      for (const tid of resolvedTriggers) {
        if (!existingTriggerIds.has(String(tid))) {
          throw new Error(`[FAIL-CLOSED] Trigger ID ${tid} referenciado pela tag "${act.name}" não existe no workspace! Abortando mutação.`);
        }
      }
      const payload = {
        name: act.expected.name,
        type: act.expected.type,
        parameter: act.expected.parameter,
        tagFiringOption: act.expected.tagFiringOption || 'oncePerEvent',
        paused: false,
        consentSettings: act.expected.consentSettings,
        firingTriggerId: resolvedTriggers
      };
      await gtmRequest('POST', ws + '/tags', payload);
    } else if (act.type === 'UPDATE_TAG') {
      console.log(`- Sincronizando Tag ${act.id} com contrato canônico...`);
      const expTriggers = act.expected.firingTriggerId || act.target.firingTriggerId;
      const resolvedTriggers = expTriggers ? expTriggers.map(id => runtimeTriggerIdMap.get(String(id)) || String(id)) : undefined;
      if (resolvedTriggers) {
        for (const tid of resolvedTriggers) {
          if (!existingTriggerIds.has(String(tid))) {
            throw new Error(`[FAIL-CLOSED] Trigger ID ${tid} referenciado pela tag "${act.name}" não existe no workspace! Abortando mutação.`);
          }
        }
      }
      const payload = {
        ...act.target,
        type: act.expected.type,
        parameter: act.expected.parameter,
        tagFiringOption: act.expected.tagFiringOption || act.target.tagFiringOption || 'oncePerEvent',
        paused: false,
        consentSettings: act.expected.consentSettings || act.target.consentSettings,
        firingTriggerId: resolvedTriggers
      };
      await gtmRequest('PUT', ws + '/tags/' + act.id, payload);
    }
  }

  console.log('\n✅ Mutações enviadas. Iniciando READBACK pós-aplicação no Workspace ' + wsId + '...');

  // 7. READBACK OBRIGATÓRIO: re-consulta o workspace e certifica 100% de paridade antes de qualquer publicação
  const freshTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  const freshTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  const freshVariables = (await gtmRequest('GET', ws + '/variables')).variable || [];

  const readbackErrors = [];
  for (const tag of freshTags) {
    if (tag.paused === true) {
      readbackErrors.push(`Tag "${tag.name}" (ID: ${tag.tagId}) está pausada no workspace`);
    }
  }

  const remainingActions = computeSyncActions(manifest, {
    tags: freshTags,
    triggers: freshTriggers,
    variables: freshVariables
  });

  if (remainingActions.length > 0 || readbackErrors.length > 0) {
    console.error(`\n[FAIL-CLOSED] Readback pós-aplicação falhou!`);
    for (const ra of remainingActions) {
      console.error(`  - Ação pendente: [${ra.type}] ${ra.name}: ${ra.description}`);
    }
    for (const err of readbackErrors) {
      console.error(`  - Erro estrutural: ${err}`);
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
