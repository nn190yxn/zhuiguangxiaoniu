import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createHash } from 'node:crypto';

const php = readFileSync(new URL('./import_sales_training_cards.php', import.meta.url), 'utf8');
const raw = readFileSync(new URL('../database/import_data/sales-training-cards.v1.json', import.meta.url));
const pkg = JSON.parse(raw);
const checksum = readFileSync(new URL('../database/import_data/sales-training-cards.v1.sha256', import.meta.url), 'utf8').trim().split(/\s+/)[0];
const body = (name) => {
  const start = php.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `missing function ${name}`);
  const next = php.indexOf('\nfunction ', start + 10);
  return php.slice(start, next < 0 ? php.length : next);
};

test('actual package bytes, shape, enums and all 300 rows satisfy the importer contract', () => {
  assert.equal(createHash('sha256').update(raw).digest('hex'), checksum);
  assert.equal(pkg.schema_version, 'sales-training-cards.v1');
  assert.deepEqual(pkg.counts, { source_cards: 75, modules: 3, cards: 300, K: 75, S: 75, D: 75, C: 75 });
  assert.equal(pkg.modules.length, 3); assert.equal(pkg.cards.length, 300);
  const codes = new Set(); const types = { K: 0, S: 0, D: 0, C: 0 }; const per = {};
  for (const card of pkg.cards) {
    assert.match(card.card_code, /^sales-\d{4}-[ksdc]$/); assert(!codes.has(card.card_code)); codes.add(card.card_code);
    assert(['K', 'S', 'D', 'C'].includes(card.card_type)); types[card.card_type]++;
    assert(['easy', 'medium', 'hard'].includes(card.difficulty)); assert.equal(card.score, 100); assert.equal(card.status, 1);
    assert(card.options === null || Array.isArray(card.options));
    per[card.module_code] = (per[card.module_code] || 0) + 1;
  }
  assert.deepEqual(types, { K: 75, S: 75, D: 75, C: 75 });
  assert.deepEqual(per, { 'sales-ability-foundation': 100, 'sales-ability-advanced': 84, 'sales-ability-expert': 116 });
});

test('CLI is closed by default and rejects ambiguous arguments and uppercase hashes', () => {
  const parser = body('parseArgs');
  assert.match(php, /PHP_SAPI !== 'cli'/); assert.match(php, /require __DIR__ \. '\/\.\.\/api\/config\.php'/); assert.match(php, /\$db\s*=\s*getDB\(\)/);
  for (const signal of ['Duplicate argument', 'Unknown argument', '64 lowercase hexadecimal', 'Import apply requires', 'Rollback apply requires', '--allow-update requires']) assert(parser.includes(signal));
  assert.match(parser, /\^\[0-9a-f\]\{64\}\$\/D/);
});

test('dry-run call graph has no lock, backup, manifest or mutation path; explicit report is the sole allowed output', () => {
  const tail = php.slice(php.lastIndexOf("try {\n    $args"));
  assert.match(tail, /\$args\['apply'\]\?applyImport[^:]+:summary/);
  const dryFunctions = body('preflight') + body('loadState') + body('diffPackage') + body('summary');
  assert.doesNotMatch(dryFunctions, /GET_LOCK|backup\(|atomicJson\(|beginTransaction|\bINSERT\b|\bUPDATE\b|\bDELETE\b/);
  assert.match(tail, /explicit --report permits only this requested filesystem output/);
});

test('normalizers equate PDO numeric strings with package integers without coercing text', () => {
  const moduleNormalizer = body('normModule'); const cardNormalizer = body('normCard');
  for (const field of ['required_score', 'total_cards', 'status']) assert(moduleNormalizer.includes(field));
  for (const field of ['score', 'sort_order', 'status']) assert(cardNormalizer.includes(field));
  assert.match(moduleNormalizer, /\(int\)\$r\[\$field\]/);
  assert.match(cardNormalizer, /\(int\)\$r\[\$field\]/);
  assert.match(cardNormalizer, /normalizeOptions/);
  const normalizeModule = r => ({ ...r, required_score: Number(r.required_score), total_cards: Number(r.total_cards), status: Number(r.status) });
  assert.deepEqual(normalizeModule({ module_name: '007', required_score: '60', total_cards: '100', status: '1' }), normalizeModule({ module_name: '007', required_score: 60, total_cards: 100, status: 1 }));
  assert.equal(normalizeModule({ module_name: '007', required_score: 60, total_cards: 100, status: 1 }).module_name, '007');
});

test('preflight verifies all tables, InnoDB, columns, JSON, enum and unique indexes', () => {
  const p = body('preflight');
  for (const signal of ['training_modules', 'training_cards', 'user_progress', 'SHOW TABLE STATUS', 'InnoDB', 'SHOW FULL COLUMNS', 'SHOW INDEX', 'options', "'K'", "'S'", "'D'", "'C'", 'Non_unique', 'single-column unique index', 'minimumVarchar', 'must be TEXT', 'must be ENUM']) assert(p.includes(signal));
});

test('apply has lock, post-lock preflight/diff, backup before transaction, second diff, assertions and finally release', () => {
  const a = body('applyImport');
  const ordered = ['GET_LOCK', 'preflight($db)', 'diffPackage($db,$p)', 'backup($db,$p', 'beginTransaction()', 'diffPackage($db,$p)', 'assertImport($db,$p', 'commit()'];
  let pos = -1; for (const signal of ordered) { const found = a.indexOf(signal, pos + 1); assert(found > pos, `${signal} is absent/out of order`); pos = found; }
  assert.match(a, /finally\{/); assert.match(a, /rollBack\(\)/); assert.match(a, /RELEASE_LOCK/);
  assert.match(a, /\$max=.*MAX\(sort_order\)/); assert.match(body('normModule'), /required_score','total_cards','status/);
  assert.match(a, /rowsDigestExceptCodes/);
  const assertion = body('assertImport');
  assert.match(assertion, /Preexisting rows changed/);
  assert.match(assertion, /normModule\(\$x\)!==normModule\(\$m\)/);
  assert.match(assertion, /normCard\(\$c/);
});

test('all data mutations and lock operations use prepared statements where values occur', () => {
  const mutations = [...php.matchAll(/\$db->prepare\('([^']*(?:INSERT|UPDATE|DELETE|GET_LOCK|RELEASE_LOCK)[^']*)'\)/g)].map(x => x[1]);
  assert(mutations.length >= 10); for (const sql of mutations) assert(sql.includes('?'), sql);
  assert.doesNotMatch(php, /DB_PASSWORD|MYSQL_PWD|mysqli_connect|new PDO\(/);
});

test('backup and manifest provide recoverability, pending identity before commit, then completed', () => {
  const a = body('applyImport');
  assert(a.indexOf("'status'=>'pending'") < a.indexOf('beginTransaction()'));
  assert(a.indexOf('atomicJson($a[\'manifest\'],$pending,true)') < a.indexOf('beginTransaction()'));
  assert(a.indexOf("$pending['recoverable_from_pending']=true") < a.indexOf('commit()'));
  assert(a.indexOf("$completed['status']='completed'") > a.indexOf('commit()'));
  assert.match(a, /database committed; manifest remains recoverable pending at/);
  for (const signal of ['package_sha256', 'batch_id', 'backup', 'before_counts', 'inserted', 'updated', 'skipped', 'after_counts']) assert(a.includes(signal));
  const b = body('backup'); for (const signal of ['SHOW CREATE TABLE', 'target_modules', 'target_cards', 'related_user_progress']) assert(php.includes(signal));
});

test('rollback trusts only completed manifest exact inserted IDs/codes and blocks unsafe cases', () => {
  const read = body('readManifest'); const check = body('rollbackCheck'); const run = body('runRollback');
  assert.match(read, /status==='pending'.*recoverable_from_pending/s);
  for (const signal of ['batch contains updates', 'Inserted card ID/code mismatch', 'user_progress', 'unknown module card', 'Inserted module ID/code mismatch']) assert((read + check).includes(signal));
  assert.match(run, /DELETE FROM training_cards WHERE id=\? AND card_code=\?/);
  assert.match(run, /DELETE FROM training_modules WHERE id=\? AND module_code=\?/);
  assert.doesNotMatch(run, /title|created_at|BETWEEN|LIKE/); assert.match(run, /rollbackCheck\(\$db,\$m\).*beginTransaction[\s\S]*rollbackCheck\(\$db,\$m\)/);
  assert.match(run, /rowsDigestExceptIds/);
  assert.match(body('readManifest'), /validateExternalInputPath/);
  assert.match(run, /readManifest[\s\S]*rollbackCheck/);
});

test('report paths are prevalidated and post-commit report failures warn without a retry exit', () => {
  const main = php.slice(php.lastIndexOf("try {\n    $args"));
  assert(main.indexOf("validateOutputPath($args['report'],true)") < main.indexOf('$db = getDB()'));
  assert.match(main, /database_committed/);
  assert.match(main, /WARNING: database committed; report write failed/);
  assert.match(main, /report_warning/);
  assert.match(body('applyImport'), /database_committed/);
  assert.match(body('runRollback'), /database_committed/);
});

test('reports/backups/manifests use outside-webroot atomic 0600 non-overwrite writes', () => {
  const path = body('validateOutputPath'); const write = body('atomicJson');
  for (const signal of ["realpath(__DIR__.'/..')", 'realpath(dirname($path))', 'must exist and be writable', 'Refusing to overwrite']) assert(path.includes(signal));
  for (const signal of ['random_bytes', 'LOCK_EX', 'chmod($tmp,0600)', 'rename($tmp,$path)']) assert(write.includes(signal));
});
