import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=(path)=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
test('Creator subscriptions are per model, not a shared platform-wide pass',()=>{
 const core=read('includes/creator-memberships.php');
 for(const table of ['creator_club_tiers','creator_club_follows','creator_club_posts','creator_club_entitlements','creator_club_review_events'])assert.ok(core.includes(table));
 assert.match(core,/creator_id=\?/);
 assert.match(core,/role='model'/);
 assert.match(core,/function az_creator_club_can_view\(/);
 assert.match(core,/origin='admin_test'/);
 assert.match(core,/creator_club_test_grants/);
});
test('Subscription payments are disabled and cannot be activated from club forms',()=>{
 const page=read('creator-club.php'),handler=read('creator-club-action.php');
 assert.match(page,/Subscription checkout not available/);
 assert.match(page,/No charges are made/);
 assert.match(page,/staff review/);
 assert.doesNotMatch(handler,/stripe|payment_intent|charge|payout|connect|subscription_create/i);
 assert.doesNotMatch(page,/action=["']subscribe["']/);
});
test('Exclusive content requires a server-authorized post, not merely hidden UI',()=>{
 const media=read('creator-club-media.php');
 assert.match(media,/az_require_auth\(true\)/);
 assert.match(media,/az_creator_club_post_row\(/);
 assert.match(media,/az_personal_media_row\(/);
 assert.match(media,/Cache-Control: private, no-store/);
 assert.match(media,/Accept-Ranges: bytes/);
 assert.match(media,/Cross-Origin-Resource-Policy: same-origin/);
 assert.match(read('includes/creator-memberships.php'),/revoked_at IS NULL/);
 assert.match(read('includes/creator-memberships.php'),/rights_confirmed/);
 assert.match(read('creator-club.php'),/name="rights_confirmed"/);
 assert.match(read('includes/creator-memberships.php'),/ends_at>\?/);
 assert.doesNotMatch(read('creator-club.php'),/personal-media-file\.php\?/);
});
test('Only admin reviews and creators can submit; mutation requires CSRF',()=>{
 const page=read('creator-club-action.php');
 assert.match(page,/az_require_csrf\(\)/);
 assert.match(page,/az_creator_club_review\(\$user/);
 assert.match(page,/az_creator_club_withdraw\(\$user/);
 assert.match(read('includes/creator-memberships.php'),/function az_creator_club_require_admin/);
 assert.match(read('includes/creator-memberships.php'),/function az_creator_club_require_creator/);
});
test('New creator club links and schema migration are installed',()=>{
 assert.match(read('includes/bootstrap.php'),/creator-memberships\.php/);
 assert.match(read('includes/database.php'),/az_upgrade_creator_memberships_schema/);
 assert.match(read('user.php'),/creator-club\.php\?creator=/);
 assert.match(read('includes/member-layout.php'),/creator-club-admin\.php/);
 assert.match(read('includes/member-layout.php'),/creator-clubs\.php/);
 assert.equal(JSON.parse(read('package.json')).version,'1.55.0');
});
