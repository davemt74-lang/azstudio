# AZHottie Studio v1.42.0 — Admin Management Intelligence

The Admin Command Center now features an integrated management briefing based entirely on authenticated source records. This is a workflow-priority aid, not a candidate selection model.

## Added
- Management view: new casting applications, pending creator videos, provider exceptions, aged workflow cases, prioritized actions and evidence-backed commerce/audience watchpoints.
- 24-hour and 72-hour escalation for unreviewed casting and submitted videos; 7-day stalls for contacted/screening/interview workflows.
- Deduplicated Agent bell, Brain and History reminders, through the private hourly cron and an explicit administrator Check follow-ups action protected by CSRF.
- Administrator-only management snapshot in Agent Chat. Model accounts receive no private application or video management data.
- Responsive mobile and desktop briefing, without nested scrolling.

## Scope & privacy
- Neither personal attributes, names, questionnaire answers, private notes, nor unpublished media are fed into management triage or notifications.
- Work is prioritized by workflow age and status only, not candidate suitability or video quality.
- Approvals, publication, outreach, payments and transfers stay behind existing staff decisions and permissions.
- Commerce comparisons are contextual signals: Stripe checkout sessions, video play starts/completions, and session-bounded live product impressions are not unique-user metrics. Cash/manual receipts are not provider-verified.
- No new money movement or automatic activation of LIVE financial operations.

## Upgrade
1. Back up code, database, storage configuration, uploads and private media.
2. Deploy over existing application files; do not rerun the installer.
3. Database schema 44 to 45 creates admin_intelligence_deliveries and admin_intelligence_scans. A durable uniqueness constraint prevents repeat notification delivery.
4. Verify dashboard, Agent Chat, notification bell, hourly cron, Casting CRM, Video Submissions and Commerce links on hosted PHP/MySQL.
5. Keep Stripe/EasyPost/Connect LIVE financial actions disabled until independent hosted provider TEST verification.

## CI gates
PHP rule/SQLite migration tests and responsive Chromium tests are integrated into the existing five-job GitHub workflow. Hosted acceptance remains separate from code-level CI.
