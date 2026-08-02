import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

const runPhp = (source) => {
  const result = spawnSync('php', ['-r', source], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
};

test(`${validatesCriteria(['3.1-3.5', 'Property 2'])} 随机角色范围不会暴露权限矩阵外员工`, () => {
  const output = runPhp(String.raw`
    require 'api/kernel/bootstrap.php';
    $checks = 0;
    for ($seed = 1; $seed <= 128; $seed++) {
      mt_srand($seed);
      foreach (['self', 'stores', 'all'] as $scope) {
        $authorizedStores = [1, 3, 5];
        $auth = new PlatformAuthContext(true, 10, 20, 'staff', $authorizedStores, [], [], 1, [], $scope);
        for ($step = 0; $step < 256; $step++) {
          $targetStaff = mt_rand(1, 40);
          $targetStore = mt_rand(1, 6);
          $actual = $auth->canAccessStaff($targetStaff, $targetStore);
          $expected = $scope === 'all'
            || $targetStaff === 20
            || ($scope === 'stores' && in_array($targetStore, $authorizedStores, true));
          if ($actual !== $expected) {
            throw new RuntimeException('authorization scope escaped');
          }
          $checks++;
        }
      }
    }
    echo json_encode(['checks' => $checks]);
  `);
  assert.equal(output.checks, 128 * 3 * 256);
});

test(`${validatesCriteria(['4.3-4.4', 'Property 3'])} 随机成功状态变化保持版本严格单调`, () => {
  const output = runPhp(String.raw`
    require 'api/kernel/bootstrap.php';
    $successes = 0;
    $conflicts = 0;
    for ($seed = 1; $seed <= 128; $seed++) {
      mt_srand($seed);
      $version = 0;
      for ($step = 0; $step < 256; $step++) {
        $previous = $version;
        $expected = mt_rand(0, 3) === 0 ? max(0, $version - 1) : $version;
        try {
          $version = PlatformStateVersion::advance($version, $expected);
          if ($version <= $previous) {
            throw new RuntimeException('state version did not increase');
          }
          $successes++;
        } catch (PlatformApiException $error) {
          if ($error->httpStatus() !== 409 || $version !== $previous) {
            throw new RuntimeException('conflict changed authoritative state');
          }
          $conflicts++;
        }
      }
    }
    echo json_encode(['successes' => $successes, 'conflicts' => $conflicts]);
  `);
  assert.equal(output.successes + output.conflicts, 128 * 256);
  assert.ok(output.successes > 0);
  assert.ok(output.conflicts > 0);
});

test(`${validatesCriteria(['4.6', '11.1-11.2'])} 成功与全部错误类别保持统一响应和请求 ID`, () => {
  const output = runPhp(String.raw`
    require 'api/kernel/bootstrap.php';
    $lines = [];
    $context = PlatformRequestContext::fromServer(['HTTP_X_REQUEST_ID' => 'contract-request-1234']);
    $logger = new PlatformApiLogger(function (string $line) use (&$lines): void { $lines[] = $line; });
    $guest = PlatformAuthContext::guest();
    $staff = new PlatformAuthContext(true, 1, 2, 'staff', [1], [], [], 1, [], 'self');
    $errors = [
      'validation' => new InvalidArgumentException('字段错误'),
      'authentication' => (function () use ($guest) { try { $guest->requireAuthenticated(); } catch (Throwable $error) { return $error; } })(),
      'permission' => (function () use ($staff) { try { $staff->requirePermission('staff.view_all'); } catch (Throwable $error) { return $error; } })(),
      'conflict' => (function () { try { PlatformStateVersion::assertExpected(3, 2); } catch (Throwable $error) { return $error; } })(),
      'internal' => new RuntimeException('private database detail'),
    ];
    $responses = ['success' => PlatformApiResponse::success($context, ['ok' => true])->payload()];
    $statuses = ['success' => 200];
    foreach ($errors as $name => $error) {
      $response = PlatformExceptionMapper::response($error, $context, $logger);
      $responses[$name] = $response->payload();
      $statuses[$name] = $response->httpStatus();
    }
    echo json_encode(['responses' => $responses, 'statuses' => $statuses]);
  `);

  assert.deepEqual(output.statuses, {
    success: 200,
    validation: 400,
    authentication: 401,
    permission: 403,
    conflict: 409,
    internal: 500,
  });
  for (const response of Object.values(output.responses)) {
    assert.deepEqual(Object.keys(response), ['code', 'message', 'data', 'request_id']);
    assert.equal(response.request_id, 'contract-request-1234');
  }
  assert.equal(output.responses.internal.message, '服务暂时不可用');
});
