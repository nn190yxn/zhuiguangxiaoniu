import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const runtimeSource = readFileSync(new URL('../api/reminder/_common.php', import.meta.url), 'utf8');
const migrationSource = readFileSync(new URL('../database/migrations/202607310007_reminder_delivery.sql', import.meta.url), 'utf8');

test('workload reminders cover every obligated employee role', () => {
  assert.match(migrationSource, /'sales,coach,manager', '20:00'/);
  assert.match(migrationSource, /'sales,coach,manager', '23:00'/);
  assert.match(runtimeSource, /role_code IN \('sales', 'coach', 'manager'\)/);
  assert.match(runtimeSource, /\['sales', 'coach', 'manager'\]/);
});

test('manager aliases resolve into the reminder population', () => {
  assert.match(runtimeSource, /\['manager', 'store_manager', 'shop_manager', '店长'\]/);
  assert.match(runtimeSource, /s\.role IN \('sales', 'coach', 'manager'.*'店长'/);
});
