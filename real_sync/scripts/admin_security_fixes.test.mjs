import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';

const repoRoot = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, repoRoot), 'utf8');

test('设备安全概览端点存在且使用审计权限与聚合查询', () => {
  const endpoint = read('api/admin/security/device-overview.php');
  assert.match(endpoint, /adminRequirePermission\('staff\.audit_view'\)/);
  assert.match(endpoint, /view=alerts|'alerts'/);
  assert.match(endpoint, /COUNT\(DISTINCT dl\.device_fingerprint\)/);
  assert.match(endpoint, /staff_name/);
  assert.match(endpoint, /unique_devices/);
  assert.ok(existsSync(new URL('api/admin/security/device-overview.php', repoRoot)));
});

test('设备统计端点封堵越权：查他人需审计权限', () => {
  const device = read('api/statistics/device.php');
  assert.match(device, /adminRequirePermission\('staff\.audit_view'\)/);
  assert.match(device, /\$requestedStaffId > 0 && \$requestedStaffId !== \(int\)\$staffId/);
});

test('设备安全页改调新概览端点', () => {
  const page = read('admin/security-devices.html');
  assert.match(page, /api\/admin\/security\/device-overview\.php\?view=alerts/);
  assert.match(page, /api\/admin\/security\/device-overview\.php\?view=usage/);
  assert.doesNotMatch(page, /api\/statistics\/device\.php\?view=/);
});

test('知识审核双页不再引用幽灵鉴权函数', () => {
  assert.doesNotMatch(read('admin/knowledge-age-review.html'), /getRequestAuthHeaders/);
  assert.doesNotMatch(read('admin/knowledge-enrichment.html'), /getRequestAuthHeaders/);
  assert.match(read('admin/knowledge-age-review.html'), /window\.authHeaders/);
  assert.match(read('admin/knowledge-enrichment.html'), /window\.authHeaders/);
});

test('统计接口移除人名手机号白名单后门', () => {
  assert.doesNotMatch(read('api/statistics/staff.php'), /何梓辛|18285031172/);
  assert.doesNotMatch(read('api/statistics/store.php'), /何梓辛|18285031172/);
});

test('internal-auth 暴露统一页面权限辅助并清理旧路径', () => {
  const auth = read('internal-auth.js');
  assert.match(auth, /adminPageAllowed/);
  assert.match(auth, /requireAdminPage/);
  assert.match(auth, /level === 'system'/);
  assert.match(auth, /level === 'admin-only'/);
  assert.doesNotMatch(auth, /\/ai-drill/);
  assert.doesNotMatch(auth, /\/skill-review/);
});

test('此前裸奔的管理页接入统一权限口径', () => {
  for (const page of [
    'admin/learning.html',
    'admin/recruitment-requirements.html',
    'admin/recruitment-rules.html',
  ]) {
    assert.match(read(page), /adminPageAllowed\(['"]headquarter['"],\s*user\)/, page);
  }
  assert.match(read('assets/pages/admin-staffs.js'), /adminPageAllowed\('headquarter', user\)/);
  assert.match(read('admin/drill.html'), /__DRILL_AUTH_READY__/);
  assert.match(read('js/drill-admin.js'), /__DRILL_ADMIN_AUTH_OK__/);
});

test('公共工具就位且高危弹窗收敛为轻提示', () => {
  assert.ok(existsSync(new URL('assets/pages/admin-common.js', repoRoot)));
  const common = read('assets/pages/admin-common.js');
  assert.match(common, /adminToast/);
  assert.match(common, /escapeHtml/);
  assert.match(common, /authFetch/);
  for (const js of ['assets/pages/admin-staffs.js', 'assets/pages/admin-wecom.js']) {
    const src = read(js);
    assert.match(src, /window\.AdminCommon\.adminToast\(/, js);
    assert.doesNotMatch(src, /window\.alert\(/, js);
  }
  assert.doesNotMatch(read('admin/recruitment-requirements.html'), /window\.prompt\('请输入退回原因'\)/);
  assert.match(read('admin/recruitment-requirements.html'), /openReturnModal/);
});

test('招聘规则分数线优先取接口返回值', () => {
  const page = read('admin/recruitment-rules.html');
  assert.match(page, /currentGradeRules=rule\.grade_rules&&rule\.grade_rules\.A\?rule\.grade_rules/);
  assert.match(page, /min:Number\(currentGradeRules\.A\.min\)\|\|80/);
});

test('废弃端点与旧路径残留清理', () => {
  assert.equal(existsSync(new URL('api/admin/drill/v2/cutover.php', repoRoot)), false);
  assert.equal(existsSync(new URL('api/admin/drill/v2/governance.php', repoRoot)), false);
});
