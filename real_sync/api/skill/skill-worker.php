<?php
declare(strict_types=1);

if (!defined('PLATFORM_SKILL_FUNCTIONS_ONLY')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('forbidden');
    }

    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../kernel/bootstrap.php';
    require_once __DIR__ . '/../platform/JobQueue.php';

    try {
        $pdo = getDB();
        platformRequireMigrationReadiness($pdo, ['202607310008', '202607310010', '202607310012']);
        $stmt = $pdo->query("SELECT id FROM skill_review_records WHERE status IN ('pending', 'transcribing', 'analyzing') ORDER BY created_at ASC LIMIT 1");
        $recordId = (int)($stmt->fetchColumn() ?: 0);
        if ($recordId === 0) {
            exit(0);
        }

        $pdo->beginTransaction();
        try {
            $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo));
            $job = $queue->enqueue(
                'skill.review.process',
                'skill_review_record',
                (string)$recordId,
                hash('sha256', 'skill.review.process:' . $recordId),
                ['record_id' => $recordId],
                20,
                3
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        fwrite(STDOUT, '[skill.worker] queued job_id=' . (int)$job['id'] . ' record_id=' . $recordId . PHP_EOL);
        exit(0);
    } catch (Throwable $error) {
        error_log('[skill.worker] Error: ' . $error->getMessage());
        fwrite(STDERR, '[skill.worker] failed: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

function getStatus($pdo, $recordId) {
    $stmt = $pdo->prepare("SELECT status FROM skill_review_records WHERE id = ?");
    $stmt->execute([$recordId]);
    return $stmt->fetchColumn();
}

function transcribeAudio($audioFile, $pdo, ?callable $checkpoint = null) {
    $settings = loadAISettings($pdo);
    
    if (!empty($settings['zhipu_api_key'])) {
        $result = transcribeWithZhipu($audioFile, $settings['zhipu_api_key']);
        if ($result) return $result;
        if ($checkpoint !== null) $checkpoint();
    }
    
    if (!empty($settings['doubao_api_key'])) {
        $result = transcribeWithDoubao($audioFile, $settings['doubao_api_key']);
        if ($result) return $result;
        if ($checkpoint !== null) $checkpoint();
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
