import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const sqlPath = path.join(repoRoot, 'real_sync', 'database', 'migrations', '202608260001_knowledge_card_phase2_schema.sql');
const seedSqlPath = path.join(repoRoot, 'real_sync', 'database', 'migrations', '202608260002_knowledge_card_phase2_seed_categories.sql');
const manifestIntegritySqlPath = path.join(repoRoot, 'real_sync', 'database', 'migrations', '202608260003_knowledge_card_manifest_integrity.sql');
const catalogPath = path.join(repoRoot, 'real_sync', 'database', 'migration_catalog.php');
const manifestPath = path.join(repoRoot, 'real_sync', 'database', 'migration_manifest.php');
const sql = readFileSync(sqlPath, 'utf8');
const seedSql = readFileSync(seedSqlPath, 'utf8');
const manifestIntegritySql = readFileSync(manifestIntegritySqlPath, 'utf8');

const expectedTables = [
  'knowledge_import_batches',
  'knowledge_item_versions',
  'knowledge_item_sources',
  'knowledge_item_relations',
  'knowledge_favorites',
  'knowledge_recent_views',
  'knowledge_audit_logs',
];
const expectedCoreColumns = [
  'item_code', 'content_type', 'domain_code', 'risk_level', 'publication_status', 'source_batch_id', 'current_version_id',
];
const expectedIndexes = [
  'uk_knowledge_items_item_code', 'idx_knowledge_items_publication', 'idx_knowledge_items_content_type', 'idx_knowledge_items_domain_risk',
  'uk_knowledge_import_batches_package', 'uk_knowledge_item_versions_number', 'uk_knowledge_item_sources_batch_card',
  'uk_knowledge_item_relations_pair', 'uk_knowledge_favorites_user_item', 'uk_knowledge_recent_views_user_item',
];

test('schema migration is additive, deterministic, and registered with its checksum', () => {
  assert.match(sql, /^SET NAMES utf8mb4;/);
  assert.doesNotMatch(sql, /^\s*(DROP|TRUNCATE|DELETE|UPDATE|INSERT)\b/im);
  assert.match(sql, /CREATE TABLE IF NOT EXISTS `knowledge_import_batches`/);
  for (const table of expectedTables) assert.ok(sql.includes(`CREATE TABLE IF NOT EXISTS \u0060${table}\u0060`), table);
  for (const column of expectedCoreColumns) {
    assert.match(sql, new RegExp(`ADD COLUMN ${column}\\b`));
    assert.match(readFileSync(manifestPath, 'utf8'), new RegExp(`['"]${column}['"]`));
  }
  for (const index of expectedIndexes) {
    assert.ok(sql.includes(`KEY \u0060${index}\u0060`) || sql.includes(`KEY ${index} `), index);
    assert.match(readFileSync(manifestPath, 'utf8'), new RegExp(`['"]${index}['"]`));
  }
  const catalog = readFileSync(catalogPath, 'utf8');
  const checksum = createHash('sha256').update(readFileSync(sqlPath)).digest('hex');
  assert.match(catalog, new RegExp(`'202608260001'\\s*=>\\s*'${checksum}'`));
  assert.match(manifestPath ? readFileSync(manifestPath, 'utf8') : '', /'202608260001'\s*=>/);
});

test('schema migration is repeat-safe for every additive core change', () => {
  for (const column of expectedCoreColumns) {
    assert.match(sql, new RegExp(`EXISTS\\(SELECT 1 FROM information_schema\\.COLUMNS[\\s\\S]*COLUMN_NAME = '${column}'\\)`));
  }
  for (const index of expectedIndexes.slice(0, 4)) {
    assert.match(sql, new RegExp(`EXISTS\\(SELECT 1 FROM information_schema\\.STATISTICS[\\s\\S]*INDEX_NAME = '${index}'\\)`));
  }
  assert.match(sql, /information_schema\.TABLE_CONSTRAINTS[\s\S]*fk_knowledge_items_source_batch/);
  assert.match(sql, /information_schema\.TABLE_CONSTRAINTS[\s\S]*fk_knowledge_items_current_version/);
  assert.match(sql, /publication_status VARCHAR\(16\) NOT NULL DEFAULT ''published''/);
  assert.match(sql, /publication_default|isolated/);
});

test('phase-two import category seed is idempotent and registered', () => {
  assert.match(seedSql, /^-- 202608260002/);
  assert.match(seedSql, /INSERT IGNORE INTO `knowledge_categories`/);
  assert.match(seedSql, /'phase2_import'/);
  assert.match(seedSql, /'knowledge_card'/);
  assert.doesNotMatch(seedSql, /^\s*(DROP|TRUNCATE|DELETE|UPDATE)\b/im);
  const catalog = readFileSync(catalogPath, 'utf8');
  const checksum = createHash('sha256').update(readFileSync(seedSqlPath)).digest('hex');
  assert.match(catalog, new RegExp(`'202608260002'\\s*=>\\s*'${checksum}'`));
  assert.match(catalog, /phase2_import_category_seeded/);
  assert.match(readFileSync(manifestPath, 'utf8'), /'202608260002'\s*=>/);
});

test('manifest integrity migration is additive, repeat-safe, and registered', () => {
  assert.match(manifestIntegritySql, /ADD COLUMN manifest_sha256 CHAR\(64\) NULL/);
  assert.match(manifestIntegritySql, /information_schema\.COLUMNS[\s\S]*COLUMN_NAME = 'manifest_sha256'/);
  assert.match(manifestIntegritySql, /chk_knowledge_import_batches_manifest_sha256/);
  assert.doesNotMatch(manifestIntegritySql, /^\s*(DROP|TRUNCATE|DELETE|UPDATE|INSERT)\b/im);
  const catalog = readFileSync(catalogPath, 'utf8');
  const checksum = createHash('sha256').update(readFileSync(manifestIntegritySqlPath)).digest('hex');
  assert.match(catalog, new RegExp(`'202608260003'\\s*=>\\s*'${checksum}'`));
  assert.match(readFileSync(manifestPath, 'utf8'), /'202608260003'\s*=>[\s\S]*'manifest_sha256'/);
});

test('relationships preserve existing knowledge IDs and historical rows', () => {
  assert.match(sql, /FOREIGN KEY \(`knowledge_item_id`\) REFERENCES `knowledge_items` \(`id`\) ON DELETE RESTRICT/);
  assert.match(sql, /FOREIGN KEY \(`source_item_id`\) REFERENCES `knowledge_items` \(`id`\) ON DELETE RESTRICT/);
  assert.match(sql, /FOREIGN KEY \(`target_item_id`\) REFERENCES `knowledge_items` \(`id`\) ON DELETE RESTRICT/);
  assert.match(sql, /FOREIGN KEY \(`knowledge_id`\) REFERENCES `knowledge_items` \(`id`\) ON DELETE RESTRICT/);
  assert.doesNotMatch(sql, /ALTER TABLE knowledge_items DROP|DROP COLUMN|DROP INDEX/i);
  assert.doesNotMatch(sql, /user_knowledge_progress/);
  assert.doesNotMatch(sql, /drill_templates/);
});
