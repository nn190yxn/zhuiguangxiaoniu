<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeFieldNormalizer.php';
require_once dirname(__DIR__) . '/platform/RecruitmentReminderProjection.php';

final class ResumeReviewService
{
    private PDO $pdo;
    private RecruitmentPermissionService $permissions;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissions)
    {
        $this->pdo = $pdo;
        $this->permissions = $permissions;
    }

    public function list(array $scope, array $query): array
    {
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $where = [$scopeSql];
        $filters = [
            'requirement_id' => ['application.requirement_id', 'int', []],
            'batch_id' => ['document.batch_id', 'int', []],
            'grade' => ['application.effective_grade', 'enum', ['A', 'B', 'C']],
            'queue_status' => ['application.queue_status', 'enum', ['appointment', 'review_archive']],
            'contact_status' => ['application.contact_status', 'enum', ['not_contacted', 'calling', 'no_answer', 'scheduled', 'rejected', 'invalid_phone']],
            'information_status' => ['application.information_status', 'enum', ['complete', 'needs_confirmation', 'missing_contact']],
            'duplicate_status' => ['candidate.duplicate_status', 'enum', ['unique', 'suspected', 'confirmed']],
            'document_status' => ['document.status', 'enum', ['draft', 'queued', 'processing', 'completed', 'failed', 'superseded']],
        ];
        foreach ($filters as $key => [$column, $type, $allowed]) {
            $value = trim((string) ($query[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($type === 'int') {
                $value = (int) $value;
                if ($value <= 0) {
                    continue;
                }
            } elseif (!in_array($value, $allowed, true)) {
                throw new RecruitmentAdminException('筛选条件无效：' . $key);
            }
            $where[] = $column . ' = ?';
            $params[] = $value;
        }
        $keyword = mb_substr(trim((string) ($query['keyword'] ?? '')), 0, 80, 'UTF-8');
        if ($keyword !== '') {
            $where[] = '(candidate.name LIKE ? OR requirement.requirement_no LIKE ? OR requirement.position_name_snapshot LIKE ? OR application.extracted_profile_json LIKE ?)';
            array_push($params, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
        }
        $conditionStatus = trim((string) ($query['condition_status'] ?? ''));
        if ($conditionStatus !== '') {
            if (!in_array($conditionStatus, ['matched', 'unmatched', 'unknown', 'manual_check'], true)) {
                throw new RecruitmentAdminException('硬性条件状态筛选无效');
            }
            $where[] = "EXISTS (SELECT 1 FROM recruitment_match_evidence condition_evidence WHERE condition_evidence.application_id = application.id AND condition_evidence.dimension_type = 'hard_condition' AND condition_evidence.match_status = ?)";
            $params[] = $conditionStatus;
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($query['page_size'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $from = ' FROM recruitment_applications application '
            . 'JOIN recruitment_candidates candidate ON candidate.id = application.candidate_id '
            . 'JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id '
            . 'JOIN recruitment_resume_documents document ON document.id = application.document_id '
            . 'JOIN recruitment_resume_batches batch ON batch.id = document.batch_id '
            . 'LEFT JOIN stores store ON store.id = requirement.store_id '
            . 'WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $sql = "SELECT application.id, application.candidate_id, application.document_id, application.requirement_id, application.system_grade, application.manual_grade, application.effective_grade, application.total_score, application.raw_score, application.information_status, application.contact_status, application.queue_status, application.hiring_status, application.state_version, application.contact_note, application.extracted_profile_json, application.highlights_json, application.updated_at, candidate.name, candidate.duplicate_status, requirement.requirement_no, requirement.position_name_snapshot, store.name AS store_name, batch.id AS batch_id, batch.batch_no, document.status AS document_status, (SELECT GROUP_CONCAT(rule_key ORDER BY id SEPARATOR '、') FROM recruitment_match_evidence keyword_evidence WHERE keyword_evidence.application_id = application.id AND keyword_evidence.dimension_type = 'keyword' AND keyword_evidence.match_status = 'matched') AS matched_keywords, (SELECT GROUP_CONCAT(CONCAT(rule_key, ':', match_status) ORDER BY id SEPARATOR '；') FROM recruitment_match_evidence hard_evidence WHERE hard_evidence.application_id = application.id AND hard_evidence.dimension_type = 'hard_condition') AS hard_condition_status "
            . $from . " ORDER BY CASE application.effective_grade WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 ELSE 4 END, application.total_score DESC, application.id ASC LIMIT " . $pageSize . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map([$this, 'publicRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return ['items' => $items, 'total' => (int) $count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize];
    }

    public function detail(int $applicationId, array $scope): array
    {
        $application = $this->accessibleApplication($applicationId, $scope);
        $queries = [
            'evidence' => 'SELECT dimension_type, rule_key, match_status, score, source_text, page_no, confidence FROM recruitment_match_evidence WHERE application_id = ? ORDER BY dimension_type, id',
            'grade_reviews' => 'SELECT system_grade, manual_grade, review_reason, reviewer_staff_id, reviewed_at FROM recruitment_grade_reviews WHERE application_id = ? ORDER BY reviewed_at DESC, id DESC',
            'contact_logs' => 'SELECT contact_status, scheduled_at, contact_note, operator_staff_id, contacted_at FROM recruitment_contact_logs WHERE application_id = ? ORDER BY contacted_at DESC, id DESC',
            'queue_events' => 'SELECT event_type, before_status, after_status, event_reason, operator_staff_id, operated_at FROM recruitment_queue_events WHERE application_id = ? ORDER BY operated_at DESC, id DESC',
            'processing_versions' => 'SELECT id, parser_version, ocr_version, model_provider, model_name, prompt_version, evidence_validator_version, scoring_version, content_hash, status, created_at FROM recruitment_processing_versions WHERE document_id = ? ORDER BY id DESC',
        ];
        $result = $this->publicRow($application);
        foreach ($queries as $key => $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$key === 'processing_versions' ? (int) $application['document_id'] : $applicationId]);
            $result[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $relation = $this->pdo->prepare(
            "SELECT relation.id, relation.relation_type, relation.canonical_candidate_id, canonical.name AS canonical_name, relation.related_candidate_id, related.name AS related_name, relation.reason, relation.operated_at FROM recruitment_candidate_relations relation JOIN recruitment_candidates canonical ON canonical.id = relation.canonical_candidate_id JOIN recruitment_candidates related ON related.id = relation.related_candidate_id WHERE (relation.canonical_candidate_id = ? OR relation.related_candidate_id = ?) ORDER BY relation.id DESC"
        );
        $relation->execute([(int) $application['candidate_id'], (int) $application['candidate_id']]);
        $result['duplicate_relations'] = $relation->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $result;
    }

    public function revealPhone(int $applicationId, array $scope): array
    {
        $application = $this->accessibleApplication($applicationId, $scope);
        $stmt = $this->pdo->prepare('SELECT phone_ciphertext, phone_display_ciphertext, phone_key_version FROM recruitment_candidates WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $application['candidate_id']]);
        $candidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $normalizer = new ResumeFieldNormalizer();
        return [
            'application_id' => $applicationId,
            'candidate_id' => (int) $application['candidate_id'],
            'phone' => $normalizer->decrypt($candidate['phone_ciphertext'] ?? null),
            'masked_phone' => $normalizer->decrypt($candidate['phone_display_ciphertext'] ?? null),
            'key_version' => $candidate['phone_key_version'] ?? null,
        ];
    }

    public function updatePhone(int $applicationId, string $phone, array $scope): array
    {
        $normalizer = new ResumeFieldNormalizer();
        $protected = $normalizer->protectPhone($phone);
        if ($protected['normalized'] === '') {
            throw new RecruitmentAdminException('请输入有效的 11 位手机号');
        }
        $application = $this->accessibleApplication($applicationId, $scope, true);
        $profile = json_decode((string) ($application['extracted_profile_json'] ?? ''), true);
        $profile = is_array($profile) ? $profile : [];
        $profile['phone'] = [
            'value' => $protected['masked'],
            'confidence' => 1,
            'evidence' => [['page_no' => 0, 'text' => '人工补充电话']],
            'status' => 'verified',
            'protected' => $protected,
        ];
        unset($profile['phone']['protected']['normalized']);
        $this->pdo->beginTransaction();
        try {
            $candidate = $this->pdo->prepare(
                'UPDATE recruitment_candidates SET phone_ciphertext = ?, phone_display_ciphertext = ?, phone_lookup_hash = ?, phone_confidence = ?, phone_key_version = ? WHERE id = ?'
            );
            $candidate->execute([
                $protected['ciphertext'], $protected['display_ciphertext'], $protected['lookup_hash'], 1,
                $protected['key_version'], (int) $application['candidate_id'],
            ]);
            $update = $this->pdo->prepare(
                "UPDATE recruitment_applications SET extracted_profile_json = ?, information_status = CASE WHEN information_status = 'missing_contact' THEN 'complete' ELSE information_status END WHERE id = ?"
            );
            $update->execute([
                json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $applicationId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->publicRow($this->accessibleApplication($applicationId, $scope));
    }

    public function updateName(int $applicationId, string $name, array $scope): array
    {
        $name = mb_substr(trim($name), 0, 120, 'UTF-8');
        if ($name === '') {
            throw new RecruitmentAdminException('请输入候选人姓名');
        }
        $application = $this->accessibleApplication($applicationId, $scope, true);
        $profile = json_decode((string) ($application['extracted_profile_json'] ?? ''), true);
        $profile = is_array($profile) ? $profile : [];
        $profile['name'] = [
            'value' => $name,
            'confidence' => 1,
            'evidence' => [['page_no' => 0, 'text' => '人工补充姓名']],
            'status' => 'verified',
        ];
        $this->pdo->beginTransaction();
        try {
            $candidate = $this->pdo->prepare('UPDATE recruitment_candidates SET name = ?, name_confidence = ? WHERE id = ?');
            $candidate->execute([$name, 1, (int) $application['candidate_id']]);
            $update = $this->pdo->prepare('UPDATE recruitment_applications SET extracted_profile_json = ? WHERE id = ?');
            $update->execute([json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $applicationId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->publicRow($this->accessibleApplication($applicationId, $scope));
    }

    public function reviewGrade(int $applicationId, string $manualGrade, string $reason, array $scope, int $staffId): array
    {
        $application = $this->accessibleApplication($applicationId, $scope);
        $manualGrade = strtoupper(trim($manualGrade));
        $reason = mb_substr(trim($reason), 0, 1000, 'UTF-8');
        if (!in_array($manualGrade, ['A', 'B', 'C'], true) || $reason === '') {
            throw new RecruitmentAdminException('人工等级和调级原因必须完整填写');
        }
        $queue = $manualGrade === 'C' ? 'review_archive' : 'appointment';
        $this->pdo->beginTransaction();
        try {
            $review = $this->pdo->prepare('INSERT INTO recruitment_grade_reviews (application_id, system_grade, manual_grade, review_reason, reviewer_staff_id) VALUES (?, ?, ?, ?, ?)');
            $review->execute([$applicationId, $application['system_grade'], $manualGrade, $reason, $staffId ?: null]);
            $update = $this->pdo->prepare("UPDATE recruitment_applications SET manual_grade = ?, effective_grade = ?, queue_status = ?, archive_reason = CASE WHEN ? = 'C' THEN 'grade_c' ELSE NULL END, archived_by = CASE WHEN ? = 'C' THEN ? ELSE NULL END, archived_at = CASE WHEN ? = 'C' THEN NOW() ELSE NULL END, restored_by = CASE WHEN ? <> 'C' THEN ? ELSE restored_by END, restored_at = CASE WHEN ? <> 'C' THEN NOW() ELSE restored_at END WHERE id = ?");
            $update->execute([$manualGrade, $manualGrade, $queue, $manualGrade, $manualGrade, $staffId ?: null, $manualGrade, $manualGrade, $staffId ?: null, $manualGrade, $applicationId]);
            $this->recordQueueEvent($applicationId, $manualGrade === 'C' ? 'archive' : 'appointment', (string) $application['queue_status'], $queue, $reason, $staffId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->publicRow($this->accessibleApplication($applicationId, $scope));
    }

    public function updateContact(int $applicationId, string $status, string $note, ?string $scheduledAt, array $scope, int $staffId, string $idempotencyKey = '', ?int $expectedVersion = null): array
    {
        $allowed = ['calling', 'no_answer', 'scheduled', 'rejected', 'invalid_phone', 'note'];
        if (!in_array($status, $allowed, true)) {
            throw new RecruitmentAdminException('联系状态无效');
        }
        $note = mb_substr(trim($note), 0, 1000, 'UTF-8');
        if ($status === 'note' && $note === '') {
            throw new RecruitmentAdminException('联系备注不能为空');
        }
        $scheduledAt = $scheduledAt !== null && trim($scheduledAt) !== '' ? date('Y-m-d H:i:s', strtotime($scheduledAt) ?: 0) : null;
        if ($status === 'scheduled' && ($scheduledAt === null || $scheduledAt === '1970-01-01 00:00:00')) {
            throw new RecruitmentAdminException('预约状态必须填写有效时间');
        }
        $effectiveStatus = $status === 'note' ? null : $status;
        $this->pdo->beginTransaction();
        try {
            $application = $this->accessibleApplication($applicationId, $scope, true);
            PlatformStateVersion::assertExpected((int) $application['state_version'], $expectedVersion, ['application_id' => $applicationId]);
            $nextVersion = PlatformStateVersion::next((int) $application['state_version']);
            $log = $this->pdo->prepare('INSERT INTO recruitment_contact_logs (application_id, contact_status, scheduled_at, contact_note, operator_staff_id) VALUES (?, ?, ?, ?, ?)');
            $log->execute([$applicationId, $status, $scheduledAt, $note !== '' ? $note : null, $staffId ?: null]);
            $contactLogId = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare('UPDATE recruitment_applications SET contact_status = COALESCE(?, contact_status), contact_note = COALESCE(NULLIF(?, \'\'), contact_note), state_version = ? WHERE id = ? AND state_version = ?');
            $update->execute([$effectiveStatus, $note, $nextVersion, $applicationId, (int) $application['state_version']]);
            if ($update->rowCount() !== 1) {
                throw new RecruitmentAdminException('候选人状态已变化', 409);
            }
            (new RecruitmentReminderProjection($this->pdo))->contactChanged(
                $applicationId,
                $effectiveStatus ?? (string) $application['contact_status'],
                $scheduledAt,
                $idempotencyKey !== '' ? $idempotencyKey : 'contact-log:' . $contactLogId
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->publicRow($this->accessibleApplication($applicationId, $scope));
    }

    public function updateQueue(int $applicationId, string $action, string $reason, array $scope, int $staffId): array
    {
        $application = $this->accessibleApplication($applicationId, $scope);
        $reason = mb_substr(trim($reason), 0, 1000, 'UTF-8');
        $map = [
            'archive' => ['review_archive', 'archive', 'manual_removed'],
            'remove' => ['review_archive', 'manual_removed', 'manual_removed'],
            'restore' => ['appointment', 'restore', null],
            'appointment' => ['appointment', 'appointment', null],
        ];
        if (!isset($map[$action]) || $reason === '') {
            throw new RecruitmentAdminException('队列操作和原因必须完整填写');
        }
        [$after, $event, $archiveReason] = $map[$action];
        if ($after === 'appointment' && (string) $application['effective_grade'] === 'C') {
            throw new RecruitmentAdminException('C 级候选人需先完成等级复核后进入预约队列', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE recruitment_applications SET queue_status = ?, archive_reason = ?, archived_by = ?, archived_at = ?, restored_by = ?, restored_at = ? WHERE id = ?');
            $stmt->execute([
                $after,
                $archiveReason,
                $after === 'review_archive' ? ($staffId ?: null) : null,
                $after === 'review_archive' ? date('Y-m-d H:i:s') : null,
                $after === 'appointment' ? ($staffId ?: null) : null,
                $after === 'appointment' ? date('Y-m-d H:i:s') : null,
                $applicationId,
            ]);
            $this->recordQueueEvent($applicationId, $event, (string) $application['queue_status'], $after, $reason, $staffId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->publicRow($this->accessibleApplication($applicationId, $scope));
    }

    public function resolveDuplicate(int $canonicalId, int $relatedId, string $action, string $reason, array $scope, int $staffId): array
    {
        if ($canonicalId <= 0 || $relatedId <= 0 || $canonicalId === $relatedId || !in_array($action, ['confirm', 'release'], true)) {
            throw new RecruitmentAdminException('重复候选人处理参数无效');
        }
        $this->assertCandidateAccessible($canonicalId, $scope);
        $this->assertCandidateAccessible($relatedId, $scope);
        $reason = mb_substr(trim($reason), 0, 1000, 'UTF-8');
        if ($reason === '') {
            throw new RecruitmentAdminException('处理原因不能为空');
        }
        $snapshotStmt = $this->pdo->prepare('SELECT id, name, duplicate_status, record_status, canonical_candidate_id FROM recruitment_candidates WHERE id IN (?, ?) ORDER BY id');
        $snapshotStmt->execute([$canonicalId, $relatedId]);
        $snapshot = $snapshotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $relationType = $action === 'confirm' ? 'confirmed_duplicate' : 'released';
        $this->pdo->beginTransaction();
        try {
            $relation = $this->pdo->prepare('INSERT IGNORE INTO recruitment_candidate_relations (relation_type, canonical_candidate_id, related_candidate_id, before_snapshot_json, reason, operator_staff_id) VALUES (?, ?, ?, ?, ?, ?)');
            $relation->execute([$relationType, $canonicalId, $relatedId, json_encode($snapshot, JSON_UNESCAPED_UNICODE), $reason, $staffId ?: null]);
            if ($action === 'confirm') {
                $canonical = $this->pdo->prepare("UPDATE recruitment_candidates SET duplicate_status = 'confirmed' WHERE id = ?");
                $canonical->execute([$canonicalId]);
                $related = $this->pdo->prepare("UPDATE recruitment_candidates SET duplicate_status = 'confirmed', record_status = 'merged', canonical_candidate_id = ? WHERE id = ?");
                $related->execute([$canonicalId, $relatedId]);
                $applications = $this->pdo->prepare('UPDATE recruitment_applications SET candidate_id = ? WHERE candidate_id = ?');
                $applications->execute([$canonicalId, $relatedId]);
            } else {
                $related = $this->pdo->prepare("UPDATE recruitment_candidates SET duplicate_status = 'unique', record_status = 'active', canonical_candidate_id = NULL WHERE id = ?");
                $related->execute([$relatedId]);
                $remaining = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_candidates WHERE canonical_candidate_id = ? AND record_status = 'merged'");
                $remaining->execute([$canonicalId]);
                $canonical = $this->pdo->prepare('UPDATE recruitment_candidates SET duplicate_status = ? WHERE id = ?');
                $canonical->execute([(int) $remaining->fetchColumn() > 0 ? 'confirmed' : 'unique', $canonicalId]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['canonical_candidate_id' => $canonicalId, 'related_candidate_id' => $relatedId, 'action' => $action, 'relation_type' => $relationType];
    }

    public function accessibleApplication(int $applicationId, array $scope, bool $lock = false): array
    {
        if ($applicationId <= 0) {
            throw new RecruitmentAdminException('候选人投递编号无效');
        }
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare(
            'SELECT application.*, candidate.name, candidate.duplicate_status, requirement.requirement_no, requirement.position_name_snapshot, store.name AS store_name, batch.id AS batch_id, batch.batch_no, document.status AS document_status '
            . 'FROM recruitment_applications application JOIN recruitment_candidates candidate ON candidate.id = application.candidate_id JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id JOIN recruitment_resume_documents document ON document.id = application.document_id JOIN recruitment_resume_batches batch ON batch.id = document.batch_id LEFT JOIN stores store ON store.id = requirement.store_id '
            . 'WHERE application.id = ? AND ' . $scopeSql . ' LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(array_merge([$applicationId], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('候选人投递不存在或超出数据范围', 404);
        }
        return $row;
    }

    private function assertCandidateAccessible(int $candidateId, array $scope): void
    {
        [$scopeSql, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare('SELECT 1 FROM recruitment_applications application JOIN recruitment_requirements requirement ON requirement.id = application.requirement_id WHERE application.candidate_id = ? AND ' . $scopeSql . ' LIMIT 1');
        $stmt->execute(array_merge([$candidateId], $params));
        if (!$stmt->fetchColumn()) {
            throw new RecruitmentAdminException('候选人不存在或超出数据范围', 404);
        }
    }

    private function recordQueueEvent(int $applicationId, string $event, string $before, string $after, string $reason, int $staffId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO recruitment_queue_events (application_id, event_type, before_status, after_status, event_reason, operator_staff_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$applicationId, $event, $before, $after, $reason, $staffId ?: null]);
    }

    private function publicRow(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        foreach (['candidate_id', 'document_id', 'requirement_id', 'batch_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (isset($row['state_version'])) {
            $row['state_version'] = (int) $row['state_version'];
        }
        foreach (['total_score', 'raw_score'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (float) $row[$key];
            }
        }
        $profile = json_decode((string) ($row['extracted_profile_json'] ?? ''), true);
        $row['profile'] = is_array($profile) ? $profile : [];
        $highlights = json_decode((string) ($row['highlights_json'] ?? ''), true);
        $row['highlights'] = is_array($highlights) ? $highlights : [];
        foreach (['phone', 'email'] as $field) {
            if (isset($row['profile'][$field]['protected'])) {
                unset($row['profile'][$field]['protected']);
            }
        }
        unset($row['extracted_profile_json']);
        unset($row['highlights_json']);
        return $row;
    }
}
