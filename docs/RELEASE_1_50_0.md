# AZHottie v1.50.0 — Creator & Video Performance

## Existing systems reused

The app already records aggregated Watch player starts/completions/estimated playing seconds; publishes approved creator videos and finished productions; records model review status; and keeps approved creator promotions, signed Stripe Checkout orders, and staff-confirmed cash in separate ledgers. This release combines those existing signals **without introducing a second payments or publishing system**.

## Added

- **Creator & Video Performance** report linked from the administrator Command Center, creator dashboard, creator navigation, Commerce and User Submissions. Period filters: 7, 30 and 90 UTC days; video type and administrator-only model filter. Actual per-video and per-creator rows, daily play-start trend, current versus equal-prior-period comparisons, review snapshot, 8 summary cards and downloadable UTF-8 CSV. Mobile table rows become stacked cards; no horizontal page overflow or nested scroll areas are intended.
- **Strict server authorization:** only active administrators and models. Models always query their own ID even when URL parameters attempt to select somebody else. Admins can filter a model or all. Admin-only creator comparisons do not appear to models. Reports include only current public approved creator videos and published/unarchived finished productions. Private and withdrawn videos cannot leak titles from historical activity.
- **Product CTA taps** on public Watch videos, captured unobtrusively for approved currently published videos/products/promotions. The server verifies the public clip and the product promotion's current revision, and suppresses admin, creator self-click, bots, previews and repeats within a 30-minute browser session. Only daily aggregate identifiers of the public video, product and promotion are stored; no individual viewer identifiers, browser fingerprints, IP addresses, or histories. Recording starts with v1.50. It is not a proxy for actual purchases.
- **Attribution separation:** clicked CTA, started Stripe Checkout Session, signed verified paid Stripe order, and administrator-confirmed cash are *different* values. Stripe net subtracts confirmed refunds and reversals; revenue is USD product subtotal only. Commission is an estimate, not evidence of a transfer or settled bank payout. Unverified external-seller orders are not labeled verified.
- **Review snapshot:** submissions and current pending items, reviewed current decisions, approved/scheduled, changes requested, rejected, and elapsed submission-to-review hours. Review history is not append-only for all model videos; therefore these are explicitly described as **current-state measurements**, not a full immutable approval funnel.
- **CSV safety:** data is escaped using the CSV writer and formula-like string prefixes are neutralized before export. Authenticated, no-store response; export respects the same role-scoped and type/period filters.

## Database upgrade

First deployed application boot upgrades **schema 47 → 48** with `commerce_video_engagement_daily` (video_id, product_id, promotion_id, view_day, clicks), a composite primary key and no visitor information. The migration is lock protected and idempotent for SQLite and MySQL. **Back up both database and application/media before uploading.** Deploy over the existing site. Preserve `storage/config.php`, uploaded media and private secrets. **Do not rerun the installer.**

## Gates and honest limitations

`php tests/creator-analytics-v150.php` exercises migration, viewer/event privacy and dedupe, creator role isolation, public eligibility, current/prior date windows, product attribution/refunds and cash separation, review turnaround and CSV formula safety against SQLite fixtures. `node --test tests/creator-analytics-v150.test.mjs` covers the real Watch CTA JS and report wiring. Existing JavaScript, PHP, SQL, browser and Live Director CI suites remain required. Final-commit **5/5** GitHub CI and post-merge **5/5** must pass before release packaging.

Hosted MySQL permissions, actual traffic, browser interoperability and real Stripe TEST payments are operator acceptance gates; synthetic fixtures cannot certify them. This release does not enlarge Live viewer capacity, claim a unique-viewer audience, enable provider payouts, auto-publish content or change financial approval defaults.
