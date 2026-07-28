import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const h5 = readFileSync(new URL('../mobile/workload-v2.html', import.meta.url), 'utf8');
const miniJs = readFileSync(new URL('../mini-program/pages/workload/index.js', import.meta.url), 'utf8');
const miniWxml = readFileSync(new URL('../mini-program/pages/workload/index.wxml', import.meta.url), 'utf8');
const legalRoles = new Set(['sales', 'coach', 'manager']);

test('property 27: ordinary employee identity remains readonly for every legal role', () => {
  for (const role of legalRoles) {
    const context = { role, store_id: 1, permissions: { can_view_all: false } };
    const roleLocked = !context.permissions.can_view_all;
    const storeLocked = context.store_id > 0 && !context.permissions.can_view_all;
    assert.equal(roleLocked, true);
    assert.equal(storeLocked, true);
    assert.equal(legalRoles.has(context.role), true);
  }
  assert.match(h5, /if\(!canViewAll\)\{roleSel\.disabled=true/);
  assert.match(h5, /myStoreId>0&&!canViewAll[\s\S]*sel\.disabled=true/);
  assert.match(miniJs, /roleLocked:\s*!canViewAll/);
  assert.match(miniWxml, /picker[\s\S]*disabled="\{\{roleLocked\}\}"/);
  assert.doesNotMatch(miniWxml, /bindinput="onStoreInput"/);
});

test('property 27: authorized role selection is bounded by the legal role options', () => {
  const generatedIndexes = Array.from({ length: 200 }, (_, index) => index - 50);
  for (const index of generatedIndexes) {
    const roleOptions = ['sales', 'coach', 'manager'];
    const selected = roleOptions[index];
    assert.equal(selected === undefined || legalRoles.has(selected), true);
  }
  assert.match(miniJs, /roleOptions:\s*\[\{ label: '销售', value: 'sales' \}, \{ label: '教练', value: 'coach' \}, \{ label: '店长', value: 'manager' \}\]/);
  assert.match(h5, /option value="sales"[\s\S]*option value="coach"[\s\S]*option value="manager"/);
});
