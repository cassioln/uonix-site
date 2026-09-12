const {
  gtmRequest,
  getVerifiedWorkspaceId,
  isApplyRequested,
  isPublishRequested,
  isPublishConfirmed
} = require('./gtm-client.js');

const META_PIXEL_ID = '461430774437593';

/**
 * Definições canônicas de tags de conversão do Meta Pixel (Facebook/Instagram Ads).
 */
const EXPECTED_META_TAGS = [
  {
    name: 'Meta Pixel - Conversão - Solicitar Orçamento',
    triggerName: 'Evento - Solicitar Orçamento Uônix',
    fallbackTriggerId: '21',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  var txId = {{DLV - transaction_id}} || undefined;
  fbq('track', 'Lead', {
    content_name: 'Solicitação de Orçamento Ancoragem Predial',
    content_category: 'Orçamento B2B',
    currency: 'BRL',
    value: 0
  }, {
    eventID: txId
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Contato Formulário',
    triggerName: 'Evento - Contato via Formulário',
    fallbackTriggerId: '53',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('track', 'Contact', {
    content_name: 'Contato via Formulário Institucional',
    content_category: 'Contato'
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Download Checklist Técnico',
    triggerName: 'Evento - Download Checklist Técnico',
    fallbackTriggerId: '50',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('track', 'CompleteRegistration', {
    content_name: 'Checklist Técnico de Ancoragem Predial',
    status: true
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Assinatura Newsletter',
    triggerName: 'Evento - Assinatura Newsletter Uônix',
    fallbackTriggerId: '46',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  var txId = {{DLV - transaction_id}} || undefined;
  fbq('track', 'Subscribe', {
    content_name: 'Newsletter Uônix',
    currency: 'BRL',
    value: 0
  }, {
    eventID: txId
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Adicionar ao Carrinho',
    triggerName: 'Evento - Adicionar ao Carrinho Uônix',
    fallbackTriggerId: '55',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('track', 'AddToCart', {
    content_name: 'Produto Uônix',
    content_type: 'product'
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Iniciar Finalização',
    triggerName: 'Evento - Iniciar Finalização Uônix',
    fallbackTriggerId: '56',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('track', 'InitiateCheckout', {
    content_category: 'Orçamento de Ancoragem Predial'
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - WhatsApp',
    triggerName: 'Clique - WhatsApp Links',
    fallbackTriggerId: '10',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('trackCustom', 'Clique_WhatsApp_Uonix', { channel: 'whatsapp' });
  fbq('track', 'Contact', {
    content_name: 'Contato WhatsApp',
    content_category: 'WhatsApp'
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Telefone',
    triggerName: 'Clique - Telefone tel',
    fallbackTriggerId: '36',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('track', 'Contact', {
    content_name: 'Contato Telefone',
    content_category: 'Telefone'
  });
})();
</script>`
  },
  {
    name: 'Meta Pixel - Conversão - Email',
    triggerName: 'Clique - Email mailto',
    fallbackTriggerId: '37',
    html: `<script>
(function() {
  if (typeof fbq !== 'function') return;
  fbq('track', 'Contact', {
    content_name: 'Contato Email',
    content_category: 'Email'
  });
})();
</script>`
  }
];

function normalizeHtml(html) {
  return String(html || '').replace(/\r\n/g, '\n').trim();
}

function validateMetaHtmlTag(currentTag, expectedDef, triggerId) {
  const diffs = [];
  if (!currentTag) return { isAdherent: false, differences: ['Tag inexistente'] };
  if (currentTag.type !== 'html') diffs.push(`Tipo divergente: esperado "html", encontrado "${currentTag.type}"`);
  if (currentTag.paused === true) diffs.push('Tag está pausada');

  const curHtmlParam = (currentTag.parameter || []).find(p => p.key === 'html')?.value;
  if (normalizeHtml(curHtmlParam) !== normalizeHtml(expectedDef.html)) {
    diffs.push('Snippet HTML do Meta Pixel diverge da definição canônica');
  }

  const curTriggers = (currentTag.firingTriggerId || []).map(String).sort();
  const expectedTriggers = [String(triggerId)];
  if (curTriggers.length !== expectedTriggers.length || curTriggers[0] !== expectedTriggers[0]) {
    diffs.push(`firingTriggerId divergente: esperado ${JSON.stringify(expectedTriggers)}, encontrado ${JSON.stringify(curTriggers)}`);
  }

  const consent = currentTag.consentSettings;
  if (!consent || consent.consentStatus !== 'needed') {
    diffs.push('consentSettings.consentStatus deve ser "needed"');
  } else {
    const list = (consent.consentType && consent.consentType.list) || [];
    const hasAdStorage = list.some(i => i.value === 'ad_storage');
    if (!hasAdStorage) diffs.push('consentType deve exigir "ad_storage"');
  }

  return { isAdherent: diffs.length === 0, differences: diffs };
}

function findExactlyOneByName(entities, name, entityType, errors) {
  const matches = entities.filter(entity => entity.name === name);
  if (matches.length !== 1) {
    errors.push(`${entityType} "${name}" deve existir exatamente uma vez; encontrado: ${matches.length}`);
    return null;
  }
  return matches[0];
}

function validateMetaPixelReadback({ variables, triggers, tags }) {
  const errors = [];
  const pixelVariable = findExactlyOneByName(variables, 'Constante - Meta Pixel ID', 'Variável', errors);
  if (pixelVariable) {
    const pixelValue = (pixelVariable.parameter || []).find(param => param.key === 'value')?.value;
    if (pixelVariable.type !== 'c' || pixelValue !== META_PIXEL_ID) {
      errors.push('Variável "Constante - Meta Pixel ID" diverge do contrato canônico');
    }
  }

  const pageView = findExactlyOneByName(tags, 'Facebook Pixel - PageView', 'Tag', errors);
  if (!pageView || pageView.type !== 'html' || pageView.paused === true) {
    errors.push('Tag "Facebook Pixel - PageView" ausente, divergente ou pausada');
  }

  const triggersByName = new Map();
  for (const trigger of triggers) {
    if (!triggersByName.has(trigger.name)) triggersByName.set(trigger.name, []);
    triggersByName.get(trigger.name).push(trigger);
  }

  for (const tagDef of EXPECTED_META_TAGS) {
    const triggerMatches = triggersByName.get(tagDef.triggerName) || [];
    if (triggerMatches.length !== 1) {
      errors.push(`Trigger "${tagDef.triggerName}" deve existir exatamente uma vez; encontrado: ${triggerMatches.length}`);
      continue;
    }

    const serverTag = findExactlyOneByName(tags, tagDef.name, 'Tag', errors);
    if (serverTag) {
      const validation = validateMetaHtmlTag(serverTag, tagDef, String(triggerMatches[0].triggerId));
      if (!validation.isAdherent) {
        errors.push(`Tag "${tagDef.name}" diverge: ${validation.differences.join('; ')}`);
      }
    }
  }

  return errors;
}

async function main(options = {}) {
  const accountId = '6348960683';
  const containerId = '248910884';
  const base = `/accounts/${accountId}/containers/${containerId}`;

  const requestFn = options.customGtmRequest || gtmRequest;
  const apply = options.apply !== undefined ? options.apply : isApplyRequested();
  const publishRequested = options.publish !== undefined ? options.publish : isPublishRequested();
  const publishConfirmed = options.confirmPublish !== undefined ? options.confirmPublish : isPublishConfirmed();
  const throwOnError = options.throwOnError === true;

  // 1. Obter e validar Workspace
  let wsId;
  let wsName = 'default';
  if (options.workspaceId) {
    wsId = options.workspaceId;
    wsName = options.workspaceName || wsId;
  } else {
    const wsObj = await getVerifiedWorkspaceId(accountId, containerId);
    wsId = wsObj.workspaceId;
    wsName = wsObj.name;
  }
  const ws = `${base}/workspaces/${wsId}`;

  console.log('========================================================================');
  console.log(`🌐 SETUP CONVERSÕES META PIXEL — WORKSPACE ${wsId} ("${wsName}")`);
  console.log(`MODO: ${apply ? 'APLICAR MUTAÇÕES (--apply)' : 'DRY-RUN (somente auditoria/planejamento)'}`);
  console.log('========================================================================\n');

  const journal = [];

  try {
    // 2. Variável Canônica: Constante - Meta Pixel ID
    console.log('1. Verificando variável Constante - Meta Pixel ID...');
    const existingVars = (await requestFn('GET', ws + '/variables')).variable || [];
    let pixelVar = existingVars.find(v => v.name === 'Constante - Meta Pixel ID');

    if (!pixelVar) {
      if (!apply) {
        console.log('  [DRY-RUN] Variável não existe. Seria criada com valor:', META_PIXEL_ID);
      } else {
        const varData = {
          name: 'Constante - Meta Pixel ID',
          type: 'c',
          parameter: [{ type: 'template', key: 'value', value: META_PIXEL_ID }]
        };
        const journalEntry = { op: 'CREATE', type: 'variables', id: null, name: 'Constante - Meta Pixel ID' };
        journal.push(journalEntry);
        pixelVar = await requestFn('POST', ws + '/variables', varData);
        if (!pixelVar || !pixelVar.variableId) {
          throw new Error('[FAIL-CLOSED] POST da variável Meta Pixel não retornou variableId; rollback seguro impossível.');
        }
        journalEntry.id = String(pixelVar.variableId);
        console.log('  Variável criada com ID:', pixelVar.variableId);
      }
    } else {
      const curVal = (pixelVar.parameter || []).find(p => p.key === 'value')?.value;
      if (curVal !== META_PIXEL_ID) {
        console.log(`  [DIVERGÊNCIA] Variável ID ${pixelVar.variableId} possui valor '${curVal}' (esperado: '${META_PIXEL_ID}')`);
        if (apply) {
          journal.push({ op: 'UPDATE', type: 'variables', id: String(pixelVar.variableId), name: pixelVar.name, previousPayload: JSON.parse(JSON.stringify(pixelVar)) });
          pixelVar.parameter = [{ type: 'template', key: 'value', value: META_PIXEL_ID }];
          await requestFn('PUT', ws + '/variables/' + pixelVar.variableId, pixelVar);
          console.log('  Variável corrigida com sucesso.');
        }
      } else {
        console.log(`  Variável existe e está 100% aderente (ID: ${pixelVar.variableId}).`);
      }
    }

    // 3. Mapear Triggers Existentes
    const existingTriggers = (await requestFn('GET', ws + '/triggers')).trigger || [];
    const triggerByName = new Map(existingTriggers.map(t => [t.name, t]));

    // 4. Tags de Conversão do Meta Pixel
    console.log('\n2. Verificando tags de conversão do Meta Pixel...');
    const existingTags = (await requestFn('GET', ws + '/tags')).tag || [];

    for (const tagDef of EXPECTED_META_TAGS) {
      const matchedTrigger = triggerByName.get(tagDef.triggerName);
      const firingId = matchedTrigger ? String(matchedTrigger.triggerId) : tagDef.fallbackTriggerId;

      const expectedTagPayload = {
        name: tagDef.name,
        type: 'html',
        parameter: [
          { type: 'template', key: 'html', value: tagDef.html },
          { type: 'boolean', key: 'supportDocumentWrite', value: 'false' }
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

      let existingTag = existingTags.find(t => t.name === tagDef.name);

      if (!existingTag) {
        if (!apply) {
          console.log(`  [DRY-RUN] Tag "${tagDef.name}" não existe. Seria criada.`);
        } else {
          const journalEntry = { op: 'CREATE', type: 'tags', id: null, name: tagDef.name };
          journal.push(journalEntry);
          existingTag = await requestFn('POST', ws + '/tags', expectedTagPayload);
          if (!existingTag || !existingTag.tagId) {
            throw new Error(`[FAIL-CLOSED] POST da tag "${tagDef.name}" não retornou tagId; rollback seguro impossível.`);
          }
          journalEntry.id = String(existingTag.tagId);
          console.log(`  Tag "${tagDef.name}" criada com ID: ${existingTag.tagId}`);
        }
      } else {
        const val = validateMetaHtmlTag(existingTag, tagDef, firingId);
        if (!val.isAdherent) {
          console.log(`  [DIVERGÊNCIA] Tag ID ${existingTag.tagId} ("${tagDef.name}") diverge do contrato canônico:`);
          val.differences.forEach(d => console.log(`    - ${d}`));
          if (apply) {
            journal.push({ op: 'UPDATE', type: 'tags', id: String(existingTag.tagId), name: existingTag.name, previousPayload: JSON.parse(JSON.stringify(existingTag)) });
            existingTag.type = expectedTagPayload.type;
            existingTag.parameter = expectedTagPayload.parameter;
            existingTag.firingTriggerId = expectedTagPayload.firingTriggerId;
            existingTag.tagFiringOption = expectedTagPayload.tagFiringOption;
            existingTag.consentSettings = expectedTagPayload.consentSettings;
            await requestFn('PUT', ws + '/tags/' + existingTag.tagId, existingTag);
            console.log(`  Tag "${tagDef.name}" atualizada para o contrato canônico.`);
          }
        } else {
          console.log(`  Tag "${tagDef.name}" existe e está 100% aderente (ID: ${existingTag.tagId}).`);
        }
      }
    }

    // 5. Readback pós-aplicação no escopo transacional
    if (apply) {
      console.log('\n🔍 Realizando READBACK pós-aplicação do servidor GTM...');
      const freshTags = (await requestFn('GET', ws + '/tags')).tag || [];
      const freshTriggers = (await requestFn('GET', ws + '/triggers')).trigger || [];
      const freshVariables = (await requestFn('GET', ws + '/variables')).variable || [];
      const readbackErrors = validateMetaPixelReadback({
        variables: freshVariables,
        triggers: freshTriggers,
        tags: freshTags
      });

      if (readbackErrors.length > 0) {
        throw new Error(`[FAIL-CLOSED] Readback pós-aplicação falhou: ${readbackErrors.join('; ')}`);
      }
      console.log('🛡️  READBACK CONFIRMADO: Todas as tags do Meta Pixel verificadas com paridade integral no servidor GTM!');
    }
  } catch (err) {
    console.error(`\n🚨 [FAIL-CLOSED] Erro no ciclo de mutação do Meta Pixel: ${err.message}`);
    console.error('🔄 Iniciando ROLLBACK TRANSACIONAL compensatório...');

    async function resolveAmbiguousCreate(entry) {
      if (entry.id) return true;
      const response = await requestFn('GET', `${ws}/${entry.type}`);
      const collectionName = entry.type.slice(0, -1);
      const matches = (response[collectionName] || []).filter(entity => entity.name === entry.name);
      if (matches.length === 0) return false;
      if (matches.length !== 1 || !matches[0][entry.type === 'variables' ? 'variableId' : 'tagId']) {
        throw new Error(`não foi possível resolver CREATE ambíguo de ${entry.type} "${entry.name}"`);
      }
      entry.id = String(matches[0][entry.type === 'variables' ? 'variableId' : 'tagId']);
      return true;
    }

    for (let i = journal.length - 1; i >= 0; i--) {
      const entry = journal[i];
      try {
        if (entry.op === 'CREATE') {
          if (!entry.id) {
            const persisted = await resolveAmbiguousCreate(entry);
            if (!persisted) continue;
          }
          console.warn(`  [ROLLBACK] Removendo entidade ${entry.type}/${entry.id} ("${entry.name}")...`);
          await requestFn('DELETE', `${ws}/${entry.type}/${entry.id}`);
        } else if (entry.op === 'UPDATE') {
          console.warn(`  [ROLLBACK] Restaurando snapshot de ${entry.type}/${entry.id} ("${entry.name}")...`);
          await requestFn('PUT', `${ws}/${entry.type}/${entry.id}`, entry.previousPayload);
        }
      } catch (rbErr) {
        console.error(`  [ERRO NO ROLLBACK] Falha ao compensar ${entry.type}/${entry.id}: ${rbErr.message}`);
      }
    }

    if (throwOnError) throw err;
    process.exit(1);
  }

  // 6. Publicação sob demanda com dupla confirmação
  if (publishRequested) {
    if (!apply) {
      console.warn('\n[DRY-RUN] Flag --publish requer --apply para executar criação e publicação de versão.');
    } else if (!publishConfirmed) {
      console.warn('\n⚠️ Flag --publish fornecida, mas requer --confirm-publish para publicar live.');
    } else {
      console.log('\n📦 Criando e publicando versão no GTM com Meta Pixel integrado...');
      const resVer = await requestFn('POST', ws + ':create_version', {
        name: 'v32 - Implantação Completa Microconversões Meta Pixel',
        notes: 'Adiciona tags de conversão do Meta Pixel com deduplicação eventID e conformidade LGPD estrita.'
      });
      const versionId = resVer.containerVersion?.containerVersionId;
      console.log('Versão criada:', versionId);
      await requestFn('POST', base + '/versions/' + versionId + ':publish');
      console.log(`🎉 Versão ${versionId} publicada com sucesso no GTM live!`);
    }
  } else {
    console.log('\nPublicação live não solicitada. Para aplicar e publicar, use: --apply --publish --confirm-publish');
  }
}

if (require.main === module) {
  main().catch(err => {
    console.error('ERRO:', err.message);
    process.exit(1);
  });
}

module.exports = {
  main,
  EXPECTED_META_TAGS,
  validateMetaHtmlTag,
  validateMetaPixelReadback
};
