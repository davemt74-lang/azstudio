# AZHottie v1.43.0 — Admin Agent Action Center

## What's new

The existing **Admin Command Center** includes a compact staff-owned Action Center. Administrators can track current management recommendations directly, create tasks from overdue casting and video review workflows, assign owners, set UTC due dates, acknowledge work, resolve with a required written reason, reopen when needed, and review an immutable event history.

- **Server-approved recommendations:** The browser sends an opaque source kind, source ID and stage. The server rechecks the live management briefing before tracking. Client-provided titles or URLs are not accepted as source authority.
- **Role isolation:** Only active administrator accounts may read, create, update, assign or acknowledge tasks. Only active administrator accounts appear in the owner chooser; creators cannot view the queue.
- **Durable audit:** Assignments, acknowledgment, resolution and reopening use revision-checked writes and append immutable history. A concurrent edit returns a conflict, never silently overwrites staff changes.
- **Follow-up loop:** The existing hourly private cron scans up to 100 aged casting/video workflow records, auto-tracks untracked cases and checks due tasks. A due reminder is delivered once; tasks overdue by 24 hours or more are reminded at most once per UTC day, only if the underlying source is still active.
- **One Agent:** Reminders use the existing Agent bell, Brain, Chat and History through the current activity table. No parallel inbox or new task service.
- **Commerce work:** Provider reconciliation, unverified external orders and creator settlement recommendations may be tracked as staff-review tasks, never as payment execution requests.

## Safeguards

Closing a task does **not** approve/reject a casting application, review/publish a creator video, verify an order, send a Stripe refund, buy EasyPost postage, send a Connect transfer, or confirm a bank deposit. Task details are based on predefined server workflow descriptions; applicant names, contact details, questionnaire answers and unpublished media are not copied into automatic tasks.

Task due notifications are suppressed when an applicant or video source has progressed out of the original state. Staff still control task closure and its resolution note. Financial-provider TEST acceptance and actual bank payouts remain independently verified processes.

## Upgrade

1. Back up code, database, configuration, uploads, and private media.
2. Deploy the full ZIP over the existing application files. Do not rerun the installer.
3. Schema upgrades **45 → 46**, creating three Action Center tables; no original order, casting or video tables are modified.
4. Verify the Admin Command Center, staff task ownership, optimistic concurrency, due dates, Agent notifications and audit history on hosted PHP/MySQL.
5. Confirm private hourly cron executes as configured; live-money operations remain disabled until independent provider TEST acceptance.

## Automated verification

- PHP SQLite tests for schema 46, role permissions, source binding, owner selection, invalid dates, revision conflicts, full task lifecycle, notification uniqueness and source-status suppression.
- Node JS regression tests for endpoint access, source authorization, Agent Chat/Brain/cron hooks and absence of financial provider execution actions.
- Chromium browser acceptance at 1440 / 1024 / 768 / 390 / 320px for read-only initial load, explicit CSRF mutation, reassignment, acknowledgment, resolution/reopening and mobile overflow.
- Full five-job GitHub pre-merge and post-merge CI, including deployment ZIP packaging.

Code-level CI success does not verify production hosted MySQL or real external provider transactions.
