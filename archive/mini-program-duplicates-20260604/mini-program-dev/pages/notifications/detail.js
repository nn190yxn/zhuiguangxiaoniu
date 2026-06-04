const app = getApp();

Page({
  data: {
    notificationId: null,
    notification: {}
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ notificationId: options.id });
      this.loadNotificationDetail(options.id);
    }
  },

  loadNotificationDetail(id) {
    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?id=${id}`
    }).then(res => {
      const notification = res.data;
      notification.typeName = this.getTypeName(notification.type);
      this.setData({ notification });
    }).catch(err => {
      console.error('加载通知详情失败:', err);
      wx.showToast({
        title: '加载失败',
        icon: 'none'
      });
    });
  },

  getTypeName(type) {
    const map = {
      'update': '更新通知',
      'reminder': '待办提醒',
      'confirm': '待确认'
    };
    return map[type] || '通知';
  },

  goToPolicy() {
    const policyId = this.data.notification.policy_id;
    if (policyId) {
      wx.navigateTo({
        url: `/pages/policy/detail?id=${policyId}`
      });
    }
  },

  confirmRead() {
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
      data: { id: this.data.notificationId }
    }).then(() => {
      const notification = this.data.notification;
      notification.is_confirmed = true;
      this.setData({ notification });
      wx.showToast({
        title: '确认成功',
        icon: 'success'
      });
    }).catch(err => {
      wx.showToast({
        title: '确认失败',
        icon: 'none'
      });
    });
  }
});