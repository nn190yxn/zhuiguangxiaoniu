const drill = require('../../../utils/drill-v2');

Page({
  data: {
    currentStatus: '',
    list: [],
    loading: false,
    statusNames: {
      pending: '待开始',
      learning: '学习中',
      practicing: '进行中',
      completed: '已完成'
    }
  },

  onLoad() {
    this.loadDrills();
  },

  onShow() {
    this.loadDrills();
  },

  onPullDownRefresh() {
    this.loadDrills().finally(() => wx.stopPullDownRefresh());
  },

  selectFilter(e) {
    const status = e.currentTarget.dataset.status;
    this.setData({ currentStatus: status });
  },

  async loadDrills() {
    this.setData({ loading: true });

    try {
      const dashboard = await drill.loadDashboard();
      let list = (dashboard.assignments.items || []).map(d => this.normalizeDrill(d));

      if (this.data.currentStatus) {
        list = list.filter(d => d.user_status === this.data.currentStatus);
      }

      const requiredVersion = list.map(item => item.minimum_client_version).filter(Boolean).sort().pop();
      const currentVersion = ((wx.getAccountInfoSync().miniProgram || {}).version || '0.0.0');
      const minimumVersionMessage = requiredVersion && this.isVersionBelow(currentVersion, requiredVersion)
        ? `请升级小程序至 ${requiredVersion} 后继续演练`
        : '';
      this.setData({ list, loading: false, dashboard, minimumVersionMessage });
    } catch (err) {
      this.setData({ loading: false });
      wx.showToast({ title: '加载失败', icon: 'none' });
    }
  },

  goToDrill(e) {
    wx.navigateTo({
      url: `/pages/drill/doing/doing?id=${e.currentTarget.dataset.id}&assignment_id=${e.currentTarget.dataset.id}&plan_item_id=${e.currentTarget.dataset.planItemId}`
    });
  },

  goToFreeChat() {
    wx.navigateTo({
      url: '/pages/drill/free-chat/free-chat'
    });
  },

  goToQa() {
    wx.navigateTo({ url: '/pages/drill/free-chat/free-chat?mode=qa' });
  },

  goToFlow() {
    wx.navigateTo({ url: '/pages/drill/free-chat/free-chat?mode=flow' });
  },

  goToExam() {
    wx.navigateTo({ url: '/pages/exam/list' });
  },

  normalizeDrill(drill) {
    const status = drill.status || drill.user_status || 'pending';
    const stepLabels = ['学习', '背诵', '演练', '通关'];
    const stepBadges = [1, 2, 3, 4].map(step => {
      let className = '';
      if (drill.step_status && drill.step_status[step] === 'completed') {
        className = 'completed';
      } else if (step === drill.current_step && status !== 'completed') {
        className = 'active';
      }

      return {
        step,
        label: stepLabels[step - 1],
        class_name: className
      };
    });

    return {
      ...drill,
      id: drill.assignment_id || drill.id,
      plan_item_id: (drill.items && drill.items[0] && drill.items[0].plan_item_id) || drill.plan_item_id,
      title: drill.plan_name || drill.title,
      description: drill.domain_code || drill.description || '',
      user_status: status,
      status_class: status,
      status_text: this.data.statusNames[status] || '待开始',
      step_badges: stepBadges
    };
  },
  isVersionBelow(current, required) {
    const currentParts = current.split('.').map(Number);
    const requiredParts = required.split('.').map(Number);
    for (let index = 0; index < 3; index += 1) {
      const difference = (currentParts[index] || 0) - (requiredParts[index] || 0);
      if (difference !== 0) return difference < 0;
    }
    return false;
  }
});
