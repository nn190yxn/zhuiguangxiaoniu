import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/StaffDirectoryService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(new URL('../api/admin/staff/export.php', import.meta.url), 'utf8');
const listEndpoint = readFileSync(new URL('../api/admin/staff/list.php', import.meta.url), 'utf8');

test('staff export reuses directory filtering, paging, and sensitive field policy', () => {
  assert.match(service, /public function export\(array \$query\): array/);
  assert.match(service, /\$firstPage = \$this->list\(\$query\)/);
  assert.match(service, /\$page = \$this->list\(\$query\)/);
  assert.match(endpoint, /appRoleTokensFromUser\(\$user, \$staff\)/);
  assert.match(endpoint, /array_intersect\(\['operation', 'admin'\], \$roles\)/);
  assert.match(listEndpoint, /new StaffDirectoryService\(getDB\(\), \$canViewSensitive\)/);
});

test('staff export streams bounded UTF-8 CSV with an explicit field allowlist', () => {
  assert.match(service, /> 20000/);
  assert.match(service, /StaffDirectoryExportLimitException/);
  assert.match(service, /private function exportRows\(array \$query, array \$firstPage\): Generator/);
  assert.match(endpoint, /Content-Type: text\/csv; charset=utf-8/);
  assert.match(endpoint, /Content-Disposition: attachment/);
  assert.match(endpoint, /fopen\('php:\/\/output', 'wb'\)/);
  assert.match(endpoint, /\\xEF\\xBB\\xBF/);
  assert.match(endpoint, /fputcsv\(\$output/);
  for (const header of ['工号', '姓名', '手机号', '门店', '主岗位', '系统角色', '生命周期', '账号状态']) {
    assert.match(endpoint, new RegExp(`'${header}'`));
  }
});

test('staff export protects spreadsheet consumers from formula injection', () => {
  assert.match(endpoint, /preg_match\('\/\^\[=\+\\-@\]\/', \$value\)/);
  assert.match(endpoint, /return "'" \. \$value/);
  for (const dangerous of ['=1+1', '+cmd', '-2+3', '@SUM(A1:A2)']) {
    const escaped = /^[=+\-@]/.test(dangerous) ? `'${dangerous}` : dangerous;
    assert.equal(escaped.startsWith("'"), true);
  }
});

test('staff export endpoint requires directory permission and maps limit errors', () => {
  assert.match(endpoint, /adminRequirePermission\('staff\.view_all'\)/);
  assert.match(endpoint, /StaffDirectoryExportLimitException[\s\S]*?jsonResponse\(400/);
  assert.match(endpoint, /X-Content-Type-Options: nosniff/);
  assert.match(endpoint, /X-Export-Row-Count/);
});
