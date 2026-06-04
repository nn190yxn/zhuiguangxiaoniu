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
    this.checkLoginStatus();
  },

  checkLoginStatus() {
    const token = auth.getToken();
    const userInfo = auth.getUserInfo();

    if (token && userInfo) {
      this.globalData.token = token;
      this.globalData.userInfo = userInfo;
    }
  },

  login(token, userInfo) {
    this.globalData.token = token;
    this.globalData.userInfo = userInfo;
    auth.setToken(token);
    auth.setUserInfo(userInfo);
  },

  logout() {
    this.globalData.token = null;
    this.globalData.userInfo = null;
    auth.clearAuth();
  },

  isLoggedIn() {
    return !!this.globalData.token;
  },

  collectDeviceInfo() {
    try {
      const device = wx.getDeviceInfo ? wx.getDeviceInfo() : {};
      const windowInfo = wx.getWindowInfo ? wx.getWindowInfo() : {};
      const appBaseInfo = wx.getAppBaseInfo ? wx.getAppBaseInfo() : {};
      const legacyInfo = (!wx.getDeviceInfo || !wx.getWindowInfo || !wx.getAppBaseInfo) ? wx.getSystemInfoSync() : {};
      const systemInfo = Object.assign({}, legacyInfo, device, windowInfo, appBaseInfo);
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

  ensureDeviceInfo() {
    let deviceId = wx.getStorageSync('device_id') || '';
    if (!deviceId) {
      deviceId = this.generateDeviceId();
      wx.setStorageSync('device_id', deviceId);
    }

    const deviceInfo = this.globalData.deviceInfo || {};
    deviceInfo.device_id = deviceInfo.device_id || deviceId;
    deviceInfo.device_fingerprint = deviceInfo.device_fingerprint || deviceId;
    this.globalData.deviceInfo = deviceInfo;
    return deviceInfo;
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
        data: deviceInfo,
        timeout: 3000,
        redirectOnUnauthorized: false
      });
    } catch (err) {
      console.error('设备信息上报失败:', err);
    }
  },

  request(options) {
    return api.request(options);
  }
});
