<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadRoleRuleVersionService.php';

final class WorkloadAuditTaskException extends RuntimeException {
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int {
        return $this->statusCode;
    }
}

final class WorkloadAuditTaskService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function replaceForSubmission(
        int $reportId,
        int $staffId,
        int $storeId,
        string $roleCode,
        array $values,
        array $metricRules,
        ?int $operatorStaffId = null,
        string $supersedeComment = '日报重新提交，审核任务已由新版本替代'
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new WorkloadAuditTaskException('审核任务必须在日报事务中创建', 500);
        }
        if ($reportId <= 0 || $staffId <= 0 || $storeId <= 0 || trim($roleCode) === '') {
            throw new WorkloadAuditTaskException('审核任务身份信息无效');
        }
        $operatorStaffId = $operatorStaffId ?? $staffId;
        if ($operatorStaffId <= 0) {
            throw new WorkloadAuditTaskException('审核任务操作人无效');
        }
        $supersedeComment = mb_substr(trim($supersedeComment), 0, 255);
        if ($supersedeComment === '') {
            throw new WorkloadAuditTaskException('审核任务替换原因不能为空');
        }

        $desiredValues = [];
        foreach ($metricRules as $metricCode => $rule) {
            if (($rule['audit_mode'] ?? '') !== 'full' || !array_key_exists($metricCode, $values)) {
                continue;
            }
            $value = (float) $values[$metricCode];
            if ($value > 0) {
                $desiredValues[(string) $metricCode] = $value;
            }
        }

        $select = $this->pdo->prepare(
            'SELECT id, metric_code, task_version, audit_status, superseded_at '
            . 'FROM workload_audit_tasks WHERE report_id = ? '
            . 'ORDER BY metric_code, task_version, id FOR UPDATE'
        );
        $select->execute([$reportId]);
        $historyByMetric = [];
        $currentTasks = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $task) {
            $metricCode = (string) $task['metric_code'];
            $historyByMetric[$metricCode][] = $task;
            if ($task['superseded_at'] === null && (string) $task['audit_status'] !== 'superseded') {
                $currentTasks[] = $task;
            }
        }

        $supersede = $this->pdo->prepare(
            "UPDATE workload_audit_tasks SET audit_status = 'superseded', superseded_at = NOW(), updated_at = NOW() "
            . "WHERE id = ? AND superseded_at IS NULL AND audit_status <> 'superseded'"
        );
        $log = $this->pdo->prepare(
            'INSERT INTO workload_audit_logs '
            . '(task_id, operator_staff_id, before_status, after_status, comment) VALUES (?, ?, ?, ?, ?)'
        );
        $supersededIds = [];
        foreach ($currentTasks as $task) {
            $supersede->execute([(int) $task['id']]);
            if ($supersede->rowCount() !== 1) {
                throw new WorkloadAuditTaskException('审核任务状态已发生变化，请重试', 409);
            }
            $supersededIds[] = (int) $task['id'];
            $log->execute([
                (int) $task['id'],
                $operatorStaffId,
                (string) $task['audit_status'],
                'superseded',
                $supersedeComment,
            ]);
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO workload_audit_tasks "
            . "(report_id, staff_id, store_id, role_code, metric_code, task_version, previous_task_id, submitted_value, audit_status) "
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $createdTasks = [];
        foreach ($desiredValues as $metricCode => $submittedValue) {
            $history = $historyByMetric[$metricCode] ?? [];
            $latest = $history !== [] ? $history[count($history) - 1] : null;
            $taskVersion = $latest ? ((int) $latest['task_version'] + 1) : 1;
            $previousTaskId = $latest ? (int) $latest['id'] : null;
            $insert->execute([
                $reportId,
                $staffId,
                $storeId,
                $roleCode,
                $metricCode,
                $taskVersion,
                $previousTaskId,
                $submittedValue,
            ]);
            $createdTasks[] = [
                'id' => (int) $this->pdo->lastInsertId(),
                'metric_code' => $metricCode,
                'task_version' => $taskVersion,
                'previous_task_id' => $previousTaskId,
                'audit_status' => 'pending',
            ];
        }

        return ['superseded_task_ids' => $supersededIds, 'created_tasks' => $createdTasks];
    }

    public function transition(int $taskId, string $afterStatus, int $operatorStaffId, string $comment = ''): array {
        if ($taskId <= 0 || $operatorStaffId <= 0) {
            throw new WorkloadAuditTaskException('审核任务或操作人无效');
        }
        if (!in_array($afterStatus, ['approved', 'rejected', 'needs_resubmit'], true)) {
            throw new WorkloadAuditTaskException('审核状态无效');
        }
        $comment = mb_substr(trim($comment), 0, 255);
        if (in_array($afterStatus, ['rejected', 'needs_resubmit'], true) && $comment === '') {
            throw new WorkloadAuditTaskException('驳回或要求补凭证时必须填写审核意见');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $select = $this->pdo->prepare(
                'SELECT task.*, report.report_date AS business_date FROM workload_audit_tasks task '
                . 'JOIN workload_daily_reports report ON report.id = task.report_id WHERE task.id = ? FOR UPDATE'
            );
            $select->execute([$taskId]);
            $task = $select->fetch(PDO::FETCH_ASSOC);
            if (!$task) {
                throw new WorkloadAuditTaskException('审核任务不存在', 404);
            }
            if ($task['superseded_at'] !== null || (string) $task['audit_status'] === 'superseded') {
                throw new WorkloadAuditTaskException('历史审核任务只读', 409);
            }
            $beforeStatus = (string) $task['audit_status'];
            if ($beforeStatus === $afterStatus) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return ['task_id' => $taskId, 'before_status' => $beforeStatus, 'after_status' => $afterStatus, 'idempotent' => true];
            }
            if ($beforeStatus !== 'pending') {
                throw new WorkloadAuditTaskException('只有待审核任务可以执行审核操作', 409);
            }

            $evidenceCountAtReview = null;
            if ($afterStatus === 'needs_resubmit') {
                $evidenceCountStmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM workload_evidences evidence '
                    . 'JOIN workload_daily_reports evidence_report ON evidence_report.id = evidence.report_id '
                    . 'WHERE evidence.deleted_at IS NULL AND ('
                    . '(evidence.report_id = ? AND evidence.metric_code = ?) OR '
                    . '(? = ? AND ? = ? AND evidence_report.report_date = ? AND evidence_report.store_id = ? '
                    . "AND evidence_report.role_code IN ('coach', 'sales') "
                    . "AND evidence.metric_code IN ('coach_store_poi_checkin', 'sales_store_poi_checkin'))"
                    . ')'
                );
                $evidenceCountStmt->execute([
                    (int) $task['report_id'],
                    (string) $task['metric_code'],
                    (string) $task['role_code'],
                    'manager',
                    (string) $task['metric_code'],
                    'manager_store_poi_checkin',
                    (string) $task['business_date'],
                    (int) $task['store_id'],
                ]);
                $evidenceCountAtReview = (int) $evidenceCountStmt->fetchColumn();
            }

            $update = $this->pdo->prepare(
                'UPDATE workload_audit_tasks SET audit_status = ?, auditor_staff_id = ?, audit_comment = ?, '
                . 'audited_at = NOW(), evidence_count_at_review = ? '
                . "WHERE id = ? AND superseded_at IS NULL AND audit_status = 'pending'"
            );
            $update->execute([$afterStatus, $operatorStaffId, $comment, $evidenceCountAtReview, $taskId]);
            if ($update->rowCount() !== 1) {
                throw new WorkloadAuditTaskException('审核任务状态已发生变化，请重试', 409);
            }
            $log = $this->pdo->prepare(
                'INSERT INTO workload_audit_logs '
                . '(task_id, operator_staff_id, before_status, after_status, comment) VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([$taskId, $operatorStaffId, $beforeStatus, $afterStatus, $comment]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return [
                'task_id' => $taskId,
                'report_id' => (int) $task['report_id'],
                'business_date' => (string) $task['business_date'],
                'store_id' => (int) $task['store_id'],
                'staff_id' => (int) $task['staff_id'],
                'role_code' => (string) $task['role_code'],
                'metric_code' => (string) $task['metric_code'],
                'before_status' => $beforeStatus,
                'after_status' => $afterStatus,
                'idempotent' => false,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function assertEvidenceUploadAllowed(int $reportId, string $metricCode, int $staffId): array {
        if (!$this->pdo->inTransaction()) {
            throw new WorkloadAuditTaskException('凭证上传授权必须在写入事务中执行', 500);
        }
        if ($reportId <= 0 || $staffId <= 0 || trim($metricCode) === '') {
            throw new WorkloadAuditTaskException('凭证上传范围无效');
        }

        $reportStmt = $this->pdo->prepare(
            'SELECT id, staff_id, submit_status FROM workload_daily_reports WHERE id = ? FOR UPDATE'
        );
        $reportStmt->execute([$reportId]);
        $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            throw new WorkloadAuditTaskException('日报不存在', 404);
        }
        if ((int) $report['staff_id'] !== $staffId) {
            throw new WorkloadAuditTaskException('无权操作该日报', 403);
        }
        if ((string) $report['submit_status'] !== 'submitted') {
            return ['report_id' => $reportId, 'task_id' => null, 'audit_status' => null];
        }

        $taskStmt = $this->pdo->prepare(
            "SELECT id, audit_status FROM workload_audit_tasks WHERE report_id = ? AND metric_code = ? "
            . "AND superseded_at IS NULL AND audit_status <> 'superseded' ORDER BY task_version DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        $taskStmt->execute([$reportId, $metricCode]);
        $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
        if (!$task || (string) $task['audit_status'] !== 'needs_resubmit') {
            throw new WorkloadAuditTaskException('已提交日报仅可为待补凭证任务上传图片', 409);
        }
        return [
            'report_id' => $reportId,
            'task_id' => (int) $task['id'],
            'audit_status' => (string) $task['audit_status'],
        ];
    }

    public function requestReaudit(int $taskId, int $staffId): array {
        if ($taskId <= 0 || $staffId <= 0) {
            throw new WorkloadAuditTaskException('审核任务或员工身份无效');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $select = $this->pdo->prepare('SELECT * FROM workload_audit_tasks WHERE id = ? FOR UPDATE');
            $select->execute([$taskId]);
            $task = $select->fetch(PDO::FETCH_ASSOC);
            if (!$task) {
                throw new WorkloadAuditTaskException('审核任务不存在', 404);
            }
            if ((int) $task['staff_id'] !== $staffId) {
                throw new WorkloadAuditTaskException('无权重新提交该审核任务', 403);
            }
            if ($task['superseded_at'] !== null || (string) $task['audit_status'] === 'superseded') {
                $replacement = $this->currentReplacement((int) $task['id'], $staffId);
                if ($replacement) {
                    if ($ownsTransaction) {
                        $this->pdo->commit();
                    }
                    return $this->reauditResult($task, $replacement, true);
                }
                throw new WorkloadAuditTaskException('历史审核任务只读', 409);
            }
            if ((string) $task['audit_status'] !== 'needs_resubmit') {
                throw new WorkloadAuditTaskException('只有待补凭证任务可以重新送审', 409);
            }

            $reportStmt = $this->pdo->prepare(
                "SELECT id, staff_id, submit_status FROM workload_daily_reports WHERE id = ? FOR UPDATE"
            );
            $reportStmt->execute([(int) $task['report_id']]);
            $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
            if (!$report || (int) $report['staff_id'] !== $staffId || (string) $report['submit_status'] !== 'submitted') {
                throw new WorkloadAuditTaskException('审核任务关联的日报状态无效', 409);
            }

            $version = (new WorkloadRoleRuleVersionService($this->pdo))->forReport((int) $task['report_id']);
            $metricCode = (string) $task['metric_code'];
            $rule = $version['metric_rules'][$metricCode] ?? null;
            if (!$rule || !$rule['need_evidence'] || (string) $rule['audit_mode'] !== 'full') {
                throw new WorkloadAuditTaskException('当前指标不支持补凭证重审', 409);
            }

            $evidenceStmt = $this->pdo->prepare(
                'SELECT id, created_at FROM workload_evidences WHERE report_id = ? AND metric_code = ? '
                . 'AND deleted_at IS NULL ORDER BY id FOR UPDATE'
            );
            $evidenceStmt->execute([(int) $task['report_id'], $metricCode]);
            $evidences = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $evidenceCount = count($evidences);
            if ($evidenceCount < (int) $rule['min_evidence_count']) {
                throw new WorkloadAuditTaskException(
                    sprintf('%s 至少需要上传 %d 张凭证图片', $rule['metric_name'], (int) $rule['min_evidence_count'])
                );
            }
            $evidenceBaseline = $task['evidence_count_at_review'] ?? null;
            if ($evidenceBaseline !== null) {
                $hasSupplement = $evidenceCount > (int) $evidenceBaseline;
            } else {
                $auditedAt = (string) ($task['audited_at'] ?? '');
                $hasSupplement = $auditedAt !== '' && array_filter(
                    $evidences,
                    static fn(array $evidence): bool => (string) ($evidence['created_at'] ?? '') >= $auditedAt
                ) !== [];
            }
            if (!$hasSupplement) {
                throw new WorkloadAuditTaskException('请先补充新的凭证图片再重新送审', 409);
            }

            $supersede = $this->pdo->prepare(
                "UPDATE workload_audit_tasks SET audit_status = 'superseded', superseded_at = NOW(), updated_at = NOW() "
                . "WHERE id = ? AND superseded_at IS NULL AND audit_status = 'needs_resubmit'"
            );
            $supersede->execute([$taskId]);
            if ($supersede->rowCount() !== 1) {
                throw new WorkloadAuditTaskException('审核任务状态已发生变化，请重试', 409);
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO workload_audit_tasks "
                . "(report_id, staff_id, store_id, role_code, metric_code, task_version, previous_task_id, submitted_value, audit_status) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $insert->execute([
                (int) $task['report_id'],
                $staffId,
                (int) $task['store_id'],
                (string) $task['role_code'],
                $metricCode,
                (int) $task['task_version'] + 1,
                $taskId,
                (float) $task['submitted_value'],
            ]);
            $replacement = [
                'id' => (int) $this->pdo->lastInsertId(),
                'task_version' => (int) $task['task_version'] + 1,
                'audit_status' => 'pending',
            ];

            $log = $this->pdo->prepare(
                'INSERT INTO workload_audit_logs '
                . '(task_id, operator_staff_id, before_status, after_status, comment) VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([
                $taskId,
                $staffId,
                'needs_resubmit',
                'superseded',
                '员工补充凭证后重新送审',
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $this->reauditResult($task, $replacement, false, $evidenceCount);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function employeeReviewState(int $reportId, int $staffId): array {
        if ($reportId <= 0 || $staffId <= 0) {
            return ['tasks' => [], 'pending_items' => [], 'needs_resubmit_count' => 0];
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.metric_code, t.task_version, t.previous_task_id, t.submitted_value, '
            . 't.audit_status, t.audit_comment, t.audited_at, t.evidence_count_at_review, t.created_at, '
            . '(SELECT COUNT(*) FROM workload_evidences e WHERE e.report_id = t.report_id '
            . 'AND e.metric_code = t.metric_code AND e.deleted_at IS NULL) AS evidence_count, '
            . '(SELECT MAX(e.created_at) FROM workload_evidences e WHERE e.report_id = t.report_id '
            . 'AND e.metric_code = t.metric_code AND e.deleted_at IS NULL) AS latest_evidence_at '
            . 'FROM workload_audit_tasks t WHERE t.report_id = ? AND t.staff_id = ? '
            . "AND t.superseded_at IS NULL AND t.audit_status <> 'superseded' ORDER BY t.metric_code, t.id"
        );
        $stmt->execute([$reportId, $staffId]);
        $tasks = [];
        $pendingItems = [];
        $needsResubmitCount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $task) {
            $status = (string) $task['audit_status'];
            $supplemented = false;
            if ($status === 'needs_resubmit') {
                $supplemented = $task['evidence_count_at_review'] !== null
                    ? (int) $task['evidence_count'] > (int) $task['evidence_count_at_review']
                    : (
                        (string) ($task['latest_evidence_at'] ?? '') !== ''
                        && (string) ($task['latest_evidence_at'] ?? '') >= (string) ($task['audited_at'] ?? '')
                    );
            }
            $requiredAction = 'none';
            if ($status === 'pending') {
                $requiredAction = 'await_review';
            } elseif ($status === 'rejected') {
                $requiredAction = 'review_rejection';
            } elseif ($status === 'needs_resubmit') {
                $requiredAction = $supplemented ? 'request_reaudit' : 'supplement_evidence';
            }
            if ($status === 'needs_resubmit') {
                $needsResubmitCount++;
                $pendingItems[] = $supplemented
                    ? '重新送审指标：' . (string) $task['metric_code']
                    : '补充指标凭证：' . (string) $task['metric_code'];
            } elseif ($status === 'rejected') {
                $pendingItems[] = '查看指标驳回意见：' . (string) $task['metric_code'];
            }
            $tasks[] = [
                'task_id' => (int) $task['id'],
                'metric_code' => (string) $task['metric_code'],
                'task_version' => (int) $task['task_version'],
                'previous_task_id' => $task['previous_task_id'] !== null ? (int) $task['previous_task_id'] : null,
                'submitted_value' => (float) $task['submitted_value'],
                'audit_status' => $status,
                'audit_comment' => (string) ($task['audit_comment'] ?? ''),
                'audited_at' => $task['audited_at'],
                'evidence_count' => (int) $task['evidence_count'],
                'evidence_count_at_review' => $task['evidence_count_at_review'] !== null
                    ? (int) $task['evidence_count_at_review']
                    : null,
                'latest_evidence_at' => $task['latest_evidence_at'],
                'supplemented_after_review' => $supplemented,
                'required_action' => $requiredAction,
            ];
        }
        return [
            'tasks' => $tasks,
            'pending_items' => $pendingItems,
            'needs_resubmit_count' => $needsResubmitCount,
        ];
    }

    private function currentReplacement(int $previousTaskId, int $staffId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, task_version, audit_status FROM workload_audit_tasks '
            . "WHERE previous_task_id = ? AND staff_id = ? AND superseded_at IS NULL AND audit_status <> 'superseded' "
            . 'ORDER BY task_version DESC, id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$previousTaskId, $staffId]);
        $replacement = $stmt->fetch(PDO::FETCH_ASSOC);
        return $replacement ?: null;
    }

    private function reauditResult(array $previousTask, array $replacement, bool $idempotent, ?int $evidenceCount = null): array {
        return [
            'previous_task_id' => (int) $previousTask['id'],
            'task_id' => (int) $replacement['id'],
            'task_version' => (int) $replacement['task_version'],
            'audit_status' => (string) $replacement['audit_status'],
            'evidence_count' => $evidenceCount,
            'idempotent' => $idempotent,
        ];
    }
}
