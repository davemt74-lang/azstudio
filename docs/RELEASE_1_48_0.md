# AZHottie Studio v1.48.0 — Live Broadcast Troubleshooting & Redacted Support

## Built

**Connection troubleshooting** in Live Director → Broadcast checks, using already-secured numeric Director, phone-offer and fan telemetry plus existing operator acceptance statuses. The engine identifies each observable failure stage: no approved phone, approved phone without fresh offer, missing/stale Director health, frozen preview frames, camera offered but absent from Director, holding slate not yet taken to Program, stalled Program sender, low sender FPS, frozen fan playback, blocked fan autoplay, missing fan telemetry and recorded operator failures.

Each finding includes a *reason* and a specific *next check*, and offers a link that focuses the relevant seven-step acceptance checklist. Findings are deterministic browser-report comparisons, **not** independently verified hardware diagnoses. The system does not switch Program, reset phones, publish or record video, change users or initiate financial actions.

**Redacted diagnostic summary:** An administrator may open and copy a support summary with room state, a strict allowlist of safe aggregate counters, diagnosis codes and operator pass/fail totals. The summary explicitly excludes the broadcast ID/title, device and viewer identities, IP addresses, SDP, tokens, media URLs, recorded notes and other personal data. Copy is user-initiated; if clipboard permission is unavailable the text can be selected manually. No outbound support transmission takes place.

**Latest-show continuity:** On refresh and while the acceptance tab is open, the status request retrieves the newest broadcast owned by the administrator. A newly started session no longer inherits the previous show ID, checklist or live metrics. Historical acceptance records remain scoped to their own room in the server database. Ended sessions retain saved recording counts but cannot display old phone or fan telemetry as live evidence.

## Safeguards

- Administrator-only access via the existing Live Acceptance status endpoint. No new public endpoint, camera authorization or producer permissions.
- No schema change: database remains **47**.
- No automated pass/fail statuses are written. Even seven operator-passed steps remain **operator-attested, not independently verified**.
- Missing or stale health data is labeled unknown/needs review; the absence of a finding never certifies an actual camera, cellular, TURN, audio or recording flow.
- JavaScript uses textContent, allowlisted stage codes, bounded findings and no HTML injection.
- Diagnostic support export excludes operator note contents and all stream identifiers.

## Verification

- PHP: tests/live-triage-v148.php — stage classification, severity, missing/ended/active room distinctions, explicit operator failures, unknown telemetry, safe redaction and no false green status.
- Node: tests/live-triage-v148.test.mjs — server wiring, no live or financial actions, newest-room polling, safe rendering and sanitized copy.
- Real Chrome: tests/live-triage-browser.py — mobile/desktop 1440, 768, 390, 320 pixels, same-tab new show tracking, focused checklist navigation, copyable sanitized packet, no unexpected mutations or horizontal overflow.
- Existing v1.47 PHP and Chrome acceptance regressions retained; their version checks now follow the installed application's version.
- Full five-job GitHub pre-merge CI and post-merge main CI required before producing the deployment ZIP.

## Hosted physical acceptance

A person must still connect a real approved phone, verify moving previews, take it to Program, test playback and audio from another network, interrupt/recover, and play back an actual recorded show. Browser telemetry and checklists cannot perform those tests on their own. Live financial operations remain disabled until separate provider TEST acceptance.