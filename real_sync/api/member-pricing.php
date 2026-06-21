<?php
declare(strict_types=1);

require_once __DIR__ . '/common/context.php';

handleCORS();
appJsonError(404, '当前内网项目未启用会员收费标准模块');
