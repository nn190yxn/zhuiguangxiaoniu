<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/kernel/RequestContext.php';
require_once dirname(__DIR__) . '/kernel/SensitiveData.php';

interface PlatformAiInvocationStore
{
    public function recordInvocation(array $invocation): void;
}

final class PlatformPdoAiInvocationStore implements PlatformAiInvocationStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function recordInvocation(array $invocation): void
    {
        $sql = 'INSERT INTO platform_ai_invocations '
            . '(invocation_key, request_id, idempotency_key, capability, purpose_code, data_classification, '
            . 'requested_provider, actual_provider, model, contract_version, processing_version, status, '
            . 'error_code, error_summary, retryable, recovery_required, attempt_count, fallback_used, elapsed_ms, '
            . 'input_sha256, input_bytes, output_sha256, output_bytes, approval_id, retention_policy_code, '
            . 'retention_until, created_at, completed_at) '
            . 'VALUES (:invocation_key, :request_id, :idempotency_key, :capability, :purpose_code, :data_classification, '
            . ':requested_provider, :actual_provider, :model, :contract_version, :processing_version, :status, '
            . ':error_code, :error_summary, :retryable, :recovery_required, :attempt_count, :fallback_used, :elapsed_ms, '
            . ':input_sha256, :input_bytes, :output_sha256, :output_bytes, :approval_id, :retention_policy_code, '
            . ':retention_until, :created_at, :completed_at)';
        $this->pdo->prepare($sql)->execute($invocation);
    }
}

final class PlatformAiException extends RuntimeException
{
    private const RECOVERABLE_CODES = [
        'rate_limited',
        'timeout',
        'transport_failed',
        'provider_unavailable',
    ];

    public function __construct(
        private string $errorCode,
        private ?string $provider = null,
        private string $providerDetail = '',
        private ?bool $isRetryable = null,
        private ?bool $requiresRecovery = null
    ) {
        parent::__construct($errorCode);
        $this->isRetryable ??= in_array($errorCode, self::RECOVERABLE_CODES, true);
        $this->requiresRecovery ??= $this->isRetryable;
    }

    public static function providerFailure(string $errorCode, string $detail, string $provider): self
    {
        return new self($errorCode, $provider, $detail);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function providerDetail(): string
    {
        return $this->providerDetail;
    }

    public function retryable(): bool
    {
        return $this->isRetryable;
    }

    public function recoveryRequired(): bool
    {
        return $this->requiresRecovery;
    }
}

final class PlatformAiCapabilityGateway
{
    public const TEXT_GENERATE = 'text.generate';
    public const ASSESSMENT_SCORE = 'assessment.score';
    public const VISION_EXTRACT = 'vision.extract';
    public const OCR_EXTRACT = 'ocr.extract';
    public const SPEECH_TRANSCRIBE = 'speech.transcribe';
    public const IMAGE_GENERATE = 'image.generate';

    private const CONTRACT_VERSION = 'ai-capability.v1';
    private const CAPABILITIES = [
        self::TEXT_GENERATE,
        self::ASSESSMENT_SCORE,
        self::VISION_EXTRACT,
        self::OCR_EXTRACT,
        self::SPEECH_TRANSCRIBE,
        self::IMAGE_GENERATE,
    ];
    private const DATA_CLASSIFICATIONS = ['public', 'internal', 'personal', 'sensitive', 'restricted'];
    private const ERROR_CODES = [
        'request_invalid',
        'capability_unsupported',
        'approval_denied',
        'provider_unconfigured',
        'authentication_failed',
        'rate_limited',
        'timeout',
        'transport_failed',
        'provider_unavailable',
        'response_invalid',
        'internal_error',
    ];

    /** @var array<string, Closure> */
    private array $providers = [];
    /** @var array<string, list<string>> */
    private array $routes = [];
    private Closure $approval;

    public function __construct(
        private PlatformAiInvocationStore $store,
        array $providers = [],
        array $routes = [],
        ?callable $approval = null
    ) {
        foreach ($providers as $name => $executor) {
            $provider = self::token($name, 'provider_unconfigured', 64);
            if (!is_callable($executor)) {
                throw new InvalidArgumentException('ai_provider_executor_invalid');
            }
            $this->providers[$provider] = Closure::fromCallable($executor);
        }
        foreach ($routes as $capability => $providerNames) {
            if (!in_array($capability, self::CAPABILITIES, true) || !is_array($providerNames)) {
                throw new InvalidArgumentException('ai_route_invalid');
            }
            $this->routes[$capability] = array_values(array_unique(array_map(
                static fn(mixed $name): string => self::token($name, 'ai_route_invalid', 64),
                $providerNames
            )));
        }
        $this->approval = $approval === null
            ? static fn(array $decision): array => [
                'approved' => in_array($decision['data_classification'], ['public', 'internal'], true),
                'reason_code' => 'explicit_data_processing_approval_required',
            ]
            : Closure::fromCallable($approval);
    }

    public function invoke(array $input): array
    {
        $capability = trim((string)($input['capability'] ?? ''));
        if ($capability === '') {
            throw new PlatformAiException('request_invalid');
        }
        if (!in_array($capability, self::CAPABILITIES, true)) {
            throw new PlatformAiException('capability_unsupported');
        }

        $request = $this->normalizeRequest($input, $capability);
        $startedAt = hrtime(true);
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $inputSummary = PlatformSensitiveData::summary($request['input'], 'ai_input');

        if ($capability === self::IMAGE_GENERATE) {
            $error = new PlatformAiException('capability_unsupported');
            $this->record($request, $createdAt, $startedAt, $inputSummary, null, [
                'status' => 'unsupported',
                'error' => $error,
                'attempt_count' => 0,
            ]);
            throw $error;
        }

        $candidates = $this->providerCandidates($request);
        if ($candidates === []) {
            $error = new PlatformAiException('provider_unconfigured', $request['preferred_provider']);
            $this->record($request, $createdAt, $startedAt, $inputSummary, null, [
                'status' => 'failed',
                'error' => $error,
                'attempt_count' => 0,
            ]);
            throw $error;
        }

        $approved = [];
        foreach ($candidates as $provider) {
            if (!isset($this->providers[$provider])) {
                continue;
            }
            try {
                $decision = ($this->approval)([
                    'capability' => $capability,
                    'purpose' => $request['purpose'],
                    'data_classification' => $request['data_classification'],
                    'provider' => $provider,
                    'request_id' => $request['request_id'],
                    'retention_policy_code' => $request['retention_policy_code'],
                    'approval_context' => $request['approval_context'],
                ]);
            } catch (Throwable $approvalError) {
                $error = new PlatformAiException(
                    'internal_error',
                    $provider,
                    $approvalError->getMessage(),
                    false,
                    false
                );
                $this->record($request, $createdAt, $startedAt, $inputSummary, null, [
                    'status' => 'failed',
                    'error' => $error,
                    'attempt_count' => 0,
                ]);
                throw $error;
            }
            if (is_array($decision) && ($decision['approved'] ?? false) === true) {
                $approved[] = [
                    'provider' => $provider,
                    'approval_id' => self::optionalToken($decision['approval_id'] ?? null, 128),
                ];
            }
        }
        if ($approved === []) {
            $hasConfiguredProvider = false;
            foreach ($candidates as $provider) {
                if (isset($this->providers[$provider])) {
                    $hasConfiguredProvider = true;
                    break;
                }
            }
            $error = new PlatformAiException($hasConfiguredProvider ? 'approval_denied' : 'provider_unconfigured');
            $this->record($request, $createdAt, $startedAt, $inputSummary, null, [
                'status' => $hasConfiguredProvider ? 'rejected' : 'failed',
                'error' => $error,
                'attempt_count' => 0,
            ]);
            throw $error;
        }

        $attempt = 0;
        $lastError = null;
        $lastProvider = null;
        $lastApprovalId = null;
        while ($attempt < $request['max_attempts']) {
            $elapsedMs = self::elapsedMs($startedAt);
            $remainingMs = $request['timeout_ms'] - $elapsedMs;
            if ($remainingMs <= 0) {
                $lastError = new PlatformAiException('timeout', $lastProvider);
                break;
            }

            $selection = $approved[min($attempt, count($approved) - 1)];
            $lastProvider = $selection['provider'];
            $lastApprovalId = $selection['approval_id'];
            $attempt++;
            try {
                $providerResult = ($this->providers[$lastProvider])([
                    'capability' => $capability,
                    'contract_version' => $request['contract_version'],
                    'request_id' => $request['request_id'],
                    'purpose' => $request['purpose'],
                    'input' => $request['input'],
                    'timeout_ms' => max(1, $remainingMs),
                    'attempt' => $attempt,
                    'idempotency_key' => $request['idempotency_key'],
                ]);
                $providerResult = $this->validateProviderResult($providerResult, $lastProvider);
                $outputSummary = PlatformSensitiveData::summary($providerResult['output'], 'ai_output');
                $result = [
                    'status' => 'completed',
                    'capability' => $capability,
                    'contract_version' => $request['contract_version'],
                    'request_id' => $request['request_id'],
                    'requested_provider' => $request['requested_provider'],
                    'provider' => $lastProvider,
                    'model' => $providerResult['model'],
                    'processing_version' => $providerResult['processing_version'],
                    'elapsed_ms' => self::elapsedMs($startedAt),
                    'attempts' => $attempt,
                    'fallback' => $lastProvider !== $request['requested_provider'],
                    'output' => $providerResult['output'],
                ];
                $this->record($request, $createdAt, $startedAt, $inputSummary, $outputSummary, [
                    'status' => 'completed',
                    'actual_provider' => $lastProvider,
                    'model' => $providerResult['model'],
                    'processing_version' => $providerResult['processing_version'],
                    'attempt_count' => $attempt,
                    'fallback_used' => $result['fallback'],
                    'approval_id' => $lastApprovalId,
                ]);
                return $result;
            } catch (PlatformAiException $error) {
                $lastError = $this->normalizeProviderError($error, $lastProvider);
            } catch (Throwable $error) {
                $lastError = new PlatformAiException('internal_error', $lastProvider, $error->getMessage(), false, false);
            }

            if (!$lastError->retryable()) {
                break;
            }
        }

        $lastError ??= new PlatformAiException('internal_error', $lastProvider);
        if ($lastError->retryable() && !$lastError->recoveryRequired()) {
            $lastError = new PlatformAiException(
                $lastError->errorCode(),
                $lastError->provider(),
                $lastError->providerDetail(),
                true,
                true
            );
        }
        $this->record($request, $createdAt, $startedAt, $inputSummary, null, [
            'status' => 'failed',
            'error' => $lastError,
            'actual_provider' => $lastProvider,
            'attempt_count' => $attempt,
            'fallback_used' => $lastProvider !== null && $lastProvider !== $request['requested_provider'],
            'approval_id' => $lastApprovalId,
        ]);
        throw $lastError;
    }

    private function normalizeRequest(array $input, string $capability): array
    {
        $requestId = trim((string)($input['request_id'] ?? ''));
        $contractVersion = trim((string)($input['contract_version'] ?? ''));
        $dataClassification = trim((string)($input['data_classification'] ?? ''));
        $rawInput = $input['input'] ?? null;
        $timeoutMs = filter_var($input['timeout_ms'] ?? null, FILTER_VALIDATE_INT);
        $maxAttempts = filter_var($input['max_attempts'] ?? null, FILTER_VALIDATE_INT);

        if (
            !PlatformRequestContext::isValidRequestId($requestId)
            || $contractVersion !== self::CONTRACT_VERSION
            || !in_array($dataClassification, self::DATA_CLASSIFICATIONS, true)
            || $rawInput === null
            || $rawInput === ''
            || $rawInput === []
            || $timeoutMs === false
            || $timeoutMs < 100
            || $timeoutMs > 120000
            || $maxAttempts === false
            || $maxAttempts < 1
            || $maxAttempts > 5
        ) {
            throw new PlatformAiException('request_invalid');
        }

        $approvalContext = $input['approval_context'] ?? [];
        if (!is_array($approvalContext)) {
            throw new PlatformAiException('request_invalid');
        }
        try {
            $purpose = self::token($input['purpose'] ?? '', 'request_invalid', 64);
            $idempotencyKey = self::token($input['idempotency_key'] ?? '', 'request_invalid', 128, 8);
            $retentionPolicy = self::token($input['retention_policy_code'] ?? '', 'request_invalid', 64);
            $preferredProvider = self::optionalToken($input['preferred_provider'] ?? null, 64);
        } catch (InvalidArgumentException) {
            throw new PlatformAiException('request_invalid');
        }

        return [
            'capability' => $capability,
            'contract_version' => $contractVersion,
            'request_id' => $requestId,
            'purpose' => $purpose,
            'data_classification' => $dataClassification,
            'input' => $rawInput,
            'preferred_provider' => $preferredProvider,
            'requested_provider' => $preferredProvider,
            'timeout_ms' => $timeoutMs,
            'max_attempts' => $maxAttempts,
            'idempotency_key' => $idempotencyKey,
            'retention_policy_code' => $retentionPolicy,
            'retention_until' => self::retentionUntil($input['retention_until'] ?? null),
            'approval_context' => $approvalContext,
        ];
    }

    private function providerCandidates(array &$request): array
    {
        $route = $this->routes[$request['capability']] ?? [];
        $candidates = $request['preferred_provider'] === null
            ? $route
            : array_merge([$request['preferred_provider']], $route);
        $candidates = array_values(array_unique($candidates));
        if ($request['requested_provider'] === null && $candidates !== []) {
            $request['requested_provider'] = $candidates[0];
        }
        return $candidates;
    }

    private function validateProviderResult(mixed $result, string $provider): array
    {
        if (!is_array($result) || !array_key_exists('output', $result)) {
            throw new PlatformAiException('response_invalid', $provider);
        }
        try {
            $model = self::token($result['model'] ?? '', 'response_invalid', 128);
            $processingVersion = self::token($result['processing_version'] ?? '', 'response_invalid', 128);
        } catch (InvalidArgumentException) {
            throw new PlatformAiException('response_invalid', $provider);
        }
        return [
            'model' => $model,
            'processing_version' => $processingVersion,
            'output' => $result['output'],
        ];
    }

    private function normalizeProviderError(PlatformAiException $error, string $provider): PlatformAiException
    {
        $code = in_array($error->errorCode(), self::ERROR_CODES, true)
            ? $error->errorCode()
            : 'internal_error';
        return new PlatformAiException(
            $code,
            $error->provider() ?? $provider,
            $error->providerDetail(),
            $error->retryable(),
            $error->recoveryRequired()
        );
    }

    private function record(
        array $request,
        DateTimeImmutable $createdAt,
        int $startedAt,
        array $inputSummary,
        ?array $outputSummary,
        array $outcome
    ): void {
        $error = $outcome['error'] ?? null;
        $errorSummary = null;
        if ($error instanceof PlatformAiException && $error->providerDetail() !== '') {
            $sanitized = PlatformSensitiveData::sanitize($error->providerDetail(), 'provider_response');
            $errorSummary = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $completedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->store->recordInvocation([
            'invocation_key' => bin2hex(random_bytes(16)),
            'request_id' => $request['request_id'],
            'idempotency_key' => $request['idempotency_key'],
            'capability' => $request['capability'],
            'purpose_code' => $request['purpose'],
            'data_classification' => $request['data_classification'],
            'requested_provider' => $request['requested_provider'],
            'actual_provider' => $outcome['actual_provider'] ?? null,
            'model' => $outcome['model'] ?? null,
            'contract_version' => $request['contract_version'],
            'processing_version' => $outcome['processing_version'] ?? null,
            'status' => $outcome['status'],
            'error_code' => $error instanceof PlatformAiException ? $error->errorCode() : null,
            'error_summary' => $errorSummary,
            'retryable' => $error instanceof PlatformAiException && $error->retryable() ? 1 : 0,
            'recovery_required' => $error instanceof PlatformAiException && $error->recoveryRequired() ? 1 : 0,
            'attempt_count' => (int)($outcome['attempt_count'] ?? 0),
            'fallback_used' => ($outcome['fallback_used'] ?? false) ? 1 : 0,
            'elapsed_ms' => self::elapsedMs($startedAt),
            'input_sha256' => $inputSummary['sha256'],
            'input_bytes' => $inputSummary['bytes'],
            'output_sha256' => $outputSummary['sha256'] ?? null,
            'output_bytes' => $outputSummary['bytes'] ?? null,
            'approval_id' => $outcome['approval_id'] ?? null,
            'retention_policy_code' => $request['retention_policy_code'],
            'retention_until' => $request['retention_until'],
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
            'completed_at' => $completedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int)floor((hrtime(true) - $startedAt) / 1000000));
    }

    private static function retentionUntil(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+180 days')
                ->format('Y-m-d H:i:s.u');
        }
        try {
            $retention = new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new PlatformAiException('request_invalid');
        }
        if ($retention <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new PlatformAiException('request_invalid');
        }
        return $retention->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function token(mixed $value, string $error, int $maxLength, int $minLength = 1): string
    {
        $token = trim((string)$value);
        $length = strlen($token);
        if (
            $length < $minLength
            || $length > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $token) !== 1
        ) {
            throw new InvalidArgumentException($error);
        }
        return $token;
    }

    private static function optionalToken(mixed $value, int $maxLength): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return self::token($value, 'request_invalid', $maxLength);
    }
}
