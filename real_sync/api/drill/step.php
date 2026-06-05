<?php
/**
 * 演练步骤更新API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        $taskId = isset($data['task_id']) ? (int)$data['task_id'] : 0;
        $step = isset($data['step']) ? (int)$data['step'] : 1;
        $action = isset($data['action']) ? trim($data['action']) : 'start';
        $score = isset($data['score']) ? max(0, min(100, (int)$data['score'])) : 0;
        $feedback = isset($data['feedback']) ? htmlspecialchars(trim($data['feedback']), ENT_QUOTES, 'UTF-8') : '';
        $recordingUrl = isset($data['recording_url']) ? trim($data['recording_url']) : '';

        if (!$taskId) {
            jsonResponse(1, '缺少任务ID');
        }

        if ($step < 1 || $step > 4) {
            jsonResponse(1, '无效的步骤');
        }

        $allowedActions = ['start', 'complete', 'retry'];
        if (!in_array($action, $allowedActions)) {
            jsonResponse(1, '未知操作');
        }

        // 获取用户任务
        $taskSql = "SELECT dt.*, t.pass_score, t.points_reward, t.id AS template_id
                    FROM user_drill_tasks dt
                    JOIN drill_templates t ON dt.template_id = t.id
                    WHERE dt.id = ? AND dt.user_id = ?";
        $stmt = $db->prepare($taskSql);
        $stmt->execute([$taskId, $userId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(1, '任务不存在');
        }

        $stepStatus = !empty($task['step_status']) ? json_decode($task['step_status'], true) : [];
        $stepScores = !empty($task['step_scores']) ? json_decode($task['step_scores'], true) : [];

        if ($action === 'start') {
            // 开始步骤
            if (!isset($stepStatus[$step]) || $stepStatus[$step] === 'pending') {
                $stepStatus[$step] = 'in_progress';
            }

            $updateSql = "UPDATE user_drill_tasks SET status = 'learning', step_status = ? WHERE id = ?";
            $db->prepare($updateSql)->execute([json_encode($stepStatus), $taskId]);

            jsonResponse(0, '步骤已开始', ['step_status' => $stepStatus]);

        } elseif ($action === 'complete') {
            // 完成步骤
            $stepStatus[$step] = 'completed';
            $stepScores[$step] = $score;

            // 计算进度
            $progress = 0;
            $completedCount = 0;
            for ($i = 1; $i <= 4; $i++) {
                if (isset($stepStatus[$i]) && $stepStatus[$i] === 'completed') {
                    $progress += 25;
                    $completedCount++;
                }
            }

            // 更新任务状态
            $newStatus = 'learning';
            if ($completedCount == 4) {
                // 全部完成
                if ($score >= $task['pass_score']) {
                    $newStatus = 'completed';
                    $progress = 100;
                } else {
                    $newStatus = 'failed';
                }
            } elseif ($completedCount > 0) {
                $newStatus = 'practicing';
            }

            $updateSql = "UPDATE user_drill_tasks
                          SET status = ?, current_step = ?, step_status = ?, step_scores = ?,
                              progress = ?, best_score = GREATEST(best_score, ?),
                              attempts_count = attempts_count + 1,
                              completed_at = ?
                          WHERE id = ?";
            $completedAt = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
            $db->prepare($updateSql)->execute([
                $newStatus, min($step + 1, 4), json_encode($stepStatus), json_encode($stepScores),
                $progress, $score, $completedAt, $taskId
            ]);

            // 记录演练记录
            $recordSql = "INSERT INTO drill_records (task_id, user_id, step, score, feedback, recording_url, assessed_by)
                           VALUES (?, ?, ?, ?, ?, ?, 'system')";
            $db->prepare($recordSql)->execute([$taskId, $userId, $step, $score, $feedback, $recordingUrl]);

            // 如果完成，奖励积分
            if ($newStatus === 'completed') {
                awardDrillPoints($userId, $task['template_id'], $task['points_reward']);
            }

            jsonResponse(0, $newStatus === 'completed' ? '演练完成' : '步骤完成', [
                'step_status' => $stepStatus,
                'progress' => $progress,
                'task_status' => $newStatus,
                'is_passed' => $newStatus === 'completed',
                'next_step' => min($step + 1, 4)
            ]);

        } elseif ($action === 'retry') {
            // 重试（重新开始演练）
            $stepStatus = [1 => 'pending', 2 => 'pending', 3 => 'pending', 4 => 'pending'];
            $stepScores = [];

            $updateSql = "UPDATE user_drill_tasks
                          SET status = 'learning', current_step = 1, step_status = ?,
                              step_scores = ?, progress = 0, attempts_count = attempts_count + 1
                          WHERE id = ?";
            $db->prepare($updateSql)->execute([json_encode($stepStatus), json_encode($stepScores), $taskId]);

            jsonResponse(0, '已重新开始', ['step_status' => $stepStatus]);

        } else {
            jsonResponse(1, '未知操作');
        }
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    error_log('drill/step error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}

/**
 * 奖励演练积分
 */
function awardDrillPoints($userId, $templateId, $points) {
    if ($points <= 0) return;

    $db = getDB();

    // 获取规则
    $ruleSql = "SELECT id, points FROM points_rules WHERE code = 'drill_complete' AND status = 1";
    $stmt = $db->prepare($ruleSql);
    $stmt->execute();
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    $actualPoints = $rule ? $rule['points'] : $points;

    // 更新用户积分
    $userPointsSql = "INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE total_points = total_points + ?, accumulated_points = accumulated_points + ?";
    $stmt = $db->prepare($userPointsSql);
    $stmt->execute([$userId, $actualPoints, $actualPoints, $actualPoints, $actualPoints]);

    // 获取最新余额
    $balanceSql = "SELECT total_points FROM user_points WHERE user_id = ?";
    $stmt = $db->prepare($balanceSql);
    $stmt->execute([$userId]);
    $balance = $stmt->fetchColumn();

    // 记录积分变动
    $ruleId = $rule ? $rule['id'] : 0;
    $recordSql = "INSERT INTO points_records (user_id, rule_id, points, balance, type, source, source_id, description)
                   VALUES (?, ?, ?, ?, 'earn', 'drill', ?, '完成演练任务')";
    $stmt = $db->prepare($recordSql);
    $stmt->execute([$userId, $ruleId, $actualPoints, $balance, $templateId]);
}
