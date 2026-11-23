# AZHottie Studio v1.44.0 — Agent Task Coordination

## Purpose
Connect the v1.43 Admin Action Center to real production assignments and scheduling without introducing a parallel task tracker or autonomous production-control service.

## Included
- Studio Command Center → Task Coordination: real active-production count, urgent production alerts, assigned-mode shots due within seven days without an active contributor, seven-day professional appointment overlap pairs, assignment approvals and staff task resolution events over the last week.
- Production blockers: assigned work awaiting a response for 48+ hours, retake requests waiting 48+ hours, takes pending staff review for 24+ hours, and assignments due within 48 hours or overdue. Cases use the original active project/shot/assignment ledger, never fabricated availability or outcomes.
- Scheduling pressure: upcoming confirmed appointments and published studio events over seven days, plus same-professional overlapping confirmed appointments. A counted overlap is a potential scheduling issue, not a booking cancellation.
- Staff workloads: outstanding Action Center tasks by active admin owner, due this week, overdue, and recent resolved tasks. Counts are tasks, not person-level performance judgments.
- Completion reporting: approved production assignments and Action Center resolution events in a rolling seven-day window. Reopening after a resolution may yield multiple resolution events; this measure is not unique creator outputs.
- Shared follow-through: Admin can click Track in Action Center on a current production risk. The server revalidates current status and stage before creating a task, uses the existing Action Center owner/due-date/audit machinery, and deduplicates by original source. The private hourly Action Center scan picks up eligible production blockers (up to a bounded sample), adding existing Agent bell/Brain/History reminders if the task remains due and source is still active.
- Agent Brain / Chat: admin-only, aggregate read-only coordination snapshot and safe source links. Models do not receive management-level staffing details.

## Safeguards
- No automatic production assignments, retake decisions, publication, calendar bookings, creator contact, payment verification or financial provider requests.
- No creator private notes, take footage, model application details, viewer identities or customer addresses in reminders or Agent Chat summaries. Workload signals reflect task counts rather than individual judgments.
- Original production and calendar states control follow-up eligibility. If a blocked assignment becomes approved, a source stage changes, or a shot receives an active contributor, reminders stop; staff remain responsible for task closure.
- Active database source table availability is checked. Missing tables are reported as unavailable, never misreported as all-clear.
- Existing schema 46 is unchanged. All live Stripe, EasyPost and Connect financial actions remain disabled by default.

## Deployment
1. Back up code, database, private configuration, uploads, and media.
2. Apply the full deployment ZIP over the existing application files; do not rerun the installer.
3. The schema remains 46 (no migration). Verify active production assignments, staff task loads and scheduling pressure on hosted PHP/MySQL.
4. Verify the private hourly cron and Action Center notification source checks.
5. Separately validate real cameras, hosted checkout attribution, and provider TEST transactions before enabling production financial operations.

## Quality and release gates
- PHP: tests/admin-coordination-v144.php — classification, unstaffed shots, appointment overlap, completion metrics, role isolation, Action Center tracking/dedup, original status closure.
- Node: tests/admin-coordination-v144.test.mjs — permissions, source linking, no financial or production mutations.
- Chromium: tests/admin-coordination-browser.py — 1440/1024/768/390/320 pixels, no horizontal overflow, read-only initial paint, explicit tracking with CSRF.
- Existing full five-job GitHub Actions gate, both on final PR commit and merged main; release archive integrity/contents.

CI completion is code-level assurance, not independent hosted real-world acceptance.
