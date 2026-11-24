# AZHottie v1.53.0 — Publishing & Growth 2.0

## Scope

Extends the existing Publishing & Growth workspace and its admin-only approved social promotion workflow. No unrelated streaming, payment, or membership changes.

- Campaign Studio accepts **only administrator-approved `social` promotions** attached to a currently public model video and its exact approved source revision.
- Staff creates a private draft, can schedule a Phoenix-local **manual handoff**, and must explicitly **activate** the campaign's trackable link. Scheduled campaigns cannot activate before the handoff time. **There is no external social-platform API sending, no auto-posting and no provider account connection.**
- The manual export produces a reusable, copyable caption plus local tracking link; when a public approved video has a cover, the browser can generate a **1080×1920 PNG promotional story card**. This is cover artwork, **not** transcoded social-format video.
- Public `campaign.php` redirects only to the internal approved video on Watch. Draft, retired, withdrawn, changed-revision or otherwise ineligible content redirects to Watch home. Tracking uses **aggregate daily opens** with 30-minute PHP-session deduplication, no IP addresses, user IDs, browser fingerprints or viewer histories in the campaign tables. Opens are **not unique visitors, verified Watch plays, orders or attributable revenue**.
- Existing Publishing Specialist Brain flags due campaign handoffs for staff; operator review is necessary.
- All admin POSTs use the existing authenticated, CSRF-protected and locking workflow. The transition is optimistic-revision guarded and records append-only staff event metadata. No automatic account or financial actions.

## Deployment

1. Back up runtime configuration, code, database and personal media. Install over the existing site without rerunning installer.
2. First database boot updates **schema 48 → 49** with tables `publishing_campaigns`, `publishing_campaign_daily` and `publishing_campaign_audit`.
3. Verify that the Publishing page lists existing approved social promotions and allows campaign draft creation and activation. Check the link from a second browser, retire it, and confirm it resolves to Watch home.
4. Review MySQL permissions, publish/campaign role boundaries, mobile viewport, and the PNG cover export on a real phone. Do not call those verified until performed.

## Tests

`tests/publishing-campaign-v153.php`: SQLite migration and replay, platform allowlist, approved-only source, optimistic transition, revocation, link redirect, audit, scheduling and explicit due activation.

`tests/publishing-campaign-v153.test.mjs`: permission/CSRF wiring, no external posting, safe same-origin cover, migration and cache version checks.

`tests/publishing-campaign-v153-browser.py`: real Chromium mock-backed campaign create/activate flow on 1440, 768, 390 and 320px with no horizontal overflow or external POST.

All five existing CI jobs must pass on the final PR head and again on merged `main` before packaging the deployment ZIP from the post-merge artifact.

## Limitations

Social scheduling is a workflow handoff; use the copy/download controls to post manually. The product does not pretend campaign link opens are verified sales. Future platform OAuth, compliant scheduled publishing and video transcoding require separately scoped provider integrations and test acceptance.
