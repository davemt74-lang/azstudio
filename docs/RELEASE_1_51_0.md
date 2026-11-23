# AZHottie v1.51.0 — Live Production: Viewer, Camera, Recording & Mobile Recovery

## Existing implementation reviewed

The Live Director already supports up to eight independently approved phone feed peers; explicit single camera and multiview Takes; a six-viewer peer-to-peer broadcast ceiling; canvas Program recording; bounded phone repair and fan recovery; an administrator-owned broadcast checklist. v1.51 extends these mechanisms instead of introducing a new streaming provider or promising production-grade SFU scale.

## Reliability changes

1. **Fan session changes while Join is pending.** Viewer status changes now invalidate *every* pending session (including a Join that has not yet received a viewer ID), on studio end or room rollover. Stale successful Join replies are still explicitly released using the old lease ID; the next room's peer and Join UI are unaffected. Frame-health counters reset on session replacement.
2. **Background recovery.** For viewers who were playing when their tab was backgrounded, a visible-tab return attempts playback again, but does not force muted playback or mistake a user pause for a network failure. Director phone previews have a 7-second foreground grace before automated frozen-frame repair or a transient stalled state demotes the on-air source. This does *not* mark a stale preview healthy: only new decoded frames allow Take. User-initiated Reconnect bypasses the grace. Other phone feeds and the recording remain isolated.
3. **Recording lifecycle.** An asynchronous MediaRecorder error now marks the local recording as unsafe for automatic upload even if it emitted substantial bytes. Recordings below 1 KB are also held. After finalizing, a local playable preview/download link is available; automatic save is attempted only after the browser decodes the first frame. The uploaded, authenticated gallery video must subsequently decode its first frame **before** the recording is attached to a saved live production and the local backup is freed. If upload or either playback probe fails, the original file remains downloadable with retry controls. The original recording room ID remains bound across retries. Byte size or container metadata alone is *never* reported as proof of playable video. The first-frame check is not verification of an entire video or audio track.
4. **Mobile Live Director controls.** A safe-area-aware bottom shortcut strip (Cameras, Take next, Record/Stop/Review backup, Audio) reuses the existing guarded Director actions and recording state. No parallel recorder or auto-Take path. The strip is mobile-only and uses a separate in-grid row rather than covering Program or camera thumbnails.

## Tests and release gate

- Node tests: pure viewer/foreground/recording policies, playable video probe cleanup, output visibility and correct mobile delegation.
- Real Chromium: delayed fan Join while studio stops/changes rooms, cleanup of stale server viewer ID without leaving the next lease; 1440/390/320 mobile layout and shortcut interactions; generated MediaRecorder video with actual browser first-frame decode.
- Previous v1.49 fan browser tests updated to embed the new pure module, without weakening their existing checks.
- Existing PHP, SQLite, Commerce, Live Director, and browser acceptance stay required. Five GitHub CI jobs must pass on both final PR head and merged main before producing a deployment ZIP.

## Deployment and operator acceptance

Deploy over v1.50, back up the database and media, preserve runtime config, and **do not** rerun the installer. No schema change; schema **48** is retained. New JS/CSS entry assets use the v1.51.0 cache key.

Actual iPhone camera decoding on Wi-Fi/cellular, live fan video/audio lip sync, TURN/firewall behavior, background locking, multi-phone motion under real networks, and full saved-video/audio replay need real-device verification. The current **six simultaneous fan viewers** peer-to-peer cap is unchanged; scaling to a higher audience needs a separately reviewed SFU/streaming architecture. No financial, profile, camera approval, or automatic Program Take permissions were expanded.
