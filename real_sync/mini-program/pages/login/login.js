const app = getApp();

Page({
  data: {
    username: '',
    password: '',
    errorMsg: '',
    loading: false,
    enableWechatLogin: false
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

    wx.request({
      url: `${app.globalData.apiBase}/auth-jwt.php?action=wxlogin`,
      method: 'POST',
      data: {
        code,
        device_id: deviceInfo.device_id || '',
        device_fingerprint: deviceInfo.device_id || `${deviceInfo.platform}_${deviceInfo.os_version}`
      },
      header: {
        'Content-Type': 'application/json'
      },
      success: (res) => {
        if (res.statusCode >= 200 && res.statusCode < 300) {
          const data = res.data;
          if (data.code === 0) {
            app.login(data.data.token, data.data.user);

            const pages = getCurrentPages();
            if (pages.length > 1) {
              wx.navigateBack();
            } else {
              wx.switchTab({
                url: '/pages/index/index'
              });
            }
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
            errorMsg: '请求失败，请稍后重试'
          });
        }
      },
      fail: (err) => {
        console.error('微信登录失败:', err);
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

    wx.request({
      url: `${app.globalData.apiBase}/auth-jwt.php`,
      method: 'POST',
      data: {
        username,
        password
      },
      header: {
        'Content-Type': 'application/json'
      },
      success: (res) => {
        if (res.statusCode >= 200 && res.statusCode < 300) {
          const data = res.data;
          if (data.code === 0) {
            // 登录成功
            app.login(data.data.token, data.data.user);

            // 跳转回之前页面或首页
            const pages = getCurrentPages();
            if (pages.length > 1) {
              wx.navigateBack();
            } else {
              wx.switchTab({
                url: '/pages/index/index'
              });
            }
          } else {
            this.setData({
              errorMsg: data.message || '登录失败'
            });
          }
        } else {
          this.setData({
            errorMsg: '请求失败，请稍后重试'
          });
        }
      },
      fail: (err) => {
        console.error('登录失败:', err);
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
  }
});
