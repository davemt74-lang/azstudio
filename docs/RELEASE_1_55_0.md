# AZHottie v1.55.0 — Creator Club & Member Subscription Foundation

## Product model

This release follows the **Patreon/OnlyFans-style per-creator subscription model** rather than a blanket studio-wide paid pass. Fans can follow individual model profiles for free. Each authorized model can submit individually priced membership tiers and exclusive posts for human moderation. Creator tiers are separate by creator ID; post/media entitlements cannot cross creators or tiers.

## Included

- `creator-clubs.php`: authenticated discover view of models whose profiles are actually shared with members.
- `creator-club.php?creator=<id>`: individual Creator Club with free follow/unfollow, approved tier proposals, and authorized member updates.
- Model workspace: propose up to five tiers and submit private video/text posts under approved tiers. Only the owner can submit; staff must explicitly approve or reject. Models can withdraw live posts immediately.
- `creator-club-admin.php`: administrator-only queue with the original submitted private footage available for review, protected through `creator-club-media.php`.
- `creator-club-media.php`: validates the logged-in visitor's creator/tier-specific access on every GET and HEAD, including byte-range requests. Never redirects fans to the owner's personal-media endpoint or serves a public signed link. Private content is not embedded in the public Watch library.
- Server-side guard against forged media IDs, stale approval revisions, cross-creator access, expired/revoked access and role changes.
- Responsive 1440, 768, 390 and 320px Creator Club UI and suite wiring.

## Important payment and compliance limits

**No subscription checkout, recurring billing, tips, payouts, creator balance, adult content payments or automatic provider provisioning.** Tier prices are proposals only, not payment offers.

Existing Stripe checkout must not be repurposed for adult creator memberships: Stripe's public policies restrict creator-monetization platforms and prohibit sexually explicit materials. Any paid launch requires prior provider approval, a permitted content policy, age/identity/consent requirements as applicable, recurring-payment cancellation/refund and tax handling, moderation and chargeback safeguards.

Admin test-only entitlements are disabled by default. A private feature flag, `features.creator_club_test_grants=true`, can be enabled only in an isolated staging installation. The grant helper remains internal, with no public grant action or customer checkout. Turning the flag off also revokes the ability of those test grants to access content. No test entitlement is evidence of a charge.

## Migration and release

Database **schema 50 → 51** adds `creator_club_tiers`, `creator_club_follows`, `creator_club_posts`, `creator_club_entitlements`, and `creator_club_review_events` with creator scoping and review history. Existing v1.54 data remains untouched. Back up database, configuration, uploads and media. Deploy over existing app, preserve private storage, and **do not rerun the installer**.

- Node static and policy tests verify scope, explicit approvals and no payment actions.
- PDO SQLite integration checks revision conflicts, tier ownership, role isolation, follow idempotency, revoked and expired access, default-off test flags, and private direct URL authorization.
- Real Chromium responsive layout test checks mobile card/forms at 1440, 768, 390 and 320px, and no horizontal overflow.
- All five existing CI jobs must pass on final PR HEAD and merged `main`, including every previous Live/Commerce regression. Deployment ZIP from post-merge artifact only.

## Follow-up before paid memberships

Integrate an eligible and explicitly approved recurring payment provider, reliable signed/webhook-confirmed subscription entitlements, lifecycle cancel/retry/renew flows, authorized creator payouts, privacy/age/content controls, and full hosted media-authorization tests. These are **not** certified by v1.55.0.
