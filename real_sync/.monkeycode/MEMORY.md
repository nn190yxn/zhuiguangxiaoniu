# User Instruction Memory

This file records user instructions, preferences, and teachings for reference in future interactions.

## Entries

[User Instruction Summary]
- Date: 2026-08-23
- Context: Task execution workflow
- Instructions:
  - Continue with safe, well-supported next steps when the path is clear.
  - Stop and ask for clarification when an unresolved decision would materially change the implementation.
  - After implementing a feature, run text-reply replay tests to verify that practical coverage, risk detection, replacement wording, and next-step advice work as expected.

[Static Website Preview]
- Date: 2026-08-24
- Context: Discovered by Agent while previewing public website content
- Category: Operations & Deployment
- Instructions:
  - Preview the static website from the repository root with `python3 -m http.server 8001 --directory /workspace/real_sync`.
  - Verify the homepage, `/news/`, article pages, and referenced assets return HTTP 200 before delivery.
