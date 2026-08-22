import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const scriptsRoot = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(scriptsRoot, '../..');
const projectConfig = JSON.parse(readFileSync(resolve(repositoryRoot, 'project.config.json'), 'utf8'));
const appSource = readFileSync(resolve(repositoryRoot, 'real_sync/mini-program/app.js'), 'utf8');

test('repository root opens directly in WeChat DevTools', () => {
  assert.equal(projectConfig.compileType, 'miniprogram');
  assert.equal(projectConfig.miniprogramRoot, 'real_sync/mini-program/');
  assert.equal(projectConfig.cloudfunctionRoot, 'real_sync/cloudfunctions/');
  assert.equal(existsSync(resolve(repositoryRoot, projectConfig.miniprogramRoot, 'app.json')), true);
  assert.equal(existsSync(resolve(repositoryRoot, projectConfig.cloudfunctionRoot)), true);
});

test('mini-program directory remains a standalone DevTools project', () => {
  const nestedConfig = JSON.parse(readFileSync(resolve(repositoryRoot, 'real_sync/mini-program/project.config.json'), 'utf8'));

  assert.equal(nestedConfig.miniprogramRoot, './');
  assert.equal(nestedConfig.cloudfunctionRoot, '../cloudfunctions/');
  assert.equal(existsSync(resolve(repositoryRoot, 'real_sync/mini-program/app.json')), true);
  assert.equal(existsSync(resolve(repositoryRoot, 'real_sync/cloudfunctions')), true);
});

test('local startup skips placeholder CloudBase initialization', () => {
  assert.match(appSource, /cloudConfig\.ENV_ID === '__CLOUD_ENV_ID__'/);
  assert.match(appSource, /TRANSPORT_EMERGENCY_ACTIVE: true/);
  assert.match(appSource, /return false;/);
});
