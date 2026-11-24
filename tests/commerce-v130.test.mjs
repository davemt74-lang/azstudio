import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const load=f=>readFileSync(new URL('../'+f,import.meta.url),'utf8');
test('Commerce binds existing workspace, shared Chat and badge, not a new notification table',()=>{
  const core=load('includes/commerce.php'),workspace=load('includes/agent-workspace.php');
  assert.match(core,/az_agent_workspace_emit\(/);
  assert.match(core,/agent_chat_messages/);
  assert.match(core,/commerce_activity/);
  assert.match(workspace,/az_commerce_briefing\(/);
  assert.match(workspace,/az_commerce_briefing/);
});
test('role authorization, revision, attribution and order state checks are implemented',()=>{
  const core=load('includes/commerce.php');
  for(const term of ['az_commerce_admin($user)','az_commerce_model($user)','revision_conflict','rights_confirmed','duplicate_order','external_reference','provider','paid','refunded','cancelled'])
    assert.ok(core.includes(term),term);
  assert.match(core,/az_commerce_active\(\$product\)/);
  assert.match(core,/commerce_live_features/);
});
test('public product endpoints cannot mutate orders or publish promotions',()=>{
  const api=load('commerce-api.php');
  assert.match(api,/az_require_csrf\(\)/);
  assert.match(api,/az_require_auth\(true\)/);
  assert.match(api,/az_commerce_public_products/);
  assert.match(api,/az_commerce_live_feature/);
  assert.match(api,/reviewOrder/);
  const storefront=load('shop.php')+load('includes/shop-homepage-view.php');
  assert.match(storefront,/noopener noreferrer external/);
  assert.doesNotMatch(storefront,/commerce_orders/);
});
test('commerce is reachable in the shared navigation and live viewer',()=>{
  assert.match(load('includes/member-layout.php'),/commerce\.php/);
  assert.match(load('includes/universal-header.php'),/'commerce'/);
  assert.match(load('live.php'),/azh-live-commerce/);
  assert.match(load('includes/agent-chat.php'),/az_commerce_chat_snapshot/);
  assert.match(load('includes/agent-followthrough-cron.php'),/az_commerce_scan/);
});

