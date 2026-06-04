<?php
/**
 * Admin pass progress stats API
 */
require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');
$db = getDB();

$stats = $db->query("SELECT
    s.stage,
    COUNT(*) as total_users,
    COUNT(CASE WHEN up.status = 'completed' THEN 1 END) as completed,
    ROUND(COUNT(CASE WHEN up.status = 'completed' THEN 1 END) * 100.0 / COUNT(*), 1) as completion_rate,
    AVG(up.progress_percent) as avg_progress
FROM staffs s
LEFT JOIN user_pass_progress up ON s.user_id = up.user_id
WHERE s.status = 1
GROUP BY s.stage
ORDER BY s.stage")->fetchAll(PDO::FETCH_ASSOC);

jsonSuccess(['list' => $stats]);
