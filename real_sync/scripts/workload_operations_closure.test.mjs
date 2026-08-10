import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const queueSource = read('../api/workload/queue-alert-job.php');
const serviceSource = read('../api/workload/services/WorkloadMetricSelectionService.php');
const adminSource = read('../admin/workload.html');

test('工作量预警入队入口具备 CLI、分钟幂等和事务约束', () => {
  assert.match(queueSource, /PHP_SAPI !== 'cli'/);
  assert.match(queueSource, /beginTransaction\(\)/);
  assert.match(queueSource, /workload\.alert\.run/);
  assert.match(queueSource, /format\('Y-m-d-H-i'\)/);
  assert.match(queueSource, /hash\('sha256', 'workload\.alert\.run:' \. \$slot\)/);
  assert.match(queueSource, /commit\(\)/);
  assert.match(queueSource, /rollBack\(\)/);
});

test('项目统计返回目标、实际、差额和达成状态', () => {
  assert.match(serviceSource, /rule\.target_value AS daily_target/);
  for (const field of ['daily_target', 'period_target', 'target_gap', 'target_completion_rate', 'target_status']) {
    assert.match(serviceSource, new RegExp(`'${field}'`));
  }
  assert.match(serviceSource, /target_status.*unset/);
});

test('工作量后台使用运营语言展示目标差额', () => {
  for (const label of ['数据截至', '统计范围', '需要跟进门店', '日报达标差额', '目标', '实际', '差额']) {
    assert.match(adminSource, new RegExp(label));
  }
  for (const label of ['数据截止', '统计口径', '异常门店', '距离达标差额']) {
    assert.doesNotMatch(adminSource, new RegExp(label));
  }
});
