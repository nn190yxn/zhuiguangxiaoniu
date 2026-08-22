import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const root = new URL('../mini-program/', import.meta.url);
const read = path => readFile(new URL(path, root), 'utf8');
const client = await read('utils/drill-v2.js');
const app = await read('app.json');
const doing = await read('pages/drill/doing/doing.js');
const list = await read('pages/drill/list/list.js');
const feedback = await read('pages/drill/feedback/feedback.js');
const records = await read('pages/drill/script-records/script-records.js');
const knowledge = await read('pages/drill/script-knowledge/script-knowledge.js');
const matrix = await read('../mini-program/business-domain-matrix.json');
const source = `${client}\n${feedback}\n${records}\n${knowledge}`;

for (const endpoint of ['home.php', 'catalog.php', 'assignments.php', 'attempts.php', 'turns.php', 'audio-assets.php', 'audio-chunks.php', 'turns/finalize.php', 'audio-recovery.php', 'attempt-status.php', 'results.php', 'learning.php', 'progress.php']) {
  assert.match(source, new RegExp(endpoint.replace('.', '\\.')));
}
assert.match(client, /Idempotency-Key/);
assert.match(client, /sha256/);
assert.match(client, /drill_v2_active_attempt/);
assert.match(client, /isRetryPending/);
assert.match(client, /recoverAudioTranscription/);
assert.match(matrix, /\/drill\/v2\/learning\.php/);
assert.match(matrix, /\/drill\/v2\/audio-recovery\.php/);
assert.match(app, /"工作量"/);
assert.match(app, /"演练"/);
assert.match(app, /"我的"/);
assert.match(doing, /textFallbackAvailable/);
assert.match(doing, /scheduleStatusPoll/);
assert.match(doing, /loadAttemptStatus/);
assert.match(source, /retry_pending/);
assert.match(doing, /音频分析失败/);
assert.match(feedback, /utils\/media/);
assert.match(feedback, /normalizeMediaFields/);
assert.match(feedback, /getPlayableTempFile/);
assert.match(feedback, /audio_url_media/);
assert.match(feedback, /destroyAudio/);
assert.match(feedback, /clearMediaCache/);
assert.match(list, /minimum_client_version/);
assert.doesNotMatch(doing, /wx\.request|wx\.uploadFile|\/drill\/analyze-script/);

console.log('mini_program_drill_v2.test.mjs passed');
