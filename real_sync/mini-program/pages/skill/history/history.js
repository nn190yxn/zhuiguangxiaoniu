const app = getApp();

Page({
  data: {
    currentFilter: "all",
    records: [],
    loading: true
  },

  onLoad() {
    this.fetchRecords();
  },

  onShow() {
    this.fetchRecords();
  },

  async changeFilter(e) {
    const filter = e.currentTarget.dataset.filter;
    this.setData({ currentFilter: filter, loading: true });
    await this.fetchRecords();
  },

  async fetchRecords() {
    try {
      const filterParam = this.data.currentFilter !== "all" ? `&scene_type=${this.data.currentFilter}` : "";
      const res = await app.request({
        url: `${app.globalData.apiBase}/skill/review-list.php${filterParam}`
      });

      if (res.code === 0 && res.data.records) {
        const sceneMap = {
          new_sale: "新签复盘",
          renewal: "续费复盘",
          assessment: "体测解读复盘"
        };

        const statusMap = {
          pending: "等待处理",
          transcribing: "语音转文字",
          analyzing: "AI 分析中",
          completed: "已完成",
          failed: "处理失败"
        };

        const records = res.data.records.map(r => ({
          ...r,
          sceneName: sceneMap[r.scene_type] || r.scene_type,
          statusText: statusMap[r.status] || r.status,
          createdAt: this.formatDate(r.created_at),
          level: r.ai_level || "default"
        }));

        this.setData({ records, loading: false });
      } else {
        this.setData({ records: [], loading: false });
      }
    } catch (err) {
      console.error("获取记录失败:", err);
      this.setData({ loading: false });
      wx.showToast({ title: "加载失败", icon: "none" });
    }
  },

  formatDate(dateStr) {
    if (!dateStr) return "";
    const d = new Date(dateStr);
    const month = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    const hour = String(d.getHours()).padStart(2, "0");
    const minute = String(d.getMinutes()).padStart(2, "0");
    return `${month}-${day} ${hour}:${minute}`;
  },

  viewDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({ url: `/pages/skill/result?record_id=${id}` });
  },

  goToRecord() {
    wx.navigateTo({ url: "/pages/skill/record" });
  }
});