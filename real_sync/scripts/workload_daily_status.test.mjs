import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadDailyStatusService.php');
const endpoint = read('../api/workload/my-status.php');
const h5 = read('../mobile/workload-v2.html');
const miniJs = read('../mini-program/pages/workload/index.js');
const miniWxml = read('../mini-program/pages/workload/index.wxml');

test('[validates 1.1, 2.5, 4.1, 4.2] employee daily status returns today, yesterday, monthly penalties, and conversion guidance', () => {
  assert.equal(existsSync(new URL('../api/workload/my-status.php', import.meta.url)), true);
  for (const field of ['today', 'yesterday_makeup', 'monthly_penalty_summary', 'conversion_table']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /SELECT UTC_TIMESTAMP\(\)/);
  assert.match(service, /workload_daily_settlements/);
  assert.match(service, /workload_penalty_records/);
  assert.match(service, /workload_conversion_rule_versions/);
  assert.match(service, /metric_definitions/);
});

test('[validates 2.4, 2.5, 4.5] daily and penalty states expose Chinese labels and deadline data', () => {
  for (const label of ['今日可完成', '上一工作日可补齐', '已达标', '已逾期', '待确认', '已确认', '已撤销', '已交薪资']) {
    assert.match(service, new RegExp(label));
  }
  assert.match(service, /makeup_deadline_at/);
  assert.match(service, /is_makeup_open/);
  assert.match(service, /status_label/);
});

test('[validates 4.1] status endpoint is authenticated, self-scoped, and uses standard compatibility metadata', () => {
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /requireAuthenticated\(\)/);
  assert.match(endpoint, /forEmployee\(\$staffId, \$storeId, \$roleCode\)/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata/);
  assert.match(endpoint, /仅支持 GET 请求/);
});

test('[validates 1.4, 2.2, 4.1, 4.2, 4.5] H5 displays daily points, makeup, penalties, and Chinese conversion guidance', () => {
  assert.match(h5, /\/api\/workload\/my-status\.php/);
  for (const label of ['每日目标', '有效', '还差', '上一工作日可补', '本月处罚', '工作量怎么算']) {
    assert.match(h5, new RegExp(label));
  }
  assert.match(h5, /makeup_deadline_at/);
  assert.match(h5, /penalty\.status_label/);
  assert.match(h5, /conversion_table/);
  assert.match(h5, /2 个体测 = 1 点工作量|row\.description/);
});

test('[validates 1.4] H5 submits valid report metrics without the retired four-positive-metrics gate', () => {
  assert.doesNotMatch(h5, /minimumPositiveCount/);
  assert.doesNotMatch(h5, /至少填写 .+ 个大于 0 的工作量指标/);
  assert.match(h5, /forSubmit&&Number\(item\.required\)/);
  assert.match(h5, /getEvidenceGaps\(\)/);
});

test('[validates 1.4, 2.2, 2.5, 4.1, 4.2, 4.5] mini program uses the employee daily status for points, makeup, penalties, and rules', () => {
  assert.match(miniJs, /\/workload\/my-status\.php/);
  for (const label of ['每日目标', '有效', '还差', '上一工作日可补', '本月处罚', '工作量怎么算', '2 个体测 = 1 点工作量']) {
    assert.match(miniWxml, new RegExp(label));
  }
  assert.match(miniJs, /makeup_deadline_at/);
  assert.match(miniJs, /penalty\.status_label/);
  assert.match(miniJs, /conversion_table/);
  assert.doesNotMatch(miniJs, /minimumPositiveCount/);
  assert.doesNotMatch(miniJs, /至少填写 .+ 个大于 0 的工作量指标/);
  assert.match(miniJs, /forSubmit && Number\(item\.required\)/);
  assert.match(miniJs, /getEvidenceGaps\(\)/);
});

test('[validates 1.5, 2.1 to 2.5] employee clients present today, makeup, overdue, and rejected states with a next action', () => {
  for (const client of [h5, miniJs]) {
    assert.match(client, /makeup_open/);
    assert.match(client, /overdue/);
    assert.match(client, /rejected_points/);
    assert.match(client, /已驳回/);
    assert.match(client, /查看审核意见并补充后重新提交/);
  }
  assert.match(h5, /今天还差/);
  assert.match(h5, /请在当日结束前完成并提交日报/);
  assert.match(miniJs, /今天还差/);
  assert.match(miniJs, /请在当日结束前完成并提交日报/);
  for (const label of ['已报', '待审核', '已驳回']) {
    assert.match(h5, new RegExp(label));
    assert.match(miniWxml, new RegExp(label));
  }
  assert.match(service, /'today_open' => '今日可完成'/);
  assert.match(service, /'makeup_open' => '上一工作日可补齐'/);
  assert.match(service, /'overdue' => '已逾期'/);
});
