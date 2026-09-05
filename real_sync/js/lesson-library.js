(function () {
  'use strict';

  var state = { page: 1, pageSize: 12, total: 0 };
  var isPreviewHost = /\.monkeycode-ai\.online$/.test(window.location.hostname);
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (value) {
    var node = document.createElement('div');
    node.textContent = String(value == null ? '' : value);
    return node.innerHTML;
  };

  function authFetch(url, options) {
    if (window.AppAuth && window.AppAuth.authFetch) return window.AppAuth.authFetch(url, options);
    var requestOptions = Object.assign({ cache: 'no-store' }, options || {});
    requestOptions.headers = window.authHeaders ? window.authHeaders(requestOptions.headers || {}) : requestOptions.headers;
    return fetch(url, requestOptions);
  }

  async function request(url) {
    var response = await authFetch(url);
    var result = await response.json().catch(function () { return {}; });
    if (!response.ok || Number(result.code) !== 0) throw new Error(result.message || '请求失败');
    return result.data || {};
  }

  function text(value) {
    if (Array.isArray(value)) {
      return value.map(text).filter(Boolean).join('、');
    }
    if (value && typeof value === 'object') {
      return Object.values(value).map(text).filter(Boolean).join('、');
    }
    return String(value == null || value === '' ? '未填写' : value);
  }

  function formatDate(value) {
    if (!value) return '-';
    var parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString('zh-CN');
  }

  function status(message, type) {
    $('listStatus').textContent = message;
    $('listStatus').className = 'status-panel ' + (type || 'loading');
    $('listStatus').hidden = false;
  }

  function showList() {
    $('listView').hidden = false;
    $('detailView').hidden = true;
  }

  function showDetail() {
    $('listView').hidden = true;
    $('detailView').hidden = false;
  }

  function renderLessons(lessons) {
    if (!lessons.length) {
      $('lessonGrid').innerHTML = '';
      status('当前条件下暂无正式教案', 'empty');
      return;
    }
    $('listStatus').hidden = true;
    $('lessonGrid').innerHTML = lessons.map(function (lesson) {
      var route = String(lesson.canonical_route || '/lesson-library.html?id=' + Number(lesson.submission_id));
      return '<a class="lesson-card" href="' + esc(route) + '">' +
        '<div class="lesson-card-top"><span class="level-tag">' + esc(lesson.class_level || '未分级') + '</span><span>批准版本 V' + esc(lesson.version_no || '-') + '</span></div>' +
        '<h2>' + esc(lesson.title || '未命名教案') + '</h2>' +
        '<dl><div><dt>课程线</dt><dd>' + esc(lesson.course_line || '-') + '</dd></div><div><dt>上课日期</dt><dd>' + esc(lesson.lesson_date || '-') + '</dd></div><div><dt>门店</dt><dd>' + esc(lesson.store_name || '-') + '</dd></div></dl>' +
        '<div class="lesson-card-footer"><span>' + esc(lesson.author_name || '未知作者') + ' · ' + esc(formatDate(lesson.library_published_at)) + '</span><strong>查看批准教案</strong></div>' +
      '</a>';
    }).join('');
  }

  function renderPagination() {
    var hasPrevious = state.page > 1;
    var hasNext = state.page * state.pageSize < state.total;
    $('previousPage').disabled = !hasPrevious;
    $('nextPage').disabled = !hasNext;
    $('pageLabel').textContent = '第 ' + state.page + ' 页';
    $('pagination').hidden = state.total <= state.pageSize;
  }

  async function loadList() {
    showList();
    status('正在加载正式教案...', 'loading');
    $('lessonGrid').innerHTML = '';
    var query = new URLSearchParams({ page: String(state.page), page_size: String(state.pageSize) });
    var keyword = $('keywordInput').value.trim();
    var courseLine = $('courseLineFilter').value.trim();
    var classLevel = $('classLevelFilter').value.trim();
    if (keyword) query.set('q', keyword);
    if (courseLine) query.set('course_line', courseLine);
    if (classLevel) query.set('class_level', classLevel);
    try {
      var data = await request('/api/lesson-library/list.php?' + query.toString());
      state.total = Number(data.total || 0);
      state.page = Number(data.page || state.page);
      state.pageSize = Number(data.page_size || state.pageSize);
      $('resultCount').textContent = '共 ' + state.total + ' 份正式教案';
      renderLessons(Array.isArray(data.list) ? data.list : []);
      renderPagination();
      window.history.replaceState(null, '', '/lesson-library.html');
    } catch (error) {
      state.total = 0;
      $('resultCount').textContent = '正式教案库';
      $('pagination').hidden = true;
      if (isPreviewHost) {
        status('预览环境暂未连接正式教案服务，请在生产域名查看数据', 'error');
      } else {
        status('加载失败：' + (error.message || '请稍后重试'), 'error');
      }
    }
  }

  function contentCard(title, value, wide) {
    return '<article class="content-card' + (wide ? ' wide' : '') + '"><h3>' + esc(title) + '</h3><p>' + esc(text(value)) + '</p></article>';
  }

  function renderDetail(data) {
    var lesson = data.lesson || {};
    var version = data.approved_version || {};
    var content = version.content || {};
    var objectives = content.objectives || {};
    var safety = content.safety || {};
    var reflection = content.reflection || {};
    var phases = Array.isArray(content.phases) ? content.phases : [];

    $('detailTitle').textContent = lesson.title || '未命名教案';
    $('detailSubtitle').textContent = (lesson.course_line || '-') + ' · ' + (lesson.class_level || '-') + ' · ' + (lesson.store_name || '-');
    $('versionBadge').textContent = '批准版本 V' + (version.version_no || lesson.version_no || '-');
    $('detailMeta').innerHTML = [
      ['作者', lesson.author_name],
      ['上课日期', lesson.lesson_date],
      ['发布时间', formatDate(lesson.library_published_at)],
      ['版本状态', version.is_immutable ? '已冻结' : '-'],
    ].map(function (item) {
      return '<div><span>' + esc(item[0]) + '</span><strong>' + esc(item[1] || '-') + '</strong></div>';
    }).join('');
    $('lessonContent').innerHTML =
      contentCard('A · 运动能力', objectives.athletic) +
      contentCard('C · 认知能力', objectives.cognitive) +
      contentCard('E · 参与动能', objectives.engagement) +
      contentCard('重点学员', content.learner_focus, true) +
      contentCard('身体安全', safety.physical) +
      contentCard('心理安全', safety.psychological) +
      contentCard('器材', content.equipment) +
      '<article class="content-card wide"><h3>课程阶段</h3>' + (phases.length ? phases.map(function (phase) {
        return '<div class="phase"><strong>' + esc(phase.name || phase.title || '阶段') + '</strong><span>' + esc(phase.duration_minutes || phase.duration || 0) + ' 分钟</span><p>' + esc(phase.content || phase.description || '-') + '</p></div>';
      }).join('') : '<p>未填写</p>') + '</article>' +
      contentCard('升降阶方案', content.progressions) +
      contentCard('助教分工', content.assistant_responsibilities) +
      contentCard('ACE 反思', [reflection.athletic, reflection.cognitive, reflection.engagement].filter(Boolean), true);
  }

  async function loadDetail(id) {
    showDetail();
    $('detailTitle').textContent = '正在加载正式教案...';
    $('detailSubtitle').textContent = '';
    $('lessonContent').innerHTML = '';
    try {
      var data = await request('/api/lesson-library/detail.php?id=' + encodeURIComponent(id));
      renderDetail(data);
      var lesson = data.lesson || {};
      var route = lesson.canonical_route || '/lesson-library.html?id=' + encodeURIComponent(id);
      window.history.replaceState(null, '', route);
    } catch (error) {
      $('detailTitle').textContent = '正式教案加载失败';
      $('detailSubtitle').textContent = error.message || '请稍后重试';
    }
  }

  function init() {
    var detailId = Number(new URLSearchParams(window.location.search).get('id'));
    if (detailId > 0) return loadDetail(detailId);
    return loadList();
  }

  $('searchForm').addEventListener('submit', function (event) { event.preventDefault(); state.page = 1; loadList(); });
  $('resetFilters').addEventListener('click', function () { $('searchForm').reset(); state.page = 1; loadList(); });
  $('previousPage').addEventListener('click', function () { if (state.page > 1) { state.page -= 1; loadList(); } });
  $('nextPage').addEventListener('click', function () { if (state.page * state.pageSize < state.total) { state.page += 1; loadList(); } });
  $('backToList').addEventListener('click', function () { state.page = 1; loadList(); });
  window.addEventListener('popstate', init);
  window.requirePageAuth({ onAuthed: init });
}());
