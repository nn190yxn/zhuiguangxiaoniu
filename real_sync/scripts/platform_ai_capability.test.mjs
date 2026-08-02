import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const gatewayUrl = new URL('../api/platform/AiCapabilityGateway.php', import.meta.url);
const migrationUrl = new URL('../database/migrations/202607310014_platform_ai_invocations.sql', import.meta.url);

function runPhp(source) {
  const result = spawnSync('php', ['-d', 'display_errors=1', '-r', source], {
    cwd: root,
    encoding: 'utf8',
    timeout: 10_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('[validates 8.1-8.2, 8.6] successful capability results and invocation summaries use stable metadata without raw content', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/AiCapabilityGateway.php';

    final class FakeAiInvocationStore implements PlatformAiInvocationStore {
      public array $rows = [];
      public function recordInvocation(array $invocation): void { $this->rows[] = $invocation; }
    }

    $store = new FakeAiInvocationStore();
    $gateway = new PlatformAiCapabilityGateway(
      $store,
      ['primary' => static function (array $call): array {
        return [
          'model' => 'model-v2',
          'processing_version' => 'assessment-2026-08',
          'output' => ['score' => 91, 'provider_response' => 'private provider payload'],
        ];
      }],
      ['assessment.score' => ['primary']],
      static fn(array $decision): array => ['approved' => true, 'approval_id' => 'approval-7']
    );
    $result = $gateway->invoke([
      'capability' => 'assessment.score',
      'contract_version' => 'ai-capability.v1',
      'request_id' => 'request-ai-success-0001',
      'purpose' => 'drill.scoring',
      'data_classification' => 'sensitive',
      'input' => ['resume' => 'private resume text', 'authorization' => 'Bearer secret-token'],
      'preferred_provider' => 'primary',
      'timeout_ms' => 5000,
      'max_attempts' => 2,
      'idempotency_key' => 'drill-score-attempt-1001',
      'retention_policy_code' => 'drill-ai-summary-180d',
    ]);
    echo json_encode(['result' => $result, 'rows' => $store->rows]);
  `);

  assert.equal(output.result.status, 'completed');
  assert.equal(output.result.capability, 'assessment.score');
  assert.equal(output.result.provider, 'primary');
  assert.equal(output.result.model, 'model-v2');
  assert.equal(output.result.processing_version, 'assessment-2026-08');
  assert.equal(output.result.attempts, 1);
  assert.equal(output.result.fallback, false);
  assert.deepEqual(output.result.output, { score: 91, provider_response: 'private provider payload' });
  assert.equal(output.rows.length, 1);
  const serialized = JSON.stringify(output.rows[0]);
  assert.doesNotMatch(serialized, /private resume text|secret-token|private provider payload/);
  assert.match(output.rows[0].input_sha256, /^[a-f0-9]{64}$/);
  assert.match(output.rows[0].output_sha256, /^[a-f0-9]{64}$/);
  assert.equal(output.rows[0].status, 'completed');
  assert.equal(output.rows[0].approval_id, 'approval-7');
});

test('[validates 11.5, property 8] every enabled AI result maps to one auditable capability and processing version', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/AiCapabilityGateway.php';
    final class PropertyAiInvocationStore implements PlatformAiInvocationStore {
      public array $rows = [];
      public function recordInvocation(array $invocation): void { $this->rows[] = $invocation; }
    }
    $capabilities = ['text.generate', 'assessment.score', 'vision.extract', 'ocr.extract', 'speech.transcribe'];
    $routes = [];
    foreach ($capabilities as $capability) { $routes[$capability] = ['primary']; }
    $decisions = [];
    $store = new PropertyAiInvocationStore();
    $gateway = new PlatformAiCapabilityGateway(
      $store,
      ['primary' => static fn(array $call): array => [
        'model' => 'model-contract-v1',
        'processing_version' => str_replace('.', '-', $call['capability']) . '-2026-08',
        'output' => ['accepted' => true],
      ]],
      $routes,
      static function (array $decision) use (&$decisions): array {
        $decisions[] = $decision;
        return ['approved' => true, 'approval_id' => 'approval-' . count($decisions)];
      }
    );
    $results = [];
    foreach ($capabilities as $index => $capability) {
      $results[] = $gateway->invoke([
        'capability' => $capability,
        'contract_version' => 'ai-capability.v1',
        'request_id' => 'request-ai-property-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'purpose' => 'contract.verify',
        'data_classification' => 'sensitive',
        'input' => ['payload' => 'private-' . $index],
        'preferred_provider' => 'primary',
        'timeout_ms' => 5000,
        'max_attempts' => 1,
        'idempotency_key' => 'property-ai-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'retention_policy_code' => 'ai-summary-180d',
        'approval_context' => ['actor_id' => 7, 'permission' => 'ai.invoke', 'scope' => 'contract'],
      ]);
    }
    echo json_encode(['results' => $results, 'rows' => $store->rows, 'decisions' => $decisions]);
  `);

  assert.equal(output.results.length, 5);
  assert.equal(output.rows.length, 5);
  for (const [index, result] of output.results.entries()) {
    const matchingRows = output.rows.filter((row) => row.request_id === result.request_id);
    assert.equal(matchingRows.length, 1, `${result.request_id} must have one audit row`);
    const row = matchingRows[0];
    assert.equal(result.capability, row.capability);
    assert.equal(result.contract_version, row.contract_version);
    assert.equal(result.processing_version, row.processing_version);
    assert.equal(result.provider, row.actual_provider);
    assert.equal(result.status, row.status);
    assert.match(row.invocation_key, /^[a-f0-9]{32}$/);
    assert.equal(output.decisions[index].capability, result.capability);
    assert.equal(output.decisions[index].purpose, 'contract.verify');
    assert.equal(output.decisions[index].data_classification, 'sensitive');
    assert.equal(output.decisions[index].retention_policy_code, 'ai-summary-180d');
    assert.deepEqual(output.decisions[index].approval_context, { actor_id: 7, permission: 'ai.invoke', scope: 'contract' });
  }
});

test('[validates 8.3-8.5] retryable provider failures use an approved fallback within the attempt budget', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/AiCapabilityGateway.php';
    final class FallbackStore implements PlatformAiInvocationStore {
      public array $rows = [];
      public function recordInvocation(array $invocation): void { $this->rows[] = $invocation; }
    }
    $calls = [];
    $approvals = [];
    $store = new FallbackStore();
    $gateway = new PlatformAiCapabilityGateway(
      $store,
      [
        'primary' => static function (array $call) use (&$calls): array {
          $calls[] = ['provider' => 'primary', 'timeout_ms' => $call['timeout_ms']];
          throw PlatformAiException::providerFailure('timeout', 'provider included private prompt', 'primary');
        },
        'backup' => static function (array $call) use (&$calls): array {
          $calls[] = ['provider' => 'backup', 'timeout_ms' => $call['timeout_ms']];
          return ['model' => 'backup-v1', 'processing_version' => 'text-v1', 'output' => ['text' => 'safe result']];
        },
      ],
      ['text.generate' => ['primary', 'backup']],
      static function (array $decision) use (&$approvals): array {
        $approvals[] = $decision['provider'];
        return ['approved' => true, 'approval_id' => 'approval-' . $decision['provider']];
      }
    );
    $result = $gateway->invoke([
      'capability' => 'text.generate',
      'contract_version' => 'ai-capability.v1',
      'request_id' => 'request-ai-fallback-0001',
      'purpose' => 'report.generate',
      'data_classification' => 'internal',
      'input' => ['prompt' => 'generate report'],
      'preferred_provider' => 'primary',
      'timeout_ms' => 5000,
      'max_attempts' => 2,
      'idempotency_key' => 'report-generate-1001',
      'retention_policy_code' => 'ai-summary-180d',
    ]);
    echo json_encode(['result' => $result, 'calls' => $calls, 'approvals' => $approvals, 'row' => $store->rows[0]]);
  `);

  assert.deepEqual(output.calls.map((call) => call.provider), ['primary', 'backup']);
  assert.deepEqual(output.approvals, ['primary', 'backup']);
  assert.equal(output.result.provider, 'backup');
  assert.equal(output.result.requested_provider, 'primary');
  assert.equal(output.result.attempts, 2);
  assert.equal(output.result.fallback, true);
  assert.equal(output.row.fallback_used, 1);
  assert.equal(output.row.attempt_count, 2);
});

test('[validates 8.4, 8.7] approval denial and image generation execute zero providers and persist stable outcomes', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/AiCapabilityGateway.php';
    final class DenialStore implements PlatformAiInvocationStore {
      public array $rows = [];
      public function recordInvocation(array $invocation): void { $this->rows[] = $invocation; }
    }
    $executions = 0;
    $store = new DenialStore();
    $gateway = new PlatformAiCapabilityGateway(
      $store,
      ['primary' => static function () use (&$executions): array { $executions++; return []; }],
      ['ocr.extract' => ['primary'], 'image.generate' => ['primary']],
      static fn(array $decision): array => ['approved' => false, 'reason_code' => 'external_processing_not_approved']
    );
    $base = [
      'contract_version' => 'ai-capability.v1',
      'purpose' => 'recruitment.ocr',
      'data_classification' => 'personal',
      'input' => ['image' => 'private image'],
      'preferred_provider' => 'primary',
      'timeout_ms' => 1000,
      'max_attempts' => 3,
      'retention_policy_code' => 'recruitment-ai-180d',
    ];
    $errors = [];
    foreach ([
      ['capability' => 'ocr.extract', 'request_id' => 'request-ai-denied-0001', 'idempotency_key' => 'ocr-denied-1001'],
      ['capability' => 'image.generate', 'request_id' => 'request-ai-image-0001', 'idempotency_key' => 'image-disabled-1001'],
    ] as $request) {
      try { $gateway->invoke($request + $base); }
      catch (PlatformAiException $error) { $errors[] = ['code' => $error->errorCode(), 'retryable' => $error->retryable()]; }
    }
    echo json_encode(['executions' => $executions, 'errors' => $errors, 'rows' => $store->rows]);
  `);

  assert.equal(output.executions, 0);
  assert.deepEqual(output.errors, [
    { code: 'approval_denied', retryable: false },
    { code: 'capability_unsupported', retryable: false },
  ]);
  assert.deepEqual(output.rows.map((row) => row.status), ['rejected', 'unsupported']);
  assert.deepEqual(output.rows.map((row) => row.attempt_count), [0, 0]);
});

test('[validates 8.4, 8.6] approval service failures become audited internal errors before provider execution', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/AiCapabilityGateway.php';
    final class ApprovalFailureStore implements PlatformAiInvocationStore {
      public array $rows = [];
      public function recordInvocation(array $invocation): void { $this->rows[] = $invocation; }
    }
    $executions = 0;
    $store = new ApprovalFailureStore();
    $gateway = new PlatformAiCapabilityGateway(
      $store,
      ['primary' => static function () use (&$executions): array { $executions++; return []; }],
      ['vision.extract' => ['primary']],
      static function (): array { throw new RuntimeException('approval backend Bearer private-token'); }
    );
    try {
      $gateway->invoke([
        'capability' => 'vision.extract', 'contract_version' => 'ai-capability.v1',
        'request_id' => 'request-ai-approval-error-1', 'purpose' => 'fitness.extract',
        'data_classification' => 'personal', 'input' => ['image' => 'private image'],
        'preferred_provider' => 'primary', 'timeout_ms' => 1000, 'max_attempts' => 2,
        'idempotency_key' => 'vision-approval-error-1', 'retention_policy_code' => 'fitness-ai-180d',
      ]);
    } catch (PlatformAiException $error) {
      $code = $error->errorCode();
    }
    echo json_encode(['executions' => $executions, 'code' => $code, 'row' => $store->rows[0] ?? null]);
  `);

  assert.equal(output.executions, 0);
  assert.equal(output.code, 'internal_error');
  assert.equal(output.row.status, 'failed');
  assert.equal(output.row.attempt_count, 0);
  assert.doesNotMatch(JSON.stringify(output.row), /private-token/);
});

test('[validates 8.1, 8.3, 8.6] request validation and retry budgets produce deterministic errors', { skip: !hasPhp }, () => {
  const output = runPhp(String.raw`
    require 'api/platform/AiCapabilityGateway.php';
    final class ValidationStore implements PlatformAiInvocationStore { public function recordInvocation(array $invocation): void {} }
    $gateway = new PlatformAiCapabilityGateway(new ValidationStore(), [], [], static fn(array $decision): array => ['approved' => true]);
    $errors = [];
    foreach ([
      [],
      ['capability' => 'unknown.capability'],
      ['capability' => 'text.generate', 'request_id' => 'short'],
    ] as $input) {
      try { $gateway->invoke($input); }
      catch (PlatformAiException $error) { $errors[] = $error->errorCode(); }
    }
    $calls = 0;
    $retryGateway = new PlatformAiCapabilityGateway(
      new ValidationStore(),
      ['failing' => static function () use (&$calls): array { $calls++; throw PlatformAiException::providerFailure('rate_limited', 'retry later', 'failing'); }],
      ['speech.transcribe' => ['failing']],
      static fn(array $decision): array => ['approved' => true]
    );
    try {
      $retryGateway->invoke([
        'capability' => 'speech.transcribe', 'contract_version' => 'ai-capability.v1',
        'request_id' => 'request-ai-budget-0001', 'purpose' => 'skill.transcribe',
        'data_classification' => 'sensitive', 'input' => ['audio' => 'private audio'],
        'preferred_provider' => 'failing', 'timeout_ms' => 5000, 'max_attempts' => 3,
        'idempotency_key' => 'speech-budget-1001', 'retention_policy_code' => 'speech-ai-180d',
      ]);
    } catch (PlatformAiException $error) {
      $budgetError = ['code' => $error->errorCode(), 'retryable' => $error->retryable(), 'recovery' => $error->recoveryRequired()];
    }
    echo json_encode(['errors' => $errors, 'calls' => $calls, 'budget_error' => $budgetError]);
  `);

  assert.deepEqual(output.errors, ['request_invalid', 'capability_unsupported', 'request_invalid']);
  assert.equal(output.calls, 3);
  assert.deepEqual(output.budget_error, { code: 'rate_limited', retryable: true, recovery: true });
});

test('[validates 7.7-7.9, 8.2, 8.6] AI invocation migration stores only bounded summaries and lifecycle metadata', () => {
  const gatewaySource = readFileSync(gatewayUrl, 'utf8');
  const migrationSource = readFileSync(migrationUrl, 'utf8');
  assert.match(migrationSource, /CREATE TABLE IF NOT EXISTS platform_ai_invocations/);
  for (const field of [
    'invocation_key', 'request_id', 'idempotency_key', 'capability', 'purpose_code', 'data_classification',
    'requested_provider', 'actual_provider', 'model', 'contract_version', 'processing_version', 'status',
    'error_code', 'attempt_count', 'fallback_used', 'elapsed_ms', 'input_sha256', 'input_bytes',
    'output_sha256', 'output_bytes', 'retention_policy_code', 'retention_until',
  ]) {
    assert.match(migrationSource, new RegExp(`\\b${field}\\b`));
  }
  assert.doesNotMatch(migrationSource, /prompt|transcript|resume_text|raw_response|input_json|output_json/i);
  assert.doesNotMatch(migrationSource, /UPDATE\s+|DELETE\s+FROM|DROP\s+/i);
  assert.match(gatewaySource, /PlatformSensitiveData::summary/);
  assert.match(gatewaySource, /PlatformSensitiveData::sanitize/);
  assert.doesNotMatch(gatewaySource, /ai_deepseek_chat|ai_baidu_ocr|ai_post_json|ai_post_form/);
});
