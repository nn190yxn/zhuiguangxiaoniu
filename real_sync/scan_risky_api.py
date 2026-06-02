import os

root = '/www/wwwroot/122.51.223.46/api'
markers = [
    'getCurrentUserId(',
    'getJwtCurrentUser(',
    'requireLogin(',
    'jsonError(401',
    'jsonResponse(401',
    'checkJwtAuth',
    'ensureAdminRole',
    'isSuperAdminUser',
]
signals = [
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'jsonSuccess(',
    'jsonResponse(',
    'echo json_encode',
    'file_get_contents(',
]

safe_markers = [
    "php_sapi_name() !== 'cli'",
    "php_sapi_name() != 'cli'",
    "PHP_SAPI !== 'cli'",
    "PHP_SAPI != 'cli'",
    'http_response_code(403',
    'forbidden',
    'Direct access is not allowed',
    'This file cannot be accessed directly',
    'require_once __DIR__ . \'/config.php\'',
]


def is_cli_only(content):
    return (
        "php_sapi_name()" in content and "cli" in content
    ) or (
        "PHP_SAPI" in content and "cli" in content
    )


def is_direct_access_blocked(content):
    blocked_text = [
        'http_response_code(403',
        'exit(\'Forbidden\')',
        'die(\'Forbidden\')',
        'Direct access is not allowed',
        'This file cannot be accessed directly',
        '__FILE__',
        '$_SERVER[\'SCRIPT_FILENAME\']',
    ]
    return any(marker in content for marker in blocked_text) and (
        '__FILE__' in content or 'SCRIPT_FILENAME' in content or '403' in content
    )

risky = []
total = 0

for dp, _, fs in os.walk(root):
    for f in fs:
        if not f.endswith('.php'):
            continue
        total += 1
        path = os.path.join(dp, f)
        try:
            s = open(path, 'r', encoding='utf-8', errors='ignore').read()
        except Exception:
            continue
        if any(m in s for m in markers):
            continue
        if any(m in s for m in safe_markers):
            if is_cli_only(s) or is_direct_access_blocked(s):
                continue
        if any(sig in s for sig in signals):
            risky.append(path)

print('TOTAL_PHP', total)
print('RISKY_NO_AUTH', len(risky))
for path in sorted(risky):
    print(path)
