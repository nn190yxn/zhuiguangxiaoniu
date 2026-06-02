<?php
require '/www/wwwroot/122.51.223.46/api/config.php';

$db = getDB();
$sql = "SELECT s.id,s.name,s.phone,s.status,s.user_id,u.ID AS wp_id,u.user_status
        FROM staffs s
        LEFT JOIN wp_users u ON u.ID=s.user_id
        WHERE s.status=1";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$missingUser = 0;
$missingPhone = 0;
$disabledWp = 0;

foreach ($rows as $r) {
    if ((int)($r['user_id'] ?? 0) <= 0 || empty($r['wp_id'])) {
        $missingUser++;
    }
    if (trim((string)($r['phone'] ?? '')) === '') {
        $missingPhone++;
    }
    if ((int)($r['user_status'] ?? 0) !== 0) {
        $disabledWp++;
    }
}

echo 'TOTAL_ACTIVE_STAFF=' . $total . PHP_EOL;
echo 'MISSING_USER_LINK=' . $missingUser . PHP_EOL;
echo 'MISSING_PHONE=' . $missingPhone . PHP_EOL;
echo 'WP_DISABLED=' . $disabledWp . PHP_EOL;

if ($missingUser || $missingPhone || $disabledWp) {
    echo 'ISSUES:' . PHP_EOL;
    foreach ($rows as $r) {
        $issues = [];
        if ((int)($r['user_id'] ?? 0) <= 0 || empty($r['wp_id'])) {
            $issues[] = 'NO_USER';
        }
        if (trim((string)($r['phone'] ?? '')) === '') {
            $issues[] = 'NO_PHONE';
        }
        if ((int)($r['user_status'] ?? 0) !== 0) {
            $issues[] = 'WP_DISABLED';
        }
        if ($issues) {
            echo ($r['id'] ?? '') . '|' . ($r['name'] ?? '') . '|' . ($r['phone'] ?? '') . '|' . implode(',', $issues) . PHP_EOL;
        }
    }
}
