const app = getApp();
const navigation = require('../../utils/navigation');
const viewState = require('../../utils/view-state');

Page({
  data: {
    isLoggedIn: false,
    userInfo: null,
    todos: [],
    todoSummary: {},
    todosLoading: false,
    homeState: viewState.readState('empty'),
    messageEntry: null,
    messageEntryText: '',
    features: app.getMiniProgramFeatures(),
  },

  onLoad(options) {
    this.applyWecomMessageEntry(options);
    this.checkLogin();
    this.loadCapabilities();
  },

  onShow() {
    this.checkLogin();
    this.loadTodos();
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

  loadCapabilities() {
    app.loadMiniProgramCapabilities().then(features => {
      this.setData({ features });
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

  loadTodos() {
    if (!app.isLoggedIn()) {
      this.setData({ todos: [], todoSummary: {}, todosLoading: false, homeState: viewState.readState('empty') });
      return;
    }
    this.setData({ todosLoading: true, homeState: viewState.readState('loading') });
    app.request({
      url: '/todos/my.php',
      redirectOnUnauthorized: false
    }).then(res => {
      const todos = (res.data.todos || []).slice(0, 3).map(item => ({
        ...item,
        priorityName: this.getPriorityName(item.priority),
        typeName: this.getTodoTypeName(item.type)
      }));
      this.setData({
        todos,
        todoSummary: res.data.summary || {},
        todosLoading: false,
        homeState: viewState.readState(todos.length > 0 ? 'ready' : 'empty')
      });
    }).catch(err => {
      console.error('加载待办失败:', err);
      this.setData({ todosLoading: false, homeState: viewState.fromError(err, '首页待办加载失败', 'retryHome') });
    });
  },

  retryHome() {
    this.loadTodos();
  },

  getPriorityName(priority) {
    const map = { urgent: '紧急', high: '重要', normal: '待办', low: '提醒' };
    return map[priority] || '待办';
  },

  getTodoTypeName(type) {
    const map = { workload: '工作量' };
    return map[type] || '任务';
  },

  applyWecomMessageEntry(options) {
    const messageEntry = app.getWecomMessageEntry(options);
    if (!messageEntry || (messageEntry.scene !== 'home' && messageEntry.scene !== 'todo')) {
      return;
    }
    this.setData({
      messageEntry,
      messageEntryText: this.buildMessageEntryText(messageEntry)
    });
    if (messageEntry.route) {
      navigation.open(messageEntry.route);
    }
  },

  buildMessageEntryText(messageEntry) {
    const parts = ['来自企业微信消息'];
    if (messageEntry.sourceKey) {
      parts.push(`规则 ${messageEntry.sourceKey}`);
    }
    if (messageEntry.route) {
      parts.push('已带页面跳转');
    }
    return parts.join(' · ');
  },

  goTodo(e) {
    const route = e.currentTarget.dataset.route;
    if (!route) return;
    navigation.open(route);
  },

  goWorkload() {
    navigation.open('/pages/workload/index');
  },

  goKnowledge() {
    navigation.open('/pages/knowledge/list');
  },

  goDrill() {
    navigation.open('/pages/drill/list/list');
  },

  goDataCenter() {
    navigation.open('/pages/data-center/index');
  },

  goMine() {
    navigation.open('/pages/mine/mine');
  },

  goLogin() {
    wx.navigateTo({
      url: '/pages/login/login'
    });
  },

});
