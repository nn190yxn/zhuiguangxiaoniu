import assert from 'node:assert/strict';
import { readFileSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const importerPath = path.join(repoRoot, 'real_sync', 'scripts', 'import_knowledge_cards.php');
const importer = readFileSync(importerPath, 'utf8');
const packagePath = path.join(repoRoot, 'real_sync', 'database', 'import_data', 'knowledge-cards-phase2.isolated-package.json');
const packageBytes = readFileSync(packagePath);
const packageSha256 = createHash('sha256').update(packageBytes).digest('hex');
const packageData = JSON.parse(packageBytes.toString('utf8'));

function phpConst(name) {
  const match = importer.match(new RegExp('const ' + name + ' = \\[([\\s\\S]*?)\\];'));
  assert.ok(match, `missing const ${name}`);
  return [...match[1].matchAll(/'([^']+)'/g)].map((entry) => entry[1]);
}

test('import CLI validates the real v2 package byte-for-byte', () => {
  assert.equal(packageData.schema_version, 'knowledge-card-isolated-package.v2');
  assert.equal(packageData.record_count, 1417);
  assert.equal(packageData.records.length, 1417);
  assert.equal(packageData.publication_default, 'isolated');
  assert.equal(packageData.default_category_code, 'phase2_import');
  assert.ok(/^[a-f0-9]{64}$/.test(packageData.package_sha256));
  assert.ok(/^[a-f0-9]{64}$/.test(packageSha256));
  const identityParts = [
    packageData.schema_version, packageData.parser_version, packageData.source_root_name,
    String(packageData.record_count), packageData.source_report_sha256,
  ];
  for (const record of packageData.records) {
    identityParts.push(record.item_code, record.source_sha256, record.normalized_hash);
  }
  assert.equal(createHash('sha256').update(identityParts.join('\0'), 'utf8').digest('hex'), packageData.package_sha256);
  // The importer compares keys strictly in the Python sort_keys order.
  assert.deepEqual(Object.keys(packageData), phpConst('PACKAGE_TOP_KEYS'));
  assert.deepEqual(Object.keys(packageData.records[0]), phpConst('RECORD_KEYS'));
  assert.ok(importer.includes('package_sha256 does not match package identity'));
  assert.ok(importer.includes('normalized_hash does not match content in record #'));
  assert.ok(importer.includes('source_sha256 does not match raw_markdown in record #'));
  for (const record of packageData.records) {
    assert.ok(/^[A-Z]+-\d{4}$/.test(record.item_code));
    assert.equal(record.item_code, record.source_card_id);
    assert.ok(record.title.length > 0);
    assert.ok(record.content.length > 0);
    assert.ok(record.raw_markdown.length > 0);
    assert.equal(createHash('sha256').update(record.content, 'utf8').digest('hex'), record.normalized_hash);
    assert.equal(record.publication_status, 'isolated');
  }
});

test('CLI argument guards match phase-one boundaries', () => {
  assert.ok(importer.includes("failCli('--sha256 must be a 64-character lowercase hex digest')"));
  assert.ok(importer.includes("failCli('unknown argument: '"));
  assert.ok(importer.includes("failCli('duplicate argument: '"));
  assert.ok(importer.includes("failCli('import --apply requires --backup-dir DIR')"));
  assert.ok(importer.includes("failCli('--apply requires --manifest PATH')"));
  assert.ok(importer.includes("failCli('rollback requires --manifest PATH')"));
  assert.ok(importer.includes("failCli('--allow-update is only valid for import --apply')"));
  assert.ok(importer.includes("failCli('--ack-manual-review is only valid for import --apply')"));
  assert.ok(importer.includes("PHP_SAPI !== 'cli'"));
});

test('dry-run is read-only: no lock, backup, manifest, or writes', () => {
  const summaryStart = importer.indexOf('function summary(');
  const summaryEnd = importer.indexOf('function validateOutputPath(');
  const summaryBody = importer.slice(summaryStart, summaryEnd);
  assert.ok(summaryBody.includes('atomicJson($args[\'report\'], $report)'), 'explicit --report is the only dry-run write');
  assert.doesNotMatch(summaryBody, /GET_LOCK/i);
  assert.doesNotMatch(summaryBody, /backup\(/i);
  assert.doesNotMatch(summaryBody, /beginTransaction/i);
  assert.doesNotMatch(summaryBody, /INSERT\s+(?:IGNORE\s+)?INTO/i);
  assert.doesNotMatch(summaryBody, /UPDATE\s+knowledge_/i);
  assert.doesNotMatch(summaryBody, /DELETE\s+FROM/i);
  assert.doesNotMatch(summaryBody, /atomicJson\([^)]*manifest/i);
  // The dry-run branch never reaches applyImport.
  const mainBody = importer.slice(importer.indexOf('function main('));
  assert.ok(mainBody.includes("$state = loadState($db, $package);\n            $diff = diffPackage($package, $state);\n            summary($package, $state, $diff, $args);"));
});

test('apply acquires lock, backs up, writes pending manifest, and commits inside a transaction', () => {
  const body = importer.slice(importer.indexOf('function applyImport('));
  const order = [
    'SELECT GET_LOCK(?, 10)',
    'preflight($db, $package)',
    'loadState($db, $package)',
    'diffPackage($package, $state)',
    'backup($db, $package, $state, $args[\'backup_dir\'], \'import-before\')',
    "'status' => 'pending'",
    '$db->beginTransaction()',
    'loadState($db, $package)',
    'diffPackage($package, $state2)',
    'INSERT INTO knowledge_import_batches',
    'insertItem($db, $categoryId, $newBatchId,',
    'updateItem($db, $newBatchId,',
    "INSERT IGNORE INTO knowledge_item_relations",
    'insertAudit(',
    'assertImport(',
    "$manifest['prepared_at'] = date('Y-m-d H:i:s')",
    'bindManifestDigest($db, $newBatchId,',
    '$db->commit()',
    "$completionMarker = $args['manifest'] . '.completed'",
    "'status' => 'completed'",
  ];
  let cursor = 0;
  for (const needle of order) {
    const at = body.indexOf(needle, cursor);
    assert.ok(at !== -1, `missing apply step: ${needle}`);
    cursor = at + needle.length;
  }
  assert.ok(body.includes('catch (Throwable $error)'));
  assert.ok(body.includes('$db->rollBack()'));
  assert.ok(body.includes('SELECT RELEASE_LOCK(?)'));
  assert.ok(importer.includes('database_committed: import and rollback manifest are safe, but completion marker could not be written'));
  assert.ok(importer.includes('recover via database-verified pending manifest'));
  assert.ok(importer.includes('function bindManifestDigest('));
  assert.ok(importer.includes('manifest digest cannot be bound inside the import transaction'));
  assert.ok(importer.includes('SET manifest_sha256 = ?'));
  assert.ok(importer.includes("package already has a ' . $state['batch']['status'] . ' batch; manual reconciliation required"));
  assert.ok(importer.includes('no changes: all '));
  assert.ok(importer.includes('records already imported (idempotent)'));
});

test('idempotency: insert/skip/update_pending and never delete-reinsert old rows', () => {
  assert.ok(importer.includes("'items' => ['insert' => [], 'skip' => [], 'update_pending' => []]"));
  assert.ok(importer.includes("hash_equals((string)$source['source_sha256'], (string)$record['source_sha256'])"));
  assert.ok(importer.includes("hash_equals((string)$source['normalized_hash'], (string)$record['normalized_hash'])"));
  assert.ok(importer.includes("'update_pending'][] = $code;"));
  assert.ok(importer.includes("'reason' => 'content_or_source_changed'"));
  assert.ok(importer.includes("$state['batch'] !== null && $updateCount > 0"));
  assert.ok(importer.includes('refusing duplicate batch/source update'));
  assert.ok(importer.includes('$updateCount > 0 && !$args[\'allow_update\']'));
  assert.ok(importer.includes('update_pending='));
  assert.ok(importer.includes('requires --allow-update'));
  assert.ok(importer.includes('existing old rows are preserved, never deleted'));
  const applyBody = importer.slice(importer.indexOf('function applyImport('), importer.indexOf('function readManifest('));
  assert.doesNotMatch(applyBody, /DELETE\s+FROM\s+knowledge_items/i, 'apply must never delete knowledge items');
  const updateBody = importer.slice(importer.indexOf('function updateItem('), importer.indexOf('function assertImport('));
  assert.ok(updateBody.includes('SELECT COALESCE(MAX(version_no), 0) + 1 AS next_no'), 'updates create a new version number');
  assert.ok(updateBody.includes("SET status = 'superseded' WHERE knowledge_item_id = ? AND status = 'active'"));
  assert.doesNotMatch(updateBody, /\bDELETE\b/i);
});

test('manual review and candidate relations guard the apply', () => {
  assert.ok(importer.includes('const SIMILARITY_THRESHOLD = 0.80'));
  assert.ok(importer.includes('$ratio >= SIMILARITY_THRESHOLD'));
  assert.ok(importer.includes("'candidates' => []"));
  assert.match(importer, /relation_type\)\s*\r?\n\s*VALUES \(\?, \?, \\'candidate\\'\)/);
  assert.ok(importer.includes('manual_review='));
  assert.ok(importer.includes('requires --ack-manual-review'));
  assert.ok(importer.includes('INSERT IGNORE INTO knowledge_item_relations'));
});

test('rollback trusts only database-bound completed/pending manifests and blocks on risky state', () => {
  const body = importer.slice(importer.indexOf('function readManifest('));
  assert.ok(body.includes('manifest is neither completed nor pending'));
  assert.ok(body.includes('manifest package_sha256 does not match the supplied package'));
  assert.ok(body.includes('batch contains updates; automatic rollback is refused, restore from backup manually'));
  assert.ok(body.includes('rollback blocked: rows in '));
  assert.ok(body.includes('rollback blocked: imported items have unknown, incoming, reviewed, or non-candidate relations'));
  assert.ok(body.includes('rollback blocked: manifest batch does not match database package'));
  assert.ok(body.includes('rollback blocked: manifest path does not match database batch'));
  assert.ok(body.includes('rollback blocked: manifest SHA-256 does not match database batch'));
  assert.ok(body.includes('rollback blocked: invalid source ownership in manifest'));
  assert.ok(body.includes('rollback blocked: invalid version ownership in manifest'));
  assert.ok(body.includes("$row['source_batch_id']"));
  assert.ok(body.includes("$row['publication_status'] !== 'isolated'"));
  assert.ok(body.includes('target_item_id IN ('));
  assert.ok(body.includes('item no longer matches manifest: id='));
  assert.ok(body.includes('source no longer matches manifest:'));
  assert.ok(body.includes('version no longer matches manifest:'));
  assert.ok(importer.includes('DELETE FROM knowledge_item_relations WHERE relation_id = ? AND source_item_id = ?'));
  assert.ok(importer.includes('DELETE FROM knowledge_item_sources WHERE source_id = ? AND knowledge_item_id = ? AND source_card_id = ? AND batch_id = ?'));
  assert.ok(importer.includes('DELETE FROM knowledge_item_versions WHERE version_id = ? AND knowledge_item_id = ?'));
  assert.ok(importer.includes('DELETE FROM knowledge_items WHERE id = ? AND item_code = ?'));
  assert.ok(importer.includes("status = 'rolled_back'"));
  assert.match(importer, /insertAudit\([\s\S]*?'rollback',[\s\S]*?'knowledge_import_batches'/);
});

test('non-target rows are digested before and asserted after apply/rollback', () => {
  assert.ok(importer.includes('function rowsDigestExceptCodes(PDO $db, array $codes): string'));
  assert.ok(importer.includes('function rowsDigestExceptIds(PDO $db, string $table, string $idColumn, array $ids): string'));
  assert.ok(importer.includes('non-target knowledge_items changed during import'));
  assert.ok(importer.includes('non-target rows changed during import'));
  assert.ok(importer.includes('non-target knowledge_items changed during rollback'));
  assert.ok(importer.includes('imported items must be isolated, non-public, status=1'));
  assert.ok(importer.includes('imported item must have exactly one active version'));
  assert.ok(importer.includes('orphan rows in '));
  assert.ok(importer.includes('imported items still present after rollback'));
});

test('security: no hardcoded credentials, parameterized statements, atomic 0600 outputs outside web root', () => {
  assert.doesNotMatch(importer, /DB_PASSWORD/i);
  assert.doesNotMatch(importer, /mysqli_connect/i);
  assert.doesNotMatch(importer, /\bnew\s+PDO\b/i);
  assert.doesNotMatch(importer, /getenv\(/i);
  const insertMatches = [...importer.matchAll(/INSERT (?:IGNORE )?INTO \w+[^;]*VALUES[^;]*/g)];
  assert.ok(insertMatches.length >= 8, 'expected parameterized INSERT statements');
  for (const match of insertMatches) {
    assert.ok(match[0].includes('?'), `INSERT not parameterized: ${match[0].slice(0, 80)}...`);
  }
  const deleteMatches = [...importer.matchAll(/DELETE FROM \w+[^;]*/g)];
  for (const match of deleteMatches) {
    assert.ok(match[0].includes('?'), `DELETE not parameterized: ${match[0]}`);
  }
  assert.ok(importer.includes('@chmod($temporary, 0600)'));
  assert.ok(importer.includes('refusing to overwrite existing file: '));
  assert.ok(importer.includes('output path must be outside the web root: '));
  assert.ok(importer.includes('output directory does not exist: '));
  assert.ok(importer.includes('function atomicJson('));
  assert.ok(importer.includes('require __DIR__ . \'/../api/config.php\''));
  assert.ok(importer.includes('uk_knowledge_items_item_code'));
});

test('schema preflight requires the phase-two migration and target category', () => {
  assert.ok(importer.includes('run migration 202608260001/202608260002 first'));
  assert.ok(importer.includes('run migration 202608260001 first'));
  assert.ok(importer.includes('run migration 202608260002 first'));
  assert.ok(importer.includes('run migration 202608260003 first'));
  assert.ok(importer.includes('const IMPORT_CATEGORY_CODE = \'phase2_import\''));
  assert.ok(importer.includes('SELECT id FROM knowledge_categories WHERE code = ? AND status = 1'));
});

test('v2 package SHA is stable for the checked-in artifact', () => {
  assert.equal(packageSha256, '97d41b3428feafed6ef526f2363ddf09710727afe06e4b1cff8e6de4ac5d66d1');
});
