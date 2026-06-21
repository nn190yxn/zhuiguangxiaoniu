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
    }
  },

  onLoad() {
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
