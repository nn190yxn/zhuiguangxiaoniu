import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/workload.html', import.meta.url), 'utf8');

test('loading, empty, error, timeout, retry, and stale responses remain explicit', () => {
  for (const testId of ['workload-loading', 'workload-empty', 'workload-error']) {
    assert.match(page, new RegExp(`data-testid="${testId}"`));
  }
  assert.match(page, /setAsyncState\("loading"\)[\s\S]*Promise\.all/);
  assert.match(page, /version = \+\+requestVersion[\s\S]*version !== requestVersion/);
  assert.match(page, /Number\(error\.code\) === 408[\s\S]*"timeout"/);
  assert.match(page, /trace_id[\s\S]*request_id/);
  assert.match(page, /retryBtn[\s\S]*addEventListener\("click", loadData\)/);
});

test('date and matrix validation focus the first invalid control', () => {
  assert.match(page, /if \(!from \|\| !to\)[\s\S]*\.focus\(\)[\s\S]*return false/);
  assert.match(page, /if \(from > to\)[\s\S]*dateFrom[\s\S]*\.focus\(\)/);
  assert.match(page, /days > 365[\s\S]*日期范围最多 366 天/);
  assert.match(page, /primaryDimension[\s\S]*secondaryDimension[\s\S]*\.focus\(\)/);
});

test('workspace and analytics tabs support roving keyboard activation', () => {
  assert.match(page, /function bindTabs\(selector, activate\)/);
  for (const key of ['ArrowLeft', 'ArrowRight', 'Home', 'End']) assert.match(page, new RegExp(key));
  assert.match(page, /aria-selected/);
  assert.match(page, /tabIndex = selected \? 0 : -1/);
  assert.match(page, /bindTabs\("\[data-workspace\]"/);
  assert.match(page, /bindTabs\("\[data-view\]"/);
});

test('audit and alert actions enforce comments and idempotent mutations', () => {
  assert.match(page, /\["rejected", "needs_resubmit"\]\.includes\(status\)[\s\S]*必须填写处理意见/);
  assert.match(page, /actionComment[\s\S]*maxlength="500"/);
  assert.match(page, /alert-resolve/);
  assert.match(page, /audit-action\.php/);
  assert.match(page, /function idempotencyKey\(prefix\)/);
});

test('standard lifecycle and import preflight provide guarded write actions', () => {
  for (const action of ['standard-create', 'standard-copy', 'standard-publish', 'standard-disable', 'standard-delete', 'import-confirm']) {
    assert.match(page, new RegExp(action));
  }
  assert.match(page, /window\.confirm\("确认删除这个未引用草稿？"\)/);
  assert.match(page, /id="standardItemForm"/);
  assert.match(page, /function standardItemPayload\(versionId\)/);
  assert.match(page, /standard-items\.php/);
  assert.match(page, /data-standard-item-edit/);
  assert.match(page, /data-standard-item-remove/);
  assert.match(page, /accept="\.csv,\.xlsx"/);
  assert.match(page, /new FormData\(\)/);
});

test('drawer and evidence preview implement focus entry, trapping, Escape, and restoration', () => {
  assert.match(page, /role="dialog"[\s\S]*aria-modal="true"[\s\S]*aria-labelledby="drawerTitle"/);
  assert.match(page, /role="dialog"[\s\S]*aria-modal="true"[\s\S]*aria-labelledby="previewTitle"/);
  assert.match(page, /closeDrawerBtn[\s\S]*\.focus\(\)/);
  assert.match(page, /closePreviewBtn[\s\S]*\.focus\(\)/);
  assert.match(page, /function trapFocus\(event, layer\)[\s\S]*event\.shiftKey[\s\S]*last\.focus\(\)[\s\S]*first\.focus\(\)/);
  assert.match(page, /event\.key === "Escape"[\s\S]*closePreview\(\)[\s\S]*closeDrawer\(\)/);
  assert.match(page, /data-evidence-url/);
  assert.match(page, /alt="工作量凭证大图"/);
});

test('trend canvas has same-data text and table alternatives', () => {
  assert.match(page, /id="trendText"/);
  assert.match(page, /trendTable\(rows\)/);
  assert.match(page, /role="img" aria-label="工作量完成率趋势图"/);
  assert.match(page, /function trendSection\(rows\)[\s\S]*currentTrendRows = rows/);
  assert.match(page, /function drawVisibleTrend\(\)[\s\S]*currentTrendRows/);
});

test('focus visibility, touch sizes, responsive layout, and reduced motion are preserved', () => {
  assert.match(page, /button:focus-visible[\s\S]*outline: 3px solid/);
  assert.match(page, /min-height: 44px/);
  assert.match(page, /@media \(max-width: 760px\)/);
  assert.match(page, /@media \(prefers-reduced-motion: reduce\)[\s\S]*animation-duration: 0\.01ms !important/);
});
