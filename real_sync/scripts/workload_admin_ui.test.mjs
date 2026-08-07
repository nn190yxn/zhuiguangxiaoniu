import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/workload.html', import.meta.url), 'utf8');
const compact = page.replace(/\s+/g, ' ');

test('unified filter bar covers analytics and management dimensions', () => {
  for (const id of ['dateFrom', 'dateTo', 'storeId', 'roleCode', 'staffId', 'metricCode', 'reportStatus', 'auditStatus', 'source']) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  assert.match(page, /id="appliedFilters" aria-live="polite"/);
  for (const field of ['date_from', 'date_to', 'store_id', 'role_code', 'staff_id', 'metric_code', 'report_status', 'audit_status', 'source']) {
    assert.match(compact, new RegExp(field));
  }
});

test('staff view selects accessible employees by name and keeps the selected internal ID', () => {
  assert.match(page, /id="staffName"/);
  assert.match(page, /id="staffMatchList" aria-live="polite"/);
  assert.match(page, /\/api\/workload\/staff-search\.php\?name=/);
  assert.match(page, /function renderStaffMatches\(rows\)/);
  assert.match(page, /个人视图请按姓名选择员工/);
});

test('store detail opens every employee profile and discloses draft handling', () => {
  assert.match(page, /class="staff-link" data-staff-id=/);
  assert.match(page, /function openStaffProfile\(staffId, staffName, trigger\)/);
  assert.match(page, /草稿可查看，未计入完成率和排行/);
  assert.match(page, /草稿可查看，未计入排行/);
});

test('six accessible top-level workspaces expose the complete management loop', () => {
  const workspaces = {
    dashboard: '数据驾驶舱',
    audit: '审核队列',
    funnel: '经营漏斗',
    alerts: '预警建议',
    standards: '岗位标准',
    imports: '导入记录',
  };
  for (const [workspace, label] of Object.entries(workspaces)) {
    assert.match(compact, new RegExp(`role="tab"[^>]+data-workspace="${workspace}"[^>]*> ${label}`));
  }
  assert.match(page, /function setWorkspace\(workspace, trigger\)/);
  assert.match(page, /id="analyticsTabs"[\s\S]*aria-label="驾驶舱分析视图"/);
});

test('dashboard retains five analytics views and unified endpoints', () => {
  for (const view of ['dashboard', 'store', 'metric', 'staff', 'matrix']) {
    assert.match(compact, new RegExp(`role="tab"[^>]+data-view="${view}"`));
  }
  for (const endpoint of ['store-completion', 'metric-selection', 'staff-profile', 'cross-analysis', 'metric-detail']) {
    assert.match(page, new RegExp(`/api/workload/analytics/${endpoint}\\.php`));
  }
  assert.match(page, /Promise\.all\(/);
});

test('management workspaces use their scoped backend contracts', () => {
  for (const endpoint of [
    '/api/workload/audit-list.php',
    '/api/workload/audit-action.php',
    '/api/workload/analytics/operating-funnel.php',
    '/api/workload/alerts.php',
    '/api/admin/workload/standards.php',
    '/api/admin/workload/standard-import.php',
    '/api/admin/workload/standard-import-batches.php',
  ]) {
    assert.ok(page.includes(endpoint), endpoint);
  }
  assert.match(page, /include_history=1/);
  assert.match(page, /Idempotency-Key/);
});

test('alert resolution submits the fields required by the management endpoint', () => {
  assert.match(page, /function openAlertAction\(row\)[\s\S]*handler_comment[\s\S]*event_id: row\.id[\s\S]*comment: comment/);
});

test('audit, funnel, alert, standard, and import renderers disclose business context', () => {
  assert.match(page, /function renderAuditQueue\(data\)/);
  assert.match(page, /audit_logs/);
  assert.match(page, /evidence_urls/);
  assert.match(page, /function renderOperatingFunnel\(data\)[\s\S]*relation_version[\s\S]*sample_size/);
  assert.match(page, /function renderAlerts\(data\)[\s\S]*rule_basis/);
  assert.match(page, /function renderAlerts\(data\)[\s\S]*row\.evidence\b/);
  assert.match(page, /function renderStandards\(data\)[\s\S]*standard-publish[\s\S]*standard-disable/);
  assert.match(page, /function renderImports\(data\)[\s\S]*standardImportFile[\s\S]*import-confirm/);
});

test('permission-aware defaults constrain stores, staff, and standard administration', () => {
  assert.match(page, /role === "manager"[\s\S]*currentView = "store"/);
  assert.match(page, /currentView = "staff"[\s\S]*staffId[\s\S]*readOnly = true/);
  assert.match(page, /storeId[\s\S]*disabled = true/);
  assert.match(page, /data-workspace=\"standards\"[\s\S]*data-workspace=\"imports\"[\s\S]*tab\.hidden/);
});

test('export remains scoped to dashboard views and handles direct or asynchronous downloads', () => {
  for (const exportType of ['store_completion', 'metric_selection', 'staff_full_data', 'metric_full_dimension']) {
    assert.match(page, new RegExp(exportType));
  }
  assert.match(page, /workspace !== "dashboard"/);
  assert.match(page, /downloadExportJob\(jobId, headers\)/);
  assert.match(page, /job\.download_ready/);
  assert.match(page, /URL\.createObjectURL\(blob\)/);
});

test('desktop tables and narrow-screen cards share core drilldown actions', () => {
  assert.match(page, /class="table-wrap"/);
  assert.match(page, /class="mobile-cards"/);
  for (const action of ['store', 'metric', 'staff-record', 'matrix', 'audit-open']) {
    assert.ok(page.includes(`data-action="${action}`) || page.includes(`data-business="${action}`), action);
  }
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*\.table-wrap[\s\S]*display: none[\s\S]*\.mobile-cards[\s\S]*display: grid/);
});
