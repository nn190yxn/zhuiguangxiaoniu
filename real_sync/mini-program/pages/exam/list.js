const app = getApp();
const navigation = require('../../utils/navigation');

Page({
  data: { list: [], history: [], month: '', loading: false, error: '', level: '' },

  onLoad() {
    this.setData({ month: this.currentMonth() });
    this.load();
  },

  onShow() {
    if (this.data.month) this.load();
  },

  currentMonth() {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  },

  async load() {
    this.setData({ loading: true, error: '' });
    try {
      const [listRes, historyRes] = await Promise.all([
        app.request({ url: `/exam/list.php?level=${encodeURIComponent(this.data.level)}` }),
        app.request({ url: `/exam/history.php?month=${this.data.month}` })
      ]);
      this.setData({ list: listRes.data.list || [], history: historyRes.data.list || [], loading: false });
    } catch (error) {
      this.setData({ loading: false, error: error.message || '考核加载失败' });
    }
  },

  selectLevel(e) {
    this.setData({ level: e.currentTarget.dataset.level || '' });
    this.load();
  },

  startExam(e) {
    const examId = e.currentTarget.dataset.id;
    navigation.open(`/pages/exam/exam?id=${examId}`);
  },

  onMonthChange(e) {
    this.setData({ month: e.detail.value });
    this.load();
  }
});
