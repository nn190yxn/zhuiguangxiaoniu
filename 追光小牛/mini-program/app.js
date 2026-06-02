App({
  globalData: {
    apiBase: 'https://122.51.223.46/api',
    userInfo: null,
    token: null,
    deviceInfo: null
  },

  onLaunch() {
    this.checkLoginStatus();
    this.collectDeviceInfo();
  },

  checkLoginStatus() {
    const token = wx.getStorageSync('token');
    const userInfo = wx.getStorageSync('userInfo');

    if (token && userInfo) {
      this.globalData.token = token;
      this.globalData.userInfo = userInfo;
      this.reportDeviceInfo();
    }
  },

  login(token, userInfo) {
    this.globalData.token = token;
    this.globalData.userInfo = userInfo;
    wx.setStorageSync('token', token);
    wx.setStorageSync('userInfo', userInfo);
    this.reportDeviceInfo();
  },

  logout() {
    this.globalData.token = null;
    this.globalData.userInfo = null;
    wx.removeStorageSync('token');
    wx.removeStorageSync('userInfo');
  },

  isLoggedIn() {
    return !!this.globalData.token;
  },

  collectDeviceInfo() {
    try {
      const systemInfo = wx.getSystemInfoSync();
      const deviceInfo = {
        device_id: wx.getStorageSync('device_id') || '',
        device_name: systemInfo.brand + ' ' + systemInfo.model,
        device_model: systemInfo.model,
        os_version: systemInfo.system,
        app_version: wx.getAccountInfoSync?.()?.miniProgram?.version || '1.0.0',
        screen_width: systemInfo.screenWidth,
        screen_height: systemInfo.screenHeight,
        platform: systemInfo.platform,
        version: systemInfo.version
      };

      if (!deviceInfo.device_id) {
        deviceInfo.device_id = this.generateDeviceId();
        wx.setStorageSync('device_id', deviceInfo.device_id);
      }

      this.globalData.deviceInfo = deviceInfo;
    } catch (err) {
      console.error('获取设备信息失败:', err);
    }
  },

  generateDeviceId() {
    const timestamp = Date.now();
    const random = Math.random().toString(36).substring(2, 15);
    return `DEV_${timestamp}_${random}`;
  },

  async reportDeviceInfo() {
    if (!this.globalData.token || !this.globalData.deviceInfo) {
      return;
    }

    try {
      const deviceInfo = this.globalData.deviceInfo;
      await this.request({
        url: `${this.globalData.apiBase}/statistics/device.php`,
        method: 'POST',
        data: deviceInfo
      });
      console.log('设备信息已上报');
    } catch (err) {
      console.error('设备信息上报失败:', err);
    }
  },

  request(options) {
    const that = this;
    const defaultOptions = {
      url: '',
      method: 'GET',
      data: {},
      header: {}
    };

    options = { ...defaultOptions, ...options };

    if (this.globalData.token) {
      options.header['Authorization'] = `Bearer ${this.globalData.token}`;
    }

    if (!options.header['Content-Type']) {
      options.header['Content-Type'] = 'application/json';
    }

    return new Promise((resolve, reject) => {
      wx.request({
        url: options.url,
        method: options.method,
        data: options.data,
        header: options.header,
        success(res) {
          if (res.statusCode >= 200 && res.statusCode < 300) {
            if (res.data.code === 0) {
              resolve(res.data);
            } else if (res.data.code === 401) {
              that.logout();
              wx.redirectTo({ url: '/pages/login/login' });
              reject(new Error(res.data.message || '未登录'));
            } else {
              reject(new Error(res.data.message || '请求失败'));
            }
          } else {
            reject(new Error(`请求失败: ${res.statusCode}`));
          }
        },
        fail(err) {
          reject(err);
        }
      });
    });
  }
});