# AZHottie Shop 2.1 — Editorial Storefront & Homepage Editor (v1.57.0)

## User-facing build

- Uses the approved blush/cream editorial hero image with real image asset instead of a decorative placeholder; light full-width store, curated photo category tiles, featured products, browse/search and existing safe checkout blocks.
- Adds **Commerce → Products → Shop Homepage Editor** and an administrator-only sidebar item. The editor controls hero copy, CTA destination, two image slots (hero and page background), image position, text alignment, accent/button/background/text colors, fonts, sizes, and overlay strength.
- Sections (hero, categories, trending, catalog, service) have reversible visibility switches and reordering. Categories can be individually shown/hidden; hidden categories are excluded from public product lists and detail URLs, but products, inventory, prices and orders are untouched.
- **Save Draft / Private Preview / Publish / Discard Draft** with strict server validation, administrator role, CSRF and revision locking. Uploaded JPEG, PNG and WebP images are private in storage and served only when published, or to an authenticated administrator.
- Commerce includes explicit instant toggles for **Enable/Disable Ecommerce** and **Enable/Disable Sample Data**. Demo catalog is initially off; when on, sample products are clearly marked SAMPLE / NOT FOR SALE and have no checkout/order/inventory actions. No real rows are seeded.

## Security, migration and limitations

- Schema **52 → 53** creates `shop_homepage_settings` and `shop_homepage_images`. Existing checkout, Stripe webhooks, cash fulfillment, inventory, creator commissions and Live features are unchanged. Category switches are display visibility, not an accounting or inventory change.
- Product/catalog photos remain in the separate v1.56 product storage. Homepage images stay in restricted `storage/homepage-images` with MIME/dimension/size limits and served through the dedicated guarded endpoint. No arbitrary remote URLs or untrusted CSS are accepted.
- The included hero and example product art are illustrative generated editorial imagery, not inventory or evidence of customer reviews, sales, or creator affiliation.
- Real hosted MySQL migration, real administrator media upload, and final physical mobile acceptance require deployment verification. Existing browser acceptance and all five GitHub release jobs must pass on PR HEAD and merged main before generating the deployment artifact.

## Install

Back up application, database, config and private media. Deploy over v1.56, **preserving all private `storage/` and user files; never rerun the installer**. First authenticated application boot advances schema to 53. In Commerce → Products, open **Shop Homepage Editor** to customize; preview unpublished changes, then publish. Enable sample products only when you want visible but non-purchasable demonstrations.
