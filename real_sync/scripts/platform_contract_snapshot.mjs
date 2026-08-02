#!/usr/bin/env node

import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { buildPlatformInventory } from './platform_inventory.mjs';

const currentFile = fileURLToPath(import.meta.url);
const defaultProjectRoot = resolve(dirname(currentFile), '..');
const sourceExtensions = new Set(['.html', '.js', '.mjs', '.php', '.wxml']);
const ignoredDirectories = new Set(['.git', 'node_modules', 'vendor']);
const apiReferencePattern = /(?:https?:\/\/[^/\s'"`]+)?\/?api\/[A-Za-z0-9_./-]+\.php/g;

function normalizePath(path) {
  return path.split(sep).join('/');
}

function walkSourceFiles(root, prefix = '') {
  const directory = join(root, prefix);
  if (!existsSync(directory)) return [];
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.name.startsWith('.') || ignoredDirectories.has(entry.name)) continue;
    const child = join(prefix, entry.name);
    if (entry.isDirectory()) files.push(...walkSourceFiles(root, child));
    if (entry.isFile() && sourceExtensions.has(extname(entry.name))) files.push(normalizePath(child));
  }
  return files;
}

function inferMethods(source) {
  if (!/REQUEST_METHOD/i.test(source)) return ['UNSPECIFIED'];
  const methods = ['DELETE', 'GET', 'PATCH', 'POST', 'PUT']
    .filter((method) => new RegExp(`['"]${method}['"]`, 'i').test(source));
  return methods.length > 0 ? methods : ['UNSPECIFIED'];
}

function inferSignals(source) {
  const signals = [];
  const patterns = [
    ['staff_context', /appRequireStaffContext|appResolveStaffContext|requireStaffContext/],
    ['admin_permission', /adminRequirePermission|adminRequireAnyPermission/],
    ['jwt', /verifyJWT|Authorization|Bearer\s/],
    ['session', /session_start|\$_SESSION/],
    ['request_id', /request[_-]?id|X-Request-ID/i],
    ['json_envelope', /['"]code['"]\s*=>|jsonResponse|appJsonResponse|adminJsonResponse/],
    ['idempotency_key', /Idempotency-Key|HTTP_IDEMPOTENCY_KEY/],
    ['state_version', /state[_-]?version/i],
    ['etag', /If-None-Match|\bETag\b/i],
    ['incremental_cursor', /incremental|next_cursor|sync_cursor/i],
    ['tombstone', /tombstone|permission_revoked/i],
    ['transaction', /beginTransaction\s*\(/],
    ['row_lock', /FOR\s+UPDATE/i],
    ['runtime_ddl', /CREATE\s+TABLE|ALTER\s+TABLE/i],
    ['wildcard_cors', /Access-Control-Allow-Origin:\s*\*/i],
    ['raw_exception_message', /getMessage\s*\(\)/],
  ];
  for (const [name, pattern] of patterns) {
    if (pattern.test(source)) signals.push(name);
  }
  return signals;
}

function inferActions(source) {
  if (!/["']action["']/.test(source)) return [];
  const actions = new Set();
  for (const match of source.matchAll(/(?:===|==|case\s+)[\s(]*['"]([a-z][a-z0-9_-]{1,64})['"]/gi)) {
    actions.add(match[1]);
  }
  return [...actions].sort();
}

function discoverConsumers(projectRoot) {
  const consumers = new Map();
  for (const path of walkSourceFiles(projectRoot)) {
    if (path.startsWith('api/')) continue;
    const source = readFileSync(join(projectRoot, path), 'utf8');
    for (const match of source.matchAll(apiReferencePattern)) {
      const endpoint = match[0].replace(/^https?:\/\/[^/]+/i, '').replace(/^\//, '');
      if (!consumers.has(endpoint)) consumers.set(endpoint, new Set());
      consumers.get(endpoint).add(path);
    }
  }
  return consumers;
}

function clientType(path) {
  if (path.startsWith('mini-program/')) return 'mini-program';
  if (path.startsWith('mobile/')) return 'pwa-mobile';
  if (path.startsWith('admin/')) return 'admin';
  return 'web';
}

export function buildPlatformContractSnapshot({ projectRoot = defaultProjectRoot } = {}) {
  const root = resolve(projectRoot);
  const inventory = buildPlatformInventory({ projectRoot: root });
  const consumers = discoverConsumers(root);
  const apiAssets = inventory.assets.filter(({ type }) => type === 'api');
  const endpoints = apiAssets.map((entry) => {
    const source = readFileSync(join(root, entry.path), 'utf8');
    return {
      path: entry.path,
      methods: inferMethods(source),
      actions: inferActions(source),
      signals: inferSignals(source),
      group_ids: entry.group_ids,
      ownership: entry.ownership,
      consumers: [...(consumers.get(entry.path) || [])].sort(),
    };
  }).sort((left, right) => left.path.localeCompare(right.path));
  const clients = [...consumers.entries()]
    .flatMap(([endpoint, paths]) => [...paths].map((path) => ({ path, type: clientType(path), endpoint })))
    .sort((left, right) => `${left.path}:${left.endpoint}`.localeCompare(`${right.path}:${right.endpoint}`));

  return {
    schema_version: 1,
    project_root: normalizePath(relative(resolve(root, '..'), root) || '.'),
    endpoints,
    clients,
    summary: {
      endpoint_count: endpoints.length,
      endpoint_with_consumer_count: endpoints.filter(({ consumers: paths }) => paths.length > 0).length,
      client_reference_count: clients.length,
      auth_signal_count: endpoints.filter(({ signals }) => signals.some((value) => ['staff_context', 'admin_permission', 'jwt', 'session'].includes(value))).length,
      request_id_count: endpoints.filter(({ signals }) => signals.includes('request_id')).length,
      idempotency_count: endpoints.filter(({ signals }) => signals.includes('idempotency_key')).length,
      runtime_ddl_count: endpoints.filter(({ signals }) => signals.includes('runtime_ddl')).length,
    },
  };
}

export function compareContractSnapshots(expected, actual) {
  const expectedByPath = new Map(expected.endpoints.map((entry) => [entry.path, entry]));
  const actualByPath = new Map(actual.endpoints.map((entry) => [entry.path, entry]));
  const changes = [];

  for (const [path, endpoint] of expectedByPath) {
    if (!actualByPath.has(path)) {
      changes.push({ type: 'removed_endpoint', path });
      continue;
    }
    const current = actualByPath.get(path);
    for (const field of ['methods', 'actions', 'signals']) {
      if (JSON.stringify(endpoint[field]) !== JSON.stringify(current[field])) {
        changes.push({ type: 'changed_contract', path, field, expected: endpoint[field], actual: current[field] });
      }
    }
  }
  for (const path of actualByPath.keys()) {
    if (!expectedByPath.has(path)) changes.push({ type: 'added_endpoint', path });
  }
  return changes.sort((left, right) => `${left.path}:${left.type}:${left.field || ''}`.localeCompare(`${right.path}:${right.type}:${right.field || ''}`));
}

function argumentValue(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : null;
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const snapshot = buildPlatformContractSnapshot({ projectRoot: argumentValue('--root') || defaultProjectRoot });
  const baselinePath = argumentValue('--baseline');
  const changes = baselinePath
    ? compareContractSnapshots(JSON.parse(readFileSync(resolve(baselinePath), 'utf8')), snapshot)
    : [];
  const result = { ...snapshot, changes };
  const json = `${JSON.stringify(result, null, 2)}\n`;
  const output = argumentValue('--output');
  if (output) writeFileSync(resolve(output), json);
  else process.stdout.write(json);
  if (process.argv.includes('--check') && changes.length > 0) process.exitCode = 1;
}
