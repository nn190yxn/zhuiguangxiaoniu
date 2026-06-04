const app = getApp();

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
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/script-knowledge.php?action=my_records&page=${this.data.pagination.page}&page_size=${this.data.pagination.pageSize}`
      });

      if (res.code === 0) {
        this.setData({
          records: this.normalizeRecords(res.data.records || []),
          pagination: res.data.pagination,
          hasMore: res.data.pagination.page < res.data.pagination.total_pages,
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

    try {
      const nextPage = this.data.pagination.page + 1;
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/script-knowledge.php?action=my_records&page=${nextPage}&page_size=${this.data.pagination.pageSize}`
      });

      if (res.code === 0) {
        const newRecords = [...this.data.records, ...this.normalizeRecords(res.data.records || [])];
        this.setData({
          records: newRecords,
          pagination: res.data.pagination,
          hasMore: res.data.pagination.page < res.data.pagination.total_pages
        });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
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

  normalizeRecords(records = []) {
    return records.map(item => ({
      ...item,
      displayDate: this.formatDate(item.created_at),
      levelName: this.getLevelName(item.level),
      intentName: this.getIntentName(item.customer_intent)
    }));
  },

  viewDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/drill/feedback/feedback?id=${id}&source=analysis`
    });
  }
});
