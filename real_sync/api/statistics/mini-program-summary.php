<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
handleCORS();

$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : date('Y-m');
$start = $month . '-01 00:00:00';
$end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));

try {
    $db = getDB();
    $exam = $db->prepare("SELECT COUNT(*) AS total, SUM(is_passed = 1) AS passed, AVG(total_score) AS average_score FROM exam_records WHERE user_id = ? AND exam_type = 'course_exam' AND status = 'completed' AND completed_at >= ? AND completed_at < ?");
    $exam->execute([(int)$userId, $start, $end]);
    $examRow = $exam->fetch(PDO::FETCH_ASSOC) ?: [];

    $drill = $db->prepare("SELECT COUNT(*) AS total, SUM(status IN ('completed', 'evaluated')) AS completed FROM user_drill_tasks WHERE user_id = ? AND updated_at >= ? AND updated_at < ?");
    $drill->execute([(int)$userId, $start, $end]);
    $drillRow = $drill->fetch(PDO::FETCH_ASSOC) ?: [];

    $workload = ['reports' => 0, 'submitted' => 0];
    try {
        $work = $db->prepare("SELECT COUNT(*) AS reports, SUM(status IN ('submitted', 'approved', 'needs_resubmit')) AS submitted FROM workload_daily_reports WHERE user_id = ? AND report_date >= ? AND report_date < ?");
        $work->execute([(int)$userId, $month . '-01', date('Y-m-d', strtotime($start . ' +1 month'))]);
        $workload = $work->fetch(PDO::FETCH_ASSOC) ?: $workload;
    } catch (Throwable $ignored) {
    }

    $context = function_exists('appGetCurrentStaffContext') ? appGetCurrentStaffContext() : [];
    $role = strtolower((string)($context['role'] ?? $context['system_role'] ?? ''));
    $isManager = in_array($role, ['manager', 'store_manager', 'operation', 'admin', 'ceo'], true);
    $data = [
        'month' => $month,
        'updated_at' => date('Y-m-d H:i:s'),
        'personal' => [
            'drill' => ['total' => (int)($drillRow['total'] ?? 0), 'completed' => (int)($drillRow['completed'] ?? 0)],
            'exam' => ['total' => (int)($examRow['total'] ?? 0), 'passed' => (int)($examRow['passed'] ?? 0), 'average_score' => $examRow['average_score'] === null ? null : round((float)$examRow['average_score'], 1)],
            'workload' => ['reports' => (int)($workload['reports'] ?? 0), 'submitted' => (int)($workload['submitted'] ?? 0)],
        ],
        'manager' => null,
    ];
    if ($isManager) {
        $data['manager'] = ['available' => true, 'route' => '/pages/workload/manage'];
    }
    jsonSuccess($data);
} catch (Throwable $error) {
    error_log('Mini program summary error: ' . $error->getMessage());
    jsonError(500, '数据中心暂时不可用');
}
