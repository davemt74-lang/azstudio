# AZHottie Studio v1.45.0 — Agent Operations & Performance Learning

## Scope

Builds on the existing Admin Command Center, v1.43 Action Center, and v1.44 production coordination. No separate learning database or notification system is introduced. The source of truth remains the existing staff task ledger and immutable action-history events.

### Operations Learning
- Administrator-only 7-, 30-, and 90-day reports, selected interactively from the Command Center.
- New task counts, unique tasks with a resolution event, separate resolution/reopen event totals, acknowledgment events, live overdue backlog, and current completion status for tasks created in the selected window.
- Median hours from original task creation to the first **resolution event observed in the selected reporting window**, computed once per task. Reopenings may make this longer than the first lifetime resolution. No invented durations or misleading user-level productivity scores.
- Daily UTC created/resolved/reopened event trends; the browser groups 30/90-day charts into week-sized columns and supplies the daily figures in an accessible expandable detail view.
- Grouped recurring operational categories derived from safe server-owned source kinds and stages. Applicant names, private questionnaire answers, unreleased videos, customer details, employee evaluation notes, raw task descriptions and provider secrets never enter this report.
- Evidence-backed next actions for overdue queues, recurring reopenings, long time-to-resolution cycles, and repeated workflow types. Each recommendation shows a numeric trigger; no pattern is asserted without a minimum sample threshold.
- Same bounded staff-only insights surfaced in the existing Agent Brain and Agent Chat. The Agent receives aggregate evidence, not source IDs, personal records or a trained prediction model.

### Safety and accuracy
- Everything is read-only and derived from durable task history. No automatic publication, casting decision, staff assignment, calendar booking, external payment, refund, payout, postage purchase or financial status change.
- Task resolutions and reopenings are separately labeled events, not unique completed productions. The creation-to-resolution statistic uses a maximum of 5,000 events, discloses sampling/truncation, and does not claim causality or personnel performance.
- No evidence? No fabricated priority or percentage. The dashboard shows an empty-state recommendation.
- Endpoint is an authenticated administrator-only GET and accepts only 7, 30, or 90 as period selection; all dynamic values use textContent and local allowlisted links.
- Database schema remains **46**. No migration or changes to existing production, casting, commerce or Agent notification tables.
- Existing LIVE financial provider operations remain disabled until independent hosted TEST acceptance.

### Deployment and verification
1. Back up code, database, configuration, uploads, and private media.
2. Install the full ZIP over current application files without rerunning the installer.
3. Verify the reporting panel for empty/low-volume and normal data on hosted PHP/MySQL, including role isolation and correct UTC periods.
4. Confirm existing Brain, Agent Chat and Action Center still work; repeat the desktop/tablet/phone tests and existing financial safety checks.

### Automated gates
- PHP SQLite: tests/admin-ops-learning-v145.php — period authorization, median, cohorts, distinct tasks vs resolution events, reopenings, overdue backlog, patterns, safe recommendations and source-data privacy.
- Node: tests/admin-ops-learning-v145.test.mjs — role-gated endpoint, read-only SQL, no financial actions, Agent Brain/Chat wiring, dynamic text safety.
- Chromium: tests/admin-ops-learning-browser.py — 1440/1024/768/390/320px; 7/30/90-day chart, no overflow, injection safety, no financial POSTs.
- Full five-job GitHub pre-merge CI plus five post-merge CI jobs; deployment ZIP must come from merged main.

An automated code-level score does **not** replace independent hosted acceptance of external systems or real camera/payment operation.
