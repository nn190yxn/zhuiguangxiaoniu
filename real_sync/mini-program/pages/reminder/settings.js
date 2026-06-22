const app = getApp();

function templateConfigs() {
  return [
    {
      key: 'workload_daily_first',
      sceneCode: 'workload',
      title: '工作量首次提醒',
      description: '每天 20:00 提醒你先处理当天工作量。'
    },
    {
      key: 'workload_daily_second',
      sceneCode: 'workload',
      title: '工作量二次提醒',
      description: '每天 23:00 对当天仍未完成的工作量再提醒一次。'
    }
  ];
}

Page({
  data: {
    loading: false,
    templateReady: false,
    statusText: '',
    subscriptions: [],
    rules: [
      '销售和教练每天 24:00 前完成工作量日报。',
      '20:00 会先提醒一次，23:00 对未完成项再提醒一次。',
      '当前阶段先提供工作量提醒，学习提醒后续单独上线。'
    ]
  },

  onShow() {
    this.loadSubscriptions();
  },

  async loadSubscriptions() {
    const configs = templateConfigs();
    const templateMap = app.globalData.reminderTemplates || {};
    const templateReady = configs.some(item => String(templateMap[item.key] || '').trim());
    this.setData({ loading: true, templateReady, statusText: templateReady ? '当前已具备手机订阅提醒能力。' : '当前还没有配置订阅消息模板 ID，先通过站内通知接收提醒。' });
    try {
      const res = await app.request({
        url: '/reminder/subscription.php',
        redirectOnUnauthorized: false
      });
      const rows = Array.isArray(res.data.list) ? res.data.list : [];
      const recordMap = {};
      rows.forEach(item => {
        recordMap[item.template_key] = item;
      });
      this.setData({
        subscriptions: configs.map(item => {
          const record = recordMap[item.key] || null;
          return {
            ...item,
            accept_status: record ? record.accept_status : 'unknown',
            accept_status_text: this.statusTextFor(record ? record.accept_status : 'unknown'),
            updated_at: record ? (record.updated_at || '') : '',
            has_template_id: !!String(templateMap[item.key] || '').trim()
          };
        }),
        loading: false,
      });
    } catch (err) {
      this.setData({
        loading: false,
        statusText: err.message || '加载提醒状态失败'
      });
    }
  },

  statusTextFor(status) {
    const map = {
      accept: '已授权',
      reject: '已拒绝',
      ban: '已封禁',
      unknown: '未记录'
    };
    return map[status] || '未记录';
  },

  async requestWorkloadReminder() {
    try {
      const result = await app.requestReminderSubscription({
        sceneCode: 'workload',
        templateKeys: templateConfigs().map(item => item.key)
      });
      if (!result.requested) {
        wx.showToast({ title: '当前先通过站内提醒接收', icon: 'none' });
        return;
      }
      wx.showToast({
        title: result.acceptedKeys.length ? '授权已更新' : '本次未授权',
        icon: result.acceptedKeys.length ? 'success' : 'none'
      });
      this.loadSubscriptions();
    } catch (err) {
      wx.showToast({ title: err.message || '授权失败', icon: 'none' });
    }
  }
});
