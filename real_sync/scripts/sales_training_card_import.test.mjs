import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const policy = source('../api/drill/TrainingAccessPolicy.php');
const config = source('../api/config.php');
const modulesApi = source('../api/drill/training-modules.php');
const cardsApi = source('../api/drill/training-cards.php');

// Executable model of the deliberately pure PHP role matrix.
const moduleRole = (role) => ['sales', 'consultant', 'newbie'].includes(String(role).trim().toLowerCase())
  ? 'consultant' : String(role).trim().toLowerCase();
const allowed = ({ authenticated = true, jwtRole = 'staff', staffRole = '' }, requiredRole) =>
  authenticated && (['admin', 'manager'].includes(jwtRole)
    || !String(requiredRole ?? '').trim()
    || moduleRole(staffRole) === String(requiredRole).trim().toLowerCase());

test('training access policy role matrix is exact and ignores module level', () => {
  assert.equal(allowed({ authenticated: false, staffRole: 'sales' }, ''), false);
  for (const role of ['sales', 'consultant', 'newbie']) assert.equal(moduleRole(role), 'consultant');
  assert.equal(allowed({ staffRole: 'sales' }, null), true);
  assert.equal(allowed({ staffRole: 'sales' }, 'consultant'), true);
  assert.equal(allowed({ staffRole: 'sales' }, 'coach'), false);
  assert.equal(allowed({ staffRole: 'coach' }, ''), true);
  assert.equal(allowed({ staffRole: 'coach' }, 'coach'), true);
  assert.equal(allowed({ staffRole: 'coach' }, 'consultant'), false);
  for (const jwtRole of ['admin', 'manager']) assert.equal(allowed({ jwtRole, staffRole: 'coach' }, 'consultant'), true);
  for (const staffRole of ['operation', 'ceo']) assert.equal(allowed({ jwtRole: 'staff', staffRole }, 'consultant'), false);
  assert.doesNotMatch(policy, /\blevel\b/);
});

test('config builds one authenticated context for JWT and trusted session without anonymous sales fallback', () => {
  assert.match(config, /require_once __DIR__ \. '\/drill\/TrainingAccessPolicy\.php'/);
  assert.match(config, /function getWordPressAuthRole\(\$userId\)/);
  assert.match(config, /unserialize\(\$serialized, \['allowed_classes' => false\]\)/);
  assert.match(config, /function getCurrentTrainingAccessContext\(\)/);
  assert.match(config, /\$user = getJwtCurrentUser\(\);[\s\S]*?if \(!\$user\)[\s\S]*?\$userId = getCurrentUserId\(\)/);
  assert.match(config, /if \(\$userId <= 0\)[\s\S]*?'authenticated' => false/);
  assert.match(config, /'role' => getWordPressAuthRole\(\$userId\)/);
  assert.match(config, /'staff_id' => \$staff \? \(int\)\$staff\['id'\] : null/);
  assert.match(config, /function requireTrainingModuleAccess/);
  assert.match(config, /function getTrainingModuleAccessSql/);
  assert.doesNotMatch(policy, /operation|ceo/);
});

test('all module API actions authenticate and authorize before protected reads', () => {
  assert.match(modulesApi, /\$context = requireTrainingAccessContext\(\);/);
  for (const action of ['list', 'detail', 'cards', 'my_progress']) assert.match(modulesApi, new RegExp(`case '${action}'`));
  assert.match(modulesApi, /loadAuthorizedTrainingModule\(\$db, \$context, \$moduleId\)/);
  assert.match(modulesApi, /requireTrainingModuleAccess\(\$context, \$module\)/);
  assert.match(modulesApi, /tm\.status = 1 AND tc\.status = 1|tc\.status = 1 AND tm\.status = 1/);
  assert.match(modulesApi, /getTrainingModuleAccessSql\(\$context, 'tm'\)/);
  assert.match(modulesApi, /\$requestedRole !== '' && !empty\(\$context\['is_management'\]\)/);
});

test('all card API actions authenticate; list filters in SQL; reads and writes use authorized loader', () => {
  assert.match(cardsApi, /\$context = requireTrainingAccessContext\(\);/);
  for (const action of ['list', 'get', 'submit', 'reset']) assert.match(cardsApi, new RegExp(`case '${action}'`));
  assert.doesNotMatch(cardsApi, /!\$userId.*\['list', 'get'\]/);
  assert.match(cardsApi, /FROM training_cards tc JOIN training_modules tm/);
  assert.match(cardsApi, /WHERE tc\.status = 1 AND tm\.status = 1 AND \{\$access\['sql'\]\}/);
  assert.match(cardsApi, /function loadAuthorizedTrainingCard/);
  assert.match(cardsApi, /WHERE tc\.id = \? AND tc\.status = 1 AND tm\.status = 1/);
  assert.match(cardsApi, /function getCard[\s\S]*?loadAuthorizedTrainingCard/);
  assert.match(cardsApi, /function submitAnswer[\s\S]*?loadAuthorizedTrainingCard[\s\S]*?UPDATE user_progress/);
  assert.match(cardsApi, /function resetCard[\s\S]*?loadAuthorizedTrainingCard[\s\S]*?DELETE FROM user_progress/);
});

test('successful response envelopes remain compatible', () => {
  assert.match(modulesApi, /jsonResponse\(0, 'success', \['modules' => \$modules\]\)/);
  assert.match(modulesApi, /jsonResponse\(0, 'success', \['cards' => \$cards\]\)/);
  assert.match(modulesApi, /jsonResponse\(0, 'success', \['progress' =>/);
  assert.match(cardsApi, /jsonResponse\(0, 'success', \['cards' =>/);
  assert.match(cardsApi, /jsonResponse\(0, 'success', \$card\)/);
  assert.match(cardsApi, /jsonResponse\(0, '重置成功'\)/);
});
