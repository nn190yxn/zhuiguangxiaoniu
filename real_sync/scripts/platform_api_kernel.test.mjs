import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

test('API Kernel 核心类型保持零配置依赖和统一命名', () => {
  const exception = read('api/kernel/ApiException.php');
  const context = read('api/kernel/RequestContext.php');
  const response = read('api/kernel/ApiResponse.php');
  const bootstrap = read('api/kernel/bootstrap.php');

  assert.match(exception, /final class PlatformApiException/);
  assert.match(context, /final class PlatformRequestContext/);
  assert.match(response, /final class PlatformApiResponse/);
  assert.match(bootstrap, /function platformApiContext/);
  for (const source of [exception, context, response, bootstrap]) {
    assert.doesNotMatch(source, /config\.php|getDB\s*\(|session_start\s*\(/);
  }
});

test('结构化日志按字段脱敏并传播完整请求上下文', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $lines = [];
    $context = PlatformRequestContext::fromServer([
      'HTTP_X_REQUEST_ID' => 'request-log-1234',
      'REQUEST_METHOD' => 'POST',
      'REQUEST_URI' => '/api/recruitment/process.php',
    ], ['client' => 'pwa', 'version' => '2.0.0', 'domain' => 'recruitment', 'action' => 'resume.process']);
    $logger = new PlatformApiLogger(function (string $line) use (&$lines): void { $lines[] = $line; });
    $logger->log('info', 'resume.processed', $context, [
      'phone' => '13812345678',
      'access_token' => 'secret-token',
      'openid' => 'o-sensitive',
      'resume_text' => 'private resume',
      'provider_response' => ['raw' => 'private provider data'],
    ]);
    echo $lines[0];
  `;
  const result = spawnSync('php', ['-r', php], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.request_id, 'request-log-1234');
  assert.equal(output.domain, 'recruitment');
  assert.equal(output.action, 'resume.process');
  assert.equal(output.data.phone, '138****5678');
  assert.equal(output.data.access_token, '[REDACTED]');
  assert.equal(output.data.openid.redacted, true);
  assert.equal(output.data.resume_text.type, 'content');
  assert.equal(output.data.provider_response.type, 'content');
  assert.doesNotMatch(result.stdout, /secret-token|o-sensitive|private resume|private provider data/);
});

test('异常映射返回稳定状态并隐藏内部错误', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $lines = [];
    $context = PlatformRequestContext::fromServer([], ['domain' => 'platform', 'action' => 'test']);
    $logger = new PlatformApiLogger(function (string $line) use (&$lines): void { $lines[] = $line; });
    $known = PlatformExceptionMapper::response(new PlatformApiException(409, 'version_conflict', '状态版本冲突', ['phone' => '13812345678']), $context, $logger);
    $unknown = PlatformExceptionMapper::response(new RuntimeException('Bearer private-token database failed'), $context, $logger);
    echo json_encode([
      'known_status' => $known->httpStatus(),
      'known' => $known->payload(),
      'unknown_status' => $unknown->httpStatus(),
      'unknown' => $unknown->payload(),
      'logs' => array_map('json_decode', $lines),
    ], JSON_UNESCAPED_UNICODE);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.known_status, 409);
  assert.equal(output.known.code, 'version_conflict');
  assert.equal(output.known.data.phone, '138****5678');
  assert.equal(output.unknown_status, 500);
  assert.equal(output.unknown.code, 'internal_error');
  assert.equal(output.unknown.message, '服务暂时不可用');
  assert.doesNotMatch(result.stdout, /private-token|database failed/);
});

test('请求上下文与响应在 PHP 运行时保持稳定契约', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $context = PlatformRequestContext::fromServer([
      'HTTP_X_REQUEST_ID' => 'request-12345678',
      'REQUEST_METHOD' => 'post',
      'REQUEST_URI' => '/api/health/live.php',
    ], ['client' => 'pwa', 'version' => '1.2.3', 'domain' => 'platform']);
    $response = PlatformApiResponse::success($context, ['ok' => true]);
    echo json_encode(['context' => $context->toArray(), 'status' => $response->httpStatus(), 'payload' => $response->payload()]);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.context.request_id, 'request-12345678');
  assert.equal(output.context.method, 'POST');
  assert.equal(output.status, 200);
  assert.deepEqual(output.payload, {
    code: 0,
    message: 'success',
    data: { ok: true },
    request_id: 'request-12345678',
  });
});

test('非法请求 ID 和 HTTP 状态获得安全回退', { skip: !hasPhp }, () => {
  const php = String.raw`
    require 'api/kernel/bootstrap.php';
    $context = PlatformRequestContext::fromServer(['HTTP_X_REQUEST_ID' => "bad\nheader"]);
    $error = new PlatformApiException(200, 'internal_error', 'failed');
    echo json_encode([
      'request_id' => $context->requestId(),
      'request_id_valid' => PlatformRequestContext::isValidRequestId($context->requestId()),
      'status' => $error->httpStatus(),
    ]);
  `;
  const result = spawnSync('php', ['-r', php], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });

  assert.equal(result.status, 0, result.stderr);
  const output = JSON.parse(result.stdout);
  assert.equal(output.request_id_valid, true);
  assert.equal(output.status, 500);
});
