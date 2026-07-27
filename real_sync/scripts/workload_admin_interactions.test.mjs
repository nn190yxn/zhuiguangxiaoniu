import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/workload.html', import.meta.url), 'utf8');

test('loading, empty, error, timeout, retry, and stale-response states remain explicit', () => {
  for (const testId of ['workload-loading', 'workload-empty', 'workload-error']) {
    assert.match(page, new RegExp(`data-testid="${testId}"`));
  }
  assert.match(page, /setAsyncState\('loading'\)[\s\S]*Promise\.all/);
  assert.match(page, /version=\+\+requestVersion[\s\S]*version!==requestVersion/);
  assert.match(page, /Number\(error\.code\)===408[\s\S]*'timeout'/);
  assert.match(page, /trace_id\|\|error\.data\.data\.request_id/);
  assert.match(page, /id="retryBtn"[\s\S]*addEventListener\('click',loadData\)/);
});

test('date and matrix validation focus the first invalid control', () => {
  assert.match(page, /if\(!from\|\|!to\)[\s\S]*\.focus\(\);return false/);
  assert.match(page, /if\(from>to\)[\s\S]*byId\('dateFrom'\)\.focus\(\)/);
  assert.match(page, /if\(days>365\)[\s\S]*日期范围最多 366 天/);
  assert.match(page, /primaryDimension'\)\.value===byId\('secondaryDimension'\)\.value[\s\S]*secondaryDimension'\)\.focus\(\)/);
});

test('tabs and drilldown rows support native keyboard activation', () => {
  assert.match(page, /role="tablist"/);
  assert.match(page, /\['ArrowLeft','ArrowRight','Home','End'\]/);
  assert.match(page, /tab\.setAttribute\('aria-selected',String\(selected\)\)/);
  assert.match(page, /tabindex="0" data-action/);
  assert.match(page, /event\.key==='Enter'\|\|event\.key===' '/);
});

test('drawer and preview implement focus entry, trapping, Escape, and restoration', () => {
  assert.match(page, /role="dialog" aria-modal="true" aria-labelledby="drawerTitle" tabindex="-1"/);
  assert.match(page, /role="dialog" aria-modal="true" aria-labelledby="previewTitle" tabindex="-1"/);
  assert.match(page, /requestAnimationFrame\(function\(\)\{byId\('closeDrawerBtn'\)\.focus\(\)\}\)/);
  assert.match(page, /requestAnimationFrame\(function\(\)\{byId\('closePreviewBtn'\)\.focus\(\)\}\)/);
  assert.match(page, /function trapFocus\(event,layer\)[\s\S]*event\.shiftKey[\s\S]*last\.focus\(\)[\s\S]*first\.focus\(\)/);
  assert.match(page, /event\.key==='Escape'[\s\S]*closePreview\(\)[\s\S]*closeDrawer\(\)/);
  assert.match(page, /drawerReturnFocus&&drawerReturnFocus\.focus[\s\S]*drawerReturnFocus\.focus\(\)/);
  assert.match(page, /previewReturnFocus&&previewReturnFocus\.focus[\s\S]*previewReturnFocus\.focus\(\)/);
});

test('evidence previews are labelled and trend canvas has same-data text and table alternatives', () => {
  assert.match(page, /data-evidence-url/);
  assert.match(page, /aria-label="预览第 /);
  assert.match(page, /alt="工作量凭证大图"/);
  assert.match(page, /id="trendText">'\+trendText\(rows\)/);
  assert.match(page, /trendTable\(rows\)/);
  assert.match(page, /role="img" aria-label="工作量完成率趋势图"/);
  assert.match(page, /function trendSection\(rows\)\{currentTrendRows=rows/);
  assert.match(page, /function drawVisibleTrend\(\)[\s\S]*var rows=currentTrendRows/);
});

test('focus visibility, touch sizes, and reduced-motion preferences are preserved', () => {
  assert.match(page, /button:focus-visible[\s\S]*outline:3px solid/);
  assert.match(page, /\.quick-btn,\.view-tab,\.btn,\.icon-btn\{[^}]*min-height:44px/);
  assert.match(page, /\.field input,\.field select\{[^}]*min-height:44px/);
  assert.match(page, /@media\(prefers-reduced-motion:reduce\)[\s\S]*animation-duration:\.01ms!important/);
});
