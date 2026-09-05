import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = relativePath => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const styles = read('../assets/internal-ops.css');
const pages = {
  knowledge: read('../knowledge/detail.html'),
  submission: read('../lesson-submission.html'),
  review: read('../lesson-review.html'),
  dashboard: read('../admin/dashboard.html'),
  staffs: read('../admin/staffs.html'),
};

test('复杂页面提供稳定作用域并继续加载共享认证壳', () => {
  for (const [name, bodyClass] of [
    ['knowledge', 'internal-knowledge-detail'],
    ['submission', 'internal-submission'],
    ['review', 'internal-review'],
    ['dashboard', 'internal-dashboard'],
    ['staffs', 'internal-staffs'],
  ]) {
    assert.match(pages[name], new RegExp(`<body class="[^"]*${bodyClass}`));
    assert.match(pages[name], /src="\/internal-auth\.js\?v=/);
    assert.match(styles, new RegExp(`\\.${bodyClass}`));
  }
});

test('详情、表单和审核页面保留业务节点并使用专属表面', () => {
  for (const id of ['content', 'relatedSection', 'relatedList']) {
    assert.match(pages.knowledge, new RegExp(`id="${id}"`));
  }
  for (const id of ['setup', 'workspace', 'sourceFile', 'phaseList', 'findings', 'suggestions', 'versionDiff']) {
    assert.match(pages.submission, new RegExp(`id="${id}"`));
  }
  for (const id of ['taskList', 'statusFilter', 'stageFilter', 'lessonContent', 'evidence', 'history', 'comments', 'approveButton', 'returnButton']) {
    assert.match(pages.review, new RegExp(`id="${id}"`));
  }
  assert.match(styles, /\.internal-knowledge-detail \.article/);
  assert.match(styles, /\.internal-submission :is\(\.setup, \.panel\)/);
  assert.match(styles, /\.internal-review \.decision-box/);
});

test('管理页面统一表格、筛选和模态抽屉且保留权限入口', () => {
  for (const id of ['summaryCards', 'trendChart', 'storeRanking', 'staffRanking', 'tableWrap']) {
    assert.match(pages.dashboard, new RegExp(`id="${id}"`));
  }
  for (const marker of ['class="toolbar"', 'class="table-wrap"', 'class="drawer-layer"', 'class="create-drawer', 'class="create-drawer detail-drawer"']) {
    assert.match(pages.staffs, new RegExp(marker));
  }
  assert.match(pages.dashboard, /window\.requirePageAuth\(/);
  assert.match(pages.staffs, /window\.requirePageAuth\(/);
  assert.match(styles, /\.internal-dashboard \.table-scroll/);
  assert.match(styles, /\.internal-staffs \.table-wrap/);
  assert.match(styles, /\.internal-staffs \.drawer-layer[\s\S]*z-index: 99990/);
});

test('复杂页面在窄屏保持单列内容和可滚动表格', () => {
  assert.match(styles, /@media \(max-width: 980px\)[\s\S]*\.internal-review \.layout[\s\S]*grid-template-columns: 1fr !important/);
  assert.match(styles, /@media \(max-width: 600px\)[\s\S]*\.internal-submission \.meta-grid[\s\S]*grid-template-columns: 1fr !important/);
  assert.match(styles, /\.internal-dashboard \.table-scroll,[\s\S]*overflow-x: auto/);
});
