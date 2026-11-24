import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {safeAgentHref} from '../assets/agent-workspace.js';

test('Commerce links now work inside existing bell / Brain / History',()=>{
  for(const href of ['commerce.php','commerce.php?tab=agent','commerce.php?tab=providers','commerce.php?tab=payouts','agent-chat.php?topic=commerce'])
    assert.equal(safeAgentHref(href),href);
  for(const href of ['https://external.example/commerce.php','//external.example','commerce.php?tab=<script>',
    'commerce.php?tab=agent%0D%0ASet-Cookie:bad','javascript:alert(1)','../commerce.php'])
    assert.equal(safeAgentHref(href),null);
});
test('Agent Brain and Chat use a role-scoped report, not another notification system',()=>{
 const backend=readFileSync(new URL('../includes/agent-workspace.php',import.meta.url),'utf8');
 const chat=readFileSync(new URL('../includes/agent-chat.php',import.meta.url),'utf8');
 const cron=readFileSync(new URL('../includes/agent-followthrough-cron.php',import.meta.url),'utf8');
 const gateway=readFileSync(new URL('../agent-workspace-api.php',import.meta.url),'utf8');
 assert.match(backend,/commerceAgent.*az_commerce_agent_admin_report/);
 assert.match(backend,/'commerceAgent'=>\$commerceAgent/);
 assert.match(backend,/commerce_provider_followup/);
 assert.match(backend,/commerce_commission_followup/);
 assert.match(chat,/\$summary\['commerceAgent'\]=az_commerce_agent_chat_snapshot/);
 assert.match(cron,/\$result\['commerceAgent'\]=az_commerce_agent_scan\(\)/);
 assert.match(gateway,/if\(\$action==='checkCommerce'\)/);
 assert.match(gateway,/az_require_csrf\(\)/);
 assert.match(gateway,/if\(!az_has_role\(\$user,'admin'\)\)/);
});
test('Commerce Agent cannot initiate financial provider POSTs',()=>{
 const script=readFileSync(new URL('../assets/commerce-agent-briefing.js',import.meta.url),'utf8');
 const module=readFileSync(new URL('../includes/commerce-agent.php',import.meta.url),'utf8');
 assert.match(script,/allowedHref/);
 assert.ok(script.includes("request('commerce-api.php','agentBriefing')"));
 assert.ok(script.includes("request('agent-workspace-api.php','checkCommerce',{})"));
 assert.doesNotMatch(module,/az_provider_stripe_request\(|az_easypost_request\(|az_connect_send_transfer\(/);
 assert.doesNotMatch(script,/initiateRefund|buyReturnLabel|sendConnectTransfer/);
});

