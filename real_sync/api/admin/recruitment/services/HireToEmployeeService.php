<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
require_once __DIR__ . '/ResumeFieldNormalizer.php';
require_once __DIR__ . '/RecruitmentPermissionService.php';
require_once dirname(__DIR__, 2) . '/services/StaffLifecycleService.php';
require_once dirname(__DIR__, 3) . '/kernel/bootstrap.php';
require_once dirname(__DIR__, 3) . '/platform/JobQueue.php';
require_once dirname(__DIR__) . '/platform/RecruitmentReminderProjection.php';

final class HireToEmployeeService
{
    private const REQUIRED_TABLES = [
        'recruitment_hire_approvals',
        'recruitment_hire_conversions',
        'platform_outbox_events',
        'platform_side_effect_receipts',
        'admin_operation_logs',
    ];

    public function __construct(private PDO $pdo, private RecruitmentPermissionService $permissions)
    {
    }

    public function approve(int $applicationId, string $reason, int $expectedVersion, string $idempotencyKey, array $scope, int $staffId): array
    {
        $this->assertSchemaReady();
        $reason = mb_substr(trim($reason), 0, 1000, 'UTF-8');
        if ($applicationId <= 0 || $reason === '' || $staffId <= 0 || trim($idempotencyKey) === '') {
            throw new RecruitmentAdminException('录用审批参数不完整');
        }
        $this->pdo->beginTransaction();
        try {
            $application = $this->lockApplication($applicationId, $scope);
            $stmt = $this->pdo->prepare('SELECT * FROM recruitment_hire_approvals WHERE application_id = ? OR idempotency_key = ? ORDER BY application_id = ? DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$applicationId, $idempotencyKey, $applicationId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int) $existing['application_id'] !== $applicationId || (string) $existing['decision'] !== 'approved') {
                    throw new RecruitmentAdminException('录用审批幂等键冲突', 409);
                }
                $this->pdo->commit();
                return $this->approvalResponse($existing, (int) $existing['state_version']);
            }
            PlatformStateVersion::assertExpected((int) $application['state_version'], $expectedVersion, ['application_id' => $applicationId]);
            $this->assertEligible($application);
            $nextVersion = PlatformStateVersion::next((int) $application['state_version']);
            $insert = $this->pdo->prepare("INSERT INTO recruitment_hire_approvals (application_id, decision, approval_reason, idempotency_key, state_version, approved_by) VALUES (?, 'approved', ?, ?, ?, ?)");
            $insert->execute([$applicationId, $reason, $idempotencyKey, $nextVersion, $staffId]);
            $approvalId = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare("UPDATE recruitment_applications SET hiring_status = 'approved', state_version = ? WHERE id = ? AND state_version = ?");
            $update->execute([$nextVersion, $applicationId, (int) $application['state_version']]);
            if ($update->rowCount() !== 1) {
                throw new RecruitmentAdminException('候选人状态已变化', 409);
            }
            $approval = $this->approvalById($approvalId);
            $this->pdo->commit();
            return $this->approvalResponse($approval, $nextVersion);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function convert(int $applicationId, array $input, int $expectedVersion, string $idempotencyKey, array $scope, array $operatorUser, array $operatorStaff): array
    {
        $this->assertSchemaReady();
        if ($applicationId <= 0 || trim($idempotencyKey) === '') {
            throw new RecruitmentAdminException('录用转员工参数不完整');
        }
        $requestHash = hash('sha256', PlatformJobQueueService::canonicalJson(['application_id' => $applicationId, 'input' => $input]));
        $this->pdo->beginTransaction();
        try {
            $application = $this->lockApplication($applicationId, $scope);
            $conversion = $this->existingConversion($applicationId, $idempotencyKey);
            if ($conversion) {
                if (!hash_equals((string) $conversion['request_hash'], $requestHash)) {
                    throw new RecruitmentAdminException('录用转换幂等键或请求内容冲突', 409);
                }
                if ((string) $conversion['status'] !== 'completed' || trim((string) $conversion['response_json']) === '') {
                    throw new RecruitmentAdminException('录用转换正在处理中', 409);
                }
                $this->pdo->commit();
                return json_decode((string) $conversion['response_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            PlatformStateVersion::assertExpected((int) $application['state_version'], $expectedVersion, ['application_id' => $applicationId]);
            $this->assertEligible($application);
            if ((string) $application['hiring_status'] !== 'approved') {
                throw new RecruitmentAdminException('候选人尚未完成录用审批', 409);
            }
            $approval = $this->approvedApproval($applicationId);
            $insert = $this->pdo->prepare("INSERT INTO recruitment_hire_conversions (application_id, approval_id, idempotency_key, request_hash, status, converted_by) VALUES (?, ?, ?, ?, 'processing', ?)");
            $insert->execute([$applicationId, (int) $approval['id'], $idempotencyKey, $requestHash, (int) ($operatorStaff['id'] ?? 0)]);
            $conversionId = (int) $this->pdo->lastInsertId();
            $normalizer = new ResumeFieldNormalizer();
            $staffInput = [
                ...$input,
                'name' => (string) $application['candidate_name'],
                'phone' => $normalizer->decrypt($application['phone_ciphertext'] ?? null),
                'store_id' => (int) ($input['store_id'] ?? $application['store_id']),
                'position_id' => (int) ($input['position_id'] ?? $application['position_id']),
            ];
            $employee = (new StaffLifecycleService($this->pdo))->create($staffInput, $operatorUser, $operatorStaff);
            $nextVersion = PlatformStateVersion::next((int) $application['state_version']);
            $applicationUpdate = $this->pdo->prepare("UPDATE recruitment_applications SET hiring_status = 'converted', state_version = ? WHERE id = ? AND state_version = ?");
            $applicationUpdate->execute([$nextVersion, $applicationId, (int) $application['state_version']]);
            if ($applicationUpdate->rowCount() !== 1) {
                throw new RecruitmentAdminException('候选人状态已变化', 409);
            }
            $response = [
                'application_id' => $applicationId,
                'approval_id' => (int) $approval['id'],
                'conversion_id' => $conversionId,
                'employee' => $employee,
                'state_version' => $nextVersion,
                'hiring_status' => 'converted',
            ];
            $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $complete = $this->pdo->prepare("UPDATE recruitment_hire_conversions SET employee_staff_id = ?, response_json = ?, status = 'completed', state_version = state_version + 1, converted_at = NOW(6) WHERE id = ?");
            $complete->execute([(int) $employee['id'], $responseJson, $conversionId]);
            (new RecruitmentReminderProjection($this->pdo))->hireConverted($applicationId, (int) $employee['id'], $idempotencyKey);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertSchemaReady(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!adminTableExists($this->pdo, $table)) {
                throw new RecruitmentAdminException('录用转员工数据库结构尚未就绪', 503, ['code' => 'schema_not_ready', 'table' => $table]);
            }
        }
    }

    private function lockApplication(int $applicationId, array $scope): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT application.*, candidate.name AS candidate_name, candidate.phone_ciphertext, requirement.status AS requirement_status, '
            . 'requirement.store_id, requirement.position_id, document.status AS document_status '
            . 'FROM recruitment_applications application '
            . 'JOIN recruitment_candidates candidate ON candidate.id = application.candidate_id '
            . 'JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id '
            . 'JOIN recruitment_resume_documents document ON document.id = application.document_id '
            . 'WHERE application.id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$application || !$this->permissions->canAccessRequirement($scope, (int) $application['requirement_id'])) {
            throw new RecruitmentAdminException('候选人投递不存在或超出数据范围', 404);
        }
        return $application;
    }

    private function assertEligible(array $application): void
    {
        if ((string) $application['requirement_status'] !== 'approved') {
            throw new RecruitmentAdminException('招聘需求当前状态不允许录用', 409);
        }
        if ((string) $application['document_status'] !== 'completed' || !in_array((string) $application['effective_grade'], ['A', 'B'], true)) {
            throw new RecruitmentAdminException('候选人尚未完成有效筛选', 409);
        }
        if ((string) $application['queue_status'] !== 'appointment' || (string) $application['contact_status'] !== 'scheduled') {
            throw new RecruitmentAdminException('候选人尚未完成预约联系', 409);
        }
    }

    private function approvedApproval(int $applicationId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM recruitment_hire_approvals WHERE application_id = ? AND decision = 'approved' LIMIT 1 FOR UPDATE");
        $stmt->execute([$applicationId]);
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$approval || (int) ($approval['approved_by'] ?? 0) <= 0 || trim((string) ($approval['approved_at'] ?? '')) === '') {
            throw new RecruitmentAdminException('录用审批记录缺失或结构不完整', 409);
        }
        return $approval;
    }

    private function existingConversion(int $applicationId, string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_hire_conversions WHERE application_id = ? OR idempotency_key = ? ORDER BY application_id = ? DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$applicationId, $idempotencyKey, $applicationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function approvalById(int $approvalId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_hire_approvals WHERE id = ? LIMIT 1');
        $stmt->execute([$approvalId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function approvalResponse(array $approval, int $stateVersion): array
    {
        return [
            'approval_id' => (int) ($approval['id'] ?? 0),
            'application_id' => (int) ($approval['application_id'] ?? 0),
            'decision' => (string) ($approval['decision'] ?? ''),
            'state_version' => $stateVersion,
            'hiring_status' => 'approved',
        ];
    }
}
