# v1.40.0 — Unified proactive Commerce Agent

This release adds a role-scoped, read-only Commerce Agent report under Commerce → Agent briefing and within the existing Agent Brain/Chat context. It fixes Commerce links from the Agent activity drawer and improves read-only follow-ups using the existing deduplicated commerce_activity outbox and existing Chat, History, bell and Brain surfaces. It does not add a separate notification system.

The administrator report covers payment reconciliation, returns, shipping, provider exceptions, and creator settlement drafts. The creator report is restricted to that account's balance and settlement status. Reports are organized as Key findings, Actions & follow-ups, Open questions, Next step, and Questions for you. Agent Chat includes a bounded, authenticated excerpt of the same role-scoped report.

A private hourly scan can queue staff alerts when an unresolved provider operation crosses 30 minutes or 24 hours, or an approved creator payout remains unconfirmed for 48 hours. Each record/status/urgency stage is deduplicated in the existing commerce_activity outbox, then delivered through the existing Chat + History + bell. An explicit staff “Check follow-ups” button runs the same safe scan. No external provider API calls occur during reports/scans.

## Safety

- No automatic Stripe refund, EasyPost purchase, Connect transfer, manual payout, bank deposit confirmation or order status mutation.
- Live financial actions remain disabled by default. Hosted provider TEST transactions and signed webhooks must be independently verified before any enablement decision.
- No customers, addresses, provider IDs, credentials, evidence references or other models' earnings are included in a creator's briefing or the Agent Chat excerpt.
- Reports reflect recorded ledgers; Stripe Connect transfers are distinct from bank deposits.
- No schema migration: the database stays at schema **43**.

## Validation

- php tests/commerce_agent_rules.php — pure role-scoped report, prioritization, no financial action implications.
- php tests/commerce_agent_sql.php — SQLite age thresholds, notification key dedup dimensions, source-role data isolation.
- node --test tests/commerce-agent-v140.test.mjs — safe Commerce URLs, malicious link rejection, UI routing.
- python tests/commerce_agent_browser.py — responsive Chrome desktop/tablet/phone and no financial POSTs during viewing.
- Existing five-job CI on final branch commit, plus post-merge five-job CI before deployment.
