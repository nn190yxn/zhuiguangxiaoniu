<?php
/**
 * 训练卡片API
 * POST /api/drill/training-cards.php
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = getDB();
    $userId = getCurrentUserId();

    $action = isset($_GET['action']) ? $_GET['action'] : 'list';

    if (!$userId && !in_array($action, ['list', 'get'], true)) {
        jsonResponse(401, '请先登录');
    }

    switch ($action) {
        case 'list':
            listCards($db, $userId);
            break;
        case 'get':
            getCard($db, $userId);
            break;
        case 'submit':
            submitAnswer($db, $userId);
            break;
        case 'reset':
            resetCard($db, $userId);
            break;
        default:
            jsonResponse(1, '未知操作');
    }

} catch (Exception $e) {
    error_log('training-cards error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

function listCards($db, $userId) {
    $moduleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

    if ($moduleId > 0) {
        $stmt = $db->prepare("
            SELECT tc.*, tm.module_name, tm.module_code,
                   up.status as my_status, up.score as my_score, up.best_score as my_best_score
            FROM training_cards tc
            JOIN training_modules tm ON tc.module_id = tm.id
            LEFT JOIN user_progress up ON tc.id = up.card_id AND up.user_id = ?
            WHERE tc.module_id = ?
            ORDER BY tc.card_code
        ");
        $stmt->execute([$userId, $moduleId]);
    } else {
        $stmt = $db->prepare("
            SELECT tc.*, tm.module_name, tm.module_code,
                   up.status as my_status, up.score as my_score, up.best_score as my_best_score
            FROM training_cards tc
            JOIN training_modules tm ON tc.module_id = tm.id
            LEFT JOIN user_progress up ON tc.id = up.card_id AND up.user_id = ?
            ORDER BY tm.sort_order, tc.card_code
        ");
        $stmt->execute([$userId]);
    }

    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(0, 'success', ['cards' => $cards]);
}

function getCard($db, $userId) {
    $cardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($cardId <= 0) {
        jsonResponse(1, '缺少卡片ID');
    }

    $stmt = $db->prepare("
        SELECT tc.*, tm.module_name, tm.module_code
        FROM training_cards tc
        JOIN training_modules tm ON tc.module_id = tm.id
        WHERE tc.id = ?
    ");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        jsonResponse(1, '卡片不存在');
    }

    // 获取用户进度
    $progressStmt = $db->prepare("SELECT * FROM user_progress WHERE user_id = ? AND card_id = ?");
    $progressStmt->execute([$userId, $cardId]);
    $card['my_progress'] = $progressStmt->fetch(PDO::FETCH_ASSOC);

    // 处理选项
    if ($card['options']) {
        $card['options'] = json_decode($card['options'], true);
    }

    // 根据卡片类型决定返回内容
    // K-知识卡: 返回内容供学习
    // S-话术卡: 返回场景和参考话术
    // D-演练卡: 返回演练题目
    // C-通关卡: 返回通关项

    jsonResponse(0, 'success', $card);
}

function submitAnswer($db, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);

    $cardId = isset($data['card_id']) ? (int)$data['card_id'] : 0;
    $answer = isset($data['answer']) ? $data['answer'] : '';
    $timeSpent = isset($data['time_spent']) ? (int)$data['time_spent'] : 0;

    if ($cardId <= 0) {
        jsonResponse(1, '缺少卡片ID');
    }

    // 获取卡片信息
    $stmt = $db->prepare("SELECT * FROM training_cards WHERE id = ?");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        jsonResponse(1, '卡片不存在');
    }

    // 自动评分（简单匹配）
    $score = 0;
    $isCorrect = false;
    $feedback = '';

    if ($card['card_type'] === 'K' || $card['card_type'] === 'S') {
        // 知识卡和话术卡 - 检查是否完成学习
        if (!empty($answer) || isset($data['completed'])) {
            $score = 100;
            $isCorrect = true;
            $feedback = '学习完成！';
        }
    } else if ($card['card_type'] === 'D') {
        // 演练卡 - 标记为待评分（需要人工或AI评分）
        $score = 0;
        $feedback = '演练已记录，待AI分析评分';
    } else {
        // 通关卡 - 简单判断
        $standardAnswer = strtolower(trim($card['standard_answer'] ?? ''));
        $userAnswer = strtolower(trim($answer));

        if ($standardAnswer && $userAnswer === $standardAnswer) {
            $score = 100;
            $isCorrect = true;
            $feedback = '回答正确！';
        } else if ($standardAnswer) {
            $score = 0;
            $feedback = '回答错误，正确答案是：' . $card['standard_answer'];
        }
    }

    // 更新或创建进度记录
    $checkStmt = $db->prepare("SELECT id, attempts FROM user_progress WHERE user_id = ? AND card_id = ?");
    $checkStmt->execute([$userId, $cardId]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        $attempts = $existing['attempts'] + 1;
        $bestScore = max($existing['attempts'] > 0 ? $score : 0, $score);

        $updateStmt = $db->prepare("
            UPDATE user_progress
            SET score = ?, best_score = ?, attempts = ?, time_spent = time_spent + ?,
                answers = ?, feedback = ?, status = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([
            $score, $bestScore, $attempts, $timeSpent,
            json_encode($answer),
            $feedback,
            $isCorrect ? 'passed' : ($score >= 60 ? 'completed' : 'failed'),
            $existing['id']
        ]);
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO user_progress (user_id, module_id, card_id, score, best_score, attempts, time_spent, answers, feedback, status, completed_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([
            $userId, $card['module_id'], $cardId,
            $score, $score, $timeSpent,
            json_encode($answer), $feedback,
            $isCorrect ? 'passed' : ($score >= 60 ? 'completed' : 'failed')
        ]);
    }

    jsonResponse(0, 'success', [
        'card_id' => $cardId,
        'score' => $score,
        'is_correct' => $isCorrect,
        'feedback' => $feedback,
        'standard_answer' => $card['standard_answer'],
        'tips' => $card['tips']
    ]);
}

function resetCard($db, $userId) {
    $cardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($cardId <= 0) {
        jsonResponse(1, '缺少卡片ID');
    }

    $stmt = $db->prepare("DELETE FROM user_progress WHERE user_id = ? AND card_id = ?");
    $stmt->execute([$userId, $cardId]);

    jsonResponse(0, '重置成功');
}
