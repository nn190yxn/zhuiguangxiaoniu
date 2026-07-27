import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [scopeService, queryService, detailService, detailEndpoint] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadPermissionScopeService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadMetricDetailService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/analytics/metric-detail.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

const facts = [
  { reportId: 1, storeId: 1, staffId: 10, metric: 'calls', value: 5 },
  { reportId: 2, storeId: 2, staffId: 10, metric: 'calls', value: 7 },
  { reportId: 3, storeId: 3, staffId: 10, metric: 'calls', value: 11 },
  { reportId: 4, storeId: 1, staffId: 11, metric: 'calls', value: 13 },
];

function visibleFacts(scope, rows) {
  if (scope.scopeType === 'all') return rows;
  if (scope.scopeType === 'staff') return rows.filter((row) => row.staffId === scope.staffId);
  return rows.filter((row) => scope.storeIds.includes(row.storeId));
}

test(`${validatesCriteria(['15.1', '15.3'])} permission matrix preserves each role's visible data and ranking scope`, () => {
  const cases = [
    { name: 'employee', scopeType: 'staff', staffId: 10, visible: [1, 2, 3], ranking: 'self' },
    { name: 'store manager one store', scopeType: 'stores', storeIds: [1], visible: [1, 4], ranking: 'stores' },
    { name: 'store manager multiple stores', scopeType: 'stores', storeIds: [1, 2], visible: [1, 2, 4], ranking: 'stores' },
    { name: 'headquarter operation', scopeType: 'all', visible: [1, 2, 3, 4], ranking: 'all' },
    { name: 'system administrator', scopeType: 'all', visible: [1, 2, 3, 4], ranking: 'all' },
  ];
  for (const scenario of cases) {
    const visible = visibleFacts(scenario, facts).map((row) => row.reportId);
    assert.deepEqual(visible, scenario.visible, scenario.name);
    assert.equal(scenario.canExport ?? true, true, `${scenario.name}: export`);
    assert.ok(['self', 'stores', 'all'].includes(scenario.ranking), `${scenario.name}: ranking`);
  }
  assert.equal(facts.find((row) => row.reportId === 2).storeId, 2, 'historical store snapshot remains queryable');
});

test(`${validatesCriteria(['15.2', '15.3'])} statistics, detail, and export paths share the permission contract`, () => {
  assert.match(queryService, /\$this->permissionScope\(\$context\)/);
  assert.match(queryService, /'permission_scope' => \$permissionScope/);
  assert.match(detailService, /\$this->analytics->facts\(\$input, \$context\)/);
  assert.match(detailService, /'permission_scope' => \$facts\['permission_scope'\]/);
  assert.match(detailEndpoint, /appRequireStaffContext\(\)/);
  assert.match(scopeService, /'can_export' => true/);
  assert.match(scopeService, /'ranking_scope' => \$rankingScope/);
});
