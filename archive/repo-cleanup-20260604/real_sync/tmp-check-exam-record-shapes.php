<?php
require '/www/wwwroot/122.51.223.46/api/config.php';
$db = getDB();
$stmt = $db->query("SELECT id, answers, created_at FROM exam_records ORDER BY id DESC LIMIT 30");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data = json_decode($row['answers'], true);
    $keys = array_keys(is_array($data) ? $data : []);
    $min = null;
    $max = null;
    foreach ($keys as $k) {
        if (is_numeric($k)) {
            $k = (int)$k;
            $min = $min === null ? $k : min($min, $k);
            $max = $max === null ? $k : max($max, $k);
        }
    }
    echo json_encode([
        'id' => (int)$row['id'],
        'created_at' => $row['created_at'],
        'min_qid' => $min,
        'max_qid' => $max,
        'key_count' => count($keys),
    ], JSON_UNESCAPED_UNICODE) . "\n";
}
