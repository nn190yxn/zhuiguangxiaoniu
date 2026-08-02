<?php
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once dirname(__DIR__) . '/common/context.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';

function summerCampDb(): PDO
{
    return getDB();
}

function summerCampEnsureSchema(PDO $pdo): void
{
    platformRequireMigrationReadiness($pdo, ['202607310009']);
}

function summerCampValidateCampType(string $type): bool
{
    $validTypes = ['zhongkao', 'tineng', 'tiaosheng', 'lanqiu', 'tuobei'];
    return in_array($type, $validTypes, true);
}

function summerCampGetCampName(string $type): string
{
    $names = [
        'zhongkao' => '中考体训达标营',
        'tineng' => '体能达标营',
        'tiaosheng' => '跳绳达标营',
        'lanqiu' => '篮球体能营',
        'tuobei' => '驼背体态调整营'
    ];
    return $names[$type] ?? '未知营类型';
}

function summerCampJsonError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function summerCampJsonSuccess(array $data, string $message = 'success'): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
