const app = getApp();

Page({
  data: {
    username: '',
    password: '',
    errorMsg: '',
    loading: false,
    enableWechatLogin: false,
    agreed: false
  },

  onLoad() {
    this.setData({ agreed: wx.getStorageSync('privacy_agreed') === '1' });
  },

  onUsernameInput(e) {
    this.setData({
      username: e.detail.value
    });
  },

  onPasswordInput(e) {
    this.setData({
      password: e.detail.value
    });
  },

  onAgreementChange(e) {
    const values = e.detail.value || [];
    const agreed = values.indexOf('agree') >= 0;
    this.setData({ agreed });
    wx.setStorageSync('privacy_agreed', agreed ? '1' : '0');
  },

  ensureAgreement() {
    if (this.data.agreed) return true;
    this.setData({ errorMsg: '请先阅读并同意《用户服务协议》和《隐私政策》' });
    return false;
  },

  openServiceAgreement() {
    wx.navigateTo({ url: '/pages/agreement/service' });
  },

  openPrivacyPolicy() {
    wx.navigateTo({ url: '/pages/agreement/privacy' });
  },

  doWeChatLogin() {
    if (!this.ensureAgreement()) return;
    if (!this.data.enableWechatLogin) {
      this.setData({
        errorMsg: '微信一键登录暂未启用，请先使用账号密码登录'
      });
      return;
    }
    this.setData({
      errorMsg: '',
      loading: true
    });

    wx.login({
      success: (res) => {
        if (res.code) {
          this.wxLoginWithCode(res.code);
        } else {
          this.setData({
            errorMsg: '微信授权失败，请稍后重试',
            loading: false
          });
        }
      },
      fail: (err) => {
        console.error('微信登录失败:', err);
        this.setData({
          errorMsg: '微信授权失败，请检查网络连接',
          loading: false
        });
      }
    });
  },

  wxLoginWithCode(code) {
    const deviceInfo = app.globalData.deviceInfo || {};

    app.request({
      url: '/auth-jwt.php?action=wxlogin',
      method: 'POST',
      redirectOnUnauthorized: false,
      data: {
        code,
        device_id: deviceInfo.device_id || '',
        device_fingerprint: deviceInfo.device_fingerprint || deviceInfo.device_id || ''
      }
    }).then(data => {
      app.login(data.data.token, data.data.user);
      this.goAfterLogin();
    }).catch(err => {
      const needBind = err && err.data && err.data.data && err.data.data.need_bind;
      const message = err && err.message ? err.message : '微信登录失败';
      this.setData({
        errorMsg: needBind ? '该微信未绑定员工账号，请先使用账号密码登录，或联系管理员绑定微信' : `${message}${err && err.url ? `：${err.url}` : ''}`
      });
    }).finally(() => {
      this.setData({ loading: false });
    });
  },

  doLogin() {
    if (!this.ensureAgreement()) return;
    const { username, password } = this.data;

    if (!username || !password) {
      this.setData({
        errorMsg: '请输入用户名和密码'
      });
      return;
    }

    this.setData({
      errorMsg: '',
      loading: true
    });

    const deviceInfo = app.globalData.deviceInfo || {};

    app.request({
      url: '/auth-jwt.php',
      method: 'POST',
      redirectOnUnauthorized: false,
      data: {
        username,
        password,
        device_id: deviceInfo.device_id || '',
        device_fingerprint: deviceInfo.device_fingerprint || deviceInfo.device_id || ''
      }
    }).then(data => {
      const user = data.data.user || {};
      console.error('登录返回用户状态:', {
        role: user.role || '',
        staff_id: user.staff_id || '',
        wechat_bound: user.wechat_bound,
      });
      app.login(data.data.token, user);
      this.afterPasswordLogin(user, username, password);
    }).catch(err => {
      this.setData({ errorMsg: `${err.message || '账号或密码不正确，请核对后重试'}${err && err.url ? `：${err.url}` : ''}` });
    }).finally(() => {
      this.setData({ loading: false });
    });
  },

  afterPasswordLogin(user, username, password) {
    if (!app.isWechatBound(user)) {
      app.setPendingWechatBind({ username, password });
      wx.redirectTo({ url: '/pages/wechat-bind/gate' });
      return;
    }
    this.goAfterLogin();
  },

  async goAfterLogin() {
    const currentUser = app.globalData.userInfo || {};
    if (!app.isWechatBound(currentUser)) {
      wx.redirectTo({ url: '/pages/wechat-bind/gate' });
      return;
    }

    try {
      const gateStatus = await app.getReminderGateStatus();
      if (!gateStatus.required) {
        wx.switchTab({ url: '/pages/index/index' });
        return;
      }
    } catch (err) {
      console.error('登录后检查提醒状态失败:', err && err.url ? err.url : '', err);
    }

    wx.redirectTo({ url: '/pages/reminder/gate' });
  }
});
