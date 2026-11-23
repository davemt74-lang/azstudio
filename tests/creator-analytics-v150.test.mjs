import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../assets/commerce-video-engagement.js',import.meta.url),'utf8');
const php=readFileSync(new URL('../includes/creator-analytics.php',import.meta.url),'utf8');
const page=readFileSync(new URL('../creator-analytics.php',import.meta.url),'utf8');
const watch=readFileSync(new URL('../includes/watch-views.php',import.meta.url),'utf8');
const nav=readFileSync(new URL('../includes/member-layout.php',import.meta.url),'utf8');
const id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
function app(fetchResult={ok:true}) {
  const handlers={};const requests=[];
  const section={dataset:{csrf:'real-token',videoId:id},addEventListener(name,handler){handlers[name]=handler;}};
  const sandbox={document:{baseURI:'https://azhottie.com/watch.php?id=x',querySelector(selector){assert.match(selector,/azh-commerce-watch/);return section;}},
    fetch:(url,options)=>{requests.push({url:String(url),options});return Promise.resolve(fetchResult);},URL,Promise};
  vm.runInNewContext(source,sandbox);
  const card={dataset:{productId:'d'.repeat(32),promotionId:'e'.repeat(32)}};
  const click=()=>handlers.click({target:{closest(selector){return selector.includes('[data-video-product]')?card:{tagName:'BUTTON'};}}});
  const submit=()=>handlers.submit({target:{matches(){return true;},closest(){return card;}}});
  return {requests,handlers,click,submit};
}
test('Watch captures approved CTA clicks with CSRF, no viewer data, deduped',async()=>{
  const {click,submit,requests}=app();
  click();submit();click();
  await Promise.resolve();await Promise.resolve();
  assert.equal(requests.length,1);
  assert.equal(requests[0].options.keepalive,true);
  assert.equal(requests[0].options.headers['X-CSRF-Token'],'real-token');
  const body=JSON.parse(requests[0].options.body);
  assert.equal(body.event,'click');assert.equal(body.videoId,id);
  assert.deepEqual(Object.keys(body).sort(),['event','productId','promotionId','videoId']);
});
test('Watch does not capture unrelated clicks',()=>{
  const {handlers,requests}=app();
  handlers.click({target:{closest(){return null;}}});
  assert.equal(requests.length,0);
});
test('Source report enforces role scopes, verified cash separation, CSV neutralization, and no new automatic payments',()=>{
  assert.match(php,/az_creator_analytics_assert\(/);
  assert.match(php,/model_id|creator_id|scopeModelId/);
  assert.match(php,/provider='stripe'/);
  assert.match(php,/provider='cash'/);
  assert.match(php,/az_creator_analytics_csv_safe/);
  assert.doesNotMatch(php,/Stripe\\Transfer|create_transfer|az_.*purchase_label/);
  assert.match(watch,/data-video-product/);
  assert.match(watch,/commerce-video-engagement\.js/);
  assert.match(page,/Export CSV/);
  assert.match(page,/Current-state snapshot/);
  assert.match(page,/Publicly available videos only/);
  assert.match(nav,/Creator analytics/);
  assert.match(nav,/Video performance/);
});
