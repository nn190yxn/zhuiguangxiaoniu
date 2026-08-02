import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('Legacy Adapter 复用员工上下文和后台具名权限入口', () => {
  const adapter = read('api/kernel/LegacyAuthAdapter.php');
  const auth = read('api/kernel/AuthContext.php');

  assert.match(adapter, /appGetCurrentStaffContext\(\)/);
  assert.match(adapter, /getStaffByUserId\(\$userId\)/);
  assert.match(adapter, /adminPermissionsForRole\(\$role\)/);
  assert.match(adapter, /session_version/);
  assert.match(auth, /requirePermission/);
  assert.match(auth, /visibleStoreIds/);
  assert.doesNotMatch(auth, /phone|openid|wecom_userid|password/);
});

test('AuthContext 统一角色、任职、会话和门店范围', () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $auth = PlatformLegacyAuthAdapter::fromLegacy(
      ['authenticated' => true, 'user_id' => 7, 'staff_id' => 11, 'role' => 'store_manager', 'store_id' => 2, 'permissions' => ['can_view_all' => false]],
      ['role' => 'store_manager'],
      ['role' => 'store_manager', 'store_id' => 2, 'primary_position_id' => 8, 'session_version' => 4],
      [['id' => 21, 'store_id' => 3, 'position_id' => 9]],
      static fn(string $role): array => $role === 'manager' ? ['drill.review', 'recruitment.resume_view'] : []
    );
    echo json_encode([
      'context' => $auth->toArray(),
      'requested' => $auth->visibleStoreIds([1, 3, 4]),
      'all_authorized' => $auth->visibleStoreIds(),
      'own_staff' => $auth->canAccessStaff(11, 99),
      'store_staff' => $auth->canAccessStaff(12, 3),
      'other_staff' => $auth->canAccessStaff(12, 4),
    ]);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.context.role, 'manager');
  assert.equal(output.context.session_version, 4);
  assert.deepEqual(output.context.store_ids, [2, 3]);
  assert.deepEqual(output.context.position_ids, [8, 9]);
  assert.deepEqual(output.context.assignment_ids, [21]);
  assert.deepEqual(output.requested, [3]);
  assert.deepEqual(output.all_authorized, [2, 3]);
  assert.equal(output.own_staff, true);
  assert.equal(output.store_staff, true);
  assert.equal(output.other_staff, false);
});

test('认证和具名权限拒绝映射为稳定业务异常', () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $results = [];
    foreach ([
      'guest' => PlatformAuthContext::guest(),
      'staff' => new PlatformAuthContext(true, 1, 2, 'staff', [1], [], [], 1, [], 'self'),
    ] as $name => $auth) {
      try {
        $auth->requirePermission('staff.view_all');
      } catch (PlatformApiException $error) {
        $results[$name] = [$error->httpStatus(), $error->errorCode()];
      }
    }
    echo json_encode($results);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), {
    guest: [401, 'authentication_required'],
    staff: [403, 'permission_denied'],
  });
});
