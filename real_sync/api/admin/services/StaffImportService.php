<?php
declare(strict_types=1);

require_once __DIR__ . '/StaffLifecycleService.php';

final class StaffImportValidationException extends RuntimeException {}
final class StaffImportBatchConflictException extends RuntimeException {}

final class StaffImportService {
    private const MAX_ROWS = 1000;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function import(
        array $records,
        string $batchKey,
        array $operatorUser,
        array $operatorStaff,
        array $metadata = []
    ): array {
        if ($records === [] || count($records) > self::MAX_ROWS) {
            throw new StaffImportValidationException('导入记录数量必须在 1 至 1000 行之间');
        }
        $requesterStaffId = (int)($operatorStaff['id'] ?? 0);
        if ($requesterStaffId <= 0) {
            throw new StaffImportValidationException('导入请求缺少有效员工身份');
        }
        $batchKey = $this->normalizeBatchKey($batchKey);
        $this->assertSchemaReady();

        $normalizedRecords = array_map(fn($record) => $this->normalizeRecord($record), $records);
        $summaries = array_map(fn(array $record) => $this->summarizeRecord($record), $normalizedRecords);
        $batch = $this->lockBatchForAttempt(
            $batchKey,
            $requesterStaffId,
            $summaries,
            trim((string)($metadata['file_name'] ?? '')),
            trim((string)($metadata['file_sha256'] ?? ''))
        );
        if ((string)$batch['status'] === 'completed') {
            return $this->batchResult((int)$batch['id']);
        }

        $rows = $this->retryableRows((int)$batch['id']);
        $lifecycle = new StaffLifecycleService($this->db);
        foreach ($rows as $row) {
            $rowNumber = (int)$row['row_number'];
            $record = $normalizedRecords[$rowNumber - 1] ?? [];
            $summary = $summaries[$rowNumber - 1] ?? [];
            $this->markRowProcessing((int)$row['id'], $summary, (string)$row['status'] === 'failed');
            try {
                $staff = $lifecycle->create($record, $operatorUser, $operatorStaff);
                $this->markRowSucceeded((int)$row['id'], (int)$staff['id'], $staff);
            } catch (Throwable $error) {
                $this->markRowFailed((int)$row['id'], $this->rowError($error));
            }
        }

        $this->finalizeBatch((int)$batch['id']);
        return $this->batchResult((int)$batch['id']);
    }

    public static function generateBatchKey(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function normalizeBatchKey(string $batchKey): string {
        $batchKey = strtolower(trim($batchKey));
        if ($batchKey === '') {
            return self::generateBatchKey();
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $batchKey)) {
            throw new StaffImportValidationException('batch_key 必须是有效 UUID');
        }
        return $batchKey;
    }

    private function assertSchemaReady(): void {
        foreach (['staff_import_batches', 'staff_import_rows'] as $table) {
            if (!adminTableExists($this->db, $table)) {
                throw new RuntimeException('Missing database migration 202607240001_staff_organization.sql');
            }
        }
    }

    private function lockBatchForAttempt(
        string $batchKey,
        int $requesterStaffId,
        array $summaries,
        string $fileName,
        string $fileSha256
    ): array {
        $digest = preg_match('/^[0-9a-f]{64}$/i', $fileSha256)
            ? strtolower($fileSha256)
            : hash('sha256', $this->encodeJson($summaries));
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO staff_import_batches '
                . '(batch_key, file_name, file_sha256, requested_by_staff_id, status, total_rows) '
                . "VALUES (?, ?, ?, ?, 'pending', ?) "
                . 'ON DUPLICATE KEY UPDATE batch_key = VALUES(batch_key)'
            );
            $insert->execute([$batchKey, $fileName ?: null, $digest, $requesterStaffId, count($summaries)]);

            $select = $this->db->prepare('SELECT * FROM staff_import_batches WHERE batch_key = ? FOR UPDATE');
            $select->execute([$batchKey]);
            $batch = $select->fetch(PDO::FETCH_ASSOC);
            if (!$batch) {
                throw new RuntimeException('导入批次创建失败');
            }
            if ((int)$batch['requested_by_staff_id'] !== $requesterStaffId) {
                throw new StaffImportBatchConflictException('批次标识已由其他员工使用');
            }
            if ((int)$batch['total_rows'] !== count($summaries)) {
                throw new StaffImportBatchConflictException('重试批次的行数必须与首次请求一致');
            }
            if ((string)$batch['status'] === 'processing') {
                throw new StaffImportBatchConflictException('导入批次正在处理中');
            }
            if ((string)$batch['status'] === 'completed') {
                $this->db->commit();
                return $batch;
            }

            $rowInsert = $this->db->prepare(
                'INSERT INTO staff_import_rows (batch_id, row_number, raw_summary_json, status) '
                . "VALUES (?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE row_number = VALUES(row_number)"
            );
            foreach ($summaries as $index => $summary) {
                $rowInsert->execute([(int)$batch['id'], $index + 1, $this->encodeJson($summary)]);
            }
            $update = $this->db->prepare(
                "UPDATE staff_import_batches SET status = 'processing', completed_at = NULL, updated_at = NOW() WHERE id = ?"
            );
            $update->execute([(int)$batch['id']]);
            $this->db->commit();
            $batch['status'] = 'processing';
            return $batch;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function retryableRows(int $batchId): array {
        $stmt = $this->db->prepare(
            "SELECT id, row_number, status FROM staff_import_rows WHERE batch_id = ? AND status IN ('pending', 'failed') ORDER BY row_number"
        );
        $stmt->execute([$batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function markRowProcessing(int $rowId, array $summary, bool $retry): void {
        $stmt = $this->db->prepare(
            "UPDATE staff_import_rows SET raw_summary_json = ?, validation_result_json = NULL, status = 'processing', "
            . 'retry_count = retry_count + ?, staff_id = NULL, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$this->encodeJson($summary), $retry ? 1 : 0, $rowId]);
    }

    private function markRowSucceeded(int $rowId, int $staffId, array $staff): void {
        $result = [
            'valid' => true,
            'message' => 'success',
            'employee_no' => (string)($staff['employee_no'] ?? ''),
        ];
        $stmt = $this->db->prepare(
            "UPDATE staff_import_rows SET validation_result_json = ?, status = 'succeeded', staff_id = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$this->encodeJson($result), $staffId, $rowId]);
    }

    private function markRowFailed(int $rowId, array $result): void {
        $stmt = $this->db->prepare(
            "UPDATE staff_import_rows SET validation_result_json = ?, status = 'failed', staff_id = NULL, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$this->encodeJson($result), $rowId]);
    }

    private function finalizeBatch(int $batchId): void {
        $counts = $this->rowCounts($batchId);
        $status = $counts['failed'] === 0
            ? 'completed'
            : ($counts['succeeded'] === 0 ? 'failed' : 'partial_failed');
        $stmt = $this->db->prepare(
            'UPDATE staff_import_batches SET status = ?, succeeded_rows = ?, failed_rows = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $counts['succeeded'], $counts['failed'], $batchId]);
    }

    private function batchResult(int $batchId): array {
        $batchStmt = $this->db->prepare('SELECT * FROM staff_import_batches WHERE id = ?');
        $batchStmt->execute([$batchId]);
        $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw new RuntimeException('导入批次不存在');
        }
        $rowStmt = $this->db->prepare(
            'SELECT row_number, raw_summary_json, validation_result_json, status, staff_id, retry_count '
            . 'FROM staff_import_rows WHERE batch_id = ? ORDER BY row_number'
        );
        $rowStmt->execute([$batchId]);
        $rows = [];
        $errors = [];
        foreach ($rowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $summary = $this->decodeJson($row['raw_summary_json']);
            $validation = $this->decodeJson($row['validation_result_json']);
            $rows[] = [
                'line' => (int)$row['row_number'],
                'status' => (string)$row['status'],
                'staff_id' => $row['staff_id'] === null ? null : (int)$row['staff_id'],
                'retry_count' => (int)$row['retry_count'],
                'summary' => $summary,
                'validation' => $validation,
            ];
            if ((string)$row['status'] === 'failed') {
                $errors[] = [
                    'line' => (int)$row['row_number'],
                    'employee_no' => (string)($summary['employee_no'] ?? ''),
                    'message' => (string)($validation['message'] ?? '员工创建失败'),
                ];
            }
        }
        $failed = (int)$batch['failed_rows'];
        $succeeded = (int)$batch['succeeded_rows'];
        return [
            'batch_key' => (string)$batch['batch_key'],
            'status' => (string)$batch['status'],
            'total' => (int)$batch['total_rows'],
            'succeeded' => $succeeded,
            'failed' => $failed,
            'retryable_batch_key' => $failed > 0 ? (string)$batch['batch_key'] : null,
            'rows' => $rows,
            'created' => $succeeded,
            'updated' => 0,
            'linked' => $succeeded,
            'skipped' => $failed,
            'errors' => $errors,
        ];
    }

    private function rowCounts(int $batchId): array {
        $stmt = $this->db->prepare(
            "SELECT SUM(status = 'succeeded') AS succeeded_rows, SUM(status = 'failed') AS failed_rows FROM staff_import_rows WHERE batch_id = ?"
        );
        $stmt->execute([$batchId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'succeeded' => (int)($counts['succeeded_rows'] ?? 0),
            'failed' => (int)($counts['failed_rows'] ?? 0),
        ];
    }

    private function normalizeRecord($record): array {
        $record = is_array($record) ? $record : [];
        return [
            'employee_no' => trim((string)($record['employee_no'] ?? $record['工号'] ?? '')),
            'name' => trim((string)($record['name'] ?? $record['姓名'] ?? '')),
            'phone' => trim((string)($record['phone'] ?? $record['手机号'] ?? '')),
            'store_id' => (int)($record['store_id'] ?? $record['门店ID'] ?? 0),
            'position_id' => (int)($record['position_id'] ?? $record['岗位ID'] ?? 0),
            'role' => trim((string)($record['role'] ?? $record['角色'] ?? '')),
            'initial_password' => (string)($record['initial_password'] ?? $record['password'] ?? $record['初始密码'] ?? ''),
            'username' => trim((string)($record['username'] ?? $record['账号'] ?? '')),
            'email' => trim((string)($record['email'] ?? $record['邮箱'] ?? '')),
            'entry_date' => trim((string)($record['entry_date'] ?? $record['入职日期'] ?? '')),
            'stage' => trim((string)($record['stage'] ?? $record['阶段'] ?? '')),
        ];
    }

    private function summarizeRecord(array $record): array {
        return [
            'employee_no' => $record['employee_no'],
            'name' => $record['name'],
            'store_id' => $record['store_id'],
            'position_id' => $record['position_id'],
            'role' => $record['role'],
            'phone' => adminMaskSensitiveValue($record['phone']),
            'username' => adminMaskSensitiveValue($record['username']),
            'email' => adminMaskSensitiveValue($record['email']),
        ];
    }

    private function rowError(Throwable $error): array {
        $known = $error instanceof StaffLifecycleValidationException
            || $error instanceof PasswordPolicyValidationException
            || $error instanceof StaffIdentityConflictException;
        $result = [
            'valid' => false,
            'message' => $known ? $error->getMessage() : '员工创建失败',
        ];
        if ($error instanceof StaffIdentityConflictException) {
            $result['conflict_fields'] = $error->conflictFields();
            $result['existing_profiles'] = $error->profiles();
        }
        if (!$known) {
            error_log('[admin.staff.import.row] ' . $error->getMessage());
        }
        return $result;
    }

    private function encodeJson($value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('导入数据无法编码');
        }
        return $encoded;
    }

    private function decodeJson($value): array {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
