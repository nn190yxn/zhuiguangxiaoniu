<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/context.php';

final class WorkloadObligationValidationException extends RuntimeException {}

final class WorkloadObligationService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    private const ELIGIBLE_ROLES = ['sales', 'coach', 'manager'];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function generateForDate(string $businessDate): array {
        $date = $this->normalizeBusinessDate($businessDate);
        $businessDate = $date->format('Y-m-d');
        $isWeeklyRestDay = $date->format('N') === '1';
        $requiredStatus = $isWeeklyRestDay ? 'exempt' : 'required';
        $reasonCode = $isWeeklyRestDay ? 'weekly_rest_day' : 'scheduled';
        $completionStatus = $isWeeklyRestDay ? 'exempt' : 'missing';
        $deadlineAt = $date->modify('+1 day')->format('Y-m-d 00:00:00');

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $assignments = $this->eligibleAssignments($businessDate);
            $existingKeys = $this->existingKeys($businessDate);
            $stmt = $this->pdo->prepare(
                'INSERT INTO workload_submission_obligations '
                . '(obligation_date, store_id, staff_id, role_code, required_status, reason_code, '
                . 'completion_status, deadline_at, source) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'required_status = VALUES(required_status), '
                . 'reason_code = VALUES(reason_code), '
                . 'deadline_at = VALUES(deadline_at), '
                . "completion_status = CASE WHEN report_id IS NULL AND completion_status IN ('missing', 'exempt') "
                . 'THEN VALUES(completion_status) ELSE completion_status END, '
                . "source = CASE WHEN report_id IS NULL AND completion_status IN ('missing', 'exempt') "
                . 'THEN VALUES(source) ELSE source END, '
                . 'updated_at = CURRENT_TIMESTAMP'
            );

            $inserted = 0;
            $existing = 0;
            foreach ($assignments as $assignment) {
                $key = $this->obligationKey(
                    (int) $assignment['store_id'],
                    (int) $assignment['staff_id'],
                    (string) $assignment['role_code']
                );
                if (isset($existingKeys[$key])) {
                    $existing++;
                    $storedRoleCode = $existingKeys[$key];
                } else {
                    $inserted++;
                    $storedRoleCode = (string) $assignment['role_code'];
                    $existingKeys[$key] = $storedRoleCode;
                }

                $stmt->execute([
                    $businessDate,
                    (int) $assignment['store_id'],
                    (int) $assignment['staff_id'],
                    $storedRoleCode,
                    $requiredStatus,
                    $reasonCode,
                    $completionStatus,
                    $deadlineAt,
                    'generated',
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return [
                'business_date' => $businessDate,
                'day_type' => $isWeeklyRestDay ? 'weekly_rest_day' : 'business_day',
                'required_status' => $requiredStatus,
                'reason_code' => $reasonCode,
                'deadline_at' => $deadlineAt,
                'candidate_count' => count($assignments),
                'inserted_count' => $inserted,
                'existing_count' => $existing,
                'required_count' => $isWeeklyRestDay ? 0 : count($assignments),
                'exempt_count' => $isWeeklyRestDay ? count($assignments) : 0,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
            throw new WorkloadObligationValidationException('业务日期格式必须为 YYYY-MM-DD');
        }
        return $date;
    }

    private function eligibleAssignments(string $businessDate): array {
        $stmt = $this->pdo->prepare(
            'SELECT assignment.staff_id, assignment.store_id, assignment.system_role '
            . 'FROM staff_assignments assignment '
            . 'INNER JOIN staffs staff ON staff.id = assignment.staff_id '
            . 'INNER JOIN stores store ON store.id = assignment.store_id AND store.status = 1 '
            . 'INNER JOIN organization_positions position ON position.id = assignment.position_id AND position.status = 1 '
            . 'WHERE assignment.start_date <= ? '
            . 'AND (assignment.end_date IS NULL OR assignment.end_date >= ?) '
            . "AND ((staff.status = 1 AND staff.lifecycle_status = 'active') "
            . "OR (staff.lifecycle_status = 'offboarded' AND staff.offboarded_at IS NOT NULL "
            . 'AND DATE(staff.offboarded_at) >= ?)) '
            . 'ORDER BY assignment.store_id ASC, assignment.staff_id ASC, assignment.id ASC'
        );
        $stmt->execute([$businessDate, $businessDate, $businessDate]);

        $eligible = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roleCode = appRoleCode((string) ($row['system_role'] ?? ''));
            if (!in_array($roleCode, self::ELIGIBLE_ROLES, true)) {
                continue;
            }
            $storeId = (int) ($row['store_id'] ?? 0);
            $staffId = (int) ($row['staff_id'] ?? 0);
            if ($storeId <= 0 || $staffId <= 0) {
                continue;
            }
            $key = $this->obligationKey($storeId, $staffId, $roleCode);
            $eligible[$key] = [
                'store_id' => $storeId,
                'staff_id' => $staffId,
                'role_code' => $roleCode,
            ];
        }

        return array_values($eligible);
    }

    private function existingKeys(string $businessDate): array {
        $stmt = $this->pdo->prepare(
            'SELECT store_id, staff_id, role_code '
            . 'FROM workload_submission_obligations '
            . 'WHERE obligation_date = ?'
        );
        $stmt->execute([$businessDate]);

        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roleCode = (string) $row['role_code'];
            $keys[$this->obligationKey(
                (int) $row['store_id'],
                (int) $row['staff_id'],
                appRoleCode($roleCode)
            )] = $roleCode;
        }
        return $keys;
    }

    private function obligationKey(int $storeId, int $staffId, string $roleCode): string {
        return $storeId . ':' . $staffId . ':' . $roleCode;
    }
}
