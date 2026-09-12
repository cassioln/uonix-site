const fs = require('fs');
const crypto = require('crypto');
const https = require('https');
const dns = require('dns');

function customLookup(hostname, options, callback) {
  if (typeof options === 'function') { callback = options; options = {}; }
  const isAll = options && options.all;
  const resolver = new dns.Resolver();
  resolver.setServers(['8.8.8.8', '1.1.1.1']);
  resolver.resolve4(hostname, (err, addrs) => {
    if (!err && addrs && addrs.length) {
      if (isAll) return callback(null, addrs.map(a => ({ address: a, family: 4 })));
      return callback(null, addrs[0], 4);
    }
    dns.lookup(hostname, options, callback);
  });
}

function getKey() {
  const keyPath = process.env.GTM_KEY_PATH || (process.env.HOME + '/.config/gcloud/gtm-automation-key.json');
  if (!fs.existsSync(keyPath)) {
    throw new Error(`[FAIL-CLOSED] Chave de automação GTM não encontrada em: ${keyPath}`);
  }
  return JSON.parse(fs.readFileSync(keyPath, 'utf8'));
}

function base64url(str) {
  return Buffer.from(str).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function getAccessToken() {
  const key = getKey();
  return new Promise((resolve, reject) => {
    const now = Math.floor(Date.now() / 1000);
    const header = { alg: 'RS256', typ: 'JWT' };
    const claimSet = {
      iss: key.client_email,
      scope: 'https://www.googleapis.com/auth/tagmanager.edit.containers https://www.googleapis.com/auth/tagmanager.edit.containerversions https://www.googleapis.com/auth/tagmanager.publish https://www.googleapis.com/auth/tagmanager.readonly',
      aud: key.token_uri,
      exp: now + 3600,
      iat: now
    };

    const signInput = `${base64url(JSON.stringify(header))}.${base64url(JSON.stringify(claimSet))}`;
    const sign = crypto.createSign('RSA-SHA256');
    sign.update(signInput);
    const sig = sign.sign(key.private_key, 'base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const jwt = `${signInput}.${sig}`;

    const postData = `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=${jwt}`;

    const req = https.request(key.token_uri, {
      method: 'POST',
      lookup: customLookup,
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': postData.length
      }
    }, (res) => {
      let body = '';
      res.on('data', c => body += c);
      res.on('end', () => {
        try {
          const data = JSON.parse(body);
          if (data.access_token) resolve(data.access_token);
          else reject(new Error('No access_token: ' + body));
        } catch (e) {
          reject(e);
        }
      });
    });
    req.on('error', reject);
    req.write(postData);
    req.end();
  });
}

async function gtmRequest(method, path, data = null) {
  const token = await getAccessToken();
  return new Promise((resolve, reject) => {
    const url = `https://tagmanager.googleapis.com/tagmanager/v2${path}`;
    const options = {
      method: method,
      lookup: customLookup,
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    };

    const req = https.request(url, options, (res) => {
      let body = '';
      res.on('data', c => body += c);
      res.on('end', () => {
        try {
          const parsed = body ? JSON.parse(body) : {};
          if (res.statusCode >= 200 && res.statusCode < 300) {
            resolve(parsed);
          } else {
            reject(new Error(`GTM API Error ${res.statusCode}: ${JSON.stringify(parsed)}`));
          }
        } catch (e) {
          if (res.statusCode >= 200 && res.statusCode < 300) resolve(body);
          else reject(new Error(`GTM API Error ${res.statusCode}: ${body}`));
        }
      });
    });
    req.on('error', reject);
    if (data) req.write(JSON.stringify(data));
    req.end();
  });
}

/**
 * Obtém e valida explicitamente o Workspace ID para impedir mutações cegas em workspaces incorretos.
 * Exige --workspace-id=<id> ou GTM_WORKSPACE_ID.
 */
async function getVerifiedWorkspaceId(accountId = '6348960683', containerId = '248910884') {
  let targetWsId = null;

  for (let i = 0; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--workspace-id=')) {
      targetWsId = arg.split('=')[1].trim();
    } else if (arg === '-w' || arg === '--workspace-id') {
      targetWsId = (process.argv[i + 1] || '').trim();
    }
  }

  if (!targetWsId && process.env.GTM_WORKSPACE_ID) {
    targetWsId = process.env.GTM_WORKSPACE_ID.trim();
  }

  if (!targetWsId) {
    throw new Error(
      '[FAIL-CLOSED] Workspace ID obrigatório não fornecido. ' +
      'Para evitar mutações cegas em workspaces incorretos, especifique explicitamente via --workspace-id=<id> ' +
      'ou defina a variável de ambiente GTM_WORKSPACE_ID.'
    );
  }

  const base = `/accounts/${accountId}/containers/${containerId}`;
  const wsList = await gtmRequest('GET', base + '/workspaces');
  const available = wsList.workspace || [];
  const found = available.find(w => String(w.workspaceId) === String(targetWsId));

  if (!found) {
    const availableIds = available.map(w => `${w.workspaceId} ("${w.name}")`).join(', ');
    throw new Error(
      `[FAIL-CLOSED] Workspace '${targetWsId}' não encontrado no container ${containerId}. ` +
      `Workspaces disponíveis: [${availableIds}]`
    );
  }

  return found;
}

function validateAwctTagContract(tag, expected, canonicalVariablesMap) {
  const diffs = [];
  if (!tag) {
    return { isAdherent: false, differences: ['Tag inexistente'] };
  }
  if (tag.type !== 'awct') {
    diffs.push(`Tipo divergente: esperado "awct", encontrado "${tag.type}"`);
  }
  if (tag.paused === true) {
    diffs.push(`Tag está pausada (paused: true) quando deveria estar ativa`);
  }
  const expectedFiringOption = expected.tagFiringOption || 'oncePerEvent';
  if ((tag.tagFiringOption || 'oncePerEvent') !== expectedFiringOption) {
    diffs.push(`tagFiringOption divergente: esperado "${expectedFiringOption}", encontrado "${tag.tagFiringOption}"`);
  }

  // 1. Verificação estrutural estrita de parâmetros (sem parâmetros extras, sem duplicados, tipo e valor exatos)
  const tagParams = tag.parameter || [];
  let expectedParams = expected.parameter;

  // Normalização fail-safe caso expected contenha propriedades soltas de conversão
  if (!expectedParams && (expected.conversionId || expected.conversionLabel)) {
    expectedParams = [];
    if (expected.conversionId) {
      expectedParams.push({
        type: 'template',
        key: 'conversionId',
        value: expected.conversionId
      });
    }
    if (expected.conversionLabel) {
      expectedParams.push({
        type: 'template',
        key: 'conversionLabel',
        value: expected.conversionLabel
      });
    }
    if (expected.orderId) {
      expectedParams.push({
        type: 'template',
        key: 'orderId',
        value: expected.orderId
      });
    }
    if (expected.value) {
      expectedParams.push({
        type: 'template',
        key: 'value',
        value: expected.value
      });
    }
    if (expected.currencyCode) {
      expectedParams.push({
        type: 'template',
        key: 'currencyCode',
        value: expected.currencyCode
      });
    }
  }
  expectedParams = expectedParams || [];

  if (expectedParams.length === 0) {
    diffs.push('[FAIL-CLOSED] Contrato esperado inválido: lista de parâmetros esperados está vazia');
  }

  // Rejeita parâmetros com chave duplicada
  const tagKeys = tagParams.map(p => p.key);
  const uniqueKeys = new Set(tagKeys);
  if (tagKeys.length !== uniqueKeys.size) {
    diffs.push(`Parâmetros duplicados encontrados na Tag: ${tagKeys.join(', ')}`);
  }

  // Verifica se a quantidade exata de parâmetros corresponde ao esperado
  if (expectedParams.length > 0 && tagParams.length !== expectedParams.length) {
    diffs.push(`Quantidade de parâmetros divergente: esperado ${expectedParams.length}, encontrado ${tagParams.length}`);
  }

  for (const ep of expectedParams) {
    const cp = tagParams.find(p => p.key === ep.key);
    if (!cp) {
      diffs.push(`Parâmetro obrigatório "${ep.key}" ausente`);
      continue;
    }
    if (cp.type !== ep.type) {
      diffs.push(`Tipo do parâmetro "${ep.key}" divergente: esperado "${ep.type}", encontrado "${cp.type}"`);
    }
    if (cp.value !== ep.value) {
      diffs.push(`Valor do parâmetro "${ep.key}" divergente: esperado "${ep.value}", encontrado "${cp.value}"`);
    }
  }

  // Rejeita qualquer parâmetro presente na Tag que não exista no esperado
  for (const cp of tagParams) {
    const ep = expectedParams.find(p => p.key === cp.key);
    if (!ep && expectedParams.length > 0) {
      diffs.push(`Parâmetro não canônico/extra "${cp.key}" encontrado na Tag`);
    }
  }

  // Se houver mapa de variáveis canônicas, valida se as constantes referenciadas mantêm o valor canônico
  if (canonicalVariablesMap) {
    for (const ep of expectedParams) {
      const match = typeof ep.value === 'string' && ep.value.match(/\{\{([^}]+)\}\}/);
      if (match) {
        const varName = match[1];
        const canVar = canonicalVariablesMap.get(varName);
        if (canVar) {
          const valParam = (canVar.parameter || []).find(p => p.key === 'value');
          if (!valParam || !valParam.value) {
            diffs.push(`Variável canônica "${varName}" sem parâmetro "value" definido`);
          }
        }
      }
    }
  }

  // 2. Verificação estrita de consentSettings (consentStatus e consentType)
  if (!tag.consentSettings || tag.consentSettings.consentStatus !== 'needed') {
    diffs.push(`consentStatus divergente: esperado "needed", encontrado "${tag.consentSettings?.consentStatus}"`);
  }
  const consentType = tag.consentSettings?.consentType;
  if (!consentType || consentType.type !== 'list') {
    diffs.push(`consentType.type divergente: esperado "list", encontrado "${consentType?.type}"`);
  }
  const consentList = consentType?.list || [];
  if (consentList.length !== 1) {
    diffs.push(`consentType.list deve conter exatamente 1 item, encontrado ${consentList.length}`);
  } else {
    const item = consentList[0];
    if (item.type !== 'template') {
      diffs.push(`Tipo do item de consentimento divergente: esperado "template", encontrado "${item.type}"`);
    }
    if (item.value !== 'ad_storage') {
      diffs.push(`Valor do consentimento divergente: esperado "ad_storage", encontrado "${item.value}"`);
    }
  }

  // 3. Verificação estrita de firingTriggerId (sem triggers extras nem faltantes)
  if (expected.firingTriggerId) {
    const expectedTriggers = (Array.isArray(expected.firingTriggerId) ? expected.firingTriggerId : [expected.firingTriggerId]).map(String).sort();
    const currentTriggers = (tag.firingTriggerId || []).map(String).sort();

    const missing = expectedTriggers.filter(id => !currentTriggers.includes(id));
    const extra = currentTriggers.filter(id => !expectedTriggers.includes(id));

    if (missing.length > 0 || extra.length > 0 || currentTriggers.length !== expectedTriggers.length) {
      diffs.push(`firingTriggerId divergente: esperado [${expectedTriggers.join(', ')}], encontrado [${currentTriggers.join(', ')}]`);
    }
  }

  return { isAdherent: diffs.length === 0, differences: diffs };
}

function validateCustomEventTriggerContract(trigger, expectedEvent) {
  const diffs = [];
  if (!trigger) {
    return { isAdherent: false, differences: ['Trigger inexistente'] };
  }
  if (trigger.type !== 'customEvent') {
    diffs.push(`Tipo de trigger divergente: esperado "customEvent", encontrado "${trigger.type}"`);
  }

  // 1. Verificação estrita de customEventFilter (exatamente 1 filtro com arg0 {{_event}} e arg1 esperado)
  const cef = trigger.customEventFilter || [];
  if (cef.length !== 1) {
    diffs.push(`customEventFilter deve conter exatamente 1 filtro, encontrado ${cef.length}`);
  } else {
    const f = cef[0];
    if (f.type !== 'equals') {
      diffs.push(`Tipo do customEventFilter divergente: esperado "equals", encontrado "${f.type}"`);
    }
    if (f.negate !== false) {
      diffs.push(`customEventFilter sem negate:false explícito (encontrado: ${f.negate})`);
    }
    const params = f.parameter || [];
    const paramKeys = params.map(p => p.key);
    if (new Set(paramKeys).size !== paramKeys.length) {
      diffs.push(`Parâmetros duplicados no customEventFilter: ${paramKeys.join(', ')}`);
    }
    if (params.length !== 2) {
      diffs.push(`customEventFilter deve conter exatamente 2 parâmetros (arg0, arg1), encontrado ${params.length}`);
    }
    const arg0 = params.find(p => p.key === 'arg0');
    if (!arg0 || arg0.type !== 'template' || arg0.value !== '{{_event}}') {
      diffs.push(`arg0 do customEventFilter divergente: esperado template "{{_event}}", encontrado ${JSON.stringify(arg0)}`);
    }
    const arg1 = params.find(p => p.key === 'arg1');
    if (!arg1 || arg1.type !== 'template' || arg1.value !== expectedEvent) {
      diffs.push(`arg1 do customEventFilter divergente: esperado template "${expectedEvent}", encontrado ${JSON.stringify(arg1)}`);
    }
  }

  // 2. Verificação estrita de filter (exatamente 1 filtro de consentimento AdOpt)
  const filters = trigger.filter || [];
  if (filters.length !== 1) {
    diffs.push(`Trigger filter deve conter exatamente 1 filtro AdOpt, encontrado ${filters.length}`);
  } else {
    const f = filters[0];
    if (f.type !== 'contains') {
      diffs.push(`Tipo do filtro AdOpt divergente: esperado "contains", encontrado "${f.type}"`);
    }
    if (f.negate !== false) {
      diffs.push(`filter sem negate:false explícito (encontrado: ${f.negate})`);
    }
    const params = f.parameter || [];
    const paramKeys = params.map(p => p.key);
    if (new Set(paramKeys).size !== paramKeys.length) {
      diffs.push(`Parâmetros duplicados no filtro AdOpt: ${paramKeys.join(', ')}`);
    }
    if (params.length !== 2) {
      diffs.push(`filter AdOpt deve conter exatamente 2 parâmetros (arg0, arg1), encontrado ${params.length}`);
    }
    const arg0 = params.find(p => p.key === 'arg0');
    if (!arg0 || arg0.type !== 'template' || arg0.value !== '{{Tags_Aceitas_AdOpt}}') {
      diffs.push(`arg0 do filtro AdOpt divergente: esperado template exato "{{Tags_Aceitas_AdOpt}}", encontrado ${JSON.stringify(arg0)}`);
    }
    const arg1 = params.find(p => p.key === 'arg1');
    if (!arg1 || arg1.type !== 'template' || arg1.value !== 'marketing') {
      diffs.push(`arg1 do filtro AdOpt divergente: esperado template "marketing", encontrado ${JSON.stringify(arg1)}`);
    }
  }

  return { isAdherent: diffs.length === 0, differences: diffs };
}

function isApplyRequested() {
  return process.argv.includes('--apply');
}

function isPublishRequested() {
  return process.argv.includes('--publish');
}

function isPublishConfirmed() {
  return process.argv.includes('--confirm-publish');
}

module.exports = {
  gtmRequest,
  getVerifiedWorkspaceId,
  isApplyRequested,
  isPublishRequested,
  isPublishConfirmed,
  validateAwctTagContract,
  validateCustomEventTriggerContract
};

