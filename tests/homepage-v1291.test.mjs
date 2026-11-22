import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { spawnSync } from 'node:child_process';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const file = name => readFileSync(join(root, name), 'utf8');
const page = file('index.php');
const styles = file('assets/landing.css');

test('The public homepage uses the approved text-free white studio photo and responsive source', () => {
  assert.match(page, /hero-reality-white\.webp/);
  assert.match(page, /hero-reality-white-mobile\.webp/);
  assert.match(page, /<picture>[\s\S]*?<source[^>]+media="\(max-width: 640px\)"[\s\S]*?<img/);
  assert.match(page, /alt="Five smiling adult models/);
  assert.match(page, /fetchpriority="high"/);
  for (const name of ['hero-reality-white.webp', 'hero-reality-white-mobile.webp']) {
    const size = statSync(join(root, 'assets', name)).size;
    assert.ok(size > 20000 && size < 450000, `${name} expected responsive WebP, got ${size}`);
  }
});

test('Homepage preserves all real audience, membership and casting routes with install fallbacks', () => {
  for (const route of ['watch.php', 'live-shows.php', 'dashboard.php', 'signup.php', 'calendar.php', 'classes.php', 'appointments.php', 'apply.php', 'install.php']) {
    assert.ok(page.includes(`'${route}'`), route);
  }
  assert.match(page, /\$member \? 'dashboard\.php' : 'signup\.php'/);
  assert.match(page, /\$installed \? 'watch\.php' : 'install\.php'/);
  assert.match(page, /az_render_universal_header\('index', \$member, \$installed\)/);
  assert.match(page, /<nav aria-label="Footer navigation">/);
  assert.doesNotMatch(page, /href="admin(?:-studio)?\.php"/);
});

test('Homepage supplies a single semantic H1, real named sections and accessible navigation', () => {
  assert.equal((page.match(/<h1\b/g) ?? []).length, 1);
  for (const id of ['main-content', 'the-show', 'the-world', 'casting', 'home-title', 'show-title', 'world-title', 'casting-title']) {
    assert.ok(page.includes(`id="${id}"`), id);
  }
  assert.match(page, /class="azh-home-skip" href="#main-content"/);
  assert.match(page, /<figcaption class="azh-home-hero-caption"/);
  assert.match(page, /membership does not guarantee|Membership does not guarantee/);
  assert.doesNotMatch(page, /<header id="azh-global-header"/);
});

test('Homepage light theme is responsive and respects reduced motion', () => {
  assert.match(styles, /--azh-paper:\s*#fffaf9/);
  assert.match(styles, /@media \(max-width: 820px\)/);
  assert.match(styles, /@media \(max-width: 640px\)/);
  assert.match(styles, /@media \(max-width: 360px\)/);
  assert.match(styles, /prefers-reduced-motion:\s*reduce/);
  assert.match(styles, /\.azh-home-hero-media img\s*\{[^}]*width:\s*100%/);
  assert.doesNotMatch(styles, /background:\s*#000\b/);
});

test('Homepage PHP syntax remains valid', () => {
  const result = spawnSync('php', ['-l', join(root, 'index.php')], {encoding:'utf8'});
  assert.equal(result.status, 0, result.stderr || result.stdout);
});
