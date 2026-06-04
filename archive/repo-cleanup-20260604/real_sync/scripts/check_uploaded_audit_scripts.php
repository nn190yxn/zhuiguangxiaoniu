<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$files = [
    '/www/wwwroot/122.51.223.46/scripts/check_doc_auth_chain.php',
    '/www/wwwroot/122.51.223.46/scripts/check_doc_viewer.php',
    '/www/wwwroot/122.51.223.46/scripts/check_doc_login_redirect.php',
    '/www/wwwroot/122.51.223.46/scripts/audit_workload_h5_e2e.php',
    '/www/wwwroot/122.51.223.46/scripts/audit_workload_mini.php',
    '/www/wwwroot/122.51.223.46/scripts/audit_workload_xss.php',
    '/www/wwwroot/122.51.223.46/scripts/audit_workload_p0.php',
    '/www/wwwroot/122.51.223.46/scripts/check_workload_summary.php',
    '/www/wwwroot/122.51.223.46/scripts/check_admin_login_audit.php',
    '/www/wwwroot/122.51.223.46/scripts/check_workload_flow.php',
    '/www/wwwroot/122.51.223.46/scripts/check_common_context.php',
    '/www/wwwroot/122.51.223.46/scripts/audit_login_audit_write.php',
];

foreach ($files as $file) {
    echo basename($file) . '=' . (is_file($file) ? 'YES' : 'NO') . PHP_EOL;
}
