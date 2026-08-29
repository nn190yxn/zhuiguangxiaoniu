<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function fitness_reports_storage_path(): string
{
    $baseDir = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    $candidates = [
        $baseDir . '/wp-content/uploads/fitness-records.json',
        rtrim(sys_get_temp_dir(), '/') . '/fitness-records.json',
    ];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path) && is_writable($path)) {
            return $path;
        }
    }
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return $candidates[0];
}

function fitness_reports_scope(array $context, array $record): bool
{
    if (!empty($context['is_hq'])) {
        return true;
    }
    if (($context['role'] ?? '') === 'manager') {
        return (int) ($record['store_id'] ?? 0) > 0
            && (int) ($record['store_id'] ?? 0) === (int) ($context['store_id'] ?? 0);
    }
    return (int) ($record['created_by_user_id'] ?? 0) === (int) ($context['user_id'] ?? 0);
}

function fitness_reports_valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

try {
    [$userId, $user, $staff] = adminRequirePermission('drill.analytics_all');
    $context = appGetCurrentStaffContext();
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    foreach ([$dateFrom, $dateTo] as $date) {
        if ($date !== '' && !fitness_reports_valid_date($date)) {
            jsonResponse(400, '日期格式应为 YYYY-MM-DD');
        }
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        jsonResponse(400, '开始日期不能晚于结束日期');
    }

    $storeFilter = trim((string) ($_GET['store'] ?? ''));
    $coachFilter = trim((string) ($_GET['coach'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $path = fitness_reports_storage_path();
    $raw = is_file($path) ? file_get_contents($path) : '[]';
    $records = json_decode($raw ?: '[]', true);
    $records = is_array($records) ? $records : [];
    $filtered = array_values(array_filter($records, static function ($record) use ($context, $dateFrom, $dateTo, $storeFilter, $coachFilter, $statusFilter): bool {
        if (!is_array($record) || !fitness_reports_scope($context, $record)) {
            return false;
        }
        $date = (string) ($record['test_date'] ?? '');
        if ($dateFrom !== '' && $date < $dateFrom || $dateTo !== '' && $date > $dateTo) {
            return false;
        }
        if ($storeFilter !== '' && stripos((string) ($record['coach_store'] ?? ''), $storeFilter) === false) {
            return false;
        }
        if ($coachFilter !== '' && stripos((string) ($record['coach_name'] ?? ''), $coachFilter) === false) {
            return false;
        }
        if ($statusFilter === 'fallback') {
            return (string) ($record['generation_mode'] ?? '') === 'fallback';
        }
        return $statusFilter === '' || (string) ($record['report_status'] ?? '') === $statusFilter;
    }));

    $studentKeys = [];
    $stores = [];
    $coaches = [];
    $fallbackCount = 0;
    foreach ($filtered as $record) {
        $store = (string) ($record['coach_store'] ?? '未选择');
        $coach = (string) ($record['coach_name'] ?? '未填写');
        $student = trim((string) ($record['child_name'] ?? '未填写'));
        $storeKey = $store !== '' ? $store : '未选择';
        $coachKey = $coach !== '' ? $coach : '未填写';
        $studentKeys[$storeKey . '|' . $student] = true;
        $stores[$storeKey]['report_count'] = ($stores[$storeKey]['report_count'] ?? 0) + 1;
        $stores[$storeKey]['student_keys'][$student] = true;
        $coaches[$coachKey]['report_count'] = ($coaches[$coachKey]['report_count'] ?? 0) + 1;
        $coaches[$coachKey]['student_keys'][$storeKey . '|' . $student] = true;
        if (($record['generation_mode'] ?? 'fallback') === 'fallback') {
            $fallbackCount++;
        }
    }
    $formatGroups = static function (array $groups): array {
        $result = [];
        foreach ($groups as $name => $group) {
            $result[] = [
                'name' => $name,
                'report_count' => (int) ($group['report_count'] ?? 0),
                'distinct_student_count' => count($group['student_keys'] ?? []),
            ];
        }
        usort($result, static fn(array $a, array $b): int => $b['report_count'] <=> $a['report_count']);
        return $result;
    };

    jsonResponse(0, 'success', [
        'viewer' => ['user_id' => (int) $userId, 'role' => $context['role'] ?? '', 'store_id' => (int) ($context['store_id'] ?? 0)],
        'summary' => [
            'report_count' => count($filtered),
            'distinct_student_count' => count($studentKeys),
            'fallback_report_count' => $fallbackCount,
            'today_report_count' => count(array_filter($filtered, static fn(array $record): bool => ($record['test_date'] ?? '') === date('Y-m-d'))),
            'month_report_count' => count(array_filter($filtered, static fn(array $record): bool => substr((string) ($record['test_date'] ?? ''), 0, 7) === date('Y-m'))),
        ],
        'stores' => $formatGroups($stores),
        'coaches' => $formatGroups($coaches),
        'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'store' => $storeFilter, 'coach' => $coachFilter, 'status' => $statusFilter],
    ]);
} catch (Throwable $exception) {
    jsonResponse(500, '服务器错误');
}
