#!/usr/bin/env node

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const projectRoot = new URL('../', import.meta.url);
const baselineUrl = new URL('./drill-api-baseline.json', import.meta.url);

const signalPatterns = [
  ['legacy_user_lookup', /getCurrentUserId\s*\(/],
  ['staff_context', /appRequireStaffContext\s*\(|drillV2Bootstrap\s*\(/],
  ['idempotency_key', /Idempotency-Key|HTTP_IDEMPOTENCY_KEY/],
  ['transaction', /beginTransaction\s*\(/],
  ['row_lock', /FOR UPDATE/i],
  ['wildcard_cors', /Access-Control-Allow-Origin:\s*\*/],
  ['exposes_exception', /jsonResponse\([^\n]*getMessage\s*\(/],
];

export function loadDrillApiBaseline() {
  return JSON.parse(readFileSync(baselineUrl, 'utf8'));
}

export function buildDrillApiSnapshot(baseline = loadDrillApiBaseline()) {
  return {
    version: baseline.version,
    entity_id_spaces: baseline.entity_id_spaces,
    endpoints: baseline.endpoints.map((endpoint) => {
      const source = readFileSync(new URL(endpoint.path, projectRoot), 'utf8');
      return {
        path: endpoint.path,
        methods: endpoint.methods,
        actions: endpoint.actions,
        auth: endpoint.auth,
        input_ids: endpoint.input_ids,
        output_ids: endpoint.output_ids,
        mutates: endpoint.mutates,
        known_risks: endpoint.known_risks,
        source_signals: signalPatterns
          .filter(([, pattern]) => pattern.test(source))
          .map(([name]) => name),
      };
    }),
  };
}

export function checkDrillApiBaseline(baseline = loadDrillApiBaseline()) {
  const snapshot = buildDrillApiSnapshot(baseline);
  const mismatches = snapshot.endpoints.flatMap((endpoint, index) => {
    const expected = baseline.endpoints[index].expected_source_signals;
    return JSON.stringify(endpoint.source_signals) === JSON.stringify(expected)
      ? []
      : [{ path: endpoint.path, expected, actual: endpoint.source_signals }];
  });
  return { snapshot, mismatches };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const { snapshot, mismatches } = checkDrillApiBaseline();
  process.stdout.write(`${JSON.stringify({ ...snapshot, mismatches }, null, 2)}\n`);
  if (process.argv.includes('--check') && mismatches.length > 0) {
    process.exitCode = 1;
  }
}
