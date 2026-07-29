import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('统计服务覆盖参与、完成、通过、平均尝试、复核、辅导、维度和低样本', () => {
  const service = source('../api/drill/v2/services/DrillAnalyticsService.php');
  for (const field of ['participation_count', 'completion_count', 'pass_rate', 'average_attempts', 'pending_review_count', 'needs_coaching_count', 'dimension_distribution', 'low_sample']) {
    assert.match(service, new RegExp(field));
  }
  assert.match(service, /count\(\$staffIds\) < 3 \|\| \$attempts < 10/);
  for (const dimension of ['staff', 'stage', 'plan', 'status', 'store_id', 'position_id']) assert.match(service, new RegExp(dimension));
});

test('治理作业只登记到期并留下审计，AI 队列和迁移监控可查询', () => {
  const service = source('../api/drill/v2/services/DrillGovernanceService.php');
  const worker = source('./drill-governance-worker.php');
  assert.match(service, /audio_expiry_pending/);
  assert.match(service, /ai_retry_pending/);
  assert.match(service, /migration_failed/);
  assert.match(service, /audio\.retention_expired/);
  assert.match(service, /physical_cleanup.*manual_or_deployment_worker_required/);
  assert.match(worker, /--apply/);
  assert.doesNotMatch(service, /\bDELETE\s+FROM\b/i);
});

test('切换服务仅执行对账、预检和回滚演练计划', () => {
  const service = source('../api/drill/v2/services/DrillCutoverService.php');
  const migration = source('../database/migrations/202607280008_drill_analytics_governance_cutover.sql');
  for (const entity of ['tasks', 'attempts', 'recordings', 'analyses', 'certifications']) assert.match(service, new RegExp(entity));
  assert.match(service, /production_switch.*not_executed/);
  assert.match(service, /production_rollback.*not_executed/);
  assert.match(service, /retain_v2_as_readonly/);
  assert.match(migration, /drill_cutover_reconciliations/);
  assert.match(migration, /drill_cutover_rollback_drills/);
  assert.doesNotMatch(migration, /\b(?:DELETE\s+FROM|DROP\s+(?:TABLE|COLUMN|INDEX))\b/i);
});

test('治理与切换端点使用具名权限、认证和幂等写入', () => {
  const analytics = source('../api/admin/drill/v2/analytics.php');
  const governance = source('../api/admin/drill/v2/governance.php');
  const cutover = source('../api/admin/drill/v2/cutover.php');
  assert.match(analytics, /drill\.analytics_all/);
  assert.match(governance, /drill\.analytics_all/);
  assert.match(cutover, /drill\.migration_manage/);
  assert.match(governance, /drillV2RunIdempotent/);
  assert.match(cutover, /drillV2RunIdempotent/);
});
