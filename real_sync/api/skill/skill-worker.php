<?php
/**
 * 录音复盘后台处理 Worker
 * 由 cron 每分钟执行一次：* * * * * php /www/wwwroot/122.51.223.46/api/skill/skill-worker.php
 *
 * 功能：处理 pending/transcribing/analyzing 状态的记录
 */

require_once __DIR__ . '/../config.php';

$sceneMap = [
    'new_sale' => [
        'name' => '追光小牛新签复盘',
        'skill_dir' => '/www/wwwroot/122.51.223.46/skills/追光小牛新签复盘/',
    ],
    'renewal' => [
        'name' => '追光小牛续费复盘',
        'skill_dir' => '/www/wwwroot/122.51.223.46/skills/追光小牛续费复盘/',
    ],
    'assessment' => [
        'name' => '追光小牛体测解读复盘',
        'skill_dir' => '/www/wwwroot/122.51.223.46/skills/追光小牛体测解读复盘/',
    ],
];

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // 获取待处理记录（按创建时间排序，每次处理 1 条）
    $stmt = $pdo->prepare("SELECT id, scene_type, recording_url FROM skill_review_records
        WHERE status IN ('pending', 'transcribing', 'analyzing')
        ORDER BY created_at ASC
        LIMIT 1");
    $stmt->execute();
    $record = $stmt->fetch();

    if (!$record) {
        exit(0); // 没有待处理记录
    }

    $recordId = (int)$record['id'];
    $sceneType = $record['scene_type'];
    $recordingUrl = $record['recording_url'];
    $savePath = '/www/wwwroot/122.51.223.46' . $recordingUrl;

    if (!file_exists($savePath)) {
        updateStatusPDO($pdo, $recordId, 'failed', '录音文件不存在');
        echo "[$recordId] 文件不存在: $savePath\n";
        exit(0);
    }

    if (!isset($sceneMap[$sceneType])) {
        updateStatusPDO($pdo, $recordId, 'failed', '未知的场景类型: ' . $sceneType);
        exit(0);
    }

    $status = getStatus($pdo, $recordId);

    if ($status === 'pending') {
        updateStatusPDO($pdo, $recordId, 'transcribing', '开始语音转文字...');
        echo "[$recordId] 开始语音转文字...\n";

        $transcript = transcribeAudio($savePath, $pdo);

        if (empty($transcript)) {
            updateStatusPDO($pdo, $recordId, 'failed', '语音转文字失败，未获取到文本');
            echo "[$recordId] 语音转文字失败\n";
            exit(0);
        }

        echo "[$recordId] 语音转文字完成，文本长度: " . mb_strlen($transcript, 'UTF-8') . " 字符\n";

        $stmt = $pdo->prepare("UPDATE skill_review_records SET transcript_text = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$transcript, $recordId]);
    }

    $status = getStatus($pdo, $recordId);

    if ($status === 'transcribing') {
        $stmt = $pdo->prepare("UPDATE skill_review_records SET status = 'analyzing', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$recordId]);
        $status = 'analyzing';
    }

    if ($status === 'analyzing') {
        echo "[$recordId] 开始 AI 分析...\n";
        updateStatusPDO($pdo, $recordId, 'analyzing', 'AI 正在分析录音内容...');

        $skillContent = readSkillContent($sceneMap[$sceneType]['skill_dir']);
        if (empty($skillContent)) {
            updateStatusPDO($pdo, $recordId, 'failed', '复盘标准文件不存在');
            exit(0);
        }

        $stmt = $pdo->prepare("SELECT transcript_text FROM skill_review_records WHERE id = ?");
        $stmt->execute([$recordId]);
        $transcript = $stmt->fetchColumn();

        if (empty($transcript)) {
            updateStatusPDO($pdo, $recordId, 'failed', '转写文本为空');
            exit(0);
        }

        $report = analyzeWithAI($transcript, $skillContent, $sceneMap[$sceneType]['name'], $pdo);

        if (empty($report)) {
            updateStatusPDO($pdo, $recordId, 'failed', 'AI 分析失败');
            exit(0);
        }

        $score = extractScore($report);
        $level = extractLevel($report);

        echo "[$recordId] AI 分析完成，分数: $score, 等级: $level\n";

        $stmt = $pdo->prepare("UPDATE skill_review_records
            SET status = 'completed', ai_report = ?, ai_score = ?, ai_level = ?, updated_at = NOW()
            WHERE id = ?");
        $stmt->execute([$report, $score, $level, $recordId]);

        echo "[$recordId] 复盘完成\n";
    }

} catch (Exception $e) {
    error_log('[skill.worker] Error: ' . $e->getMessage());
    exit(1);
}

// ===== 辅助函数 =====

function getStatus($pdo, $recordId) {
    $stmt = $pdo->prepare("SELECT status FROM skill_review_records WHERE id = ?");
    $stmt->execute([$recordId]);
    return $stmt->fetchColumn();
}

function transcribeAudio($audioFile, $pdo) {
    $settings = loadAISettings($pdo);

    if (!empty($settings['zhipu_api_key'])) {
        $result = transcribeWithZhipu($audioFile, $settings['zhipu_api_key']);
        if ($result) return $result;
    }

    if (!empty($settings['doubao_api_key'])) {
        $result = transcribeWithDoubao($audioFile, $settings['doubao_api_key']);
        if ($result) return $result;
    }

    return null;
}

function transcribeWithZhipu($audioFile, $apiKey) {
    $ext = strtolower(pathinfo($audioFile, PATHINFO_EXTENSION));
    $mimeType = getAudioMimeType($ext);

    $boundary = '----WebKitFormBoundary' . md5(uniqid());
    $fileContent = file_get_contents($audioFile);
    $filename = basename($audioFile);

    $body = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mimeType}\r\n\r\n";
    $body .= $fileContent . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
    $body .= "glm-4-voice\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
    $body .= "zh-CN\r\n";
    $body .= "--{$boundary}--\r\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://open.bigmodel.cn/api/paas/v4/audio/transcriptions',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['text']) && !empty($data['text'])) {
            return $data['text'];
        }
    }

    error_log("[skill.asr] Zhipu ASR failed (HTTP $httpCode): " . ($curlError ?: $response));
    return null;
}

function transcribeWithDoubao($audioFile, $apiKey) {
    $ext = strtolower(pathinfo($audioFile, PATHINFO_EXTENSION));
    $mimeType = getAudioMimeType($ext);

    $boundary = '----WebKitFormBoundary' . md5(uniqid());
    $fileContent = file_get_contents($audioFile);
    $filename = basename($audioFile);

    $body = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mimeType}\r\n\r\n";
    $body .= $fileContent . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
    $body .= "doubao-1.5-pro-32k\r\n";
    $body .= "--{$boundary}--\r\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://ark.cn-beijing.volces.com/api/v3/audio/transcriptions',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['text']) && !empty($data['text'])) {
            return $data['text'];
        }
    }

    error_log("[skill.asr] Doubao ASR failed (HTTP $httpCode): " . substr($response, 0, 500));
    return null;
}

function analyzeWithAI($transcript, $skillContent, $sceneName, $pdo) {
    $settings = loadAISettings($pdo);

    $apiKey = $settings['deepseek_api_key'] ?? '';
    $model = 'deepseek-chat';
    $apiUrl = 'https://api.deepseek.com/v1/chat/completions';

    if (empty($apiKey)) {
        $apiKey = $settings['doubao_api_key'] ?? '';
        $model = $settings['doubao_model'] ?? 'doubao-pro-32k';
        $apiUrl = 'https://ark.cn-beijing.volces.com/api/v3/chat/completions';
    }

    if (empty($apiKey)) {
        throw new Exception('没有可用的 AI 服务配置');
    }

    $systemPrompt = "你是{$sceneName}的专业评估专家。请严格按照以下复盘标准对销售录音转写文本进行分析评分。\n\n{$skillContent}";

    $userPrompt = "请对以下录音转写文本进行复盘分析：\n\n--- 录音转写文本开始 ---\n{$transcript}\n--- 录音转写文本结束 ---\n\n请按照复盘标准输出完整的分析报告，必须包含总分（0-100）和等级（优秀/良好/合格/不合格）。";

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.3,
        'max_tokens' => 4000,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
    }

    throw new Exception("AI 分析失败 (HTTP $httpCode): " . substr($response, 0, 500));
}

function extractScore($report) {
    if (preg_match('/总分[：:]\s*(\d+)/', $report, $matches)) return (int)$matches[1];
    if (preg_match('/(\d{1,3})\s*分\s*\/\s*100/', $report, $matches)) return (int)$matches[1];
    if (preg_match('/得分[：:]\s*(\d+)/', $report, $matches)) return (int)$matches[1];
    if (preg_match('/评分[：:]\s*(\d+)/', $report, $matches)) return (int)$matches[1];
    return 0;
}

function extractLevel($report) {
    if (preg_match('/等级[：:]\s*(优秀|良好|合格|不合格)/', $report, $matches)) return $matches[1];
    if (preg_match('/(优秀|良好|合格|不合格)/', $report, $matches)) return $matches[1];
    return '';
}

function loadAISettings($pdo) {
    $settings = [
        'deepseek_api_key' => '',
        'zhipu_api_key' => '',
        'doubao_api_key' => '',
        'doubao_model' => 'doubao-pro-32k',
    ];

    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM ai_settings');
        foreach ($stmt->fetchAll() as $row) {
            $key = (string)($row['setting_key'] ?? '');
            $value = trim((string)($row['setting_value'] ?? ''));
            if (array_key_exists($key, $settings) && $value !== '') {
                $settings[$key] = $value;
            }
        }
    } catch (Exception $e) {
        error_log('[skill.asr] Settings load failed: ' . $e->getMessage());
    }

    return $settings;
}

function readSkillContent($skillDir) {
    $skillFile = $skillDir . 'SKILL.md';
    if (file_exists($skillFile)) return file_get_contents($skillFile);
    if (is_dir($skillDir)) {
        $files = glob($skillDir . '*.md');
        if (!empty($files)) {
            $content = '';
            foreach ($files as $file) $content .= file_get_contents($file) . "\n\n";
            return $content;
        }
    }
    return '';
}

function getAudioMimeType($ext) {
    $map = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'webm' => 'audio/webm', 'aac' => 'audio/aac'];
    return $map[$ext] ?? 'audio/mpeg';
}

function updateStatusPDO($pdo, $recordId, $status, $error = '') {
    if ($status === 'failed') {
        $stmt = $pdo->prepare("UPDATE skill_review_records SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $error, $recordId]);
    } else {
        $stmt = $pdo->prepare("UPDATE skill_review_records SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $recordId]);
    }
}
