<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Report file is required\n");
    exit(1);
}

$json = json_decode((string) file_get_contents($file), true);
if (!is_array($json)) {
    fwrite(STDERR, "Invalid report JSON\n");
    exit(1);
}

echo 'SCANNED=' . ($json['scanned_files'] ?? 0) . PHP_EOL;
echo 'HIGH_RISK=' . ($json['high_risk_no_auth_count'] ?? 0) . PHP_EOL;
$findings = $json['findings'] ?? [];
foreach (array_slice($findings, 0, 60) as $finding) {
    echo ($finding['path'] ?? '') . ' | ' . implode(',', $finding['risk_hits'] ?? []) . PHP_EOL;
}
