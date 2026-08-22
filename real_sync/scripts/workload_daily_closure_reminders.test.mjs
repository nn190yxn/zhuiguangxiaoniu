import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const reminder = read('../api/reminder/_common.php');
const worker = read('../api/reminder/reminder-worker.php');
const handler = read('../api/platform/jobs/ReminderJobHandler.php');
const jobsEndpoint = read('../api/reminder/jobs.php');
const workloadDailyEndpoint = read('../api/reminder/workload-daily.php');
const migration = read('../database/migrations/202608120005_workload_daily_closure_reminders.sql');
const deliveryMigration = read('../database/migrations/202607310007_reminder_delivery.sql');

test('[validates 5.1 to 5.3] daily closure reminders target makeup, overdue penalties, managers, and headquarters', () => {
  for (const code of [
    'workload_makeup_employee', 'workload_makeup_manager',
    'workload_penalty_employee', 'workload_penalty_manager', 'workload_penalty_hq',
  ]) {
    assert.match(reminder, new RegExp(code));
    assert.match(migration, new RegExp(code));
  }
  assert.match(reminder, /reminderFetchWorkloadMakeupRows/);
  assert.match(reminder, /settlement_status = 'makeup_open'/);
  assert.match(reminder, /reminderFetchWorkloadPenaltyRows/);
  assert.match(reminder, /settlement_status = 'overdue'/);
  assert.match(reminder, /penalty\.status = 'pending_confirmation'/);
  assert.match(reminder, /reminderFetchManagersByStore/);
  assert.match(reminder, /reminderFetchHeadquarterRecipients/);
});

test('[validates 5.1 to 5.3] reminder phases are scheduled and dispatched through the existing idempotent jobs', () => {
  assert.match(reminder, /if \(\$time >= '09:00'\)/);
  assert.match(reminder, /if \(\$time >= '00:05' && \$time < '09:00'\)/);
  assert.match(handler, /'learning_required', 'first', 'second', 'store_summary', 'hq_summary'/);
  assert.match(workloadDailyEndpoint, /in_array\(\$phase, \['all', 'first', 'second', 'store_summary', 'hq_summary', 'makeup', 'penalty'\], true\)/);
  assert.match(workloadDailyEndpoint, /reminderBuildWorkloadJobs\(\$pdo, \$reportDate, \$phase\)/);
  for (const source of [worker, jobsEndpoint, workloadDailyEndpoint]) {
    assert.match(source, /'makeup'/);
    assert.match(source, /'penalty'/);
  }
  assert.match(reminder, /reminderUpsertJob/);
  assert.match(deliveryMigration, /UNIQUE KEY uk_target_job/);
  assert.match(reminder, /reminderWechatTemplateKey/);
});

test('[validates 5.2, 5.3] reminder messages use Chinese actions and the workload entry point', () => {
  for (const label of ['昨天工作量待补齐', '工作量逾期处理结果', '工作量逾期跟进', '工作量逾期处罚待处理汇总']) {
    assert.match(reminder, new RegExp(label));
  }
  assert.match(reminder, /请在今晚 24:00 前跟进完成/);
  assert.match(reminder, /请在处罚处理页确认/);
});
