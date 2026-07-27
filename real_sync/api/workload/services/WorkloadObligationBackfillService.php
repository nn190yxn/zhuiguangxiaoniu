<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/context.php';

final class WorkloadObligationBackfillValidationException extends RuntimeException {}

final class WorkloadObligationBackfillService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    private const ELIGIBLE_ROLES = ['sales', 'coach'];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function backfill(string $fromDate, string $toDate): array {
        $from = $this->normalizeBusinessDate($fromDate);
        $to = $this->normalizeBusinessDate($toDate);
        if ($from > $to) {
            throw new WorkloadObligationBackfillValidationException('开始日期不能晚于结束日期');
        }

        $today = new DateTimeImmutable('today', new DateTimeZone(self::BUSINESS_TIMEZONE));
        if ($to >= $today) {
            throw new WorkloadObligationBackfillValidationException('历史义务回填的结束日期必须早于今天');
        }

        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $existingExactKeys = $this->existingExactKeys($fromDate, $toDate);
            $existingSemanticKeys = $this->existingSemanticKeys($fromDate, $toDate);
            $reports = $this->historicalReports($fromDate, $toDate);
            $reportKeys = [];
            $reportInsert = $this->pdo->prepare(
                'INSERT INTO workload_submission_obligations '
                . '(obligation_date, store_id, staff_id, role_code, required_status, reason_code, '
                . 'report_id, completion_status, deadline_at, completed_at, source) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'required_status = VALUES(required_status), '
                . 'reason_code = VALUES(reason_code), '
                . 'report_id = VALUES(report_id), '
                . 'deadline_at = VALUES(deadline_at), '
                . "completed_at = CASE WHEN completion_status = 'corrected' "
                . 'THEN completed_at ELSE VALUES(completed_at) END, '
                . "source = CASE WHEN completion_status = 'corrected' "
                . 'THEN source ELSE VALUES(source) END, '
                . "completion_status = CASE WHEN completion_status = 'corrected' "
                . 'THEN completion_status ELSE VALUES(completion_status) END, '
                . 'updated_at = CURRENT_TIMESTAMP'
            );

            $insertedReports = 0;
            $existingReports = 0;
            foreach ($reports as $report) {
                $date = (string) $report['report_date'];
                $storeId = (int) $report['store_id'];
                $staffId = (int) $report['staff_id'];
                $roleCode = (string) $report['role_code'];
                $exactKey = $this->exactKey($date, $storeId, $staffId, $roleCode);
                $semanticKey = $this->semanticKey($date, $storeId, $staffId, appRoleCode($roleCode));
                if (isset($existingExactKeys[$exactKey])) {
                    $existingReports++;
                    $storedRoleCode = $roleCode;
                } elseif (isset($existingSemanticKeys[$semanticKey])) {
                    $existingReports++;
                    $storedRoleCode = $existingSemanticKeys[$semanticKey];
                } else {
                    $insertedReports++;
                    $existingExactKeys[$exactKey] = true;
                    $storedRoleCode = $roleCode;
                }
                $existingSemanticKeys[$semanticKey] = $storedRoleCode;

                $isWeeklyRestDay = $this->isWeeklyRestDay($date);
                $completionStatus = (string) $report['submit_status'] === 'submitted'
                    ? 'submitted'
                    : 'draft';
                $completedAt = $completionStatus === 'submitted'
                    ? ($report['submitted_at'] ?? $report['updated_at'] ?? null)
                    : null;
                $reportInsert->execute([
                    $date,
                    $storeId,
                    $staffId,
                    $storedRoleCode,
                    $isWeeklyRestDay ? 'exempt' : 'required',
                    $isWeeklyRestDay ? 'weekly_rest_day' : 'historical_report',
                    (int) $report['id'],
                    $completionStatus,
                    $this->deadlineAt($date),
                    $completedAt,
                    'backfill',
                ]);
                $reportKeys[$semanticKey] = true;
            }

            $candidates = $this->historicalAssignmentCandidates($from, $to);
            $missingInsert = $this->pdo->prepare(
                'INSERT INTO workload_submission_obligations '
                . '(obligation_date, store_id, staff_id, role_code, required_status, reason_code, '
                . 'completion_status, deadline_at, source) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'required_status = CASE WHEN report_id IS NULL AND completion_status IN ('
                . "'missing', 'exempt') THEN VALUES(required_status) ELSE required_status END, "
                . 'reason_code = CASE WHEN report_id IS NULL AND completion_status IN ('
                . "'missing', 'exempt') THEN VALUES(reason_code) ELSE reason_code END, "
                . 'deadline_at = CASE WHEN report_id IS NULL AND completion_status IN ('
                . "'missing', 'exempt') THEN VALUES(deadline_at) ELSE deadline_at END, "
                . 'completion_status = CASE WHEN report_id IS NULL AND completion_status IN ('
                . "'missing', 'exempt') THEN VALUES(completion_status) ELSE completion_status END, "
                . 'source = CASE WHEN report_id IS NULL AND completion_status IN ('
                . "'missing', 'exempt') THEN VALUES(source) ELSE source END, "
                . 'updated_at = CURRENT_TIMESTAMP'
            );

            $insertedMissing = 0;
            $existingMissing = 0;
            $coveredByReport = 0;
            foreach ($candidates as $candidate) {
                $key = $candidate['key'];
                if (isset($reportKeys[$key])) {
                    $coveredByReport++;
                    continue;
                }

                if (isset($existingSemanticKeys[$key])) {
                    $existingMissing++;
                    $storedRoleCode = $existingSemanticKeys[$key];
                } else {
                    $insertedMissing++;
                    $storedRoleCode = $candidate['role_code'];
                    $existingSemanticKeys[$key] = $storedRoleCode;
                }
                $isWeeklyRestDay = $this->isWeeklyRestDay($candidate['obligation_date']);
                $missingInsert->execute([
                    $candidate['obligation_date'],
                    $candidate['store_id'],
                    $candidate['staff_id'],
                    $storedRoleCode,
                    $isWeeklyRestDay ? 'exempt' : 'required',
                    $isWeeklyRestDay ? 'weekly_rest_day' : 'historical_assignment',
                    $isWeeklyRestDay ? 'exempt' : 'missing',
                    $this->deadlineAt($candidate['obligation_date']),
                    'backfill',
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'report_count' => count($reports),
                'inserted_report_count' => $insertedReports,
                'existing_report_count' => $existingReports,
                'assignment_candidate_count' => count($candidates),
                'covered_by_report_count' => $coveredByReport,
                'inserted_missing_count' => $insertedMissing,
                'existing_missing_count' => $existingMissing,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function historicalReports(string $fromDate, string $toDate): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, report_date, store_id, staff_id, role_code, submit_status, submitted_at, updated_at '
            . 'FROM workload_daily_reports WHERE report_date BETWEEN ? AND ? '
            . 'ORDER BY report_date ASC, store_id ASC, staff_id ASC, id ASC'
        );
        $stmt->execute([$fromDate, $toDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function historicalAssignmentCandidates(DateTimeImmutable $from, DateTimeImmutable $to): array {
        $stmt = $this->pdo->prepare(
            'SELECT assignment.staff_id, assignment.store_id, assignment.system_role, '
            . 'assignment.start_date, assignment.end_date, staff.lifecycle_status, staff.offboarded_at '
            . 'FROM staff_assignments assignment '
            . 'INNER JOIN staffs staff ON staff.id = assignment.staff_id '
            . 'WHERE assignment.start_date <= ? '
            . 'AND (assignment.end_date IS NULL OR assignment.end_date >= ?) '
            . "AND (staff.lifecycle_status = 'active' OR (staff.lifecycle_status = 'offboarded' "
            . 'AND staff.offboarded_at IS NOT NULL AND DATE(staff.offboarded_at) >= ?)) '
            . 'ORDER BY assignment.start_date ASC, assignment.store_id ASC, assignment.staff_id ASC, assignment.id ASC'
        );
        $stmt->execute([$to->format('Y-m-d'), $from->format('Y-m-d'), $from->format('Y-m-d')]);

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $assignment) {
            $roleCode = appRoleCode((string) ($assignment['system_role'] ?? ''));
            if (!in_array($roleCode, self::ELIGIBLE_ROLES, true)) {
                continue;
            }
            $storeId = (int) ($assignment['store_id'] ?? 0);
            $staffId = (int) ($assignment['staff_id'] ?? 0);
            if ($storeId <= 0 || $staffId <= 0) {
                continue;
            }

            $assignmentStart = $this->databaseDate((string) $assignment['start_date']);
            $candidateFrom = $assignmentStart > $from ? $assignmentStart : $from;
            $candidateTo = $to;
            if (!empty($assignment['end_date'])) {
                $assignmentEnd = $this->databaseDate((string) $assignment['end_date']);
                $candidateTo = $assignmentEnd < $candidateTo ? $assignmentEnd : $candidateTo;
            }
            if ((string) $assignment['lifecycle_status'] === 'offboarded') {
                $offboardedAt = $this->databaseDate(substr((string) $assignment['offboarded_at'], 0, 10));
                $candidateTo = $offboardedAt < $candidateTo ? $offboardedAt : $candidateTo;
            }

            for ($date = $candidateFrom; $date <= $candidateTo; $date = $date->modify('+1 day')) {
                $businessDate = $date->format('Y-m-d');
                $key = $this->semanticKey($businessDate, $storeId, $staffId, $roleCode);
                $candidates[$key] = [
                    'key' => $key,
                    'obligation_date' => $businessDate,
                    'store_id' => $storeId,
                    'staff_id' => $staffId,
                    'role_code' => $roleCode,
                ];
            }
        }
        return array_values($candidates);
    }

    private function existingExactKeys(string $fromDate, string $toDate): array {
        $stmt = $this->pdo->prepare(
            'SELECT obligation_date, store_id, staff_id, role_code '
            . 'FROM workload_submission_obligations WHERE obligation_date BETWEEN ? AND ?'
        );
        $stmt->execute([$fromDate, $toDate]);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $keys[$this->exactKey(
                (string) $row['obligation_date'],
                (int) $row['store_id'],
                (int) $row['staff_id'],
                (string) $row['role_code']
            )] = true;
        }
        return $keys;
    }

    private function existingSemanticKeys(string $fromDate, string $toDate): array {
        $stmt = $this->pdo->prepare(
            'SELECT obligation_date, store_id, staff_id, role_code '
            . 'FROM workload_submission_obligations WHERE obligation_date BETWEEN ? AND ?'
        );
        $stmt->execute([$fromDate, $toDate]);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roleCode = (string) $row['role_code'];
            $keys[$this->semanticKey(
                (string) $row['obligation_date'],
                (int) $row['store_id'],
                (int) $row['staff_id'],
                appRoleCode($roleCode)
            )] = $roleCode;
        }
        return $keys;
    }

    private function normalizeBusinessDate(string $businessDate): DateTimeImmutable {
        $businessDate = trim($businessDate);
        $timezone = new DateTimeZone(self::BUSINESS_TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $businessDate
        ) {
            throw new WorkloadObligationBackfillValidationException('业务日期格式必须为 YYYY-MM-DD');
        }
        return $date;
    }

    private function databaseDate(string $date): DateTimeImmutable {
        return new DateTimeImmutable($date, new DateTimeZone(self::BUSINESS_TIMEZONE));
    }

    private function isWeeklyRestDay(string $businessDate): bool {
        return $this->databaseDate($businessDate)->format('N') === '1';
    }

    private function deadlineAt(string $businessDate): string {
        return $this->databaseDate($businessDate)->modify('+1 day')->format('Y-m-d 00:00:00');
    }

    private function exactKey(string $date, int $storeId, int $staffId, string $roleCode): string {
        return $date . ':' . $storeId . ':' . $staffId . ':' . $roleCode;
    }

    private function semanticKey(string $date, int $storeId, int $staffId, string $roleCode): string {
        return $date . ':' . $storeId . ':' . $staffId . ':' . $roleCode;
    }
}
