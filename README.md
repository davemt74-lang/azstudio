# AZHottie Studio — v1.35.0

**Customer-Selected Carrier Checkout:** Real USPS/UPS/FedEx EasyPost rates selected before Stripe Checkout on product pages, creator videos and Live. Session-bound short-lived quotes, encrypted addresses, signed Stripe verification, staff shipping exception quarantine, and Agent/analytics integration. Existing flat/free shipping, cash pickup and local delivery remain. No automatic labels, returns, tax filings or creator payouts. Schema 40→41. See docs/RELEASE_1_35_0.txt.

---

# AZHottie Studio — v1.34.0

**Carrier Integration:** Optional EasyPost multi-carrier USPS/UPS/FedEx rate comparison in the existing staff Shipping workspace, explicit cost-capped test/production label purchases, provider-label reconciliation after uncertain results, signed monotonic tracking webhooks, and shared Commerce Agent notification/Brain/Chat updates. Customer shipping addresses remain encrypted and hidden from Agent context. Carrier account credentials and real label purchasing are disabled until independently configured on the server. **Customer checkout pricing remains per-product flat/free shipping**; carrier rates are used by staff for fulfillment. Schema 39 → 40. See `docs/RELEASE_1_34_0.txt`.

---

# AZHottie Studio — v1.33.0

**Inventory, Shipping & Fulfillment Specialist:** Per-product SKU and inventory settings, tracked stock holds with idempotent Stripe/cash checkout, US flat/free shipping (Stripe-hosted only), verified shipping address collection, privacy-protected shipment queue, staff parcel/tracking controls, low-stock and fulfillment Agent alerts. No live carrier rates or automatic label purchase; carrier tracking is staff-entered, and physical restocking requires human inspection. Schema 38→39. See `docs/RELEASE_1_33_0.txt`.

---

# AZHottie Studio — v1.32.0

**Pay with Cash, Local Pickup & Local Delivery — configured on each product.** In Commerce > Products > Create/Edit, set cash availability, pickup location and hours, delivery ZIP coverage, fees, minimums and instructions independently for every item. Shoppers can request pickup/delivery from Shop, approved shoppable videos and Live, without paying online. The shared Local Orders queue handles staff quotes, acceptance, cash collection, pickup/delivery fulfillment and full cash refunds. Private contact details are encrypted at rest and excluded from Agent Chat, Brain, creator accounts and public receipts. Cash commissions begin only after staff record actual collection; Stripe-signed and manually recorded payments remain separately labeled. Schema 37 → 38, cash opt-in defaults OFF. See `docs/RELEASE_1_32_0.txt`.

---

# AZHottie Studio — v1.31.0

**Verified Commerce and Affiliate Attribution:** Optional Stripe-hosted checkout and signed webhook reconciliation, provider-verified paid orders, creator-video and Live attribution, refunds, disputes, shared Agent notifications/Chat/Brain, and separate verified-versus-admin-recorded analytics. Existing external seller links remain available but do not provide automatic payment evidence. No automatic creator payouts, fulfillment or tax handling. Schema 36→37. See `docs/RELEASE_1_31_0.txt`.

---

# AZHottie Studio — v1.30.0

**Commerce & Creator Affiliate Agent, first layer:** Admin-managed products with external checkout links, private creator briefs, model interest/approval, revision-bound published-video affiliations, manually recorded/reconciled external orders, creator commission estimates, and Live Director product spotlight. Commerce reuses the existing Agent notification bell, History, Brain, and persistent Chat (with deduplicated delivery and hourly follow-through). Customer payment processing, independently verified payment/refund webhooks and actual creator payouts are not included. Schema upgrades 35 → 36. See `docs/RELEASE_1_30_0.txt` and `UPGRADE.txt`.

---

# AZHottie Studio — v1.29.0

**Publishing & Audience Growth Specialist:** The existing admin User Submissions review queue now supports explicit approval for a future Arizona publication time. The site-private cron validates media, consent, model eligibility and exact revision immediately before release. A new admin Publishing & Growth workspace displays measured creator/interview Watch playback, approval/publishing calendars, metadata-based private promotion drafts, and explicit on-site featured placements. Agent Brain, Chat and History use verified role-scoped counts. No automatic external social/email posting or inferred footage analysis. Schema 34 → 35. See `docs/RELEASE_1_29_0.txt` for limitations, release gates and acceptance checks.

**Video Production & Editing Specialist (existing Agent Chat + Brain):** In `my-editor.php`, authorized creators can mark private timestamped highlights, captions or source issues, generate optional teaser/highlight/recap cut proposals from *existing clip timings and creator-supplied notes*, preview and explicitly approve an edit, discard it, or revert the approved timeline when no newer changes exist. The original media is never modified and all changes use owner-scoped editor revisions and CSRF checks. Agent Brain/Chat summarize verified editing/review states, and private hourly cron sends deduplicated follow-up alerts for unreviewed proposals. This release does **not** perform video recognition, speech transcription or silent publication, and it does not replace the existing My Videos/admin submission approval process. Schema upgrades 33 → 34. See `docs/RELEASE_1_28_0.txt` for test gates, limitations, and production acceptance.

---

# AZHottie Studio — v1.27.0

**Calendar & Scheduling Specialist integrated with Agent Chat and Brain.** A private unified schedule now combines a member’s registered events/classes, confirmed appointments, active production sessions, and assigned shot deadlines (deadlines do not block time). Models can record weekly Arizona-time availability and unavailable periods. Admin producers can compare fully onboarded, active-roster models against a proposed time, with unavailable, unknown, busy and available shown distinctly—never treating missing data as confirmed availability. Agent Brain shows upcoming events and time conflicts, and the existing private hourly cron sends deduplicated 24-hour in-app reminders for confirmed appointments and event registrations. Agent Chat receives scoped aggregate data (types and times only; no private notes, names or contact details). Scheduling changes remain in the established authorization-checked Calendar, Appointments, and Production workflows. Schema advances 32 → 33. **Live shows cannot appear as future bookings until they are linked to a scheduled event.** See `docs/RELEASE_1_27_0.txt` for release gates and production acceptance.

---

# AZHottie Studio — v1.26.0

**New: Casting & Onboarding Agent integrated into Brain and Chat.** Admins see verified casting-stage counts, application-completeness checklists, pending and stalled reviews, expiring or missing private invitations, and per-model onboarding progress. The existing Agent Brain and Agent Chat share a structured *Key findings / Actions & follow-ups / Open questions / Next step / Questions for you* report. Chat receives only anonymized, read-only workflow totals and summaries—not applicant identities, contact details, notes, or questionnaire responses. Stage changes (new → contacted → screening → interview) and the administrator's onboarding confirmations require explicit confirmation, CSRF validation, and current record revision; approvals, account invitations, and production assignments stay in their existing authorized flows. The private hourly follow-through cron adds deduplicated in-app reminders and preserves History. Production matching displays only roster-eligible models with completed onboarding and never changes assignments automatically. Schema 31→32 adds casting delivery/scans and onboarding checkpoints. See `docs/RELEASE_1_26_0.txt` for tests and production acceptance.

# AZHottie Studio — v1.25.0

**New: Live Director Specialist.** The Admin Agent’s Brain now monitors actual browser-reported camera health, Director outbound video frames, and fan-player decoded-frame progress independently. It distinguishes phone-camera loss, stalled outbound Program senders, frozen fan playback, and browser-blocked playback. Confirmed issues create deduplicated admin notifications and History entries. An operator-only Live Director strip provides recheck and explicit recovery choices; the agent does not switch sources or end the show. Measurements include numeric metrics only—no recordings, media, chat, phone labels, or viewer identity—and require authorized active sessions. A schema upgrade from 30→31 adds diagnostic samples/incidents, with best-effort 30-day cleanup through the existing private cron. See `docs/RELEASE_1_25_0.txt` for the acceptance checklist and production limitations.

---

# AZHottie Studio — v1.24.0

**New: proactive assignment follow-through.** The existing Model/Admin Agent now checks verified assignment states and produces recipient-scoped in-app notifications for unanswered work, approaching deadlines, overdue submissions and delayed production reviews. Admins can run owner-scoped checks and explicitly confirm a reminder from the Brain. Hourly automatic checks require a private server cron configured on the host; the UI shows whether a cron run has actually been observed. Notifications are deduplicated, stale unread reminders are resolved on status changes, and no assignment or publication is silently changed. Schema 29→30 adds the reminder-delivery ledger and scan receipts. See `docs/RELEASE_1_24_0.txt` for setup and acceptance checks.

---

# AZHottie Studio — v1.23.0

**New: model and admin agent workspace.** A notification bell in the signed-in shared header opens a slide-out workspace with Notifications, History and Brain tabs. Verified video and assignment changes create account-scoped history; Brain analyzes current authorized production/submission status and offers suggestions and explicit, permission-checked assignment actions. No autonomous worker or automatic approvals. The new schema 28→29 migration creates a private per-recipient activity ledger. See `docs/RELEASE_1_23_0.txt` for verification, acceptance criteria and deployment.

---

# AZHottie Studio — v1.22.2

**New in v1.22.2 — Live Director fan playback and camera relay reliability:**
Fixes three contributors to “connected phone, first frozen frame” reports. The fan
viewer now starts the composite video muted to satisfy browser autoplay policy,
has an explicit sound toggle, and preserves its video MediaStream when WebRTC
adds the audio track. The Director continues rendering under permissible
background timers and, when hidden, temporarily relays only the *already taken*
selected single phone video track to connected viewers instead of depending on
background-throttled canvas.captureStream frames. The transport uses
RTCRtpSender.replaceTrack, not a new room or fan connection. A per-viewer
outbound-RTP frame watchdog detects stalled Program sending even when the phone
preview FPS looks healthy and fails over an eligible viewer to that selected
phone. The fan player reports a decoded-frame stall when connected but no
fresh video arrives. **Important limitation:** direct relay temporarily bypasses
canvas graphics/overlays for that viewer. Multicamera compositions, prerecorded
video and complete overlay-preserving recordings still require the Director tab
to remain in the foreground; browsers can throttle background compositing.
This is not a server-side streaming/SFU implementation. No database change.
See `docs/RELEASE_1_22_2.txt` for production acceptance scenarios.

**New in v1.22.0 — engagement, assignment deadlines and action required:**
The member dashboard now charts 14 days of real profile visits and public Watch playback starts, with 30-day play sessions, player completions and approximate watched minutes. Playback counts are anonymized, session-deduplicated and limited to eligible published videos linked to the account; raw visitor identities are not stored. The dashboard also prioritizes action-required producer feedback, late/due-soon assignments, videos requiring changes and admin moderation, with direct links into the relevant work. A dedicated deadline panel groups overdue work and deadlines in the next seven days. Submitted shots under review are not treated as overdue. Schema upgrades automatically from 27 to 28. Existing historical playback cannot be backfilled. See `docs/RELEASE_1_22_0.txt` for privacy and hosted acceptance checks.

**New in v1.21.0 — Member analytics dashboard and compact Director header:**
The signed-in model dashboard now opens with four authentic stats cards (session-deduplicated profile views, distinct published interviews, live show appearances marked untracked until credits exist, and approved independent videos), followed by actual owned/rostered productions, active assignments, available shot opportunities, upcoming registered events and confirmed appointments, and My Videos submission counts. The old photo welcome hero and generic promotional tiles have been removed. The UI remains light, mobile responsive and owner scoped. A schema upgrade from 26 to 27 creates a daily aggregate profile-view counter without saving personal visitor identifiers. The Live Director header now starts with a back control and functional camera search on the left; visible branding, show-title label, Member Live and Saved Shows buttons have been removed while broadcast/recording title metadata stays editable. See `docs/RELEASE_1_21_0.txt` and `UPGRADE.txt` for acceptance tests.

**New in v1.20.1 — Streamlined shared sidebar:**
The redundant sidebar introduction ("YOUR AZHOTTIE / My space. / Private member area" and its Admin Studio counterpart) is removed from the shared navigation on member workspaces, admin screens, and the signed-in public-page navigation flyout. All navigation entries, section labels, access controls, top header branding, and page titles remain in place. The first sidebar navigation section now starts near the top of the panel with consistent spacing. No database change is required.

**New in v1.20.0 — My Videos, User Submissions and readable dashboards:**
Models can create independent video posts from private My Media originals or edited copies at `my-videos.php`, edit details, submit videos with explicit rights consent, and see draft, pending, needs-changes, rejected or published status. Assignment submissions continue exclusively through `my-work.php`.

Admins have a new `user-submissions.php` queue with protected video previews, approve/publish, request-changes, reject-with-reason, and unpublish actions. Both `admin-studio.php` and the signed-in `dashboard.php` surface real pending/published review counts. Public Watch shows only approved posts from active model accounts with confirmed publication consent. Source gallery assets are not automatically published and stay in owner-private storage. Model video records use an automatic **schema upgrade from 25 to 26** on first request, with no installer rerun.

The shared dashboard, admin cards, interviews and segment containers now use light backgrounds and dark text for readability; video monitors/players remain dark. See `docs/RELEASE_1_20_0.txt` for the review workflow, package verification and production acceptance checklist.

**New in v1.19.1 — Timeline audio editing and phone preview reliability:**
`my-editor.php` now offers left/right trim handles and per-video right-click
menus, source-audio detachment onto an independently movable A2 timeline lane,
per-clip volume envelopes with right-click keyframe insertion and draggable
volume points, and freely draggable floating clip/audio inspectors. Save, reload,
preview, and export preserve the edit. Originals remain unmodified.

In `live-director.php`, phone tiles now distinguish approved phones from phones
actively offering live video; WebRTC host/phone configuration uses the same
approved STUN/TURN servers and connection failures produce actionable statuses.
A QR-invited, signed-in phone can participate in the approved camera flow.
Registration and local recording **do not alone prove that WebRTC video arrived**.
For devices on separate networks, configure a TURN relay where necessary.

This is an in-place upgrade without a database migration. Stop broadcasts,
back up the website/database/complete `storage/` directory, preserve all existing
private storage and uploads, do not rerun `install.php`, then hard-refresh the
editor and Director. See `docs/RELEASE_1_19_1.txt` and `UPGRADE.txt`.


**New in v1.18:** Live Director has persistent, real moving camera thumbnails
for every locally attached or approved live phone feed, per-source video-frame
health/diagnostics, camera loss handling, program microphone and opt-in camera
audio mixing with meters, reusable private scene presets, uploaded image
logos/graphics overlaid on Program, and verified, resumable saved recordings.
The public receives only the Program canvas, never private camera thumbnails.
The schema stays at version 25. See docs/RELEASE_1_18.txt.

**Earlier v1.14:** private Agent Chat canvas and a sticky member footer composer (admin enabled, OpenAI/Claude); simplified media hero; bottom-aligned, single-toggle phone camera controls and a local trim/export tool; light-gray community calendar and higher contrast scheduling surfaces; full-viewport personal video editor with theme-matched, independent pane scrollbars.

A self-hosted PHP workspace for producing AZHottie content from the first brief through model assignments, filming, review, editing, and explicit publication. Existing interviews retain question cards, answer trims, and fades; production edits can omit cards or choose them per clip.

The package includes a light public landing page, a public **Watch** feed, a signed-in member area, and a private production studio. Members can hold several roles, attend events and classes, and book professionals. Administrators manage the interview library, model profiles, questionnaire builder, recording room, editor, and finished-video library. Approved phones can upload footage and optionally supply a live camera view. Compatible local cameras and approved phone feeds can be selected during a continuous take while the host's microphone stays the same.

Interviews, profiles, questionnaires, original recordings, review notes, edit settings, and explicitly saved finished videos are stored on your server. An administrator can publish a selected finished version to the public Watch feed, with its title, description, model credit, and cover. Saving or exporting a video does not publish it. Interviews, original takes, casting notes, member calendars, and appointment details remain private.

## Signed-in navigation and model media gallery (v1.10)

- When signed in, the universal header shows only the **sidebar hamburger** (left of the logo), AZHOTTIE logo, and member label. All navigation links and Sign out are in the existing left sidebar, including on phones. The same sidebar is available from the header when a member opens Watch or the landing page. Logged-out visitors retain the public navigation.
- The hamburger opens/closes the left sidebar at every viewport width; mobile uses normal document scrolling, not a second scrolling box.
- Models and professionals have a **My media & editor** sidebar link (`my-media.php`). Admins also have this private personal editor alongside their separate interview editor. Non-admin visits to `editor.php` redirect to the personal editor; administrator-owned interview footage remains restricted to admins.
- A contributor can upload MP4, MOV, WebM, or Ogg footage into a **private owner-scoped media gallery**. The server validates file signatures, uses up to 1 MiB upload chunks with server checkpoints and retry support, and stores files beneath the protected `storage/media` directory. Other members, fans and public visitors cannot retrieve the originals.
- The model editor can save titles, personal notes, non-destructive in/out points, and an uploaded or captured cover image. In browsers that support canvas capture, Web Audio and MediaRecorder, **Export & save edited copy** renders a new private video; the original remains untouched. A local edited download is available if uploading the result fails. Export requires keeping the page visible.
- Default private media limits: 1 GiB per file, 5 GiB per member gallery, 300 media entries; actual PHP and hosting quotas may be lower. The administrator can adjust `max_personal_media_bytes` in private storage configuration if hosting capacity allows. Incomplete uploads are limited to eight per account.
- Personal media **does not automatically enter a production or the public Watch feed**. Models submit footage for assignments through `my-work.php`; publishing still requires administrator review and explicit publication.
- The database upgrades from schema 17 to **18** automatically at first request after you deploy. Preserve existing `storage` and database; **do not rerun the installer** on an existing site.

## Install on your hosting account

1. Upload the deployment ZIP to your PHP hosting account and extract it into the website root or a subfolder. The folder containing `index.php` is the application root. For a subfolder installation, the address might be `https://example.com/studio/`.
2. Preserve the included `.htaccess` files when extracting or copying the package. They protect the private application files on Apache hosting.
3. Make the `storage` directory writable by PHP. The installer creates `storage/media` and `storage/uploads` if needed. Use the permissions recommended by your host; world-writable permissions are not required.
4. Open `https://example.com/studio/install.php`, using your actual domain and installation path. The installer checks PHP, database support, upload settings, and storage access.
5. Enter your administrator name, email address, and password. **No installation, license, or security key is required.** This account is the normal login for your private studio.
6. Keep SQLite selected for automatic database setup, or choose MySQL and enter the details of an existing empty database. For MySQL on shared hosting, create the database and its user in the hosting control panel first, and grant that user access to the database.
7. Select **Create my studio**. You are signed in and taken to the interview library. The installer locks after successful setup.

Use the site's HTTPS address. Browser camera/microphone capture and phone connections require it on a deployed site; localhost is supported for development.

### Server requirements

| Requirement | Details |
|---|---|
| PHP | 8.1 or newer |
| Database | PDO with `pdo_sqlite` or `pdo_mysql` |
| Database privileges | The application account must be able to create tables/indexes and add columns for installation and upgrades. |
| Sessions | PHP sessions enabled with a writable session storage location |
| File uploads | Enabled; `post_max_size` at least 128 KB and `upload_max_filesize` at least 64 KB |
| Photos and video covers | JPG, PNG, or WebP up to 5 MiB, uploaded in one request. To allow the full image limit, set `upload_max_filesize` to at least `5M` and `post_max_size` higher, such as `8M`. Covers also have a 12,000-pixel limit per side and a 40-megapixel total limit. |
| Application storage | PHP can create and write files in `storage`, `storage/media`, and `storage/uploads` |
| Web server | Apache with the included access rules enabled, or equivalent private-directory protection on Nginx |
| Browser | JavaScript, IndexedDB, media playback, and the capture/export APIs used by the requested feature |

Production use does not require Node.js, Composer, FFmpeg, a queue service, or a background worker. Browser capture and export use the browser's own media capabilities. Optional live phone feeds use WebRTC; relay configuration is described below.

Uploads are divided into chunks negotiated against the server's PHP limits, up to 1 MiB per request. The default maximum original recording size is 2 GiB per file. The administrator can change `max_upload_bytes` in the private `storage/config.php` if the hosting plan supports a different limit. Available disk space still applies.

## Upgrade an existing installation

**Keep your entire existing `storage` directory, database, and administrator account. Do not run the installer again.**

Back up the installation, finish active recordings/uploads, and replace the application PHP, JavaScript, CSS, and other distribution files with release 1.7.0. Merge the package into the existing directory instead of deleting and recreating the installation. Open the studio and sign in. Database additions run automatically on the first database access and are designed to be repeatable after an interrupted upgrade. The final database schema version is **16**.

The accounts migration at schema 4 gives every pre-existing account the administrator role once, preserving the access those accounts already had. New sign-ups do not inherit administrator access. Review the migrated accounts in **Accounts** and adjust their roles as needed. Later migrations add scheduling, explicit public publication, covers, production work, and optional assistant proposals without reinstalling the studio.

Existing interviews retain their answers, original takes, notes, and edit settings. Existing participant names are not automatically converted into model profiles; create a profile and link it from **Session details** when useful. Custom questionnaires are selected for new interviews.

See **UPGRADE_V1_9.txt** for the current upgrade sequence, backups, and rollback considerations.

## Pages and sign-in

All paths below are relative to the folder where you installed the application.

| Page | Purpose |
|---|---|
| `index.php` | Public AZHottie model-led membership landing page with the bright studio hero |
| `apply.php` | Public model casting form (editable questions/answers, no account required) |
| `casting.php` | Admin-only casting CRM, lead stages, private notes and editable questionnaire |
| `model-invite.php?token=…` | One-time invitation for an administrator-approved model account |
| `dashboard.php` | Signed-in member workspace with left sidebar and right page canvas |
| `watch.php` / `watch.php?id=…` | Public feed and individual posts for explicitly published finished videos |
| `install.php` | First-run administrator and database setup; locked after installation |
| `signup.php` | Member registration and model/professional role requests |
| `login.php` | Member and administrator sign-in |
| `account.php` | Own details, roles, password, and permitted linked model-profile fields |
| `accounts.php` | Administrator account management, role approvals, and model-profile links |
| `admin.php` | Administrator AI keys, provider models, access checks, routing and daily generation limit |
| `calendar.php` | Signed-in events and class sessions, registration, and attendance tools |
| `classes.php` | Reusable classes and their scheduled sessions |
| `appointments.php` | Member bookings; professional/admin service and availability management |
| `productions.php` | Administrator production projects, shots, contributors, review, and editor handoff |
| `director.php?projectId=…` | Owned production coverage, usable sections, first-cut builder and saved editable versions |
| `my-work.php` | Model/professional assigned work, open shots, submissions, and completed work |
| `library.php` | Administrator's active and archived interviews |
| `profiles.php` | Administrator's private model profiles and linked interview history |
| `questionnaires.php` | Administrator's reusable question-set builder |
| `interview.php?id=…` | Administrator recording room for an owned interview |
| `editor.php?id=…` | Administrator editor for that interview's saved takes |
| `videos.php` | Administrator's saved finished versions, covers, and publication controls |
| `phone.php?invite=…#key=…` | Limited phone access reached through the full interview invitation |
| `logout.php` | Signs out through the studio header form |

`work-api.php` handles scoped project and assignment operations; `work-upload-api.php` and `work-media.php` handle contributor uploads and private footage/references. `work-planner-api.php` provides optional quick shot-list proposals. `production-plan-api.php` provides owner-only saved scripts, casting and optional plan proposals; `work-thread-api.php` provides private assignment questions to the owner and assignee. `director-api.php` handles coverage and usable sections; `edit-api.php` saves named editable cuts and revision history; `edit-composer-api.php` provides optional editorial proposals. The planning, Director, named-edit and editorial-proposal APIs require administrator ownership. `api.php` handles authenticated administrator interview and finished-video requests. `accounts-api.php` serves account operations, and `schedule-api.php` serves the private calendar and appointment workflows. `media.php` serves private recordings, profile photos, finished videos, and covers after checking account access, and supports seeking and downloads. Access media through the application rather than directly from storage.

`content-api.php` supplies the public published-video feed and post details. `public-media.php` streams only currently published finished videos and their covers. These public routes do not expose original takes, review notes, member accounts, or appointment records.

`phone-api.php` has separate host and phone operations. A phone receives an interview-scoped capability after requesting access and must be approved before uploading or sharing live video. It does not receive an administrator login or general studio-library access.

## Production projects and model work

Open **Productions** to create a campaign or shoot. Save its title, product/service line, brief, and project kind. A project starts in **Draft** for planning; switch to **Active** to let contributors claim work or upload. **Completed** stops new work while preserving footage and finished cuts. **Archived** also closes its phone room and unpublishes the project's public cuts; restoring it does not republish them.

A project can link to one calendar event/class session or an appointment you are authorized to view. Linking is a reference, not a new booking, registration, or capacity reservation. Members only receive schedule details that their own account can access. All production deadlines use America/Phoenix.

### Add models to this production

Open a saved production and use **Add models to this production**. Search approved model/professional accounts, select several, and choose **Add selected models**. The saved roster shows each included person's roles and current assignment count. A production can include up to 200 accounts. Approve or create accounts in **Accounts** first; a standalone casting profile cannot sign in or receive work.

Included members see an active production in **My work → My productions**, even before they receive a specific assignment. Its eligible open shots appear in **Open shots**. Adding someone to the roster does not assign every shot or consume a contributor place. Individual assignments remain a separate action.

You can edit a roster while a production is Draft or In progress. Completed and Archived rosters are read-only until reopened. **Remove** stops access to new open work and new claims; it preserves the member's existing explicit assignments, submitted footage, and review history. Cancel an assignment separately when needed. Current account status, roles, and the assignment/project state still control whether existing work can continue.

When upgrading, existing noncancelled/nondeclined contributors are added to their productions automatically. Productions with unclaimed open shots need their intended members added to the roster. Other accounts are not enrolled automatically. A membership change does not invalidate an otherwise current AI proposal or edit.

### Save a production plan and script

Open **Production plan** inside a saved production. Enter the objective, product/service focus, finished length, format, creative direction and requested deliverables. Add ordered scenes with an action, spoken lines or talking points, performer role, and props/reference notes. Each scene can contain several shots with framing, instructions, orientation, target duration and requested takes.

For each shot, choose **Leave unassigned**, **Open to included models**, or **Assign to specific models**. Direct casting selects eligible accounts from this production's roster. Set contributor places, eligible roles and an optional Phoenix deadline while reviewing the same plan. The plan supports up to 20 scenes and 60 shots, with up to 12 shots per scene.

**Save production plan** preserves work for a later session. Saving does not create shots or invitations. Review the exact distribution and recipients, then **Create shots and assignments** to create the pending work together. A bad or stale casting choice leaves the batch unapplied. Retrying a completed request recovers its saved result instead of duplicating shots or notifications.

Created scenes remain attached to their original script snapshot and assignments. They are read-only in the plan; open the created shot to change its current directions, or append a new scene for additional work. Existing assignments, footage and reviews are preserved. The plan shows current progress from the actual linked work, including open, assigned, accepted, submitted, retake, approved and closed states. A completed or archived production's plan is read-only until reopened.

Models see the relevant original scene and script on their shot/assignment page beside the normal filming directions and recording/upload controls. The full plan and other private assignments remain producer-only. Current shot directions take precedence if the producer later edits the created shot.

### Draft the plan with AI

The optional plan assistant uses the **Shot-list assistant** provider/model already configured in **Admin → AI settings**. Save the plan direction first, then request a draft. The assistant drafts scenes, actions, lines, performer-role labels and camera shots. Review the proposal before adding its scenes to the plan, edit the script and select actual roster accounts locally, save, and review the assignment batch before creating work.

The provider receives the selected project/plan creative text and existing scene/shot titles. It does not receive account records, roster identities, messages, private casting profiles, reference-file bytes, video or audio. Generated performer roles are creative labels, not invented bookings or assignments. Existing saved plans are never silently replaced by a response; stale proposals must be reviewed through the recovery controls or generated again from current direction. Generation and adoption create no live assignments by themselves.

The existing quick shot-list assistant remains available for a simpler list without a saved multi-scene script. Plan, quick-shot and editorial generation share one per-admin request allowance and one pending generation limit. No new API key or background worker is needed.

### Create the shot list

Each shot has a title, instructions, orientation, target duration, requested number of takes, deadline, order, and references. You can upload JPG, PNG, WebP, or PDF references up to 20 MiB each, subject to the host's single-request upload limits. To allow the full reference limit, configure `upload_max_filesize` at least `20M` and `post_max_size` above it, such as `24M`. Normal footage still uses negotiated chunks.

Choose **Assigned** to invite specific eligible accounts from the production's roster, or **Open** to let eligible included members claim a place. Direct assignments can be prepared in Draft; claims and uploads require In progress. Set the contributor limit and eligible roles for each shot. A member qualifies through either selected role. Concurrent claims are checked again on the server; the last place cannot be claimed twice. Producer capture is credited as a producer contribution and does not consume a model's contributor place.

Members sign in to **My work**, with five tabs:

| Tab | What appears |
|---|---|
| Assigned | Invitations to accept/decline, accepted work, and requested changes |
| My productions | Active productions whose rosters include this member, including those without assigned shots yet |
| Open shots | Open shots from included productions, with role eligibility and available places shown |
| My submissions | Work submitted for producer review |
| Completed | Completed, declined, or cancelled assignments and their retained history |

A member can view their own assignments, authorized references, takes, feedback, and published results. Included members see eligible open shots and their own direct assignments; another person's private direct shots and submissions remain hidden. In-app updates announce active-production membership, activation, newly opened eligible shots, assignments, and reviews. Administrator accounts manage their own projects. An account with multiple roles keeps one login.

### Record, upload, and submit

Accept an invitation or claim an open shot. Open the assignment, select a camera/microphone, and record, or choose multiple existing video files. Portrait member recording uses 720 × 1280 and square recording uses 720 × 720, with a matching preview crop; the studio keeps its 1280 × 720 default. If canvas capture is unavailable, the recorder announces a native-frame single-camera fallback. Each take is bound to the exact assignment, contributor, and shot on the server. An administrator can upload on an assigned member's behalf; the contributor and the actual uploading administrator remain separately recorded.

Local recording/upload drafts are kept in IndexedDB for the same signed-in account and assignment. Reopen that assignment in the same browser to resume from the server-confirmed chunk. A lost completion response recovers the original receipt instead of adding a duplicate take. If a changed brief makes the old upload stale, **Restart upload · keep recording** confirms closure of the old upload and preserves the local footage for a fresh attempt. **Download copy** is available for local drafts. Keep the browser's site data until the server confirms saving; browser storage is not a substitute for server backups.

Saved uploads begin as **Draft** takes. Choose **Submit takes for review** when the batch is ready. The producer receives an in-app notification. A changed brief, closed project, cancelled assignment, removed role, or suspended account is rechecked during upload; a stale or unauthorized destination is not silently accepted. Keep the local original and coordinate a fresh eligible assignment if access changes.

### Ask questions about an assignment

**Questions & replies** keeps a private text conversation between the producer and the assigned model/professional beside the assignment. Ask about a line, wardrobe, framing or a deadline before filming; replies remain with the work. Each message allows up to 4,000 characters. The latest 30 messages load first, with **Load earlier messages** for older history and unread counts for incoming messages. Refresh checks for new replies; no external email or SMS is sent.

A failed send preserves its draft and request identity for retry. Opening a conversation acknowledges the messages shown, without consuming a newer message that arrives during the read. Removing someone from a production roster preserves this conversation for their existing assignment. Completed/archived productions and declined/cancelled assignments retain read-only history; unavailable accounts and producer self-contributions cannot start a new exchange.

### Review and retakes

Open an assignment from Productions. Play each submission, mark favorites, save timestamped notes, and choose **Accept take** or **Request retake** with feedback. New attempts are added to the history; they do not overwrite prior footage. The member sees the review notification and feedback in My work.

Accepted takes become available in the production editor. The original recording room cannot delete a submitted production take out of its history; use the assignment review and cancellation controls. Withdrawing an accepted source or cancelling its assignment unpublishes the related project's cuts. The server checks sources again before saving an export and before publishing an existing version.

### Studio and approved phones

Use **Studio & phone cameras** from a project to record against its shot list using the existing recording room. Its QR invitation, host approval, local/USB camera selection, optional approved-phone live inputs, and fixed/random switching remain available. An approved phone targets a current active shot. Captured studio/phone takes enter a producer contribution for submission and review.

Upload access and a live camera feed are separate capabilities. A successful phone upload does not prove a live WebRTC connection will work on that network. Phone and camera interoperability limits are listed under Validation status.

### Edit and publish

**Open editor** uses the project's accepted footage. Choose interview question cards for interview-style content, no cards for a production montage, or per-clip cards in a mixed cut. Trim, order, preview, and fade footage using the existing browser renderer. A mixed project can import a shortlisted, nonprivate take from an owned active interview; it retains source provenance and review notes. Making that original private, archiving/deleting its interview, or deleting its original take withdraws the imported source from future exports and related public cuts.

After rendering, explicitly choose **Save finished video**. Open the saved version in **Finished videos**, enter its title/description and public contributor credit, upload a cover or capture a video frame, then choose **Publish to fan feed**. Project and editor links remain attached to the finished version. Saving, accepting, rendering, or marking a tracking status Published never publishes a fan post by itself.

## Production Director and editable versions

Open an existing production and choose **Production Director**. Its Coverage, Footage, First cut and Saved cuts views stay attached to the same production, assignments and accepted source footage.

### Coverage requirements

Define the opening, product detail, demonstration, answer, reaction, supporting footage or closing material needed. Set a minimum number of distinct usable takes, an optional usable-duration target, required/optional status, order, and linked shots. Coverage shows missing footage, waiting reviews, actionable retakes and readiness. **Create or open missing shot** reuses the normal shot/assignment workflow; retrying it does not create a duplicate request. Marking a requirement ready is based on accepted labeled footage with known usable duration, not visual or audio analysis.

Multiple marked sections of one source count as one take. Overlapping sections contribute their union to coverage duration. Unknown or sub-100ms durations do not satisfy usable coverage. A completed production permits marking usable sections and editing cuts, while new coverage plans and new shots require reopening the project.

### Mark usable sections

In Footage, play an accepted take and mark a start/end or enter exact seconds. Choose the coverage requirement, add a section label and editorial notes, and mark a preferred section when useful. Existing favorites and timestamped review notes remain visible. A section must last at least 0.1 seconds within the reported source duration.

Curated ranges constrain the initial first cut. Once a requirement/take has marked sections, the builder does not silently widen it to the entire recording. Withdrawn or private sources no longer contribute to readiness or new drafts.

### Prepare a first cut

Choose 30 seconds, 60 seconds, Full selection or a custom target. The manual builder prefers marked preferred sections, then favorites, and produces an editable proposal. It selects a source at most once, accounts for black question-card time, and keeps fades inside answer duration. A short target reduces selected ranges; missing coverage and insufficient material remain visible.

Review the sequence, title cards and trims, preview individual sections, then **Save as named draft**. Initial draft creation rechecks the exact coverage fingerprint and candidate range boundaries. Open the draft in the existing editor for continuous preview and real-time export. Output aspect, fit, resolution and card settings remain editor controls.

### Save and restore editable cuts

Each named cut has independent clip order, inclusion, trims, cards and output settings. Saving a cut preserves a numbered immutable revision. The editor supports Save as new cut, renaming/saving the current cut, viewing historical revisions, restoring a revision, and archiving a cut. Historical or archived views require a new cut or restore before editing/rendering. Newly accepted footage does not silently join an existing named cut.

A saved finished video records its exact named-cut ID and revision. Its return link opens that source revision, and the cut lists its finished exports. A cut with finished exports can be archived but cannot be deleted as an unused draft. Deleting an unused draft preserves original recordings. Legacy interview edits continue using their existing save path.

### Optional AI edit proposals

Configure the editing provider in **Admin → AI settings**. In Director, **Optional AI edit proposal** uses the saved brief, coverage requirements, accepted labels, section notes and timestamped review-note excerpts to suggest a sequence. It sends no video/audio bytes, reference files, account records or casting profile records. Up to 40 in-range review notes per candidate are included, each limited to 1,500 characters, with truncation/omission metadata; large requests are rejected with a manual-builder alternative.

The assistant receives at most 120 candidate sections and may propose up to 40 distinct sources. Its output must use current candidate identities and valid ranges. Generated ordering and missing-coverage suggestions remain editable before creating a named cut. Applying a stale proposal fails without leaving a partial timeline. This is metadata-based editorial help; transcription and visual footage analysis are later additions.

Production-plan, quick shot-list and edit-proposal requests share the configured daily request allowance. Pending request identities survive interrupted browser responses; Check saved proposal and Retry same request recover the original intent. Provider responses are saved before a draft is created. No AI request publishes footage, assigns contributors or changes an existing timeline automatically.

## Optional shot-list assistant

The production workflow works without an AI provider key. **Admin → AI settings** (`admin.php`) now manages separate OpenAI and Claude (Anthropic) connections. Settings and credentials belong to the signed-in administrator. The keys are encrypted on the server using the installation secret in `storage/config.php` and are never returned to browser code. Preserve that configuration with your backups. PHP OpenSSL and outbound HTTPS streams are needed for the optional assistants.

### Connect an assistant

1. Open **Admin → AI settings** and enter the OpenAI key, Claude key, or both. Leave **Enable AI assistants** off while configuring a new connection, then save. A blank key field keeps its saved key; the explicit remove checkbox clears it.
2. Choose **Check key & load models** on the provider card. This uses the saved key to retrieve available text-model candidates. It makes no content-generation request. The OpenAI model list does not confirm structured-output capability; Claude capability metadata is used when supplied. Generation compatibility and provider billing remain separate from this access check.
3. Choose a model from the dropdown. For a Claude key that is not scoped to a workspace, enter its workspace ID; workspace-scoped keys can leave that field blank.
4. Choose the **Shot-list assistant** (including full production plans) and **Editing assistant** providers independently. Both can use one provider, or each can use a different provider. Set the shared daily generation limit, enable assistance, and save.
5. Return to Productions or Director. Use **Check saved proposal** to refresh an already-open page, then generate a small proposal and review it.

The daily limit counts production-plan, quick-shot and editing generation attempts across both providers, resets at 00:00 UTC, and is not a dollar budget. One pending generation per administrator is allowed. Connection checks do not consume the generation allowance. There is no automatic fallback to the other provider.

Existing OpenAI key/model/enabled/limit settings migrate automatically into Admin. The old credential copy is removed during migration; old proposal records and retry receipts are retained. Keys can be replaced or removed independently. Removing a key used by an enabled assistant requires switching that assistant to another configured provider or disabling assistance in the same save.

### How production assignments are generated

A production starts with a brief. You can write shot instructions yourself, ask the selected AI provider for an editable shot list, or use Director to create a shot for missing coverage. The AI produces a title, framing/action or spoken-beat instructions, orientation, target duration and requested take count. There is no dedicated full-screenplay generator or automatic performer matching in this release; a script or dialogue can be included in the project brief or shot instructions.

**Create a shot list from this brief** sends the project title, brief, product line/kind, existing shot titles, requested count and additional direction to the chosen provider. No footage, reference files, account records or casting-profile records are included. Text that an administrator writes in the brief or direction is sent as part of that request. One proposal is limited to 12 shots and 6,000 output tokens.

A complete response becomes a saved, editable proposal. Edit titles, instructions, orientation, duration and take counts, remove unwanted shots, then choose **Add reviewed shots to project**. The application creates unassigned shots atomically. You then assign a model/professional, or open a shot for eligible contributors to claim within its capacity. AI does not dispatch assignments, publish content or change an existing timeline automatically.

Each saved proposal records its provider and model. Changing Admin settings affects new requests; retries retain the original receipt. If the project or shot list changed since drafting, the old proposal cannot overwrite newer work. Refusals, invalid output, incomplete responses, timeouts and provider errors create no shots. The editing assistant likewise validates its suggested clip IDs, ranges and runtime before a proposal can be applied.

Automatic transcript/frame understanding, AI shot scoring and server-side rendering remain later extensions. The browser editor renders video. See `docs/AI_SETTINGS_API.txt`, `docs/RELEASE_1_5.txt` and the historical Stoneward inspection in `docs/AI_PRODUCTION_NEXT.txt`.

## Accounts and multiple roles

Each person uses one account and can hold any combination of **fan**, **customer**, **model**, **professional**, and **administrator** roles. For example, a model can also provide professional services and book another professional's appointments.

Members register at **Join / Sign up**, confirm that they are 18 or older, and choose their interests. Fan and customer access starts immediately. Model and professional access requires an administrator to approve the requested roles in **Accounts**. A registration requesting only model/professional access receives customer access while those requests are pending. Members can request additional model or professional roles from **My account** later. Administrator access is assigned by an existing administrator.

Administrators can review pending requests, approve or decline roles, suspend or reactivate accounts, and link an approved model to an existing production profile or create one. The linked model can edit their permitted profile fields from **My account**; private casting notes and interview footage remain in the production studio. A profile's bio is not automatically a public website profile.

Suspension stops sign-in and new member activity while retaining history. Removing the professional role or suspending a provider stops new appointments with that provider. Existing registrations and appointment history are preserved; administrators can manage outstanding bookings instead of losing their records.

## Private calendar, events, and classes

The calendar is one shared studio community, available only after sign-in. Administrators create events, choose their eligible roles, set an optional instructor/professional, and publish them. Members see published events allowed by their current roles. Drafts and full rosters are restricted; a member sees their own registration without other attendees' account details.

All scheduling uses **America/Phoenix** studio time. The server stores UTC timestamps and presents the corresponding Phoenix dates and times. Choose Phoenix time when planning an event or appointment, even if your current device is in another time zone.

- **Minimum capacity** is an enrollment target. The page reports whether the target has been met; falling below it does **not** automatically cancel an event.
- **Maximum capacity** is the seat limit. Further registrations join the waitlist. A cancellation promotes the earliest active, eligible, conflict-free person on the waitlist.
- Members can register or cancel their own upcoming registrations. The server rejects conflicting appointments or event registrations and prevents duplicate enrollments.
- Administrators and an event's assigned professional can manage its attendance: **Unmarked**, **Present**, **Absent**, or **Excused**. Other members cannot open the roster.
- Canceling an event closes its registrations. Existing enrollments also protect against silent time/instructor changes, unpublishing, or a capacity reduction below occupied seats.

**Classes** are reusable templates with a title, duration, instructor, location, eligible roles, and capacity settings. Administrators schedule individual or repeated sessions, with up to 12 sessions per batch. Every session is an event with its own registration, waitlist, and attendance. Editing or archiving a class template does not rewrite its existing sessions.

## Professional appointments

Open **Appointments** to choose a service, professional, date, and available time. **Models can book appointments**, as can active fans, customers, professionals, and administrators. A customer role is not required for a member whose model role is already active.

Professionals manage their own appointment types, durations, locations, buffers before/after a session, minimum booking notice, advance-booking window, weekly hours, and blocked time. Weekly hours can contain several intervals on the same day. Administrators can manage these settings for professionals.

Available slots account for working hours, blocked time, booking notice, existing appointments, buffers, and the professional's scheduled events/classes. The server also checks the member's own commitments. These checks run again when a booking is saved, so two people cannot reserve the same professional time simultaneously.

Members see their own bookings and can reschedule or cancel upcoming confirmed appointments. Professionals see their assigned appointments and can mark completed sessions or no-shows after the scheduled start. Administrators can manage all appointments. Another customer's appointment notes and details are not included in the member calendar. A professional who also books services as a customer uses the same account for both activities.

## Model profiles

Open **Models**, choose **Add model**, and save a stage name. A profile can also hold a photo, public-bio draft, social handles, project interests, availability, and private production notes. The public-bio field is stored privately; it does not publish a website profile.

Use **New interview** from a profile to preselect that model. Its history lists linked interviews. You can link an existing interview from the recording room's **Session details**. Each interview retains its own participant display name.

Archive a profile to remove it from active choices while retaining its history. Restore it before starting another linked interview. Deleting a profile removes its profile record and photo and unlinks its interviews; existing interviews, takes, historical display names, and finished videos remain.

## Build reusable questionnaires

Open **Questionnaires** and create a named set. Add your own questions, import selected prompts from the 80-question AZHottie starter bank, or copy the entire bank. Each question has text, a category, an open-answer or multiple-choice type, and a private-casting flag. Use **Move up** and **Move down** to arrange the conversation.

A set supports 1–200 questions. Multiple-choice questions use 2–12 nonempty choices, one per line. Sets can be duplicated, revised, archived, or deleted.

Choose a saved set when creating an interview or use **Use for new interview** from the builder. The server saves a copy of the questionnaire with that interview. Later edits, archiving, or deletion of the reusable set do not change the saved interview questions. Interviews without a selected set continue to use the included starter bank and their existing custom questions.

## Conduct and save an interview

1. In the library, choose **New interview**. Enter a title and participant name, optionally choose a model profile and questionnaire, and add session notes.
2. Open the recording room and choose a question. The included question bank separates public creative prompts from private casting questions. Written answer notes are saved per question. To add a custom question with an existing video, enter its question text in the editor’s import section.
3. Enable the camera and microphone and approve the browser's device permissions. Select the intended devices in the recording settings.
4. Record an answer, or import a video that the browser can play. Recordings retain their question text, duration, answer-start marker, and private-question flag.
5. Wait for upload completion. Use **Save interview** for the interview's written information and settings, and check the displayed saved state before leaving.

To keep the spoken question out of the final answer clip, record the question and answer together and select **Mark answer start** when the answer begins. The editor uses that marker as the starting trim; you can adjust the exact start and end with the preview playhead.

### Favorite takes and timestamped notes

Use **Favorite** to mark a take for review. Open **Review & notes**, pause at a specific moment, and add a note. Its timestamp is relative to the original take; selecting the time seeks back to that point. Notes are also available while adjusting a clip in the editor.

Review labels are **Unreviewed**, **Shortlisted**, **Needs another take**, and **Private only**. Favorites and labels organize review; favoriting alone does not choose a take for the final sequence. Private takes are excluded from automatic assembly, and marking a take **Private only** removes it from the current edit. Review changes do not rewrite previously exported or saved finished movies.

### Resume, archive, and delete

- Reopen a saved interview from the library to continue its notes, recordings, and edit. Server-saved work is available after signing into the same account on another supported browser or device.
- An interrupted upload can leave a pending recording in the original browser's IndexedDB storage. Return in that same browser and use the pending-recording controls to retry. Keep that browser's site data until the upload succeeds; a locally pending file is not yet a server backup.
- Archive an interview to move it out of the active list while keeping its recordings and edit settings. Phone access closes and its finished videos are unpublished from Watch. Restore the interview to continue; restore does not automatically republish its videos.
- Delete a take to remove its original and review notes. Delete an interview to remove its takes, finished-video files, review data, unfinished uploads, and phone connections. Downloaded copies and separate backups are unaffected.

## Connect phones to an interview

### Invite and approve

1. Keep the host's recording room open and select **Interview QR code**.
2. Scan the QR on the phone, or open the complete copied invitation link.
3. Enter a model/device name on the phone and select **Request to join**.
4. Approve the named phone in the host's **Connect a phone** section.

The invitation lasts 15 minutes. A pending approval request also lasts 15 minutes. Approval lasts up to eight hours unless the phone is disconnected earlier. Refreshing the invitation invalidates the previous invitation while keeping approved phones connected. A room permits up to 16 pending or approved phones.

Use **Disconnect** for one phone or **Disconnect all** to revoke the current phones and close the invitation. A phone remembers its scoped connection in that browser tab's session storage so an ordinary refresh can reconnect. Opening the link in a different browser does not transfer the existing approval.

### Upload a recorded clip

On an approved phone, select **Record a clip** to use the native camera or **Choose a video** for an existing file. Review it, optionally add a title, then select **Upload to interview**.

The host-selected question and private flag are attached when the upload begins. The phone displays that question so the participant can see the current context. Finishing the upload adds an original take to the interview; it does not publish a finished video.

The phone keeps an unfinished browser recovery copy. **Resume upload** checks committed server progress instead of restarting successful chunks. **Download local copy** remains available while the file is on the page. Cancellation retains the local clip and respects an upload that finished saving just before the cancellation request. Keep the page open if recovery storage is unavailable.

If approval expires or the host disconnects the phone, uploads and live sharing stop. The current local clip remains available to download. Request a fresh invitation when another connection is needed.

### Share an optional live camera

An approved phone can choose its front or rear camera and explicitly select **Share live camera**. Keep the phone page and host recording room open. The phone sends video only; the host retains the microphone selected in the recording room.

A connected phone can be the initial camera: select its feed before enabling capture. The host still supplies a microphone, but a local webcam is optional.

The host can select a connected phone as a camera source when a rotation slot is available. Phone previews have an eight-feed ceiling, and recording rotation supports **eight total sources**, including local cameras and phone feeds. A connected preview does not override that total-source limit.

The Live label appears only after the WebRTC peer is connected. Connection setup has a readiness timeout and bounded retries. If a network cannot establish the live connection, the phone reports the failure and recorded-clip uploads remain available. Live sharing stops when the phone page is left or approval is revoked. The camera does not restart automatically after refresh.

### Optional relay configuration

The default ICE configuration uses `stun:stun.l.google.com:19302`. Some networks may need a TURN relay for a live connection. A relay service is **not included or provisioned** by the installer.

If you have a relay provider, add a top-level `phone_ice_servers` entry inside the existing returned array in `storage/config.php`. Preserve all existing database, account, limit, and other configuration values. Example:

```php
'phone_ice_servers' => [
    ['urls' => 'stun:stun.l.google.com:19302'],
    [
        'urls' => [
            'turn:turn.example.com:3478?transport=udp',
            'turns:turn.example.com:5349?transport=tcp',
        ],
        'username' => 'YOUR_TURN_USERNAME',
        'credential' => 'YOUR_TURN_CREDENTIAL',
    ],
],
```

These are placeholders, not working service credentials. Use the URLs and relay credentials supplied by your provider, then reconnect the live feeds. Recorded-clip uploads do not use a TURN relay.

## Switch cameras during a take

Connect compatible local cameras, such as USB webcams or HDMI-to-USB devices that the browser exposes as cameras. Connect approved phone feeds if desired. Enable the host camera/microphone, open **Camera & audio settings**, and select the cameras in the rotation.

| Mode | Behavior |
|---|---|
| Manual switching | Choose a camera from the selector or use **Switch camera now**. |
| Fixed interval | Rotate through the selected cameras at the chosen interval; the default is 30 seconds. |
| Random intervals | Choose another selected camera randomly and vary the delay from half to one-and-a-half times the interval. With 30 seconds selected, delays are approximately 15–45 seconds. |

The UI supports a base interval of 10–120 seconds. Automatic switching runs during an active take and needs at least two selected, available cameras. The host microphone remains the same while the picture changes. Source loss attempts a fallback to another camera; if capture cannot continue, review the recovered partial take.

Continuous switching is recorded through a **1280 × 720** program canvas. Exporting that recording in a 1080-format frame upscales it; it does not create native 1080p detail. Imported originals can have different dimensions. Browsers without the required canvas capture capability fall back to single-camera recording and require the take to stop before changing cameras.

Each take stores the switched program as one video. Upload separate phone clips when you want additional originals to use in the editor.

## Build and export a video

Open the editor, choose the takes to include, reorder them, and adjust each answer's title and trim points. Question cards use white text on black. Card duration, fade duration, aspect ratio, resolution, and video fit are configurable.

**Takes marked private are excluded from the automatically assembled edit by default.** Review the selected takes and inclusion controls before exporting, especially when using private casting questions or imported material.

Use **Save edit** to retain the edit settings on the server. Preview the sequence, then choose **Export video**.

Export is performed **in real time in the browser**: an edit lasting ten minutes needs approximately ten minutes of rendering time, plus preparation and finalization. Keep the tab visible and the device awake while exporting. Background-tab throttling, device sleep, closing the page, or loss of browser resources can interrupt the render.

The application negotiates MP4 or WebM according to the browser's available recording/export formats. It does not guarantee that every browser can export both formats. Original media also needs to decode in the browser before it can be used in a preview or export.

After rendering, **Download finished video** saves the movie to your device. **Save finished video** explicitly uploads that rendered cut into the studio's **Finished videos** library. Enter a title, optional description, and production status, then wait for the saved-version confirmation. Saving an edit does not save a rendered movie; exporting or downloading does not automatically upload it.

### Finished-video versions

Each newly saved rendered cut receives a version number within its interview. A retry of the same completed upload recovers its existing receipt. The finished-video page supports playback/download, title and description edits, production status, an optional external published URL, covers, public Watch publication, archive/restore, and deletion. Setting the production status to **Published** remains a tracking action. Use the separate **Publish to fan feed** button to create the public Watch post.

Each file retains source-edit metadata. Finished videos rendered from a named production cut link to the exact saved editable revision that produced them. Legacy interview exports retain their existing current-editor link.

Deleting one finished video leaves its interview and original takes intact. Deleting the source interview also deletes its finished-video files. Archive or download assets you want to retain before deleting an entire interview.

### Covers for saved versions

Open a saved version in **Finished videos** and use **Video cover**. Upload a JPG, PNG, or WebP, or pause the video on the desired image and select **Capture current frame**. **Remove cover** returns that version to its default artwork. Save any pending video-detail changes before changing the cover.

Each saved finished version has its own cover. Uploads are limited to **5 MiB**, **12,000 pixels per side**, and **40 megapixels** in total. Current-frame capture produces a JPEG, preserving proportions with a maximum **1,920-pixel long edge**. The cover appears in the library and as the player poster; cards use a 16:9 crop.

Cover changes are saved immediately. **If the version is already published, uploading, capturing, or removing its cover updates the existing public post immediately.** Cover changes do not require **Update fan post**.

### Publish to the public Watch feed

Save the finished version's title and description, review its model credit and cover, then choose **Publish to fan feed**. The post becomes viewable without signing in at **Watch**, with an individual shareable URL. This publishes the saved finished cut and selected public fields; it does not expose the original interview or automatically post to an outside social platform.

Publication saves a snapshot of the title, description, and model credit. Later private metadata edits do not change those public fields until you select **Update fan post**. This differs from covers, whose saved changes take effect on an existing post immediately.

Use **Unpublish** to remove the public post while retaining the saved version. Archiving the video or its source interview also unpublishes it. Restoring either item does **not** republish it; restore the required records and select **Publish to fan feed** again. Deleting a saved version removes its video and cover files, and deleting an interview removes its finished versions as well.

## Private storage and backups

The installer writes private settings to `storage/config.php`. SQLite installations store the database in `storage/interviews.sqlite`, including accounts, role approvals, schedules, attendance, appointments, and publication records. Original takes, profile photos, saved finished movies, and video covers are in `storage/media`; incomplete uploads are in `storage/uploads`.

For a complete backup, preserve:

- `storage/config.php` and your application version.
- A consistent SQLite database backup, or a MySQL database export.
- The matching contents of `storage/media`.

For SQLite, pause writes while backing up the database and any `interviews.sqlite-wal` / `interviews.sqlite-shm` files, or use SQLite's backup facility. For MySQL, take a database export and retain matching media files. Keep `storage/uploads` as well when preserving unfinished server uploads. Browser-local pending clips are separate from server backups.

When updating application files, retain the existing `storage` directory and configuration. A clean deployment ZIP intentionally contains no administrator account, database, saved interviews, or recordings. Restore a backup's configuration and database together; the installer does not replace an administrator already present in a database.

### Apache

The root `.htaccess` and the `.htaccess` files in `storage` and `includes` deny direct requests to private directories and data files. Ensure your hosting account permits these access rules. Check that a direct request to `storage/interviews.sqlite` and a request to `storage/config.php` are denied.

### Nginx

Nginx does not read `.htaccess`. Add equivalent rules to the site's server configuration. For an application installed at `/studio/`, these directory rules must take precedence over PHP/static-file routing:

```nginx
location ^~ /studio/storage/ {
    return 404;
}
location ^~ /studio/includes/ {
    return 404;
}
```

For a website-root installation, use `/storage/` and `/includes/`. Keep your normal PHP handling and dotfile protection configured, reload Nginx, and verify that direct private-file requests are denied.

PHP's built-in development server also ignores `.htaccess`; use a development router that blocks private paths. It is not the deployment web server for this package.

## Troubleshooting

| Symptom | Check |
|---|---|
| Installer reports no supported database driver | Enable `pdo_sqlite` or `pdo_mysql` for the PHP version serving this site. |
| MySQL setup fails | Confirm the full hosting-account database/user names, password, host, and assigned database permissions. Use an empty database. |
| Storage or upload failure | Check PHP write access, available disk space, file uploads, temporary upload storage, and the hosting error log. |
| Photo or cover fails while videos work | Images use one request. Check the 5 MiB limit and PHP's whole-request/file limits. Covers must also fit within 12,000 pixels per side and 40 megapixels total. |
| An upgrade appears to have lost interviews | Check that the original `storage` and database configuration are still present. Do not create another account/database over existing work. |
| A camera is connected but not in rotation | Recording supports eight total camera sources. Check the chosen rotation sources and any source-limit message. |
| QR expired or phone disconnected | Generate a new invitation, scan it, and approve the named phone again. |
| Phone waits for approval | Approve its name from the host's open recording room. Pending requests expire after 15 minutes. |
| Live phone video cannot connect | Keep both pages open, check camera permissions/network access, and consider provider-backed TURN if needed. Recorded-clip upload remains available. |
| Phone clip will not preview | The original can still be uploaded when the phone cannot read its duration. The host's editor needs a decodable format to use it in an edit. |
| Camera/microphone cannot start | Use HTTPS, grant permissions, select the intended devices, and close another app using the same device if necessary. |
| Recording remains pending | Keep the original browser's site data, restore the connection or sign-in session, and retry the pending upload. |
| Imported video will not preview | Use a recording format that the current browser can decode. MOV/MP4/WebM container names alone do not guarantee codec support. |
| Export stops or takes too long | Keep the tab visible and device awake; real-time duration is expected. Reduce resolution or divide a very long edit if device resources are limited. |
| Finished video missing from the library | Export/download does not upload it. Choose **Save finished video** and wait for confirmation. |
| Finished video missing from Watch | Save the version, then use **Publish to fan feed**. A production status of Published is not that action. Archived videos/interviews are unpublished and need explicit republication after restore. |
| Public title did not change | Save the video details, then choose **Update fan post**. Public metadata is a publication snapshot; cover changes update immediately. |
| Model/professional tools are unavailable | Check **My account** for a pending role request. An administrator must approve model/professional access in **Accounts**. |
| No appointment times appear | Check the selected Phoenix date, active provider/type, weekly hours, blocked time, buffers, booking notice, and existing commitments. |
| Profile/questionnaire changed in another tab | The revision guard prevented an overwrite. Keep your draft available and reload the saved record before deciding which changes to retain. |
| Sign-in session expires | Sign in again; previously server-saved interviews remain available. |
| Installer says an administrator already exists | Restore that installation's configuration or select an empty database. The installer does not overwrite the account. |

## Validation status

The final checks passed **187 HTTP integration tests and 182 JavaScript tests**, with **66 production PHP files and 26 production JavaScript files** passing syntax checks. The verified score is **9/10**; the installed-browser/host/device gate remains unverified. The complete matrix and review are recorded in `docs/RELEASE_REVIEW.txt`. Server checks use real HTTP requests, isolated SQLite installations and multiple PHP workers for contention. JavaScript checks include the actual page controllers using test DOM/media objects; they are not live-browser visual or physical-device tests.

A prior browser preview was rejected by automatic URL security review, including a prohibition on alternate browser workarounds. This build did not retry that blocked preview through another surface. The installed-browser and physical-device gate remains unverified. Historical v1.2 browser observations are retained as history and are not a v1.7 pass.

Actual HostGator deployment, live MySQL, physical cameras/audio devices, Safari/iOS, phone-network WebRTC and real OpenAI/Claude requests remain installation checks. AI tests use provider-response fixtures. Run the short production acceptance sequence in the release review after updating.

The separate source archive contains test suites and disposable QA fixtures. From that source root:

```sh
python3 tests/backend_integration.py --php /path/to/php
python3 tests/production_integration.py --php /path/to/php
python3 tests/accounts_integration.py --php /path/to/php
python3 tests/scheduling_integration.py --php /path/to/php
python3 tests/publication_integration.py --php /path/to/php
python3 tests/phone_integration.py --php /path/to/php
python3 tests/phone_edge_integration.py --php /path/to/php
python3 tests/work_integration.py --php /path/to/php
python3 tests/work_roster_integration.py --php /path/to/php
python3 tests/production_plan_integration.py --php /path/to/php
python3 tests/work_threads_integration.py --php /path/to/php
python3 tests/production_plan_flow_integration.py --php /path/to/php
python3 tests/work_media_integration.py --php /path/to/php
python3 tests/work_planner_integration.py --php /path/to/php
python3 tests/director_integration.py --php /path/to/php
python3 tests/edit_versions_integration.py --php /path/to/php
python3 tests/edit_composer_integration.py --php /path/to/php
python3 tests/ai_settings_integration.py --php /path/to/php
python3 tests/ai-routing-smoke.py --php /path/to/php
node --test tests/*.test.mjs
```

## Browser API references

- [Camera and microphone secure-context requirements](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia)
- [Browser recording-format support](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder/isTypeSupported_static)
- [WebRTC connectivity](https://developer.mozilla.org/en-US/docs/Web/API/WebRTC_API/Connectivity)
- `docs/THIRD_PARTY.txt` retains the local QR generator's license and Lucky-reference attribution.

- [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) — provider schema and incomplete/refusal handling for the optional assistant.

- [OpenAI Models API](https://developers.openai.com/api/reference/resources/models/methods/list) — saved-key model discovery.
- [Claude Structured Outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs) — structured Messages responses and refusal/incomplete handling.
- [Claude authentication](https://platform.claude.com/docs/en/manage-claude/authentication) — server credentials and optional workspace selection.
- [Claude Models API](https://platform.claude.com/docs/en/api/models/list) — available models and capability metadata.


=== AZHottie Studio 1.8.0 ===
The shared header is used throughout the app. Admins receive six editable AZHottie questionnaire templates on first visit to Studio > Questionnaires. All previous custom sets and interview snapshots remain untouched.

## Social member profiles (v1.13)

The account form and social profile are separate. `user.php` presents a
social-network-style page (cover, avatar, name, role badges, bio, specialties,
and already-published Watch videos). A user can optionally choose a unique
profile handle at `account-settings.php`, giving a shareable `/user/<handle>`
link. New social profiles are private by default; the owner can choose
members-only or public visibility. Picture uploads are restricted to JPG, PNG
or WebP and are served by `user-media.php` subject to the same visibility rule.
Private personal media galleries, assignments, private interviews, casting
notes, and appointment records are never exposed through the social profile.

The authenticated global header keeps its logo and role-aware sidebar access,
with the avatar/account dropdown at the far right. The hamburger is mobile-only
where the full desktop sidebar is already visible.

## Social profile layout (v1.13.1)

`user.php` (including friendly `/user/<handle>` links) is now a standalone
profile page. It uses the universal site header and full-width cover layout,
without the logged-in workspace sidebar or breadcrumb/member-area strip. Its
header has no sidebar hamburger because the profile does not have that panel.
All other authenticated pages continue to use the normal member sidebar.
Social visibility, avatar/cover access, and private/public video rules are
unchanged. See `docs/RELEASE_1_13_1_PROFILE_LAYOUT.txt`.

### v1.15 Admin Studio and Live Director
The normal member sidebar contains only an Admin Studio shortcut. Dedicated
admin pages retain their own administration navigation. Optional `editor` role
may be granted by an admin for Live Director and its own media and camera tools,
without full account/studio administration.

Live Director uses local USB webcams and approved phone feeds, selecting one
1280×720 canvas Program output. It supports manual, fixed 30-second, and random
15–45-second camera switching and an optional host microphone. `live.php` lets
signed-in fan/customer accounts view the selected program (limited peer-to-peer
WebRTC pilot; up to six viewers, Director tab stays open). Program recordings
save to the Director's private media library and can be downloaded as backups.
For security/privacy/network requirements and the detailed rollout checklist,
read `docs/RELEASE_1_15.txt`.

### v1.16 Saved live productions and full-screen control room

`live-director.php` is a dedicated non-scrolling broadcast workspace: camera/phone
sources on the left and the program monitor on the right (stacked on mobile).
The member `live.php` page starts with the Program player, and shows published
saved live productions below it. `live-productions.php` provides title/description,
cover upload, review and admin-controlled publishing. Program recordings are
private by default; signed-in fans may watch only admin-published replays.

Upgrade to schema 24 by replacing application files while preserving storage.
See `docs/RELEASE_1_16.txt` for controls, security and acceptance steps.

### v1.17 Live switching and public show gallery

`live-director.php` is the full-screen, independent production console. Camera
and approved phone feeds enter private Preview before an explicit Take to the
Program canvas, which is the only output transmitted to members and recorded.
The operator can select Cut, Dissolve, or Fade-through-black transitions, and
switch logo, camera label, and custom on-canvas title overlays on/off. The
previously recorded show library is no longer embedded inside Director.

`live-productions.php` remains the private Admin Studio recording/archive page.
`live-shows.php` lists only recordings explicitly released as PUBLIC by an
administrator; member publishing alone does not expose a show to guests. The
`live-public-api.php` listing and `live-recording-media.php` media endpoints
re-check publication, public audience and account activity. See
`docs/RELEASE_1_17.txt` for required hosted acceptance.


## v1.19.0 — Live Show Production

Live Director (`live-director.php`) includes camera composition previews
(single, two-way split, picture-in-picture, and four-up), named Show Rundowns,
private gallery video cue/take playback, and an optional moderated Audience tab.
All broadcast video remains a single Program canvas to fans and recordings.
Fans (`live.php`) can react and chat when signed in; the Director must explicitly
enable chat each live session. Source feeds, owner media and rundown storage
remain private. See `docs/RELEASE_1_19.txt` and `UPGRADE.txt`.


### v1.22.1 — Admin overview and Live Director toolbar

Admin Studio uses the shared breadcrumb followed by one page title, without a
second AZHOTTIE / CONTROL ROOM breadcrumb. The Live Director header displays
its show / recording title to the left where the camera search used to be;
all connected phone cameras are shown without name filtering. The existing
Go live and recording flows keep their title metadata. No schema migration.
