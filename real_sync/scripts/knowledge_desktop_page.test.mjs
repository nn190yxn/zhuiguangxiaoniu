import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('desktop knowledge center provides working professional and sales list routes', () => {
  const html = read('knowledge/index.html');
  const script = read('knowledge/knowledge.js');
  assert.match(html, /data-primary-category="professional"/);
  assert.match(html, /data-primary-category="sales"/);
  assert.match(html, /id="topicList"/);
  assert.match(html, /data-mode="favorite"/);
  assert.match(html, /data-mode="recent"/);
  assert.match(script, /\/api\/knowledge\/list\.php/);
  assert.match(script, /primary_category/);
  assert.match(script, /content_type/);
  assert.match(script, /favorite/);
  assert.match(script, /recent/);
  assert.match(script, /\/knowledge\/detail\.html\?id=/);
});

test('desktop preview fallback exposes published static entries only', () => {
  const script = read('knowledge/knowledge.js');
  assert.match(script, /content-index\.json/);
  assert.match(script, /item\.publication_status !== 'published'/);
  assert.match(script, /item\.primary_category !== state\.primaryCategory/);
  assert.match(script, /safeInternalPath\(item\.canonical_url\)/);
});

test('desktop detail uses published knowledge API and safe text rendering', () => {
  const html = read('knowledge/detail.html');
  const script = read('knowledge/detail.js');
  assert.match(html, /\/knowledge\/detail\.js/);
  assert.match(script, /\/api\/knowledge\/detail\.php/);
  assert.match(script, /\/api\/knowledge\/favorite\.php/);
  assert.match(script, /<div class="body">\$\{escapeHtml\(item\.content \|\| '暂无详细内容'\)\}<\/div>/);
  assert.match(script, /\/knowledge\/detail\.html\?id=/);
});
