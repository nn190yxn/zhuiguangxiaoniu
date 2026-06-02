from pathlib import Path
import re

BASE = Path('/www/wwwroot/122.51.223.46/api')

WRITE_WORDS = re.compile(
    r'INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM|move_uploaded_file|file_put_contents|mkdir\(|unlink\(|exec\(|shell_exec\(|system\(|copy\(',
    re.I,
)
AUTH_WORDS = re.compile(
    r'appRequireStaffContext|getCurrentUserId\(|getJwtCurrentUser\(|currentUserCanAdmin\(|requireAdminAuth\(|checkAdminPermission\(|appRequireViewStore\(|appRequireOperateStaff\(|ensureAdminAccess\(',
    re.I,
)
METHOD_WORDS = re.compile(r'REQUEST_METHOD|OPTIONS', re.I)


def rel(path: Path) -> str:
    return str(path).replace('/www/wwwroot/122.51.223.46/', '')


print('## API_WRITE_AUTH_SCAN')
for p in sorted(BASE.rglob('*.php')):
    try:
        text = p.read_text(encoding='utf-8', errors='ignore')
    except Exception:
        continue
    has_write = bool(WRITE_WORDS.search(text))
    has_auth = bool(AUTH_WORDS.search(text))
    has_method = bool(METHOD_WORDS.search(text))
    if has_write or has_method:
        print(f'{rel(p)}\tWRITE={has_write}\tAUTH={has_auth}\tMETHOD={has_method}')


print('## ADMIN_AUTH_PATTERN_SCAN')
admin_base = BASE / 'admin'
for p in sorted(admin_base.rglob('*.php')):
    try:
        text = p.read_text(encoding='utf-8', errors='ignore')
    except Exception:
        continue
    print('###', rel(p))
    for key in [
        "require_once __DIR__ . '/common.php'",
        'currentUserCanAdmin(',
        'getCurrentUserId(',
        'appRequireStaffContext(',
        'REQUEST_METHOD',
    ]:
        print(f'{key}\t{key in text}')
