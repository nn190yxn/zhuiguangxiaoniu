const app = getApp();

Page({
  data: {
    id: null,
    item: {},
    isCompleted: false,
    categoryTypeName: '知识',
    drills: [],
    scripts: [],
    currentDrillId: null,
    currentDrillStatus: '',
    related: [],
    articleHtml: ''
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ id: options.id });
      this.loadDetail();
    }
  },

  async loadDetail() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/knowledge/detail.php?id=${this.data.id}`
      });

      if (res.code === 0) {
        const item = this.normalizeKnowledgeItem(res.data.item);
        const progress = res.data.progress;
        const drills = (res.data.drills || []).map(drill => this.normalizeDrill(drill));
        const scripts = res.data.scripts || [];
        const related = (res.data.related || []).map(row => this.normalizeKnowledgeItem(row));

        const typeNames = {action: '动作', script: '话术', knowledge_card: '知识卡'};

        // 扩展字段名称映射
        const subjectNames = {fitness: '体能', sensory: '感统', skill: '技能'};
        const trainingNames = {strength: '力量', cardio: '心肺', flexibility: '柔韧', balance: '平衡', coordination: '协调'};

        // 处理标签
        let tags = item.tags || [];
        if (item.subject && subjectNames[item.subject]) {
          tags.push({name: subjectNames[item.subject], type: 'subject'});
        }
        if (item.age_group) {
          tags.push({name: item.age_group + '岁', type: 'age'});
        }
        if (item.training_type && trainingNames[item.training_type]) {
          tags.push({name: trainingNames[item.training_type], type: 'training'});
        }

        // 设置当前演练ID
        this.setData({
          item: item,
          articleHtml: this.formatArticleContent(item.content || item.summary || ''),
          isCompleted: progress && progress.is_completed,
          categoryTypeName: typeNames[item.category_type] || '知识',
          drills: drills,
          scripts: scripts,
          related: related,
          tags: tags,
          currentDrillId: drills.length > 0 ? drills[0].id : null,
          currentDrillStatus: drills.length > 0 ? drills[0].task_status : ''
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  async markComplete() {
    if (this.data.isCompleted) {
      wx.showToast({ title: '已经学完了', icon: 'none' });
      return;
    }

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/knowledge/progress.php`,
        method: 'POST',
        data: {
          knowledge_id: this.data.id,
          action: 'complete',
          score: 100,
          learning_time: 60
        }
      });

      if (res.code === 0) {
        this.setData({ isCompleted: true });
        wx.showToast({
          title: '学习完成！+' + (res.data.points_awarded || 0) + '积分',
          icon: 'success'
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '操作失败', icon: 'none' });
    }
  },

  goToDrill(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/drill/doing/doing?id=${id}`
    });
  },

  goToRelated(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/knowledge/detail?id=${id}`
    });
  },

  playAudio(e) {
    const url = e.currentTarget.dataset.url;
    if (url) {
      wx.showToast({ title: '播放示范音频', icon: 'none' });
    }
  },

  normalizeDrill(drill) {
    const completed = drill.task_status === 'completed';
    return {
      ...drill,
      status_text: completed ? '已完成' : (drill.task_progress > 0 ? '进行中' : '未开始'),
      button_text: completed ? '查看详情' : '开始演练'
    };
  },

  normalizeKnowledgeItem(item = {}) {
    const typeNames = { action: '动作', script: '话术', knowledge_card: '知识卡' };
    const iconMap = { action: '动', script: '话', knowledge_card: '知' };
    return {
      ...item,
      placeholder_icon: iconMap[item.category_type] || '知',
      cover_icon: iconMap[item.category_type] || '知',
      category_type_name: typeNames[item.category_type] || '知识'
    };
  },

  formatArticleContent(content) {
    const raw = String(content || '').trim();
    if (!raw) {
      return '<p style="margin:0 0 14px;line-height:1.85;color:#6b625c;font-size:15px;">暂无正文内容</p>';
    }

    if (/<\/?[a-z][\s\S]*>/i.test(raw)) {
      return `<div style="font-size:15px;line-height:1.85;color:#2f2925;">${raw}</div>`;
    }

    const lines = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
    const html = [];
    let paragraph = [];
    let listType = '';
    let listItems = [];

    const escapeHtml = (value) => String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const inline = (value) => escapeHtml(value)
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/`([^`]+)`/g, '<span style="background:#f5f3f0;border-radius:4px;padding:0 4px;">$1</span>');

    const flushParagraph = () => {
      if (!paragraph.length) return;
      html.push(`<p style="margin:0 0 16px;line-height:1.9;color:#2f2925;font-size:15px;">${inline(paragraph.join(' '))}</p>`);
      paragraph = [];
    };

    const flushList = () => {
      if (!listType || !listItems.length) return;
      const tag = listType;
      const items = listItems.map(item => `<li style="margin:0 0 8px;line-height:1.75;">${inline(item)}</li>`).join('');
      html.push(`<${tag} style="margin:0 0 16px 20px;padding:0;color:#2f2925;font-size:15px;">${items}</${tag}>`);
      listType = '';
      listItems = [];
    };

    lines.forEach(line => {
      const text = line.trim();
      if (!text) {
        flushParagraph();
        flushList();
        return;
      }

      const heading = text.match(/^(#{1,4})\s+(.+)$/);
      if (heading) {
        flushParagraph();
        flushList();
        const size = heading[1].length <= 2 ? 18 : 16;
        html.push(`<h3 style="margin:22px 0 10px;color:#1f1a17;font-size:${size}px;line-height:1.45;font-weight:700;">${inline(heading[2])}</h3>`);
        return;
      }

      if (/^[-*•]\s+/.test(text)) {
        flushParagraph();
        if (listType && listType !== 'ul') flushList();
        listType = 'ul';
        listItems.push(text.replace(/^[-*•]\s+/, ''));
        return;
      }

      if (/^\d+[.、]\s+/.test(text)) {
        flushParagraph();
        if (listType && listType !== 'ol') flushList();
        listType = 'ol';
        listItems.push(text.replace(/^\d+[.、]\s+/, ''));
        return;
      }

      if (/^[-=]{3,}$/.test(text)) {
        flushParagraph();
        flushList();
        html.push('<div style="height:1px;background:#eee7e1;margin:20px 0;"></div>');
        return;
      }

      flushList();
      paragraph.push(text);
    });

    flushParagraph();
    flushList();
    return html.join('');
  }
});
