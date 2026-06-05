<?php
/**
 * 通关地图API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        // 从JWT获取用户角色（不允许通过GET参数覆盖）
        $user = getJwtCurrentUser();
        $userRole = 'sales'; // 默认角色
        if ($user && isset($user['role'])) {
            if ($user['role'] === 'admin' || $user['role'] === 'manager') {
                // 管理角色默认查看自己的通关路径，仅在显式传参时切换
                $requestedRole = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
                $userRole = $requestedRole !== ''
                    ? normalizeStaffRoleCode($requestedRole)
                    : getEffectiveStaffRole($user);
            } else {
                // 普通员工只能查看自己的角色
                $userRole = getEffectiveStaffRole($user);
            }
        } else {
            $staff = getStaffByUserId($userId);
            if ($staff && !empty($staff['role'])) {
                $userRole = normalizeStaffRoleCode($staff['role']);
            }
        }

        $roleCandidates = [$userRole];
        if (in_array($userRole, ['sales', 'coach'], true)) {
            $roleCandidates = ['sales', 'coach'];
        }

        $placeholders = implode(', ', array_fill(0, count($roleCandidates), '?'));
        $sql = "SELECT * FROM pass_stages
                WHERE is_active = 1 AND (role = 'common' OR role IN ($placeholders))
                ORDER BY order_index ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($roleCandidates);
        $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取用户通关进度
        $progressSql = "SELECT * FROM user_pass_progress WHERE user_id = ?";
        $stmt = $db->prepare($progressSql);
        $stmt->execute([$userId]);
        $progressList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $progressMap = [];
        foreach ($progressList as $p) {
            $progressMap[$p['stage_id']] = $p;
        }

        // 获取已获得的证书
        $certSql = "SELECT stage_id, certificate_no, verify_code, issued_at FROM pass_certificates WHERE user_id = ?";
        $stmt = $db->prepare($certSql);
        $stmt->execute([$userId]);
        $certList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $certMap = [];
        foreach ($certList as $cert) {
            $certMap[$cert['stage_id']] = $cert;
        }

        // 统计任务数
        $taskCountSql = "SELECT stage_id, COUNT(*) as cnt FROM stage_tasks WHERE is_required = 1 GROUP BY stage_id";
        $stmt = $db->prepare($taskCountSql);
        $stmt->execute();
        $taskCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $taskCountMap = [];
        foreach ($taskCounts as $tc) {
            $taskCountMap[$tc['stage_id']] = $tc['cnt'];
        }

        // 构建阶段数据
        $result = [];
        $prevCompleted = true; // 公共阶段完成后才能解锁角色阶段

        foreach ($stages as $stage) {
            $stageId = $stage['id'];
            $progress = $progressMap[$stageId] ?? null;
            $cert = $certMap[$stageId] ?? null;
            $totalTasks = $taskCountMap[$stageId] ?? 0;
            $completedTasks = $progress && $progress['completed_tasks']
                ? count(json_decode($progress['completed_tasks'], true))
                : 0;

            // 判断状态
            $status = 'locked';
            if ($stage['role'] === 'common') {
                // 公共阶段始终可访问
                $prevCompleted = $progress && $progress['status'] === 'completed';
            }

            if ($progress) {
                $status = $progress['status'];
            } elseif ($prevCompleted) {
                $status = 'active';
            }

            $result[] = [
                'id' => $stageId,
                'name' => $stage['name'],
                'code' => $stage['code'],
                'role' => $stage['role'],
                'stage' => $stage['stage'],
                'description' => $stage['description'],
                'status' => $status,
                'progress_percent' => $progress ? (float)$progress['progress_percent'] : 0,
                'tasks_count' => $totalTasks,
                'completed_count' => $completedTasks,
                'exam_score' => $progress ? (int)$progress['exam_score'] : null,
                'certificate' => $cert ? [
                    'certificate_no' => $cert['certificate_no'],
                    'verify_code' => $cert['verify_code'],
                    'issued_at' => $cert['issued_at']
                ] : null,
                'started_at' => $progress ? $progress['started_at'] : null,
                'completed_at' => $progress ? $progress['completed_at'] : null
            ];

            // 更新前置状态
            if ($status !== 'completed') {
                $prevCompleted = false;
            }
        }

        jsonResponse(0, 'success', [
            'role' => $userRole,
            'role_name' => ['sales' => '销售', 'coach' => '教练', 'manager' => '店长'][$userRole] ?? '未知',
            'stages' => $result
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
