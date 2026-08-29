const app = getApp();
const navigation = require('../../utils/navigation');

Page({
  data: { month: '', updatedAt: '', loading: false, error: '', personal: null, manager: null },
  onLoad() { this.setData({ month: this.currentMonth() }); this.load(); },
  onShow() { if (this.data.month) this.load(); },
  currentMonth() { const date = new Date(); return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`; },
  load() {
    this.setData({ loading: true, error: '' });
    app.request({ url: `/statistics/mini-program-summary.php?month=${this.data.month}` })
      .then(res => this.setData({ personal: res.data.personal || null, manager: res.data.manager || null, updatedAt: res.data.updated_at || '', loading: false }))
      .catch(error => this.setData({ loading: false, error: error.message || '数据加载失败' }));
  },
  onMonthChange(e) { this.setData({ month: e.detail.value }); this.load(); },
  goWorkload() { navigation.open('/pages/workload/index'); },
  goDrill() { navigation.open('/pages/drill/list/list'); },
  goExam() { navigation.open('/pages/exam/list'); },
  goMine() { navigation.open('/pages/mine/mine'); },
  goManager() { navigation.open('/pages/workload/manage'); }
});
