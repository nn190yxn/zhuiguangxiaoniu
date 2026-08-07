import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const endpoint = readFileSync(new URL('../api/workload/staff-search.php', import.meta.url), 'utf8');

test('staff name search validates requests and returns only authorized active staff', () => {
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /appCanAccessWorkload/);
  assert.match(endpoint, /appCanViewAll/);
  assert.match(endpoint, /s\.status = 1/);
  assert.match(endpoint, /s\.name LIKE \?/);
  assert.match(endpoint, /LIMIT 20/);
  assert.match(endpoint, /staff_id/);
  assert.match(endpoint, /store_name/);
});
