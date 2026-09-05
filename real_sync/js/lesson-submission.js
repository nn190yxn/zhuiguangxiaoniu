(function () {
  'use strict';

  var state = { submission: null, version: null, content: {}, dirty: false, locked: false, authenticatedAuthorName: '' };
  var editableStatuses = ['draft', 'editable', 'returned', 'parse_failed'];
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (value) { var node = document.createElement('div'); node.textContent = String(value == null ? '' : value); return node.innerHTML; };
  var attr = function (value) { return esc(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };

  function authFetch(url, options) {
    if (window.AppAuth && window.AppAuth.authFetch) return window.AppAuth.authFetch(url, options);
    var requestOptions = Object.assign({}, options || {});
    requestOptions.headers = window.authHeaders ? window.authHeaders(requestOptions.headers || {}) : requestOptions.headers;
    return fetch(url, requestOptions);
  }
  async function request(url, options) {
    var response = await authFetch(url, options || {});
    var result = await response.json().catch(function () { return {}; });
    if (!response.ok || Number(result.code) !== 0) throw new Error(result.message || '请求失败');
    return result.data || {};
  }
  function post(url, body) {
    return request(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) }, body: JSON.stringify(body) });
  }
  function notify(message, bad) {
    $('notice').textContent = message; $('notice').className = 'notice show' + (bad ? ' bad' : '');
    clearTimeout(notify.timer); notify.timer = setTimeout(function () { $('notice').className = 'notice'; }, 4500);
  }
  function valueAt(object, path) { return path.split('.').reduce(function (value, key) { return value && value[key] != null ? value[key] : ''; }, object); }
  function listText(value) { return Array.isArray(value) ? value.map(function (item) { return typeof item === 'object' ? (item.name || item.value || item.title || '') : item; }).filter(Boolean).join('\n') : String(value || ''); }
  function lineList(value) { return String(value || '').split(/\r?\n|,|，|、|;|；/).map(function (item) { return item.trim(); }).filter(Boolean); }

  function setBusy(busy) { document.body.classList.toggle('loading', busy); }
  function setDirty() { state.dirty = true; }
  function fillEditor(content) {
    state.content = content || {};
    document.querySelectorAll('[data-path]').forEach(function (input) {
      var value = valueAt(state.content, input.dataset.path);
      input.value = ['equipment', 'progressions'].includes(input.dataset.path) ? listText(value) : String(value || '');
    });
    renderPhases(Array.isArray(state.content.phases) ? state.content.phases : []);
    state.dirty = false;
  }
  function readContent() {
    var content = JSON.parse(JSON.stringify(state.content || {})); content.metadata = content.metadata || {};
    document.querySelectorAll('[data-path]').forEach(function (input) {
      var keys = input.dataset.path.split('.'); var target = content;
      keys.slice(0, -1).forEach(function (key) { target[key] = target[key] || {}; target = target[key]; });
      target[keys[keys.length - 1]] = ['equipment', 'progressions'].includes(input.dataset.path) ? lineList(input.value) : input.value.trim();
    });
    content.phases = Array.from(document.querySelectorAll('.phase')).map(function (row) { return { name: row.querySelector('[data-phase="name"]').value.trim(), duration_minutes: Number(row.querySelector('[data-phase="duration"]').value || 0), content: row.querySelector('[data-phase="content"]').value.trim() }; }).filter(function (phase) { return phase.name || phase.content; });
    return content;
  }
  function renderPhases(phases) {
    $('phaseList').innerHTML = phases.length ? phases.map(function (phase, index) { return '<div class="phase" data-index="' + index + '"><input data-phase="name" aria-label="阶段名称" placeholder="阶段名称" value="' + attr(phase.name || phase.title) + '"><input data-phase="duration" aria-label="分钟" type="number" min="0" placeholder="分钟" value="' + attr(phase.duration_minutes || phase.duration || '') + '"><button class="btn ghost remove-phase" type="button">移除</button><textarea data-phase="content" aria-label="阶段内容" placeholder="教学内容、组织方式和观察重点">' + esc(phase.content || phase.description) + '</textarea></div>'; }).join('') : '<div class="empty">尚未添加课程阶段</div>';
  }
  function addPhase() { var phases = readContent().phases || []; phases.push({ name: '', duration_minutes: '', content: '' }); renderPhases(phases); setDirty(); }
  function statusLabel(status) { return { draft: '草稿', parsing: '解析中', completed: '解析完成', failed: '解析失败', parse_failed: '解析失败', editable: '可编辑', submitted: '待店长初审', store_approved: '待教学主管终审', final_approved: '已入正式教案库', returned: '已退回' }[status] || status || '未知'; }

  async function loadDetail(id) {
    var data = await request('/api/lesson-submissions/detail.php?id=' + encodeURIComponent(id));
    state.submission = data.submission || data; state.version = data.current_version || data.version || null;
    state.locked = !editableStatuses.includes(state.submission.status);
    $('setup').style.display = 'none'; $('workspace').classList.add('active'); $('statusBadge').textContent = statusLabel(state.submission.status);
    $('editorTitle').textContent = state.submission.title || '结构化教案';
    $('versionText').textContent = state.version ? '版本 V' + (state.version.version_no || '-') : '等待结构化版本';
    $('lockMessage').innerHTML = state.locked ? '<div class="readonly">当前审核状态只读</div>' : '';
    fillEditor(state.version && (state.version.content || state.version.content_json) || {});
    document.querySelectorAll('#workspace input,#workspace textarea,#workspace button').forEach(function (control) { if (control.id !== 'validateButton') control.disabled = state.locked; });
    renderSources(data.source_files || data.files || [], data.parse_runs || []);
    renderSuggestions(data.suggestions || []);
    renderVersionOptions(data.versions || []);
    history.replaceState(null, '', '/lesson-submission.html?id=' + encodeURIComponent(id));
  }
  function renderSources(files, runs) {
    var latestRun = runs[0] || {}; $('parseStatus').textContent = latestRun.status ? statusLabel(latestRun.status) : '';
    $('sourceFiles').innerHTML = files.length ? files.map(function (file) { return '<div class="source-file"><strong>' + esc(file.original_name) + '</strong><div class="cite">' + esc(file.extension || file.mime_type || '') + ' · ' + Math.max(1, Math.round(Number(file.byte_size || 0) / 1024)) + ' KB</div></div>'; }).join('') : '<div class="empty">暂无原始文件</div>';
    if (latestRun.error_message) $('sourceFiles').insertAdjacentHTML('beforeend', '<div class="finding error"><strong>解析提示</strong><div>' + esc(latestRun.error_message) + '</div><div class="cite">原文件已保留，可直接手工录入</div></div>');
  }
  function renderFindings(findings) {
    $('findingCount').textContent = findings.length + ' 项';
    $('findings').innerHTML = findings.length ? findings.map(function (item) { return '<div class="finding ' + attr(item.severity || 'warning') + '" data-target="' + attr(item.field_path || item.field || '') + '"><strong>' + esc(item.title || item.rule_name || '检查提示') + '</strong><div>' + esc(item.message || item.description || '') + '</div></div>'; }).join('') : '<div class="empty">ACE 结构完整，暂未发现缺项</div>';
  }
  function renderSuggestions(suggestions) {
    $('suggestions').innerHTML = suggestions.length ? suggestions.map(function (item) { var refs = item.citations || item.references || []; var disabled = state.locked || item.decision !== 'pending' || Number(item.version_id) !== Number(state.version && state.version.id); return '<div class="suggestion" data-suggestion-id="' + attr(item.id) + '" data-field-path="' + attr(item.field_path || '') + '"><strong>' + esc(item.title || item.dimension || item.suggestion_type || '优化建议') + '</strong><div>' + esc(item.suggestion || item.content || item.message || '') + '</div><div class="cite">' + esc(refs.map(function (ref) { return ref.title || ref.card_title || ref; }).join(' · ') || item.knowledge_item_title || item.citation_text || 'ACE 教学规范') + '</div><div class="actions"><button class="btn primary suggestion-action" data-decision="accepted" type="button"' + (disabled ? ' disabled' : '') + '>采纳</button><button class="btn ghost suggestion-action" data-decision="ignored" type="button"' + (disabled ? ' disabled' : '') + '>' + (item.decision === 'ignored' ? '已忽略' : '忽略') + '</button><small class="readonly">V' + esc(item.version_id || '-') + ' · ' + esc(item.decision || 'pending') + '</small></div></div>'; }).join('') : '<div class="empty">当前版本暂无优化建议</div>';
  }
  function flatten(value, prefix, output) { if (Array.isArray(value)) return value.forEach(function (item, index) { flatten(item, prefix + '[' + index + ']', output); }); if (value && typeof value === 'object') return Object.keys(value).forEach(function (key) { flatten(value[key], prefix ? prefix + '.' + key : key, output); }); output[prefix] = String(value == null ? '' : value); }
  function renderVersionOptions(versions) { state.versions = versions || []; var options = state.versions.map(function (version) { return '<option value="' + attr(version.id) + '">V' + esc(version.version_no) + ' · ' + esc(version.version_type || 'version') + ' · ' + esc(version.created_at || '') + '</option>'; }).join(''); $('compareFrom').innerHTML = options; $('compareTo').innerHTML = options; if (state.versions.length > 1) { $('compareFrom').selectedIndex = 1; $('compareTo').selectedIndex = 0; } renderDiff(); }
  function renderDiff() { var from = state.versions.find(function (version) { return String(version.id) === $('compareFrom').value; }); var to = state.versions.find(function (version) { return String(version.id) === $('compareTo').value; }); if (!from || !to || from.id === to.id) { $('versionDiff').innerHTML = '<div class="empty">选择两个不同版本后显示差异</div>'; return; } var before = {}; var after = {}; flatten(from.content_json || from.content || {}, '', before); flatten(to.content_json || to.content || {}, '', after); var keys = Array.from(new Set(Object.keys(before).concat(Object.keys(after)))).filter(function (key) { return before[key] !== after[key]; }); $('versionDiff').innerHTML = keys.length ? keys.map(function (key) { return '<div class="finding warning"><strong>' + esc(key) + '</strong><div>旧：' + esc(before[key] || '空') + '</div><div>新：' + esc(after[key] || '空') + '</div></div>'; }).join('') : '<div class="empty">两个版本内容一致</div>'; }

  async function createAndParse() {
    var file = $('sourceFile').files[0];
    var fields = { store_name: $('createStore').value.trim(), author_name: state.authenticatedAuthorName, course_line: $('createCourse').value.trim(), class_level: $('createLevel').value.trim(), lesson_date: $('createDate').value, title: $('createTitle').value.trim() };
    if (Object.keys(fields).some(function (key) { return !fields[key]; })) throw new Error('请填写全部教案信息');
    if (!file) throw new Error('请选择原始教案文件');
    if (!/\.(xlsx|xls|docx|doc)$/i.test(file.name)) throw new Error('请选择 XLSX、XLS、DOCX 或 DOC 文件');
    var created = await post('/api/lesson-submissions/create.php', fields); var id = created.id || created.submission_id;
    var body = new FormData(); body.append('submission_id', id); body.append('file', file);
    var uploaded = await request('/api/lesson-submissions/upload.php', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) }, body: body });
    var parsed = await post('/api/lesson-submissions/parse.php', { submission_id: id, source_file_id: uploaded.id });
    if (parsed.status === 'parse_failed') { await post('/api/lesson-submissions/manual-entry.php', { submission_id: id }); notify(parsed.error_message || '文件解析失败，已进入手工录入模式', true); }
    await loadDetail(id);
  }
  async function saveDraft(silent) {
    var data = await post('/api/lesson-submissions/draft.php', { submission_id: state.submission.id, status_version: Number(state.submission.status_version), content: readContent() });
    state.submission.status_version = data.status_version || state.submission.status_version + 1; state.dirty = false;
    if (!silent) notify('草稿已保存');
    await loadDetail(state.submission.id);
  }
  async function validate() {
    var data = await post('/api/lesson-submissions/validate.php', { submission_id: state.submission.id, content: readContent() });
    renderFindings(data.findings || data.issues || []); notify('检查完成');
  }
  async function optimize() {
    if (state.dirty) throw new Error('请先保存当前修改，再刷新优化建议');
    var data = await post('/api/lesson-submissions/optimize.php', { submission_id: state.submission.id, version_id: state.version && state.version.id });
    renderSuggestions(data.suggestions || []); notify('优化建议已刷新');
  }
  async function exportLesson(format) {
    if (state.dirty) throw new Error('请先保存当前修改，再导出教案');
    if (!state.version) throw new Error('当前没有可导出的结构化版本');
    var result = await post('/api/lesson-submissions/export.php', { submission_id: state.submission.id, version_id: state.version.id, format: format });
    notify((format === 'xlsx' ? 'Excel' : 'Word') + ' 已生成，正在下载');
    var link = document.createElement('a'); link.href = result.download_url; link.download = ''; document.body.appendChild(link); link.click(); link.remove();
  }
  function setAt(target, path, value) { var keys = path.split('.'); var current = target; keys.slice(0, -1).forEach(function (key, index) { var nextKey = keys[index + 1]; if (current[key] == null) current[key] = /^\d+$/.test(nextKey) ? [] : {}; current = current[key]; }); current[keys[keys.length - 1]] = value; }
  async function decideSuggestion(card, decision) { var suggestionId = Number(card.dataset.suggestionId); var content = readContent(); var fieldPath = card.dataset.fieldPath; if (decision === 'accepted' && fieldPath) { var current = valueAt(content, fieldPath); var message = card.querySelector('.cite').previousElementSibling.textContent.trim(); setAt(content, fieldPath, Array.isArray(current) ? current.concat(message) : [current, message].filter(Boolean).join('\n')); } var result = await post('/api/lesson-submissions/suggestion-decision.php', { submission_id: state.submission.id, suggestion_id: suggestionId, decision: decision, content: decision === 'accepted' ? content : undefined, status_version: Number(state.submission.status_version) }); if (decision === 'accepted') state.submission.status_version = result.status_version; notify(decision === 'accepted' ? '建议已采纳并生成新草稿版本' : '建议已忽略'); await loadDetail(state.submission.id); }
  async function run(action) { try { setBusy(true); await action(); } catch (error) { notify(error.message || '操作失败', true); } finally { setBusy(false); } }

  $('sourceFile').addEventListener('change', function () { $('fileLabel').textContent = this.files[0] ? this.files[0].name : '选择或拖入原始教案'; });
  ['dragenter', 'dragover'].forEach(function (name) { $('dropzone').addEventListener(name, function (event) { event.preventDefault(); $('dropzone').classList.add('drag'); }); });
  ['dragleave', 'drop'].forEach(function (name) { $('dropzone').addEventListener(name, function (event) { event.preventDefault(); $('dropzone').classList.remove('drag'); if (name === 'drop' && event.dataTransfer.files[0]) { $('sourceFile').files = event.dataTransfer.files; $('fileLabel').textContent = event.dataTransfer.files[0].name; } }); });
  $('startButton').addEventListener('click', function () { run(createAndParse); }); $('saveButton').addEventListener('click', function () { run(function () { return saveDraft(false); }); }); $('validateButton').addEventListener('click', function () { run(validate); }); $('optimizeButton').addEventListener('click', function () { run(optimize); }); $('exportXlsxButton').addEventListener('click', function () { run(function () { return exportLesson('xlsx'); }); }); $('exportDocxButton').addEventListener('click', function () { run(function () { return exportLesson('docx'); }); }); $('addPhase').addEventListener('click', addPhase);
  $('workspace').addEventListener('input', setDirty); $('phaseList').addEventListener('click', function (event) { if (!event.target.classList.contains('remove-phase')) return; event.target.closest('.phase').remove(); if (!$('phaseList').querySelector('.phase')) renderPhases([]); setDirty(); });
  $('suggestions').addEventListener('click', function (event) { var button = event.target.closest('.suggestion-action'); if (!button) return; run(function () { return decideSuggestion(button.closest('.suggestion'), button.dataset.decision); }); }); $('compareFrom').addEventListener('change', renderDiff); $('compareTo').addEventListener('change', renderDiff);
  $('findings').addEventListener('click', function (event) { var item = event.target.closest('[data-target]'); if (!item) return; var input = document.querySelector('[data-path="' + CSS.escape(item.dataset.target) + '"]'); if (input) { input.focus(); input.scrollIntoView({ behavior: 'smooth', block: 'center' }); } });
  window.addEventListener('beforeunload', function (event) { if (state.dirty) { event.preventDefault(); event.returnValue = ''; } });
  window.requirePageAuth({ onAuthed: function (user) { var identity = window.InternalAuth.adaptUserIdentity(user); state.authenticatedAuthorName = identity.name; $('createAuthor').value = identity.name; $('createDate').value = new Date().toISOString().slice(0, 10); var id = new URLSearchParams(location.search).get('id'); if (id) run(function () { return loadDetail(id); }); } });
}());
