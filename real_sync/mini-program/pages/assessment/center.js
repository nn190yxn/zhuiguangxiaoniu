Page({
  data: {
    entries: [
      {
        title: '通关评估',
        desc: '查看岗位阶段地图、任务进度和通关结果',
        action: '进入通关',
        url: '/pages/pass/map',
        type: 'tab',
      },
      {
        title: '演练评估',
        desc: '进入销售和教练演练任务，查看训练反馈',
        action: '进入演练',
        url: '/pages/drill/list/list',
        type: 'page',
      },
      {
        title: '评估资料',
        desc: '查看后台知识卡、制度资料和训练标准',
        action: '查看资料',
        url: '/pages/knowledge/list',
        type: 'tab',
      },
    ],
    workflow: ['查看岗位任务', '完成学习和演练', '提交通关材料', '查看反馈结果', '同步后台记录'],
  },

  openEntry(e) {
    const { url, type } = e.currentTarget.dataset;
    if (!url) return;
    if (type === 'tab') {
      wx.switchTab({ url });
      return;
    }
    wx.navigateTo({ url });
  },

  goKnowledge() {
    wx.switchTab({ url: '/pages/knowledge/list' });
  },

  goNotifications() {
    wx.navigateTo({ url: '/pages/notifications/list' });
  },
});
