<?php

declare(strict_types=1);

final class RecruitmentGovernanceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listProcessors(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM recruitment_external_processors ORDER BY processor_type, processor_code, id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveProcessor(array $input, int $staffId): array
    {
        $id = (int) ($input['id'] ?? 0);
        $type = trim((string) ($input['processor_type'] ?? ''));
        $allowedTypes = ['ocr', 'model', 'storage', 'export'];
        $fields = [
            'processor_code' => 80, 'processor_name' => 160, 'provider' => 120, 'model_name' => 160,
            'service_region' => 120, 'transport_encryption' => 160, 'deletion_mechanism' => 500,
        ];
        $values = [];
        foreach ($fields as $field => $limit) {
            $values[$field] = mb_substr(trim((string) ($input[$field] ?? '')), 0, $limit, 'UTF-8');
        }
        if (!in_array($type, $allowedTypes, true) || $values['processor_code'] === '' || $values['processor_name'] === '' || $values['provider'] === '' || $values['service_region'] === '' || $values['transport_encryption'] === '' || $values['deletion_mechanism'] === '') {
            throw new RecruitmentAdminException('外部处理服务的数据边界信息必须完整填写');
        }
        if ($type === 'model' && $values['model_name'] === '') {
            throw new RecruitmentAdminException('模型处理服务必须填写模型名称');
        }
        $retentionDays = max(0, min(3650, (int) ($input['retention_days'] ?? 0)));
        $training = !empty($input['training_use_allowed']) ? 1 : 0;
        $subcontractors = is_array($input['subcontractors'] ?? null) ? $input['subcontractors'] : [];
        if ($id > 0) {
            $stmt = $this->pdo->prepare("UPDATE recruitment_external_processors SET processor_code = ?, processor_name = ?, processor_type = ?, provider = ?, model_name = ?, service_region = ?, transport_encryption = ?, retention_days = ?, training_use_allowed = ?, subcontractors_json = ?, deletion_mechanism = ?, approval_status = 'draft', approved_by = NULL, approved_at = NULL, status = 1 WHERE id = ?");
            $stmt->execute([$values['processor_code'], $values['processor_name'], $type, $values['provider'], $values['model_name'] ?: null, $values['service_region'], $values['transport_encryption'], $retentionDays, $training, json_encode($subcontractors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $values['deletion_mechanism'], $id]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO recruitment_external_processors (processor_code, processor_name, processor_type, provider, model_name, service_region, transport_encryption, retention_days, training_use_allowed, subcontractors_json, deletion_mechanism, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$values['processor_code'], $values['processor_name'], $type, $values['provider'], $values['model_name'] ?: null, $values['service_region'], $values['transport_encryption'], $retentionDays, $training, json_encode($subcontractors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $values['deletion_mechanism'], $staffId ?: null]);
            $id = (int) $this->pdo->lastInsertId();
        }
        return $this->processor($id);
    }

    public function approveProcessor(int $id, string $action, int $staffId): array
    {
        $processor = $this->processor($id);
        if (!in_array($action, ['approve', 'disable'], true)) {
            throw new RecruitmentAdminException('外部处理服务审批动作无效');
        }
        if ($action === 'approve' && (int) $processor['training_use_allowed'] !== 0) {
            throw new RecruitmentAdminException('真实简历处理服务必须关闭训练使用');
        }
        $status = $action === 'approve' ? 'approved' : 'disabled';
        $stmt = $this->pdo->prepare('UPDATE recruitment_external_processors SET approval_status = ?, status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $action === 'approve' ? 1 : 0, $staffId ?: null, $id]);
        return $this->processor($id);
    }

    public function listRetention(): array
    {
        return [
            'policies' => $this->pdo->query('SELECT * FROM recruitment_retention_policies ORDER BY data_category, effective_version DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'legal_holds' => $this->pdo->query('SELECT * FROM recruitment_legal_holds ORDER BY status, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'disposal_jobs' => $this->pdo->query('SELECT * FROM recruitment_disposal_jobs ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public function savePolicy(array $input, int $staffId): array
    {
        $code = mb_substr(trim((string) ($input['policy_code'] ?? '')), 0, 80, 'UTF-8');
        $category = trim((string) ($input['data_category'] ?? ''));
        $action = trim((string) ($input['disposal_action'] ?? ''));
        if ($code === '' || !in_array($category, $this->categories(), true) || !in_array($action, ['delete', 'anonymize', 'archive'], true)) {
            throw new RecruitmentAdminException('留存策略参数无效');
        }
        $days = max(1, min(36500, (int) ($input['retention_days'] ?? 0)));
        $versionStmt = $this->pdo->prepare('SELECT COALESCE(MAX(effective_version), 0) + 1 FROM recruitment_retention_policies WHERE policy_code = ?');
        $versionStmt->execute([$code]);
        $version = (int) $versionStmt->fetchColumn();
        $stmt = $this->pdo->prepare("INSERT INTO recruitment_retention_policies (policy_code, data_category, retention_days, disposal_action, effective_version, status, created_by) VALUES (?, ?, ?, ?, ?, 'draft', ?)");
        $stmt->execute([$code, $category, $days, $action, $version, $staffId ?: null]);
        return $this->row('recruitment_retention_policies', (int) $this->pdo->lastInsertId());
    }

    public function publishPolicy(int $id, int $staffId): array
    {
        $policy = $this->row('recruitment_retention_policies', $id);
        if ($policy['status'] !== 'draft') {
            throw new RecruitmentAdminException('仅草稿留存策略可发布', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $archive = $this->pdo->prepare("UPDATE recruitment_retention_policies SET status = 'archived' WHERE policy_code = ? AND status = 'published'");
            $archive->execute([$policy['policy_code']]);
            $publish = $this->pdo->prepare("UPDATE recruitment_retention_policies SET status = 'published', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $publish->execute([$staffId ?: null, $id]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->row('recruitment_retention_policies', $id);
    }

    public function createHold(array $input, int $staffId): array
    {
        $scopeType = trim((string) ($input['scope_type'] ?? ''));
        $scopeId = isset($input['scope_id']) ? (int) $input['scope_id'] : null;
        $basis = mb_substr(trim((string) ($input['legal_basis'] ?? '')), 0, 500, 'UTF-8');
        $reason = mb_substr(trim((string) ($input['hold_reason'] ?? '')), 0, 1000, 'UTF-8');
        if (!in_array($scopeType, $this->scopeTypes(), true) || ($scopeType !== 'all' && (!$scopeId || $scopeId <= 0)) || $basis === '' || $reason === '') {
            throw new RecruitmentAdminException('法务冻结范围、依据和原因必须完整填写');
        }
        $holdNo = 'HOLD' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $this->pdo->prepare('INSERT INTO recruitment_legal_holds (hold_no, scope_type, scope_id, legal_basis, hold_reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$holdNo, $scopeType, $scopeType === 'all' ? null : $scopeId, $basis, $reason, $staffId ?: null]);
        return $this->row('recruitment_legal_holds', (int) $this->pdo->lastInsertId());
    }

    public function releaseHold(int $id, string $reason, int $staffId): array
    {
        $reason = mb_substr(trim($reason), 0, 1000, 'UTF-8');
        if ($reason === '') {
            throw new RecruitmentAdminException('解除冻结原因不能为空');
        }
        $stmt = $this->pdo->prepare("UPDATE recruitment_legal_holds SET status = 'released', released_by = ?, released_at = NOW(), release_reason = ? WHERE id = ? AND status = 'active'");
        $stmt->execute([$staffId ?: null, $reason, $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RecruitmentAdminException('有效法务冻结不存在', 404);
        }
        return $this->row('recruitment_legal_holds', $id);
    }

    public function createDisposal(array $input, int $staffId): array
    {
        $policy = $this->row('recruitment_retention_policies', (int) ($input['policy_id'] ?? 0));
        if ($policy['status'] !== 'published') {
            throw new RecruitmentAdminException('处置任务必须引用已发布留存策略', 409);
        }
        $scopeType = trim((string) ($input['scope_type'] ?? 'all'));
        $scopeId = isset($input['scope_id']) ? (int) $input['scope_id'] : null;
        if (!in_array($scopeType, $this->scopeTypes(), true) || ($scopeType !== 'all' && (!$scopeId || $scopeId <= 0))) {
            throw new RecruitmentAdminException('处置任务范围无效');
        }
        $count = $this->eligibleCount((string) $policy['data_category'], (int) $policy['retention_days'], $scopeType, $scopeId);
        $blocked = $this->holdBlocks($scopeType, $scopeId) ? $count : 0;
        $no = 'DSP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $this->pdo->prepare("INSERT INTO recruitment_disposal_jobs (disposal_no, policy_id, data_category, scope_type, scope_id, scan_status, scanned_count, blocked_by_hold_count, created_by) VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, ?)");
        $stmt->execute([$no, (int) $policy['id'], $policy['data_category'], $scopeType, $scopeType === 'all' ? null : $scopeId, $count, $blocked, $staffId ?: null]);
        return $this->row('recruitment_disposal_jobs', (int) $this->pdo->lastInsertId());
    }

    public function decideDisposal(int $id, string $action, int $staffId): array
    {
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new RecruitmentAdminException('处置审批动作无效');
        }
        $job = $this->row('recruitment_disposal_jobs', $id);
        if ($job['approval_status'] !== 'pending') {
            throw new RecruitmentAdminException('处置任务已完成审批', 409);
        }
        if ($action === 'approve' && (int) $job['blocked_by_hold_count'] > 0) {
            throw new RecruitmentAdminException('处置范围受有效法务冻结阻断', 409);
        }
        $stmt = $this->pdo->prepare("UPDATE recruitment_disposal_jobs SET approval_status = ?, approved_by = ?, approved_at = NOW(), execution_status = CASE WHEN ? = 'reject' THEN 'cancelled' ELSE execution_status END WHERE id = ?");
        $stmt->execute([$action === 'approve' ? 'approved' : 'rejected', $staffId ?: null, $action, $id]);
        return $this->row('recruitment_disposal_jobs', $id);
    }

    public function executeDisposal(int $id): array
    {
        $job = $this->row('recruitment_disposal_jobs', $id);
        if ($job['approval_status'] !== 'approved' || !in_array($job['execution_status'], ['pending', 'failed'], true)) {
            throw new RecruitmentAdminException('处置任务当前不可执行', 409);
        }
        if ($this->holdBlocks((string) $job['scope_type'], isset($job['scope_id']) ? (int) $job['scope_id'] : null)) {
            throw new RecruitmentAdminException('处置范围新增有效法务冻结', 409);
        }
        $policy = $this->row('recruitment_retention_policies', (int) $job['policy_id']);
        $running = $this->pdo->prepare("UPDATE recruitment_disposal_jobs SET execution_status = 'running', started_at = NOW(), failure_message = NULL WHERE id = ?");
        $running->execute([$id]);
        try {
            $count = $this->dispose((string) $job['data_category'], (string) $policy['disposal_action'], (int) $policy['retention_days'], (string) $job['scope_type'], isset($job['scope_id']) ? (int) $job['scope_id'] : null);
            $done = $this->pdo->prepare("UPDATE recruitment_disposal_jobs SET execution_status = 'completed', executed_count = ?, completed_at = NOW(), backup_disposal_status = 'not_required' WHERE id = ?");
            $done->execute([$count, $id]);
        } catch (Throwable $error) {
            $failed = $this->pdo->prepare("UPDATE recruitment_disposal_jobs SET execution_status = 'failed', retry_count = retry_count + 1, failure_message = ? WHERE id = ?");
            $failed->execute([mb_substr($error->getMessage(), 0, 1000, 'UTF-8'), $id]);
            throw $error;
        }
        return $this->row('recruitment_disposal_jobs', $id);
    }

    private function dispose(string $category, string $action, int $days, string $scopeType, ?int $scopeId): int
    {
        [$applicationSql, $params] = $this->applicationScope($scopeType, $scopeId);
        $before = date('Y-m-d H:i:s', time() - $days * 86400);
        if ($category === 'structured_profile') {
            $stmt = $this->pdo->prepare("UPDATE recruitment_applications SET extracted_profile_json = NULL, highlights_json = NULL, contact_note = NULL WHERE created_at < ? AND id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return $stmt->rowCount();
        }
        if ($category === 'archive_record') {
            $stmt = $this->pdo->prepare("UPDATE recruitment_applications SET extracted_profile_json = NULL, highlights_json = NULL, contact_note = NULL WHERE queue_status = 'review_archive' AND created_at < ? AND id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return $stmt->rowCount();
        }
        if ($category === 'contact_log') {
            $stmt = $this->pdo->prepare("UPDATE recruitment_contact_logs SET contact_note = NULL WHERE created_at < ? AND application_id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return $stmt->rowCount();
        }
        if ($category === 'ai_result') {
            $stmt = $this->pdo->prepare("UPDATE recruitment_model_results model JOIN recruitment_applications application ON application.current_processing_version_id = model.processing_version_id SET model.model_output_json = '{\"disposed\":true}', model.evidence_summary_json = NULL WHERE model.created_at < ? AND application.id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return $stmt->rowCount();
        }
        if ($category === 'ocr_text') {
            $stmt = $this->pdo->prepare("UPDATE recruitment_extraction_results extraction JOIN recruitment_applications application ON application.current_processing_version_id = extraction.processing_version_id SET extraction.fields_json = '{\"disposed\":true}', extraction.confidence_json = NULL WHERE extraction.created_at < ? AND application.id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return $stmt->rowCount();
        }
        if ($category === 'export_file') {
            $where = $scopeType === 'export' ? 'id = ?' : 'created_at < ?';
            $values = $scopeType === 'export' ? [$scopeId] : [$before];
            if ($action === 'delete') {
                $files = $this->pdo->prepare("SELECT file_key FROM recruitment_export_jobs WHERE $where");
                $files->execute($values);
                foreach ($files->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileKey) {
                    $this->removeControlledFile((string) $fileKey, 'RECRUITMENT_EXPORT_STORAGE_ROOT', 'recruitment-exports');
                }
            }
            $stmt = $this->pdo->prepare("UPDATE recruitment_export_jobs SET status = 'expired', expires_at = LEAST(expires_at, NOW()) WHERE $where");
            $stmt->execute($values);
            return $stmt->rowCount();
        }
        if ($category === 'raw_resume') {
            $documentSql = "SELECT document_id FROM recruitment_applications WHERE id IN ($applicationSql)";
            if ($action === 'delete') {
                $files = $this->pdo->prepare("SELECT DISTINCT file.storage_key FROM recruitment_resume_files file JOIN recruitment_resume_document_pages page ON page.resume_file_id = file.id WHERE file.created_at < ? AND page.document_id IN ($documentSql)");
                $files->execute(array_merge([$before], $params));
                foreach ($files->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileKey) {
                    $this->removeControlledFile((string) $fileKey, 'RECRUITMENT_RESUME_STORAGE_ROOT', 'recruitment-resumes');
                }
            }
            $stmt = $this->pdo->prepare("UPDATE recruitment_resume_files file JOIN recruitment_resume_document_pages page ON page.resume_file_id = file.id SET file.status = 'skipped', file.failure_stage = 'retention', file.failure_code = 'disposed', file.failure_message = '依据留存策略完成逻辑处置' WHERE file.created_at < ? AND page.document_id IN ($documentSql)");
            $stmt->execute(array_merge([$before], $params));
            return $stmt->rowCount();
        }
        if ($category === 'audit_log') {
            $stmt = $this->pdo->prepare("UPDATE admin_operation_logs SET before_json = NULL, after_json = NULL, ip_address = NULL, user_agent = NULL WHERE module = 'recruitment' AND created_at < ?");
            $stmt->execute([$before]);
            return $stmt->rowCount();
        }
        throw new RecruitmentAdminException('处置数据类别无效');
    }

    private function eligibleCount(string $category, int $days, string $scopeType, ?int $scopeId): int
    {
        [$applicationSql, $params] = $this->applicationScope($scopeType, $scopeId);
        $before = date('Y-m-d H:i:s', time() - $days * 86400);
        $table = ['structured_profile' => 'recruitment_applications', 'archive_record' => 'recruitment_applications', 'contact_log' => 'recruitment_contact_logs', 'ai_result' => 'recruitment_model_results', 'ocr_text' => 'recruitment_extraction_results'][$category] ?? null;
        if ($table === 'recruitment_applications') {
            $archiveFilter = $category === 'archive_record' ? "queue_status = 'review_archive' AND " : '';
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_applications WHERE $archiveFilter created_at < ? AND id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return (int) $stmt->fetchColumn();
        }
        if ($table === 'recruitment_contact_logs') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_contact_logs WHERE created_at < ? AND application_id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return (int) $stmt->fetchColumn();
        }
        if ($table === 'recruitment_model_results') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_model_results model JOIN recruitment_applications application ON application.current_processing_version_id = model.processing_version_id WHERE model.created_at < ? AND application.id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return (int) $stmt->fetchColumn();
        }
        if ($table === 'recruitment_extraction_results') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_extraction_results extraction JOIN recruitment_applications application ON application.current_processing_version_id = extraction.processing_version_id WHERE extraction.created_at < ? AND application.id IN ($applicationSql)");
            $stmt->execute(array_merge([$before], $params));
            return (int) $stmt->fetchColumn();
        }
        if ($category === 'export_file') {
            $stmt = $this->pdo->prepare($scopeType === 'export' ? 'SELECT COUNT(*) FROM recruitment_export_jobs WHERE id = ?' : 'SELECT COUNT(*) FROM recruitment_export_jobs WHERE created_at < ?');
            $stmt->execute([$scopeType === 'export' ? $scopeId : $before]);
            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_applications WHERE created_at < ? AND id IN ($applicationSql)");
        $stmt->execute(array_merge([$before], $params));
        return (int) $stmt->fetchColumn();
    }

    private function applicationScope(string $scopeType, ?int $scopeId): array
    {
        return match ($scopeType) {
            'requirement' => ['SELECT id FROM recruitment_applications WHERE requirement_id = ?', [$scopeId]],
            'batch' => ['SELECT application.id FROM recruitment_applications application JOIN recruitment_resume_documents document ON document.id = application.document_id WHERE document.batch_id = ?', [$scopeId]],
            'candidate' => ['SELECT id FROM recruitment_applications WHERE candidate_id = ?', [$scopeId]],
            'application' => ['SELECT id FROM recruitment_applications WHERE id = ?', [$scopeId]],
            default => ['SELECT id FROM recruitment_applications', []],
        };
    }

    private function removeControlledFile(string $fileKey, string $environmentName, string $defaultDirectory): void
    {
        if ($fileKey === '') {
            return;
        }
        $configured = trim((string) (getenv($environmentName) ?: ''));
        $storageRoot = $configured !== '' ? rtrim($configured, DIRECTORY_SEPARATOR) : dirname(__DIR__, 5) . '/.private/' . $defaultDirectory;
        $root = realpath($storageRoot);
        $path = realpath($storageRoot . '/' . $fileKey);
        if ($root === false || $path === false) {
            return;
        }
        if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RecruitmentAdminException('待处置文件超出受控存储目录', 500);
        }
        if (!unlink($path)) {
            throw new RecruitmentAdminException('受控文件处置失败', 500);
        }
    }

    private function holdBlocks(string $scopeType, ?int $scopeId): bool
    {
        $holds = $this->pdo->query("SELECT scope_type, scope_id FROM recruitment_legal_holds WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($scopeType === 'all') {
            return $holds !== [];
        }
        $entities = $this->scopeEntities($scopeType, $scopeId);
        foreach ($holds as $hold) {
            $holdType = (string) $hold['scope_type'];
            if ($holdType === 'all') {
                return true;
            }
            $holdId = (int) ($hold['scope_id'] ?? 0);
            if ($holdId > 0 && in_array($holdId, $entities[$holdType] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    private function scopeEntities(string $scopeType, ?int $scopeId): array
    {
        $entities = ['requirement' => [], 'batch' => [], 'candidate' => [], 'application' => [], 'export' => []];
        if ($scopeId === null || $scopeId <= 0) {
            return $entities;
        }
        if ($scopeType === 'export') {
            $stmt = $this->pdo->prepare('SELECT id, requirement_id, batch_id FROM recruitment_export_jobs WHERE id = ? LIMIT 1');
            $stmt->execute([$scopeId]);
            $export = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $entities['export'][] = $scopeId;
            if ((int) ($export['requirement_id'] ?? 0) > 0) {
                $entities['requirement'][] = (int) $export['requirement_id'];
            }
            if ((int) ($export['batch_id'] ?? 0) > 0) {
                $entities['batch'][] = (int) $export['batch_id'];
            }
            return $entities;
        }
        [$applicationSql, $params] = $this->applicationScope($scopeType, $scopeId);
        $stmt = $this->pdo->prepare(
            "SELECT application.id AS application_id, application.candidate_id, application.requirement_id, document.batch_id FROM recruitment_applications application JOIN recruitment_resume_documents document ON document.id = application.document_id WHERE application.id IN ($applicationSql)"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $entities['application'][] = (int) $row['application_id'];
            $entities['candidate'][] = (int) $row['candidate_id'];
            $entities['requirement'][] = (int) $row['requirement_id'];
            $entities['batch'][] = (int) $row['batch_id'];
        }
        if (isset($entities[$scopeType])) {
            $entities[$scopeType][] = $scopeId;
        }
        foreach ($entities as $type => $ids) {
            $entities[$type] = array_values(array_unique(array_filter($ids)));
        }
        return $entities;
    }

    private function processor(int $id): array
    {
        return $this->row('recruitment_external_processors', $id);
    }

    private function row(string $table, int $id): array
    {
        $allowed = ['recruitment_external_processors', 'recruitment_retention_policies', 'recruitment_legal_holds', 'recruitment_disposal_jobs'];
        if ($id <= 0 || !in_array($table, $allowed, true)) {
            throw new RecruitmentAdminException('治理记录编号无效');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('治理记录不存在', 404);
        }
        return $row;
    }

    private function categories(): array
    {
        return ['raw_resume', 'ocr_text', 'structured_profile', 'archive_record', 'ai_result', 'contact_log', 'export_file', 'audit_log'];
    }

    private function scopeTypes(): array
    {
        return ['all', 'requirement', 'batch', 'candidate', 'application', 'export'];
    }
}
