const app = getApp();

Page({
  data: {
    dimensions: [],
    currentDimension: null,
    scripts: [],
    loading: true
  },

  onLoad() {
    this.loadDimensions();
  },

  onShow() {
    // 每次进入页面刷新数据，确保数据同步
    if (this.data.currentDimension) {
      this.loadScripts(this.data.currentDimension);
    }
  },

  async loadDimensions() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/script-knowledge.php?action=list`
      });

      if (res.code === 0) {
        const dimensions = (res.data.dimensions || []).map(item => ({
          ...item,
          dimensionName: this.getDimensionName(item.dimension_code)
        }));
        this.setData({ dimensions });

        // 默认选中第一个维度
        if (dimensions.length > 0) {
          this.setData({ currentDimension: dimensions[0].dimension_code });
          this.loadScripts(dimensions[0].dimension_code);
        }
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  async loadScripts(dimension) {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/script-knowledge.php?dimension=${dimension}`
      });

      if (res.code === 0) {
        this.setData({
          currentDimension: dimension,
          scripts: res.data.scripts || [],
          loading: false
        });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
      this.setData({ loading: false });
    } finally {
      wx.hideLoading();
    }
  },

  selectDimension(e) {
    const dimension = e.currentTarget.dataset.dimension;
    if (dimension !== this.data.currentDimension) {
      this.loadScripts(dimension);
    }
  },

  goToDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/drill/script-detail/script-detail?id=${id}`
    });
  },

  getDimensionName(code) {
    const names = {
      'qa': '问答话术',
      'knowledge': '专业讲解',
      'feedback': '点评反馈',
      'deal': '谈单录音'
    };
    return names[code] || code;
  }
});
