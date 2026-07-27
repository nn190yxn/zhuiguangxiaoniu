import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [service, endpoint, permission] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadMetricDetailService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/analytics/metric-detail.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadPermissionScopeService.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

test(`${validatesCriteria(['15.2', '15.3'])} metric detail reuses filtered facts and permission scope`, () => {
  assert.match(service, /new WorkloadAnalyticsQueryService\(\$pdo\)/);
  assert.match(service, /\$this->analytics->facts\(\$input, \$context\)/);
  assert.match(service, /'permission_scope' => \$facts\['permission_scope'\]/);
  assert.match(service, /array_slice\(\$facts\['rows'\]/);
  assert.match(service, /'pagination'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /\$_GET, \$context/);
  assert.match(endpoint, /workload\.analytics\.metric_detail/);
  assert.match(permission, /'ranking_scope' => \$rankingScope/);
});
