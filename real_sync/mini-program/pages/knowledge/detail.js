const app = getApp();
const api = require('../../utils/api');
const media = require('../../utils/media');

Page({
  data: {
    id: null,
    item: {},
    isCompleted: false,
    categoryTypeName: '知识',
    drills: [],
    scripts: [],
    currentDrillId: null,
    related: [],
    tags: [],
    contentMode: 'sections',
    contentSections: [],
    contentNodes: '',
    currentAudioUrl: '',
    audioStatus: 'idle',
    audioError: ''
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ id: options.id });
      this.loadDetail();
    }
  },

  onUnload() {
    this.destroyAudioPlayer();
  },

  async loadDetail() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `/knowledge/detail.php?id=${this.data.id}`
      });

      if (res.code === 0) {
        const item = this.normalizeKnowledgeItem(res.data.item);
        const progress = res.data.progress;
        const drills = (res.data.drills || []).map(drill => this.normalizeDrill(drill));
        const scripts = (res.data.scripts || []).map(script => media.normalizeMediaFields(script, ['audio_url']));
        const related = (res.data.related || []).map(row => this.normalizeKnowledgeItem(row));

        const typeNames = {action: '动作', script: '话术', knowledge_card: '知识卡'};

        // 扩展字段名称映射
        const subjectNames = {fitness: '体能', sensory: '感统', skill: '技能'};
        const trainingNames = {strength: '力量', cardio: '心肺', flexibility: '柔韧', balance: '平衡', coordination: '协调'};

        // 处理标签
        let tags = (item.tags || []).map(t => {
          if (typeof t === 'string') return { name: t, type: 'default' };
          return t;
        });
        if (item.subject && subjectNames[item.subject]) {
          tags.push({name: subjectNames[item.subject], type: 'subject'});
        }
        if (item.age_group) {
          tags.push({name: item.age_group + '岁', type: 'age'});
        }
        if (item.training_type && trainingNames[item.training_type]) {
          tags.push({name: trainingNames[item.training_type], type: 'training'});
        }

        tags = this.dedupeTags(tags);

        const contentRender = this.buildContentRender(item.content);

        // 设置当前演练ID
        if (drills.length > 0) {
          this.setData({ currentDrillId: drills[0].id });
        }

        this.setData({
          item: item,
          isCompleted: progress && progress.is_completed,
          categoryTypeName: typeNames[item.category_type] || '知识',
          drills: drills,
          scripts: scripts,
          related: related,
          tags: tags,
          contentMode: contentRender.mode,
          contentSections: contentRender.sections,
          contentNodes: contentRender.nodes
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
        url: '/knowledge/progress.php',
        method: 'POST',
        idempotencyKey: api.createIdempotencyKey(`knowledge_complete_${this.data.id}`),
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
          title: '学习完成',
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
    if (!url) return;
    if (this.data.currentAudioUrl === url && this.data.audioStatus === 'playing' && this.audioContext) {
      this.audioContext.pause();
      return;
    }
    if (this.data.currentAudioUrl === url && this.data.audioStatus === 'paused' && this.audioContext) {
      this.audioContext.play();
      return;
    }
    this.startAudio(url);
  },

  startAudio(url) {
    this.destroyAudioPlayer();
    this.audioContext = wx.createInnerAudioContext();
    this.audioContext.src = url;
    this.audioContext.onCanplay(() => {
      this.setData({ audioStatus: 'playing', audioError: '' });
    });
    this.audioContext.onPlay(() => {
      this.setData({ audioStatus: 'playing', audioError: '' });
    });
    this.audioContext.onPause(() => {
      this.setData({ audioStatus: 'paused' });
    });
    this.audioContext.onEnded(() => {
      this.setData({ audioStatus: 'ended', currentAudioUrl: '' });
    });
    this.audioContext.onError((error) => {
      console.error('示范音频播放失败:', error);
      this.setData({ audioStatus: 'error', audioError: '音频播放失败，请稍后重试' });
      wx.showToast({ title: '音频播放失败', icon: 'none' });
    });
    this.setData({ currentAudioUrl: url, audioStatus: 'loading', audioError: '' });
    this.audioContext.play();
  },

  destroyAudioPlayer() {
    if (this.audioContext) {
      this.audioContext.stop();
      this.audioContext.destroy();
      this.audioContext = null;
    }
    this.setData({ currentAudioUrl: '', audioStatus: 'idle', audioError: '' });
  },

  audioButtonText(url) {
    if (this.data.currentAudioUrl !== url) return '播放示范';
    const map = {
      loading: '加载中',
      playing: '暂停示范',
      paused: '继续播放',
      error: '重新播放',
      ended: '重新播放'
    };
    return map[this.data.audioStatus] || '播放示范';
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
    return media.normalizeMediaFields({
      ...item,
      placeholder_icon: iconMap[item.category_type] || '知',
      cover_icon: iconMap[item.category_type] || '知',
      category_type_name: typeNames[item.category_type] || '知识'
    }, ['media_url', 'cover_url', 'demo_audio_url', 'audio_url']);
  },

  dedupeTags(tags = []) {
    const seen = new Set();
    return tags.filter((tag) => {
      const name = String(tag.name || '').trim();
      if (!name || seen.has(name)) {
        return false;
      }
      seen.add(name);
      return true;
    });
  },

  buildContentRender(content) {
    const raw = String(content || '').replace(/\r\n/g, '\n').trim();
    if (!raw) {
      return { mode: 'sections', sections: [], nodes: '' };
    }

    if (/<\/?[a-z][\s\S]*>/i.test(raw)) {
      return {
        mode: 'html',
        sections: [],
        nodes: `<div style="font-size:15px;line-height:1.9;color:#332e2a;">${raw}</div>`
      };
    }

    return {
      mode: 'sections',
      sections: this.parsePlainTextContent(raw),
      nodes: ''
    };
  },

  parsePlainTextContent(content) {
    const normalized = content
      .replace(/\u00a0/g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .replace(/([。！？；])\s*(\d+[.．])/g, '$1\n$2')
      .replace(/([。！？；])\s*(建议|不要|先看|再看|最后看)/g, '$1\n$2');

    const hasSectionTitle = /【[^】]+】/.test(normalized);
    const chunks = hasSectionTitle
      ? normalized.split(/(?=【[^】]+】)/).map((part) => part.trim()).filter(Boolean)
      : [normalized];

    return chunks.map((chunk, index) => {
      const match = chunk.match(/^【([^】]+)】\s*([\s\S]*)$/);
      const title = match ? match[1].trim() : `重点 ${index + 1}`;
      const body = match ? match[2].trim() : chunk;
      const paragraphs = body
        .split(/\n+/)
        .map((paragraph) => paragraph.replace(/[ \t]{2,}/g, ' ').trim())
        .filter(Boolean);

      return { title, paragraphs };
    }).filter((section) => section.paragraphs.length > 0);
  }
});
