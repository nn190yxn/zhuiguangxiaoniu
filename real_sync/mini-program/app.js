const auth = require("./utils/auth");
const api = require("./utils/api");

App({
  globalData: {
    apiBase: "https://supercalf.com/api",
    userInfo: null,
    token: null,
    deviceInfo: null,
    pendingWechatBind: null,
    agreementAccepted: false,
    reminderTemplates: {
      workload_daily_first: "a3pRSNzPasB1ca1hpehmsQWJHtcj6miH960jQHLv2oo",
      workload_daily_second: "di57b2l3CQCndUozVUtkNj7PlZei6XVuQLHt8siM-Eg"
    }
  },

  onLaunch() {
    this.checkLoginStatus();
    this.collectDeviceInfo();
    this.checkAgreementStatus();
  },

  checkAgreementStatus() {
    const accepted = wx.getStorageSync("agreement_accepted");
    if (accepted) {
      this.globalData.agreementAccepted = true;
    }
  },

  setAgreementAccepted() {
    this.globalData.agreementAccepted = true;
    wx.setStorageSync("agreement_accepted", true);
    wx.setStorageSync("agreement_accepted_at", Date.now());
  },

  hasAgreementAccepted() {
    return this.globalData.agreementAccepted === true;
  },

  showAgreement(type) {
    const urlMap = {
      service: "/pages/agreement/service",
      privacy: "/pages/agreement/privacy"
    };
    const url = urlMap[type];
    if (!url) {
      return;
    }
    wx.navigateTo({ url });
  },

  checkLoginStatus() {
    const token = auth.getToken();
    const userInfo = auth.getUserInfo();

    if (token && userInfo) {
      this.globalData.token = token;
      this.globalData.userInfo = userInfo;
      this.reportDeviceInfo();
    }
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
    this.globalData.pendingWechatBind = null;
    auth.clearAuth();
  },

  isLoggedIn() {
    return !!this.globalData.token;
  },

  collectDeviceInfo() {
    try {
      const systemInfo = wx.getSystemInfoSync();
      const deviceInfo = {
        device_id: wx.getStorageSync("device_id") || "",
        device_name: systemInfo.brand + " " + systemInfo.model,
        device_model: systemInfo.model,
        os_version: systemInfo.system,
        app_version: wx.getAccountInfoSync?.()?.miniProgram?.version || "1.0.0",
        screen_width: systemInfo.screenWidth,
        screen_height: systemInfo.screenHeight,
        platform: systemInfo.platform,
        version: systemInfo.version
      };

      if (!deviceInfo.device_id) {
        deviceInfo.device_id = this.generateDeviceId();
        wx.setStorageSync("device_id", deviceInfo.device_id);
      }

      this.globalData.deviceInfo = deviceInfo;
    } catch (err) {
      console.error("获取设备信息失败:", err);
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
        method: "POST",
        data: deviceInfo
      });
    } catch (err) {
      console.error("设备信息上报失败:", err);
    }
  },

  request(options) {
    return api.request(options);
  },

  setPendingWechatBind(payload) {
    this.globalData.pendingWechatBind = payload || null;
  },

  getPendingWechatBind() {
    return this.globalData.pendingWechatBind || null;
  },

  clearPendingWechatBind() {
    this.globalData.pendingWechatBind = null;
  },

  isWechatBound(userInfo) {
    const value = userInfo && userInfo.wechat_bound;
    if (value === true || value === 1 || value === '1' || value === 'true') {
      return true;
    }
    return false;
  },

  getRequiredReminderTemplateKeys() {
    return Object.keys(this.globalData.reminderTemplates || {}).filter(key => String(this.globalData.reminderTemplates[key] || '').trim());
  },

  async loadReminderSubscriptions() {
    const res = await this.request({
      url: '/reminder/subscription.php',
      redirectOnUnauthorized: false
    });
    return Array.isArray(res.data.list) ? res.data.list : [];
  },

  async getReminderGateStatus() {
    const requiredKeys = this.getRequiredReminderTemplateKeys();
    if (!requiredKeys.length) {
      return {
        required: false,
        ready: false,
        requiredKeys: [],
        pendingKeys: [],
        recordMap: {}
      };
    }

    const rows = await this.loadReminderSubscriptions();
    const recordMap = {};
    rows.forEach(item => {
      recordMap[item.template_key] = item;
    });
    const pendingKeys = requiredKeys.filter(key => (recordMap[key] && recordMap[key].accept_status) !== 'accept');

    return {
      required: pendingKeys.length > 0,
      ready: true,
      requiredKeys,
      pendingKeys,
      recordMap,
    };
  },

  async requestReminderSubscription(options = {}) {
    const sceneCode = options.sceneCode || '';
    const templateKeys = Array.isArray(options.templateKeys) ? options.templateKeys : [];
    const templateMap = this.globalData.reminderTemplates || {};
    const pairs = templateKeys
      .map(key => ({ key, id: String(templateMap[key] || '').trim() }))
      .filter(item => item.id);

    if (!sceneCode || !pairs.length || typeof wx.requestSubscribeMessage !== 'function') {
      return { requested: false, acceptedKeys: [], resultMap: {}, reason: 'template_not_ready' };
    }

    const tmplIds = pairs.map(item => item.id);
    const response = await new Promise((resolve, reject) => {
      wx.requestSubscribeMessage({
        tmplIds,
        success: resolve,
        fail: err => {
          const error = new Error((err && err.errMsg) || '微信提醒授权失败');
          error.original = err;
          reject(error);
        },
      });
    });

    const resultMap = {};
    const acceptedKeys = [];
    for (const item of pairs) {
      const rawStatus = String(response[item.id] || 'unknown');
      const acceptStatus = rawStatus === 'accept'
        ? 'accept'
        : (rawStatus === 'reject' ? 'reject' : (rawStatus === 'ban' ? 'ban' : 'unknown'));
      resultMap[item.key] = rawStatus;
      if (acceptStatus === 'accept') {
        acceptedKeys.push(item.key);
      }
      try {
        await this.request({
          url: '/reminder/subscription.php',
          method: 'POST',
          data: {
            scene_code: sceneCode,
            template_key: item.key,
            accept_status: acceptStatus,
          },
          redirectOnUnauthorized: false,
        });
      } catch (err) {
        console.error('保存提醒授权失败:', err);
      }
    }

    return { requested: true, acceptedKeys, resultMap };
  },

  uploadFile(options) {
    return api.uploadFile(options);
  },

  isReminderTemplateReady(templateKeys = []) {
    const templateMap = this.globalData.reminderTemplates || {};
    return templateKeys.some(key => String(templateMap[key] || '').trim());
  }
});
