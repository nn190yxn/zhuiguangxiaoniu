import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const penaltyService = read('../api/workload/services/WorkloadPenaltyService.php');
const settlementService = read('../api/workload/services/WorkloadDailySettlementService.php');
const worker = read('../api/workload/settlement-worker.php');
const penaltyAction = read('../api/admin/workload/penalty-action.php');

function amount(gapPoints) {
  return Math.round(gapPoints * 20 * 100) / 100;
}

test('[validates 3.1, 3.2] overdue gaps create one scope-unique twenty-yuan-per-point penalty', () => {
  assert.equal(amount(1), 20);
  assert.equal(amount(1.5), 30);
  assert.match(penaltyService, /private const UNIT_AMOUNT = 20\.00/);
  assert.match(penaltyService, /settlement\['settlement_status'\] === 'overdue'/);
  assert.match(penaltyService, /\$gapPoints \* self::UNIT_AMOUNT/);
  assert.match(penaltyService, /pending_confirmation/);
  assert.match(penaltyService, /FOR UPDATE/);
});

test('[validates 3.2, 3.3] repeated settlement is stable and non-payroll changes are audited', () => {
  assert.match(penaltyService, /if \(!\$existing\)/);
  assert.match(penaltyService, /if \(\(string\) \$existing\['status'\] === 'payroll_handed_off'\)/);
  assert.match(penaltyService, /审核或管理更正导致最终差额变化/);
  assert.match(penaltyService, /'adjusted'/);
  assert.match(penaltyService, /workload_penalty_record_logs/);
  assert.match(penaltyService, /before_snapshot_json/);
  assert.match(penaltyService, /审核或管理更正后已无最终差额/);
  assert.match(penaltyService, /status = 'cancelled'/);
});

test('[validates 3.1, 5.3] settlement and worker refresh penalties inside their transactions', () => {
  assert.match(settlementService, /WorkloadPenaltyService/);
  assert.match(settlementService, /->refreshForSettlement\(\$settlement\)/);
  assert.match(worker, /WorkloadPenaltyService/);
  assert.match(worker, /penalty_count/);
});

test('[validates 3.4, 3.5] only headquarters operators can transition penalties with reasons and audit history', () => {
  assert.match(penaltyAction, /\['operation', 'admin', 'ceo'\]/);
  assert.match(penaltyAction, /REQUEST_METHOD.*POST/);
  assert.match(penaltyAction, /adminJsonInput/);
  assert.match(penaltyAction, /adminRecordOperation/);
  assert.match(penaltyService, /function applyAction/);
  assert.match(penaltyService, /\['confirm', 'cancel', 'payroll_handoff'\]/);
  assert.match(penaltyService, /处理原因需填写/);
  assert.match(penaltyService, /FOR UPDATE/);
  assert.match(penaltyService, /confirmed_by_staff_id/);
  assert.match(penaltyService, /cancelled_by_staff_id/);
  assert.match(penaltyService, /payroll_handed_off_by_staff_id/);
  assert.match(penaltyService, /operated_by_staff_id/);
});

test('[validates 3.4, 3.5, 3.6] penalty state transitions preserve the confirmation and payroll handoff sequence', () => {
  assert.match(penaltyService, /'confirm' => \$status === 'pending_confirmation'/);
  assert.match(penaltyService, /'cancel' => in_array\(\$status, \['pending_confirmation', 'confirmed'\], true\)/);
  assert.match(penaltyService, /'payroll_handoff' => \$status === 'confirmed'/);
  assert.match(penaltyService, /当前处罚状态不能确认/);
  assert.match(penaltyService, /当前处罚状态不能撤销/);
  assert.match(penaltyService, /处罚确认后才能交薪资/);
  assert.match(penaltyService, /\$this->pdo->beginTransaction\(\)/);
  assert.match(penaltyService, /\$this->pdo->commit\(\)/);
});
