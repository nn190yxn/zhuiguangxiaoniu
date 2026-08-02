import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const corePages = [
  'mobile/mine.html',
  'mobile/workload-v2.html',
  'mobile/drill.html',
  'mobile/learning.html',
];

test('PWA 核心页与登录链路允许 200% 缩放', () => {
  for (const page of [...corePages, 'mobile/login.html', 'mobile/workload.html']) {
    const source = read(page);
    assert.doesNotMatch(source, /maximum-scale\s*=\s*1(?:\.0)?/i, `${page} 限制了页面缩放`);
    assert.doesNotMatch(source, /user-scalable\s*=\s*no/i, `${page} 禁止了页面缩放`);
  }
});

test('四个核心业务页共享三档响应式应用壳与一级导航', () => {
  for (const page of corePages) {
    const source = read(page);
    assert.match(source, /href="\/css\/mobile-shell\.css"/, `${page} 缺少共享应用壳样式`);
    assert.match(source, /<body class="[^"]*mobile-shell/, `${page} 缺少应用壳页面标识`);
    assert.match(source, /<nav[^>]+mobile-shell-nav[^>]+aria-label="一级导航"/, `${page} 缺少一级导航`);
  }
});

test('共享应用壳覆盖完整断点、横屏低高度和缩放回流', () => {
  const shell = read('css/mobile-shell.css');
  assert.match(shell, /@media \(max-width: 767px\)[\s\S]*mobile-shell--learning[\s\S]*grid-template-columns:\s*1fr/);
  assert.match(shell, /@media \(min-width: 768px\) and \(max-width: 1023px\)[\s\S]*mobile-shell--learning/);
  assert.match(shell, /@media \(min-width: 1024px\)[\s\S]*mobile-shell--learning/);
  assert.match(shell, /@media \(orientation: landscape\) and \(max-height: 600px\)/);
  assert.match(shell, /overflow-wrap:\s*anywhere/);
  assert.match(shell, /max-width:\s*100%/);
});

test('核心导航和主要操作使用原生键盘交互元素', () => {
  for (const page of ['mobile/mine.html', 'mobile/learning.html']) {
    const source = read(page);
    assert.doesNotMatch(source, /<(?:div|span)\b[^>]*\bonclick=/i, `${page} 仍包含无法自然进入 Tab 顺序的点击元素`);
  }

  const learning = read('mobile/learning.html');
  assert.match(learning, /role="tablist"/);
  assert.match(learning, /role="tab"/);
  assert.match(learning, /aria-selected=/);
  assert.match(learning, /event\.key === 'ArrowRight'/);
  assert.match(learning, /event\.key !== 'ArrowLeft'/);
});

test('修改密码对话框提供焦点循环、Escape 关闭和焦点恢复', () => {
  const mine = read('mobile/mine.html');
  assert.match(mine, /id="pwdModal"[^>]+role="dialog"[^>]+aria-modal="true"[^>]+aria-labelledby="pwdModalTitle"[^>]+aria-hidden="true"/);
  assert.match(mine, /function pwdFocusables\(/);
  assert.match(mine, /pwdReturnFocus\s*=\s*document\.activeElement/);
  assert.match(mine, /document\.getElementById\('oldPwd'\)\.focus\(\)/);
  assert.match(mine, /document\.getElementById\('pwdModal'\)\.addEventListener\('keydown'/);
  assert.match(mine, /event\.key\s*===\s*'Escape'/);
  assert.match(mine, /pwdReturnFocus\.focus\(\)/);
});

test('演练 Sheet 提供模态语义和完整键盘关闭行为', () => {
  const drill = read('mobile/drill.html');
  assert.match(drill, /id="sheet"[^>]+role="dialog"[^>]+aria-modal="true"[^>]+aria-labelledby="sheetTitle"[^>]+aria-hidden="true"/);
  assert.match(drill, /function sheetFocusables\(/);
  assert.match(drill, /drill\.returnFocus\s*=\s*document\.activeElement/);
  assert.match(drill, /document\.getElementById\('sheet'\)\.addEventListener\('keydown'/);
  assert.match(drill, /event\.key\s*===\s*'Escape'/);
  assert.match(drill, /drill\.returnFocus\.focus\(\)/);
});

test('工作量凭证预览提供 44px 关闭目标和完整键盘关闭行为', () => {
  const workload = read('mobile/workload-v2.html');
  assert.match(workload, /id="previewMask"[^>]+role="dialog"[^>]+aria-modal="true"[^>]+aria-labelledby="previewTitle"[^>]+aria-hidden="true"/);
  assert.match(workload, /\.preview-close\{[^}]*min-width:44px;[^}]*min-height:44px/);
  assert.match(workload, /previewReturnFocus\s*=\s*document\.activeElement/);
  assert.match(workload, /document\.getElementById\('previewClose'\)\.focus\(\)/);
  assert.match(workload, /document\.getElementById\('previewMask'\)\.addEventListener\('keydown'/);
  assert.match(workload, /event\.key\s*===\s*'Escape'/);
  assert.match(workload, /previewReturnFocus\.focus\(\)/);
});
