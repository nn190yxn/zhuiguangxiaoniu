import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../api/reminder/_common.php', import.meta.url), 'utf8');

test('workload reminders cover every obligated employee role', () => {
  assert.match(source, /'sales,coach,manager', '20:00'/);
  assert.match(source, /'sales,coach,manager', '23:00'/);
  assert.match(source, /role_code IN \('sales', 'coach', 'manager'\)/);
  assert.match(source, /\['sales', 'coach', 'manager'\]/);
});

test('manager aliases resolve into the reminder population', () => {
  assert.match(source, /\['manager', 'store_manager', 'shop_manager', '店长'\]/);
  assert.match(source, /s\.role IN \('sales', 'coach', 'manager'.*'店长'/);
});
