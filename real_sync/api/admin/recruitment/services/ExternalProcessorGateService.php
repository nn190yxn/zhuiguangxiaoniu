<?php

declare(strict_types=1);

final class ExternalProcessorGateService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function assertApproved(string $type, string $provider): array
    {
        if ($provider === 'local') {
            return ['processor_code' => 'local', 'provider' => 'local', 'service_region' => 'local'];
        }
        if (!adminTableExists($this->pdo, 'recruitment_external_processors')) {
            throw new RecruitmentAdminException('外部处理服务门禁迁移尚未执行', 503);
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM recruitment_external_processors WHERE processor_type = ? AND provider = ? AND approval_status = 'approved' AND status = 1 ORDER BY approved_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$type, $provider]);
        $processor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$processor) {
            throw new RecruitmentAdminException('外部' . strtoupper($type) . '服务尚未完成数据边界审批', 503);
        }
        foreach (['service_region', 'transport_encryption', 'deletion_mechanism', 'approved_by', 'approved_at'] as $field) {
            if (trim((string) ($processor[$field] ?? '')) === '') {
                throw new RecruitmentAdminException('外部处理服务审批信息不完整：' . $field, 503);
            }
        }
        if ((int) ($processor['training_use_allowed'] ?? 0) !== 0) {
            throw new RecruitmentAdminException('真实简历处理要求供应商关闭训练使用', 503);
        }
        if ($type === 'model' && trim((string) ($processor['model_name'] ?? '')) === '') {
            throw new RecruitmentAdminException('外部模型审批信息缺少模型名称', 503);
        }
        return $processor;
    }
}
