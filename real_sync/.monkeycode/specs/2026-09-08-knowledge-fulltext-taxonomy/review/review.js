'use strict';

const byId = id => document.getElementById(id);
const pageSize = 20;
let dataset;
let filtered = [];
let page = 1;

function element(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

function hasQuality(row) {
  return Boolean(row.quality_flag && row.quality_flag.trim() && row.quality_flag.trim() !== '无');
}

function updateSubtopics() {
  const select = byId('subtopic');
  select.replaceChildren(new Option('全部二级目录', ''));
  for (const topic of dataset.catalog.topics) {
    if (byId('topic').value && topic.label !== byId('topic').value) continue;
    for (const child of topic.children) {
      if (dataset.records.some(row => row.subtopic_code === child.code)) {
        select.add(new Option(child.label, child.code));
      }
    }
  }
}

function render() {
  const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
  page = Math.min(page, pages);
  byId('result-count').textContent = `当前筛选 ${filtered.length.toLocaleString('zh-CN')} 张 / 全部 ${dataset.records.length.toLocaleString('zh-CN')} 张`;
  byId('page-label').textContent = `${page} / ${pages}`;
  byId('previous').disabled = page <= 1;
  byId('next').disabled = page >= pages;
  byId('export').disabled = filtered.length === 0;
  const list = byId('cards');
  list.replaceChildren();
  if (!filtered.length) list.append(element('p', '没有匹配的知识卡。可以减少筛选条件或更换搜索词。', 'empty'));
  for (const row of filtered.slice((page - 1) * pageSize, page * pageSize)) {
    const card = element('article', undefined, 'card');
    const top = element('div', undefined, 'card-top');
    top.append(element('span', `NO. ${row.id}`, 'card-id'));
    const badges = element('div', undefined, 'badges');
    const pending = row.normalization_status === 'pending';
    badges.append(element('span', pending ? '待归并' : '已归并 · AI 提案', `badge${pending ? ' pending' : ''}`));
    if (row.review_status === 'needs_review') badges.append(element('span', '分类待复核', 'badge review'));
    top.append(badges);
    card.append(top, element('h3', row.title));
    card.append(element('p', pending
      ? `原提案：${row.primary_topic} / ${row.subtopic} · 目录尚待归并`
      : `${row.topic_label} / ${row.subtopic_label}`, `taxonomy${pending ? ' pending-text' : ''}`));
    card.append(element('blockquote', `原文引文：${row.evidence}`));
    card.append(element('p', `初次分类依据：${row.reason}`, 'reason'));
    if (row.normalization_note) card.append(element('p', `归并说明：${row.normalization_note}`, 'reason'));
    if (hasQuality(row)) card.append(element('p', `原文质量标记：${row.quality_flag}`, 'quality'));
    const detail = element('details', undefined, 'detail');
    detail.append(element('summary', '查看原始提案与追溯信息'));
    detail.append(element('p', `原始主主题：${row.primary_topic}；原始二级描述：${row.subtopic}`));
    detail.append(element('p', `辅助主题：${row.secondary_topics || '未标注'}`));
    detail.append(element('p', `版本 ID：${row.version_id}；来源组：${row.source_group || '待归并'}`));
    const hash = element('p', '正文 SHA256：');
    hash.append(element('code', row.content_sha256));
    detail.append(hash, element('p', '引文来自既有正文快照。结构校验通过；专业审核与生产版本核对尚未完成。'));
    card.append(detail);
    list.append(card);
  }
}

function applyFilters() {
  if (!dataset) return;
  const query = byId('query').value.trim().toLocaleLowerCase();
  const topic = byId('topic').value;
  const subtopic = byId('subtopic').value;
  const status = byId('status').value;
  filtered = dataset.records.filter(row => {
    const pending = row.normalization_status === 'pending';
    if (topic && (row.topic_label || row.primary_topic) !== topic) return false;
    if (subtopic && row.subtopic_code !== subtopic) return false;
    if (status === 'pending' && !pending) return false;
    if (status === 'normalized' && pending) return false;
    if (status === 'needs_review' && row.review_status !== 'needs_review') return false;
    if (status === 'changed' && (pending || row.topic_label === row.primary_topic)) return false;
    if (byId('quality').checked && !hasQuality(row)) return false;
    return !query || [row.id, row.title, row.evidence, row.reason, row.normalization_note,
      row.primary_topic, row.subtopic, row.topic_label, row.subtopic_label]
      .filter(Boolean).join(' ').toLocaleLowerCase().includes(query);
  });
  page = 1;
  render();
}

byId('filters').addEventListener('submit', event => event.preventDefault());
byId('filters').addEventListener('input', event => {
  if (!dataset) return;
  if (event.target.id === 'topic') updateSubtopics();
  applyFilters();
});
byId('filters').addEventListener('reset', () => {
  setTimeout(() => { if (dataset) { updateSubtopics(); applyFilters(); } }, 0);
});
document.querySelectorAll('[data-status]').forEach(button => button.addEventListener('click', () => {
  if (!dataset) return;
  byId('query').value = '';
  byId('topic').value = '';
  byId('quality').checked = false;
  byId('status').value = button.dataset.status;
  updateSubtopics();
  applyFilters();
}));
for (const [id, delta] of [['previous', -1], ['next', 1]]) {
  byId(id).addEventListener('click', () => {
    page += delta;
    render();
    byId('list-title').scrollIntoView({ block: 'start' });
  });
}
byId('export').addEventListener('click', () => {
  const blob = new Blob([JSON.stringify({
    catalog_version: dataset.catalog.version,
    publication_status: 'not_published',
    record_count: filtered.length,
    records: filtered,
  }, null, 2)], { type: 'application/json;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = element('a');
  anchor.href = url;
  anchor.download = 'knowledge-review-selection.json';
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
});

async function load() {
  try {
    const response = await fetch('./data.json', { cache: 'no-store' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (!Array.isArray(data.records) || !data.catalog?.topics || data.records.length !== data.summary?.source_count) {
      throw new Error('快照记录数与汇总不一致');
    }
    dataset = data;
    byId('total').textContent = data.records.length.toLocaleString('zh-CN');
    byId('normalized').textContent = data.summary.normalized_count.toLocaleString('zh-CN');
    byId('pending').textContent = data.summary.remaining_count.toLocaleString('zh-CN');
    byId('needs-review').textContent = data.records.filter(row => row.review_status === 'needs_review').length.toLocaleString('zh-CN');
    for (const topic of data.catalog.topics) byId('topic').add(new Option(topic.label, topic.label));
    updateSubtopics();
    applyFilters();
  } catch (error) {
    byId('result-count').textContent = `审阅数据加载失败：${error.message}。请刷新页面重试。`;
  }
}
load();
