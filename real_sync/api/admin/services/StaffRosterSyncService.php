<?php
declare(strict_types=1);

final class StaffRosterSyncService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function sync(array $records, array $operatorUser, array $operatorStaff): array {
        if ($records === [] || count($records) > 1000) {
            throw new InvalidArgumentException('同步记录数量需在 1 至 1000 行之间');
        }

        $rows = [];
        $succeeded = 0;
        $failed = 0;
        foreach ($records as $index => $record) {
            try {
                $result = $this->syncRecord($this->normalizeRecord($record), $operatorUser, $operatorStaff);
                $rows[] = [
                    'line' => $index + 1,
                    'status' => 'succeeded',
                    'summary' => $result['summary'],
                    'validation' => ['message' => $result['message']],
                ];
                $succeeded++;
            } catch (Throwable $error) {
                $rows[] = [
                    'line' => $index + 1,
                    'status' => 'failed',
                    'summary' => $this->summaryFromRecord($record),
                    'validation' => ['message' => $error->getMessage()],
                ];
                $failed++;
            }
        }

        return [
            'status' => $failed === 0 ? 'completed' : ($succeeded > 0 ? 'partial_failed' : 'failed'),
            'total' => count($records),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'rows' => $rows,
        ];
    }

    private function syncRecord(array $record, array $operatorUser, array $operatorStaff): array {
        $this->db->beginTransaction();
        try {
            $staff = $this->findStaff($record['name'], $record['phone']);
            if (!$staff) {
                throw new RuntimeException('系统无匹配员工，未创建账号');
            }

            $before = [
                'name' => (string)$staff['name'],
                'phone' => (string)$staff['phone'],
                'job_title' => (string)($staff['job_title'] ?? ''),
            ];
            $after = [
                'name' => $record['name'],
                'phone' => $record['phone'],
                'job_title' => $record['job_title'],
            ];
            $changed = $before !== $after;
            if ($changed) {
                $conflict = $this->db->prepare('SELECT id FROM staffs WHERE phone = ? AND id <> ? LIMIT 1 FOR UPDATE');
                $conflict->execute([$after['phone'], (int)$staff['id']]);
                if ($conflict->fetchColumn() !== false) {
                    throw new RuntimeException('手机号已被其他员工使用');
                }

                $update = $this->db->prepare('UPDATE staffs SET name = ?, phone = ?, job_title = ? WHERE id = ?');
                $update->execute([$after['name'], $after['phone'], $after['job_title'], (int)$staff['id']]);
                adminRecordOperation($this->db, $operatorUser, $operatorStaff, [
                    'module' => 'staff',
                    'action' => 'sync_roster',
                    'target_type' => 'staff',
                    'target_id' => (string)$staff['id'],
                    'before' => $before,
                    'after' => $after,
                ]);
            }
            $this->db->commit();

            return [
                'message' => $changed ? '员工资料已同步' : '员工资料已是最新',
                'summary' => [
                    'name' => $after['name'],
                    'job_title' => $after['job_title'],
                    'phone' => adminMaskSensitiveValue($after['phone']),
                ],
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function findStaff(string $name, string $phone): ?array {
        $byPhone = $this->db->prepare('SELECT id, name, phone, job_title FROM staffs WHERE phone = ? LIMIT 2 FOR UPDATE');
        $byPhone->execute([$phone]);
        $phoneMatches = $byPhone->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($phoneMatches) === 1) {
            return $phoneMatches[0];
        }
        if (count($phoneMatches) > 1) {
            throw new RuntimeException('手机号匹配到多个员工');
        }

        $byName = $this->db->prepare('SELECT id, name, phone, job_title FROM staffs WHERE name = ? LIMIT 2 FOR UPDATE');
        $byName->execute([$name]);
        $nameMatches = $byName->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($nameMatches) === 1) {
            return $nameMatches[0];
        }
        if (count($nameMatches) > 1) {
            throw new RuntimeException('姓名匹配到多个员工');
        }
        return null;
    }

    private function normalizeRecord($record): array {
        $record = is_array($record) ? $record : [];
        $name = trim((string)($record['name'] ?? $record['姓名'] ?? ''));
        $phone = preg_replace('/\s+/', '', trim((string)($record['phone'] ?? $record['手机号'] ?? '')));
        $jobTitle = trim((string)($record['job_title'] ?? $record['职位'] ?? $record['职务'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('姓名不能为空且不能超过 100 个字符');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new InvalidArgumentException('手机号格式无效');
        }
        if ($jobTitle === '' || mb_strlen($jobTitle, 'UTF-8') > 100) {
            throw new InvalidArgumentException('职位不能为空且不能超过 100 个字符');
        }
        return ['name' => $name, 'phone' => $phone, 'job_title' => $jobTitle];
    }

    private function summaryFromRecord($record): array {
        $record = is_array($record) ? $record : [];
        return [
            'name' => trim((string)($record['name'] ?? $record['姓名'] ?? '')),
            'job_title' => trim((string)($record['job_title'] ?? $record['职位'] ?? $record['职务'] ?? '')),
            'phone' => adminMaskSensitiveValue($record['phone'] ?? $record['手机号'] ?? ''),
        ];
    }
}
