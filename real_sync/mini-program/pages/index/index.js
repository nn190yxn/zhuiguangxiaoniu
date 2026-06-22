const app = getApp();

Page({
  data: {
    isLoggedIn: false,
    userInfo: null,
    notifications: [],
    todos: [],
    todoSummary: {},
    todosLoading: false,
  },

  onLoad() {
    this.checkLogin();
  },

  onShow() {
    this.checkLogin();
    this.loadTodos();
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
      'sales': '销售',
      'coach': '教练',
      'operation': '总部运营',
      'finance': '财务',
      'ceo': '总经理',
      'staff': '员工'
    };
    return map[role] || '员工';
  },

  loadNotifications() {
    if (!app.isLoggedIn()) return;

    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?unread=1`
    }).then(res => {
      const notifications = (res.data.list || []).map(item => ({
        ...item,
        isRead: Number(item.is_read || 0) === 1,
        createdAt: item.created_at || ''
      }));
      this.setData({
        notifications
      });
    }).catch(err => {
      console.error('加载通知失败:', err);
    });
  },

  loadTodos() {
    if (!app.isLoggedIn()) {
      this.setData({ todos: [], todoSummary: {}, todosLoading: false });
      return;
    }
    this.setData({ todosLoading: true });
    app.request({
      url: '/todos/my.php',
      redirectOnUnauthorized: false
    }).then(res => {
      this.setData({
        todos: (res.data.todos || []).map(item => ({
          ...item,
          priorityName: this.getPriorityName(item.priority),
          typeName: this.getTodoTypeName(item.type)
        })),
        todoSummary: res.data.summary || {},
        todosLoading: false
      });
    }).catch(err => {
      console.error('加载待办失败:', err);
      this.setData({ todosLoading: false });
    });
  },

  getPriorityName(priority) {
    const map = { urgent: '紧急', high: '重要', normal: '待办', low: '提醒' };
    return map[priority] || '待办';
  },

  getTodoTypeName(type) {
    const map = { workload: '工作量', policy: '制度', reminder: '提醒' };
    return map[type] || '任务';
  },

  goTodo(e) {
    const route = e.currentTarget.dataset.route;
    if (!route) return;
    wx.navigateTo({ url: route });
  },

  goWorkload() {
    wx.navigateTo({ url: '/pages/workload/index' });
  },

  goLogin() {
    wx.navigateTo({
      url: '/pages/login/login'
    });
  },

  goPolicy() {
    wx.navigateTo({
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

  goPassMap() {
    wx.switchTab({
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
