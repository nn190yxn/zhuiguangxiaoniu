const app = getApp();
const REQUEST_TIMEOUT = 10000;

Page({
  data: {
    username: '',
    password: '',
    errorMsg: '',
    loading: false
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

  doWeChatLogin() {
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
        console.error('[WX LOGIN FAIL]', err);
        this.setData({
          errorMsg: '微信授权失败，请检查网络连接',
          loading: false
        });
      }
    });
  },

  wxLoginWithCode(code) {
    const deviceInfo = app.ensureDeviceInfo ? app.ensureDeviceInfo() : (app.globalData.deviceInfo || {});
    const url = `${app.globalData.apiBase}/auth-jwt.php?action=wxlogin`;

    wx.request({
      url,
      method: 'POST',
      timeout: REQUEST_TIMEOUT,
      data: {
        code,
        device_id: deviceInfo.device_id || '',
        device_fingerprint: deviceInfo.device_fingerprint || deviceInfo.device_id || ''
      },
      header: {
        'Content-Type': 'application/json'
      },
      success: (res) => {
        const data = res.data || {};
        if (res.statusCode >= 200 && res.statusCode < 300) {
          if (data.code === 0) {
            app.login(data.data.token, data.data.user);
            this.goAfterLogin();
          } else if (data.data && data.data.need_bind) {
            this.setData({
              errorMsg: '该微信未绑定账号，请联系管理员绑定'
            });
          } else {
            this.setData({
              errorMsg: data.message || '登录失败'
            });
          }
        } else {
          this.setData({
            errorMsg: data.message || `登录失败（${res.statusCode}）`
          });
        }
      },
      fail: (err) => {
        console.error('[LOGIN REQUEST FAIL]', 'POST', url, err);
        this.setData({
          errorMsg: '网络错误，请检查网络连接'
        });
      },
      complete: () => {
        this.setData({
          loading: false
        });
      }
    });
  },

  doLogin() {
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

    const url = `${app.globalData.apiBase}/auth-jwt.php`;
    wx.request({
      url,
      method: 'POST',
      timeout: REQUEST_TIMEOUT,
      data: {
        username,
        password
      },
      header: {
        'Content-Type': 'application/json'
      },
      success: (res) => {
        const data = res.data || {};
        if (res.statusCode >= 200 && res.statusCode < 300) {
          if (data.code === 0) {
            // 登录成功
            app.login(data.data.token, data.data.user);
            this.goAfterLogin();
          } else {
            this.setData({
              errorMsg: data.message || '登录失败'
            });
          }
        } else {
          this.setData({
            errorMsg: data.message || `登录失败（${res.statusCode}）`
          });
        }
      },
      fail: (err) => {
        console.error('[LOGIN REQUEST FAIL]', 'POST', url, err);
        this.setData({
          errorMsg: '网络错误，请检查网络连接'
        });
      },
      complete: () => {
        this.setData({
          loading: false
        });
      }
    });
  },

  goAfterLogin() {
    wx.showToast({ title: '登录成功', icon: 'success' });
    const redirect = wx.getStorageSync('login_redirect') || '';
    wx.removeStorageSync('login_redirect');

    setTimeout(() => {
      if (redirect) {
        const tabPages = [
          '/pages/index/index',
          '/pages/learning/list',
          '/pages/workload/index',
          '/pages/knowledge/list',
          '/pages/mine/mine'
        ];
        const targetPath = redirect.split('?')[0];
        if (tabPages.indexOf(targetPath) >= 0) {
          wx.switchTab({ url: targetPath });
        } else {
          wx.redirectTo({ url: redirect });
        }
        return;
      }

      wx.switchTab({ url: '/pages/index/index' });
    }, 600);
  }
});
