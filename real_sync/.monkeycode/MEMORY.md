# User Instruction Memory

This file records user instructions, preferences, and teachings for reference in future interactions.

## Entries

[User Instruction Summary]
- Date: 2026-08-23
- Context: Task execution workflow
- Instructions:
  - Continue with safe, well-supported next steps when the path is clear.
  - Resolve implementation choices autonomously when the agreed plan provides direction.
  - Stop and ask for clarification when an unresolved decision would materially change the implementation.
  - After implementing a feature, run text-reply replay tests to verify that practical coverage, risk detection, replacement wording, and next-step advice work as expected.

[Static Website Preview]
- Date: 2026-08-24
- Context: Discovered by Agent while previewing public website content
- Category: Operations & Deployment
- Instructions:
  - Preview the static website from the repository root with `python3 -m http.server 8001 --directory /workspace/real_sync`.
  - Verify the homepage, `/news/`, article pages, and referenced assets return HTTP 200 before delivery.

[User Instruction Summary]
- Date: 2026-08-24
- Context: User asked to keep explanations plain and accessible
- Instructions:
  - Reply in plain, easy-to-understand language; avoid jargon and technical terminology unless the user asks for detail.

[Project Knowledge Summary]
- Date: 2026-09-03
- Context: Discovered by Agent while registering the smart lesson review domain
- Category: Testing Methods
- Instructions:
  - Run `node --test scripts/platform_business_domain_migration.test.mjs scripts/miniprogram_business_domain_matrix.test.mjs scripts/miniprogram_api_proxy.test.mjs` to verify the platform registry, mini-program matrix, and deployed proxy route synchronization.

[User Instruction Summary]
- Date: 2026-09-04
- Context: Frontend work in the real_sync project
- Instructions:
  - After making changes, run the relevant contract tests and `git diff --check`.
  - Do not commit or push changes unless explicitly requested.

[Project Knowledge Summary]
- Date: 2026-09-05
- Context: Discovered by Agent while running the full release audit
- Category: Testing Methods
- Instructions:
  - Run the complete Node test suite from `/workspace/real_sync` with `node --test $(rg --files scripts -g '*.test.mjs')` so tests using relative PHP paths resolve correctly.
  - The complete suite currently reports 1478 tests, 1470 passing, 8 skipped, and 0 failures after the PHP `ZipArchive` extension, static viewport contract, global search contract, and coach-growth lesson/knowledge contracts were corrected.

[Project Knowledge Summary]
- Date: 2026-09-05
- Context: Discovered by Agent while validating release browser flows
- Category: Troubleshooting & Debugging
- Instructions:
  - The static preview at `127.0.0.1:8001` can validate HTML routes and browser resource loading with Chromium.
  - The static preview does not provide the PHP authentication and business API runtime required for authenticated role-flow evidence; keep browser integration evidence pending until those services and test identities are available.

[Project Knowledge Summary]
- Date: 2026-09-06
- Context: Discovered by Agent while starting the local PHP API runtime
- Category: Environment Configuration
- Instructions:
  - The PHP API runtime requires `DB_PASSWORD` and `JWT_SECRET` through the process environment or `api/.env.local.php`.
  - Missing either required value makes authenticated API probes return HTTP 500 with `server_configuration_error`.

[User Instruction Summary]
- Date: 2026-09-06
- Context: Release verification workflow
- Instructions:
  - Prefer direct verification against the server when the server is available.
  - Keep verification focused and avoid adding extra local or synthetic test machinery unless it is required to diagnose a concrete failure.

[User Instruction Summary]
- Date: 2026-09-06
- Context: Deployment troubleshooting
- Instructions:
  - Deployment credentials are available in the workspace, currently at `/workspace/zhuiguangxiaoniu.pem`; check workspace project files and deployment configuration before reporting that credentials are unavailable.
  - Record only the existence and lookup convention for credentials; never expose or copy credential values into chat or project documentation.

[Project Knowledge Summary]
- Date: 2026-09-06
- Context: Discovered by Agent while diagnosing the production knowledge list failure
- Category: Troubleshooting & Debugging
- Instructions:
  - The production `knowledge_items` text columns use `utf8mb4_general_ci`, while `knowledge_item_versions` and the new knowledge age-range tables use `utf8mb4_unicode_ci`.
  - Knowledge list SQL expressions and age-code comparisons combining those tables must explicitly normalize the expression collation before `IN` or equality comparisons, or MySQL returns `Illegal mix of collations` and the API responds with `internal_error`.

[Project Knowledge Summary]
- Date: 2026-09-07
- Context: Discovered by Agent while restoring the production website root entry
- Category: Troubleshooting & Debugging
- Instructions:
  - A production Nginx site root returns HTTP 403 when its configured document root has no file matching the configured `index` list.
  - Check the site root and Nginx `index` directive before changing routing; restore the repository entry file after creating a dated remote backup.

[Project Knowledge Summary]
- Date: 2026-09-08
- Context: Discovered by Agent while verifying production platform readiness
- Category: Operations & Deployment
- Instructions:
  - Use `GET /api/platform/health.php?check=live` for application liveness.
  - Use `GET /api/platform/health.php?check=ready` for database, migration, legacy governance, and workload readiness.

[User Instruction Summary]
- Date: 2026-09-08
- Context: Knowledge card content enrichment wording
- Instructions:
  - Use plain, conversational Chinese for employee-facing knowledge enhancements.
  - Prefer concrete phrases such as “这节课主要练什么”“上课时重点看什么”“容易出现的问题”和“安全提醒”.
  - Write guidance that employees can read aloud or apply directly in class.

[User Instruction Summary]
- Date: 2026-09-09
- Context: Production deployment troubleshooting
- Instructions:
  - When server access fails, continue systematic diagnosis instead of stopping at the first connection error.
  - Record the successful SSH parameters and the cause of intermittent access so future deployments use a repeatable procedure.

[Project Knowledge Summary]
- Date: 2026-09-09
- Context: Discovered by Agent while diagnosing Tencent Cloud production SSH access
- Category: Operations & Deployment
- Instructions:
  - Production host `122.51.223.46` accepts stable public-key sessions on SSH port `2222`; use port `2222` first for deployment.
  - Port `22` can complete TCP and key exchange but intermittently closes before authentication; treat it as an unreliable fallback.
  - Production SSH logs show repeated Internet pre-auth probes and a malformed `/etc/environment` entry reported by `pam_env`; this is server-side noise worth correcting during a maintenance window.
  - Use `BatchMode=yes`, `ConnectTimeout=15`, `ConnectionAttempts=1`, `ServerAliveInterval=5`, and `ServerAliveCountMax=1` for bounded deployment connections.
