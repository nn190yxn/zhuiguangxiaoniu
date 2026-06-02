<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

function adminDashboardMonthValue(array $months, int $month): int {
    foreach ($months as $item) {
        if ((int)($item['month'] ?? 0) === $month) {
            return (int)($item['learning_completed'] ?? 0);
        }
    }
    return 0;
}

try {
    $db = getDB();
    adminRequireAuth('adminCanAccessHeadquarter');

    $today = date('Y-m-d');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

    $summary = [
        'total_stores' => 0,
        'total_staff' => 0,
        'today_workload_submit_count' => 0,
        'monthly_learning_complete_count' => 0,
        'today_revenue' => 0,
    ];

    $stmt = $db->query("SELECT COUNT(*) FROM stores WHERE status = 1");
    $summary['total_stores'] = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM staffs WHERE status = 1");
    $summary['total_staff'] = (int)$stmt->fetchColumn();

    if (adminTableExists($db, 'campaign_daily_entries')) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM campaign_daily_entries WHERE entry_date = ?");
        $stmt->execute([$today]);
        $summary['today_workload_submit_count'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT store, role_type, person_name, data_json FROM campaign_daily_entries WHERE entry_date = ? ORDER BY store ASC, role_type ASC, person_name ASC");
        $stmt->execute([$today]);
        $todayEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $todayEntries = [];
    }

    $stmt = $db->prepare("SELECT COALESCE(SUM(courses_completed + knowledge_cards_completed + drills_completed), 0) FROM monthly_statistics WHERE year = ? AND month = ?");
    $stmt->execute([$year, $month]);
    $summary['monthly_learning_complete_count'] = (int)$stmt->fetchColumn();

    $todayRevenue = 0;
    $storeRevenueMap = [];
    $storeWorkloadMap = [];

    foreach ($todayEntries as $entry) {
        $storeName = trim((string)($entry['store'] ?? ''));
        $payload = json_decode((string)($entry['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $storeWorkloadMap[$storeName] = ($storeWorkloadMap[$storeName] ?? 0) + 1;

        if (($entry['role_type'] ?? '') === '店长') {
            $revenue = (float)($payload['renewAmt'] ?? 0) + (float)($payload['newAmt'] ?? 0);
            $todayRevenue += $revenue;
            $storeRevenueMap[$storeName] = ($storeRevenueMap[$storeName] ?? 0) + $revenue;
        }
    }

    $summary['today_revenue'] = (int)round($todayRevenue);

    $storeSql = "SELECT s.id, s.name, s.manager_name,
        COUNT(DISTINCT stf.id) AS staff_count,
        COALESCE(SUM(ms.courses_completed + ms.knowledge_cards_completed + ms.drills_completed), 0) AS learning_completed,
        COALESCE(AVG(ms.pass_rate), 0) AS avg_pass_rate
        FROM stores s
        LEFT JOIN staffs stf ON stf.store_id = s.id AND stf.status = 1
        LEFT JOIN monthly_statistics ms ON ms.store_id = s.id AND ms.year = ? AND ms.month = ?
        WHERE s.status = 1
        GROUP BY s.id, s.name, s.manager_name
        ORDER BY learning_completed DESC, staff_count DESC, s.sort_order ASC";
    $stmt = $db->prepare($storeSql);
    $stmt->execute([$year, $month]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $storeRanking = [];
    $table = [];
    foreach ($stores as $store) {
        $name = (string)$store['name'];
        $workloadSubmitted = (int)($storeWorkloadMap[$name] ?? 0);
        $learningCompleted = (int)($store['learning_completed'] ?? 0);
        $weeklyCompletionRate = $workloadSubmitted > 0 ? min(100, round(($learningCompleted / max(1, $workloadSubmitted * 3)) * 100)) : 0;
        $monthRevenue = (int)round($storeRevenueMap[$name] ?? 0);
        $avgPassRate = round((float)($store['avg_pass_rate'] ?? 0), 1);

        $riskStatus = '正常';
        if ($workloadSubmitted <= 0) {
            $riskStatus = '待跟进';
        } elseif ($avgPassRate < 60) {
            $riskStatus = '学习风险';
        } elseif ($monthRevenue <= 0) {
            $riskStatus = '业绩待录入';
        }

        $storeRanking[] = [
            'store_id' => (int)$store['id'],
            'store_name' => $name,
            'value' => $learningCompleted,
            'secondary_value' => $monthRevenue,
            'staff_count' => (int)($store['staff_count'] ?? 0),
        ];

        $table[] = [
            'store_id' => (int)$store['id'],
            'store_name' => $name,
            'manager_name' => (string)($store['manager_name'] ?? '-'),
            'today_workload_submit_count' => $workloadSubmitted,
            'weekly_learning_completion_rate' => $weeklyCompletionRate,
            'monthly_revenue' => $monthRevenue,
            'risk_status' => $riskStatus,
        ];
    }

    $staffSql = "SELECT s.id, s.name, s.role, st.name AS store_name,
        COALESCE(SUM(ms.courses_completed + ms.knowledge_cards_completed + ms.drills_completed), 0) AS learning_completed,
        COALESCE(AVG(ms.pass_rate), 0) AS avg_pass_rate,
        COALESCE(SUM(ms.checkin_days), 0) AS checkin_days
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        LEFT JOIN monthly_statistics ms ON ms.staff_id = s.id AND ms.year = ? AND ms.month = ?
        WHERE s.status = 1
        GROUP BY s.id, s.name, s.role, st.name
        ORDER BY
            CASE WHEN COALESCE(SUM(ms.courses_completed + ms.knowledge_cards_completed + ms.drills_completed), 0) > 0 THEN 1 ELSE 0 END DESC,
            COALESCE(SUM(ms.courses_completed + ms.knowledge_cards_completed + ms.drills_completed), 0) DESC,
            COALESCE(AVG(ms.pass_rate), 0) DESC,
            COALESCE(SUM(ms.checkin_days), 0) DESC,
            st.name ASC,
            FIELD(s.role, 'manager', 'coach', 'sales') ASC,
            s.name ASC
        LIMIT 8";
    $stmt = $db->prepare($staffSql);
    $stmt->execute([$year, $month]);
    $staffRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $staffRanking = array_map(static function ($row) {
        return [
            'staff_id' => (int)$row['id'],
            'staff_name' => (string)$row['name'],
            'role' => (string)$row['role'],
            'store_name' => (string)($row['store_name'] ?? '-'),
            'value' => (int)($row['learning_completed'] ?? 0),
            'pass_rate' => round((float)($row['avg_pass_rate'] ?? 0), 1),
        ];
    }, $staffRows);

    $trendSql = "SELECT month,
        COALESCE(SUM(courses_completed + knowledge_cards_completed + drills_completed), 0) AS learning_completed
        FROM monthly_statistics
        WHERE year = ? AND month BETWEEN ? AND ?
        GROUP BY month
        ORDER BY month ASC";
    $startMonth = max(1, $month - 6);
    $stmt = $db->prepare($trendSql);
    $stmt->execute([$year, $startMonth, $month]);
    $trendRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $trendMonthly = [];
    for ($cursor = $startMonth; $cursor <= $month; $cursor++) {
        $trendMonthly[] = [
            'label' => sprintf('%02d月', $cursor),
            'workload_submit_count' => $cursor === $month ? $summary['today_workload_submit_count'] : 0,
            'learning_complete_count' => adminDashboardMonthValue($trendRows, $cursor),
            'revenue' => $cursor === $month ? $summary['today_revenue'] : 0,
        ];
    }

    jsonResponse(0, 'success', [
        'summary' => $summary,
        'store_ranking' => array_slice($storeRanking, 0, 6),
        'staff_ranking' => $staffRanking,
        'trend_monthly' => $trendMonthly,
        'table' => array_slice($table, 0, 10),
        'meta' => [
            'today' => $today,
            'year' => $year,
            'month' => $month,
            'workload_source' => adminTableExists($db, 'campaign_daily_entries') ? 'campaign_daily_entries' : 'unavailable',
            'learning_source' => 'monthly_statistics',
            'revenue_source' => adminTableExists($db, 'campaign_daily_entries') ? 'campaign_daily_entries' : 'unavailable',
        ],
    ]);
} catch (Throwable $e) {
    error_log('[admin.dashboard.overview] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
