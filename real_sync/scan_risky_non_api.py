import os

root = '/www/wwwroot/122.51.223.46'
skip_parts = ['/wp-admin', '/wp-includes', '/wp-content', '/wordpress']
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

safe_names = {
    'wp-cron.php',
    'xmlrpc.php',
    'wp-trackback.php',
}


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

for dp, _, fs in os.walk(root):
    if any(part in dp for part in skip_parts):
        continue
    for f in fs:
        if not f.endswith('.php'):
            continue
        if f in safe_names:
            continue
        path = os.path.join(dp, f)
        if '/api/' in path:
            continue
        try:
            s = open(path, 'r', encoding='utf-8', errors='ignore').read()
        except Exception:
            continue
        if any(m in s for m in markers):
            continue
        if is_cli_only(s) or is_direct_access_blocked(s):
            continue
        if any(sig in s for sig in signals):
            risky.append(path)

print('RISKY_NON_API', len(risky))
for path in sorted(risky)[:200]:
    print(path)
