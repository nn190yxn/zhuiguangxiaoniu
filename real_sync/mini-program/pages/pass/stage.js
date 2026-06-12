const app = getApp();

Page({
  data: {
    stageId: null,
    stage: {},
    progress: {},
    tasks: [],
    exam: null,
    taskStats: '0/0'
  },

  onLoad(options) {
    if (options.stage_id) {
      this.setData({ stageId: options.stage_id });
      this.loadStageDetail();
    }
  },

  async loadStageDetail() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/pass/stage.php?stage_id=${this.data.stageId}`
      });

      if (res.code === 0) {
        const data = res.data;
        const completedCount = (data.tasks || []).filter(t => t.status === 'completed').length;
        const totalCount = data.tasks ? data.tasks.length : 0;

        this.setData({
          stage: data.stage,
          progress: data.progress,
          tasks: (data.tasks || []).map(task => this.normalizeTask(task)),
          exam: this.normalizeExam(data.exam),
          taskStats: `${completedCount}/${totalCount}`
        });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  goToTask(e) {
    const type = e.currentTarget.dataset.type;
    const id = e.currentTarget.dataset.id;

    if (type === 'drill') {
      wx.navigateTo({ url: `/pages/drill/doing/doing?id=${id}` });
    } else if (type === 'knowledge') {
      wx.navigateTo({ url: `/pages/knowledge/detail?id=${id}` });
    } else if (type === 'policy') {
      wx.navigateTo({ url: `/pages/policy/detail?id=${id}` });
    }
  },

  startExam() {
    if (this.data.exam) {
      wx.navigateTo({
        url: `/pages/exam/exam?id=${this.data.exam.id}`
      });
    }
  },

  normalizeTask(task) {
    const iconNames = { drill: '练', knowledge: '知', policy: '制' };
    const completed = task.status === 'completed';
    return {
      ...task,
      task_icon: iconNames[task.task_type] || '项',
      status_text: completed ? '已完成' : '未完成',
      score_class: completed ? 'pass' : ''
    };
  },

  normalizeExam(exam) {
    if (!exam) return null;
    const passed = !!exam.is_passed;
    return {
      ...exam,
      status_class: passed ? 'pass' : '',
      status_text: passed ? '已通过' : (exam.attempts > 0 ? `已尝试${exam.attempts}次` : '未尝试'),
      button_text: passed ? '查看成绩' : '开始考核',
      disabled: !exam.can_attempt && !passed
    };
  }
});
