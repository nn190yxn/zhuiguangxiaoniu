import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(new URL('../admin/staffs.html', import.meta.url), 'utf8');

test('interactive controls have visible focus, touch targets, and reduced-motion support', () => {
  assert.match(page, /button:focus-visible,[\s\S]*a:focus-visible[\s\S]*outline: 3px solid/);
  assert.match(page, /\.field input, \.field select, \.field textarea \{[^}]*min-height: 44px/);
  assert.match(page, /\.btn \{[^}]*min-height: 44px/);
  assert.match(page, /\.icon-button \{ width: 44px; height: 44px/);
  assert.match(page, /@media \(prefers-reduced-motion: reduce\)[\s\S]*animation-duration: 0\.01ms/);
});

test('every modal drawer exposes dialog semantics and a programmatic focus target', () => {
  for (const title of ['detailDrawerTitle', 'createDrawerTitle', 'settingDrawerTitle']) {
    assert.match(page, new RegExp(`role="dialog" aria-modal="true" aria-labelledby="${title}"[^>]*tabindex="-1"`));
  }
  for (const label of ['关闭员工详情抽屉', '关闭新增员工抽屉', '关闭组织设置抽屉']) {
    assert.match(page, new RegExp(`aria-label="${label}"`));
  }
});

test('drawers move focus inside on open and restore their triggering controls on close', () => {
  assert.match(page, /function focusDrawer\(layer, preferredSelector\)[\s\S]*requestAnimationFrame[\s\S]*preferred \|\| drawerFocusableElements\(layer\)\[0\]/);
  assert.match(page, /openDetailDrawer\(\)[\s\S]*focusDrawer\(drawer, '#closeDetailDrawerBtn'\)/);
  assert.match(page, /openCreateDrawer\(\)[\s\S]*focusDrawer\(document\.getElementById\('createDrawer'\), '#createName'\)/);
  assert.match(page, /openSettingDrawer\(type, id = 0\)[\s\S]*focusDrawer\(document\.getElementById\('settingDrawer'\), '#settingCode'\)/);
  for (const returnFocus of ['detailDrawerReturnFocus', 'createDrawerReturnFocus', 'settingDrawerReturnFocus']) {
    assert.match(page, new RegExp(`if \\(${returnFocus}\\?\\.focus\\) ${returnFocus}\\.focus\\(\\)`));
  }
});

test('Tab focus stays in the active modal and Escape closes the top visible drawer', () => {
  assert.match(page, /function drawerFocusableElements\(layer\)[\s\S]*button:not\(\[disabled\]\)[\s\S]*!element\.closest\('\[hidden\]'\)/);
  assert.match(page, /function trapDrawerFocus\(event\)[\s\S]*event\.key !== 'Tab'[\s\S]*event\.shiftKey[\s\S]*last\.focus\(\)[\s\S]*first\.focus\(\)/);
  assert.match(page, /document\.addEventListener\('keydown',[\s\S]*trapDrawerFocus\(event\)[\s\S]*event\.key !== 'Escape'[\s\S]*settingDrawer[\s\S]*createDrawer[\s\S]*detailDrawer/);
});

test('native keyboard controls and selected-state semantics cover every workspace mode', () => {
  assert.match(page, /<button class="workspace-tab current"[^>]*aria-current="page"/);
  assert.match(page, /setWorkspace\(name\)[\s\S]*setAttribute\('aria-current', 'page'\)/);
  assert.match(page, /data-organization-mode="tree" aria-pressed="true"/);
  assert.match(page, /data-organization-mode="list" aria-pressed="false"/);
  assert.match(page, /item\.setAttribute\('aria-pressed', String\(current\)\)/);
  assert.match(page, /data-staff="\$\{escapeHtml\(item\.id\)\}"/);
});

test('responsive layouts switch shared records to cards at narrow widths', () => {
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*\.table-wrap \{ display: none; \}[\s\S]*\.employee-cards \{ display: grid; \}/);
  assert.match(page, /@media \(max-width: 760px\)[\s\S]*\.settings-cards \{ display: grid; \}[\s\S]*\.import-result-cards \{ display: grid; \}/);
  assert.match(page, /@media \(max-width: 520px\)[\s\S]*\.employee-card-grid \{ grid-template-columns: 1fr; \}/);
  assert.match(page, /tableBody\.innerHTML = list\.map/);
  assert.match(page, /cards\.innerHTML = list\.map/);
});

test('loading, empty, error, ready, and live status semantics remain testable', () => {
  for (const state of ['idle', 'loading', 'ready', 'empty', 'error']) {
    assert.match(page, new RegExp(`dataset\\.state = (?:list\\.length \\? 'ready' : 'empty'|'${state}')`));
  }
  for (const testId of ['staff-loading', 'staff-empty', 'staff-error', 'staff-card-error', 'detail-loading', 'detail-error', 'organization-error', 'import-empty', 'health-error']) {
    assert.match(page, new RegExp(`data-testid="${testId}"`));
  }
  assert.match(page, /role="status" aria-live="polite"/);
  assert.match(page, /id="appliedFilters" aria-live="polite"/);
});
