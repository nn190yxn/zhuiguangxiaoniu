import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [scopeService, queryService] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadPermissionScopeService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

test(`${validatesCriteria(['15.1', '15.2'])} central permission scopes define data and ranking visibility`, () => {
  assert.match(scopeService, /final class WorkloadPermissionScopeService/);
  assert.match(scopeService, /'scope_type' => \$scopeType/);
  assert.match(scopeService, /'ranking_scope' => \$rankingScope/);
  assert.match(scopeService, /'can_manage_configuration' => \$canManageConfiguration/);
  assert.match(scopeService, /'can_export' => true/);
  assert.match(scopeService, /\$this->scope\('all'[\s\S]*'all'/);
  assert.match(scopeService, /\$this->scope\('stores'[\s\S]*'stores'/);
  assert.match(scopeService, /\$this->scope\('staff'[\s\S]*'self'/);
  assert.match(scopeService, /staff_assignments/);
  assert.match(scopeService, /\$role === 'admin'/);
  assert.match(queryService, /new WorkloadPermissionScopeService\(\$this->pdo\)/);
  assert.match(queryService, /\$this->permissionScopeService->resolve\(\$context\)/);
});
