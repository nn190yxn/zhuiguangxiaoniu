const auth = require('../../utils/auth');

const ALLOWED_TARGETS = {
  summerAssessment: '/summer-camp-assessment-app.html',
  summerHistory: '/summer-camp-history.html',
};

Page({
  data: {
    pageTitle: '页面加载中',
    src: '',
    errorMsg: '',
  },

  onLoad(options) {
    const app = getApp();
    const key = String(options.target || '');
    const targetPath = ALLOWED_TARGETS[key] || '';
    const token = auth.getToken() || app.globalData.token || '';

    if (!targetPath) {
      this.setData({ pageTitle: '页面不可用', errorMsg: '无效的页面入口' });
      return;
    }
    if (!token) {
      this.setData({ pageTitle: '请先登录', errorMsg: '登录状态已失效，请重新登录后再打开' });
      return;
    }

    const pageTitle = key === 'summerHistory' ? '暑假班评估记录' : '暑假班学员评估';
    const baseUrl = app.globalData.apiBase.replace(/\/api\/?$/, '');
    const src = `${baseUrl}/mini-program-bridge.html?target=${encodeURIComponent(targetPath)}&token=${encodeURIComponent(token)}`;

    this.setData({ pageTitle, src, errorMsg: '' });
    wx.setNavigationBarTitle({ title: pageTitle });
  },
});
