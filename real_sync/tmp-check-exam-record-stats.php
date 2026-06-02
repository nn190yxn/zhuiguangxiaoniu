<?php
require '/www/wwwroot/122.51.223.46/api/config.php';
$db = getDB();

echo "=== total_records ===\n";
$stmt = $db->query("SELECT COUNT(*) AS c FROM exam_records");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "=== records_by_day ===\n";
$stmt = $db->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM exam_records GROUP BY DATE(created_at) ORDER BY d DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "=== records_recent ===\n";
$stmt = $db->query("SELECT id, user_id, exam_type, total_score, status, created_at FROM exam_records ORDER BY id DESC LIMIT 30");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
