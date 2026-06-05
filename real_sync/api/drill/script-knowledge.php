<?php
/**
 * 话术知识库API
 * GET /api/drill/script-knowledge.php?dimension=qa
 * GET /api/drill/script-knowledge.php?dimension=knowledge
 * GET /api/drill/script-knowledge.php?dimension=feedback
 * GET /api/drill/script-knowledge.php?dimension=deal
 * GET /api/drill/script-knowledge.php?action=list - 获取所有维度
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(1, '不支持的请求方法');
}

try {
    $db = getDB();

    $action = isset($_GET['action']) ? trim($_GET['action']) : 'list';
    $dimension = isset($_GET['dimension']) ? trim($_GET['dimension']) : '';

    if ($action === 'list') {
        // 获取所有维度列表
        $sql = "SELECT id, dimension_code, dimension_name, description, weight
                FROM script_dimensions WHERE status = 1 ORDER BY id";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $dimensions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(0, 'success', [
            'dimensions' => $dimensions
        ]);

    } elseif ($action === 'list_by_dimension' || !empty($dimension)) {
        // 获取指定维度的话术列表
        if (empty($dimension)) {
            jsonResponse(1, '缺少维度参数');
        }

        // 获取维度信息
        $dimSql = "SELECT * FROM script_dimensions WHERE dimension_code = ? AND status = 1";
        $stmt = $db->prepare($dimSql);
        $stmt->execute([$dimension]);
        $dimensionInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dimensionInfo) {
            jsonResponse(1, '无效的维度');
        }

        // 获取该维度的所有话术
        $scriptSql = "SELECT id, scene_code, scene_name, keywords, standard_script,
                             customer_intent_signals, tips
                      FROM script_knowledge
                      WHERE dimension_id = ? AND status = 1
                      ORDER BY sort_order, id";
        $stmt = $db->prepare($scriptSql);
        $stmt->execute([$dimensionInfo['id']]);
        $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 处理JSON字段
        foreach ($scripts as &$script) {
            $script['keywords'] = !empty($script['keywords']) ? json_decode($script['keywords'], true) ?: [] : [];
            $script['customer_intent_signals'] = !empty($script['customer_intent_signals']) ? json_decode($script['customer_intent_signals'], true) ?: [] : [];
        }

        jsonResponse(0, 'success', [
            'dimension' => [
                'id' => $dimensionInfo['id'],
                'code' => $dimensionInfo['dimension_code'],
                'name' => $dimensionInfo['dimension_name'],
                'description' => $dimensionInfo['description']
            ],
            'scripts' => $scripts
        ]);

    } elseif ($action === 'detail') {
        // 获取指定话术详情
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
            jsonResponse(1, '缺少话术ID');
        }

        $sql = "SELECT sk.*, sd.dimension_code, sd.dimension_name
                FROM script_knowledge sk
                JOIN script_dimensions sd ON sk.dimension_id = sd.id
                WHERE sk.id = ? AND sk.status = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $script = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$script) {
            jsonResponse(1, '话术不存在');
        }

        $script['keywords'] = json_decode($script['keywords'] ?? '', true) ?: [];
        $script['customer_intent_signals'] = json_decode($script['customer_intent_signals'] ?? '', true) ?: [];

        jsonResponse(0, 'success', $script);

    } elseif ($action === 'search') {
        // 搜索话术
        $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
        if (empty($keyword)) {
            jsonResponse(1, '缺少搜索关键词');
        }

        $sql = "SELECT sk.*, sd.dimension_code, sd.dimension_name
                FROM script_knowledge sk
                JOIN script_dimensions sd ON sk.dimension_id = sd.id
                WHERE sk.status = 1 AND (
                    sk.scene_name LIKE ? OR
                    sk.standard_script LIKE ? OR
                    sk.keywords LIKE ?
                )
                ORDER BY sk.dimension_id, sk.sort_order
                LIMIT 50";
        $likeKeyword = '%' . $keyword . '%';
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeKeyword, $likeKeyword, $likeKeyword]);
        $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($scripts as &$script) {
            $script['keywords'] = json_decode($script['keywords'], true) ?: [];
        }

        jsonResponse(0, 'success', [
            'keyword' => $keyword,
            'count' => count($scripts),
            'scripts' => $scripts
        ]);

    } elseif ($action === 'my_records') {
        // 获取当前用户的话术分析记录
        $userId = getCurrentUserId();
        if (!$userId) {
            jsonResponse(401, '请先登录');
        }

        $dimension = isset($_GET['dimension']) ? trim($_GET['dimension']) : '';
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $pageSize = min(50, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10));
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE sar.user_id = ?";
        $params = [$userId];

        if (!empty($dimension)) {
            $where .= " AND sd.dimension_code = ?";
            $params[] = $dimension;
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM script_analysis_records sar
                     JOIN script_dimensions sd ON sar.dimension_id = sd.id
                     {$where}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // 获取列表
        $listSql = "SELECT sar.id, sar.dimension_id, sar.total_score, sar.level,
                           sar.customer_intent, sar.ai_feedback, sar.created_at,
                           sd.dimension_code, sd.dimension_name
                    FROM script_analysis_records sar
                    JOIN script_dimensions sd ON sar.dimension_id = sd.id
                    {$where}
                    ORDER BY sar.created_at DESC
                    LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $offset;
        $stmt = $db->prepare($listSql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(0, 'success', [
            'records' => $records,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => ceil($total / $pageSize)
            ]
        ]);

    } elseif ($action === 'my_feedback_detail') {
        // 获取当前用户的话术分析记录详情
        $userId = getCurrentUserId();
        if (!$userId) {
            jsonResponse(401, '请先登录');
        }

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) {
            jsonResponse(1, '缺少记录ID');
        }

        $sql = "SELECT sar.*, sd.dimension_code, sd.dimension_name
                FROM script_analysis_records sar
                JOIN script_dimensions sd ON sar.dimension_id = sd.id
                WHERE sar.id = ? AND sar.user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id, $userId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            jsonResponse(1, '记录不存在');
        }

        // 获取关联的话术信息（如果存在）
        $scriptInfo = null;
        if ($record['script_id']) {
            $scriptSql = "SELECT scene_name, standard_script, tips FROM script_knowledge WHERE id = ?";
            $stmt = $db->prepare($scriptSql);
            $stmt->execute([$record['script_id']]);
            $scriptInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 格式化维度评分
        $dimensionScores = json_decode($record['dialogue_analysis'], true) ?: [];
        $formattedScores = [];
        $totalWeight = 0;
        foreach ($dimensionScores as $code => $score) {
            $formattedScores[] = [
                'code' => $code,
                'name' => getDimensionScoreName($code),
                'score' => (int)$score,
                'weight' => 1.0,
                'weighted_score' => (int)$score
            ];
        }

        // 格式化返回数据
        $formattedRecord = [
            'id' => $record['id'],
            'dimension_code' => $record['dimension_code'],
            'dimension_name' => $record['dimension_name'],
            'total_score' => (int)$record['total_score'],
            'level' => $record['level'],
            'transcribed_text' => $record['transcribed_text'],
            'dimension_scores' => $formattedScores,
            'ai_feedback' => $record['ai_feedback'],
            'suggestions' => json_decode($record['suggestions'], true) ?: [],
            'customer_intent' => $record['customer_intent'],
            'intent_signals' => json_decode($record['intent_signals'], true) ?: [],
            'flow_analysis' => json_decode($record['flow_analysis'], true) ?: [],
            'missing_steps' => json_decode($record['missing_steps'], true) ?: [],
            'audio_url' => $record['audio_url'],
            'audio_duration' => $record['audio_duration'] ?: 0,
            'scene' => $scriptInfo ? ($scriptInfo['scene_name'] ?: '话术演练') : '话术演练',
            'script_content' => $scriptInfo ? ($scriptInfo['standard_script'] ?: '') : '',
            'created_at' => $record['created_at']
        ];

        jsonResponse(0, 'success', $formattedRecord);

    } else {
        jsonResponse(1, '未知操作');
    }

} catch (Exception $e) {
    error_log('script-knowledge error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

/**
 * 获取维度评分名称
 */
function getDimensionScoreName($code) {
    $names = [
        // 问答话术维度
        'professional' => '专业性',
        'logical' => '逻辑性',
        'affinity' => '亲和力',
        'completeness' => '完整性',
        // 知识讲解维度
        'accuracy' => '准确性',
        'clarity' => '通俗性',
        'vividness' => '生动性',
        'structure' => '结构清晰',
        // 反馈维度
        'warmth' => '温暖度',
        'practicality' => '实用性',
        // 谈单维度
        'flow_completeness' => '流程完整性',
        'flow_sequence' => '流程逻辑',
        'customer_intent_judgment' => '意向判断',
        'key_points_coverage' => '要点覆盖',
        'objection_handling' => '异议处理',
        // 旧维度（兼容性）
        'fluency' => '流畅度',
        'pronunciation' => '发音清晰',
        'keywords' => '关键词准确',
        'tone' => '语气亲和',
        'closing' => '促成技巧'
    ];
    return $names[$code] ?? $code;
}
