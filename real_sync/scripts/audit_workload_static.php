<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$base = '/www/wwwroot/122.51.223.46/api/workload';
foreach (glob($base . '/*.php') ?: [] as $file) {
    $name = basename($file);
    $source = file_get_contents($file) ?: '';
    if (str_starts_with($name, '_')) {
        echo $name . " LIB\n";
        continue;
    }
    echo $name
        . ' success=' . yn(str_contains($source, 'appJsonSuccess'))
        . ' error=' . yn(str_contains($source, 'appJsonError'))
        . ' auth=' . yn(str_contains($source, 'appRequireStaffContext'))
        . ' raw_echo=' . yn(str_contains($source, 'echo json_encode') || preg_match('/\bprint\s+/', $source))
        . ' manual_header=' . yn(str_contains($source, "header('Content-Type") || str_contains($source, 'header("Content-Type'))
        . ' direct_query=' . yn(str_contains($source, '->query('))
        . ' prepare=' . yn(str_contains($source, '->prepare('))
        . "\n";
}

function yn(bool $value): string
{
    return $value ? 'YES' : 'NO';
}
