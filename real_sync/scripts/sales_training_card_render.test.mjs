import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const page = readFileSync(new URL('../training-card.html', import.meta.url), 'utf8');

function extractFunction(name) {
  const start = page.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `${name} must exist`);
  const next = page.indexOf('\n        function ', start + 1);
  const scriptEnd = page.indexOf('\n    </script>', start);
  const end = next === -1 ? scriptEnd : Math.min(next, scriptEnd);
  assert.ok(end > start, `${name} must be terminated`);
  return page.slice(start, end).trim();
}

const sandbox = {};
vm.runInNewContext(`${extractFunction('normalizeCardType')}\n${extractFunction('escapeHtml')}\n${extractFunction('formatText')}\nthis.normalizeCardType=normalizeCardType;this.escapeHtml=escapeHtml;this.formatText=formatText;`, sandbox);
const { normalizeCardType, escapeHtml, formatText } = sandbox;

const executablePattern = /<(?:script|img|svg|iframe)\b/i;
const allowedTagPattern = /^<\/?(?:p|h2|h3|ul|ol|li|blockquote|table|thead|tbody|tr|th|td|br)>$|^<p class="text-(?:correct|wrong)">$|^<div class="table-wrap">$|^<\/div>$/;
function assertSafeMarkup(html) {
  assert.doesNotMatch(html, executablePattern);
  for (const tag of html.match(/<[^>]+>/g) || []) assert.match(tag, allowedTagPattern);
}

test('executes page formatter and escapes hostile HTML, attributes, URLs and style', () => {
  const attacks = [
    '<script>alert(1)</script>', '<img src=x onerror=alert(1)>',
    '<svg onload=alert(1)>', '<iframe srcdoc="<script>x</script>">',
    '" onmouseover="alert(1)', "' style='background:url(javascript:x)'"
  ];
  for (const attack of attacks) {
    const html = formatText(attack);
    assertSafeMarkup(html);
    assert.ok(html.includes('&lt;') || html.includes('&quot;') || html.includes('&#39;'));
  }
  assert.equal(escapeHtml('<>&"\''), '&lt;&gt;&amp;&quot;&#39;');
});

test('renders paragraphs, headings, lists, quote and correctness hints with fixed markup', () => {
  const html = formatText('## 二级\n### 三级\n\n第一行\n第二行\n- 甲\n* 乙\n1. 一\n2) 二\n> 引用\n✓ 正确\n✗ 错误');
  assert.match(html, /<h2>二级<\/h2><h3>三级<\/h3>/);
  assert.match(html, /<p>第一行<br>第二行<\/p>/);
  assert.match(html, /<ul><li>甲<\/li><li>乙<\/li><\/ul>/);
  assert.match(html, /<ol><li>一<\/li><li>二<\/li><\/ol>/);
  assert.match(html, /<blockquote>引用<\/blockquote>/);
  assert.match(html, /class="text-correct"/);
  assert.match(html, /class="text-wrong"/);
});

test('renders only complete consistent pipe tables and falls back for malformed tables', () => {
  const valid = formatText('| 项目 | 分数 |\n| --- | ---: |\n| 表达 | 20 |\n| 安全 | <script>x</script> |');
  assert.match(valid, /<table><thead><tr><th>项目<\/th><th>分数<\/th>/);
  assert.match(valid, /<tbody><tr><td>表达<\/td><td>20<\/td><\/tr>/);
  assert.doesNotMatch(valid, executablePattern);

  for (const malformed of [
    '| A | B |\n| -- | --- |\n| 1 | 2 |',
    '| A | B |\n| --- | --- |\n| only-one |'
  ]) {
    const html = formatText(malformed);
    assert.doesNotMatch(html, /<table>/);
    assert.match(html, /\|/);
  }
});

test('option rendering stores only a numeric index and resolves original value in memory', () => {
  assert.doesNotMatch(page, /data-value=/);
  assert.match(page, /data-index="\$\{i\}" onclick="selectOption\(this, Number\(this\.dataset\.index\)\)"/);
  assert.match(page, /selectedAnswer = cardData\.options\[index\]/);
  assert.match(page, /Number\.isInteger\(index\)/);
});

test('card type is restricted before entering text, class names or behavior branches', () => {
  for (const type of ['K', 'S', 'D', 'C']) assert.equal(normalizeCardType(type), type);
  assert.equal(normalizeCardType('X\"><img src=x onerror=alert(1)>'), 'K');
  const renderCard = extractFunction('renderCard');
  assert.match(renderCard, /const cardType = normalizeCardType\(card\.card_type\)/);
  assert.doesNotMatch(renderCard, /\$\{card\.card_type\}/);
  assert.doesNotMatch(renderCard, /typeNames\[card\.card_type\]/);
  const handleAction = extractFunction('handleAction');
  assert.match(handleAction, /normalizeCardType\(cardData\.card_type\)/);
});

test('showResult applies the formatter to all server text and escapes score', () => {
  const showResult = extractFunction('showResult');
  for (const field of ['feedback', 'standard_answer', 'tips']) {
    assert.match(showResult, new RegExp(`formatText\\(result\\.${field} \\|\\| ''\\)`));
    assert.doesNotMatch(showResult, new RegExp(`\\$\\{result\\.${field}\\}`));
  }
  assert.match(showResult, /escapeHtml\(result\.score/);
  assert.doesNotMatch(showResult, /style=/i);
});
