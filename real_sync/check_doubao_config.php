<?php
declare(strict_types=1);

require '/www/wwwroot/122.51.223.46/api/config.php';

$pdo = getDB();
$keys = array('doubao_api_key', 'doubao_model');
foreach ($keys as $key) {
    $stmt = $pdo->prepare('SELECT setting_value FROM ai_settings WHERE setting_key = ?');
    $stmt->execute(array($key));
    $value = (string) ($stmt->fetchColumn() ?: '');
    if ($key === 'doubao_api_key') {
        echo $key . ':' . strlen($value) . PHP_EOL;
    } else {
        echo $key . ':' . $value . PHP_EOL;
    }
}
