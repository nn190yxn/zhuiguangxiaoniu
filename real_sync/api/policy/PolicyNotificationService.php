<?php
declare(strict_types=1);

final class PolicyNotificationService
{
    public function __construct(private PDO $db)
    {
        reminderEnsureSchema($this->db);
    }

    public function list(int $userId, array $query): array
    {
        $idInfo = reminderParseNotificationId((string)($query['id'] ?? ''));
        if ((int)$idInfo['id'] > 0) {
            return $this->detail($userId, $idInfo);
        }

        $page = max(1, (int)($query['page'] ?? 1));
        $pageSize = min(50, max(1, (int)($query['page_size'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $unreadOnly = (int)($query['unread'] ?? 0) === 1;
        $unreadClause = $unreadOnly ? ' AND n.is_read = 0' : '';

        $stmt = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM policy_notifications n WHERE n.user_id = ?' . $unreadClause . ') '
            . '+ (SELECT COUNT(*) FROM mini_user_notifications n WHERE n.user_id = ?' . $unreadClause . ') AS total'
        );
        $stmt->execute([$userId, $userId]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM policy_notifications WHERE user_id = ? AND is_read = 0) '
            . '+ (SELECT COUNT(*) FROM mini_user_notifications WHERE user_id = ? AND is_read = 0) AS unread'
        );
        $stmt->execute([$userId, $userId]);
        $unread = (int)$stmt->fetchColumn();

        $sql = "SELECT * FROM ("
            . "SELECT CONCAT('policy:', n.id) AS id, n.type, n.title, n.content, n.is_read, n.is_confirmed, "
            . "n.created_at, p.id AS policy_id, 'policy' AS source_type, '' AS source_key, "
            . 'p.doc_key, p.title AS policy_title FROM policy_notifications n '
            . 'LEFT JOIN policies p ON n.policy_id = p.id WHERE n.user_id = ?' . $unreadClause
            . " UNION ALL SELECT CONCAT('reminder:', n.id) AS id, n.type, n.title, n.content, n.is_read, "
            . 'n.is_confirmed, n.created_at, n.policy_id, n.source_type, n.source_key, '
            . 'NULL AS doc_key, NULL AS policy_title FROM mini_user_notifications n '
            . 'WHERE n.user_id = ?' . $unreadClause
            . ') notification_union ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $pageSize, $offset]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as &$row) {
            $row['created_at'] = $this->formatDate((string)$row['created_at']);
        }
        unset($row);

        return [
            'list' => $list,
            'unread_count' => $unread,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => (int)ceil($total / $pageSize),
            ],
        ];
    }

    public function markRead(int $userId, string $rawId): array
    {
        $idInfo = $this->validId($rawId);
        $table = $this->sourceTable((string)$idInfo['source']);
        $stmt = $this->db->prepare("UPDATE {$table} SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([(int)$idInfo['id'], $userId]);
        return ['affected' => $stmt->rowCount()];
    }

    public function confirm(int $userId, string $rawId): array
    {
        $idInfo = $this->validId($rawId);
        if ($idInfo['source'] !== 'policy') {
            throw new PlatformApiException(400, 'confirmation_not_required', '该通知无需确认');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT policy_id FROM policy_notifications WHERE id = ? AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([(int)$idInfo['id'], $userId]);
            $policyId = (int)$stmt->fetchColumn();
            if ($policyId <= 0) {
                throw new PlatformApiException(404, 'notification_not_found', '通知不存在');
            }
            $stmt = $this->db->prepare(
                'UPDATE policy_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()), '
                . 'is_confirmed = 1, confirmed_at = COALESCE(confirmed_at, NOW()) WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([(int)$idInfo['id'], $userId]);
            $stmt = $this->db->prepare('INSERT IGNORE INTO policy_read_history (policy_id, user_id) VALUES (?, ?)');
            $stmt->execute([$policyId, $userId]);
            $this->db->commit();
            return ['affected' => 1];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function send(array $input): array
    {
        $policyId = (int)($input['policy_id'] ?? 0);
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($input['user_ids'] ?? null) ? $input['user_ids'] : []),
            static fn(int $id): bool => $id > 0
        )));
        if ($policyId <= 0) {
            throw new PlatformApiException(400, 'policy_id_required', '缺少参数：policy_id');
        }
        if ($userIds === []) {
            throw new PlatformApiException(400, 'notification_recipients_required', '请选择通知接收人');
        }

        $stmt = $this->db->prepare('SELECT id, title, category, doc_key FROM policies WHERE id = ?');
        $stmt->execute([$policyId]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            throw new PlatformApiException(404, 'policy_not_found', '制度不存在');
        }
        $type = trim((string)($input['type'] ?? 'update')) ?: 'update';
        $title = trim((string)($input['title'] ?? '')) ?: '制度更新通知';
        $content = trim((string)($input['content'] ?? ''));
        $notifications = [];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO policy_notifications (policy_id, user_id, type, title, content) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($userIds as $targetUserId) {
                $stmt->execute([$policyId, $targetUserId, $type, $title, $content]);
                $notificationId = (int)$this->db->lastInsertId();
                $notifications[] = [
                    'id' => $notificationId,
                    'notification_id' => $notificationId,
                    'policy_id' => $policyId,
                    'target_user_id' => $targetUserId,
                    'type' => $type,
                    'title' => $title,
                    'content' => $content,
                ];
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $wecomStats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($notifications as $notification) {
            try {
                $dispatch = wecomDispatchPolicyNotification($this->db, $notification, $policy);
                $status = (string)($dispatch['status'] ?? 'skipped');
            } catch (Throwable) {
                $status = 'failed';
            }
            $wecomStats[$status] = ($wecomStats[$status] ?? 0) + 1;
        }
        return ['sent_count' => count($notifications), 'wecom' => $wecomStats];
    }

    private function detail(int $userId, array $idInfo): array
    {
        $id = (int)$idInfo['id'];
        $this->sourceTable((string)$idInfo['source']);
        if ($idInfo['source'] === 'reminder') {
            $sql = "SELECT n.id, n.type, n.title, n.content, n.is_read, n.is_confirmed, n.created_at, "
                . 'n.policy_id, n.source_type, n.source_key, NULL AS doc_key, NULL AS policy_title '
                . 'FROM mini_user_notifications n WHERE n.id = ? AND n.user_id = ? LIMIT 1';
        } else {
            $sql = "SELECT n.id, n.type, n.title, n.content, n.is_read, n.is_confirmed, n.created_at, "
                . "p.id AS policy_id, 'policy' AS source_type, '' AS source_key, p.doc_key, p.title AS policy_title "
                . 'FROM policy_notifications n LEFT JOIN policies p ON n.policy_id = p.id '
                . 'WHERE n.id = ? AND n.user_id = ? LIMIT 1';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $userId]);
        $detail = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$detail) {
            throw new PlatformApiException(404, 'notification_not_found', '通知不存在');
        }
        if ((int)$detail['is_read'] !== 1) {
            $table = $this->sourceTable((string)$idInfo['source']);
            $stmt = $this->db->prepare("UPDATE {$table} SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $detail['is_read'] = 1;
        }
        $detail['id'] = reminderFormatNotificationId((string)$idInfo['source'], $id);
        $detail['created_at'] = $this->formatDate((string)$detail['created_at']);
        return $detail;
    }

    private function validId(string $rawId): array
    {
        $idInfo = reminderParseNotificationId($rawId);
        if ((int)$idInfo['id'] <= 0) {
            throw new PlatformApiException(400, 'notification_id_required', '缺少参数：id');
        }
        $this->sourceTable((string)$idInfo['source']);
        return $idInfo;
    }

    private function sourceTable(string $source): string
    {
        $table = reminderNotificationSourceTable($source);
        if ($table === null) {
            throw new PlatformApiException(400, 'notification_source_invalid', '通知来源无效');
        }
        return $table;
    }

    private function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d H:i', $timestamp);
    }
}
