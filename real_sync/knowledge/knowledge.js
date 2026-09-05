(() => {
  const API_URL = '/api/knowledge/list.php';
  const STATIC_INDEX_URL = '/content-index.json';
  const PAGE_SIZE = 20;
  const isPreviewHost = /\.monkeycode-ai\.online$/.test(window.location.hostname);
  const taxonomy = {
    professional: [
      ['', '全部专业知识'], ['儿童发展', '儿童发展'], ['体能', '运动与体能'], ['感统', '感统'],
      ['动作', '动作与游戏'], ['教学', '教学法'], ['体测', '体测与评估'], ['安全', '安全'],
      ['教练', '教练成长'], ['教案', '教案参考']
    ],
    sales: [
      ['', '全部销售知识'], ['接待', '首次接待'], ['需求', '需求分析'], ['体测', '体测沟通'],
      ['体验课', '体验课'], ['家长', '家长沟通'], ['异议', '异议处理'], ['成交', '成交'],
      ['续费', '续费'], ['话术', '销售话术']
    ]
  };
  const typeNames = {
    knowledge_card: '知识卡', action: '动作', game: '游戏', script: '话术', case: '案例',
    lesson: '教案参考', training: '培训', fitness_guidance: '体测说明'
  };
  const state = { primaryCategory:'professional', topic:'', keyword:'', contentType:'', mode:'all', page:1, total:0, loading:false, staticMode:false };

  const elements = {};

  function parseState() {
    const params = new URLSearchParams(window.location.search);
    state.primaryCategory = params.get('primary_category') === 'sales' ? 'sales' : 'professional';
    state.topic = params.get('topic') || '';
    state.keyword = params.get('keyword') || '';
    state.contentType = params.get('content_type') || '';
    state.mode = ['favorite', 'recent'].includes(params.get('mode')) ? params.get('mode') : 'all';
  }

  function captureElements() {
    elements.list = document.getElementById('knowledgeList');
    elements.count = document.getElementById('resultCount');
    elements.topicList = document.getElementById('topicList');
    elements.searchInput = document.getElementById('searchInput');
    elements.contentType = document.getElementById('contentType');
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
    document.getElementById('clearFilters').addEventListener('click', () => {
      state.topic = '';
      state.keyword = '';
      state.contentType = '';
      state.mode = 'all';
      refresh();
    });
    elements.loadMore.addEventListener('click', () => loadList(state.page + 1, true));
  }

  function syncControls() {
    elements.searchInput.value = state.keyword;
    elements.contentType.value = state.contentType;
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
    if (state.mode !== 'all') params.set('mode', state.mode);
    window.history.replaceState(null, '', `${window.location.pathname}?${params}`);
  }

  function buildApiUrl(page) {
    const params = new URLSearchParams({ page:String(page), page_size:String(PAGE_SIZE), primary_category:state.primaryCategory });
    if (state.keyword) params.set('keyword', state.keyword);
    if (state.topic) params.set('subcategory_code', state.topic);
    if (state.contentType) params.set('content_type', state.contentType);
    if (state.mode === 'favorite') params.set('favorite', '1');
    if (state.mode === 'recent') params.set('recent', '1');
    return `${API_URL}?${params}`;
  }

  async function loadList(page = 1, append = false) {
    if (state.loading) return;
    state.loading = true;
    if (!append) {
      elements.list.innerHTML = '<div class="state">加载中...</div>';
      elements.loadWrap.hidden = true;
    }
    try {
      const response = await fetch(buildApiUrl(page), { headers:window.authHeaders ? window.authHeaders() : {} });
      const payload = await response.json();
      if (!response.ok || Number(payload.code) !== 0) throw new Error(payload.message || 'knowledge_request_failed');
      state.staticMode = false;
      state.page = page;
      state.total = Number(payload.data.total || 0);
      renderList(payload.data.list || [], append);
      elements.count.textContent = `共 ${state.total} 条`;
      elements.loadWrap.hidden = page * Number(payload.data.page_size || PAGE_SIZE) >= state.total;
      elements.previewNote.hidden = true;
    } catch (error) {
      if (isPreviewHost && !append) {
        await loadPublishedStaticIndex();
      } else if (!append) {
        renderState('暂时无法加载知识', '请稍后重试');
        elements.count.textContent = '加载失败';
      }
    } finally {
      state.loading = false;
    }
  }

  async function loadPublishedStaticIndex() {
    try {
      const response = await fetch(STATIC_INDEX_URL, { cache:'no-store' });
      const index = await response.json();
      const term = (state.keyword || state.topic).toLowerCase();
      const list = index.filter((item) => {
        if (item.publication_status !== 'published' || item.primary_category !== state.primaryCategory) return false;
        if (state.contentType && item.content_type !== state.contentType) return false;
        if (state.mode !== 'all') return false;
        const text = [item.title, item.summary, ...(item.keywords || [])].join(' ').toLowerCase();
        return !term || text.includes(term);
      });
      state.staticMode = true;
      state.page = 1;
      state.total = list.length;
      renderList(list, false);
      elements.count.textContent = `已发布入口 ${list.length} 条`;
      elements.previewNote.hidden = false;
      elements.loadWrap.hidden = true;
    } catch (error) {
      renderState('暂时无法加载知识', '请稍后重试');
      elements.count.textContent = '加载失败';
    }
  }

  function renderList(items, append) {
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
    const tags = [lineLabel, item.subcategory_label, typeNames[item.content_type || item.category_type] || item.content_type].filter(Boolean);
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

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  async function refresh() {
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
