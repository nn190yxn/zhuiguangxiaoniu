(function () {
  const id = Number(new URLSearchParams(location.search).get('id'));
  const el = name => document.getElementById(name);
  let selected = null, request = 0, saving = false;
  const keys = new Map();
  async function api(url, options) {
    const response = await AppAuth.authFetch(url, options);
    const body = await response.json();
    if (!response.ok || body.code !== 0) throw new Error(body.message || '请求失败，请重试');
    return body.data;
  }
  async function load() {
    const data = await api('/api/learning/detail.php?id=' + id);
    el('title').textContent = data.course.title;
    el('description').textContent = data.course.description || '';
    el('progress').value = data.course.progress_percent;
    el('progressLabel').textContent = '已完成 ' + data.course.completed_lessons + '/' + data.course.total_lessons + ' 章节';
    el('lessons').replaceChildren();
    data.lessons.forEach(lesson => {
      const button = document.createElement('button');
      button.textContent = (Number(lesson.is_completed) === 1 ? '已完成：' : '') + lesson.title;
      button.onclick = () => openLesson(lesson.id);
      el('lessons').appendChild(button);
    });
    el('message').textContent = data.lessons.length ? '请选择章节开始学习' : '课程暂无章节内容';
    return data;
  }
  async function openLesson(lessonId) {
    if (saving) return;
    const current = ++request;
    el('reader').hidden = true;
    try {
      const data = await api('/api/learning/lesson.php?id=' + lessonId);
      if (current !== request) return;
      selected = lessonId;
      el('lessonTitle').textContent = data.lesson.title;
      el('content').textContent = data.lesson.content || '本章节暂无文字内容';
      el('media').replaceChildren();
      if (data.lesson.media_url) {
        const url = new URL(data.lesson.media_url, location.href);
        if (['https:', 'http:'].includes(url.protocol)) {
          const link = document.createElement('a');
          link.href = url.href; link.textContent = '打开课程资源';
          link.target = '_blank'; link.rel = 'noopener';
          el('media').appendChild(link);
        }
      }
      el('reader').hidden = false;
      el('message').textContent = '';
    } catch (error) { if (current === request) el('message').textContent = error.message; }
  }
  el('complete').onclick = async () => {
    if (saving || !selected) return;
    saving = true; el('complete').disabled = true;
    const lessonId = selected;
    if (!keys.has(lessonId)) keys.set(lessonId, crypto.randomUUID());
    try {
      await api('/api/learning/lesson.php?id=' + lessonId, {
        method: 'POST', headers: { 'Idempotency-Key': keys.get(lessonId) }
      });
      await load();
      el('message').textContent = '进度已保存';
    } catch (error) { el('message').textContent = error.message + '，可点击保存重试'; }
    finally { saving = false; el('complete').disabled = false; }
  };
  requirePageAuth({ onAuthed: async () => {
    if (!Number.isInteger(id) || id <= 0) { el('message').textContent = '课程编号无效，请返回学习中心'; return; }
    try { const data = await load(); if (data.lessons.length) await openLesson((data.lessons.find(l => Number(l.is_completed) !== 1) || data.lessons[0]).id); }
    catch (error) { el('message').textContent = error.message; }
  } });
})();
