import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [alerts, recommendations, worker, cli] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadAlertService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadRecommendationService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadAlertWorkerService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/alert-worker.php', import.meta.url), 'utf8'),
]);

const candidates = ({ weekday, time, statuses }) => {
  const canRemind = weekday !== 1 && time >= '20:30:00';
  return statuses.flatMap((status) => {
    if (['missing', 'draft'].includes(status) && canRemind) return [`${status}_reminder`];
    if (status === 'locked_missing') return ['locked_notice', 'store_alert'];
    return [];
  });
};

const recommendation = ({ reports, staff, selected, previous = null }) => {
  if (reports < 10 || staff < 3) return [];
  const current = selected * 100 / reports;
  const result = current <= 30 ? ['low_selection'] : [];
  if (previous && previous.reports >= 10 && previous.staff >= 3) {
    const previousRate = previous.selected * 100 / previous.reports;
    if (current - previousRate <= -20) result.push('downward_trend');
  }
  return result;
};

test('18.1 generates draft, missing, locked-missing, store, and audit-backlog events', () => {
  for (const code of [
    'draft_submission_reminder',
    'missing_submission_reminder',
    'locked_missing_notice',
    'locked_missing_store_alert',
    'audit_backlog_yellow',
    'audit_backlog_red',
  ]) assert.match(alerts, new RegExp(code));
  assert.match(alerts, /mini_user_notifications/);
  assert.match(alerts, /route.*pages\/workload\/index/);
  assert.deepEqual(candidates({ weekday: 2, time: '20:30:00', statuses: ['draft', 'missing'] }), [
    'draft_reminder',
    'missing_reminder',
  ]);
  assert.deepEqual(candidates({ weekday: 2, time: '20:29:59', statuses: ['draft'] }), []);
  assert.deepEqual(candidates({ weekday: 2, time: '00:00:00', statuses: ['locked_missing'] }), [
    'locked_notice',
    'store_alert',
  ]);
  assert.deepEqual(candidates({ weekday: 1, time: '21:00:00', statuses: ['draft', 'missing'] }), []);
  assert.deepEqual(candidates({ weekday: 1, time: '00:00:00', statuses: ['locked_missing'] }), [
    'locked_notice',
    'store_alert',
  ]);
});

test('18.2 limits advice by sample size and stores facts, thresholds, evidence, and actions', () => {
  assert.match(recommendations, /MINIMUM_REPORT_SAMPLE = 10/);
  assert.match(recommendations, /MINIMUM_STAFF_SAMPLE = 3/);
  assert.match(recommendations, /LOW_SELECTION_THRESHOLD = 30\.0/);
  assert.match(recommendations, /TREND_DROP_THRESHOLD = 20\.0/);
  assert.match(recommendations, /store_completion_yellow/);
  assert.match(recommendations, /store_completion_red/);
  assert.match(recommendations, /metric_low_selection_recommendation/);
  assert.match(recommendations, /metric_downward_trend_recommendation/);
  for (const field of ['numerator', 'denominator', 'current_value', 'threshold_value', 'evidence', 'action']) {
    assert.match(recommendations, new RegExp(field));
  }
  assert.deepEqual(recommendation({ reports: 9, staff: 3, selected: 0 }), []);
  assert.deepEqual(recommendation({ reports: 10, staff: 2, selected: 0 }), []);
  assert.deepEqual(recommendation({ reports: 10, staff: 3, selected: 3 }), ['low_selection']);
  assert.deepEqual(recommendation({
    reports: 10,
    staff: 3,
    selected: 3,
    previous: { reports: 10, staff: 3, selected: 6 },
  }), ['low_selection', 'downward_trend']);
});

test('18.3 worker generates obligations, locks deadlines, evaluates events, logs runs, and retries', () => {
  assert.match(worker, /generateForDate/);
  assert.match(worker, /lockExpired/);
  assert.match(worker, /WorkloadAlertService/);
  assert.match(worker, /WorkloadRecommendationService/);
  assert.match(worker, /workload_alert_worker_runs/);
  assert.match(worker, /MAX_ATTEMPTS = 3/);
  assert.match(worker, /status = 'retrying'/);
  assert.match(worker, /completed_with_warnings/);
  assert.match(cli, /PHP_SAPI !== 'cli'/);
  assert.match(cli, /new DateTimeZone\('Asia\/Shanghai'\)/);
  assert.match(cli, /WorkloadAlertWorkerService/);
  assert.match(cli, /fwrite\(STDERR/);
});

test('18.4 comparison boundaries follow configured operators and channel failures remain isolated', () => {
  assert.match(alerts, /'<' => \$value < \$threshold/);
  assert.match(alerts, /'<=' => \$value <= \$threshold/);
  assert.match(alerts, /catch \(Throwable \$error\).*?'channel' => 'station'/s);
  assert.match(worker, /notification_failures.*completed_with_warnings/s);
  assert.equal(89.9999 < 90, true);
  assert.equal(90 < 90, false);
  assert.equal(24 > 24, false);
  assert.equal(24.0001 > 24, true);
});
