import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const root = new URL('../', import.meta.url);
const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

const markdownSource = source('../mini-program/utils/markdown.js');
const detailSource = source('../mini-program/pages/knowledge/detail.js');
const h5Source = source('../mobile/knowledge-detail.html');
const configSource = source('../api/config.php');


test('Markdown renderer escapes raw HTML and emits only controlled nodes', () => {
  const { renderMarkdown } = require(fileURLToPath(new URL('../mini-program/utils/markdown.js', import.meta.url)));
  const html = renderMarkdown([
    '# 标题',
    '',
    '<script>alert(1)</script> **重点**',
    '[危险](javascript:alert(1))',
    '![外链](data:text/html,alert(1))',
    '![未授权](https://evil.example/image.png)',
    '![受控](/uploads/knowledge/demo.png)',
    '| 项目 | 内容 |',
    '| --- | --- |',
    '| 训练 | 安全 |'
  ].join('\n'));

  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /<script|javascript:|data:text|evil\.example/i);
  assert.match(html, /markdown-missing-image/);
  assert.match(html, /src="\/uploads\/knowledge\/demo\.png"/);
  assert.match(html, /markdown-heading/);
  assert.match(html, /markdown-table-block/);
});

test('Mini Program detail never forwards database content as raw HTML', () => {
  assert.match(detailSource, /require\('\.\.\/\.\.\/utils\/markdown'\)/);
  assert.equal(detailSource.includes('${renderMarkdown(raw)}'), true);
  assert.equal(detailSource.includes('${raw}'), false);
});

test('Knowledge resource URL policy rejects external, non-HTTPS and traversal paths', () => {
  assert.match(configSource, /function getKnowledgeResourceUrl\(/);
  assert.match(configSource, /!== 'https'/);
  assert.match(configSource, /uploads\/knowledge/);
  assert.match(configSource, /rawurldecode\(\$resourcePath\)/);
  assert.match(configSource, /strpos\(\$decodedPath, '\.\.'\)/);
  assert.match(configSource, /preg_match\('\/\[\\x00-\\x1F/);
});

test('H5 resource URL policy is origin and knowledge-upload restricted', () => {
  assert.match(h5Source, /parsed\.protocol !== 'https:'/);
  assert.match(h5Source, /parsed\.origin !== location\.origin/);
  assert.match(h5Source, /startsWith\('\/uploads\/knowledge\/'\)/);
  assert.doesNotMatch(h5Source, /return value\.replace\(\/\['\"\]\/g/);
});

test('Knowledge PHP endpoints use the controlled resource resolver', () => {
  assert.match(source('../api/knowledge/detail.php'), /getKnowledgeResourceUrl\(/);
  assert.match(source('../api/knowledge/list.php'), /getKnowledgeResourceUrl/);
  assert.match(source('../api/drill/detail.php'), /getKnowledgeResourceUrl\(/);
});
