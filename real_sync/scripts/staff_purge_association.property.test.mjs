import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const associationSource = readFileSync(new URL('../api/admin/services/StaffAssociationService.php', import.meta.url), 'utf8');
const lifecycleSource = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');

const businessCategories = [
  'login_devices',
  'workload',
  'learning_pass',
  'drill_review',
  'notifications_messages',
  'points',
  'other_business',
  'audit_actor_history',
];

function createRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1103515245) + 12345) >>> 0;
    return state / 0x100000000;
  };
}

function inspectAssociationState({ counts, complete = true, primaryAssignments = 1, secondaryAssignments = 0 }) {
  const businessBlockingTotal = Object.values(counts).reduce((total, count) => total + count, 0);
  const identityBlockingTotal = Math.max(0, primaryAssignments - 1) + secondaryAssignments;
  const blockingTotal = businessBlockingTotal + identityBlockingTotal;
  const eligible = complete && blockingTotal === 0;
  return {
    complete,
    blockingTotal,
    eligible,
    recommendation: eligible ? 'purge' : 'offboard',
    confirmationToken: eligible ? 'issued' : null,
  };
}

test('production purge eligibility requires a complete check with zero blocking associations', () => {
  assert.match(associationSource, /\$eligible = \$complete && \$blockingTotal === 0/);
  assert.match(associationSource, /if \(\$eligible && \$issueToken\)/);
  assert.match(associationSource, /'confirmation_token' => \$token/);
  assert.match(associationSource, /'recommendation' => \$eligible \? 'purge' : 'offboard'/);
  assert.match(lifecycleSource, /if \(!\$associationSummary\['eligible_for_purge'\]\)/);
  assert.match(lifecycleSource, /throw new StaffPurgeBlockedException/);
});

test('every required business category contributes to the purge blocker total', () => {
  for (const category of businessCategories) {
    assert.match(associationSource, new RegExp(`'category' => '${category}'`));
    const counts = Object.fromEntries(businessCategories.map((name) => [name, name === category ? 1 : 0]));
    const result = inspectAssociationState({ counts });
    assert.equal(result.eligible, false);
    assert.equal(result.recommendation, 'offboard');
    assert.equal(result.confirmationToken, null);
  }
});

test('property 24: any nonzero business association always rejects controlled purge', () => {
  for (let run = 1; run <= 128; run += 1) {
    const random = createRandom(0x24000000 + run);

    for (let sample = 0; sample < 256; sample += 1) {
      const counts = Object.fromEntries(
        businessCategories.map((category) => [category, Math.floor(random() * 6)]),
      );
      const forcedCategory = businessCategories[Math.floor(random() * businessCategories.length)];
      counts[forcedCategory] = Math.max(1, counts[forcedCategory]);

      const result = inspectAssociationState({
        counts,
        complete: random() > 0.1,
        primaryAssignments: 1,
        secondaryAssignments: 0,
      });
      assert.ok(result.blockingTotal > 0);
      assert.equal(result.eligible, false);
      assert.equal(result.recommendation, 'offboard');
      assert.equal(result.confirmationToken, null);
    }
  }
});

test('only the complete zero-association identity baseline reaches token issuance', () => {
  const zeroCounts = Object.fromEntries(businessCategories.map((category) => [category, 0]));
  assert.equal(inspectAssociationState({ counts: zeroCounts }).eligible, true);
  assert.equal(inspectAssociationState({ counts: zeroCounts, complete: false }).eligible, false);
  assert.equal(inspectAssociationState({ counts: zeroCounts, primaryAssignments: 2 }).eligible, false);
  assert.equal(inspectAssociationState({ counts: zeroCounts, secondaryAssignments: 1 }).eligible, false);
});
