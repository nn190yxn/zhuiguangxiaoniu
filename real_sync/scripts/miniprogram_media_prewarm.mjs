#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const { prewarmHistoricalMedia, createMemoryMappingStore } = require('../cloudrun/media-adapter/index.js');

function parseArgs(argv) {
  const args = { input: '', limit: 100 };
  for (let index = 2; index < argv.length; index += 1) {
    const current = argv[index];
    if (current === '--input') args.input = argv[index += 1] || '';
    if (current === '--limit') args.limit = Number(argv[index += 1] || 100);
  }
  return args;
}

export function runPrewarmCli(argv = process.argv) {
  const args = parseArgs(argv);
  if (!args.input) throw new Error('缺少 --input <manifest.json>');
  const manifest = JSON.parse(readFileSync(resolve(args.input), 'utf8'));
  const items = Array.isArray(manifest) ? manifest : manifest.items;
  const store = createMemoryMappingStore();
  return {
    status: 'ok',
    processed: Math.min(items.length, Math.min(args.limit, 500)),
    results: prewarmHistoricalMedia(items, { limit: args.limit, store })
  };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  try {
    process.stdout.write(`${JSON.stringify(runPrewarmCli(), null, 2)}\n`);
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}
