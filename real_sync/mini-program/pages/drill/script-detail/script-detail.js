const app = getApp();

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
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/script-knowledge.php?action=detail&id=${id}`
      });

      if (res.code === 0) {
        this.setData({
          script: this.normalizeScript(res.data),
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
  },

  normalizeScript(script = {}) {
    return {
      ...script,
      dimensionName: this.getDimensionName(script.dimension_code)
    };
  }
});
