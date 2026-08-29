import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const endpoint = readFileSync(new URL('../api/records/index.php', import.meta.url), 'utf8');
const adminEndpoint = readFileSync(new URL('../api/admin/fitness-reports.php', import.meta.url), 'utf8');
const page = readFileSync(new URL('../fitness-assessment-app.html', import.meta.url), 'utf8');
const adminPage = readFileSync(new URL('../admin/fitness-reports.html', import.meta.url), 'utf8');

test('fitness records bind writes to the authenticated employee scope', () => {
  assert.match(endpoint, /appGetCurrentStaffContext\(\)/);
  assert.match(endpoint, /created_by_user_id/);
  assert.match(endpoint, /created_by_staff_id/);
  assert.match(endpoint, /'store_id' => \$contextStoreId/);
  assert.match(endpoint, /\(\$context\['role'\] \?\? ''\) === 'coach'/);
});

test('fitness records enforce headquarters, manager-store, and employee-self visibility', () => {
  assert.match(endpoint, /function records_scope\(array \$context, array \$record\)/);
  assert.match(endpoint, /!empty\(\$context\['is_hq'\]\)/);
  assert.match(endpoint, /\$recordStoreId === \(int\) \(\$context\['store_id'\] \?\? 0\)/);
  assert.match(endpoint, /\$recordUserId === \(int\) \(\$context\['user_id'\] \?\? 0\)/);
});

test('fitness records provide server-side filters, pagination, and detail lookup', () => {
  for (const field of ['date_from', 'date_to', 'store', 'coach', 'student', 'status']) {
    assert.match(endpoint, field === 'store'
      ? /'store' => 'coach_store'/
      : field === 'coach'
        ? /'coach' => 'coach_name'/
        : field === 'student'
          ? /'student' => 'child_name'/
          : new RegExp(`_GET\\['${field}'\\]`));
  }
  assert.match(endpoint, /page_size/);
  assert.match(endpoint, /records_is_detail_request/);
  assert.match(endpoint, /记录不存在或无权访问/);
  assert.match(endpoint, /X-Records-Total/);
});

test('fitness records preserve complete data and summary compatibility', () => {
  for (const field of ['test_data', 'image_ratings', 'assessment_items', 'coach_context', 'goals', 'report_content', 'generation_mode', 'report_status']) {
    assert.match(endpoint, new RegExp(`'${field}'`));
  }
  assert.match(endpoint, /function records_public_list_item/);
  assert.match(endpoint, /data_completeness/);
});

test('fitness assessment saves the rendered report and complete input context', () => {
  assert.match(page, /saveReportRecord\(results\)/);
  for (const field of ['test_data', 'image_ratings', 'assessment_items', 'coach_context', 'goals', 'report_content', 'generation_mode']) {
    assert.match(page, new RegExp(`${field}:`));
  }
  assert.match(page, /trainingPlanContent'\)\.innerHTML/);
  assert.match(page, /function loadReportHistory\(\)/);
  assert.match(page, /function showReportDetail\(encodedId\)/);
  assert.match(page, /function sanitizeReportHtml\(content\)/);
  assert.match(page, /querySelectorAll\('script,style,iframe,object,embed,form'\)/);
});

test('fitness report analytics enforce admin permission and server-side scope', () => {
  assert.match(adminEndpoint, /require_once __DIR__ \. '\/common\.php'/);
  assert.match(adminEndpoint, /adminRequirePermission\('drill\.analytics_all'\)/);
  assert.match(adminEndpoint, /function fitness_reports_scope\(array \$context, array \$record\)/);
  assert.match(adminEndpoint, /is_writable\(\$path\)/);
  assert.match(adminEndpoint, /distinct_student_count/);
  assert.match(adminEndpoint, /fallback_report_count/);
  assert.match(adminEndpoint, /date_from/);
  assert.match(adminEndpoint, /date_to/);
  assert.match(adminEndpoint, /statusFilter/);
  assert.match(adminEndpoint, /generation_mode.*fallback/);
});

test('fitness report analytics page exposes filters, summary cards, and group tables', () => {
  for (const field of ['dateFrom', 'dateTo', 'store', 'coach', 'status']) assert.match(adminPage, new RegExp(field));
  for (const label of ['报告次数', '去重学员', '本地兜底', '今日报告', '本月报告']) assert.match(adminPage, new RegExp(label));
  assert.match(adminPage, /api\/admin\/fitness-reports\.php/);
});
