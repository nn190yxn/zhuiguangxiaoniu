const app = getApp();

Page({
  data: {
    loading: false,
    statusText: '先绑定本人微信，后续才能直接微信登录并接收业务提醒。',
    userName: '',
    debugText: '',
    alreadyBound: false,
    bindMode: 'wechat',
    titleText: '先绑定本人微信',
    descText: '绑定完成后，后续登录更快，提醒也能稳定送达。当前账号登录后需要先完成微信绑定。',
    actionText: '立即绑定微信'
  },

  onShow() {
    const userInfo = app.globalData.userInfo || {};
    const pending = app.getPendingWechatBind();
    const lastError = wx.getStorageSync('last_request_error');
    const bindMode = pending && pending.bindMode === 'wecom' ? 'wecom' : 'wechat';
    const alreadyBound = app.isWechatBound(userInfo);
    this.setData({
      userName: userInfo.display_name || userInfo.username || (pending && pending.username) || '',
      alreadyBound,
      bindMode,
      titleText: bindMode === 'wecom' ? '先关联企业微信成员' : '先绑定本人微信',
      descText: bindMode === 'wecom'
        ? '关联完成后，员工可从企业微信工作台直接进入。当前账号登录后需要先完成企业微信成员关联。'
        : '绑定完成后，后续登录更快，提醒也能稳定送达。当前账号登录后需要先完成微信绑定。',
      actionText: bindMode === 'wecom' ? '立即关联企业微信' : '立即绑定微信',
      statusText: alreadyBound ? '已绑定' : (bindMode === 'wecom' ? '未关联企业微信成员' : '未绑定'),
      debugText: `mode=${bindMode}; wechat_bound=${String(userInfo.wechat_bound)}; wecom_bound=${String(userInfo.wecom_bound)}; pending=${pending && pending.username ? 'yes' : 'no'}${lastError && lastError.url ? `; last_error=${lastError.url}` : ''}`
    });
  },

  async continueNext() {
    try {
      const gateStatus = await app.getReminderGateStatus();
      if (!gateStatus.required) {
        wx.switchTab({ url: '/pages/index/index' });
        return;
      }
    } catch (err) {
      console.error('绑定页检查提醒状态失败:', err && err.url ? err.url : '', err);
    }
    wx.redirectTo({ url: '/pages/reminder/gate' });
  },

  startBind() {
    const pending = app.getPendingWechatBind();
    if (!pending || !pending.username || !pending.password) {
      wx.showToast({ title: '请重新登录后再绑定', icon: 'none' });
      app.logout();
      wx.reLaunch({ url: '/pages/login/login' });
      return;
    }

    if (this.data.bindMode === 'wecom') {
      this.setData({ loading: true, statusText: '正在发起企业微信成员关联，请稍候。' });
      this.bindWithWecom(pending);
      return;
    }

    this.setData({ loading: true, statusText: '正在发起微信授权绑定，请在微信授权完成后继续。' });
    wx.login({
      success: (res) => {
        if (!res.code) {
          this.setData({ loading: false, statusText: '微信授权失败，请重试。' });
          return;
        }
        this.bindWithCode(res.code, pending);
      },
      fail: (err) => {
        console.error('微信绑定授权失败:', err);
        this.setData({ loading: false, statusText: '微信授权失败，请检查网络后重试。' });
      }
    });
  },

  bindWithWecom(pending) {
    const deviceInfo = app.globalData.deviceInfo || {};
    app.getWecomLoginPayload().then(payload => {
      return app.request({
        url: '/auth-jwt.php?action=wecombind',
        method: 'POST',
        redirectOnUnauthorized: false,
        data: {
          ...payload,
          username: pending.username,
          password: pending.password,
          device_id: deviceInfo.device_id || '',
          device_fingerprint: deviceInfo.device_fingerprint || deviceInfo.device_id || ''
        }
      });
    }).then(data => {
      app.clearPendingWechatBind();
      app.login(data.data.token, data.data.user);
      wx.showToast({ title: '企业微信关联成功', icon: 'success' });
      this.continueNext();
    }).catch(err => {
      this.setData({
        loading: false,
        statusText: `${err.message || '企业微信关联失败，请联系管理员处理。'}${err && err.url ? `：${err.url}` : ''}`
      });
    });
  },

  bindWithCode(code, pending) {
    const deviceInfo = app.globalData.deviceInfo || {};
    app.request({
      url: '/auth-jwt.php?action=wxbind',
      method: 'POST',
      redirectOnUnauthorized: false,
      data: {
        code,
        username: pending.username,
        employee_no: pending.username,
        password: pending.password,
        device_id: deviceInfo.device_id || '',
        device_fingerprint: deviceInfo.device_fingerprint || deviceInfo.device_id || ''
      }
    }).then(data => {
      app.clearPendingWechatBind();
      app.login(data.data.token, data.data.user);
      wx.showToast({ title: '微信绑定成功', icon: 'success' });
      this.continueNext();
    }).catch(err => {
      this.setData({
        loading: false,
        statusText: `${err.message || '微信绑定失败，请联系管理员处理。'}${err && err.url ? `：${err.url}` : ''}`
      });
    });
  },

  exitLogin() {
    app.logout();
    wx.reLaunch({ url: '/pages/login/login' });
  }
});
