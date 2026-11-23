# AZHottie v1.49.0 — Live Production Lifecycle Hardening

This release stabilizes actual high-risk state transitions in the existing Live Director, fan player, and approved phone previews. No new streaming service, external provider, phone approval method or video publishing workflow is introduced.

## Changes

**Fan viewer session safety.** A slow WebRTC Join now exposes Leave immediately. New viewer requests include a monotonic timestamp-prefixed nonce with 80 random bits; PHP checks ordering under its existing per-community Join lock and rejects stale earlier requests before they can evict a newer active viewer. Older clients with random nonces remain supported, and no new database column is introduced. If the visitor leaves or retries before the server's reply arrives, the stale returned viewer ID is explicitly released through the existing authorized Leave endpoint. The old request may not bind its peer, SDP or media to the new viewer session, and its finally block cannot override the new session's buttons or connecting state. Session IDs are validated before any cleanup request. This helps prevent temporarily stranded slots in the existing **six-viewer P2P broadcast limit**; it does not expand audience capacity.

**Director camera restoration and switching.** A source failure continues to take a single-camera Program to the holding slate. Once that same approved camera has genuinely recovered usable decoded frames, its existing Broadcast button changes to **Restore to Program**. Only a human click takes it back on-air. Other connected camera streams and Program outputs are not reset; explicit holding-slate and other-camera takes clear the interrupted source hint. Concurrent Go Live clicks are guarded.

**Recording transaction safety.** The Director now blocks another recording during setup, stopping, finalization or upload, and while an unsaved/failed backup is still present. Each recording permanently keeps its original room ID, even if a new broadcast begins while setup/upload is pending. The recording button does not re-enable until the file is saved or the operator explicitly confirms discarding the local backup. Tiny/invalid files remain local only. Interrupted upload retains the original file and upload idempotency token for retry. Successful byte-size validation is labeled **byte verification**, never recorded-video playback certification.

**Program audio readiness.** Audio tab shows local mixer running/suspended/unavailable and whether an enabled live mic or approved camera audio is selected. A human **Resume mixer** control calls AudioContext.resume from a user gesture. This verifies only local routing; real fan-audio and lip synchronization require testing on a separate device/network.

## Quality gates

- Node and PHP tests: viewer lease freshness and out-of-order server rejection, bounded recording start, multi-phone isolated peers, local mixer readiness, explicit camera restore and no financial/broadcast auto-takes.
- Real Chrome: 1440, 768, 390 and 320 pixel screens; actual viewer JavaScript tested against delayed and reordered mock server Join responses; explicit Leave and stale slot cleanup without disrupting a new session.
- Existing PHP/SQLite, Commerce, Live Director, Live Agent, recording, fan recovery and browser acceptance regression suites remain required.
- All five GitHub CI jobs must pass on the final PR head and merged main; deployment ZIP comes only from the successful post-merge package job.

## Deployment and limitations

Back up code, database, storage config and media, then deploy over the existing files without rerunning the installer. Schema **47 remains unchanged**.

Automated CI does **not** prove live cameras, multiple actual phones, video/audio synchronization, TURN/firewall fallback, cellular fan playback, iOS background behavior, or successful recording playback. Use the existing **Live Director → Broadcast checks** physical-device checklist before marking production acceptance 10/10. Live financial actions remain disabled pending independent provider TEST acceptance.
