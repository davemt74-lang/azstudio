# AZHottie v1.54.0 — Creator Onboarding & Talent Management

## Builds on existing Casting CRM

Keeps all pre-existing applicant forms, human screening stages, role invitations, model onboarding, and canonical work assignments in the original Casting and Work modules. **No automatic applicant acceptance, applicant scoring, identity lookup, or private questionnaire exposure** is introduced.

## Added

- Administrator-only **Casting → Talent Pipeline**: live totals for the pre-existing casting stages; due and overdue staff follow-ups; recently converted creator roster, verified public-video play starts for the last 30 UTC days, and original four onboarding checkpoints. Each card links to the canonical applicant, onboarding, creator-analytics, and production-workspaces pages.
- **Private follow-ups** from an application detail with an explicit owner, action, and Phoenix-local date/time deadline (future 5 minutes–365 days). Staff alone can complete or cancel a follow-up, providing a completion reason; every state transition is audited. Optimistic revision protection stops stale-tab double updates. Follow-ups never automatically email, approve, invite, or assign applicants.
- Existing Casting Agent briefing shows only an aggregate count of overdue follow-ups; it does not expose private notes to the model or create unsolicited contact.
- Responsive layout including wrap-safe casting tabs on narrow 320px phones; no nested page scrollbars intended.

## Database and deployment

First authenticated application boot upgrades **schema 49 → 50** through new `casting_followups` and `casting_followup_history` tables. No changes to legacy personal applicant fields. Indexes support due-date and lead-scoped retrieval. Back up database/media/configuration; deploy over existing v1.53 files; preserve private `storage/` and uploads and **do not rerun installer**.

## Verification

- Existing PHP/SQLite, Commerce, Live, admin and role suites remain required.
- New PDO SQLite integration checks schema migration replay, Phoenix conversion, invalid deadlines, audit, optimistic concurrency, overdue follow-up, and verified public creator metrics. Run through CI's PHP job.
- New Node regression checks server role scope, CSRF, no automatic selection, cross-module links, and installed-site migrations.
- New real Chromium responsive suite at 1440, 768, 390 and 320px, including navigation and follow-up controls; run through CI.
- Full 5-job GitHub CI required on final head and postmerge `main`, deployment ZIP from postmerge artifact only.

## Operator acceptance

Staff must test casting permissions and real applicant-to-model invitation flow, converted onboarding and task history on hosted MySQL/SQLite. Browser fixture acceptance alone does not certify real hosted data or external email delivery.
