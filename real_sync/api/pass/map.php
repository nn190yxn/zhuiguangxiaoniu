<?php
/**
 * 通关地图API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = (int)getCurrentUserId();
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($method === 'GET') {
        // 角色只能来自服务端认证上下文，客户端参数不得覆盖。
        $user = getJwtCurrentUser();
        $staff = getStaffByUserId($userId) ?: [];
        $userRole = normalizeStaffRoleCode((string)($staff['role'] ?? ($user['role'] ?? '')));
        if ($userRole === '') {
            jsonResponse(403, '员工角色未配置');
            exit;
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
        $taskCountSql = "SELECT st.stage_id, COUNT(*) as cnt
                         FROM stage_tasks st
                         WHERE st.is_required = 1
                           AND (st.task_type NOT IN ('knowledge', 'drill') OR
                             (st.task_type = 'knowledge' AND EXISTS (
                               SELECT 1 FROM knowledge_items k
                               WHERE k.id = st.task_id AND k.status = 1 AND k.publication_status = 'published'
                             )) OR
                             (st.task_type = 'drill' AND EXISTS (
                               SELECT 1 FROM drill_templates dt
                               WHERE dt.id = st.task_id AND dt.status = 1
                                 AND (dt.knowledge_card_id IS NULL OR EXISTS (
                                   SELECT 1 FROM knowledge_items linked_k
                                   WHERE linked_k.id = dt.knowledge_card_id
                                     AND linked_k.status = 1
                                     AND linked_k.publication_status = 'published'
                                 ))
                             )))
                         GROUP BY st.stage_id";
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
                ? min(count(json_decode($progress['completed_tasks'], true) ?: []), (int)$totalTasks)
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
