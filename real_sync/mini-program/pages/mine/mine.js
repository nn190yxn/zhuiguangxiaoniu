const app = getApp();

Page({
  data: {
    userInfo: null
  },

  onLoad() {
    this.setData({ userInfo: app.globalData.userInfo });
  },

  onShow() {
    this.setData({ userInfo: app.globalData.userInfo });
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

  clearCache() {
    wx.showModal({
      title: '清除缓存',
      content: '确定要清除本地缓存吗？',
      success: (res) => {
        if (res.confirm) {
          const keepKeys = ['token', 'userInfo', 'device_id'];
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
