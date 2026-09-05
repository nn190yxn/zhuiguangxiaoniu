import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const internalHtml = readFileSync(new URL('../internal.html', import.meta.url), 'utf8');
const authScript = readFileSync(new URL('../internal-auth.js', import.meta.url), 'utf8');

test('员工端固定六个一级入口和规范路径', () => {
  for (const label of ['制度中心', '知识中心', '演练中心', '学习中心', '业务工具', '我的']) {
    assert.match(internalHtml, new RegExp(`>${label}<`));
    assert.match(authScript, new RegExp(`label: '${label}'`));
  }
  for (const path of ['/制度标准/', '/knowledge/', '/mobile/drill.html', '/learning/', '/internal.html#tools', '/mobile/mine.html']) {
    assert.match(authScript, new RegExp(`href: '${path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`));
  }
});

test('知识和学习旧入口保留查询参数并跳转规范入口', () => {
  const knowledgeCompat = readFileSync(new URL('../知识库/index.html', import.meta.url), 'utf8');
  const learningCompat = readFileSync(new URL('../新员工学习/index.html', import.meta.url), 'utf8');
  const knowledgeFileCompat = readFileSync(new URL('../knowledge.html', import.meta.url), 'utf8');
  const learningFileCompat = readFileSync(new URL('../learning.html', import.meta.url), 'utf8');
  assert.match(knowledgeCompat, /window\.location\.replace\('\/knowledge\/'.*window\.location\.search/);
  assert.match(learningCompat, /window\.location\.replace\('\/learning\/'.*window\.location\.search/);
  assert.match(knowledgeFileCompat, /window\.location\.replace\('\/knowledge\/'.*window\.location\.search/);
  assert.match(learningFileCompat, /var target = '\/learning\/'/);
  assert.match(learningFileCompat, /var suffix = window\.location\.search/);
});
