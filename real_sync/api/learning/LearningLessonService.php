<?php
declare(strict_types=1);

final class LearningLessonService
{
    private Closure $resourceUrl;

    public function __construct(private PDO $db, callable $resourceUrl)
    {
        $this->resourceUrl = Closure::fromCallable($resourceUrl);
    }

    public function readAndComplete(int $userId, int $lessonId): array
    {
        if ($lessonId <= 0) {
            throw new PlatformApiException(400, 'lesson_id_required', '缺少章节 ID');
        }

        $this->db->beginTransaction();
        try {
            $userLock = $this->db->prepare('SELECT ID FROM wp_users WHERE ID = ? FOR UPDATE');
            $userLock->execute([$userId]);
            if (!$userLock->fetchColumn()) {
                throw new PlatformApiException(404, 'user_not_found', '用户不存在');
            }

            $stmt = $this->db->prepare(
                'SELECT l.*, c.id AS course_id, c.title AS course_title '
                . 'FROM course_lessons l JOIN courses c ON l.course_id = c.id WHERE l.id = ?'
            );
            $stmt->execute([$lessonId]);
            $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lesson) {
                throw new PlatformApiException(404, 'lesson_not_found', '章节不存在');
            }

            $courseId = (int)$lesson['course_id'];
            $stmt = $this->db->prepare(
                'INSERT INTO user_lesson_progress (user_id, lesson_id, course_id, is_completed, completed_at) '
                . 'VALUES (?, ?, ?, 1, NOW()) '
                . 'ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()'
            );
            $stmt->execute([$userId, $lessonId, $courseId]);

            $progress = $this->updateCourseProgress($userId, $courseId);
            $navigation = $this->navigation($courseId, $lessonId);
            if (!empty($lesson['media_url'])) {
                $lesson['media_url'] = ($this->resourceUrl)((string)$lesson['media_url']);
            }

            $this->db->commit();
            return ['lesson' => $lesson, 'navigation' => $navigation, 'progress' => $progress];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function updateCourseProgress(int $userId, int $courseId): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM course_lessons WHERE course_id = ?');
        $stmt->execute([$courseId]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_lesson_progress '
            . 'WHERE user_id = ? AND course_id = ? AND is_completed = 1'
        );
        $stmt->execute([$userId, $courseId]);
        $completed = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT status FROM user_course_progress WHERE user_id = ? AND course_id = ? FOR UPDATE'
        );
        $stmt->execute([$userId, $courseId]);
        $wasCompleted = (int)$stmt->fetchColumn() === 1;

        $progress = $total > 0 ? round($completed / $total * 100, 2) : 0.0;
        $status = $progress >= 100 ? 1 : 0;
        $completedAt = $status === 1 ? date('Y-m-d H:i:s') : null;
        $stmt = $this->db->prepare(
            'INSERT INTO user_course_progress (user_id, course_id, progress, status, completed_at) '
            . 'VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE progress = ?, status = ?, completed_at = ?'
        );
        $stmt->execute([
            $userId, $courseId, $progress, $status, $completedAt,
            $progress, $status, $completedAt,
        ]);

        if ($status === 1 && !$wasCompleted) {
            $this->awardPoints($userId, 'course_complete', $courseId);
        }
        return ['completed' => $completed, 'total' => $total, 'percent' => $progress, 'status' => $status];
    }

    private function navigation(int $courseId, int $lessonId): array
    {
        $navigation = ['prev' => null, 'next' => null];
        $stmt = $this->db->prepare(
            'SELECT id, title FROM course_lessons WHERE course_id = ? '
            . 'AND sort_order < (SELECT sort_order FROM course_lessons WHERE id = ?) '
            . 'ORDER BY sort_order DESC LIMIT 1'
        );
        $stmt->execute([$courseId, $lessonId]);
        $navigation['prev'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $this->db->prepare(
            'SELECT id, title FROM course_lessons WHERE course_id = ? '
            . 'AND sort_order > (SELECT sort_order FROM course_lessons WHERE id = ?) '
            . 'ORDER BY sort_order ASC LIMIT 1'
        );
        $stmt->execute([$courseId, $lessonId]);
        $navigation['next'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $navigation;
    }

    private function awardPoints(int $userId, string $ruleCode, int $sourceId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM points_rules WHERE code = ? AND status = 1');
        $stmt->execute([$ruleCode]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            return;
        }

        if ((int)$rule['daily_limit'] > 0) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = ? AND created_at >= ?'
            );
            $stmt->execute([$userId, $rule['id'], date('Y-m-d 00:00:00')]);
            if ((int)$stmt->fetchColumn() >= (int)$rule['daily_limit']) {
                return;
            }
        }
        if ((int)$rule['total_limit'] > 0) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = ?');
            $stmt->execute([$userId, $rule['id']]);
            if ((int)$stmt->fetchColumn() >= (int)$rule['total_limit']) {
                return;
            }
        }

        $points = (int)$rule['points'];
        $stmt = $this->db->prepare(
            'INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE total_points = total_points + ?, accumulated_points = accumulated_points + ?'
        );
        $stmt->execute([$userId, $points, $points, $points, $points]);
        $stmt = $this->db->prepare('SELECT total_points FROM user_points WHERE user_id = ?');
        $stmt->execute([$userId]);
        $balance = (int)$stmt->fetchColumn();
        $stmt = $this->db->prepare(
            "INSERT INTO points_records (user_id, rule_id, points, balance, type, source, source_id, description) "
            . "VALUES (?, ?, ?, ?, 'earn', ?, ?, ?)"
        );
        $stmt->execute([
            $userId, $rule['id'], $points, $balance, $rule['rule_type'], $sourceId, $rule['name'],
        ]);
    }
}
