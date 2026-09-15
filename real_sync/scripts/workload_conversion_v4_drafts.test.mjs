import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const migration = read('../database/migrations/202608010003_workload_conversion_v4_drafts.sql');
const manifest = read('../database/migration_manifest.php');
const previewService = read('../api/workload/services/WorkloadConversionPreviewService.php');
const previewEndpoint = read('../api/workload/conversion-preview.php');
const context = read('../api/common/context.php');
const workloadPage = read('../mini-program/pages/workload/index.js');
const managePage = read('../mini-program/pages/workload/manage.js');

test('[validates 5, 5.1] V4.0 drafts cover five roles and remain inactive before publication', () => {
  assert.match(manifest, /'202608010003'/);
  for (const versionCode of ['sales-v4-draft', 'coach-v4-draft', 'manager-v4-draft', 'teaching-supervisor-v4-draft', 'supervisor-v4-draft']) {
    assert.match(migration, new RegExp(`'${versionCode}'`));
  }
  assert.match(migration, /'draft'/);
  assert.match(migration, /'2026-08-01'/);
  assert.doesNotMatch(migration, /\bDROP\s+(?:TABLE|COLUMN|INDEX)\b/i);
  assert.doesNotMatch(migration, /\bTRUNCATE\b/i);
  assert.doesNotMatch(migration, /\bDELETE\s+FROM\b/i);
});

test('[validates 5, 5.1] V4.0 preserves agreed conversion and mixed-evidence rules', () => {
  assert.match(migration, /'sales-deal-amount'.*'step', 4000, 1, NULL/s);
  assert.match(migration, /'manager-order-amount'.*'threshold', 500, 1/s);
  assert.match(migration, /'manager-poi'.*'\["manager_store_poi_checkin"\]'.*'\["image","screenshot","manager_confirmation"\]'/s);
  for (const role of ['teaching_supervisor', 'supervisor']) {
    assert.match(migration, new RegExp(`'${role}'.*'\\["image","screenshot","manager_confirmation"\\]'`, 's'));
  }
});

test('[validates 5] preview is read-only, compares draft rules, and requires headquarters access', () => {
  assert.match(previewService, /WHERE version_code = \? AND role_code = \? LIMIT 1/);
  assert.match(previewService, /\$draft\['status'\] !== 'draft'/);
  assert.match(previewService, /activeForDate/);
  assert.match(previewService, /'change_type'/);
  assert.match(previewEndpoint, /\$_SERVER\['REQUEST_METHOD'\].*!== 'GET'/s);
  assert.match(previewEndpoint, /appCanEditAll/);
  assert.match(previewEndpoint, /WorkloadConversionPreviewService/);
});

test('[validates 5.1] every V4.0 role can enter the workload and management views', () => {
  for (const role of ['sales', 'coach', 'manager', 'teaching_supervisor', 'supervisor']) {
    assert.match(context, new RegExp(`'${role}' => '${role}'|\[.*'${role}'.*\]`, 's'));
    assert.match(workloadPage, new RegExp(`value: '${role}'`));
    assert.match(managePage, new RegExp(`value: '${role}'`));
  }
  assert.match(context, /'教学主管' => 'teaching_supervisor'/);
  assert.match(context, /'督导' => 'supervisor'/);
});
