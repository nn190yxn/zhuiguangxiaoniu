const app = getApp();

Page({
  data: {
    policyId: null,
    policy: {},
    isConfirmed: false,
    loading: false
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ policyId: options.id });
      this.loadPolicyDetail(options.id);
    }
  },

  loadPolicyDetail(id) {
    this.setData({ loading: true });

    app.request({
      url: `${app.globalData.apiBase}/policy/detail.php?id=${id}`
    }).then(res => {
      const payload = res.data || {};
      const policy = payload.policy || payload;
      const readStatus = payload.read_status || {};
      this.setData({
        policy,
        isConfirmed: !!(policy.is_confirmed || readStatus.is_confirmed),
        loading: false
      });
    }).catch(err => {
      console.error('加载制度详情失败:', err);
      this.setData({ loading: false });
      wx.showToast({
        title: '加载失败',
        icon: 'none'
      });
    });
  },

  confirmRead() {
    if (this.data.isConfirmed) return;

    wx.showModal({
      title: '确认阅读',
      content: '确认已阅读并理解该制度内容？',
      success: (res) => {
        if (res.confirm) {
          this.doConfirm();
        }
      }
    });
  },

  doConfirm() {
    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?action=confirm`,
      method: 'POST',
      data: {
        id: this.data.policyId,
        policy_id: this.data.policyId
      }
    }).then(() => {
      this.setData({ isConfirmed: true });
      wx.showToast({
        title: '确认成功',
        icon: 'success'
      });
    }).catch(err => {
      console.error('确认失败:', err);
      wx.showToast({
        title: '确认失败',
        icon: 'none'
      });
    });
  }
});
