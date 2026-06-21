const auth = require('./utils/auth');
const api = require('./utils/api');

App({
  globalData: {
    apiBase: 'https://supercalf.com/api',
    userInfo: null,
    token: null,
    deviceInfo: null
  },

  onLaunch() {
    this.collectDeviceInfo();
    this.checkLoginStatus();
  },

  checkLoginStatus() {
    const token = auth.getToken();
    const userInfo = auth.getUserInfo();

    if (!token || !userInfo) {
      this.globalData.token = null;
      this.globalData.userInfo = null;
      return;
    }

    if (auth.isTokenExpired(0)) {
      auth.clearAuth();
      this.globalData.token = null;
      this.globalData.userInfo = null;
      return;
    }

    this.globalData.token = token;
    this.globalData.userInfo = userInfo;
    this.reportDeviceInfo();
  },

  login(token, userInfo) {
    this.globalData.token = token;
    this.globalData.userInfo = userInfo;
    auth.setToken(token);
    auth.setUserInfo(userInfo);
    this.reportDeviceInfo();
  },

  logout() {
    this.globalData.token = null;
    this.globalData.userInfo = null;
    auth.clearAuth();
  },

  isLoggedIn() {
    if (this.globalData.token && !auth.isTokenExpired(0)) {
      return true;
    }

    const token = auth.getToken();
    const userInfo = auth.getUserInfo();
    if (!token || !userInfo || auth.isTokenExpired(0)) {
      return false;
    }

    this.globalData.token = token;
    this.globalData.userInfo = userInfo;
    return true;
  },

  collectDeviceInfo() {
    try {
      const systemInfo = wx.getSystemInfoSync();
      const deviceInfo = {
        device_id: wx.getStorageSync('device_id') || '',
        device_fingerprint: '',
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

      deviceInfo.device_fingerprint = deviceInfo.device_id;

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
    } catch (err) {
      console.error('设备信息上报失败:', err);
    }
  },

  request(options) {
    return api.request(options);
  },

  uploadFile(options) {
    return api.uploadFile(options);
  }
});
