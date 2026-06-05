const app = getApp();

Page({
  data: {
    isLoggedIn: false,
    userInfo: null,
    notifications: []
  },

  onLoad() {
    this.checkLogin();
  },

  onShow() {
    this.checkLogin();
    this.loadNotifications();
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
      url: `${app.globalData.apiBase}/policy/notify.php?unread=1`
    }).then(res => {
      this.setData({
        notifications: res.data.list || []
      });
    }).catch(err => {
      console.error('加载通知失败:', err);
    });
  },

  goLogin() {
    wx.navigateTo({
      url: '/pages/login/login'
    });
  },

  goPolicy() {
    wx.switchTab({
      url: '/pages/policy/list'
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

  goSkill() {
    wx.navigateTo({
      url: '/pages/skill/record'
    });
  },

  goPassMap() {
    wx.switchTab({
      url: '/pages/pass/map'
    });
  },

  goNotifications() {
    wx.switchTab({
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
