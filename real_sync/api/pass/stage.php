<?php
/**
 * 通关阶段详情API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();
    $user = getJwtCurrentUser();

    if ($method === 'GET') {
        $stageId = isset($_GET['stage_id']) ? (int)$_GET['stage_id'] : 0;

        if (!$stageId) {
            jsonResponse(1, '缺少阶段ID');
        }

        // 获取阶段信息
        $stageSql = "SELECT * FROM pass_stages WHERE id = ? AND is_active = 1";
        $stmt = $db->prepare($stageSql);
        $stmt->execute([$stageId]);
        $stage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stage) {
            jsonResponse(1, '阶段不存在');
        }

        $effectiveRole = normalizeStaffRoleCode(getEffectiveStaffRole($user));
        $requestedRole = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
        if (isJwtManager($user) && $requestedRole !== '') {
            $effectiveRole = normalizeStaffRoleCode($requestedRole);
        }

        if (!isPassStageRoleAllowed($stage['role'], $effectiveRole)) {
            jsonResponse(403, '无权查看该通关阶段');
        }

        // 获取用户进度
        $progressSql = "SELECT * FROM user_pass_progress WHERE user_id = ? AND stage_id = ?";
        $stmt = $db->prepare($progressSql);
        $stmt->execute([$userId, $stageId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        // 获取阶段任务
        $tasksSql = "SELECT st.*,
                     CASE st.task_type
                       WHEN 'drill' THEN (SELECT title FROM drill_templates WHERE id = st.task_id)
                       WHEN 'knowledge' THEN (SELECT title FROM knowledge_items WHERE id = st.task_id)
                       WHEN 'policy' THEN (SELECT title FROM policies WHERE id = st.task_id)
                       WHEN 'exam' THEN (SELECT title FROM exams WHERE id = st.task_id)
                       ELSE '未知任务'
                     END as task_title
                     FROM stage_tasks st
                     WHERE st.stage_id = ?
                     ORDER BY st.order_index ASC";
        $stmt = $db->prepare($tasksSql);
        $stmt->execute([$stageId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取用户任务完成情况
        $completedTasks = $progress && $progress['completed_tasks']
            ? json_decode($progress['completed_tasks'], true)
            : [];

        // 获取演练任务详情
        foreach ($tasks as &$task) {
            $isCompleted = in_array($task['task_id'], $completedTasks);
            $taskScore = null;

            if ($isCompleted) {
                $task['status'] = 'completed';
            } elseif ($progress) {
                $task['status'] = 'in_progress';
            } else {
                $task['status'] = 'pending';
            }

            // 获取具体得分
            if ($task['task_type'] === 'drill') {
                $scoreSql = "SELECT best_score FROM user_drill_tasks WHERE template_id = ? AND user_id = ? AND status = 'completed'";
                $stmt = $db->prepare($scoreSql);
                $stmt->execute([$task['task_id'], $userId]);
                $taskScore = $stmt->fetchColumn();
            } elseif ($task['task_type'] === 'knowledge') {
                $scoreSql = "SELECT score FROM user_knowledge_progress WHERE knowledge_id = ? AND user_id = ? AND is_completed = 1";
                $stmt = $db->prepare($scoreSql);
                $stmt->execute([$task['task_id'], $userId]);
                $taskScore = $stmt->fetchColumn();
            }

            $task['score'] = $taskScore ? (int)$taskScore : null;
        }

        // 获取综合考核信息
        $examSql = "SELECT e.id, e.title, e.pass_score, e.duration, e.attempt_limit,
                    (SELECT MAX(er.total_score) FROM exam_records er WHERE er.user_id = ? AND er.module_id = e.id AND er.is_passed = 1) as best_score,
                    (SELECT COUNT(*) FROM exam_records er WHERE er.user_id = ? AND er.module_id = e.id AND er.exam_type = 'course_exam') as attempts
                    FROM exams e
                    JOIN stage_tasks st ON st.task_type = 'exam' AND st.task_id = e.id
                    WHERE st.stage_id = ? AND e.is_active = 1
                    ORDER BY st.order_index ASC
                    LIMIT 1";
        $stmt = $db->prepare($examSql);
        $stmt->execute([$userId, $userId, $stageId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            $exam['is_passed'] = $exam['best_score'] >= $exam['pass_score'];
            $exam['can_attempt'] = $exam['attempt_limit'] == 0 || $exam['attempts'] < $exam['attempt_limit'];
        }

        // 获取证书
        $certSql = "SELECT * FROM pass_certificates WHERE user_id = ? AND stage_id = ?";
        $stmt = $db->prepare($certSql);
        $stmt->execute([$userId, $stageId]);
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

        // 计算总进度
        $requiredTasks = array_filter($tasks, fn($t) => $t['is_required']);
        $completedRequired = array_filter($tasks, fn($t) => $t['status'] === 'completed' && $t['is_required']);
        $progressPercent = count($requiredTasks) > 0
            ? round(count($completedRequired) / count($requiredTasks) * 100, 1)
            : 0;

        jsonResponse(0, 'success', [
            'stage' => [
                'id' => $stage['id'],
                'name' => $stage['name'],
                'code' => $stage['code'],
                'description' => $stage['description'],
                'required_score' => $stage['required_score'],
                'points_reward' => $stage['points_reward']
            ],
            'progress' => [
                'status' => $progress ? $progress['status'] : 'locked',
                'progress_percent' => $progress ? (float)$progress['progress_percent'] : $progressPercent,
                'exam_score' => $progress ? (int)$progress['exam_score'] : null,
                'attempts_count' => $progress ? (int)$progress['attempts_count'] : 0,
                'started_at' => $progress ? $progress['started_at'] : null,
                'completed_at' => $progress ? $progress['completed_at'] : null
            ],
            'tasks' => $tasks,
            'exam' => $exam,
            'certificate' => $certificate
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
