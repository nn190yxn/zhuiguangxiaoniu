import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');

test('legacy migration uses stable keys and additive read-only storage', () => {
  const service = source('../api/drill/v2/services/DrillMigrationService.php');
  const migration = source('../database/migrations/202607280007_drill_legacy_migration.sql');
  assert.match(service, /drill-migration:v1/);
  assert.match(service, /SELECT \* FROM `'.*'`/);
  assert.match(service, /duplicateIds/);
  assert.match(service, /orphanCount/);
  assert.match(migration, /drill_migration_batches/);
  assert.match(migration, /drill_legacy_history_instances/);
  assert.match(migration, /drill_legacy_feedback_mappings/);
  assert.match(service, /drill_content_import_batches/);
  assert.match(service, /drill_content_import_items/);
  assert.doesNotMatch(migration, /(?:UPDATE|DELETE|INSERT)\s+(?:INTO\s+)?`?(?:drill_templates|drill_scripts|user_drill_tasks|drill_recordings|script_ai_feedback)`?/i);
});

test('migration supports idempotent batches, retries, and exact conservation reports', () => {
  const service = source('../api/drill/v2/services/DrillMigrationService.php');
  const migration = source('../database/migrations/202607280007_drill_legacy_migration.sql');
  assert.match(migration, /uk_drill_migration_batches_key/);
  assert.match(service, /idempotent_replay/);
  assert.match(service, /retryFailed/);
  assert.match(service, /accounted_total.*input_total/);
  assert.match(service, /=== \$report\['input_total'\]/);
});

test('legacy feedback IDs resolve through the compatibility adapter', () => {
  const adapter = source('../api/drill/v2/services/DrillLegacyFeedbackAdapter.php');
  const oldEndpoint = source('../api/drill/recording-feedback.php');
  const historyEndpoint = source('../api/drill/v2/legacy-feedback.php');
  const client = source('../mini-program/pages/drill/feedback/feedback.js');
  assert.match(adapter, /historyForLegacyFeedback/);
  assert.match(oldEndpoint, /resolveRecordingId/);
  assert.match(historyEndpoint, /legacy_feedback_id/);
  assert.match(historyEndpoint, /readonly/);
  assert.match(client, /source === 'analysis'/);
  assert.match(client, /recording-feedback\.php\?recording_id=/);
  assert.match(client, /const feedback = response\.data \|\| null/);
  assert.match(client, /this\.setData\(\{\s*feedback,/s);
  assert.match(client, /retryFeedback\(\)/);
  assert.doesNotMatch(client, /feedback: summary/);
});
