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

module.exports = { gtmRequest };
