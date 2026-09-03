import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');
const scriptPath = path.join(repositoryRoot, 'scripts', 'pagespeed-check.mjs');

async function runWithStubbedPageSpeed() {
  const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'uonix-pagespeed-'));
  const bootstrapPath = path.join(temporaryDirectory, 'fetch-stub.mjs');
  const outputPath = path.join(temporaryDirectory, 'summary.json');

  await writeFile(
    bootstrapPath,
    `globalThis.fetch = async (endpoint) => ({\n` +
      `  ok: true,\n` +
      `  text: async () => JSON.stringify({\n` +
      `    id: endpoint.searchParams.get('url'),\n` +
      `    lighthouseResult: {\n` +
      `      finalUrl: endpoint.searchParams.get('url'),\n` +
      `      categories: { performance: { score: 1 } },\n` +
      `      audits: {}\n` +
      `    }\n` +
      `  })\n` +
      `});\n`
  );

  execFileSync(
    process.execPath,
    [`--import=${bootstrapPath}`, scriptPath, `--out=${outputPath}`],
    {
      cwd: repositoryRoot,
      encoding: 'utf8',
      env: { ...process.env, PAGESPEED_API_KEY: 'test-key' },
    }
  );

  return JSON.parse(await readFile(outputPath, 'utf8'));
}

test('uses the canonical remote environments when no PageSpeed URL is supplied', async () => {
  const results = await runWithStubbedPageSpeed();

  assert.deepEqual(
    results.map((result) => result.requestedUrl),
    [
      'https://uonix.com.br/',
      'https://uonix.ksio.dev/',
      'https://test.uonix.ksio.dev/',
    ]
  );
});

test('help identifies the canonical QA, DEV and provisional production targets', () => {
  const help = execFileSync(process.execPath, [scriptPath, '--help'], {
    cwd: repositoryRoot,
    encoding: 'utf8',
  });

  assert.match(help, /\buonix\.com\.br/);
  assert.match(help, /uonix\.ksio\.dev/);
  assert.match(help, /test\.uonix\.ksio\.dev/);
  assert.doesNotMatch(help, /qa\.uonix\.ksio\.dev/);
});
