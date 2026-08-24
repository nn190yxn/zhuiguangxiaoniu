<?php
/** Training card API. */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $userId = getCurrentUserId();
    $context = requireTrainingAccessContext();
    if ($userId !== (int)$context['user_id']) { jsonResponse(401, '请先登录'); }
    $db = getDB();
    $action = $_GET['action'] ?? 'list';
    switch ($action) {
        case 'list': listCards($db, $context); break;
        case 'get': getCard($db, $context); break;
        case 'submit': submitAnswer($db, $context); break;
        case 'reset': resetCard($db, $context); break;
        default: jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    error_log('training-cards error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

function listCards($db, array $context) {
    $moduleId = (int)($_GET['module_id'] ?? 0);
    $access = getTrainingModuleAccessSql($context, 'tm');
    $sql = "SELECT tc.*, tm.module_name, tm.module_code, up.status my_status, up.score my_score, up.best_score my_best_score
            FROM training_cards tc JOIN training_modules tm ON tc.module_id = tm.id
            LEFT JOIN user_progress up ON tc.id = up.card_id AND up.user_id = ?
            WHERE tc.status = 1 AND tm.status = 1 AND {$access['sql']}";
    $params = array_merge([$context['user_id']], $access['params']);
    if ($moduleId > 0) { $sql .= ' AND tc.module_id = ?'; $params[] = $moduleId; }
    $sql .= $moduleId > 0 ? ' ORDER BY tc.card_code' : ' ORDER BY tm.sort_order, tc.card_code';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    jsonResponse(0, 'success', ['cards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/** Load only enabled cards/modules, then enforce the owning module policy. */
function loadAuthorizedTrainingCard($db, array $context, $cardId) {
    $stmt = $db->prepare('SELECT tc.*, tm.module_name, tm.module_code, tm.role_code module_role_code, tm.status module_status FROM training_cards tc JOIN training_modules tm ON tc.module_id = tm.id WHERE tc.id = ? AND tc.status = 1 AND tm.status = 1');
    $stmt->execute([(int)$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) { jsonResponse(404, '培训资源不存在'); }
    requireTrainingModuleAccess($context, ['status' => $card['module_status'], 'role_code' => $card['module_role_code']]);
    return $card;
}

function getCard($db, array $context) {
    $cardId = (int)($_GET['id'] ?? 0);
    if ($cardId <= 0) { jsonResponse(1, '缺少卡片ID'); }
    $card = loadAuthorizedTrainingCard($db, $context, $cardId);
    $stmt = $db->prepare('SELECT * FROM user_progress WHERE user_id = ? AND card_id = ?');
    $stmt->execute([$context['user_id'], $cardId]);
    $card['my_progress'] = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($card['options']) { $card['options'] = json_decode($card['options'], true); }
    unset($card['module_role_code'], $card['module_status']);
    jsonResponse(0, 'success', $card);
}

function submitAnswer($db, array $context) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $cardId = (int)($data['card_id'] ?? 0);
    $answer = $data['answer'] ?? '';
    $timeSpent = (int)($data['time_spent'] ?? 0);
    if ($cardId <= 0) { jsonResponse(1, '缺少卡片ID'); }
    $card = loadAuthorizedTrainingCard($db, $context, $cardId);

    $score = 0; $isCorrect = false; $feedback = '';
    if ($card['card_type'] === 'K' || $card['card_type'] === 'S') {
        if (!empty($answer) || isset($data['completed'])) { $score = 100; $isCorrect = true; $feedback = '学习完成！'; }
    } elseif ($card['card_type'] === 'D') {
        $feedback = '演练已记录，待AI分析评分';
    } else {
        $standard = strtolower(trim($card['standard_answer'] ?? ''));
        if ($standard && strtolower(trim((string)$answer)) === $standard) { $score = 100; $isCorrect = true; $feedback = '回答正确！'; }
        elseif ($standard) { $feedback = '回答错误，正确答案是：' . $card['standard_answer']; }
    }

    $stmt = $db->prepare('SELECT id, attempts, best_score FROM user_progress WHERE user_id = ? AND card_id = ?');
    $stmt->execute([$context['user_id'], $cardId]); $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = $isCorrect ? 'passed' : ($score >= 60 ? 'completed' : 'failed');
    if ($existing) {
        $stmt = $db->prepare('UPDATE user_progress SET score = ?, best_score = ?, attempts = ?, time_spent = time_spent + ?, answers = ?, feedback = ?, status = ?, completed_at = NOW() WHERE id = ?');
        $stmt->execute([$score, max((int)$existing['best_score'], $score), (int)$existing['attempts'] + 1, $timeSpent, json_encode($answer), $feedback, $status, $existing['id']]);
    } else {
        $stmt = $db->prepare('INSERT INTO user_progress (user_id, module_id, card_id, score, best_score, attempts, time_spent, answers, feedback, status, completed_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())');
        $stmt->execute([$context['user_id'], $card['module_id'], $cardId, $score, $score, $timeSpent, json_encode($answer), $feedback, $status]);
    }
    jsonResponse(0, 'success', ['card_id'=>$cardId,'score'=>$score,'is_correct'=>$isCorrect,'feedback'=>$feedback,'standard_answer'=>$card['standard_answer'],'tips'=>$card['tips']]);
}

function resetCard($db, array $context) {
    $cardId = (int)($_GET['id'] ?? 0);
    if ($cardId <= 0) { jsonResponse(1, '缺少卡片ID'); }
    loadAuthorizedTrainingCard($db, $context, $cardId);
    $stmt = $db->prepare('DELETE FROM user_progress WHERE user_id = ? AND card_id = ?');
    $stmt->execute([$context['user_id'], $cardId]);
    jsonResponse(0, '重置成功');
}
