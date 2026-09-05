import assert from 'node:assert/strict';

const BASE_URL = 'https://endpoint.invalid';

function endpointParts(endpoint) {
  if (typeof endpoint === 'string') {
    return { method: 'GET', path: endpoint };
  }
  if (endpoint && typeof endpoint === 'object') {
    return { method: endpoint.method || 'GET', path: endpoint.path || '' };
  }
  return { method: 'GET', path: '' };
}

export function normalizeEndpoint(methodOrEndpoint, path) {
  const input = typeof methodOrEndpoint === 'object' && methodOrEndpoint !== null
    ? methodOrEndpoint
    : { method: methodOrEndpoint, path };
  const parsed = new URL(String(input.path || ''), BASE_URL);
  const query = [...parsed.searchParams.entries()]
    .filter(([key]) => key === 'action')
    .sort(([leftKey, leftValue], [rightKey, rightValue]) => leftKey.localeCompare(rightKey) || leftValue.localeCompare(rightValue));
  const search = query.length > 0 ? `?${new URLSearchParams(query).toString()}` : '';
  const method = String(input.method || 'GET').toUpperCase() === 'UPLOAD' ? 'POST' : String(input.method || 'GET').toUpperCase();
  return `${method} ${parsed.pathname}${search}`;
}

export function endpointKeys(endpoints) {
  return endpoints.map(endpoint => normalizeEndpoint(endpointParts(endpoint)));
}

function sortedUnique(values) {
  return [...new Set(values)].sort();
}

function duplicateKeys(values) {
  const counts = new Map();
  for (const value of values) counts.set(value, (counts.get(value) || 0) + 1);
  return [...counts.entries()].filter(([, count]) => count > 1).map(([value]) => value).sort();
}

export function compareEndpointSets(leftEndpoints, rightEndpoints) {
  const left = endpointKeys(leftEndpoints);
  const right = endpointKeys(rightEndpoints);
  const leftSet = new Set(left);
  const rightSet = new Set(right);
  return {
    equal: leftSet.size === rightSet.size
      && [...leftSet].every(value => rightSet.has(value))
      && duplicateKeys(left).length === 0
      && duplicateKeys(right).length === 0,
    left: sortedUnique(left),
    right: sortedUnique(right),
    leftOnly: sortedUnique(left.filter(value => !rightSet.has(value))),
    rightOnly: sortedUnique(right.filter(value => !leftSet.has(value))),
    duplicateLeft: duplicateKeys(left),
    duplicateRight: duplicateKeys(right),
  };
}

export function assertEndpointCollectionsEqual(leftEndpoints, rightEndpoints, message = 'endpoint 集合不一致') {
  const comparison = compareEndpointSets(leftEndpoints, rightEndpoints);
  assert.equal(comparison.equal, true, `${message}: ${JSON.stringify(comparison)}`);
  return comparison;
}
