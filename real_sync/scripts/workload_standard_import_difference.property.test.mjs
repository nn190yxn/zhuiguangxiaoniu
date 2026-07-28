import assert from 'node:assert/strict';
import test from 'node:test';

function difference(existing, imported) {
  const previous = new Map(existing.map((item) => [item.code, item.value]));
  const next = new Map(imported.map((item) => [item.code, item.value]));
  const result = { added: [], modified: [], disabled: [], unchanged: [] };
  for (const [code, value] of next) {
    if (!previous.has(code)) result.added.push(code);
    else if (previous.get(code) === value) result.unchanged.push(code);
    else result.modified.push(code);
  }
  for (const code of previous.keys()) if (!next.has(code)) result.disabled.push(code);
  return result;
}

function randomItems(seed, maximum) {
  let value = seed;
  const next = () => (value = (value * 1664525 + 1013904223) >>> 0);
  const items = new Map();
  for (let index = 0; index < maximum; index++) {
    const code = `metric_${next() % 20}`;
    items.set(code, next() % 100);
  }
  return [...items].map(([code, itemValue]) => ({ code, value: itemValue }));
}

test('[validates 25.2; correctness 5] every union item belongs to exactly one difference set', () => {
  for (let seed = 1; seed <= 1000; seed++) {
    const existing = randomItems(seed, seed % 17);
    const imported = randomItems(seed * 31, seed % 19);
    const result = difference(existing, imported);
    const groups = Object.values(result);
    const classified = groups.flat();
    const union = new Set([...existing, ...imported].map((item) => item.code));
    assert.equal(classified.length, union.size);
    assert.equal(new Set(classified).size, classified.length);
    assert.equal(result.added.length + result.modified.length + result.unchanged.length, imported.length);
    for (const code of result.disabled) assert.equal(imported.some((item) => item.code === code), false);
  }
});

test('[validates 25.2] importing an identical standard is entirely unchanged', () => {
  for (let seed = 1; seed <= 200; seed++) {
    const items = randomItems(seed, 20);
    const result = difference(items, items);
    assert.equal(result.unchanged.length, items.length);
    assert.equal(result.added.length + result.modified.length + result.disabled.length, 0);
  }
});
