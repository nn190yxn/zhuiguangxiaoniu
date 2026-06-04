const app = getApp();

Page({
  data: {
    isLoggedIn: false,
    userInfo: null,
    notifications: [],
    notificationLoaded: false
  },

  onLoad() {
    this.checkLogin();
  },

  onShow() {
    this.checkLogin();
    this.scheduleLoadNotifications();
  },

  onHide() {
    if (this.notificationTimer) {
      clearTimeout(this.notificationTimer);
      this.notificationTimer = null;
    }
  },

  checkLogin() {
    const isLoggedIn = app.isLoggedIn();
    const userInfo = app.globalData.userInfo;

    if (isLoggedIn && userInfo) {
      userInfo.roleName = this.getRoleName(userInfo.role);
    }

    this.setData({
      isLoggedIn,
      userInfo
    });
  },

  getRoleName(role) {
    const map = {
      'admin': '管理员',
      'manager': '店长',
      'staff': '员工'
    };
    return map[role] || '员工';
  },

  loadNotifications() {
    if (!app.isLoggedIn()) return;

    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?unread=1`,
      timeout: 4000,
      redirectOnUnauthorized: false
    }).then(res => {
      this.setData({
        notifications: res.data.list || [],
        notificationLoaded: true
      });
    }).catch(err => {
      this.setData({ notificationLoaded: true });
    });
  },

  scheduleLoadNotifications() {
    if (!app.isLoggedIn()) return;
    if (this.notificationTimer) clearTimeout(this.notificationTimer);
    this.notificationTimer = setTimeout(() => {
      this.loadNotifications();
    }, 600);
  },

  goLogin() {
    wx.navigateTo({
      url: '/pages/login/login'
    });
  },

  goKnowledge() {
    wx.switchTab({
      url: '/pages/knowledge/list'
    });
  },

  goLearning() {
    wx.switchTab({
      url: '/pages/learning/list'
    });
  },

  goDrill() {
    wx.navigateTo({
      url: '/pages/drill/list/list'
    });
  },

  goWorkload() {
    wx.switchTab({
      url: '/pages/workload/index'
    });
  },

  goPassMap() {
    wx.navigateTo({
      url: '/pages/pass/map'
    });
  },

  goNotifications() {
    wx.navigateTo({
      url: '/pages/notifications/list'
    });
  },

  viewNotice(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/notifications/detail?id=${id}`
    });
  }
});
