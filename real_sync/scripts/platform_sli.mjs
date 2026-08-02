import { pathToFileURL } from 'node:url';

export const TIER1_JOURNEYS = Object.freeze([
  'public_homepage',
  'synthetic_login',
  'employee_entry',
  'core_api',
]);

export function buildJourneyDefinitions({ baseUrl, username = '', password = '' }) {
  const root = String(baseUrl || '').replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(root)) {
    throw new TypeError('baseUrl must be an absolute HTTP URL');
  }

  return [
    {
      id: 'public_homepage',
      url: `${root}/`,
      request: { method: 'GET' },
      assert: ({ status, text }) => status < 500 && /<html\b/i.test(text),
    },
    {
      id: 'synthetic_login',
      url: `${root}/api/auth-jwt.php`,
      request: {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'login', username, password, source: 'synthetic_sli' }),
      },
      configured: username !== '' && password !== '',
      assert: ({ status, json }) => status < 500 && json?.code === 0 && Boolean(json?.data?.token),
    },
    {
      id: 'employee_entry',
      url: `${root}/internal.html`,
      request: { method: 'GET' },
      assert: ({ status, text }) => status < 500 && /<html\b/i.test(text),
    },
    {
      id: 'core_api',
      url: `${root}/api/platform/health.php?check=ready`,
      request: { method: 'GET', headers: { Accept: 'application/json' } },
      assert: ({ status, json }) => status === 200 && json?.data?.health?.status === 'healthy',
    },
  ];
}

export async function probeJourney(journey, { fetchImpl = globalThis.fetch, timeoutMs = 8000, now = Date.now } = {}) {
  const startedAt = now();
  if (journey.configured === false) {
    return probeResult(journey.id, startedAt, 0, false, 'not_configured');
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetchImpl(journey.url, { ...journey.request, signal: controller.signal });
    const text = await response.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {
      json = null;
    }
    const elapsedMs = Math.max(0, now() - startedAt);
    if (response.status >= 500) {
      return probeResult(journey.id, startedAt, elapsedMs, false, 'http_5xx', response.status);
    }
    if (!journey.assert({ status: response.status, text, json })) {
      return probeResult(journey.id, startedAt, elapsedMs, false, json === null ? 'response_structure' : 'authority_assertion', response.status);
    }
    return probeResult(journey.id, startedAt, elapsedMs, true, null, response.status);
  } catch (error) {
    const failure = error?.name === 'AbortError' ? 'timeout' : 'transport_error';
    return probeResult(journey.id, startedAt, Math.max(0, now() - startedAt), false, failure);
  } finally {
    clearTimeout(timer);
  }
}

export async function runSyntheticJourneys(options) {
  const {
    baseUrl,
    username = '',
    password = '',
    attempts = 2,
    fetchImpl = globalThis.fetch,
    timeoutMs = 8000,
    now = Date.now,
  } = options;
  if (!Number.isInteger(attempts) || attempts < 2 || attempts > 10) {
    throw new RangeError('attempts must be an integer from 2 to 10');
  }

  const results = [];
  for (const journey of buildJourneyDefinitions({ baseUrl, username, password })) {
    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      const result = await probeJourney(journey, { fetchImpl, timeoutMs, now });
      results.push({ ...result, attempt });
    }
  }
  return results;
}

export function aggregateMinute(probes, { requiredSuccesses = 2 } = {}) {
  if (!Number.isInteger(requiredSuccesses) || requiredSuccesses < 1) {
    throw new RangeError('requiredSuccesses must be a positive integer');
  }
  const journeyResults = Object.fromEntries(TIER1_JOURNEYS.map((id) => [id, { total: 0, successes: 0, failures: {} }]));
  for (const probe of probes) {
    if (!journeyResults[probe.journey]) continue;
    const result = journeyResults[probe.journey];
    result.total += 1;
    if (probe.success === true) {
      result.successes += 1;
    } else {
      const failure = probe.failure || 'unknown';
      result.failures[failure] = (result.failures[failure] || 0) + 1;
    }
  }
  const available = TIER1_JOURNEYS.every((id) => journeyResults[id].successes >= requiredSuccesses);
  return { available, required_successes: requiredSuccesses, journeys: journeyResults };
}

export function calculateMonthlyAvailability(month, minuteResults, plannedMaintenanceMinutes = []) {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) {
    throw new TypeError('month must use YYYY-MM format');
  }
  const [year, monthNumber] = month.split('-').map(Number);
  const totalMinutes = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate() * 24 * 60;
  const availableMinutes = minuteResults.filter((result) => result.available === true).length;
  return {
    month,
    available_minutes: availableMinutes,
    total_minutes: totalMinutes,
    unavailable_minutes: totalMinutes - availableMinutes,
    planned_maintenance_minutes: new Set(plannedMaintenanceMinutes).size,
    availability: totalMinutes === 0 ? 0 : availableMinutes / totalMinutes,
    objective: 0.999,
    objective_met: availableMinutes / totalMinutes >= 0.999,
  };
}

function probeResult(journey, startedAt, durationMs, success, failure, httpStatus = null) {
  return {
    journey,
    observed_at: new Date(startedAt).toISOString(),
    duration_ms: durationMs,
    success,
    failure,
    http_status: httpStatus,
  };
}

async function main() {
  const results = await runSyntheticJourneys({
    baseUrl: process.env.PLATFORM_SLI_BASE_URL || 'https://supercalf.com',
    username: process.env.PLATFORM_SLI_USERNAME || '',
    password: process.env.PLATFORM_SLI_PASSWORD || '',
  });
  process.stdout.write(`${JSON.stringify({ generated_at: new Date().toISOString(), probes: results })}\n`);
  process.exitCode = results.every(({ success }) => success) ? 0 : 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
  });
}
