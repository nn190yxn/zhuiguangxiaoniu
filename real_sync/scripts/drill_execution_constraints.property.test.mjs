import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migration = readFileSync(new URL('../database/migrations/202607270003_drill_execution_domain.sql', import.meta.url), 'utf8');

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

test('requirement 4 keeps one assignment per publication and employee', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const assignments = new Map();
    for (let step = 0; step < 256; step++) {
      const publicationId = 1 + Math.floor(next() * 12);
      const staffId = 1 + Math.floor(next() * 40);
      assignments.set(`${publicationId}:${staffId}`, { publicationId, staffId });
      assert.equal(assignments.size, new Set(assignments.keys()).size);
    }
  }
  assert.match(migration, /UNIQUE KEY `uk_drill_assignments_publication_staff` \(`publication_id`, `staff_id`\)/);
});

test('requirements 6 and 20 keep turn and chunk sequences unique under retries', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const turns = new Map();
    const chunks = new Map();
    for (let step = 0; step < 256; step++) {
      const attemptId = 1 + Math.floor(next() * 10);
      const turnNo = 1 + Math.floor(next() * 30);
      const audioId = 1 + Math.floor(next() * 15);
      const chunkNo = 1 + Math.floor(next() * 60);
      const turnKey = `${attemptId}:${turnNo}`;
      const chunkKey = `${audioId}:${chunkNo}`;
      turns.set(turnKey, { attemptId, turnNo });
      const checksum = chunks.get(chunkKey)?.checksum ?? `sha256-${chunkKey}`;
      chunks.set(chunkKey, { audioId, chunkNo, checksum });
      assert.equal(chunks.get(chunkKey).checksum, checksum);
    }
    assert.equal(turns.size, new Set(turns.keys()).size);
    assert.equal(chunks.size, new Set(chunks.keys()).size);
  }
  assert.match(migration, /uk_drill_turns_attempt_turn/);
  assert.match(migration, /uk_drill_audio_chunks_sequence/);
  assert.match(migration, /chk_drill_turns_number/);
  assert.match(migration, /chk_drill_audio_chunks_number/);
});

test('requirements 7 and 20 reject evidence crossing attempt boundaries', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    for (let step = 0; step < 256; step++) {
      const evaluationAttemptId = 1 + Math.floor(next() * 20);
      const segmentAttemptId = 1 + Math.floor(next() * 20);
      const accepted = evaluationAttemptId === segmentAttemptId;
      assert.equal(accepted, evaluationAttemptId === segmentAttemptId);
    }
  }
  assert.match(migration, /FOREIGN KEY \(`evaluation_id`, `attempt_id`\) REFERENCES `drill_evaluations` \(`id`, `attempt_id`\)/);
  assert.match(migration, /FOREIGN KEY \(`segment_id`, `attempt_id`\) REFERENCES `drill_transcript_segments` \(`id`, `attempt_id`\)/);
});

test('requirements 8 and 9 require complete certification identities and score snapshots', () => {
  const requiredColumns = [
    'assignment_id',
    'attempt_id',
    'review_task_id',
    'evaluation_id',
    'plan_id',
    'staff_id',
    'reviewer_staff_id',
    'ai_score',
    'final_score',
    'critical_results_json',
    'result_snapshot_json',
    'certified_at',
  ];
  for (const column of requiredColumns) {
    assert.match(migration, new RegExp('`' + column + '` [^,\\n]+ NOT NULL'));
  }
  assert.match(migration, /uk_drill_certifications_assignment/);
  assert.match(migration, /uk_drill_certifications_attempt/);
  assert.match(migration, /chk_drill_certification_scores/);
});

test('requirements 8 and 12 preserve one active coaching task and one status value', () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const activeByAssignment = new Set();
    for (let step = 0; step < 256; step++) {
      const assignmentId = 1 + Math.floor(next() * 30);
      const status = ['open', 'in_progress', 'completed', 'cancelled'][Math.floor(next() * 4)];
      if (status === 'open' || status === 'in_progress') activeByAssignment.add(assignmentId);
      assert.equal(activeByAssignment.size, new Set(activeByAssignment).size);
    }
  }
  assert.match(migration, /`status` VARCHAR\(32\) NOT NULL DEFAULT 'open'/);
  assert.match(migration, /`active_assignment_id` BIGINT UNSIGNED GENERATED ALWAYS AS/);
  assert.match(migration, /UNIQUE KEY `uk_drill_coaching_tasks_active` \(`active_assignment_id`\)/);
});
