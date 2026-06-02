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
    related: []
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
        const item = res.data.item;
        const progress = res.data.progress;
        const drills = res.data.drills || [];
        const scripts = res.data.scripts || [];
        const related = res.data.related || [];

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
          tags: tags
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
      url: `/pages/drill/doing?id=${id}`
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
  }
});