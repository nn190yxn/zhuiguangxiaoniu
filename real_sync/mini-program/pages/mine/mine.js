const app = getApp();

Page({
  data: {
    userInfo: null,
    avatarText: '用'
  },

  onLoad() {
    this.syncUserInfo();
  },

  onShow() {
    this.syncUserInfo();
  },

  syncUserInfo() {
    const userInfo = app.globalData.userInfo || null;
    const displayName = userInfo && (userInfo.display_name || userInfo.username);
    this.setData({
      userInfo,
      avatarText: displayName ? String(displayName).slice(0, 1) : '用'
    });
  },

  showComingSoon() {
    wx.showToast({
      title: '功能开发中',
      icon: 'none'
    });
  },

  goToWorkload() {
    wx.navigateTo({ url: '/pages/workload/index' });
  },

  goToNotifications() {
    wx.navigateTo({ url: '/pages/notifications/list' });
  },

  goToReminderSettings() {
    wx.navigateTo({ url: '/pages/reminder/settings' });
  },

  clearCache() {
    wx.showModal({
      title: '清除缓存',
      content: '确定要清除本地缓存吗？',
      success: (res) => {
        if (res.confirm) {
          const keepKeys = ['token', 'jwt_token', 'userInfo', 'user_info', 'device_id'];
          try {
            const info = wx.getStorageInfoSync();
            info.keys.forEach(key => {
              if (!keepKeys.includes(key)) {
                wx.removeStorageSync(key);
              }
            });
          } catch (e) {
            console.error('清除缓存失败:', e);
          }
          wx.showToast({
            title: '清除成功',
            icon: 'success'
          });
        }
      }
    });
  },

  checkUpdate() {
    wx.showToast({
      title: '已是最新版本',
      icon: 'success'
    });
  },

  logout() {
    wx.showModal({
      title: '退出登录',
      content: '确定要退出登录吗？',
      success: (res) => {
        if (res.confirm) {
          app.logout();
          wx.redirectTo({
            url: '/pages/login/login'
          });
        }
      }
    });
  }
});
