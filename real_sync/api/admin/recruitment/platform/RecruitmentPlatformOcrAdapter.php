<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/ai-runtime.php';
require_once dirname(__DIR__) . '/services/ExternalProcessorGateService.php';

final class RecruitmentPlatformOcrAdapter
{
    private ExternalProcessorGateService $gate;

    public function __construct(private PDO $pdo)
    {
        $this->gate = new ExternalProcessorGateService($pdo);
    }

    public function extract(string $path, string $idempotencyKey): array
    {
        $processor = $this->gate->assertApproved('ocr', 'baidu_ocr');
        $approvalId = (string) ($processor['approval_reference'] ?? $processor['id'] ?? '');
        if ($approvalId === '') {
            throw new RecruitmentAdminException('外部 OCR 审批缺少 approval_id', 503);
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('OCR 图片读取失败');
        }
        $text = ai_gateway_ocr_extract('data:application/octet-stream;base64,' . base64_encode($content), 'recruitment.resume.ocr', [
            'preferred_provider' => 'baidu_ocr',
            'data_classification' => 'sensitive_personal',
            'idempotency_key' => $idempotencyKey,
            'retention_policy_code' => 'recruitment-ocr-180d',
            'business_authorized' => true,
            'approval_id' => $approvalId,
        ]);
        return ['text' => $text, 'processor' => $processor];
    }
}
