const app = getApp();

function templateConfigs() {
  return [
    {
      key: 'workload_daily_first',
      title: '20:00 首次提醒',
      description: '当天工作量还没处理完，会先提醒你补日报和凭证。'
    },
    {
      key: 'workload_daily_second',
      title: '23:00 二次提醒',
      description: '当天仍未完成时，会再提醒一次，避免错过截止时间。'
    }
  ];
}

Page({
  data: {
    loading: true,
    submitting: false,
    statusText: '',
    pendingItems: [],
    ready: false,
    debugText: '',
    completed: false,
  },

  onShow() {
    this.loadGateStatus();
  },

  async loadGateStatus() {
    const lastError = wx.getStorageSync('last_request_error');
    this.setData({
      loading: true,
      statusText: '',
      debugText: lastError && lastError.url ? `last_error=${lastError.url}; err=${lastError.errMsg || ''}` : ''
    });
    try {
      const gateStatus = await app.getReminderGateStatus();
      if (!gateStatus.ready || !gateStatus.required) {
        this.setData({
          loading: false,
          ready: true,
          completed: true,
          statusText: '提醒状态已经满足，可以直接进入首页。',
          pendingItems: []
        });
        return;
      }

      const pendingSet = new Set(gateStatus.pendingKeys);
      this.setData({
        loading: false,
        ready: true,
        completed: false,
        statusText: '开启后，你会收到每日工作量提醒。当前登录需要先完成提醒授权。',
        pendingItems: templateConfigs().filter(item => pendingSet.has(item.key))
      });
    } catch (err) {
      console.error('加载提醒拦截状态失败:', err && err.url ? err.url : '', err);
      this.setData({
        loading: false,
        ready: false,
        completed: false,
        statusText: `${err.message || '提醒状态加载失败，请重试。'}${err && err.url ? `：${err.url}` : ''}`,
        pendingItems: []
      });
    }
  },

  async requestReminderAuthorization() {
    this.setData({ submitting: true });
    try {
      const result = await app.requestReminderSubscription({
        sceneCode: 'workload',
        templateKeys: templateConfigs().map(item => item.key)
      });

      if (!result.requested) {
        this.setData({ statusText: '当前提醒模板还未就绪，请联系管理员检查配置。' });
        return;
      }

      const gateStatus = await app.getReminderGateStatus();
      if (!gateStatus.required) {
        wx.showToast({ title: '提醒已开启', icon: 'success' });
        this.setData({
          completed: true,
          statusText: '提醒授权已经完成，可以直接进入首页。',
          pendingItems: []
        });
        return;
      }

      this.setData({
        statusText: '当前还没有完成全部提醒授权，请继续开启后进入首页。'
      });
      this.loadGateStatus();
    } catch (err) {
      wx.showToast({ title: err.message || '授权失败', icon: 'none' });
      this.setData({
        completed: false,
        statusText: `${err.message || '授权失败'}${err && err.url ? `：${err.url}` : ''}`
      });
    } finally {
      this.setData({ submitting: false });
    }
  },

  enterHome() {
    wx.switchTab({ url: '/pages/index/index' });
  },

  retryLoad() {
    this.loadGateStatus();
  },

  exitLogin() {
    app.logout();
    wx.reLaunch({ url: '/pages/login/login' });
  }
});
