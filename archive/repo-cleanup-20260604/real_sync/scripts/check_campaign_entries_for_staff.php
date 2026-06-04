<?php

require_once __DIR__ . '/../api/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$date = $argv[1] ?? date('Y-m-d');
$names = array_slice($argv, 2);
if (!$names) {
    $names = ['吴丽沙', '陈美琴'];
}

$db = getDB();
foreach ($names as $name) {
    $stmt = $db->prepare('SELECT id,entry_date,store,role_type,person_name,data_json,updated_at FROM campaign_daily_entries WHERE entry_date=? AND person_name=? ORDER BY id DESC');
    $stmt->execute([$date, $name]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo $name . ' => ' . json_encode($rows, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
