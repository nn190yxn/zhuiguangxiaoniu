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
                'scenario_rules' => $context['scenario_rules'] ?? [],
                'current_stage' => $context['current_stage'] ?? [],
                'stage_prompt_contract' => $context['stage_prompt_contract'] ?? [],
                'history' => $context['history'] ?? [],
            ], '你是销售演练中的真实家长。只返回 JSON 对象，字段为 response、intent、target_action 和 stage_code。每次只问一个自然问题，使用家长日常说话方式，控制在 1 至 2 句话，避免“当前环节、训练目标、关键动作、价值呈现”等培训术语。问题必须体现 customer_profile 中的孩子情况、家庭顾虑和沟通风格，优先围绕 stage_prompt_contract.validation_actions 和 scenario_rules.key_actions 中尚未覆盖的动作提问。你只能提出客户问题，不能替员工回答，不能编造场景外的课程、价格、政策或承诺；必须遵守 stage_prompt_contract.must_avoid 和 scenario_rules.risk_expressions。真实家长问题示例：孩子才 4 岁，现在学会不会太早？你们这个课具体能帮他解决什么？我们家离得有点远，一周要来几次？价格怎么收费，能不能先试一段时间？', 800, 0.5, true);
        } catch (DrillAiRetryableException $error) {
            if (!in_array($error->getMessage(), ['销售演练 AI 未返回 JSON 对象。', '销售演练 AI JSON 解析失败。'], true)) {
                throw $error;
            }
            return [
                'content' => $this->fallbackCustomerQuestion($context),
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

    private function fallbackCustomerQuestion(array $context): string
    {
        $stageCode = trim((string) ($context['current_stage']['stage_code'] ?? ''));
        $stage = trim((string) (($context['current_stage']['name'] ?? $context['current_stage']['stage_code'] ?? '当前环节')));
        $naturalQuestions = [
            'lead_preparation' => '我是在网上看到你们的，想先了解一下适不适合我家孩子，可以先给我介绍一下吗？',
            'invitation_confirmation' => '体验课具体是哪天？需要提前准备什么，孩子要早点到吗？',
            'arrival_reception' => '孩子第一次来有点慢热，你们会先怎么带他适应？',
            'needs_diagnosis' => '我家孩子最近不太愿意主动运动，来这里主要能帮他改善什么？',
            'assessment_experience' => '刚才你说他这方面需要加强，平时在家里会有什么表现？',
            'solution_value' => '你们这个课程具体怎么帮到孩子？和普通兴趣班有什么区别？',
            'objection_signing_handoff' => '我还是有点担心价格和坚持问题，万一不合适怎么办？',
            'followup_referral' => '我回去和家人商量一下，你把今天的情况发我，明天下午再联系可以吗？',
        ];
        if (isset($naturalQuestions[$stageCode])) {
            return $naturalQuestions[$stageCode];
        }
        $actions = array_values((array) (($context['scenario_rules']['key_actions'] ?? [])));
        $action = $actions[0] ?? '';
        if (is_array($action)) {
            $action = $action['name'] ?? $action['content'] ?? $action['action'] ?? '';
        }
        $action = trim((string) $action);
        return $action !== ''
            ? '在' . $stage . '这个环节，我比较关心您会怎样' . $action . '，可以具体说说吗？'
            : '在' . $stage . '这个环节，我最关心这一步具体要怎么推进？';
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
