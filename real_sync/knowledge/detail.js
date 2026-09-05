(() => {
  const API_URL = '/api/knowledge/detail.php';
  let knowledgeId = '';
  let isFavorite = false;
  let favoriteBusy = false;

  const typeNames = { knowledge_card:'知识卡', action:'动作', game:'游戏', script:'话术', case:'案例', lesson:'教案参考', coach_growth:'教练成长' };

  async function init() {
    knowledgeId = new URLSearchParams(window.location.search).get('id') || '';
    const back = document.getElementById('backLink');
    const returnCategory = new URLSearchParams(window.location.search).get('primary_category');
    if (returnCategory === 'professional' || returnCategory === 'sales') back.href = `/knowledge/?primary_category=${returnCategory}`;
    if (!/^\d+$/.test(knowledgeId) || Number(knowledgeId) < 1) {
      renderState('缺少有效的知识编号', '请返回知识列表重新选择');
      return;
    }
    await loadDetail();
  }

  async function loadDetail() {
    try {
      const response = await fetch(`${API_URL}?id=${encodeURIComponent(knowledgeId)}`, { headers:window.authHeaders ? window.authHeaders() : {} });
      const payload = await response.json();
      if (!response.ok || Number(payload.code) !== 0) throw new Error(payload.message || 'knowledge_detail_failed');
      isFavorite = Boolean(payload.data.is_favorite);
      renderDetail(payload.data);
    } catch (error) {
      renderState('暂时无法加载知识', '请稍后重试');
    }
  }

  function renderDetail(data) {
    const item = data.item || {};
    const lineLabel = item.primary_category_label || (item.primary_category === 'sales' ? '销售知识' : '专业知识');
    const displayMeta = Object.values(item.display_meta || {}).map((row) => row.label).filter(Boolean);
    const tags = [lineLabel, item.subcategory_label, typeNames[item.content_type || item.category_type], ...(item.tags || []), ...displayMeta].filter(Boolean);
    document.title = `${item.title || '知识详情'} | 追光小牛`;
    document.getElementById('content').innerHTML = `<article class="article">
      <h1>${escapeHtml(item.title || '未命名知识')}</h1>
      ${item.summary ? `<p class="summary">${escapeHtml(item.summary)}</p>` : ''}
      <div class="meta">${tags.map((tag, index) => `<span class="tag${index === 0 ? ' line' : ''}">${escapeHtml(tag)}</span>`).join('')}</div>
      <div class="actions"><button type="button" class="favorite${isFavorite ? ' active' : ''}" id="favoriteButton">${isFavorite ? '已收藏' : '收藏'}</button></div>
      <div class="body">${escapeHtml(item.content || '暂无详细内容')}</div>
    </article>`;
    document.getElementById('favoriteButton').addEventListener('click', toggleFavorite);
    renderRelated(data.related || []);
  }

  function renderRelated(items) {
    const section = document.getElementById('relatedSection');
    if (!items.length) {
      section.hidden = true;
      return;
    }
    document.getElementById('relatedList').innerHTML = items.map((item) => `<a class="related-item" href="/knowledge/detail.html?id=${encodeURIComponent(String(item.id))}">${escapeHtml(item.title)}<span>${escapeHtml(typeNames[item.content_type || item.category_type] || '知识')}</span></a>`).join('');
    section.hidden = false;
  }

  async function toggleFavorite() {
    if (favoriteBusy) return;
    favoriteBusy = true;
    const button = document.getElementById('favoriteButton');
    try {
      const next = !isFavorite;
      const response = await fetch('/api/knowledge/favorite.php', {
        method:next ? 'POST' : 'DELETE',
        headers:window.authHeaders ? window.authHeaders({ 'Content-Type':'application/json' }) : { 'Content-Type':'application/json' },
        body:JSON.stringify({ knowledge_id:Number(knowledgeId) })
      });
      const payload = await response.json();
      if (!response.ok || Number(payload.code) !== 0) throw new Error(payload.message || 'favorite_failed');
      isFavorite = next;
      button.textContent = isFavorite ? '已收藏' : '收藏';
      button.classList.toggle('active', isFavorite);
    } catch (error) {
      button.textContent = '操作失败';
      window.setTimeout(() => { button.textContent = isFavorite ? '已收藏' : '收藏'; }, 1500);
    } finally {
      favoriteBusy = false;
    }
  }

  function renderState(title, detail) {
    document.getElementById('content').innerHTML = `<div class="state"><strong>${escapeHtml(title)}</strong>${escapeHtml(detail)}</div>`;
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  window.addEventListener('DOMContentLoaded', () => {
    if (typeof window.requirePageAuth === 'function') window.requirePageAuth({ onAuthed:init });
    else init();
  });
})();
