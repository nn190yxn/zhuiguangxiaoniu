<?php
require '/www/wwwroot/122.51.223.46/api/config.php';
$db = getDB();
$stmt = $db->prepare('SELECT s.id,s.name,s.phone,s.role,s.user_id,s.store_id,u.user_login,u.user_status FROM staffs s LEFT JOIN wp_users u ON u.ID=s.user_id WHERE s.phone=? LIMIT 1');
$stmt->execute(['18285031172']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
