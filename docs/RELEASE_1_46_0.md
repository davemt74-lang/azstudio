# AZHottie Studio v1.46.0 — Live Broadcast Reliability

## Viewer
Fresh WebRTC handshake and viewer ID on failed/closed transport, sustained disconnection (6s), missing video (30s) or verified decoded-frame stall (12s). Maximum three automated retries per rolling two minutes with 1.2/3/7-second delays. Stable moving frames for 45s reset the budget. Explicit Leave stops retries, manual Join resets them. Autoplay rejection, paused playback and hidden tab do not trigger connection loops. UI shows accessible recovery status; Join and Leave buttons honor hidden state.

## Director and phone
Two bounded frozen-preview resets per approved phone per two minutes, or manual Reconnect feed. Closes only the selected phone's stale WebRTC peer and sends owner-authenticated CSRF-checked signaling action resetFeed for that exact device and nonce. Phone sees an expired relay and generates a new offer. A late reset cannot delete a newer nonce or revoke approval. Director does not switch another phone's Program source or interfere with recording. An affected Program source uses existing holding slate.

## Recording
Do not start a recording if the broadcast ended during preparation. Under-1024-byte recordings are marked invalid, remain as local download backup and are not uploaded automatically as valid completed shows.

## Verification and deployment
Schema remains 46. Back up application, database, storage config and media; do not rerun installer. Node recovery tests cover viewer/phone budgets and safety. PHP SQLite nonce tests cover owner and approval gating and stale nonce protection. Real Chromium browser harness simulates viewer failure, new handshake, old viewer cleanup and explicit Leave at 1440/768/390/320px without external media network. GitHub five-job pre/post-merge CI and full deployment ZIP are required. Hosted hardware acceptance is separate.

## Production limitations
Independent real-device testing remains necessary for approved iPhone/other phone cameras, preview FPS, Program switching, fan playback on another network, TURN, iOS autoplay/screen-lock, audio, recording and playback. No live payment operation is enabled.
