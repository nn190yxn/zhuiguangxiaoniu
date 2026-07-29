<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillAiAdapter.php';
require_once __DIR__ . '/DrillAttemptStateMachine.php';

final class DrillSpeakerMappingService
{
    public function __construct(private PDO $pdo, private DrillAiAdapter $ai)
    {
    }

    public function mapAndPersist(int $attemptId, int $actorStaffId, array $transcript, DateTimeImmutable $now): array
    {
        $mapped = $this->ai->mapSpeakers(['attempt_id' => $attemptId, 'transcript' => $transcript]);
        $segments = (array) ($mapped['payload']['segments'] ?? []);
        if ($segments === []) {
            throw new DrillAiRetryableException('说话人映射未返回分段。');
        }
        $this->pdo->beginTransaction();
        try {
            $attempt = $this->lockAttempt($attemptId);
            $participants = $this->participants($attemptId);
            $requiresConfirmation = false;
            foreach ($segments as $row) {
                $key = (string) ($row['speaker_key'] ?? '');
                $confidence = (float) ($row['confidence'] ?? 0);
                if (!isset($participants[$key]) || $confidence < 0.8) {
                    $requiresConfirmation = true;
                    continue;
                }
                $coachSupplement = (bool) ($row['is_coach_supplement'] ?? false);
                $statement = $this->pdo->prepare('INSERT INTO drill_transcript_segments (attempt_id, transcript_id, segment_no, speaker_key, role_code, starts_ms, ends_ms, content, mapping_confidence, mapping_status, is_coach_supplement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE speaker_key = VALUES(speaker_key), role_code = VALUES(role_code), content = VALUES(content), mapping_confidence = VALUES(mapping_confidence), mapping_status = VALUES(mapping_status), is_coach_supplement = VALUES(is_coach_supplement)');
                $statement->execute([$attemptId, (int) ($row['transcript_id'] ?? 0), (int) ($row['segment_no'] ?? 0), $key, (string) ($row['role_code'] ?? $participants[$key]['role_code']), max(0, (int) ($row['starts_ms'] ?? 0)), max(0, (int) ($row['ends_ms'] ?? 0)), trim((string) ($row['content'] ?? '')), $confidence, 'mapped', $coachSupplement ? 1 : 0]);
            }
            $status = $requiresConfirmation ? DrillAttemptStateMachine::transition((string) $attempt['status'], 'require_speaker_confirmation') : (string) $attempt['status'];
            $this->pdo->prepare('UPDATE drill_attempts SET status = ?, status_version = status_version + 1 WHERE id = ?')->execute([$status, $attemptId]);
            $this->pdo->commit();
            return ['attempt_id' => $attemptId, 'status' => $status, 'confirmation_required' => $requiresConfirmation, 'metadata' => $mapped['metadata']];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function confirmSubjects(int $attemptId, int $actorStaffId, array $subjects, DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $attempt = $this->lockAttempt($attemptId);
            foreach ($subjects as $subject) {
                $this->pdo->prepare("UPDATE drill_attempt_score_subjects SET status = 'confirmed', confirmed_by = ?, confirmed_at = ? WHERE attempt_id = ? AND participant_key = ?")->execute([$actorStaffId, $now->format('Y-m-d H:i:s'), $attemptId, (string) $subject]);
            }
            if ((string) $attempt['status'] === 'speaker_confirmation_required') {
                $this->pdo->prepare("UPDATE drill_attempts SET status = 'evaluating', status_version = status_version + 1 WHERE id = ?")->execute([$attemptId]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function lockAttempt(int $attemptId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status FROM drill_attempts WHERE id = ? FOR UPDATE');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new DomainException('演练实例不存在。');
        }
        return $attempt;
    }

    private function participants(int $attemptId): array
    {
        $stmt = $this->pdo->prepare('SELECT participant_key, role_code FROM drill_attempt_participants WHERE attempt_id = ?');
        $stmt->execute([$attemptId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'participant_key');
    }
}
