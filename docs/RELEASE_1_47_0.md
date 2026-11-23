# AZHottie Studio v1.47.0 — Broadcast Acceptance & Diagnostics

## Purpose
Make the existing Live Director camera-to-fan acceptance process repeatable, measurable, auditable and easy for an administrator to troubleshoot. This is a separate acceptance layer, not another streaming service.

## New operator-facing workflow

- Open Live Director → Broadcast checks. The panel is visible only to an authenticated administrator.
- Recent browser-reported measurements include the count of approved phones, fresh signaling offers, healthy camera previews reported by Director, Program mode/sender FPS, reporting/playing/frozen fan sessions, Director sample age and stored recording counts/maximum file size.
- Measurements come from existing secured phone relay records, existing Live Agent health telemetry, and private recorded-show metadata. They do not include viewer identities, camera stream tokens, SDP, IP addresses, microphone audio, or video frames.
- Missing/stale data stays unverified. A phone approval does not prove moving video, a recent signaling offer does not prove decoded frames, viewer telemetry does not prove the viewer was on a separate network, and a saved media record does not prove successful recorded playback.

## Seven operator test steps
1. Approve a real phone and start sharing live.
2. Watch continuously moving phone preview frames in Director.
3. Take the phone to Program and confirm the visible Program changes.
4. Watch the broadcast on a second real device over another network or cellular connection.
5. Unmute remote fan audio and confirm the intended microphone or media sound.
6. Record Program, verify upload, and play the saved private recording (audio and picture).
7. Interrupt the test camera connection; verify bounded reconnection or the safe holding slate without disrupting the recording.

Each administrator can record pass, fail, or untested with an observation note (required for pass/fail). Results are scoped to a specific broadcast room, persist with optimistic concurrency revision checks, and are recorded in an immutable change log. They can be updated for seven days following the broadcast start to permit recording playback checks after the show.

A complete checklist is labeled operator-attested. It is not independently verified acceptance. No status change, broadcast switch, phone authorization, media publication, payment, or financial-provider action occurs when a step is marked.

## Security and privacy
- Read/save only when the caller is an active administrator and owns the broadcast.
- CSRF validation applies to all POST changes; room IDs, step names and result enums are validated; observations are bounded to 240 characters. Do not include identifying viewer information or credentials in notes.
- The checklist records only safe operator observations and timestamps, not video streams or SDP.
- Existing Live Agent telemetry freshness rules apply; a broadcast ending does not preserve stale green performance counters. Checklist history remains readable for an owned ended show.

## Database and deployment
- Schema 46 → 47 adds az_live_acceptance_checks and az_live_acceptance_events, retaining existing Live Director and recording data.
- Back up code, DB, private config, uploads and media. Install ZIP over the application; do not rerun the installer. First database boot applies the migration.
- Administrator roles only. Existing phone pairing, Program switching, fan WebRTC and recording endpoints remain unchanged.
- Automated PHP SQLite integration tests cover migration/roles/room isolation, fresh vs stale telemetry, recording indicators, manual completion semantics, audit events, revision conflicts and result validation.
- Node regression tests cover permissions, endpoint routing, UI safety and absence of any broadcast/financial mutations.
- Real Chromium tests exercise read-only initial state, CSRF-saving an operator step, persistence, audit history, text escaping, and mobile layouts at 1440, 768, 390, and 320 pixels.
- GitHub's full five-job release gate must pass on the final PR commit and post-merge main before producing the full deployment ZIP.

## Physical-device signoff
Passing automated CI does not complete real-device acceptance. An administrator must still independently test actual iPhones/other phones, iOS Safari, cellular networks, TURN/fallback, fan audio, Program camera switches, interruption recovery and video playback on the hosted installation. Live financial operations remain disabled by default until separate provider TEST acceptance.
