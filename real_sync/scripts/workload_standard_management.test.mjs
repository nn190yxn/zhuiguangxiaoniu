import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const service = read('api/admin/services/WorkloadRoleRuleAdminService.php');
const migration = read('database/migrations/202607240008_workload_standard_management.sql');
const manifest = read('database/migration_manifest.php');

const endpoints = [
  'api/admin/workload/standards.php',
  'api/admin/workload/standard-items.php',
  'api/admin/workload/standard-copy.php',
  'api/admin/workload/standard-delete.php',
  'api/admin/workload/standard-publish.php',
  'api/admin/workload/standard-disable.php',
];

test('[validates 24.1-24.4] migration adds lifecycle, snapshots, and idempotency storage', () => {
  for (const column of [
    'requires_daily_report',
    'source_rule_version_id',
    'published_by_staff_id',
    'published_at',
    'metric_name_snapshot',
    'unit_snapshot',
    'value_type_snapshot',
  ]) {
    assert.match(migration, new RegExp(`COLUMN_NAME = '${column}'`));
    assert.match(manifest, new RegExp(`'${column}'`));
  }
  assert.match(migration, /CREATE TABLE IF NOT EXISTS workload_standard_idempotency_keys/);
  assert.match(migration, /UNIQUE KEY uq_workload_standard_idempotency \(idempotency_key, action\)/);
});

test('[validates 24.2-24.8] service owns complete draft and publication lifecycle', () => {
  for (const method of ['createDraft', 'updateDraft', 'mutateItems', 'copyToDraft', 'deleteDraft', 'publish', 'disable']) {
    assert.match(service, new RegExp(`function ${method}\\(`));
  }
  assert.match(service, /status IN \('active', 'scheduled'\) FOR UPDATE/);
  assert.match(service, /modify\('-1 day'\)/);
  assert.match(service, /workload_daily_reports WHERE rule_version_id = \?/);
  assert.match(service, /已发布岗位标准保持只读/);
});

test('[validates 24.2-24.4] item rules validate complete fields and unique codes', () => {
  for (const field of [
    'metric_code',
    'metric_name_snapshot',
    'unit_snapshot',
    'value_type_snapshot',
    'is_required',
    'allow_zero',
    'min_value',
    'max_value',
    'need_evidence',
    'min_evidence_count',
    'max_evidence_count',
    'audit_mode',
    'statistic_direction',
    'target_value',
    'sort_order',
  ]) assert.match(service, new RegExp(`'${field}'`));
  assert.match(service, /同一岗位标准内项目编码不能重复/);
  assert.match(service, /项目最小值不能大于最大值/);
});

test('[validates 24.5-24.8] all writes require idempotency, audit, transactions, and cache invalidation', () => {
  assert.match(service, /workload_standard_idempotency_keys/);
  assert.match(service, /beginTransaction\(\)/);
  assert.match(service, /adminRecordOperation\(/);
  assert.match(service, /\$this->cache->invalidate\(/);
  assert.match(service, /Idempotency-Key 已用于不同请求/);
  assert.match(service, /INSERT IGNORE INTO workload_standard_idempotency_keys/);
  assert.match(service, /cache_invalidation_scope/);
  for (const endpoint of endpoints) {
    const source = read(endpoint);
    assert.match(source, /workloadStandardBootstrap/);
    if (!endpoint.endsWith('standards.php')) assert.match(source, /workloadStandardIdempotencyKey\(\)/);
  }
  assert.match(read('api/admin/workload/standards.php'), /workloadStandardIdempotencyKey\(\)/);
});

test('[validates 24.9] role validation uses the enabled organization position dictionary', () => {
  assert.match(service, /organization_positions WHERE position_code = \? AND status = 1/);
  assert.doesNotMatch(service, /\['sales', 'coach'\]/);
});

test('[validates 24.2, 24.6, 24.9] publishing synchronizes templates and metric definitions for every enabled role', () => {
  assert.match(service, /function synchronizePublishedItems\(/);
  assert.match(service, /INSERT INTO metric_definitions/);
  assert.match(service, /INSERT INTO workload_templates/);
  assert.match(service, /INSERT INTO workload_template_items/);
  assert.match(service, /'-standard-' \. \$versionId/);
  assert.match(service, /organization_positions WHERE position_code = \? AND status = 1 LIMIT 1 FOR UPDATE/);
  assert.match(service, /role_code = \? AND is_active = 1/);
  assert.match(migration, /workload_role_rule_versions MODIFY COLUMN role_code VARCHAR\(64\)/);
  assert.match(migration, /metric_definitions MODIFY COLUMN role_code VARCHAR\(64\)/);
  assert.match(migration, /workload_templates MODIFY COLUMN role_code VARCHAR\(64\)/);
  assert.match(migration, /workload_alert_rules MODIFY COLUMN target_role_code VARCHAR\(64\)/);
  assert.match(migration, /workload_alert_events MODIFY COLUMN role_code VARCHAR\(64\)/);
  assert.match(migration, /workload_metric_relations MODIFY COLUMN role_code VARCHAR\(64\)/);
});

test('[validates 24.6, 24.8] scheduled publications use isolated templates and bounded intervals may precede later versions', () => {
  assert.match(service, /function synchronizePublishedItems\(int \$versionId/);
  assert.match(service, /INSERT INTO workload_templates/);
  assert.match(service, /if \(\$effectiveTo !== null && \$effectiveTo < \$existingFrom\)/);
});

test('[validates 24.1, 24.7] lifecycle display and copied-draft differences are derived from persisted dates', () => {
  assert.match(service, /function effectiveStatus\(/);
  assert.match(service, /'stored_status'/);
  assert.match(service, /function difference\(/);
  assert.match(service, /'difference'/);
  assert.match(service, /停用操作仅允许缩短现有有效期/);
});
