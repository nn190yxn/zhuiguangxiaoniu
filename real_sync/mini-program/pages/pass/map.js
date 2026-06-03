const app = getApp();

Page({
  data: {
    currentRole: 'sales',
    stages: [],
    userRole: 'sales'
  },

  onLoad() {
    const userInfo = app.globalData.userInfo;
    const userRole = (userInfo?.role || 'sales');
    const currentRole = userRole;
    const canSwitchRole = userRole === 'admin' || userRole === 'manager';
    this.setData({ userRole, currentRole, canSwitchRole });
    this.loadPassMap();
  },

  onPullDownRefresh() {
    this.loadPassMap().finally(() => wx.stopPullDownRefresh());
  },

  selectRole(e) {
    const role = e.currentTarget.dataset.role;
    if (!this.data.canSwitchRole && role !== this.data.userRole) return;
    this.setData({ currentRole: role, stages: [] });
    this.loadPassMap();
  },

  async loadPassMap() {
    wx.showLoading({ title: '加载中...' });

    const role = this.data.canSwitchRole
      ? this.data.currentRole
      : this.data.userRole;

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/pass/map.php?role=${role}`
      });

      if (res.code === 0) {
        this.setData({ stages: (res.data.stages || []).map(stage => this.normalizeStage(stage)) });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  goToStage(e) {
    const stageId = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/pass/stage?stage_id=${stageId}&role=${this.data.currentRole}`
    });
  },

  normalizeStage(stage) {
    const statusNames = {
      locked: '未解锁',
      active: '进行中',
      completed: '已通关'
    };
    return {
      ...stage,
      status_text: statusNames[stage.status] || '未解锁'
    };
  }
});
