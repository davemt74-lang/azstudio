# AZHottie Studio v1.29.1 — Reality-series homepage refresh

## Scope

- Redesign the public `index.php` landing page around the user-approved high-key white-studio photograph (five adult models and a male photographer).
- Keep the image **free of baked-in text**, so marketing copy remains semantic, accessible and editable in HTML.
- Add optimized desktop and mobile WebP versions; mobile crop retains all five models.
- Introduce a responsive editorial masthead, full-width hero, section shortcuts, Watch/live-show discovery, member-experience cards, casting invitation and structured footer.
- Keep the existing shared universal navigation/header and all real route fallbacks for installed, signed-in and not-yet-installed states.
- Preserve adult-facing framing, consent and admission disclaimers; no placeholder videos, counts or availability.

## Deployment

- No database schema changes or backfills.
- Upload or extract the full deployment ZIP into the existing AZHottie installation after backing up the site and private `storage/` directory.
- Preserve private configuration, uploaded media and database data. Do **not** rerun `install.php`.
- Refresh `index.php` after deployment; `assets/landing.css?v=1.29.1` busts the old stylesheet cache.
- Verify the hero displays, links open the intended installed routes, and mobile widths do not overflow.

## Automated and browser tests

- `npm test` validates existing behavior plus five new homepage assertions; existing casting navigation test updated to the approved new hero.
- `php -l index.php` and the broader PHP lint gate protect PHP syntax.
- `python tests/homepage-v1291-browser.py` uses Chromium to render the real PHP guest page with offline-inlined assets at 1440, 820, 390 and 320 px. Requires the Playwright Python package and Chromium.
- The site still requires real hosted checks for installed account links, casting flow and media availability on `azhottie.com`; these are not certified by offline screenshots.

## Assets

`assets/hero-reality-white.webp` and `assets/hero-reality-white-mobile.webp` are optimized from the supplied approved, AI-generated studio image. No third-party images or external scripts are used.
