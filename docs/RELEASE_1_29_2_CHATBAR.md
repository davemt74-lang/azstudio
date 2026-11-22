# AZHottie Studio v1.29.2 — Shared chat composer cleanup

The shared model/admin chat dock no longer displays the redundant "Ask AZHottie Agent" header or the footer privacy disclaimer / "Open chat" link. The message placeholder, submit button, and existing navigation to Agent Chat remain available.

## Behavior

- The composer retains explicit accessible form/input labels and Enter-to-send behavior.
- Existing member/admin access controls and chat API authorization are unchanged.
- If browser storage blocks sending a composed message to Agent Chat, an inline, screen-reader-announced error appears. This replaces the former error target in the removed footer.
- Stylesheet and module versions are bumped to v1.29.2 to force browsers to load the changed chat dock.

## Deployment

No database migration. Merge the deployment files into the site after backing up private data, preserving `storage/` and private configuration, and do not rerun `install.php`. Refresh a signed-in member and admin page, then check the composer and Agent Chat. Production verification remains outstanding until exercised on azhottie.com.

## Local tests

- 406/406 JavaScript regression tests passed.
- Shared member layout PHP syntax passed; all PHP files pass lint.
- Chromium responsive fixture passed at 1440, 768, 390 and 320 pixels without layout overflow or JavaScript exceptions; forced blocked-storage fallback announces the error.
