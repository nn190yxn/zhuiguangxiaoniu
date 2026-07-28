import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { checkDrillApiBaseline, loadDrillApiBaseline } from './snapshot-drill-api.mjs';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');

test('legacy drill snapshot covers exactly thirteen endpoints and their ID spaces', () => {
  const baseline = loadDrillApiBaseline();
  assert.equal(baseline.endpoints.length, 13);
  assert.equal(new Set(baseline.endpoints.map(({ path }) => path)).size, 13);
  assert.equal(baseline.entity_id_spaces.recording_id, 'drill_recordings.id');
  assert.equal(baseline.entity_id_spaces.analysis_id, 'script_analysis_records.id');
  assert.equal(baseline.entity_id_spaces.legacy_feedback_id, 'script_ai_feedback.id');
  const upload = baseline.endpoints.find(({ path }) => path.endsWith('/upload-recording.php'));
  assert.deepEqual(upload.output_ids, ['recording_id', 'analysis_id', 'legacy_feedback_id']);
});

test('legacy drill source signals match the captured migration baseline', () => {
  const { mismatches } = checkDrillApiBaseline();
  assert.deepEqual(mismatches, []);
});

test('baseline keeps the five blocking legacy chains visible during v2 work', () => {
  const baseline = loadDrillApiBaseline();
  const risks = new Set(baseline.endpoints.flatMap(({ known_risks }) => known_risks));
  for (const risk of [
    'recording_analysis_feedback_id_disconnect',
    'physical_public_path_mismatch',
    'script_id_space_collision',
    'duplicate_points_award',
    'concurrent_turn_duplication',
  ]) {
    assert.ok(risks.has(risk), risk);
  }

  assert.match(source('../api/drill/upload-recording.php'), /'feedback_id'\s*=>\s*\$analysisId/);
  assert.match(source('../mini-program/pages/drill/doing/doing.js'), /feedback\?id=\$\{feedback\.feedback_id/);
  assert.match(source('../mini-program/pages/drill/feedback/feedback.js'), /recording_id=\$\{this\.data\.feedbackId\}/);
  assert.match(source('../api/drill/analyze-script.php'), /FROM script_knowledge WHERE id = \?/);
  assert.match(source('../api/drill/step.php'), /awardDrillPoints\(/);
  assert.doesNotMatch(source('../api/drill/step.php'), /beginTransaction\(/);
  assert.match(source('../api/drill/free-chat.php'), /WHERE session_id = \? ORDER BY id ASC/);
});
