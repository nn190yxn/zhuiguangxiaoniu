import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const employeeCommon = source('../api/drill/v2/_common.php');
const adminCommon = source('../api/admin/drill/v2/_common.php');
const idempotencyService = source('../api/drill/v2/services/DrillIdempotencyService.php');
const migration = source('../database/migrations/202607270001_drill_api_foundation.sql');
const manifest = source('../database/migration_manifest.php');
const adminPermissions = source('../api/admin/common.php');
const config = source('../api/config.php');

test('drill v2 employee contract uses staff context and stable response envelope', () => {
  assert.match(employeeCommon, /appGetCurrentStaffContext\(\)/);
  assert.match(employeeCommon, /'code'\s*=>\s*\$code/);
  assert.match(employeeCommon, /'message'\s*=>\s*\$message/);
  assert.match(employeeCommon, /'data'\s*=>\s*\$data/);
  assert.match(employeeCommon, /'request_id'\s*=>\s*appRequestId\(\)/);
  assert.match(employeeCommon, /drillV2Error\(405/);
  assert.match(employeeCommon, /drillV2Error\(401/);
  assert.match(employeeCommon, /handleCORS\(\)/);
});

test('drill v2 admin contract enforces named permissions', () => {
  assert.match(adminCommon, /adminHasPermission\(\$permission/);
  assert.match(adminCommon, /drillV2Error\(403/);
  for (const permission of [
    'drill.content_manage',
    'drill.knowledge_manage',
    'drill.rubric_calibrate',
    'drill.plan_publish',
    'drill.review',
    'drill.coaching',
    'drill.analytics_all',
    'drill.migration_manage',
  ]) {
    assert.match(adminPermissions, new RegExp(`'${permission.replace('.', '\\.')}'`));
  }
});

test('drill idempotency service owns transaction, locks replays, and detects conflicts', () => {
  assert.match(idempotencyService, /beginTransaction\(\)/);
  assert.match(idempotencyService, /INSERT IGNORE INTO drill_idempotency_keys/);
  assert.match(idempotencyService, /FOR UPDATE/);
  assert.match(idempotencyService, /hash_equals/);
  assert.match(idempotencyService, /response_json = \?/);
  assert.match(idempotencyService, /rollBack\(\)/);
  assert.match(idempotencyService, /canonicalize\(\$request\)/);
});

test('drill idempotency migration and manifest expose the required identity', () => {
  assert.match(migration, /CREATE TABLE IF NOT EXISTS drill_idempotency_keys/);
  assert.match(migration, /UNIQUE KEY uq_drill_idempotency_identity \(user_id, action, idempotency_key\)/);
  assert.match(migration, /request_hash CHAR\(64\) NOT NULL/);
  assert.match(manifest, /'202607270001'/);
  assert.match(manifest, /'drill_idempotency_keys'/);
  assert.match(manifest, /'uq_drill_idempotency_identity'/);
  assert.match(config, /Content-Type, Authorization, Idempotency-Key, X-Request-ID/);
});
