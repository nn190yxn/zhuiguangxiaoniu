const drill = require('../../../utils/drill-v2');

Page({
  data: {
    script: null,
    loading: true
  },

  onLoad(options) {
    if (options.id) {
      this.loadDetail(options.id);
    }
  },

  async loadDetail(id) {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await drill.request('/catalog.php');

      if (res.code === 0) {
        this.setData({
          script: (res.data.items || []).find(item => String(item.scenario_version_id) === String(id)) || {},
          loading: false
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  copyScript() {
    if (!this.data.script) return;
    wx.setClipboardData({
      data: this.data.script.standard_script,
      success: () => {
        wx.showToast({ title: '已复制', icon: 'success' });
      }
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
