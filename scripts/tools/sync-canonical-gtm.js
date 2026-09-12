const fs = require('fs');
const path = require('path');
const { gtmRequest } = require('./gtm-client.js');

async function syncGtmGovernance() {
  const base = '/accounts/6348960683/containers/248910884';
  const wsList = await gtmRequest('GET', base + '/workspaces');
  const wsId = wsList.workspace[0].workspaceId;
  const ws = base + '/workspaces/' + wsId;
  console.log('Using workspace:', wsId);

  // 1. Obter tags, triggers e variáveis atuais
  const currentTags = (await gtmRequest('GET', ws + '/tags')).tag || [];
  const currentTriggers = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  const currentVariables = (await gtmRequest('GET', ws + '/variables')).variable || [];

  console.log(`Current: ${currentTags.length} tags, ${currentTriggers.length} triggers, ${currentVariables.length} variables.`);

  // 2. Remover Tag 48 (DOM scraper redundante que duplica eventos emitidos pelo PHP)
  const tag48 = currentTags.find(t => t.name.includes('Listener Conversoes Customizadas') || t.tagId === '48');
  if (tag48) {
    console.log('Removendo Tag 48 redundante...');
    try {
      await gtmRequest('DELETE', ws + '/tags/' + tag48.tagId);
      console.log('Tag 48 removida com sucesso.');
    } catch (e) {
      console.warn('Falha ao remover Tag 48:', e.message);
    }
  }

  // 3. Atualizar consentSettings em TODAS as tags Google Ads awct e sp
  const tagsParaAtualizarConsent = (await gtmRequest('GET', ws + '/tags')).tag || [];
  for (const tag of tagsParaAtualizarConsent) {
    if (tag.type === 'awct' || tag.type === 'sp') {
      const needsUpdate = !tag.consentSettings || tag.consentSettings.consentStatus !== 'needed';
      if (needsUpdate) {
        console.log(`Atualizando consentSettings da tag ${tag.tagId} (${tag.name})...`);
        tag.consentSettings = {
          consentStatus: 'needed',
          consentType: {
            type: 'list',
            list: [
              { type: 'template', value: 'ad_storage' }
            ]
          }
        };
        await gtmRequest('PUT', ws + '/tags/' + tag.tagId, tag);
        console.log(`Tag ${tag.tagId} atualizada com ad_storage needed.`);
      }
    }
  }

  // 4. Garantir negate: false explícito em todos os filtros de triggers de eventos customizados
  const triggersParaAtualizar = (await gtmRequest('GET', ws + '/triggers')).trigger || [];
  for (const tr of triggersParaAtualizar) {
    let changed = false;
    if (tr.customEventFilter && Array.isArray(tr.customEventFilter)) {
      for (const f of tr.customEventFilter) {
        if (f.negate !== false) {
          f.negate = false;
          changed = true;
        }
      }
    }
    if (tr.filter && Array.isArray(tr.filter)) {
      for (const f of tr.filter) {
        if (f.negate !== false) {
          f.negate = false;
          changed = true;
        }
      }
    }

    // Se for trigger de conversão (ex: newsletter, download, contato), garantir filtro de AdOpt marketing
    const isCustomConversionTrigger = ['46', '50', '53', '21'].includes(String(tr.triggerId)) ||
      tr.name.includes('Assinatura Newsletter') ||
      tr.name.includes('Download Checklist') ||
      tr.name.includes('Contato via Formulário') ||
      tr.name.includes('Solicitar Orçamento');

    if (isCustomConversionTrigger) {
      tr.filter = tr.filter || [];
      const hasMarketingFilter = tr.filter.some(f => 
        f.parameter && f.parameter.some(p => p.value && p.value.includes('Tags_Aceitas_AdOpt'))
      );
      if (!hasMarketingFilter) {
        tr.filter.push({
          type: 'contains',
          negate: false,
          parameter: [
            { type: 'template', key: 'arg0', value: '{{Tags_Aceitas_AdOpt}}' },
            { type: 'template', key: 'arg1', value: 'marketing' }
          ]
        });
        changed = true;
      }
    }

    if (changed) {
      console.log(`Atualizando trigger ${tr.triggerId} (${tr.name}) com negate: false e regras AdOpt...`);
      await gtmRequest('PUT', ws + '/triggers/' + tr.triggerId, tr);
      console.log(`Trigger ${tr.triggerId} atualizado.`);
    }
  }

  console.log('Sincronização de governança no workspace concluída com sucesso.');
}

if (require.main === module) {
  syncGtmGovernance().catch(err => {
    console.error('Erro na sincronização:', err);
    process.exit(1);
  });
}

module.exports = { syncGtmGovernance };
