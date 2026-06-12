Page({
  data: {
    entries: [
      {
        title: '体测评估',
        desc: '记录学员体测数据、训练建议和阶段反馈',
        status: '规划接入',
      },
      {
        title: '暑假班评估',
        desc: '复用现有暑假班评估能力，沉淀学员报告',
        status: '已有基础',
      },
      {
        title: '阶段训练评估',
        desc: '按阶段记录训练表现，辅助教练和店长跟进',
        status: '待配置',
      },
    ],
    workflow: ['选择评估类型', '填写学员信息', '录入指标', '生成建议', '保存记录'],
  },

  goKnowledge() {
    wx.switchTab({ url: '/pages/knowledge/list' });
  },

  goNotifications() {
    wx.navigateTo({ url: '/pages/notifications/list' });
  },
});
