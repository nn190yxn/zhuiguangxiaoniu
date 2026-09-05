import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const html = await readFile(new URL('design-preview.html', root), 'utf8');
const css = await readFile(new URL('assets/intranet-design-preview.css', root), 'utf8');
const js = await readFile(new URL('js/intranet-design-preview.js', root), 'utf8');

test('design preview exposes original directions and the requested hybrid', () => {
  for (const key of ['a', 'b', 'c', 'd']) {
    assert.match(html, new RegExp(`data-preview-target="${key}"`));
    assert.match(html, new RegExp(`data-preview="${key}"`));
    assert.match(css, new RegExp(`\\.mockup-${key}`));
  }
  assert.match(html, /曜石电蓝/);
  assert.match(html, /海军蓝翡翠/);
  assert.match(html, /石墨信号橙/);
  assert.match(html, /控制台融合/);
  assert.match(js, /石墨信号橙 · 企业控制台/);
  assert.match(css, /enterprise console structure with graphite and signal orange/);
});

test('preview interaction supports switching, keyboard navigation, and selection', () => {
  assert.match(js, /function activate/);
  assert.match(js, /ArrowLeft/);
  assert.match(js, /ArrowRight/);
  assert.match(js, /intranet-design-choice/);
  assert.match(js, /aria-pressed/);
});

test('preview remains responsive on tablet and mobile widths', () => {
  assert.match(html, /name="viewport"/);
  assert.match(css, /@media\(max-width:1000px\)/);
  assert.match(css, /@media\(max-width:720px\)/);
});
