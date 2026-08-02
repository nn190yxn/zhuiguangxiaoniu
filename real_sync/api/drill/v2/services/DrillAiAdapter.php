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
        $result = $this->request('customer_turn', [
            'customer_profile' => $context['customer_profile'] ?? [],
            'scenario_goal' => $context['scenario_goal'] ?? [],
            'current_stage' => $context['current_stage'] ?? [],
            'history' => $context['history'] ?? [],
        ], '你是销售演练中的客户。只返回 JSON 对象，字段为 response 和 intent；回应应基于已提供的画像和对话，禁止编造资料。', 800, 0.5);
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
        return $this->request('evaluation', $context, '你是销售演练评分助手。只返回 JSON 对象，包含 total_score、dimension_scores、critical_results、evidence、suggestions、smart_actions。证据必须引用输入 segments 的 id，输入证据不足时标记 insufficient_evidence。', 2400, 0.1);
    }

    public function generateScenarioDraft(array $context): array
    {
        return $this->request('scenario_draft', $context, '你是销售训练场景草稿助手。只返回 JSON 对象，包含 title、customer_profile、objectives、key_actions、standard_expressions、risk_expressions、prompt_policy。输出仅供人工审核。', 1800, 0.3);
    }

    private function request(string $operation, array $input, string $system, int $tokens, float $temperature): array
    {
        $startedAt = hrtime(true);
        try {
            $raw = ($this->chat)(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $system, $tokens, $temperature);
            $payload = $this->decodeJson((string) $raw);
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
                'prompt_version' => (string) ($this->promptVersions[$operation] ?? 'sales_drill_' . $operation . '_v1'),
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
}
