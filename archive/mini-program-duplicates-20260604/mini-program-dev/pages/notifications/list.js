const app = getApp();

Page({
  data: {
    notifications: [],
    loading: false,
    page: 1,
    hasMore: true
  },

  onLoad() {
    this.loadNotifications();
  },

  onShow() {
    // 刷新
    this.setData({ page: 1, notifications: [] });
    this.loadNotifications();
  },

  onReachBottom() {
    if (this.data.hasMore && !this.data.loading) {
      this.loadNotifications(true);
    }
  },

  loadNotifications(isLoadMore = false) {
    if (this.data.loading) return;

    const page = isLoadMore ? this.data.page + 1 : 1;
    this.setData({ loading: true });

    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?page=${page}&page_size=20`
    }).then(res => {
      const newList = (res.data.list || []).map(item => {
        item.typeName = this.getTypeName(item.type);
        return item;
      });

      const notifications = isLoadMore
        ? [...this.data.notifications, ...newList]
        : newList;

      this.setData({
        notifications,
        page,
        hasMore: newList.length === 20,
        loading: false
      });
    }).catch(err => {
      console.error('加载通知失败:', err);
      this.setData({ loading: false });
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

  viewNotification(e) {
    const id = e.currentTarget.dataset.id;
    const item = this.data.notifications.find(n => n.id === id);

    // 标记已读
    if (item && !item.is_read) {
      this.markRead(id);
    }

    wx.navigateTo({
      url: `/pages/notifications/detail?id=${id}`
    });
  },

  markRead(id) {
    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?action=read`,
      method: 'POST',
      data: { id }
    }).then(() => {
      // 更新本地状态
      const notifications = this.data.notifications.map(n => {
        if (n.id === id) {
          n.is_read = true;
        }
        return n;
      });
      this.setData({ notifications });
    }).catch(err => {
      console.error('标记已读失败:', err);
    });
  },

  confirmNotify(e) {
    e.stopPropagation();

    const id = e.currentTarget.dataset.id;

    wx.showModal({
      title: '确认阅读',
      content: '确认已阅读并理解该制度内容？',
      success: (res) => {
        if (res.confirm) {
          this.doConfirm(id);
        }
      }
    });
  },

  doConfirm(id) {
    app.request({
      url: `${app.globalData.apiBase}/policy/notify.php?action=confirm`,
      method: 'POST',
      data: { id }
    }).then(() => {
      wx.showToast({
        title: '确认成功',
        icon: 'success'
      });

      // 更新本地状态
      const notifications = this.data.notifications.map(n => {
        if (n.id === id) {
          n.is_confirmed = true;
        }
        return n;
      });
      this.setData({ notifications });
    }).catch(err => {
      wx.showToast({
        title: '确认失败',
        icon: 'none'
      });
    });
  }
});