<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/JobDispatcher.php';
require_once dirname(__DIR__) . '/PrivateFileStorage.php';
if (!defined('PLATFORM_SKILL_FUNCTIONS_ONLY')) {
    define('PLATFORM_SKILL_FUNCTIONS_ONLY', true);
}
require_once dirname(__DIR__, 2) . '/skill/skill-worker.php';

final class SkillReviewJobHandler implements PlatformJobHandler
{
    private string $projectRoot;

    public function __construct(private PDO $db)
    {
        $root = realpath(dirname(__DIR__, 3));
        if ($root === false) {
            throw new RuntimeException('skill_project_root_missing');
        }
        $this->projectRoot = $root;
    }

    public function handle(PlatformJobExecutionContext $context, array $payload): array
    {
        $recordId = (int)($payload['record_id'] ?? 0);
        if ($recordId < 1) {
            throw new PlatformJobPermanentFailure('invalid_skill_record_id');
        }

        $record = $this->record($recordId);
        if ($record === null) {
            throw new PlatformJobPermanentFailure('skill_record_missing');
        }
        $status = (string)$record['status'];
        if (in_array($status, ['completed', 'failed'], true)) {
            return ['record_id' => $recordId, 'status' => $status, 'already_terminal' => true];
        }

        $scene = $this->scene((string)$record['scene_type']);
        if ($scene === null) {
            return $this->failRecord($recordId, '未知的场景类型', 'skill_scene_unknown');
        }

        if ($status === 'pending') {
            $audioFile = $this->audioFile((string)$record['recording_url']);
            if ($audioFile === null) {
                return $this->failRecord($recordId, '录音文件不存在', 'skill_recording_missing');
            }
            updateStatusPDO($this->db, $recordId, 'transcribing');
            $context->assertCurrent();
            $transcript = transcribeAudio($audioFile, $this->db, static function () use ($context): void {
                $context->heartbeatIfDue();
                $context->assertCurrent();
            });
            $context->heartbeatIfDue();
            $context->assertCurrent();
            if (!is_string($transcript) || trim($transcript) === '') {
                return $this->failRecord($recordId, '语音转文字失败，未获取到文本', 'skill_transcription_failed');
            }
            $stmt = $this->db->prepare("UPDATE skill_review_records SET transcript_text = ?, status = 'analyzing', updated_at = NOW() WHERE id = ? AND status = 'transcribing'");
            $stmt->execute([$transcript, $recordId]);
            $status = 'analyzing';
        }

        if ($status !== 'transcribing' && $status !== 'analyzing') {
            throw new PlatformJobPermanentFailure('skill_record_status_invalid');
        }
        if ($status === 'transcribing') {
            $this->db->prepare("UPDATE skill_review_records SET status = 'analyzing', updated_at = NOW() WHERE id = ? AND status = 'transcribing'")->execute([$recordId]);
        }

        $skillContent = readSkillContent($scene['skill_dir']);
        if (trim((string)$skillContent) === '') {
            return $this->failRecord($recordId, '复盘标准文件不存在', 'skill_standard_missing');
        }
        $stmt = $this->db->prepare('SELECT transcript_text FROM skill_review_records WHERE id = ?');
        $stmt->execute([$recordId]);
        $transcript = trim((string)$stmt->fetchColumn());
        if ($transcript === '') {
            return $this->failRecord($recordId, '转写文本为空', 'skill_transcript_empty');
        }

        try {
            $context->heartbeatIfDue();
            $context->assertCurrent();
            $report = analyzeWithAI($transcript, (string)$skillContent, $scene['name'], $this->db);
            $context->heartbeatIfDue();
            $context->assertCurrent();
        } catch (PlatformJobLeaseLost $error) {
            throw $error;
        } catch (Throwable $error) {
            updateStatusPDO($this->db, $recordId, 'failed', $error->getMessage());
            throw new PlatformJobPermanentFailure('skill_analysis_failed', 0, $error);
        }
        if (!is_string($report) || trim($report) === '') {
            return $this->failRecord($recordId, 'AI 分析失败', 'skill_analysis_empty');
        }

        $score = max(0, min(100, extractScore($report)));
        $level = extractLevel($report);
        $stmt = $this->db->prepare("UPDATE skill_review_records SET status = 'completed', ai_report = ?, ai_score = ?, ai_level = ?, error_message = NULL, updated_at = NOW() WHERE id = ? AND status = 'analyzing'");
        $stmt->execute([$report, $score, $level, $recordId]);
        return ['record_id' => $recordId, 'status' => 'completed', 'score' => $score, 'level' => $level];
    }

    private function record(int $recordId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, scene_type, recording_url, status FROM skill_review_records WHERE id = ? LIMIT 1');
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function scene(string $sceneType): ?array
    {
        $scenes = [
            'new_sale' => ['name' => '追光小牛新签复盘', 'directory' => '追光小牛新签复盘'],
            'renewal' => ['name' => '追光小牛续费复盘', 'directory' => '追光小牛续费复盘'],
            'assessment' => ['name' => '追光小牛体测解读复盘', 'directory' => '追光小牛体测解读复盘'],
        ];
        if (!isset($scenes[$sceneType])) {
            return null;
        }
        return $scenes[$sceneType] + ['skill_dir' => $this->projectRoot . '/skills/' . $scenes[$sceneType]['directory'] . '/'];
    }

    private function audioFile(string $recordingUrl): ?string
    {
        if (str_starts_with($recordingUrl, 'local_private:')) {
            $storageKey = substr($recordingUrl, strlen('local_private:'));
            try {
                $download = (new PlatformPrivateFileStorage())->prepareDownload(
                    [$storageKey],
                    'application/octet-stream',
                    basename($storageKey)
                );
                return isset($download['paths'][0]) ? (string)$download['paths'][0] : null;
            } catch (Throwable) {
                return null;
            }
        }
        $uploadsRoot = realpath($this->projectRoot . '/uploads');
        $candidate = realpath($this->projectRoot . '/' . ltrim($recordingUrl, '/'));
        if ($uploadsRoot === false || $candidate === false || !is_file($candidate)) {
            return null;
        }
        $prefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $prefix) ? $candidate : null;
    }

    private function failRecord(int $recordId, string $message, string $code): array
    {
        updateStatusPDO($this->db, $recordId, 'failed', $message);
        throw new PlatformJobPermanentFailure($code);
    }
}
