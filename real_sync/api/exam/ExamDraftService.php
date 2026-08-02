<?php
declare(strict_types=1);

final class ExamDraftService
{
    public function __construct(private PDO $db)
    {
    }

    public function save(int $userId, array $input): array
    {
        $examType = trim((string)($input['exam_type'] ?? ''));
        $sourceExamId = (int)($input['source_exam_id'] ?? 0);
        $selectedExamId = (int)($input['selected_exam_id'] ?? 0);
        $duration = max(0, (int)($input['duration'] ?? 0));
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $paperCode = strtoupper(trim((string)($input['paper_code'] ?? '')));
        if ($examType === '') {
            throw new PlatformApiException(400, 'exam_type_required', '缺少考试类型');
        }
        if ($sourceExamId <= 0 || $selectedExamId <= 0) {
            throw new PlatformApiException(400, 'exam_identity_required', '缺少试卷标识');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT id, answers FROM exam_records WHERE user_id = ? AND exam_type = ? "
                . "AND status = 'in_progress' AND module_id = ? "
                . 'AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$userId, $examType, $sourceExamId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $storedAnswers = $existing ? json_decode((string)($existing['answers'] ?? ''), true) : [];
            $currentVersion = max(0, (int)($storedAnswers['__meta']['state_version'] ?? 0));
            $expectedVersion = array_key_exists('state_version', $input)
                ? (int)$input['state_version']
                : $currentVersion;
            $nextVersion = PlatformStateVersion::advance($currentVersion, $expectedVersion, [
                'resource' => 'exam_draft',
                'source_exam_id' => $sourceExamId,
            ]);

            $answers['__meta'] = [
                'source_exam_id' => $sourceExamId,
                'selected_exam_id' => $selectedExamId,
                'paper_code' => in_array($paperCode, ['A', 'B'], true) ? $paperCode : 'A',
                'state_version' => $nextVersion,
            ];
            $encodedAnswers = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($existing) {
                $id = (int)$existing['id'];
                try {
                    $stmt = $this->db->prepare(
                        'UPDATE exam_records SET answers = ?, duration = ?, updated_at = NOW() WHERE id = ?'
                    );
                    $stmt->execute([$encodedAnswers, $duration, $id]);
                } catch (PDOException $error) {
                    if ((string)$error->getCode() !== '42S22' && !str_contains($error->getMessage(), 'updated_at')) {
                        throw $error;
                    }
                    $stmt = $this->db->prepare('UPDATE exam_records SET answers = ?, duration = ? WHERE id = ?');
                    $stmt->execute([$encodedAnswers, $duration, $id]);
                }
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO exam_records (user_id, module_id, exam_type, status, answers, duration, created_at, updated_at) "
                    . "VALUES (?, ?, ?, 'in_progress', ?, ?, NOW(), NOW())"
                );
                $stmt->execute([$userId, $sourceExamId, $examType, $encodedAnswers, $duration]);
                $id = (int)$this->db->lastInsertId();
            }

            $this->db->commit();
            return ['id' => $id, 'message' => '保存成功', 'state_version' => $nextVersion];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
