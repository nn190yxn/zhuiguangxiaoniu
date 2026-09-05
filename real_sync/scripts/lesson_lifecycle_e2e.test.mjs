import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import test from 'node:test';

const projectRoot = resolve(import.meta.dirname, '..');
const harness = join(projectRoot, 'scripts', 'lesson_lifecycle_e2e.php');

function runHarness(args) {
  const result = spawnSync('php', [harness, ...args], {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: 20_000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

async function availablePort() {
  const server = createServer();
  await new Promise((resolveListen, reject) => server.once('error', reject).listen(0, '127.0.0.1', resolveListen));
  const { port } = server.address();
  await new Promise((resolveClose, reject) => server.close((error) => error ? reject(error) : resolveClose()));
  return port;
}

async function waitForServer(url, process) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    if (process.exitCode !== null) throw new Error(`PHP 上传服务提前退出，退出码 ${process.exitCode}`);
    try {
      const response = await fetch(url);
      if (response.status === 404) return;
    } catch {
      // The bounded retry loop waits for the local test server to accept requests.
    }
    await new Promise((resolveWait) => setTimeout(resolveWait, 50));
  }
  throw new Error('PHP 上传服务启动超时');
}

test('教案从创建到归档的生产服务链路保持一致', { timeout: 30_000 }, async () => {
  const tempRoot = mkdtempSync(join(tmpdir(), 'lesson-lifecycle-e2e-'));
  const dbPath = join(tempRoot, 'lesson.sqlite');
  const fixturePath = join(tempRoot, 'lesson.docx');
  const storageRoot = join(tempRoot, 'private-files');
  let server;
  let stderr = '';

  try {
    const setup = runHarness(['setup', dbPath, fixturePath, storageRoot]);
    assert.deepEqual(
      { status: setup.status, status_version: setup.status_version },
      { status: 'draft', status_version: 1 },
    );

    const port = await availablePort();
    server = spawn('php', ['-S', `127.0.0.1:${port}`, harness], {
      cwd: projectRoot,
      env: {
        ...process.env,
        LESSON_E2E_DB: dbPath,
        LESSON_E2E_STORAGE: storageRoot,
        LESSON_E2E_SUBMISSION: String(setup.submission_id),
      },
      stdio: ['ignore', 'ignore', 'pipe'],
    });
    server.stderr.setEncoding('utf8');
    server.stderr.on('data', (chunk) => { stderr += chunk; });
    await waitForServer(`http://127.0.0.1:${port}/ready`, server);

    const form = new FormData();
    form.append(
      'lesson_file',
      new Blob([readFileSync(fixturePath)], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }),
      'lesson.docx',
    );
    const uploadResponse = await fetch(`http://127.0.0.1:${port}/upload`, { method: 'POST', body: form });
    const upload = await uploadResponse.json();
    assert.equal(uploadResponse.status, 200, `${JSON.stringify(upload)}\n${stderr}`);
    assert.equal(upload.submission_id, setup.submission_id);
    assert.equal(upload.mime_type, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    assert.match(upload.sha256, /^[a-f0-9]{64}$/);

    server.kill('SIGTERM');
    await new Promise((resolveExit) => server.once('exit', resolveExit));
    server = undefined;

    const completed = runHarness(['complete', dbPath, storageRoot, String(setup.submission_id), String(upload.id)]);
    assert.deepEqual(completed.states, {
      created: 'draft',
      parsed: 'editable',
      edited: 'editable',
      submitted: 'store_review',
      store_approved: 'supervisor_review',
      supervisor_approved: 'approved',
      archived: 'archived',
    });
    assert.deepEqual(
      {
        status: completed.final_state.status,
        status_version: completed.final_state.status_version,
        library_status: completed.final_state.library_status,
      },
      { status: 'archived', status_version: 7, library_status: 'archived' },
    );
    assert.equal(completed.final_state.current_version_id, completed.final_state.approved_version_id);
    assert.equal(completed.published_item.canonical_route, `/lesson-library.html?id=${setup.submission_id}`);
    assert.deepEqual(completed.history_counts, {
      lesson_versions: 3,
      lesson_source_files: 1,
      lesson_parse_runs: 1,
      lesson_review_tasks: 2,
      lesson_audit_logs: 8,
    });
  } finally {
    if (server && server.exitCode === null) {
      server.kill('SIGTERM');
      await new Promise((resolveExit) => server.once('exit', resolveExit));
    }
    rmSync(tempRoot, { recursive: true, force: true });
  }
});
