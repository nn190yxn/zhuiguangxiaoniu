<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$mobile = file_get_contents('/www/wwwroot/122.51.223.46/mobile/workload.html') ?: '';
$miniWxml = file_get_contents('/www/wwwroot/122.51.223.46/mini-program/pages/workload/index.wxml') ?: '';
$miniJs = file_get_contents('/www/wwwroot/122.51.223.46/mini-program/pages/workload/index.js') ?: '';

$h5HasMaxDate = str_contains($mobile, "getElementById('reportDate').max=today()")
    || str_contains($mobile, "getElementById('reportDate').max = today()");
echo 'H5_MAX_DATE=' . ($h5HasMaxDate ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_PICKER_END=' . (str_contains($miniWxml, 'end="{{maxDate}}"') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_MAX_DATE_DATA=' . (str_contains($miniJs, 'maxDate: today()') ? 'YES' : 'NO') . PHP_EOL;
echo 'H5_NON_INPUT_ROLE_EMPTY=' . (str_contains($mobile, '当前岗位无需提交销售/教练工作量日报') ? 'YES' : 'NO') . PHP_EOL;
echo 'MINI_NON_INPUT_ROLE_EMPTY=' . (str_contains($miniJs, '当前岗位无需提交销售/教练工作量日报') ? 'YES' : 'NO') . PHP_EOL;
