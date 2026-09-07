(() => {
  const API_URL = '/api/knowledge/list.php';
  const STATIC_INDEX_URL = '/content-index.json';
  const PAGE_SIZE = 20;
  const isPreviewHost = /\.monkeycode-ai\.online$/.test(window.location.hostname);
  const taxonomy = {
    professional: [
      ['', '全部专业知识'], ['child_development', '儿童发展'], ['fitness', '运动与体能'], ['sensory', '感统'],
      ['action_game', '动作与游戏'], ['teaching', '教学法'], ['assessment', '体测与评估'], ['safety', '安全'],
      ['coach_growth', '教练成长'], ['lesson_reference', '教案参考']
    ],
    sales: [
      ['', '全部销售知识'], ['reception', '首次接待'], ['needs_analysis', '需求分析'], ['fitness_explanation', '体测沟通'],
      ['trial_class', '体验课'], ['parent_communication', '家长沟通'], ['objection_handling', '异议处理'], ['conversion', '成交'],
      ['renewal', '续费'], ['sales_script', '销售话术']
    ]
  };
  const typeNames = {
    knowledge_card: '知识卡', action: '动作', game: '游戏', script: '话术', case: '案例',
    lesson: '教案参考', training: '培训', fitness_guidance: '体测说明'
  };
  const state = { primaryCategory:'professional', topic:'', keyword:'', contentType:'', mode:'all', page:1, total:0, loading:false, staticMode:false };

  const elements = {};
  let requestSequence = 0;
  const renderedIds = new Set();

  function parseState() {
    const params = new URLSearchParams(window.location.search);
    state.primaryCategory = params.get('primary_category') === 'sales' ? 'sales' : 'professional';
    state.topic = params.get('topic') || '';
    state.keyword = params.get('keyword') || '';
    state.contentType = params.get('content_type') || '';
    state.ageCode = params.get('age_code') || '';
    state.mode = ['favorite', 'recent'].includes(params.get('mode')) ? params.get('mode') : 'all';
  }

  function captureElements() {
    elements.list = document.getElementById('knowledgeList');
    elements.count = document.getElementById('resultCount');
    elements.activeFilters = document.getElementById('activeFilters');
    elements.topicList = document.getElementById('topicList');
    elements.searchInput = document.getElementById('searchInput');
    elements.contentType = document.getElementById('contentType');
    elements.ageCode = document.getElementById('ageCode');
    elements.loadWrap = document.getElementById('loadWrap');
    elements.loadMore = document.getElementById('loadMore');
    elements.previewNote = document.getElementById('previewNote');
  }

  function bindEvents() {
    document.getElementById('searchForm').addEventListener('submit', (event) => {
      event.preventDefault();
      state.keyword = elements.searchInput.value.trim();
      refresh();
    });
    document.querySelectorAll('[data-primary-category]').forEach((button) => button.addEventListener('click', () => {
      state.primaryCategory = button.dataset.primaryCategory;
      state.topic = '';
      refresh();
    }));
    document.querySelectorAll('[data-mode]').forEach((button) => button.addEventListener('click', () => {
      state.mode = button.dataset.mode;
      refresh();
    }));
    elements.contentType.addEventListener('change', () => {
      state.contentType = elements.contentType.value;
      refresh();
    });
    elements.ageCode.addEventListener('change', () => {
      state.ageCode = elements.ageCode.value;
      refresh();
    });
    document.getElementById('clearFilters').addEventListener('click', () => {
      state.topic = '';
      state.keyword = '';
      state.contentType = '';
      state.ageCode = '';
      state.mode = 'all';
      refresh();
    });
    elements.loadMore.addEventListener('click', () => loadList(state.page + 1, true));
  }

  function syncControls() {
    elements.searchInput.value = state.keyword;
    elements.contentType.value = state.contentType;
    elements.ageCode.value = state.ageCode;
    document.querySelectorAll('[data-primary-category]').forEach((button) => button.classList.toggle('active', button.dataset.primaryCategory === state.primaryCategory));
    document.querySelectorAll('[data-mode]').forEach((button) => button.classList.toggle('active', button.dataset.mode === state.mode));
    const topics = taxonomy[state.primaryCategory];
    elements.topicList.innerHTML = topics.map(([value, label]) => `<button type="button" class="topic${value === state.topic ? ' active' : ''}" data-topic="${escapeHtml(value)}">${escapeHtml(label)}</button>`).join('');
    elements.topicList.querySelectorAll('[data-topic]').forEach((button) => button.addEventListener('click', () => {
      state.topic = button.dataset.topic;
      refresh();
    }));
  }

  function syncUrl() {
    const params = new URLSearchParams();
    params.set('primary_category', state.primaryCategory);
    if (state.topic) params.set('topic', state.topic);
    if (state.keyword) params.set('keyword', state.keyword);
    if (state.contentType) params.set('content_type', state.contentType);
    if (state.ageCode) params.set('age_code', state.ageCode);
    if (state.mode !== 'all') params.set('mode', state.mode);
    window.history.replaceState(null, '', `${window.location.pathname}?${params}`);
  }

  function buildApiUrl(page, state) {
    const params = new URLSearchParams({ page:String(page), page_size:String(PAGE_SIZE), primary_category:state.primaryCategory });
    if (state.keyword) params.set('keyword', state.keyword);
    if (state.topic) params.set('subcategory_code', state.topic);
    if (state.contentType) params.set('content_type', state.contentType);
    if (state.ageCode) params.set('age_code', state.ageCode);
    if (state.mode === 'favorite') params.set('favorite', '1');
    if (state.mode === 'recent') params.set('recent', '1');
    return `${API_URL}?${params}`;
  }

  async function loadList(page = 1, append = false) {
    if (append && (state.loading || state.staticMode || page <= state.page || elements.loadWrap.hidden)) return;
    const requestId = ++requestSequence;
    const snapshot = { ...state };
    state.loading = true;
    elements.loadMore.disabled = true;
    if (!append) {
      elements.list.innerHTML = '<div class="state">加载中...</div>';
      elements.loadWrap.hidden = true;
    }
    try {
      const request = window.authFetch
        ? window.authFetch(buildApiUrl(page, snapshot))
        : fetch(buildApiUrl(page, snapshot), { headers:window.authHeaders ? window.authHeaders() : {} });
      const response = await request;
      const payload = await response.json();
      if (requestId !== requestSequence) return;
      if (!response.ok || Number(payload.code) !== 0) throw new Error(payload.message || 'knowledge_request_failed');
      state.staticMode = false;
      state.page = page;
      state.total = Number(payload.data.total || 0);
      renderList(payload.data.list || [], append);
      elements.count.textContent = `共 ${state.total} 条`;
      renderActiveFilters();
      elements.loadWrap.hidden = page * Number(payload.data.page_size || PAGE_SIZE) >= state.total;
      elements.previewNote.hidden = true;
    } catch (error) {
      if (requestId !== requestSequence) return;
      if (isPreviewHost && !append) {
        await loadPublishedStaticIndex(requestId, snapshot);
      } else if (!append) {
        renderState('暂时无法加载知识', '请稍后重试');
        elements.count.textContent = '加载失败';
      } else {
        elements.loadMore.textContent = '加载失败，点击重试';
      }
    } finally {
      if (requestId === requestSequence) {
        state.loading = false;
        elements.loadMore.disabled = false;
      }
    }
  }

  async function loadPublishedStaticIndex(requestId, state) {
    try {
      const response = await fetch(STATIC_INDEX_URL, { cache:'no-store' });
      const index = await response.json();
      if (requestId !== requestSequence) return;
      const term = state.keyword.toLowerCase();
      const list = index.filter((item) => {
        if (item.publication_status !== 'published' || item.primary_category !== state.primaryCategory) return false;
         if (state.contentType && item.content_type !== state.contentType) return false;
         if (state.ageCode && !(item.age_codes || []).includes(state.ageCode)) return false;
         if (state.topic && item.subcategory_code !== state.topic) return false;
        if (state.mode !== 'all') return false;
        const text = [item.title, item.summary, ...(item.keywords || [])].join(' ').toLowerCase();
        return !term || text.includes(term);
      });
      setStaticResult(list.length);
      renderList(list, false);
      elements.count.textContent = `已发布入口 ${list.length} 条`;
      renderActiveFilters();
      elements.previewNote.hidden = false;
      elements.loadWrap.hidden = true;
    } catch (error) {
      if (requestId !== requestSequence) return;
      renderState('暂时无法加载知识', '请稍后重试');
      elements.count.textContent = '加载失败';
    }
  }

  function setStaticResult(total) {
    state.staticMode = true;
    state.page = 1;
    state.total = total;
  }

  function renderList(items, append) {
    if (!append) renderedIds.clear();
    items = items.filter((item) => {
      const id = String(item.id || item.canonical_url);
      if (renderedIds.has(id)) return false;
      renderedIds.add(id);
      return true;
    });
    elements.loadMore.textContent = '加载更多';
    if (!items.length && !append) {
      renderState('暂无匹配内容', state.mode === 'favorite' ? '这里会显示收藏的知识' : state.mode === 'recent' ? '这里会显示最近浏览的知识' : '调整分类或搜索词后重试');
      return;
    }
    const html = items.map(renderCard).join('');
    if (append) elements.list.insertAdjacentHTML('beforeend', html);
    else elements.list.innerHTML = html;
  }

  function renderCard(item) {
    const isStatic = Boolean(item.canonical_url);
    const href = isStatic
      ? safeInternalPath(item.canonical_url)
      : `/knowledge/detail.html?id=${encodeURIComponent(String(item.id || ''))}&primary_category=${encodeURIComponent(state.primaryCategory)}`;
    const lineLabel = item.primary_category_label || (item.primary_category === 'sales' ? '销售知识' : '专业知识');
    const tags = [lineLabel, item.subcategory_label, typeNames[item.content_type || item.category_type] || item.content_type, ...(item.age_labels || [])].filter(Boolean);
    return `<a class="knowledge-card" href="${escapeHtml(href)}">
      <div class="card-top"><h2 class="card-title">${escapeHtml(item.title || '未命名知识')}</h2>${Number(item.is_favorite || 0) ? '<span class="favorite">已收藏</span>' : ''}</div>
      <p class="card-summary">${escapeHtml(item.summary || '')}</p>
      <div class="tags">${tags.map((tag, index) => `<span class="tag${index === 0 ? ' line' : ''}">${escapeHtml(tag)}</span>`).join('')}</div>
      <div class="card-foot"><span>${isStatic ? '已发布内容入口' : item.updated_at ? `更新 ${escapeHtml(item.updated_at)}` : '知识内容'}</span><span>查看</span></div>
    </a>`;
  }

  function safeInternalPath(value) {
    const path = String(value || '');
    return path.startsWith('/') && !path.startsWith('//') && !path.includes('..') ? path : '/knowledge/';
  }

  function renderState(title, detail) {
    elements.list.innerHTML = `<div class="state"><strong>${escapeHtml(title)}</strong>${escapeHtml(detail)}</div>`;
  }

  function renderActiveFilters() {
    const filters = [];
    if (state.keyword) filters.push(`搜索：${state.keyword}`);
    if (state.contentType) filters.push(`类型：${typeNames[state.contentType] || state.contentType}`);
    if (state.ageCode) {
      const option = Array.from(elements.ageCode.options).find((item) => item.value === state.ageCode);
      filters.push(`年龄：${option ? option.textContent : state.ageCode}`);
    }
    if (state.topic) {
      const topic = taxonomy[state.primaryCategory].find(([value]) => value === state.topic);
      filters.push(`分类：${topic ? topic[1] : state.topic}`);
    }
    elements.activeFilters.textContent = filters.length ? ` · ${filters.join(' · ')}` : '';
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  async function refresh() {
    state.page = 0;
    state.total = 0;
    syncControls();
    syncUrl();
    await loadList(1, false);
  }

  async function init() {
    parseState();
    captureElements();
    bindEvents();
    await refresh();
  }

  window.addEventListener('DOMContentLoaded', () => {
    if (typeof window.requirePageAuth === 'function') window.requirePageAuth({ onAuthed:init });
    else init();
  });
})();
