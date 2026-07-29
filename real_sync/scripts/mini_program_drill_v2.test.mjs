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
const source = `${client}\n${feedback}\n${records}\n${knowledge}`;

for (const endpoint of ['home.php', 'catalog.php', 'assignments.php', 'attempts.php', 'turns.php', 'audio-assets.php', 'audio-chunks.php', 'turns/finalize.php', 'results.php', 'learning.php', 'progress.php']) {
  assert.match(source, new RegExp(endpoint.replace('.', '\\.')));
}
assert.match(client, /Idempotency-Key/);
assert.match(client, /sha256/);
assert.match(client, /drill_v2_active_attempt/);
assert.match(app, /"工作量"/);
assert.match(app, /"演练"/);
assert.match(app, /"我的"/);
assert.match(doing, /textFallbackAvailable/);
assert.match(list, /minimum_client_version/);
assert.doesNotMatch(doing, /wx\.request|wx\.uploadFile|\/drill\/analyze-script/);

console.log('mini_program_drill_v2.test.mjs passed');
