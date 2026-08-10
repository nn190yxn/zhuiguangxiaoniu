<?php

declare(strict_types=1);

final class DrillAiRetryableException extends RuntimeException
{
}

/**
 * Injectable boundary for sales-drill AI calls. Persist callers only receive
 * provider metadata and a controlled response reference, never a raw payload.
 */
final class DrillAiAdapter
{
    /** @var callable(string, string, int, float): string */
    private $chat;

    public function __construct(
        private string $provider,
        private string $model,
        private int $timeoutSeconds,
        callable $chat,
        private array $promptVersions = []
    ) {
        if ($provider === '' || $model === '' || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('AI 适配器配置不完整。');
        }
        $this->chat = $chat;
    }

    public static function fromProjectRuntime(array $config = []): self
    {
        $provider = trim((string) ($config['provider'] ?? getenv('DRILL_AI_PROVIDER') ?: 'deepseek'));
        $model = trim((string) ($config['model'] ?? getenv('DRILL_AI_MODEL') ?: 'default'));
        $timeout = (int) ($config['timeout_seconds'] ?? getenv('DRILL_AI_TIMEOUT_SECONDS') ?: 45);
        $versions = (array) ($config['prompt_versions'] ?? []);
        if ($provider !== 'deepseek') {
            throw new InvalidArgumentException('当前销售演练 AI 提供方未注册。');
        }
        require_once dirname(__DIR__, 3) . '/ai-runtime.php';
        if (!function_exists('ai_gateway_text_generate')) {
            throw new DrillAiRetryableException('销售演练 AI 运行时暂不可用。');
        }
        if ($model === 'default' && function_exists('ai_runtime_load_settings')) {
            $settings = ai_runtime_load_settings();
            $model = trim((string) ($settings['deepseek_model'] ?? 'deepseek-v4-flash'));
        }
        return new self(
            $provider,
            $model,
            $timeout,
            static fn(string $prompt, string $system, int $tokens, float $temperature): string => ai_gateway_text_generate(
                $prompt,
                $system,
                'sales_drill_text_generate',
                array(
                    'max_tokens' => $tokens,
                    'temperature' => $temperature,
                    'json_object' => true,
                    'timeout_ms' => min(120000, max(100, $timeout * 1000)),
                    'business_authorized' => true,
                    'approval_id' => 'sales-drill-runtime',
                )
            ),
            $versions
        );
    }

    public function generateCustomerTurn(array $context): array
    {
        try {
            $result = $this->request('customer_turn', [
                'customer_profile' => $context['customer_profile'] ?? [],
                'scenario_goal' => $context['scenario_goal'] ?? [],
                'current_stage' => $context['current_stage'] ?? [],
                'history' => $context['history'] ?? [],
            ], '你是销售演练中的客户。只返回 JSON 对象，字段为 response 和 intent；回应应基于已提供的画像和对话，禁止编造资料。', 800, 0.5, true);
        } catch (DrillAiRetryableException $error) {
            if (!in_array($error->getMessage(), ['销售演练 AI 未返回 JSON 对象。', '销售演练 AI JSON 解析失败。'], true)) {
                throw $error;
            }
            return [
                'content' => '我想先了解您建议的具体安排，以及它如何适合我们目前的需求。',
                'intent' => 'continue',
                'metadata' => [
                    'provider' => $this->provider,
                    'model' => $this->model,
                    'prompt_version' => (string) ($this->promptVersions['customer_turn'] ?? 'sales_drill_customer_turn_v1') . ':fallback',
                    'duration_ms' => 0,
                    'raw_response_ref' => 'ai:customer_turn:fallback:' . hash('sha256', $error->getMessage()),
                ],
            ];
        }
        $response = trim((string) ($result['payload']['response'] ?? ''));
        if ($response === '') {
            throw new DrillAiRetryableException('客户回应结构不完整。');
        }
        return ['content' => $response, 'intent' => trim((string) ($result['payload']['intent'] ?? 'continue')), 'metadata' => $result['metadata']];
    }

    public function mapSpeakers(array $context): array
    {
        return $this->request('speaker_mapping', $context, '你是演练录音说话人标注器。只返回 JSON 对象，包含 segments 数组；每项含 segment_no、speaker_key、role_code、starts_ms、ends_ms、content、confidence、is_coach_supplement。仅引用给出的转写内容。', 1800, 0.1);
    }

    public function evaluateAttempt(array $context): array
    {
        try {
            return $this->request('evaluation', $context, '你是销售演练评分助手。只输出一个可解析的 JSON 对象，不要 Markdown、说明文字或代码块。固定结构为 {"total_score":0,"dimension_scores":{},"critical_results":{},"evidence":[],"suggestions":[],"smart_actions":[]}。dimension_scores 的键使用评分规则中的维度 code，evidence 的 segment_id 必须引用输入 segments 的 id；输入证据不足时使用 insufficient_evidence。', 2400, 0.1, true);
        } catch (DrillAiRetryableException $error) {
            if (!in_array($error->getMessage(), ['销售演练 AI 未返回 JSON 对象。', '销售演练 AI JSON 解析失败。'], true)) {
                throw $error;
            }
            return $this->deterministicEvaluation($context, $error);
        }
    }

    public function generateScenarioDraft(array $context): array
    {
        return $this->request('scenario_draft', $context, '你是销售训练场景草稿助手。只返回 JSON 对象，包含 title、customer_profile、objectives、key_actions、standard_expressions、risk_expressions、prompt_policy。输出仅供人工审核。', 1800, 0.3);
    }

    private function request(string $operation, array $input, string $system, int $tokens, float $temperature, bool $repairInvalidJson = false): array
    {
        $startedAt = hrtime(true);
        $repaired = false;
        try {
            $raw = ($this->chat)(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $system, $tokens, $temperature);
            try {
                $payload = $this->decodeJson((string) $raw);
            } catch (DrillAiRetryableException $error) {
                if (!$repairInvalidJson || trim((string) $raw) === '') {
                    throw $error;
                }
                $repairedRaw = ($this->chat)(
                    '将以下销售演练评分输出转换为严格 JSON 对象。只输出 JSON，不要 Markdown。必须保留 total_score、dimension_scores、critical_results、evidence、suggestions、smart_actions 字段；缺失字段使用空对象或空数组。原始输出：' . (string) $raw,
                    '你是结构化输出修复器。只返回可解析的 JSON 对象。',
                    $tokens,
                    0.0
                );
                $payload = $this->decodeJson((string) $repairedRaw);
                $raw = $repairedRaw;
                $repaired = true;
            }
        } catch (DrillAiRetryableException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DrillAiRetryableException('销售演练 AI 调用失败：' . $error->getMessage(), 0, $error);
        }
        return [
            'payload' => $payload,
            'metadata' => [
                'provider' => $this->provider,
                'model' => $this->model,
                'prompt_version' => (string) ($this->promptVersions[$operation] ?? 'sales_drill_' . $operation . '_v1') . ($repaired ? ':repaired' : ''),
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1000000),
                'raw_response_ref' => 'ai:' . $operation . ':' . hash('sha256', $this->provider . '|' . $this->model . '|' . (string) $raw),
            ],
        ];
    }

    private function decodeJson(string $raw): array
    {
        if (preg_match('/\{[\s\S]*\}/', $raw, $matches) !== 1) {
            throw new DrillAiRetryableException('销售演练 AI 未返回 JSON 对象。');
        }
        $decoded = json_decode($matches[0], true);
        if (!is_array($decoded)) {
            throw new DrillAiRetryableException('销售演练 AI JSON 解析失败。');
        }
        return $decoded;
    }

    private function insufficientEvidenceEvaluation(array $context, DrillAiRetryableException $error): array
    {
        $dimensionScores = [];
        foreach ((array) ($context['rubric']['dimensions'] ?? []) as $dimension) {
            $code = trim((string) ($dimension['code'] ?? ''));
            if ($code !== '') {
                $dimensionScores[$code] = [
                    'capability_score' => 0,
                    'script_match_score' => 0,
                    'evidence_status' => 'insufficient_evidence',
                ];
            }
        }
        return [
            'payload' => [
                'total_score' => 0,
                'dimension_scores' => $dimensionScores,
                'critical_results' => [],
                'evidence' => [],
                'evidence_status' => 'insufficient_evidence',
                'suggestions' => ['评分服务收到的 AI 输出格式无效，本次结果按证据不足处理，请补充完整对练后再次评分。'],
                'smart_actions' => [],
            ],
            'metadata' => [
                'provider' => $this->provider,
                'model' => $this->model,
                'prompt_version' => (string) ($this->promptVersions['evaluation'] ?? 'sales_drill_evaluation_v1') . ':insufficient_evidence',
                'duration_ms' => 0,
                'raw_response_ref' => 'ai:evaluation:insufficient_evidence:' . hash('sha256', $error->getMessage()),
            ],
        ];
    }

    private function deterministicEvaluation(array $context, DrillAiRetryableException $error): array
    {
        $segments = array_values((array) ($context['segments'] ?? []));
        $dimensions = array_values((array) ($context['rubric']['dimensions'] ?? []));
        if ($segments === [] || $dimensions === []) {
            return $this->insufficientEvidenceEvaluation($context, $error);
        }

        $characterCount = array_sum(array_map(static function (array $segment): int {
            $content = trim((string) ($segment['content'] ?? ''));
            return function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
        }, $segments));
        $coverage = min(1, $characterCount / 120);
        $dimensionScores = [];
        $evidence = [];
        foreach ($dimensions as $index => $dimension) {
            $code = trim((string) ($dimension['code'] ?? ''));
            $weight = (float) ($dimension['weight'] ?? 0);
            if ($code === '' || $weight <= 0) {
                continue;
            }
            $score = round($weight * $coverage, 2);
            $dimensionScores[$code] = [
                'capability_score' => $score,
                'script_match_score' => $score,
                'evidence_status' => 'deterministic_reference',
            ];
            $segment = $segments[$index % count($segments)];
            $evidence[] = [
                'segment_id' => (int) ($segment['id'] ?? 0),
                'dimension_code' => $code,
                'criterion_code' => 'deterministic_text_coverage',
                'evidence_type' => 'deterministic_reference',
                'status' => 'supported',
            ];
        }
        if ($dimensionScores === []) {
            return $this->insufficientEvidenceEvaluation($context, $error);
        }

        return [
            'payload' => [
                'dimension_scores' => $dimensionScores,
                'critical_results' => [],
                'evidence' => $evidence,
                'evidence_status' => 'deterministic_reference',
                'suggestions' => ['AI 评分服务未返回有效结构化结果，本次分数依据已确认文本长度生成，仅供本次练习参考。'],
                'smart_actions' => [],
            ],
            'metadata' => [
                'provider' => $this->provider,
                'model' => $this->model,
                'prompt_version' => (string) ($this->promptVersions['evaluation'] ?? 'sales_drill_evaluation_v1') . ':deterministic_fallback',
                'duration_ms' => 0,
                'raw_response_ref' => 'ai:evaluation:deterministic_fallback:' . hash('sha256', $error->getMessage()),
            ],
        ];
    }
}
