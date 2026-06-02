<?php
require '/www/wwwroot/122.51.223.46/api/config.php';

$db = getDB();
$ids = [2,3,4,5,6,7,8,9,10];

$db->beginTransaction();
try {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE staffs SET status = 0, updated_at = NOW() WHERE id IN ($in)");
    $stmt->execute($ids);
    $db->commit();
    echo 'UPDATED_ROWS=' . $stmt->rowCount() . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo 'ERROR=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
