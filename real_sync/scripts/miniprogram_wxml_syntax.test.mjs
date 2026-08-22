import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';

const root = new URL('../mini-program/', import.meta.url);

function collectWxml(directory, files = []) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) collectWxml(path, files);
    else if (entry.name.endsWith('.wxml')) files.push(path);
  }
  return files;
}

test('WXML does not combine wx:else and wx:for on one node', () => {
  const files = collectWxml(root.pathname);
  for (const file of files) {
    const source = readFileSync(file, 'utf8');
    assert.doesNotMatch(source, /<[^>]+wx:else[^>]+wx:for=/, file);
    assert.doesNotMatch(source, /<[^>]+wx:for=[^>]+wx:else/, file);
  }
});
