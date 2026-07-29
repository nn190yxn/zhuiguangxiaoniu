const drill = require('../../../utils/drill-v2');

Page({
  data: {
    records: [],
    loading: true,
    pagination: {
      page: 1,
      pageSize: 10,
      total: 0,
      totalPages: 0
    },
    hasMore: false
  },

  onLoad(options) {
    if (options.dimension) {
      this.setData({ dimension: options.dimension });
    }
    this.loadRecords();
  },

  onShow() {
    this.refreshRecords();
  },

  onReachBottom() {
    if (this.data.hasMore) {
      this.loadMore();
    }
  },

  async loadRecords() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await drill.request('/results.php');
      if (res.data) {
        const records = res.data.items || [];
        this.setData({
          records,
          pagination: { ...this.data.pagination, total: records.length, totalPages: 1 },
          hasMore: false,
          loading: false
        });
      } else {
        wx.showToast({ title: res.message || '加载失败', icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  async loadMore() {
    if (!this.data.hasMore || this.data.loading) return;

    this.setData({ loading: true });

    this.setData({ loading: false, hasMore: false });
  },

  async refreshRecords() {
    this.setData({
      pagination: { ...this.data.pagination, page: 1 },
      hasMore: false
    });
    await this.loadRecords();
  },

  getLevelName(level) {
    const levelMap = {
      'excellent': '优秀',
      'good': '良好',
      'pass': '合格',
      'fail': '不合格'
    };
    return levelMap[level] || level || '-';
  },

  getLevelColor(level) {
    const colorMap = {
      'excellent': '#52c41a',
      'good': '#1890ff',
      'pass': '#faad14',
      'fail': '#f5222d'
    };
    return colorMap[level] || '#999';
  },

  getIntentName(intent) {
    const intentMap = {
      'high': '高意向',
      'medium': '中意向',
      'low': '低意向'
    };
    return intentMap[intent] || '-';
  },

  getIntentColor(intent) {
    const colorMap = {
      'high': '#52c41a',
      'medium': '#faad14',
      'low': '#f5222d'
    };
    return colorMap[intent] || '#999';
  },

  formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = now - date;
    const day = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (day === 0) {
      return '今天 ' + date.toTimeString().slice(0, 5);
    } else if (day === 1) {
      return '昨天 ' + date.toTimeString().slice(0, 5);
    } else if (day < 7) {
      return day + '天前';
    } else {
      return date.toLocaleDateString('zh-CN');
    }
  },

  viewDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/drill/feedback/feedback?id=${id}&source=analysis`
    });
  }
});
