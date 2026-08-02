<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/ai-runtime.php';
require_once dirname(__DIR__) . '/services/ExternalProcessorGateService.php';

final class RecruitmentPlatformAiAdapter
{
    private ExternalProcessorGateService $gate;

    public function __construct(private PDO $pdo)
    {
        $this->gate = new ExternalProcessorGateService($pdo);
    }

    public function generate(string $prompt, string $systemPrompt, string $idempotencyKey): array
    {
        $processor = $this->gate->assertApproved('model', 'deepseek');
        $approvalId = (string) ($processor['approval_reference'] ?? $processor['id'] ?? '');
        if ($approvalId === '') {
            throw new RecruitmentAdminException('外部模型审批缺少 approval_id', 503);
        }
        $content = ai_gateway_text_generate($prompt, $systemPrompt, 'recruitment.resume.extract', [
            'preferred_provider' => 'deepseek',
            'data_classification' => 'sensitive_personal',
            'max_tokens' => 6000,
            'temperature' => 0.0,
            'idempotency_key' => $idempotencyKey,
            'retention_policy_code' => 'recruitment-ai-180d',
            'business_authorized' => true,
            'approval_id' => $approvalId,
        ]);
        return ['content' => $content, 'processor' => $processor];
    }
}
