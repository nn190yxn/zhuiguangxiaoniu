<?php
require '/www/wwwroot/122.51.223.46/api/config.php';

$db = getDB();
$year = (int)date('Y');
$month = (int)date('n');

$sql = "SELECT s.id, s.name, s.role, s.store_id, st.name AS store_name,
    COALESCE(SUM(ms.courses_completed + ms.knowledge_cards_completed + ms.drills_completed), 0) AS learning_completed,
    COALESCE(AVG(ms.pass_rate), 0) AS avg_pass_rate,
    COALESCE(SUM(ms.checkin_days), 0) AS checkin_days,
    COUNT(ms.id) AS ms_rows
    FROM staffs s
    LEFT JOIN stores st ON st.id = s.store_id
    LEFT JOIN monthly_statistics ms ON ms.staff_id = s.id AND ms.year = ? AND ms.month = ?
    WHERE s.status = 1
    GROUP BY s.id, s.name, s.role, s.store_id, st.name
    ORDER BY learning_completed DESC, avg_pass_rate DESC, checkin_days DESC
    LIMIT 12";

$stmt = $db->prepare($sql);
$stmt->execute([$year, $month]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'year' => $year,
    'month' => $month,
    'staff_active_count' => (int)$db->query("SELECT COUNT(*) FROM staffs WHERE status = 1")->fetchColumn(),
    'monthly_statistics_rows' => (int)$db->query("SELECT COUNT(*) FROM monthly_statistics WHERE year = $year AND month = $month")->fetchColumn(),
];

echo json_encode(['summary' => $summary, 'top_staff_rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
