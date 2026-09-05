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
  - The complete suite currently reports 1468 tests, 1460 passing, 8 skipped, and 0 failures.
