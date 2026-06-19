# Server to GitHub Final Sync Report

Date: 2026-06-05

## Baseline

- Server runtime directory: `/www/wwwroot/122.51.223.46/`
- Git repository: `/workspace/real_sync`
- GitHub main source directory: `/workspace/real_sync/real_sync/`
- Remote: `https://github.com/nn190yxn/zhuiguangxiaoniu.git`

## Pushed Commits

- `c8bb5b8 sync: align tracked files with server baseline`
- `aa30df0 chore: remove files absent from server baseline`
- `35cd2ee sync: add server baseline business modules`
- `5919719 sync: add server baseline site sources`

## Completed Work

### Tracked File Alignment

- Pulled same-path server runtime changes into tracked GitHub files.
- Excluded sensitive config candidates, line-ending-only changes, test bypass code, and hardcoded credential scripts.

### Removed Server-Missing GitHub Residue

- Removed 69 tracked files that were present in GitHub but absent from the server runtime baseline after user confirmation.
- Removed items were primarily flat root copies, deployment artifacts, historical pages, audit scripts, SQL/JSON data, and stale docs.

### Added Server-Only Business Modules

- Added 87 server-only source files after filtering.
- Included admin APIs, business APIs, policy mini-program pages, and drill script-record pages.

### Added Server-Only Site Sources

- Added 60 server-only site source files after filtering.
- Included static site pages, WordPress child-theme template sources, frontend auth helper, sitemap/robots, and project planning markdown files.

## Verification Performed

- Confirmed local `HEAD` matches `origin/main` after each push.
- Ran `git diff --check` or equivalent scoped checks before commits.
- Scanned staged diffs for hardcoded credentials, test bypass markers, private key markers, and known leaked password strings.
- Kept `.htaccess`, `.user.ini`, WordPress core files, uploads/media, test scripts, runtime artifacts, and duplicate mini-program tree out of Git.

## Remaining Excluded Items

- 68 items from `/tmp/opencode/server-only-exclude.txt`:
  - runtime or WordPress core: 23
  - test or audit scripts: 31
  - media or uploads: 5
  - artifacts or data: 9
- 131 items from `/tmp/opencode/server-only-skip2.txt`:
  - duplicate mini-program tree under `mobile/pages`: 122
  - config/init/test/maintenance scripts: 8
  - still unclear: 1 (`index.php`)

## Current State

- Latest pushed commit before this report: `59197195161156093c75a2ff0b2d0059c06249d2`
- Local repository and `origin/main` are aligned.
- Working tree was clean after the final source sync push.
