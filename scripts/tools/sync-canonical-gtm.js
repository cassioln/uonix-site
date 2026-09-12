const fs = require('fs');
const path = require('path');
const {
  gtmRequest,
  getVerifiedWorkspaceId,
  isApplyRequested,
  isPublishRequested,
  isPublishConfirmed
} = require('./gtm-client.js');

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
  const canonicalTags = manifest.containerVersion?.tag || [];
  const canonicalTriggers = manifest.containerVersion?.trigger || [];
  const canonicalVariables = manifest.containerVersion?.variable || [];

  // Mapas canônicos por nome e ID
  const canonicalTagByName = new Map();
  const canonicalTagById = new Map();
  for (const t of canonicalTags) {
    canonicalTagByName.set(t.name, t);
    canonicalTagById.set(String(t.tagId), t);
  }

  const canonicalTriggerByName = new Map();
  const canonicalTriggerById = new Map();
  for (const tr of canonicalTriggers) {
    canonicalTriggerByName.set(tr.name, tr);
    canonicalTriggerById.set(String(tr.triggerId), tr);
  }

  // 3. Obter tags, triggers e variáveis atuais do Workspace
  const currentTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  const currentTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  const currentVariables = (await gtmRequest('GET', ws + '/variables')).variable || [];

  console.log(`Estado atual: ${currentTags.length} tags, ${currentTriggers.length} triggers, ${currentVariables.length} variáveis.`);
  console.log(`Manifesto canônico: ${canonicalTags.length} tags, ${canonicalTriggers.length} triggers, ${canonicalVariables.length} variáveis.\n`);

  const plannedActions = [];

  // A. Ação: Deletar tags que não existem no manifesto canônico (ex: Tag 48 ou scrapers não autorizados)
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

  // B. Ação: Deep Comparison de cada tag canônica autorizada
  for (const tag of currentTags) {
    const expected = canonicalTagById.get(String(tag.tagId)) || canonicalTagByName.get(tag.name);
    if (!expected) continue;

    const diffs = [];

    // Tipo de Tag
    if (tag.type !== expected.type) {
      diffs.push(`Tipo divergente: esperado "${expected.type}", atual "${tag.type}"`);
    }

    // Tag Firing Option
    if (expected.tagFiringOption && tag.tagFiringOption !== expected.tagFiringOption) {
      diffs.push(`tagFiringOption divergente: esperado "${expected.tagFiringOption}", atual "${tag.tagFiringOption}"`);
    }

    // Parâmetros essenciais
    const expParams = expected.parameter || [];
    const curParams = tag.parameter || [];
    for (const ep of expParams) {
      const cp = curParams.find(p => p.key === ep.key);
      if (!cp || cp.value !== ep.value) {
        diffs.push(`Parâmetro "${ep.key}" divergente: esperado "${ep.value}", atual "${cp?.value}"`);
      }
    }

    // Consent Settings (consentStatus e consentType estritos)
    if (expected.consentSettings) {
      const curConsentStatus = tag.consentSettings?.consentStatus;
      if (curConsentStatus !== expected.consentSettings.consentStatus) {
        diffs.push(`consentStatus divergente: esperado "${expected.consentSettings.consentStatus}", atual "${curConsentStatus}"`);
      }

      const expConsentList = (expected.consentSettings.consentType?.list || []).map(c => c.value);
      const curConsentList = (tag.consentSettings?.consentType?.list || []).map(c => c.value);
      const consentEqual = expConsentList.length === curConsentList.length &&
        expConsentList.every(val => curConsentList.includes(val));
      if (!consentEqual) {
        diffs.push(`consentType divergente: esperado [${expConsentList.join(', ')}], atual [${curConsentList.join(', ')}]`);
      }
    }

    // Firing Triggers
    if (expected.firingTriggerId) {
      const expTriggers = (expected.firingTriggerId || []).map(String);
      const curTriggers = (tag.firingTriggerId || []).map(String);
      const triggersEqual = expTriggers.length === curTriggers.length &&
        expTriggers.every(id => curTriggers.includes(id));
      if (!triggersEqual) {
        diffs.push(`firingTriggerId divergente: esperado [${expTriggers.join(', ')}], atual [${curTriggers.join(', ')}]`);
      }
    }

    if (diffs.length > 0) {
      plannedActions.push({
        type: 'UPDATE_TAG',
        id: tag.tagId,
        name: tag.name,
        target: tag,
        expected,
        differences: diffs,
        description: `Sincronizar Tag ${tag.tagId} (${tag.name}) com o manifesto canônico: ${diffs.join('; ')}`
      });
    }
  }

  // C. Ação: Deep Comparison de cada trigger de conversão
  for (const tr of currentTriggers) {
    const expected = canonicalTriggerById.get(String(tr.triggerId)) || canonicalTriggerByName.get(tr.name);
    if (!expected) continue;

    const diffs = [];

    // Tipo de trigger
    if (tr.type !== expected.type) {
      diffs.push(`Tipo de trigger divergente: esperado "${expected.type}", atual "${tr.type}"`);
    }

    // customEventFilter: checar filtros e presença obrigatória de negate: false explícito
    const expCef = expected.customEventFilter || [];
    const curCef = tr.customEventFilter || [];
    if (expCef.length > 0) {
      if (curCef.length === 0) {
        diffs.push('customEventFilter ausente no trigger atual');
      } else {
        for (const f of curCef) {
          if (f.negate !== false) {
            diffs.push(`customEventFilter sem negate:false explícito (encontrado: ${f.negate})`);
          }
        }
        const expEvt = expCef[0]?.parameter?.find(p => p.key === 'arg1')?.value;
        const curEvt = curCef[0]?.parameter?.find(p => p.key === 'arg1')?.value;
        if (expEvt && curEvt !== expEvt) {
          diffs.push(`Evento customizado divergente: esperado "${expEvt}", atual "${curEvt}"`);
        }
      }
    }

    // filter (ex: filtro AdOpt): checar filtros e presença obrigatória de negate: false explícito
    const expFilter = expected.filter || [];
    const curFilter = tr.filter || [];
    if (expFilter.length > 0) {
      if (curFilter.length === 0) {
        diffs.push('filter AdOpt ausente no trigger atual');
      } else {
        for (const f of curFilter) {
          if (f.negate !== false) {
            diffs.push(`filter sem negate:false explícito (encontrado: ${f.negate})`);
          }
        }
      }
    }

    if (diffs.length > 0) {
      plannedActions.push({
        type: 'UPDATE_TRIGGER',
        id: tr.triggerId,
        name: tr.name,
        target: tr,
        expected,
        differences: diffs,
        description: `Sincronizar Trigger ${tr.triggerId} (${tr.name}) com o manifesto canônico: ${diffs.join('; ')}`
      });
    }
  }

  // 4. Relatório de Ações Planejadas
  console.log(`📋 Total de ações identificadas: ${plannedActions.length}`);
  if (plannedActions.length === 0) {
    console.log('✅ O workspace já está 100% aderente ao contrato canônico de governança.');
    if (!isPublishRequested()) {
      return;
    }
  }

  for (let i = 0; i < plannedActions.length; i++) {
    const act = plannedActions[i];
    console.log(`  [${i + 1}] [${act.type}] ${act.name} (ID: ${act.id}): ${act.description}`);
  }
  console.log('');

  // 5. Se não houver --apply, encerra como DRY-RUN fail-closed
  if (!apply) {
    console.log('🛡️  NENHUMA mutação foi executada (modo DRY-RUN padrão).');
    if (isPublishRequested()) {
      console.warn(
        '⚠️ Flag --publish requer explicitamente a flag --apply para criar e publicar versões. ' +
        'Publicação bloqueada fail-closed.'
      );
    }
    console.log(`Para aplicar as ações acima no Workspace ${wsId}, execute:`);
    console.log(`  node scripts/tools/sync-canonical-gtm.js --workspace-id=${wsId} --apply\n`);
    return;
  }

  // 6. Execução no modo --apply
  console.log('🚀 Executando mutações planejadas no Workspace...');
  for (const act of plannedActions) {
    if (act.type === 'DELETE_TAG') {
      console.log(`- Deletando Tag não autorizada ${act.id}...`);
      await gtmRequest('DELETE', ws + '/tags/' + act.id);
      console.log(`  Tag ${act.id} deletada.`);
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
      console.log(`  Tag ${act.id} sincronizada com sucesso.`);
    } else if (act.type === 'UPDATE_TRIGGER') {
      console.log(`- Sincronizando Trigger ${act.id} com contrato canônico...`);
      const payload = {
        ...act.target,
        type: act.expected.type,
        customEventFilter: act.expected.customEventFilter || act.target.customEventFilter,
        filter: act.expected.filter || act.target.filter
      };
      await gtmRequest('PUT', ws + '/triggers/' + act.id, payload);
      console.log(`  Trigger ${act.id} sincronizado com sucesso.`);
    }
  }

  console.log('\n✅ Todas as mutações aplicadas com sucesso no Workspace ' + wsId);

  // 7. Publicação sob demanda com dupla confirmação e exigência estrita de --apply
  if (isPublishRequested()) {
    if (!apply) {
      console.warn(
        '\n[DRY-RUN] Publicação cancelada: requer a flag --apply explícita para criar e publicar versão.'
      );
    } else if (!isPublishConfirmed()) {
      console.warn(
        '\n⚠️ Publicação solicitada com --publish, mas requer confirmação explícita via --confirm-publish. ' +
        'A versão NÃO foi publicada automaticamente.'
      );
    } else {
      console.log('\n📦 Criando nova versão a partir do Workspace...');
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

module.exports = { syncGtmGovernance };
