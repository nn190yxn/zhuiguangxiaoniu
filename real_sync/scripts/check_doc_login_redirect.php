<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$login = file_get_contents('/www/wwwroot/122.51.223.46/mobile/login.html') ?: '';
$mine = file_get_contents('/www/wwwroot/122.51.223.46/mobile/mine.html') ?: '';

echo 'LOGIN_FORCE_REDIRECT_PRESERVED=' . (str_contains($login, 'force_change_password=1&redirect=') ? 'YES' : 'NO') . PHP_EOL;
echo 'LOGIN_LAST_USERNAME_STORED=' . (str_contains($login, 'last_login_username') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINE_REFRESH_AFTER_CHANGE=' . (str_contains($mine, 'refreshLoginAfterPasswordChange') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINE_REDIRECT_AFTER_CHANGE=' . (str_contains($mine, "get('redirect')") ? 'YES' : 'NO') . PHP_EOL;

$phone = $argv[1] ?? '';
if ($phone !== '') {
    $docCheck = shell_exec('php /www/wwwroot/122.51.223.46/scripts/check_doc_viewer.php ' . escapeshellarg($phone) . ' v4-00 2>&1');
    echo $docCheck;
}
