#!/usr/bin/env node

import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';

const DEFAULT_URLS = [
  'https://qa.uonix.ksio.dev/',
  'https://uonix.ksio.dev/',
];

const DEFAULT_AUDITS = [
  'first-contentful-paint',
  'largest-contentful-paint',
  'speed-index',
  'total-blocking-time',
  'cumulative-layout-shift',
  'server-response-time',
  'total-byte-weight',
  'render-blocking-resources',
  'unused-css-rules',
  'unused-javascript',
  'largest-contentful-paint-element',
  'lcp-lazy-loaded',
  'prioritize-lcp-image',
  'uses-rel-preconnect',
  'uses-responsive-images',
  'uses-optimized-images',
  'modern-image-formats',
];

function parseArgs(argv) {
  const args = {
    urls: [],
    strategies: [],
    categories: ['performance'],
    envFile: '.env',
    out: '',
    rawOut: '',
    locale: 'pt-BR',
  };

  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    const next = argv[i + 1];
    const readValue = () => {
      if (arg.includes('=')) {
        return arg.split('=').slice(1).join('=');
      }

      i += 1;
      return next;
    };

    if (arg === '--help' || arg === '-h') {
      args.help = true;
    } else if (arg === '--url' || arg.startsWith('--url=')) {
      args.urls.push(readValue());
    } else if (arg === '--strategy' || arg.startsWith('--strategy=')) {
      args.strategies.push(readValue());
    } else if (arg === '--category' || arg.startsWith('--category=')) {
      args.categories.push(readValue());
    } else if (arg === '--env' || arg.startsWith('--env=')) {
      args.envFile = readValue();
    } else if (arg === '--out' || arg.startsWith('--out=')) {
      args.out = readValue();
    } else if (arg === '--raw-out' || arg.startsWith('--raw-out=')) {
      args.rawOut = readValue();
    } else if (arg === '--locale' || arg.startsWith('--locale=')) {
      args.locale = readValue();
    } else {
      throw new Error(`Argumento desconhecido: ${arg}`);
    }
  }

  if (args.urls.length === 0) {
    args.urls = DEFAULT_URLS;
  }

  if (args.strategies.length === 0) {
    args.strategies = ['mobile'];
  }

  return args;
}

function printHelp() {
  console.log(`Uso:
  node scripts/pagespeed-check.mjs [opcoes]

Opcoes:
  --url URL            URL a medir. Pode repetir. Padrao: QA e producao ksio.
  --strategy mobile    Estrategia Lighthouse: mobile ou desktop. Pode repetir.
  --category perf      Categoria PageSpeed. Padrao: performance.
  --env .env           Arquivo com PAGESPEED_API_KEY=... ou a chave crua.
  --out arquivo.json   Salva o resumo em JSON.
  --raw-out dir        Salva respostas brutas da API no diretorio informado.
  --locale pt-BR       Locale da API.

Exemplo:
  node scripts/pagespeed-check.mjs --url=https://qa.uonix.ksio.dev/ --strategy=mobile --out=tmp/pagespeed/qa.json
`);
}

function loadApiKey(envFile) {
  const candidates = [
    process.env.PAGESPEED_API_KEY,
    process.env.GOOGLE_PAGESPEED_API_KEY,
    process.env.GOOGLE_API_KEY,
  ].filter(Boolean);

  if (candidates.length > 0) {
    return candidates[0];
  }

  if (!existsSync(envFile)) {
    return '';
  }

  const lines = readFileSync(envFile, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'));

  const parsed = new Map();

  for (const line of lines) {
    const match = line.match(/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/);

    if (!match) {
      continue;
    }

    const value = match[2].replace(/^['"]|['"]$/g, '').trim();
    parsed.set(match[1], value);
  }

  for (const name of ['PAGESPEED_API_KEY', 'GOOGLE_PAGESPEED_API_KEY', 'GOOGLE_API_KEY', 'API_KEY']) {
    if (parsed.has(name)) {
      return parsed.get(name);
    }
  }

  const bareKey = lines.find((line) => !line.includes('='));
  return bareKey || '';
}

function getDisplay(audits, id) {
  const audit = audits[id];

  if (!audit) {
    return null;
  }

  return {
    id,
    title: audit.title,
    score: audit.score,
    displayValue: audit.displayValue || null,
    numericValue: audit.numericValue ?? null,
    savingsMs: audit.details?.overallSavingsMs ?? null,
    wastedBytes: audit.details?.overallSavingsBytes ?? audit.details?.overallSavingsBytesEstimate ?? null,
  };
}

function getOpportunityScore(item) {
  return (item.savingsMs || 0) + ((item.wastedBytes || 0) / 1024);
}

function summarize(json, url, strategy) {
  const lighthouse = json.lighthouseResult || {};
  const audits = lighthouse.audits || {};
  const performanceScore = lighthouse.categories?.performance?.score;
  const selectedAudits = Object.fromEntries(
    DEFAULT_AUDITS
      .map((id) => [id, getDisplay(audits, id)])
      .filter(([, value]) => Boolean(value))
  );
  const opportunities = Object.values(audits)
    .filter((audit) => audit?.details?.type === 'opportunity')
    .map((audit) => ({
      id: audit.id,
      title: audit.title,
      displayValue: audit.displayValue || null,
      score: audit.score,
      savingsMs: audit.details?.overallSavingsMs ?? null,
      wastedBytes: audit.details?.overallSavingsBytes ?? null,
    }))
    .filter((item) => getOpportunityScore(item) > 0)
    .sort((a, b) => getOpportunityScore(b) - getOpportunityScore(a))
    .slice(0, 8);

  return {
    requestedUrl: url,
    finalUrl: lighthouse.finalUrl || json.id || url,
    strategy,
    fetchTime: lighthouse.fetchTime || json.analysisUTCTimestamp || null,
    score: performanceScore == null ? null : Math.round(performanceScore * 100),
    metrics: {
      fcp: selectedAudits['first-contentful-paint']?.displayValue || null,
      lcp: selectedAudits['largest-contentful-paint']?.displayValue || null,
      speedIndex: selectedAudits['speed-index']?.displayValue || null,
      tbt: selectedAudits['total-blocking-time']?.displayValue || null,
      cls: selectedAudits['cumulative-layout-shift']?.displayValue || null,
      ttfb: selectedAudits['server-response-time']?.displayValue || null,
      bytes: selectedAudits['total-byte-weight']?.displayValue || null,
    },
    audits: selectedAudits,
    opportunities,
    warnings: lighthouse.runWarnings || [],
  };
}

function printSummary(results) {
  const rows = results.map((result) => ({
    url: result.requestedUrl,
    strategy: result.strategy,
    score: result.score,
    fcp: result.metrics.fcp,
    lcp: result.metrics.lcp,
    speedIndex: result.metrics.speedIndex,
    tbt: result.metrics.tbt,
    cls: result.metrics.cls,
    ttfb: result.metrics.ttfb,
    bytes: result.metrics.bytes,
  }));

  console.table(rows);

  for (const result of results) {
    console.log(`\n${result.strategy.toUpperCase()} ${result.requestedUrl} - score ${result.score}`);

    for (const opportunity of result.opportunities) {
      const suffix = [
        opportunity.displayValue,
        opportunity.savingsMs ? `${Math.round(opportunity.savingsMs)} ms` : '',
        opportunity.wastedBytes ? `${Math.round(opportunity.wastedBytes / 1024)} KiB` : '',
      ].filter(Boolean).join(' | ');

      console.log(`- ${opportunity.title}${suffix ? `: ${suffix}` : ''}`);
    }
  }
}

function rawFilename(url, strategy) {
  const safeUrl = url
    .replace(/^https?:\/\//, '')
    .replace(/[^A-Za-z0-9.-]+/g, '-')
    .replace(/^-+|-+$/g, '');

  return `${safeUrl}-${strategy}.json`;
}

async function run() {
  const args = parseArgs(process.argv.slice(2));

  if (args.help) {
    printHelp();
    return;
  }

  const apiKey = loadApiKey(args.envFile);

  if (!apiKey) {
    throw new Error(`Chave PageSpeed nao encontrada em ${args.envFile}. Use PAGESPEED_API_KEY=... ou coloque a chave em uma linha unica.`);
  }

  const results = [];
  const rawResponses = [];

  for (const url of args.urls) {
    for (const strategy of args.strategies) {
      const endpoint = new URL('https://www.googleapis.com/pagespeedonline/v5/runPagespeed');
      endpoint.searchParams.set('url', url);
      endpoint.searchParams.set('strategy', strategy);
      endpoint.searchParams.set('locale', args.locale);
      endpoint.searchParams.set('key', apiKey);

      for (const category of args.categories) {
        endpoint.searchParams.append('category', category);
      }

      const response = await fetch(endpoint);
      const body = await response.text();

      if (!response.ok) {
        throw new Error(`PageSpeed falhou para ${url} (${strategy}): HTTP ${response.status} ${body.slice(0, 500)}`);
      }

      const json = JSON.parse(body);
      rawResponses.push({ url, strategy, json });
      results.push(summarize(json, url, strategy));
    }
  }

  printSummary(results);

  if (args.out) {
    const outPath = path.resolve(args.out);
    await mkdir(path.dirname(outPath), { recursive: true });
    await writeFile(outPath, `${JSON.stringify(results, null, 2)}\n`);
  }

  if (args.rawOut) {
    const rawDir = path.resolve(args.rawOut);
    await mkdir(rawDir, { recursive: true });

    for (const item of rawResponses) {
      await writeFile(
        path.join(rawDir, rawFilename(item.url, item.strategy)),
        `${JSON.stringify(item.json, null, 2)}\n`
      );
    }
  }
}

run().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
