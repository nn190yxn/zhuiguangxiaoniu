<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/workload/_common.php';
require_once dirname(__DIR__) . '/wecom/_common.php';

function reminderDb(): PDO {
    return workloadDb();
}

function reminderNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
}

function reminderEnsureSchema(PDO $pdo): void {
    platformRequireMigrationReadiness($pdo, ['202607240002', '202607310005', '202607310006', '202607310007', '202608120001', '202608120005']);
}

function reminderParseNotificationId(string $rawId): array {
    $rawId = trim($rawId);
    if ($rawId === '') {
        return ['source' => 'policy', 'id' => 0];
    }
    if (strpos($rawId, ':') === false) {
        return ['source' => 'policy', 'id' => (int)$rawId];
    }
    [$source, $id] = explode(':', $rawId, 2);
    return ['source' => $source ?: 'policy', 'id' => (int)$id];
}

function reminderFormatNotificationId(string $source, int $id): string {
    return $source . ':' . $id;
}

function reminderNotificationSourceTable(string $source): ?string {
    if ($source === 'policy') {
        return 'policy_notifications';
    }
    if ($source === 'reminder') {
        return 'mini_user_notifications';
    }
    return null;
}

function reminderWorkloadRoleAliases(string $roleCode): array {
    if ($roleCode === 'sales') {
        return ['sales', 'consultant', 'sale', '销售', '实习销售'];
    }
    if ($roleCode === 'coach') {
        return ['coach', '教练', '实习教练'];
    }
    if ($roleCode === 'manager') {
        return ['manager', 'store_manager', 'shop_manager', '店长'];
    }
    return [$roleCode];
}

function reminderFetchActiveWorkloadStaffs(PDO $pdo): array {
    $stmt = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, s.role, s.store_id, s.openid, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1
          AND s.store_id IS NOT NULL
          AND s.role IN ('sales', 'coach', 'manager', 'consultant', 'sale', 'store_manager', 'shop_manager', '销售', '教练', '店长', '实习销售', '实习教练')
        ORDER BY s.store_id ASC, s.id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reminderFetchManagersByStore(PDO $pdo): array {
    $stmt = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, s.store_id, st.name AS store_name, s.openid
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1 AND s.user_id IS NOT NULL AND s.user_id > 0 AND s.role IN ('manager', 'store_manager', 'shop_manager', '店长')
        ORDER BY s.store_id ASC, s.id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $storeId = (int)($row['store_id'] ?? 0);
        if ($storeId <= 0) {
            continue;
        }
        $map[$storeId][] = $row;
    }
    return $map;
}

function reminderFetchHeadquarterRecipients(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id AS staff_id, user_id, name AS staff_name, role, store_id, openid
        FROM staffs
        WHERE status = 1 AND user_id IS NOT NULL AND user_id > 0");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $result = [];
    foreach ($rows as $row) {
        $roleCode = appRoleCode((string)($row['role'] ?? ''));
        if (!in_array($roleCode, ['operation', 'finance', 'admin', 'ceo'], true)) {
            continue;
        }
        $row['role_code'] = $roleCode;
        $result[] = $row;
    }
    return $result;
}

function reminderFetchActiveLearningStaffs(PDO $pdo): array {
    $stmt = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1 AND s.user_id IS NOT NULL AND s.user_id > 0
        ORDER BY s.store_id ASC, s.id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reminderFetchLearningPendingRows(PDO $pdo): array {
    $staffRows = reminderFetchActiveLearningStaffs($pdo);
    if (!$staffRows) {
        return [];
    }

    $courseStmt = $pdo->prepare("SELECT c.id, c.title, COALESCE(ucp.progress, 0) AS progress, COALESCE(ucp.status, 0) AS user_status
        FROM courses c
        LEFT JOIN user_course_progress ucp ON ucp.course_id = c.id AND ucp.user_id = ?
        WHERE c.status = 1 AND c.is_required = 1 AND COALESCE(ucp.status, 0) <> 1
        ORDER BY COALESCE(ucp.progress, 0) DESC, c.sort_order ASC, c.id DESC
        LIMIT 1");
    $countStmt = $pdo->prepare("SELECT COUNT(*)
        FROM courses c
        LEFT JOIN user_course_progress ucp ON ucp.course_id = c.id AND ucp.user_id = ?
        WHERE c.status = 1 AND c.is_required = 1 AND COALESCE(ucp.status, 0) <> 1");

    $rows = [];
    foreach ($staffRows as $staff) {
        $userId = (int)($staff['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $courseStmt->execute([$userId]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$course) {
            continue;
        }
        $countStmt->execute([$userId]);
        $pendingCount = (int)$countStmt->fetchColumn();
        $progress = max(0, min(100, (int)($course['progress'] ?? 0)));
        $rows[] = [
            'staff_id' => (int)($staff['staff_id'] ?? 0),
            'user_id' => $userId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'role_code' => appRoleCode((string)($staff['role'] ?? '')),
            'store_id' => (int)($staff['store_id'] ?? 0),
            'store_name' => (string)($staff['store_name'] ?? ''),
            'course_id' => (int)($course['id'] ?? 0),
            'course_title' => (string)($course['title'] ?? ''),
            'progress' => $progress,
            'pending_count' => $pendingCount,
        ];
    }

    return $rows;
}

function reminderBuildLearningJobs(PDO $pdo, string $reportDate): array {
    $pendingRows = reminderFetchLearningPendingRows($pdo);
    $jobs = [];
    $skipped = [];

    foreach ($pendingRows as $row) {
        if ((int)($row['user_id'] ?? 0) <= 0) {
            $skipped[] = [
                'rule_code' => 'learning_required_daily',
                'staff_id' => (int)($row['staff_id'] ?? 0),
                'staff_name' => (string)($row['staff_name'] ?? ''),
                'reason' => '未绑定 user_id',
            ];
            continue;
        }
        $progress = (int)($row['progress'] ?? 0);
        $courseTitle = (string)($row['course_title'] ?? '必修课程');
        $jobs[] = [
            'reminder_date' => $reportDate,
            'rule_code' => 'learning_required_daily',
            'target_user_id' => (int)$row['user_id'],
            'target_staff_id' => (int)$row['staff_id'],
            'target_store_id' => (int)$row['store_id'],
            'target_role_code' => (string)($row['role_code'] ?? ''),
            'target_name' => (string)($row['staff_name'] ?? ''),
            'type' => 'reminder',
            'title' => $progress > 0 ? '必修课程待完成' : '有必修课程待开始',
            'content' => $progress > 0
                ? ((string)$row['staff_name']) . '，请继续完成《' . $courseTitle . '》，当前进度 ' . $progress . '%。'
                : ((string)$row['staff_name']) . '，你有必修课程《' . $courseTitle . '》待开始，请及时学习。',
            'payload' => array_merge($row, [
                'scene' => 'learning',
                'source_key' => 'learning_required',
                'date' => $reportDate,
            ]),
        ];
    }

    return ['jobs' => $jobs, 'skipped' => $skipped, 'pending_rows' => $pendingRows];
}

function reminderWorkloadIncompleteReason(array $report = null, int $gapCount = 0): string {
    if (!$report) {
        return '未填写日报';
    }
    $status = (string)($report['submit_status'] ?? 'draft');
    if ($gapCount > 0) {
        return '缺少' . $gapCount . '项凭证';
    }
    if ($status === 'draft') {
        return '草稿未提交';
    }
    return '未完成日报';
}

function reminderFetchWorkloadIncompleteRows(PDO $pdo, string $reportDate): array {
    $staffRows = reminderFetchActiveWorkloadStaffs($pdo);
    if (!$staffRows) {
        return [];
    }

    $reportStmt = $pdo->prepare("SELECT id, report_date, store_id, staff_id, role_code, submit_status, remarks, submitted_at, updated_at
        FROM workload_daily_reports
        WHERE report_date = ? AND role_code IN ('sales', 'coach', 'manager')");
    $reportStmt->execute([$reportDate]);
    $reportMap = [];
    foreach ($reportStmt->fetchAll(PDO::FETCH_ASSOC) as $report) {
        $key = (int)$report['staff_id'] . ':' . appRoleCode((string)($report['role_code'] ?? ''));
        $reportMap[$key] = $report;
    }

    $rows = [];
    foreach ($staffRows as $staff) {
        $roleCode = appRoleCode((string)($staff['role'] ?? ''));
        if (!in_array($roleCode, ['sales', 'coach', 'manager'], true)) {
            continue;
        }
        $key = (int)$staff['staff_id'] . ':' . $roleCode;
        $report = $reportMap[$key] ?? null;
        $status = $report ? (string)($report['submit_status'] ?? 'draft') : 'missing';
        $gapCount = 0;
        if ($report && (int)($report['id'] ?? 0) > 0) {
            $gapCount = workloadReportEvidenceGapCount($pdo, (int)$report['id'], $roleCode);
        }
        if ($status === 'submitted' && $gapCount <= 0) {
            continue;
        }
        $rows[] = [
            'report_date' => $reportDate,
            'staff_id' => (int)$staff['staff_id'],
            'user_id' => (int)($staff['user_id'] ?? 0),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'role_code' => $roleCode,
            'store_id' => (int)($staff['store_id'] ?? 0),
            'store_name' => (string)($staff['store_name'] ?? ''),
            'openid' => (string)($staff['openid'] ?? ''),
            'report_id' => $report ? (int)$report['id'] : 0,
            'submit_status' => $status,
            'evidence_gap_count' => $gapCount,
            'reason_text' => reminderWorkloadIncompleteReason($report, $gapCount),
        ];
    }

    return $rows;
}

function reminderBuildWorkloadJobs(PDO $pdo, string $reportDate, string $phase = 'all'): array {
    $phaseRules = [
        'first' => ['workload_daily_first'],
        'second' => ['workload_daily_second'],
        'store_summary' => ['workload_store_summary'],
        'hq_summary' => ['workload_hq_summary'],
        'makeup' => ['workload_makeup_employee', 'workload_makeup_manager'],
        'penalty' => ['workload_penalty_employee', 'workload_penalty_manager', 'workload_penalty_hq'],
        'all' => [
            'workload_daily_first', 'workload_daily_second', 'workload_store_summary', 'workload_hq_summary',
            'workload_makeup_employee', 'workload_makeup_manager',
            'workload_penalty_employee', 'workload_penalty_manager', 'workload_penalty_hq',
        ],
    ];
    $selected = $phaseRules[$phase] ?? $phaseRules['all'];
    $incompleteRows = reminderFetchWorkloadIncompleteRows($pdo, $reportDate);
    $jobs = [];
    $skipped = [];

    foreach ($incompleteRows as $row) {
        if (in_array('workload_daily_first', $selected, true)) {
            if ($row['user_id'] > 0) {
                $jobs[] = [
                    'reminder_date' => $reportDate,
                    'rule_code' => 'workload_daily_first',
                    'target_user_id' => $row['user_id'],
                    'target_staff_id' => $row['staff_id'],
                    'target_store_id' => $row['store_id'],
                    'target_role_code' => $row['role_code'],
                    'target_name' => $row['staff_name'],
                    'type' => 'reminder',
                    'title' => '今晚 20:00 前请完成工作量日报',
                    'content' => $row['staff_name'] . '，你今天的工作量状态是“' . $row['reason_text'] . '”，请在今晚 20:00 前先处理，最晚当天 24:00 前完成。',
                    'payload' => $row,
                ];
            } else {
                $skipped[] = ['rule_code' => 'workload_daily_first', 'staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'], 'reason' => '未绑定 user_id'];
            }
        }
        if (in_array('workload_daily_second', $selected, true)) {
            if ($row['user_id'] > 0) {
                $jobs[] = [
                    'reminder_date' => $reportDate,
                    'rule_code' => 'workload_daily_second',
                    'target_user_id' => $row['user_id'],
                    'target_staff_id' => $row['staff_id'],
                    'target_store_id' => $row['store_id'],
                    'target_role_code' => $row['role_code'],
                    'target_name' => $row['staff_name'],
                    'type' => 'reminder',
                    'title' => '今晚 23:00 再提醒一次，请把工作量补齐',
                    'content' => $row['staff_name'] . '，你今天的工作量状态仍然是“' . $row['reason_text'] . '”，请尽快补齐，系统会按当天未完成记录汇总。',
                    'payload' => $row,
                ];
            } else {
                $skipped[] = ['rule_code' => 'workload_daily_second', 'staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'], 'reason' => '未绑定 user_id'];
            }
        }
    }

    $byStore = [];
    foreach ($incompleteRows as $row) {
        $storeId = (int)$row['store_id'];
        if ($storeId <= 0) {
            continue;
        }
        $byStore[$storeId][] = $row;
    }

    if (in_array('workload_store_summary', $selected, true)) {
        $managersByStore = reminderFetchManagersByStore($pdo);
        foreach ($byStore as $storeId => $rows) {
            $managerRows = $managersByStore[$storeId] ?? [];
            $summaryPieces = array_map(static function (array $row): string {
                return $row['staff_name'] . '（' . $row['reason_text'] . '）';
            }, $rows);
            $content = '你门店今天还有 ' . count($rows) . ' 人工作量未完成：' . implode('、', $summaryPieces) . '。请及时跟进，今天 24:00 前补齐。';
            foreach ($managerRows as $manager) {
                $jobs[] = [
                    'reminder_date' => $reportDate,
                    'rule_code' => 'workload_store_summary',
                    'target_user_id' => (int)($manager['user_id'] ?? 0),
                    'target_staff_id' => (int)($manager['staff_id'] ?? 0),
                    'target_store_id' => $storeId,
                    'target_role_code' => 'manager',
                    'target_name' => (string)($manager['staff_name'] ?? ''),
                    'type' => 'reminder',
                    'title' => ((string)($manager['store_name'] ?? '门店')) . '今日工作量待补齐汇总',
                    'content' => $content,
                    'payload' => ['store_id' => $storeId, 'store_name' => (string)($manager['store_name'] ?? ''), 'incomplete_rows' => $rows],
                ];
            }
            if (!$managerRows) {
                $skipped[] = ['rule_code' => 'workload_store_summary', 'store_id' => $storeId, 'store_name' => (string)($rows[0]['store_name'] ?? ''), 'reason' => '门店无可接收提醒的店长账号'];
            }
        }
    }

    if (in_array('workload_hq_summary', $selected, true)) {
        $storePieces = [];
        foreach ($byStore as $storeId => $rows) {
            $storeName = (string)($rows[0]['store_name'] ?? ('门店' . $storeId));
            $storePieces[] = $storeName . count($rows) . '人';
        }
        $hqRecipients = reminderFetchHeadquarterRecipients($pdo);
        $content = $storePieces
            ? '全门店今日工作量未完成汇总：' . implode('，', $storePieces) . '。请按门店名单继续跟进。'
            : '今天所有门店的工作量都已完成。';
        foreach ($hqRecipients as $recipient) {
            $jobs[] = [
                'reminder_date' => $reportDate,
                'rule_code' => 'workload_hq_summary',
                'target_user_id' => (int)($recipient['user_id'] ?? 0),
                'target_staff_id' => (int)($recipient['staff_id'] ?? 0),
                'target_store_id' => (int)($recipient['store_id'] ?? 0),
                'target_role_code' => (string)($recipient['role_code'] ?? ''),
                'target_name' => (string)($recipient['staff_name'] ?? ''),
                'type' => 'reminder',
                'title' => '全门店今日工作量汇总提醒',
                'content' => $content,
                'payload' => ['store_summary' => $byStore],
            ];
        }
    }

    if (in_array('workload_makeup_employee', $selected, true) || in_array('workload_makeup_manager', $selected, true)) {
        $makeupRows = reminderFetchWorkloadMakeupRows($pdo, $reportDate);
        $managersByStore = reminderFetchManagersByStore($pdo);
        $makeupByStore = [];
        foreach ($makeupRows as $row) {
            $makeupByStore[(int)$row['store_id']][] = $row;
            if (in_array('workload_makeup_employee', $selected, true) && (int)$row['user_id'] > 0) {
                $jobs[] = reminderWorkloadJob($reportDate, 'workload_makeup_employee', $row, '昨天工作量待补齐',
                    $row['staff_name'] . '，昨天还差 ' . $row['gap_points'] . ' 点，请在 ' . $row['makeup_deadline_at'] . ' 前补齐。');
            } elseif (in_array('workload_makeup_employee', $selected, true)) {
                $skipped[] = reminderSkippedWorkloadRecipient('workload_makeup_employee', $row);
            }
        }
        if (in_array('workload_makeup_manager', $selected, true)) {
            foreach ($makeupByStore as $storeId => $rows) {
                foreach ($managersByStore[$storeId] ?? [] as $manager) {
                    $jobs[] = reminderWorkloadJob($reportDate, 'workload_makeup_manager', array_merge($rows[0], [
                        'user_id' => (int)$manager['user_id'], 'staff_id' => (int)$manager['staff_id'], 'staff_name' => (string)$manager['staff_name'],
                    ]), ((string)$manager['store_name']) . '昨日工作量补齐跟进', '门店有 ' . count($rows) . ' 人处于补齐期：' . reminderWorkloadNames($rows) . '。请在今晚 24:00 前跟进完成。', ['makeup_rows' => $rows]);
                }
                if (empty($managersByStore[$storeId])) $skipped[] = ['rule_code' => 'workload_makeup_manager', 'store_id' => $storeId, 'reason' => '门店无可接收提醒的店长账号'];
            }
        }
    }

    if (in_array('workload_penalty_employee', $selected, true) || in_array('workload_penalty_manager', $selected, true) || in_array('workload_penalty_hq', $selected, true)) {
        $penaltyRows = reminderFetchWorkloadPenaltyRows($pdo, $reportDate);
        $managersByStore = reminderFetchManagersByStore($pdo);
        $penaltyByStore = [];
        foreach ($penaltyRows as $row) {
            $penaltyByStore[(int)$row['store_id']][] = $row;
            if (in_array('workload_penalty_employee', $selected, true) && (int)$row['user_id'] > 0) {
                $jobs[] = reminderWorkloadJob($reportDate, 'workload_penalty_employee', $row, '工作量逾期处理结果',
                    $row['staff_name'] . '，' . $row['business_date'] . ' 工作量差额 ' . $row['gap_points'] . ' 点，待处理金额 ¥' . $row['penalty_amount'] . '。');
            } elseif (in_array('workload_penalty_employee', $selected, true)) {
                $skipped[] = reminderSkippedWorkloadRecipient('workload_penalty_employee', $row);
            }
        }
        if (in_array('workload_penalty_manager', $selected, true)) {
            foreach ($penaltyByStore as $storeId => $rows) {
                foreach ($managersByStore[$storeId] ?? [] as $manager) {
                    $jobs[] = reminderWorkloadJob($reportDate, 'workload_penalty_manager', array_merge($rows[0], [
                        'user_id' => (int)$manager['user_id'], 'staff_id' => (int)$manager['staff_id'], 'staff_name' => (string)$manager['staff_name'],
                    ]), ((string)$manager['store_name']) . '工作量逾期跟进', '门店新增 ' . count($rows) . ' 条待确认处罚：' . reminderWorkloadNames($rows) . '。请跟进处理状态。', ['penalty_rows' => $rows]);
                }
                if (empty($managersByStore[$storeId])) $skipped[] = ['rule_code' => 'workload_penalty_manager', 'store_id' => $storeId, 'reason' => '门店无可接收提醒的店长账号'];
            }
        }
        if (in_array('workload_penalty_hq', $selected, true) && $penaltyRows) {
            foreach (reminderFetchHeadquarterRecipients($pdo) as $recipient) {
                $jobs[] = reminderWorkloadJob($reportDate, 'workload_penalty_hq', array_merge($penaltyRows[0], [
                    'user_id' => (int)$recipient['user_id'], 'staff_id' => (int)$recipient['staff_id'], 'staff_name' => (string)$recipient['staff_name'], 'store_id' => 0,
                ]), '工作量逾期处罚待处理汇总', '新增 ' . count($penaltyRows) . ' 条工作量逾期处罚待确认，涉及 ' . reminderWorkloadNames($penaltyRows) . '。请在处罚处理页确认。', ['penalty_rows' => $penaltyRows]);
            }
        }
    }

    return ['jobs' => $jobs, 'skipped' => $skipped, 'incomplete_rows' => $incompleteRows];
}

function reminderFetchWorkloadMakeupRows(PDO $pdo, string $reportDate): array {
    $stmt = $pdo->prepare("SELECT settlement.business_date, settlement.store_id, settlement.staff_id, settlement.role_code, settlement.gap_points, settlement.makeup_deadline_at, staff.user_id, staff.name AS staff_name, store.name AS store_name
        FROM workload_daily_settlements settlement
        JOIN staffs staff ON staff.id = settlement.staff_id AND staff.status = 1
        LEFT JOIN stores store ON store.id = settlement.store_id
        WHERE settlement.business_date = DATE_SUB(?, INTERVAL 1 DAY) AND settlement.settlement_status = 'makeup_open' AND settlement.gap_points > 0");
    $stmt->execute([$reportDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reminderFetchWorkloadPenaltyRows(PDO $pdo, string $reportDate): array {
    $stmt = $pdo->prepare("SELECT penalty.business_date, penalty.store_id, penalty.staff_id, penalty.role_code, penalty.gap_points, penalty.penalty_amount, penalty.status AS penalty_status, staff.user_id, staff.name AS staff_name, store.name AS store_name
        FROM workload_penalty_records penalty
        JOIN workload_daily_settlements settlement ON settlement.id = penalty.settlement_id
        JOIN staffs staff ON staff.id = penalty.staff_id AND staff.status = 1
        LEFT JOIN stores store ON store.id = penalty.store_id
        WHERE penalty.business_date = DATE_SUB(?, INTERVAL 2 DAY) AND settlement.settlement_status = 'overdue' AND penalty.status = 'pending_confirmation'");
    $stmt->execute([$reportDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reminderWorkloadJob(string $reportDate, string $ruleCode, array $row, string $title, string $content, array $extraPayload = []): array {
    return [
        'reminder_date' => $reportDate, 'rule_code' => $ruleCode,
        'target_user_id' => (int)($row['user_id'] ?? 0), 'target_staff_id' => (int)($row['staff_id'] ?? 0),
        'target_store_id' => (int)($row['store_id'] ?? 0), 'target_role_code' => (string)($row['role_code'] ?? ''),
        'target_name' => (string)($row['staff_name'] ?? ''), 'type' => 'reminder', 'title' => $title, 'content' => $content,
        'payload' => array_merge($row, ['report_date' => $reportDate], $extraPayload),
    ];
}

function reminderSkippedWorkloadRecipient(string $ruleCode, array $row): array {
    return ['rule_code' => $ruleCode, 'staff_id' => (int)($row['staff_id'] ?? 0), 'staff_name' => (string)($row['staff_name'] ?? ''), 'reason' => '未绑定 user_id'];
}

function reminderWorkloadNames(array $rows): string {
    return implode('、', array_map(static fn(array $row): string => (string)($row['staff_name'] ?? ''), $rows));
}

function reminderUpsertJob(PDO $pdo, array $job): int {
    $stmt = $pdo->prepare("INSERT INTO mini_reminder_jobs
        (reminder_date, rule_code, target_user_id, target_staff_id, target_store_id, target_role_code, target_name, type, title, content, payload_json, status, channel_station_status, channel_wechat_status, channel_wechat_note, last_error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', '', '')
        ON DUPLICATE KEY UPDATE
            target_role_code = VALUES(target_role_code),
            target_name = VALUES(target_name),
            title = VALUES(title),
            content = VALUES(content),
            payload_json = VALUES(payload_json),
            status = IF(status = 'sent', status, 'pending'),
            channel_station_status = IF(status = 'sent', channel_station_status, 'pending'),
            channel_wechat_status = IF(status = 'sent', channel_wechat_status, 'pending'),
            channel_wechat_note = '',
            last_error = ''");
    $payloadJson = json_encode($job['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt->execute([
        $job['reminder_date'],
        $job['rule_code'],
        (int)($job['target_user_id'] ?? 0),
        (int)($job['target_staff_id'] ?? 0),
        (int)($job['target_store_id'] ?? 0),
        (string)($job['target_role_code'] ?? ''),
        (string)($job['target_name'] ?? ''),
        (string)($job['type'] ?? 'reminder'),
        (string)($job['title'] ?? ''),
        (string)($job['content'] ?? ''),
        $payloadJson === false ? '{}' : $payloadJson,
    ]);

    $idStmt = $pdo->prepare("SELECT id FROM mini_reminder_jobs WHERE reminder_date = ? AND rule_code = ? AND target_user_id = ? AND target_staff_id = ? AND target_store_id = ? LIMIT 1");
    $idStmt->execute([
        $job['reminder_date'],
        $job['rule_code'],
        (int)($job['target_user_id'] ?? 0),
        (int)($job['target_staff_id'] ?? 0),
        (int)($job['target_store_id'] ?? 0),
    ]);
    return (int)$idStmt->fetchColumn();
}

function reminderWechatTemplateKey(string $ruleCode): string {
    $map = [
        'learning_required_daily' => 'learning_required_daily',
        'workload_daily_first' => 'workload_daily_first',
        'workload_daily_second' => 'workload_daily_second',
        'workload_store_summary' => 'workload_store_summary',
        'workload_hq_summary' => 'workload_hq_summary',
        'workload_makeup_employee' => 'workload_makeup_employee',
        'workload_makeup_manager' => 'workload_makeup_manager',
        'workload_penalty_employee' => 'workload_penalty_employee',
        'workload_penalty_manager' => 'workload_penalty_manager',
        'workload_penalty_hq' => 'workload_penalty_hq',
    ];
    return $map[$ruleCode] ?? $ruleCode;
}

function reminderDuePhases(DateTimeImmutable $now): array {
    $time = $now->format('H:i');
    $phases = [];
    if ($time >= '09:00') {
        $phases[] = 'learning_required';
        $phases[] = 'makeup';
    }
    if ($time >= '20:00') {
        $phases[] = 'first';
    }
    if ($time >= '23:00') {
        $phases[] = 'second';
    }
    if ($time >= '23:05') {
        $phases[] = 'store_summary';
    }
    if ($time >= '23:10') {
        $phases[] = 'hq_summary';
    }
    if ($time >= '00:05' && $time < '09:00') {
        $phases[] = 'penalty';
    }
    return $phases;
}

function reminderDispatchJob(PDO $pdo, int $jobId): array {
    $stmt = $pdo->prepare("SELECT * FROM mini_reminder_jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return ['job_id' => $jobId, 'status' => 'missing'];
    }
    if ((string)($job['status'] ?? '') === 'sent' && (int)($job['notification_id'] ?? 0) > 0) {
        return ['job_id' => $jobId, 'status' => 'already_sent', 'notification_id' => (int)$job['notification_id']];
    }
    if ((int)($job['target_user_id'] ?? 0) <= 0) {
        $pdo->prepare("UPDATE mini_reminder_jobs SET status = 'failed', channel_station_status = 'failed', channel_wechat_status = 'skipped', last_error = 'missing_user_id' WHERE id = ?")->execute([$jobId]);
        return ['job_id' => $jobId, 'status' => 'failed', 'reason' => 'missing_user_id'];
    }

    $insert = $pdo->prepare("INSERT INTO mini_user_notifications (user_id, type, title, content, source_type, source_key, source_job_id)
        VALUES (?, ?, ?, ?, 'reminder', ?, ?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)");
    $insert->execute([
        (int)$job['target_user_id'],
        (string)($job['type'] ?? 'reminder'),
        (string)($job['title'] ?? ''),
        (string)($job['content'] ?? ''),
        (string)($job['rule_code'] ?? ''),
        $jobId,
    ]);

    $notificationIdStmt = $pdo->prepare("SELECT id FROM mini_user_notifications WHERE source_type = 'reminder' AND source_job_id = ? LIMIT 1");
    $notificationIdStmt->execute([$jobId]);
    $notificationId = (int)$notificationIdStmt->fetchColumn();

    $wecomResult = wecomDispatchReminderMessage($pdo, $job);
    $wechatStatus = (string)($wecomResult['status'] ?? 'skipped');
    $wechatNote = (string)($wecomResult['note'] ?? '');
    $lastError = $wechatStatus === 'failed' ? $wechatNote : '';

    $update = $pdo->prepare("UPDATE mini_reminder_jobs
        SET notification_id = ?, status = 'sent', channel_station_status = 'sent', channel_wechat_status = ?, channel_wechat_note = ?, sent_at = NOW(), last_error = ?
        WHERE id = ?");
    $update->execute([$notificationId, $wechatStatus, $wechatNote, $lastError, $jobId]);

    return [
        'job_id' => $jobId,
        'status' => 'sent',
        'notification_id' => $notificationId,
        'wechat_status' => $wechatStatus,
        'wechat_note' => $wechatNote,
        'wecom_log_id' => isset($wecomResult['log_id']) ? (int)$wecomResult['log_id'] : 0,
    ];
}

function reminderRetryWecomDispatch(PDO $pdo, int $jobId): array {
    reminderEnsureSchema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM mini_reminder_jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        throw new RuntimeException('提醒任务不存在');
    }
    if ((int)($job['target_user_id'] ?? 0) <= 0) {
        $pdo->prepare("UPDATE mini_reminder_jobs SET channel_wechat_status = 'failed', channel_wechat_note = 'missing_user_id', last_error = 'missing_user_id' WHERE id = ?")->execute([$jobId]);
        throw new RuntimeException('该提醒任务缺少 target_user_id');
    }

    $job['id'] = $jobId;
    $wecomResult = wecomDispatchReminderMessage($pdo, $job);
    $wechatStatus = (string)($wecomResult['status'] ?? 'skipped');
    $wechatNote = (string)($wecomResult['note'] ?? '');
    $lastError = $wechatStatus === 'failed' ? $wechatNote : '';

    $update = $pdo->prepare("UPDATE mini_reminder_jobs
        SET channel_wechat_status = ?, channel_wechat_note = ?, last_error = ?, updated_at = NOW()
        WHERE id = ?");
    $update->execute([$wechatStatus, $wechatNote, $lastError, $jobId]);

    return [
        'job_id' => $jobId,
        'wechat_status' => $wechatStatus,
        'wechat_note' => $wechatNote,
        'wecom_log_id' => isset($wecomResult['log_id']) ? (int)$wecomResult['log_id'] : 0,
    ];
}
