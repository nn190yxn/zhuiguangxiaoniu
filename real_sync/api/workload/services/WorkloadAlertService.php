<?php
declare(strict_types=1);

final class WorkloadAlertService {
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    private const MANAGED_OPERATIONAL_RULES = [
        'draft_submission_reminder',
        'missing_submission_reminder',
        'locked_missing_notice',
        'locked_missing_store_alert',
        'audit_backlog_yellow',
        'audit_backlog_red',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function evaluate(?DateTimeImmutable $now = null): array {
        $now = ($now ?: new DateTimeImmutable('now', new DateTimeZone(self::BUSINESS_TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
        $rules = $this->rules();
        $candidates = array_merge(
            $this->submissionCandidates($now, $rules),
            $this->auditBacklogCandidates($now, $rules)
        );

        $dates = [$now->format('Y-m-d') => true];
        foreach ($candidates as $candidate) {
            $dates[(string) $candidate['business_date']] = true;
        }

        $events = [];
        $closedCount = 0;
        foreach (array_keys($dates) as $businessDate) {
            $dateCandidates = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool => $candidate['business_date'] === $businessDate
            ));
            $result = $this->syncCandidates($businessDate, $dateCandidates, self::MANAGED_OPERATIONAL_RULES);
            $events = array_merge($events, $result['events']);
            $closedCount += $result['closed_count'];
        }

        $notifications = $this->publishNotifications($events);
        return [
            'candidate_count' => count($candidates),
            'event_count' => count($events),
            'closed_count' => $closedCount,
            'notification_sent_count' => $notifications['sent_count'],
            'notification_failures' => $notifications['failures'],
        ];
    }

    public function rules(): array {
        $stmt = $this->pdo->query(
            'SELECT rule.* FROM workload_alert_rules rule '
            . 'INNER JOIN (SELECT rule_code, MAX(version_no) AS version_no FROM workload_alert_rules '
            . 'WHERE enabled = 1 GROUP BY rule_code) latest '
            . 'ON latest.rule_code = rule.rule_code AND latest.version_no = rule.version_no '
            . 'WHERE rule.enabled = 1 ORDER BY rule.rule_code'
        );
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
            $rules[(string) $rule['rule_code']] = $rule;
        }
        return $rules;
    }

    public function syncCandidates(string $businessDate, array $candidates, array $managedRuleCodes): array {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO workload_alert_events '
                . '(rule_code, business_date, period_type, period_key, store_id, staff_id, role_code, metric_code, '
                . 'target_role_code, severity, numerator, denominator, current_value, threshold_value, evidence_json, status) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open') "
                . 'ON DUPLICATE KEY UPDATE business_date = VALUES(business_date), period_type = VALUES(period_type), '
                . 'target_role_code = VALUES(target_role_code), severity = VALUES(severity), numerator = VALUES(numerator), '
                . 'denominator = VALUES(denominator), current_value = VALUES(current_value), '
                . 'threshold_value = VALUES(threshold_value), evidence_json = VALUES(evidence_json), '
                . "status = CASE WHEN status = 'resolved' THEN status ELSE 'open' END, updated_at = CURRENT_TIMESTAMP"
            );
            $find = $this->pdo->prepare(
                'SELECT id, status FROM workload_alert_events WHERE rule_code = ? AND period_key = ? '
                . 'AND store_id = ? AND staff_id = ? AND role_code = ? AND metric_code = ? LIMIT 1'
            );
            $events = [];
            $activeKeys = [];
            foreach ($candidates as $candidate) {
                $evidence = $candidate['evidence'] ?? [];
                $upsert->execute([
                    $candidate['rule_code'],
                    $candidate['business_date'],
                    $candidate['period_type'],
                    $candidate['period_key'],
                    (int) ($candidate['store_id'] ?? 0),
                    (int) ($candidate['staff_id'] ?? 0),
                    (string) ($candidate['role_code'] ?? ''),
                    (string) ($candidate['metric_code'] ?? ''),
                    $candidate['target_role_code'],
                    $candidate['severity'],
                    (float) $candidate['numerator'],
                    (float) $candidate['denominator'],
                    (float) $candidate['current_value'],
                    (float) $candidate['threshold_value'],
                    json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
                $find->execute([
                    $candidate['rule_code'],
                    $candidate['period_key'],
                    (int) ($candidate['store_id'] ?? 0),
                    (int) ($candidate['staff_id'] ?? 0),
                    (string) ($candidate['role_code'] ?? ''),
                    (string) ($candidate['metric_code'] ?? ''),
                ]);
                $stored = $find->fetch(PDO::FETCH_ASSOC);
                if (!$stored) {
                    throw new RuntimeException('预警事件幂等写入失败');
                }
                $candidate['event_id'] = (int) $stored['id'];
                $candidate['status'] = (string) $stored['status'];
                $events[] = $candidate;
                $activeKeys[$this->eventKey($candidate)] = true;
            }

            $closedCount = $this->closeStaleEvents($businessDate, $managedRuleCodes, $activeKeys);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return ['events' => $events, 'closed_count' => $closedCount];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function submissionCandidates(DateTimeImmutable $now, array $rules): array {
        $businessDate = $now->format('Y-m-d');
        $reminderRule = $rules['draft_submission_reminder'] ?? null;
        $reminderTime = (string) ($reminderRule['reminder_time'] ?? '20:30:00');
        $canRemind = (int) $now->format('N') !== 1
            && $now >= new DateTimeImmutable($businessDate . ' ' . $reminderTime, $now->getTimezone());
        $scanFrom = $now->modify('-1 day')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT obligation.obligation_date, obligation.store_id, obligation.staff_id, obligation.role_code, '
            . 'obligation.completion_status, obligation.deadline_at, staff.name AS staff_name, store.name AS store_name '
            . 'FROM workload_submission_obligations obligation '
            . 'LEFT JOIN staffs staff ON staff.id = obligation.staff_id '
            . 'LEFT JOIN stores store ON store.id = obligation.store_id '
            . "WHERE obligation.required_status = 'required' AND obligation.obligation_date BETWEEN ? AND ? "
            . "AND obligation.completion_status IN ('missing', 'draft', 'locked_missing')"
        );
        $stmt->execute([$scanFrom, $businessDate]);

        $candidates = [];
        $lockedByStore = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string) $row['completion_status'];
            $date = (string) $row['obligation_date'];
            if ($date === $businessDate && $canRemind && in_array($status, ['missing', 'draft'], true)) {
                $code = $status === 'draft' ? 'draft_submission_reminder' : 'missing_submission_reminder';
                $candidates[] = $this->candidate([
                    'rule_code' => $code,
                    'business_date' => $date,
                    'period_type' => 'day',
                    'period_key' => $date,
                    'store_id' => (int) $row['store_id'],
                    'staff_id' => (int) $row['staff_id'],
                    'role_code' => (string) $row['role_code'],
                    'target_role_code' => 'staff',
                    'severity' => (string) ($reminderRule['severity'] ?? 'warning'),
                    'numerator' => 1,
                    'denominator' => 1,
                    'current_value' => 1,
                    'threshold_value' => 1,
                    'evidence' => [
                        'completion_status' => $status,
                        'deadline_at' => (string) $row['deadline_at'],
                        'route' => '/pages/workload/index?date=' . $date,
                    ],
                ]);
            }
            if ($status !== 'locked_missing') {
                continue;
            }
            $lockedRule = $rules['locked_missing_notice'] ?? [];
            $candidates[] = $this->candidate([
                'rule_code' => 'locked_missing_notice',
                'business_date' => $date,
                'period_type' => 'day',
                'period_key' => $date,
                'store_id' => (int) $row['store_id'],
                'staff_id' => (int) $row['staff_id'],
                'role_code' => (string) $row['role_code'],
                'target_role_code' => 'staff',
                'severity' => (string) ($lockedRule['severity'] ?? 'critical'),
                'numerator' => 1,
                'denominator' => 1,
                'current_value' => 1,
                'threshold_value' => (float) ($lockedRule['threshold_value'] ?? 0),
                'evidence' => ['completion_status' => $status, 'deadline_at' => (string) $row['deadline_at']],
            ]);
            $storeKey = $date . ':' . (int) $row['store_id'];
            if (!isset($lockedByStore[$storeKey])) {
                $lockedByStore[$storeKey] = ['date' => $date, 'store_id' => (int) $row['store_id'], 'staff_ids' => []];
            }
            $lockedByStore[$storeKey]['staff_ids'][] = (int) $row['staff_id'];
        }
        foreach ($lockedByStore as $group) {
            $count = count(array_unique($group['staff_ids']));
            $candidates[] = $this->candidate([
                'rule_code' => 'locked_missing_store_alert',
                'business_date' => $group['date'],
                'period_type' => 'day',
                'period_key' => $group['date'],
                'store_id' => $group['store_id'],
                'target_role_code' => 'manager',
                'severity' => 'critical',
                'numerator' => $count,
                'denominator' => $count,
                'current_value' => $count,
                'threshold_value' => 0,
                'evidence' => ['locked_missing_staff_ids' => array_values(array_unique($group['staff_ids']))],
            ]);
        }
        return $candidates;
    }

    private function auditBacklogCandidates(DateTimeImmutable $now, array $rules): array {
        $stmt = $this->pdo->prepare(
            "SELECT store_id, COUNT(*) AS pending_count, TIMESTAMPDIFF(HOUR, MIN(created_at), ?) AS oldest_age_hours "
            . "FROM workload_audit_tasks WHERE audit_status = 'pending' AND superseded_at IS NULL GROUP BY store_id"
        );
        $stmt->execute([$now->format('Y-m-d H:i:s')]);
        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            foreach (['audit_backlog_yellow', 'audit_backlog_red'] as $code) {
                if (!isset($rules[$code])) {
                    continue;
                }
                $rule = $rules[$code];
                $age = (float) $row['oldest_age_hours'];
                if (!$this->matches($age, (string) $rule['comparison_operator'], (float) $rule['threshold_value'])) {
                    continue;
                }
                $date = $now->format('Y-m-d');
                $candidates[] = $this->candidate([
                    'rule_code' => $code,
                    'business_date' => $date,
                    'period_type' => 'day',
                    'period_key' => $date,
                    'store_id' => (int) $row['store_id'],
                    'target_role_code' => (string) $rule['target_role_code'],
                    'severity' => (string) $rule['severity'],
                    'numerator' => (int) $row['pending_count'],
                    'denominator' => 1,
                    'current_value' => $age,
                    'threshold_value' => (float) $rule['threshold_value'],
                    'evidence' => [
                        'pending_count' => (int) $row['pending_count'],
                        'oldest_age_hours' => $age,
                    ],
                ]);
            }
        }
        return $candidates;
    }

    private function publishNotifications(array $events): array {
        $sent = 0;
        $failures = [];
        foreach ($events as $event) {
            if (($event['status'] ?? 'open') !== 'open') {
                continue;
            }
            try {
                $userIds = $this->notificationUserIds($event);
                foreach ($userIds as $userId) {
                    $notificationId = (int) hexdec(substr(hash(
                        'sha256',
                        (int) $event['event_id'] . ':' . $userId
                    ), 0, 15));
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO mini_user_notifications '
                        . '(user_id, type, title, content, source_type, source_key, source_job_id) '
                        . "VALUES (?, 'workload_alert', ?, ?, 'workload_alert', ?, ?) "
                        . 'ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)'
                    );
                    $stmt->execute([
                        $userId,
                        $this->notificationTitle((string) $event['rule_code']),
                        $this->notificationContent($event),
                        (string) $event['rule_code'],
                        $notificationId,
                    ]);
                    $sent++;
                }
            } catch (Throwable $error) {
                $failures[] = [
                    'event_id' => (int) ($event['event_id'] ?? 0),
                    'channel' => 'station',
                    'error' => mb_substr($error->getMessage(), 0, 300),
                ];
            }
        }
        return ['sent_count' => $sent, 'failures' => $failures];
    }

    private function notificationUserIds(array $event): array {
        $targetRole = (string) $event['target_role_code'];
        if ($targetRole === 'staff') {
            $stmt = $this->pdo->prepare('SELECT user_id FROM staffs WHERE id = ? AND user_id > 0 AND status = 1');
            $stmt->execute([(int) $event['staff_id']]);
        } elseif ($targetRole === 'manager') {
            $stmt = $this->pdo->prepare(
                "SELECT user_id FROM staffs WHERE store_id = ? AND user_id > 0 AND status = 1 "
                . "AND role IN ('manager', 'store_manager', '店长')"
            );
            $stmt->execute([(int) $event['store_id']]);
        } else {
            $stmt = $this->pdo->query(
                "SELECT user_id FROM staffs WHERE user_id > 0 AND status = 1 "
                . "AND role IN ('admin', 'operation', 'operations', 'finance', 'ceo', '总部')"
            );
        }
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    }

    private function closeStaleEvents(string $businessDate, array $managedRuleCodes, array $activeKeys): int {
        if ($managedRuleCodes === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($managedRuleCodes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM workload_alert_events WHERE business_date = ? AND status = 'open' "
            . "AND rule_code IN ($placeholders)"
        );
        $stmt->execute(array_merge([$businessDate], $managedRuleCodes));
        $close = $this->pdo->prepare(
            "UPDATE workload_alert_events SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'open'"
        );
        $closed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $event) {
            if (isset($activeKeys[$this->eventKey($event)])) {
                continue;
            }
            $close->execute([(int) $event['id']]);
            $closed += $close->rowCount();
        }
        return $closed;
    }

    private function candidate(array $candidate): array {
        return array_merge([
            'store_id' => 0,
            'staff_id' => 0,
            'role_code' => '',
            'metric_code' => '',
            'numerator' => 0,
            'denominator' => 0,
            'current_value' => 0,
            'threshold_value' => 0,
            'evidence' => [],
        ], $candidate);
    }

    private function eventKey(array $event): string {
        return implode(':', [
            (string) $event['rule_code'],
            (string) $event['period_key'],
            (int) ($event['store_id'] ?? 0),
            (int) ($event['staff_id'] ?? 0),
            (string) ($event['role_code'] ?? ''),
            (string) ($event['metric_code'] ?? ''),
        ]);
    }

    private function matches(float $value, string $operator, float $threshold): bool {
        return match ($operator) {
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '=' => abs($value - $threshold) < 0.0001,
            default => throw new RuntimeException('预警规则比较运算符无效'),
        };
    }

    private function notificationTitle(string $ruleCode): string {
        return match ($ruleCode) {
            'draft_submission_reminder' => '工作量草稿待提交',
            'missing_submission_reminder' => '今日工作量待填写',
            'locked_missing_notice' => '工作量日报已锁定缺交',
            'locked_missing_store_alert' => '门店存在锁定缺交',
            'audit_backlog_yellow', 'audit_backlog_red' => '工作量审核积压',
            default => '工作量经营提醒',
        };
    }

    private function notificationContent(array $event): string {
        return sprintf(
            '统计周期 %s，当前值 %.2f，阈值 %.2f，请及时处理。',
            (string) $event['period_key'],
            (float) $event['current_value'],
            (float) $event['threshold_value']
        );
    }
}
