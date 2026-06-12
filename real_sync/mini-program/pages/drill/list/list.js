const app = getApp();

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
    },
    scenarioGroups: []
  },

  onLoad() {
    this.initScenarioGroups();
    this.loadDrills();
  },

  initScenarioGroups() {
    const userInfo = app.globalData.userInfo || {};
    const role = userInfo.role || 'sales';
    const salesGroups = [
      { title: '首次电话', desc: '建立信任、确认需求、推进微信或到店邀约', action: '练邀约', target: 'freeChat' },
      { title: '微信破冰', desc: '承接新资源，完成需求挖掘和课程价值表达', action: '看话术', target: 'scriptKnowledge' },
      { title: '到店接待', desc: '围绕学员情况做接待、介绍和试听前铺垫', action: '看知识', target: 'knowledge' },
      { title: '异议处理', desc: '处理价格、时间、效果和家长顾虑', action: '练异议', target: 'freeChat' },
    ];
    const coachGroups = [
      { title: '课后反馈', desc: '向家长讲清本节表现、训练重点和家庭配合', action: '练反馈', target: 'freeChat' },
      { title: '体测沟通', desc: '解释体测结果、训练建议和阶段目标', action: '看知识', target: 'knowledge' },
      { title: '续费沟通', desc: '结合训练效果和阶段计划做续费沟通', action: '看话术', target: 'scriptKnowledge' },
    ];
    this.setData({ scenarioGroups: role === 'coach' ? coachGroups : salesGroups });
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

    const userInfo = app.globalData.userInfo;
    const role = userInfo?.role || 'sales';

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/list.php?role=${role}`
      });

      if (res.code === 0) {
        let list = (res.data.list || []).map(d => this.normalizeDrill(d));

        if (this.data.currentStatus) {
          list = list.filter(d => d.user_status === this.data.currentStatus);
        }

        this.setData({ list, loading: false });
      }
    } catch (err) {
      this.setData({ loading: false });
      wx.showToast({ title: '加载失败', icon: 'none' });
    }
  },

  goToDrill(e) {
    wx.navigateTo({
      url: `/pages/drill/doing/doing?id=${e.currentTarget.dataset.id}`
    });
  },

  goToFreeChat() {
    wx.navigateTo({
      url: '/pages/drill/free-chat/free-chat'
    });
  },

  goScenario(e) {
    const target = e.currentTarget.dataset.target;
    if (target === 'scriptKnowledge') {
      wx.navigateTo({ url: '/pages/drill/script-knowledge/script-knowledge' });
      return;
    }
    if (target === 'knowledge') {
      wx.switchTab({ url: '/pages/knowledge/list' });
      return;
    }
    this.goToFreeChat();
  },

  normalizeDrill(drill) {
    const status = drill.user_status || 'pending';
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
      status_class: status,
      status_text: this.data.statusNames[status] || '待开始',
      step_badges: stepBadges
    };
  }
});
