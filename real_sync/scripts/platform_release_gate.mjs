import { pathToFileURL } from 'node:url';

export const OBSERVATION_WINDOWS = Object.freeze({
  documentation_or_static: 15,
  single_domain: 30,
  shared_platform: 60,
});

export function evaluateReleaseObservation(input) {
  const requiredMinutes = OBSERVATION_WINDOWS[input.batch_type];
  if (!requiredMinutes) {
    throw new TypeError('batch_type must be documentation_or_static, single_domain, or shared_platform');
  }

  const triggers = [];
  const metrics = input.metrics || {};
  const journeyFailures = maximumConsecutiveJourneyFailures(metrics.journey_probes || []);
  if (journeyFailures.count >= 2) {
    triggers.push(trigger('core_journey_consecutive_failures', journeyFailures));
  }

  const http = metrics.http_5xx || {};
  const httpRequests = nonNegative(http.requests);
  const httpErrors = nonNegative(http.errors);
  const httpRate = httpRequests === 0 ? 0 : httpErrors / httpRequests;
  if (httpRequests >= 20 && httpRate >= 0.02) {
    triggers.push(trigger('http_5xx_rate', { requests: httpRequests, errors: httpErrors, rate: httpRate }));
  }

  const latency = metrics.latency || {};
  const latencySamples = nonNegative(latency.samples);
  const p95 = nonNegative(latency.p95_ms);
  const baselineP95 = nonNegative(latency.baseline_p95_ms);
  const latencyMinutes = nonNegative(latency.consecutive_minutes_over_2x);
  if (latencySamples >= 100 && baselineP95 > 0 && p95 > baselineP95 * 2 && latencyMinutes >= 15) {
    triggers.push(trigger('p95_latency_regression', {
      samples: latencySamples,
      p95_ms: p95,
      baseline_p95_ms: baselineP95,
      consecutive_minutes: latencyMinutes,
    }));
  }

  const dataDifferences = nonNegative(metrics.data_differences);
  if (dataDifferences > 0) {
    triggers.push(trigger('data_reconciliation_difference', { count: dataDifferences }));
  }

  const queueOldestMinutes = nonNegative(metrics.queue_oldest_minutes);
  if (queueOldestMinutes > 15) {
    triggers.push(trigger('queue_backlog', { oldest_minutes: queueOldestMinutes }));
  }

  const tasks = metrics.tasks || {};
  const taskTotal = nonNegative(tasks.total);
  const taskFailed = nonNegative(tasks.failed);
  const taskFailureRate = taskTotal === 0 ? 0 : taskFailed / taskTotal;
  if (taskTotal > 0 && taskFailureRate >= 0.05) {
    triggers.push(trigger('task_failure_rate', { total: taskTotal, failed: taskFailed, rate: taskFailureRate }));
  }

  const permissions = metrics.permission_denials || {};
  const permissionRate = nonNegative(permissions.rate);
  const permissionBaseline = nonNegative(permissions.baseline_rate);
  if (permissionRate > permissionBaseline * 3) {
    triggers.push(trigger('permission_denial_regression', { rate: permissionRate, baseline_rate: permissionBaseline }));
  }

  const observedMinutes = nonNegative(input.observed_minutes);
  const observationComplete = observedMinutes >= requiredMinutes;
  const stop = triggers.length > 0;
  return {
    batch_type: input.batch_type,
    required_observation_minutes: requiredMinutes,
    observed_minutes: observedMinutes,
    observation_complete: observationComplete,
    stop,
    decision: stop ? 'stop_and_evaluate_rollback' : observationComplete ? 'continue' : 'observe',
    triggers,
  };
}

export function createReleaseEvidence(input) {
  for (const field of ['batch_id', 'scope', 'backup', 'validation', 'observation']) {
    if (input[field] === null || input[field] === undefined || input[field] === '') {
      throw new TypeError(`release evidence requires ${field}`);
    }
  }
  const decision = evaluateReleaseObservation(input.observation);
  return {
    schema_version: '1.0',
    batch_id: String(input.batch_id),
    generated_at: input.generated_at || new Date().toISOString(),
    scope: input.scope,
    backup: input.backup,
    validation: input.validation,
    observation: input.observation,
    decision,
    recovery: input.recovery || { status: 'not_required', actions: [] },
  };
}

export function maximumConsecutiveJourneyFailures(probes) {
  const state = new Map();
  let maximum = { journey: null, count: 0 };
  const sorted = [...probes].sort((left, right) => String(left.observed_at || '').localeCompare(String(right.observed_at || '')));
  for (const probe of sorted) {
    const journey = String(probe.journey || 'unknown');
    const count = probe.success === true ? 0 : (state.get(journey) || 0) + 1;
    state.set(journey, count);
    if (count > maximum.count) maximum = { journey, count };
  }
  return maximum;
}

function trigger(code, evidence) {
  return { code, evidence };
}

function nonNegative(value) {
  const number = Number(value || 0);
  if (!Number.isFinite(number) || number < 0) throw new TypeError('release metrics must be non-negative numbers');
  return number;
}

async function main() {
  let source = '';
  for await (const chunk of process.stdin) source += chunk;
  const input = JSON.parse(source);
  const evidence = createReleaseEvidence(input);
  process.stdout.write(`${JSON.stringify(evidence)}\n`);
  process.exitCode = evidence.decision.stop ? 2 : evidence.decision.observation_complete ? 0 : 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
  });
}
