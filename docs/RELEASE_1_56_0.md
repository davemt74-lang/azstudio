# AZHottie v1.56.0 — Shopping Experience 2.0

## Existing payment and operational systems reused

- Verified Stripe checkout and receipt/webhook reconciliation, external seller checkouts, staff-confirmed cash pickup/delivery, shipping rate selection, stock reservations, refunds and creator attribution are **unchanged**.
- This release is a merchandising/presentation layer; no financial provider, charge, transfer, commission, permission, or storefront external-posting automation is added.

## Built

1. **New responsive storefront** at `shop.php`: curated hero, cards with approved cover images, featured-first ordering, searchable product names/descriptions/creator public credits, collection filters, price/name sorting, honest out-of-stock/made-to-order/carrier indicators, existing checkout forms and cash order status/return links.
2. **Product detail and photo gallery** at `shop-product.php?id=...`: up to six manually approved product images with keyboard-operable thumbnail picker, accessible alt text, clearly displayed pricing and existing checkout options. Related available items are matched by collection; no invented recommendations or storefront data.
3. **Admin merchandising panel** in Commerce → Products: categorize existing products, set a short tagline and featured flag, choose up to four currently active products in a curated set, upload/describe/reorder/delete JPEG/PNG/WebP photos. Admin POSTs require CSRF and stale-revision checks; no changes to product checkout and fulfillment settings.
4. **Security-controlled media** stored in `storage/product-images/`, inaccessible to direct HTTP. `shop-media.php` checks that the image belongs to a **currently available** public product or is viewed by an authorized active administrator; archived/draft product photos are not public. No public upload interface; only active administrator may upload, capped at six photos, 4 MB, 24 megapixels and a strict MIME allowlist. SVG and script-capable media are not accepted.
5. **Creator-featured recommendations** appear only when the linked video has a current approved promotion, creator account remains active, video is published, rights are confirmed and video revision matches. Links go to the existing approved Watch page. No private model/profile details are exposed through this feature.
6. **Curated-set metadata** references individual active products but sells the parent as its own existing product SKU, price and stock. Component inventory is not automatically reserved or decremented, and no bundle discount is calculated. Staff must configure the bundle SKU's own inventory/fulfillment and price. A multi-item cart is not introduced in v1.56.

## Upgrade and tests

- Schema **51 → 52** adds `commerce_product_catalog`, `commerce_product_images` and `commerce_bundle_components`; existing schema is not rewritten. Upgrade runs on the normal authenticated application bootstrap; do not rerun the installer.
- New PHP/SQLite integration: migration replay, admin-only editing, stale revisions, category and bundle validation, public/archived photo isolation, public video attribution and private image upload rejection.
- New Node assertions: unchanged checkout paths, role and CSRF guards, server-side photo protection, validated product listing and client UI wiring.
- New real-Chromium responsive acceptance at 1440/768/390/320px: filtering, sorting, gallery switching, accessibility state and no horizontal overflow; mocked admin API enforces CSRF, save/upload/delete flow and avoids financial actions.
- All existing CI jobs (Live, Commerce, admin, fan, SQLite and PHP) must pass on the final PR head and merged main. Deployment ZIP must come from the successful post-merge `main` artifact.

## Acceptance limitations

Automated tests do not certify real hosted MySQL migrations, uploaded photo display on HostGator, a live checkout with actual payment-provider credentials, or real product fulfillment. User must check those on the deployed site. **The release is not a multi-item shopping cart**, and separately stocked curated-set SKUs are not automatically deducted from their displayed components.
