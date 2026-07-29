import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const common = source('../api/admin/drill/v2/_common.php');
const service = source('../api/drill/v2/services/DrillAdminApiService.php');
const permissions = source('../api/admin/common.php');

test('管理端资源逐一绑定具名权限与幂等写入', () => {
  const permissionsByResource = {
    domains: 'drill.content_manage', stages: 'drill.content_manage', scenarios: 'drill.content_manage',
    rubrics: 'drill.content_manage', 'reference-materials': 'drill.content_manage',
    'knowledge-map': 'drill.knowledge_manage', calibrations: 'drill.rubric_calibrate',
    plans: 'drill.plan_publish', reviews: 'drill.review', coaching: 'drill.coaching',
    analytics: 'drill.analytics_all', migrations: 'drill.migration_manage',
  };
  for (const [resource, permission] of Object.entries(permissionsByResource)) {
    const endpoint = source(`../api/admin/drill/v2/${resource}.php`);
    const genericHandler = new RegExp(`drillAdminV2Handle\\('${resource}', '${permission.replace('.', '\\.')}\\'`);
    const dedicatedHandler = new RegExp(`drillAdminV2Bootstrap\\('${permission.replace('.', '\\.')}\\'`);
    assert.ok(genericHandler.test(endpoint) || dedicatedHandler.test(endpoint));
  }
  assert.match(common, /drillV2RunIdempotent\(/);
  assert.match(permissions, /if \(\$role === 'manager'\)/);
});

test('范围策略将复核人、店长和总部角色隔离', () => {
  assert.match(service, /'scope_type' => 'all'/);
  assert.match(service, /'scope_type' => 'stores'/);
  assert.match(service, /'scope_type' => 'reviewer'/);
  assert.match(service, /reviewer_staff_id/);
  assert.match(service, /staff_assignments WHERE store_id IN/);
  assert.match(service, /DrillAdminScopeDeniedException/);
  assert.match(common, /drillV2Error\(403/);
});

test('统计输出样本规模和低样本标记，迁移端点提供预检、执行与重试契约', () => {
  assert.match(service, /'low_sample' => count\(\$staffIds\) < 3 \|\| count\(\$items\) < 10/);
  assert.match(service, /DrillMigrationService/);
  assert.match(service, /'execute'/);
  assert.match(service, /'retry_failed'/);
});
