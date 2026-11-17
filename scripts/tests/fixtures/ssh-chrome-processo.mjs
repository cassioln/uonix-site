#!/usr/bin/env node
// Fixture CDP local: deixa um descendente após encerrar o líder.
import fs from 'node:fs';
import { spawn } from 'node:child_process';
const filho = spawn(process.execPath, ['-e', 'setInterval(()=>{},1000)'], { stdio: 'ignore' });
fs.writeFileSync(process.argv[2], String(filho.pid));
let buffer = '';
const entrada = fs.createReadStream('', { fd: 3, autoClose: false });
const saida = fs.createWriteStream('', { fd: 4, autoClose: false });
entrada.on('data', b => {
  buffer += b.toString(); let n;
  while ((n = buffer.indexOf('\0')) >= 0) {
    const req = JSON.parse(buffer.slice(0, n)); buffer = buffer.slice(n + 1);
    if (req.method === 'Browser.close') { process.exit(0); }
    if (process.argv[3] === 'falhar-abertura' && req.method === 'Browser.getVersion') {
      saida.write(JSON.stringify({ id: req.id, error: { code: -1, message: 'fixture' } }) + '\0');
      continue;
    }
    const result = req.method === 'Target.createTarget' ? { targetId: 'fixture' }
      : req.method === 'Target.attachToTarget' ? { sessionId: 'fixture' } : {};
    saida.write(JSON.stringify({ id: req.id, result }) + '\0');
  }
});
