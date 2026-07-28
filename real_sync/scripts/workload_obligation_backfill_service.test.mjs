import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/workload/services/WorkloadObligationBackfillService.php', import.meta.url),
  'utf8',
);
const worker = readFileSync(
  new URL('../api/workload/obligation-backfill-worker.php', import.meta.url),
  'utf8',
);

const normalizeRole = (role) => {
  const value = String(role).trim().toLowerCase();
  if (['sales', 'sale', 'consultant', '销售', '实习销售'].includes(value)) return 'sales';
  if (['coach', '教练', '实习教练'].includes(value)) return 'coach';
  if (['manager', 'store_manager', 'shop_manager', '店长'].includes(value)) return 'manager';
  return value;
};

const eachDate = (from, to) => {
  const dates = [];
  for (let value = from; value <= to;) {
    dates.push(value);
    const next = new Date(`${value}T00:00:00Z`);
    next.setUTCDate(next.getUTCDate() + 1);
    value = next.toISOString().slice(0, 10);
  }
  return dates;
};

function backfillModel({ from, to, reports, assignments }) {
  const obligations = new Map();
  const reportKeys = new Set();
  for (const report of reports) {
    if (report.date < from || report.date > to) continue;
    const exactKey = `${report.date}:${report.storeId}:${report.staffId}:${report.role}`;
    const semanticKey = `${report.date}:${report.storeId}:${report.staffId}:${normalizeRole(report.role)}`;
    obligations.set(exactKey, {
      key: exactKey,
      storeId: report.storeId,
      role: report.role,
      reportId: report.id,
      completionStatus: report.submitted ? 'submitted' : 'draft',
      source: 'backfill',
    });
    reportKeys.add(semanticKey);
  }

  const candidates = new Map();
  for (const assignment of assignments) {
    const role = normalizeRole(assignment.role);
    if (!['sales', 'coach', 'manager'].includes(role)) continue;
    for (const date of eachDate(from, to)) {
      if (date < assignment.startDate || (assignment.endDate && date > assignment.endDate)) continue;
      const key = `${date}:${assignment.storeId}:${assignment.staffId}:${role}`;
      candidates.set(key, { key, date, role, storeId: assignment.storeId });
    }
  }
  for (const candidate of candidates.values()) {
    if (reportKeys.has(candidate.key)) continue;
    const monday = new Date(`${candidate.date}T00:00:00Z`).getUTCDay() === 1;
    obligations.set(candidate.key, {
      ...candidate,
      requiredStatus: monday ? 'exempt' : 'required',
      completionStatus: monday ? 'exempt' : 'missing',
      reasonCode: monday ? 'weekly_rest_day' : 'historical_assignment',
      source: 'backfill',
    });
  }
  return [...obligations.values()];
}

test('service accepts only a completed historical Shanghai date range', () => {
  assert.match(service, /BUSINESS_TIMEZONE = 'Asia\/Shanghai'/);
  assert.match(service, /if \(\$from > \$to\)/);
  assert.match(service, /if \(\$to >= \$today\)/);
  assert.match(service, /createFromFormat\('!Y-m-d', \$businessDate, \$timezone\)/);
});

test('historical reports create obligations from their stored organization snapshot', () => {
  assert.match(service, /FROM workload_daily_reports WHERE report_date BETWEEN \? AND \?/);
  assert.match(service, /\$roleCode = \(string\) \$report\['role_code'\]/);
  assert.match(service, /\$storeId = \(int\) \$report\['store_id'\]/);
  assert.match(service, /\$storedRoleCode = \$existingSemanticKeys\[\$semanticKey\]/);
  assert.match(service, /\$existingSemanticKeys\[\$semanticKey\] = \$storedRoleCode/);
  assert.match(service, /'historical_report'/);
  assert.match(service, /'backfill'/);
});

test('effective assignments supplement confirmed employee workload history', () => {
  assert.match(service, /FROM staff_assignments assignment/);
  assert.match(service, /assignment\.start_date <= \?/);
  assert.match(service, /assignment\.end_date IS NULL OR assignment\.end_date >= \?/);
  assert.match(service, /staff\.lifecycle_status = 'offboarded'/);
  assert.match(service, /appRoleCode\(/);
  assert.match(service, /ELIGIBLE_ROLES = \['sales', 'coach', 'manager'\]/);
  assert.match(service, /'historical_assignment'/);
});

test('backfill is transactional, idempotent, and protects corrected obligations', () => {
  assert.match(service, /\$this->pdo->beginTransaction\(\)/);
  assert.match(service, /ON DUPLICATE KEY UPDATE/g);
  assert.match(service, /completion_status = 'corrected'/);
  assert.match(service, /report_id IS NULL AND completion_status IN \(/);
  assert.match(service, /\$this->pdo->rollBack\(\)/);
  assert.ok(
    service.indexOf("completed_at = CASE WHEN completion_status = 'corrected'")
      < service.indexOf("completion_status = CASE WHEN completion_status = 'corrected'"),
  );
});

test('CLI accepts one date or a date range and emits JSON', () => {
  assert.match(worker, /PHP_SAPI !== 'cli'/);
  assert.match(worker, /\$toDate = isset\(\$argv\[2\]\)/);
  assert.match(worker, /->backfill\(\$fromDate, \$toDate\)/);
  assert.match(worker, /json_encode\(\$result, JSON_UNESCAPED_UNICODE\)/);
});

test('model preserves report snapshots and fills only uncovered assignment dates', () => {
  const obligations = backfillModel({
    from: '2026-07-27',
    to: '2026-07-29',
    reports: [
      { id: 91, date: '2026-07-28', storeId: 20, staffId: 1, role: 'consultant', submitted: true },
    ],
    assignments: [
      { startDate: '2026-07-27', endDate: '2026-07-29', storeId: 10, staffId: 1, role: 'sales' },
      { startDate: '2026-07-27', endDate: '2026-07-29', storeId: 10, staffId: 2, role: 'manager' },
    ],
  });

  const report = obligations.find(({ reportId }) => reportId === 91);
  assert.equal(report.storeId, 20);
  assert.equal(report.role, 'consultant');
  assert.equal(report.completionStatus, 'submitted');
  assert.equal(obligations.filter(({ storeId }) => storeId === 10).length, 6);
  assert.equal(obligations.find(({ date }) => date === '2026-07-27').requiredStatus, 'exempt');
  assert.equal(obligations.find(({ date }) => date === '2026-07-29').source, 'backfill');
});
