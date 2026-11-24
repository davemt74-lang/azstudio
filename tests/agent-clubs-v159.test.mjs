import test from 'node:test';
import assert from 'node:assert/strict';
import {safeAgentHref} from '../assets/agent-workspace.js';

test('shared activity links open Club workspaces without allowing external redirects',()=>{
  for(const path of ['creator-clubs.php','creator-club.php?creator=12','creator-club-workspace.php','creator-club-admin.php'])assert.equal(safeAgentHref(path),path);
  for(const path of ['https://evil.invalid/creator-club.php','//evil.invalid','../creator-club.php','creator-club.php?next=https://evil.invalid','creator-club.php#javascript:alert(1)'])assert.equal(safeAgentHref(path),null);
});
