const app = getApp();

Page({
  data: {
    loading: false,
    statusText: '先绑定本人微信，后续才能直接微信登录并接收业务提醒。',
    userName: ''
  },

  onShow() {
    const userInfo = app.globalData.userInfo || {};
    if (app.isWechatBound(userInfo)) {
      wx.redirectTo({ url: '/pages/reminder/gate' });
      return;
    }

    const pending = app.getPendingWechatBind();
    this.setData({
      userName: userInfo.display_name || userInfo.username || (pending && pending.username) || ''
    });
  },

  startBind() {
    const pending = app.getPendingWechatBind();
    if (!pending || !pending.username || !pending.password) {
      wx.showToast({ title: '请重新登录后再绑定', icon: 'none' });
      app.logout();
      wx.reLaunch({ url: '/pages/login/login' });
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
      wx.redirectTo({ url: '/pages/reminder/gate' });
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
