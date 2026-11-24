import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = path => readFileSync(new URL('../' + path, import.meta.url), 'utf8');

test('shared sidebar removes redundant intro for all roles and routes', () => {
  const php=read('includes/member-layout.php');
  assert.doesNotMatch(php,/azh-side-intro|YOUR AZHOTTIE|My space|Private member area|Production administration/);
  assert.match(php,/function az_render_member_nav_links/);
  assert.match(php,/<div class="azh-side-navigation">\s*<nav aria-label=/);
  assert.match(php,/\$adminPage \? 'Admin sections' : 'Member sections'/);
  assert.match(php,/ADMIN WORKSPACE/);
  assert.match(php,/MEMBERSHIP/);
  assert.match(php,/User Submissions/);
  assert.match(php,/Sign out/);
});

test('public pages and admin workspaces share updated sidebar navigation', () => {
  const header=read('includes/universal-header.php');
  assert.match(header,/az_render_member_nav_links\(\$current, \$user\)/);
  const memberShell=read('includes/member-layout.php');
  assert.match(memberShell,/az_render_member_nav_links\(\$page, \$user\)/);
});

test('styles have no unused introduction selectors and put first nav label near top', () => {
  const css=read('assets/member-layout.css');
  const header=read('assets/universal-header.css');
  assert.doesNotMatch(css+header,/azh-side-intro/);
  assert.match(css,/nav > \.azh-side-label:first-child\{margin-top:4px\}/);
  assert.match(header,/\.azh-floating-sidebar \.azh-side-label/);
  assert.match(header,/\.azh-floating-sidebar nav > \.azh-side-label:first-child/);
});

