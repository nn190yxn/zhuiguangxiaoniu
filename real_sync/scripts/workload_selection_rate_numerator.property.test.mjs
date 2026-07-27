import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const analyticsService = readFileSync(
  new URL('../api/workload/services/WorkloadAnalyticsQueryService.php', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const metricCodes = ['consultation', 'experience', 'renewal', 'referral'];

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function phpRoundToTwo(value) {
  return Math.sign(value) * Math.round(Math.abs(value) * 100 + Number.EPSILON) / 100;
}

function selectionSummaries(rows) {
  const groups = new Map();
  for (const row of rows) {
    if (row.reportStatus !== 'submitted' || row.metricCode === '' || row.reportId <= 0) continue;
    if (!groups.has(row.metricCode)) groups.set(row.metricCode, new Map());
    const reports = groups.get(row.metricCode);
    if (!reports.has(row.reportId)) reports.set(row.reportId, phpRoundToTwo(row.rawValue));
  }

  return [...groups.entries()].map(([metricCode, reports]) => ({
    metricCode,
    numerator: [...reports.values()].filter((rawValue) => rawValue > 0).length,
    denominator: reports.size,
  }));
}

function assertProperty6(rows, context) {
  for (const summary of selectionSummaries(rows)) {
    assert.ok(
      summary.numerator <= summary.denominator,
      `${context}: ${summary.metricCode} numerator ${summary.numerator}, denominator ${summary.denominator}`,
    );
    const value = summary.denominator > 0 ? summary.numerator / summary.denominator : 0;
    assert.ok(value >= 0 && value <= 1, `${context}: ${summary.metricCode} rate ${value}`);
  }
}

test(`${validatesCriteria(['3.1', 'Property 6'])} arbitrary metric facts keep the positive raw numerator within submitted reports`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const rows = [];

    for (let step = 0; step < 256; step += 1) {
      rows.push({
        reportId: random() < 0.03 ? 0 : 1 + Math.floor(random() * 64),
        metricCode: random() < 0.03
          ? ''
          : metricCodes[Math.floor(random() * metricCodes.length)],
        reportStatus: random() < 0.75 ? 'submitted' : 'draft',
        rawValue: (random() * 2_000_000 - 1_000_000) / 1000,
      });
      assertProperty6(rows, `seed ${seed}, step ${step}`);
    }
  }
});

test(`${validatesCriteria(['3.1', 'Property 6'])} rounding, duplicate, draft, and metric boundaries preserve the subset`, () => {
  const rawValues = [-1000, -0.005, -0.004, 0, 0.004, 0.005, 0.01, 1000];
  const rows = rawValues.flatMap((rawValue, index) => [
    {
      reportId: index + 1,
      metricCode: 'consultation',
      reportStatus: 'submitted',
      rawValue,
    },
    {
      reportId: index + 1,
      metricCode: 'consultation',
      reportStatus: 'submitted',
      rawValue: 1000,
    },
    {
      reportId: index + 1,
      metricCode: 'renewal',
      reportStatus: 'draft',
      rawValue: 1000,
    },
  ]);

  assert.deepEqual(selectionSummaries(rows), [{
    metricCode: 'consultation',
    numerator: 3,
    denominator: 8,
  }]);
  assertProperty6(rows, 'boundary matrix');
  assert.deepEqual(selectionSummaries([]), []);
});

test(`${validatesCriteria(['3.1', 'Property 6'])} production aggregation counts a positive subset of deduplicated submitted reports`, () => {
  assert.match(analyticsService, /if \(\(\$row\['report_status'\] \?\? ''\) !== 'submitted'\)/);
  assert.match(analyticsService, /if \(isset\(\$groups\[\$metricCode\]\['_report_ids'\]\[\$reportId\]\)\)/);
  assert.match(analyticsService, /\$groups\[\$metricCode\]\['submitted_report_count'\]\+\+/);
  assert.match(analyticsService, /if \(\$rawValue > 0\)/);
  assert.match(analyticsService, /\$groups\[\$metricCode\]\['positive_raw_report_count'\]\+\+/);
  assert.match(
    analyticsService,
    /\$group\['selection_rate'\] = \$this->ratio\(\$group\['positive_raw_report_count'\], \$submittedReportCount\)/,
  );
  assert.match(analyticsService, /'value' => \$denominator > 0 \? round\(\$numerator \/ \$denominator, 4\) : 0\.0/);
});
