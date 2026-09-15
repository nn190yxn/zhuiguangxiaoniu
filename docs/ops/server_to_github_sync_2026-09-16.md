# Server to GitHub Sync (2026-09-16)

## Baseline

- Server runtime directory: `/www/wwwroot/122.51.223.46/` (production, source of truth)
- Repository site mirror: `/workspace/real_sync/`
- Remote: `https://github.com/nn190yxn/zhuiguangxiaoniu.git` (branch `main`)

## Method

1. Listed production source files by extension (`php html js mjs cjs css json sql md py wxml wxss wxs xml webmanifest txt yml yaml`),
   pruning runtime/backup trees (`.git`, `uploads`, `wp-admin`, `wp-includes`, `wp-content`, `logs`, `data`, `backups`,
   `.agent-backups`, `.agent-deploy-backups`, `.private`, `_private_docs`, `_archive`, `wordpress`, `.well-known`).
2. Downloaded the full filtered tree and compared it byte-for-byte with the repository mirror.
3. Applied the server version to every common path that differed and added every server-only source file.

## Results

- Common paths: 1879, all identical after sync.
- Tracked files updated to the server version: 92.
- Server-only source files added: 152 (recruitment/workload/platform APIs, mini-program agreement/skill/survey/webview pages,
  knowledge enrichment module, `login.html`, `workload.html`, `drill.html`, `detail.php`, `index.php`,
  `migration_catalog.php`, `migration_manifest.php`, `_admin_internal/*`, `体系文件_最终版/*`).
- Files removed: none. The 38 production-absent files were initially deleted, then restored after live probing
  showed production still references them (see Discrepancies below).

## Discrepancies Found During Sync (Reported, Not Fixed)

Production serves 404 for files that its own code links to or calls, so these repository-only files are the only
remaining copy and were intentionally kept:

1. `news/index.html` links to `about-brand.html`, `pricing.html`, `enrollment-guide.html`, `coach-team.html`,
   `guiyang-childrens-sports-brand.html`; all return `404` on `https://supercalf.com/news/...`.
2. `mini-program/pages/exam/list.js` (registered in `app.json`) calls `/exam/list.php` and `/exam/history.php`;
   both return `404` on `https://supercalf.com/api/exam/...`. Only `index.php`, `resume.php`, `save.php`,
   `submit.php` and the two services exist on the server.
3. `mini-program/pages/drill/free-chat/free-chat.js` requires `utils/privacy`, but `mini-program/utils/privacy.js`
   is absent from the server.

The remaining production-absent files are non-runtime artifacts: `courses/*` pages (server keeps a different
4-page set), root `project.config.json` / `project.private.config.json` (server keeps `mini-program/project.config.json`)
and `.preview-check/*` preview images.

## Pending Confirmation

- Commit and push of the 92 updated plus 152 added files is not yet performed.

## Intentionally Excluded From Git

Server files deliberately kept out of the repository:

- WordPress core/runtime: `wp-*.php`, `license.txt`, `readme.html`, `wp-config.php`
- Secrets: `api/.env.local.php`, server `wp-config.php`
- Sensitive data: `staff-import-20260506.json` (contains default passwords)
- Debug/remote artifacts: `api/admin/test.php`, `test-auth.html`, `ai-analyzer.remote.html`, `ai-drill.remote.html`

## Retained Repo-Only Items

Kept in the repository even though production does not serve them:

- `real_sync/scripts/**` local test suite
- `real_sync/database/**` knowledge phase2 migrations and import packages
- `real_sync/.monkeycode/**` and `docs/**` project documentation

## Verification

- Production server was not modified.
- No credentials, private keys, or known leaked password strings present in the staged diff.
- All 92 updated files re-hashed and matched the server versions.
