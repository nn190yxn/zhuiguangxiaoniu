import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const viewState = require('../mini-program/utils/view-state.js');

test('统一页面状态区分普通错误、离线与冲突', () => {
  assert.equal(viewState.fromError(new Error('失败')).status, 'error');
  assert.equal(viewState.fromError({ category: 'network', message: '断网' }).status, 'offline');
  assert.deepEqual(
    viewState.fromError({ category: 'conflict', message: '版本冲突', recoveryAction: 'reload' }),
    { status: 'conflict', message: '版本冲突', recoveryAction: 'reload' }
  );
});

test('统一页面状态保留读写状态全集', () => {
  assert.deepEqual(Object.values(viewState.READ_STATES), ['loading', 'empty', 'ready', 'error', 'offline', 'conflict']);
  assert.deepEqual(Object.values(viewState.WRITE_STATES), ['idle', 'submitting', 'success', 'error', 'offline', 'conflict']);
});
