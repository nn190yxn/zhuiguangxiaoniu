const app = getApp();

Page({
  data: {
    title: ''
  },
  onLoad(options) {
    this.setData({
      title: decodeURIComponent(options.title || '问卷')
    });
  },
  goHome() {
    wx.switchTab({ url: '/pages/index/index' });
  }
});
