import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

function runLegacyResponse(expression) {
  const result = spawnSync('php', ['-r', `require 'api/config.php'; ${expression}`], {
    cwd: root,
    encoding: 'utf8',
    env: {
      ...process.env,
      DB_PASSWORD: 'contract-test-placeholder',
      JWT_SECRET: 'contract-test-placeholder',
    },
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('知识管理成功分支统一返回业务码零', () => {
  const source = read('api/admin/knowledge/index.php');
  assert.match(source, /jsonSuccess\(\$data, 'ok'\)/);
  assert.match(source, /jsonSuccess\(\[\], 'ok'\)/);
  assert.doesNotMatch(source, /jsonResponse\(200, 'ok'/);
});

test('旧式响应的成功、验证和冲突语义保持一致', { skip: !hasPhp }, () => {
  assert.deepEqual(runLegacyResponse("jsonSuccess(['saved' => true]);"), {
    code: 0,
    message: 'success',
    data: { saved: true },
  });
  assert.deepEqual(runLegacyResponse("jsonResponse(400, 'invalid', null);"), {
    code: 400,
    message: 'invalid',
    data: null,
  });
  assert.deepEqual(runLegacyResponse("jsonResponse(409, 'conflict', ['field' => 'version']);"), {
    code: 409,
    message: 'conflict',
    data: { field: 'version' },
  });
});

test('平台响应统一成功、验证、冲突和异常结构', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $context = PlatformRequestContext::fromServer([], ['domain' => 'contract', 'action' => 'response']);
    $responses = [
      'success' => PlatformApiResponse::success($context, ['saved' => true]),
      'validation' => PlatformExceptionMapper::response(new InvalidArgumentException('invalid'), $context),
      'conflict' => PlatformExceptionMapper::response(new PlatformApiException(409, 'version_conflict', 'conflict'), $context),
      'internal' => PlatformExceptionMapper::response(new RuntimeException('private detail'), $context),
    ];
    echo json_encode(array_map(static fn($response) => [
      'status' => $response->httpStatus(),
      'payload' => $response->payload(),
    ], $responses));
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.success.status, 200);
  assert.equal(output.success.payload.code, 0);
  assert.equal(output.validation.status, 400);
  assert.equal(output.validation.payload.code, 'validation_error');
  assert.equal(output.conflict.status, 409);
  assert.equal(output.conflict.payload.code, 'version_conflict');
  assert.equal(output.internal.status, 500);
  assert.equal(output.internal.payload.code, 'internal_error');
  assert.equal(output.internal.payload.message, '服务暂时不可用');
  for (const response of Object.values(output)) {
    assert.deepEqual(Object.keys(response.payload), ['code', 'message', 'data', 'request_id']);
  }
});
