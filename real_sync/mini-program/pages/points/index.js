const app = getApp();

Page({
  data: {
    totalPoints: 0,
    todayChecked: false,
    continuousDays: 0,
    accumulatedPoints: 0,
    rules: [],
    records: [],
    exchangeItems: [],
    activeTab: 'rules',
    loadingRecords: false,
    showModal: false,
    selectedItem: {},
    formData: {}
  },

  onLoad() {
    this.loadPointsInfo();
  },

  onShow() {
    if (this.data.activeTab === 'records') {
      this.loadRecords();
    } else if (this.data.activeTab === 'exchange') {
      this.loadExchangeItems();
    }
  },

  async loadPointsInfo() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/points/index.php`
      });
      if (res.code === 0) {
        this.setData({
          totalPoints: res.data.total_points,
          todayChecked: res.data.today_checked,
          continuousDays: res.data.continuous_days,
          accumulatedPoints: res.data.accumulated_points,
          rules: res.data.rules || []
        });
      }
    } catch (err) {
      console.error('加载失败:', err);
    }
  },

  async loadRecords() {
    this.setData({ loadingRecords: true });
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/points/records.php?page=1&page_size=50`
      });
      if (res.code === 0) {
        this.setData({ records: res.data.list || [] });
      }
    } catch (err) {
      console.error('加载失败:', err);
    } finally {
      this.setData({ loadingRecords: false });
    }
  },

  async loadExchangeItems() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/points/exchange.php`
      });
      if (res.code === 0) {
        this.setData({ exchangeItems: (res.data.items || []).map(item => this.normalizeExchangeItem(item)) });
      }
    } catch (err) {
      console.error('加载失败:', err);
    }
  },

  switchTab(e) {
    const tab = e.currentTarget.dataset.tab;
    this.setData({ activeTab: tab });

    if (tab === 'records') {
      this.loadRecords();
    } else if (tab === 'exchange') {
      this.loadExchangeItems();
    }
  },

  async doCheckin() {
    if (this.data.todayChecked) return;

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/points/checkin.php`,
        method: 'POST'
      });
      if (res.code === 0) {
        this.setData({
          todayChecked: true,
          totalPoints: res.data.balance,
          continuousDays: res.data.continuous_days
        });
        wx.showToast({
          title: `签到成功！+${res.data.points}积分`,
          icon: 'success'
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '签到失败', icon: 'none' });
    }
  },

  openExchange(e) {
    const { id, title, points } = e.currentTarget.dataset;
    if (this.data.totalPoints < points) {
      wx.showToast({ title: '积分不足，无法兑换', icon: 'none' });
      return;
    }
    this.setData({
      showModal: true,
      selectedItem: { id, title, points },
      formData: {}
    });
  },

  closeModal() {
    this.setData({ showModal: false });
  },

  submitExchange(e) {
    const formData = e.detail.value;
    if (!formData.receiver_name || !formData.receiver_phone || !formData.receiver_address) {
      wx.showToast({ title: '请填写完整信息', icon: 'none' });
      return;
    }

    wx.showLoading({ title: '提交中...' });

    app.request({
      url: `${app.globalData.apiBase}/points/exchange.php?action=exchange`,
      method: 'POST',
      data: {
        item_id: this.data.selectedItem.id,
        ...formData
      }
    }).then(res => {
      if (res.code === 0) {
        this.closeModal();
        this.setData({ totalPoints: res.data.balance });
        wx.showToast({ title: '兑换成功！', icon: 'success' });
        this.loadExchangeItems();
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    }).catch(err => {
      wx.showToast({ title: '兑换失败', icon: 'none' });
    }).finally(() => {
      wx.hideLoading();
    });
  },

  normalizeExchangeItem(item) {
    const coverImage = this.normalizeImageUrl(item.cover_image);
    return {
      ...item,
      cover_image: coverImage,
      coverStyle: coverImage ? `background-image: url('${coverImage}')` : ''
    };
  },

  normalizeImageUrl(url) {
    if (!url || url === 'null' || url === 'undefined') return '';
    return String(url);
  }
});
