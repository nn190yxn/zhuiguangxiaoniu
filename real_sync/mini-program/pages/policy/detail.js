const app = getApp();

Page({
  data: {
    policyId: null,
    notificationId: '',
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
      url: `/policy/detail.php?id=${id}`
    }).then(res => {
      const payload = res.data || {};
      const policy = payload.policy || payload;
      const readStatus = payload.read_status || {};
      this.setData({
        policy,
        notificationId: readStatus.notification_id || '',
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
    if (!this.data.notificationId) {
      wx.showToast({
        title: '未找到待确认通知',
        icon: 'none'
      });
      return;
    }
    app.request({
      url: '/policy/notify.php?action=confirm',
      method: 'POST',
      data: {
        id: this.data.notificationId,
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
