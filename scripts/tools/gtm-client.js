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

function validateAwctTagContract(tag, expected) {
  const diffs = [];
  if (!tag) {
    return { isAdherent: false, differences: ['Tag inexistente'] };
  }
  if (tag.type !== 'awct') {
    diffs.push(`Tipo divergente: esperado "awct", encontrado "${tag.type}"`);
  }
  const expectedFiringOption = expected.tagFiringOption || 'oncePerEvent';
  if (tag.tagFiringOption !== expectedFiringOption) {
    diffs.push(`tagFiringOption divergente: esperado "${expectedFiringOption}", encontrado "${tag.tagFiringOption}"`);
  }

  // Verifica parâmetros
  const params = tag.parameter || [];
  if (expected.conversionId) {
    const pId = params.find(p => p.key === 'conversionId')?.value;
    if (pId !== expected.conversionId) {
      diffs.push(`conversionId divergente: esperado "${expected.conversionId}", encontrado "${pId}"`);
    }
  }
  if (expected.conversionLabel) {
    const pLabel = params.find(p => p.key === 'conversionLabel')?.value;
    if (pLabel !== expected.conversionLabel) {
      diffs.push(`conversionLabel divergente: esperado "${expected.conversionLabel}", encontrado "${pLabel}"`);
    }
  }
  if (expected.orderId) {
    const pOrder = params.find(p => p.key === 'orderId')?.value;
    if (pOrder !== expected.orderId) {
      diffs.push(`orderId divergente: esperado "${expected.orderId}", encontrado "${pOrder}"`);
    }
  }

  // Verifica consentSettings
  if (!tag.consentSettings || tag.consentSettings.consentStatus !== 'needed') {
    diffs.push(`consentStatus divergente: esperado "needed", encontrado "${tag.consentSettings?.consentStatus}"`);
  }
  const consentList = tag.consentSettings?.consentType?.list || [];
  const consentValues = consentList.map(c => c.value);
  if (consentValues.length !== 1 || consentValues[0] !== 'ad_storage') {
    diffs.push(`consentType divergente: esperado estritamente ["ad_storage"], encontrado [${consentValues.join(', ')}]`);
  }

  // Verifica firingTriggerId
  if (expected.firingTriggerId) {
    const expectedTriggers = Array.isArray(expected.firingTriggerId) ? expected.firingTriggerId : [expected.firingTriggerId];
    const currentTriggers = (tag.firingTriggerId || []).map(String);
    const missing = expectedTriggers.filter(id => !currentTriggers.includes(String(id)));
    if (missing.length > 0 || currentTriggers.length !== expectedTriggers.length) {
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

  // Verifica customEventFilter
  const cef = trigger.customEventFilter || [];
  if (cef.length === 0) {
    diffs.push('customEventFilter ausente');
  } else {
    for (const f of cef) {
      if (f.negate !== false) {
        diffs.push(`customEventFilter sem negate:false explícito (encontrado: ${f.negate})`);
      }
    }
    const evtVal = cef[0]?.parameter?.find(p => p.key === 'arg1')?.value;
    if (evtVal !== expectedEvent) {
      diffs.push(`Evento customizado divergente: esperado "${expectedEvent}", encontrado "${evtVal}"`);
    }
  }

  // Verifica filter (filtro de consentimento AdOpt)
  const filters = trigger.filter || [];
  if (filters.length === 0) {
    diffs.push('Filtro de consentimento AdOpt ausente em trigger.filter');
  } else {
    for (const f of filters) {
      if (f.negate !== false) {
        diffs.push(`filter sem negate:false explícito (encontrado: ${f.negate})`);
      }
    }
    const hasMarketing = filters.some(f =>
      f.parameter?.some(p => p.value?.includes('Tags_Aceitas_AdOpt')) &&
      f.parameter?.some(p => p.value === 'marketing')
    );
    if (!hasMarketing) {
      diffs.push('Filtro AdOpt de marketing (Tags_Aceitas_AdOpt contendo "marketing") ausente');
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

