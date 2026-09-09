import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const api = readFileSync(path.join(root, 'api/admin/knowledge-age-review.php'), 'utf8');
const page = readFileSync(path.join(root, 'admin/knowledge-age-review.html'), 'utf8');
const dashboard = readFileSync(path.join(root, 'admin/dashboard.html'), 'utf8');

test('后台年龄审核页面覆盖队列、证据和确认动作', () => {
  assert.match(api, /adminRequirePermission\('knowledge_audit'\)/);
  assert.match(api, /review_status/);
  assert.match(api, /source_text/);
  assert.match(api, /knowledge_audit_logs/);
  assert.match(api, /review_status = \?/);
  assert.match(page, /知识卡/);
  assert.match(page, /填写确认或驳回原因/);
  assert.match(page, /data-action="confirm"/);
  assert.match(page, /data-action="reject"/);
  assert.match(dashboard, /knowledge-age-review\.html/);
});

test('年龄审核写入使用事务并保留审核原因', () => {
  assert.match(api, /beginTransaction\(\)/);
  assert.match(api, /commit\(\)/);
  assert.match(api, /metadata_json/);
  assert.match(api, /reason.*note/s);
});
