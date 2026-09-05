import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('[validates 7.5] 正式教案库页面使用列表与批准版本详情接口', () => {
  const html = read('lesson-library.html');
  const script = read('js/lesson-library.js');

  assert.doesNotMatch(html, /http-equiv="refresh"/i);
  assert.doesNotMatch(html, /href="(?:\.\/)?lessons\//);
  assert.match(html, /src="\/internal-auth\.js\?v=20260904-fixed-nav"/);
  assert.match(html, /src="\/js\/lesson-library\.js"/);
  for (const id of [
    'searchForm',
    'keywordInput',
    'courseLineFilter',
    'classLevelFilter',
    'lessonGrid',
    'listStatus',
    'pagination',
    'detailView',
    'lessonContent',
    'backToList',
  ]) {
    assert.match(html, new RegExp(`id="${id}"`));
  }

  assert.match(script, /\/api\/lesson-library\/list\.php/);
  assert.match(script, /\/api\/lesson-library\/detail\.php\?id=/);
  assert.match(script, /lesson\.canonical_route/);
  assert.match(script, /new URLSearchParams\(window\.location\.search\)\.get\('id'\)/);
  assert.match(script, /window\.requirePageAuth\(\{ onAuthed:/);
});

test('正式教案库页面提供加载、空结果、失败和分页状态', () => {
  const script = read('js/lesson-library.js');

  assert.match(script, /正在加载正式教案/);
  assert.match(script, /当前条件下暂无正式教案/);
  assert.match(script, /加载失败/);
  assert.match(script, /state\.page > 1/);
  assert.match(script, /state\.page \* state\.pageSize < state\.total/);
});

test('[validates 7.5] 学习中心教案入口指向规范正式教案库路由', () => {
  const learning = read('learning/index.html');

  assert.match(learning, /href="\/lesson-library\.html"[^>]*>[\s\S]*?<strong>正式教案库<\/strong>/);
  assert.doesNotMatch(learning, /href="\/lesson-submission\.html"[^>]*>[\s\S]*?<strong>教案工作台<\/strong>/);
});
