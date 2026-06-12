const app = getApp();

Page({
  data: {
    recordId: 0,
    status: "pending",
    statusText: "等待处理",
    statusDesc: "录音已上传，正在排队处理",
    aiScore: 0,
    aiLevel: "",
    aiReport: "",
    transcriptText: "",
    sceneName: "",
    errorMessage: "",
    showTranscript: false,
    scoreLevel: "default"
  },

  pollTimer: null,

  onLoad(options) {
    const recordId = parseInt(options.record_id, 10);
    if (recordId > 0) {
      this.setData({ recordId });
      this.pollResult();
    } else {
      wx.showToast({ title: "参数错误", icon: "none" });
      setTimeout(() => wx.navigateBack(), 1500);
    }
  },

  onUnload() {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
    }
  },

  pollResult() {
    this.fetchResult();

    this.pollTimer = setInterval(() => {
      if (this.data.status === "completed" || this.data.status === "failed") {
        clearInterval(this.pollTimer);
        return;
      }
      this.fetchResult();
    }, 3000);
  },

  async fetchResult() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/skill/review-list.php?record_id=${this.data.recordId}`
      });

      if (res.code === 0 && res.data.record) {
        const record = res.data.record;
        const statusMap = {
          pending: { text: "等待处理", desc: "录音已上传，正在排队处理" },
          transcribing: { text: "语音转文字", desc: "正在将录音转换为文字..." },
          analyzing: { text: "AI 分析中", desc: "正在分析录音内容并评分..." },
          completed: { text: "已完成", desc: "" },
          failed: { text: "处理失败", desc: record.error_message || "处理过程中发生错误" }
        };

        const sceneMap = {
          new_sale: "新签复盘",
          renewal: "续费复盘",
          assessment: "体测解读复盘"
        };

        this.setData({
          status: record.status,
          statusText: statusMap[record.status]?.text || record.status,
          statusDesc: statusMap[record.status]?.desc || "",
          aiScore: record.ai_score || 0,
          aiLevel: record.ai_level || "",
          aiReport: record.ai_report || "",
          transcriptText: record.transcript_text || "",
          sceneName: sceneMap[record.scene_type] || record.scene_type,
          errorMessage: record.error_message || "",
          scoreLevel: record.ai_level || "default"
        });
      }
    } catch (err) {
      console.error("获取结果失败:", err);
    }
  },

  toggleTranscript() {
    this.setData({ showTranscript: !this.data.showTranscript });
  },

  goBack() {
    wx.navigateBack();
  },

  goToHistory() {
    wx.navigateTo({ url: "/pages/skill/history/history" });
  }
});
