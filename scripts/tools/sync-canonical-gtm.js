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

  // 2. Obter tags, triggers e variáveis atuais
  const currentTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  const currentTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  const currentVariables = (await gtmRequest('GET', ws + '/variables')).variable || [];

  console.log(`Estado atual: ${currentTags.length} tags, ${currentTriggers.length} triggers, ${currentVariables.length} variáveis.\n`);

  const plannedActions = [];

  // A. Ação: Remoção da Tag 48 se presente
  const tag48 = currentTags.find(t => t.name.includes('Listener Conversoes Customizadas') || String(t.tagId) === '48');
  if (tag48) {
    plannedActions.push({
      type: 'DELETE_TAG',
      id: tag48.tagId,
      name: tag48.name,
      description: 'Remover Tag 48 redundante (DOM scraper depreciado em favor de emissões confirmadas via PHP/JS)'
    });
  }

  // B. Ação: Tag 47 (Assinatura Newsletter) - Inclusão de orderId para deduplicação Google Ads
  const tag47 = currentTags.find(t => String(t.tagId) === '47' || t.name === 'Google Ads - Conversão - Assinatura Newsletter');
  if (tag47) {
    const hasOrderId = (tag47.parameter || []).some(p => p.key === 'orderId' && p.value === '{{DLV - transaction_id}}');
    if (!hasOrderId) {
      plannedActions.push({
        type: 'UPDATE_TAG_ORDER_ID',
        id: tag47.tagId,
        name: tag47.name,
        target: tag47,
        description: 'Configurar orderId: {{DLV - transaction_id}} na Tag 47 para deduplicação server-side no Google Ads'
      });
    }
  }

  // C. Ação: Atualizar consentSettings em tags Google Ads awct e sp
  for (const tag of currentTags) {
    if (tag.type === 'awct' || tag.type === 'sp') {
      const needsUpdate = !tag.consentSettings || tag.consentSettings.consentStatus !== 'needed';
      if (needsUpdate) {
        plannedActions.push({
          type: 'UPDATE_TAG_CONSENT',
          id: tag.tagId,
          name: tag.name,
          target: tag,
          description: `Atualizar consentSettings para ad_storage needed na Tag ${tag.tagId} (${tag.name})`
        });
      }
    }
  }

  // D. Ação: Triggers de conversão com negate: false explícito e filtro AdOpt
  for (const tr of currentTriggers) {
    let needsNegateFix = false;
    if (tr.customEventFilter && Array.isArray(tr.customEventFilter)) {
      for (const f of tr.customEventFilter) {
        if (f.negate === true) needsNegateFix = true;
      }
    }
    if (tr.filter && Array.isArray(tr.filter)) {
      for (const f of tr.filter) {
        if (f.negate === true) needsNegateFix = true;
      }
    }

    const isCustomConversionTrigger = ['46', '50', '53', '21'].includes(String(tr.triggerId)) ||
      tr.name.includes('Assinatura Newsletter') ||
      tr.name.includes('Download Checklist') ||
      tr.name.includes('Contato via Formulário') ||
      tr.name.includes('Solicitar Orçamento');

    let needsAdoptFilter = false;
    if (isCustomConversionTrigger) {
      tr.filter = tr.filter || [];
      const hasMarketingFilter = tr.filter.some(f =>
        f.parameter && f.parameter.some(p => p.value && p.value.includes('Tags_Aceitas_AdOpt'))
      );
      if (!hasMarketingFilter) needsAdoptFilter = true;
    }

    if (needsNegateFix || needsAdoptFilter) {
      plannedActions.push({
        type: 'UPDATE_TRIGGER',
        id: tr.triggerId,
        name: tr.name,
        target: tr,
        needsNegateFix,
        needsAdoptFilter,
        description: `Corrigir trigger ${tr.triggerId} (${tr.name}): negate explícito (${needsNegateFix}) / filtro AdOpt (${needsAdoptFilter})`
      });
    }
  }

  // 3. Relatório de Ações Planejadas
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

  // 4. Se não houver --apply, encerra como DRY-RUN fail-closed
  if (!apply) {
    console.log('🛡️  NENHUMA mutação foi executada (modo DRY-RUN padrão).');
    console.log(`Para aplicar as ações acima no Workspace ${wsId}, execute:`);
    console.log(`  node scripts/tools/sync-canonical-gtm.js --workspace-id=${wsId} --apply\n`);
    return;
  }

  // 5. Execução no modo --apply
  console.log('🚀 Executando mutações planejadas no Workspace...');
  for (const act of plannedActions) {
    if (act.type === 'DELETE_TAG') {
      console.log(`- Deletando Tag ${act.id}...`);
      await gtmRequest('DELETE', ws + '/tags/' + act.id);
      console.log(`  Tag ${act.id} deletada.`);
    } else if (act.type === 'UPDATE_TAG_ORDER_ID') {
      console.log(`- Adicionando orderId na Tag ${act.id}...`);
      const tag = act.target;
      tag.parameter = tag.parameter || [];
      tag.parameter = tag.parameter.filter(p => p.key !== 'orderId');
      tag.parameter.unshift({
        type: 'template',
        key: 'orderId',
        value: '{{DLV - transaction_id}}'
      });
      await gtmRequest('PUT', ws + '/tags/' + act.id, tag);
      console.log(`  Tag ${act.id} atualizada com orderId.`);
    } else if (act.type === 'UPDATE_TAG_CONSENT') {
      console.log(`- Atualizando consentSettings na Tag ${act.id}...`);
      const tag = act.target;
      tag.consentSettings = {
        consentStatus: 'needed',
        consentType: {
          type: 'list',
          list: [{ type: 'template', value: 'ad_storage' }]
        }
      };
      await gtmRequest('PUT', ws + '/tags/' + act.id, tag);
      console.log(`  Tag ${act.id} atualizada com consentimento.`);
    } else if (act.type === 'UPDATE_TRIGGER') {
      console.log(`- Atualizando Trigger ${act.id}...`);
      const tr = act.target;
      if (tr.customEventFilter && Array.isArray(tr.customEventFilter)) {
        for (const f of tr.customEventFilter) f.negate = false;
      }
      if (tr.filter && Array.isArray(tr.filter)) {
        for (const f of tr.filter) f.negate = false;
      }
      if (act.needsAdoptFilter) {
        tr.filter = tr.filter || [];
        tr.filter.push({
          type: 'contains',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
            { type: 'template', key: 'arg1', value: 'marketing' }
          ]
        });
      }
      await gtmRequest('PUT', ws + '/triggers/' + act.id, tr);
      console.log(`  Trigger ${act.id} atualizado.`);
    }
  }

  console.log('\n✅ Todas as mutações aplicadas com sucesso no Workspace ' + wsId);

  // 6. Publicação sob demanda com dupla confirmação
  if (isPublishRequested()) {
    if (!isPublishConfirmed()) {
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
