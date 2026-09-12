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

const keyPath = process.env.HOME + '/.config/gcloud/gtm-automation-key.json';
const key = JSON.parse(fs.readFileSync(keyPath, 'utf8'));

function base64url(str) {
  return Buffer.from(str).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function getAccessToken() {
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
  isPublishConfirmed
};

