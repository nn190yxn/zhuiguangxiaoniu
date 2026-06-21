const api = require('../../../utils/api');

const STATUS_TEXTS = {
  pending: '正在排队...',
  transcribing: '正在将录音转为文字...',
  analyzing: 'AI 正在分析录音内容...',
};

Page({
  data: {
    recordId: '',
    sceneName: '',
    status: 'pending',
    statusText: '正在排队...',
    loading: true,
    result: null,
  },

  pollTimer: null,
  pollCount: 0,
  failCount: 0,

  onLoad(options) {
    const id = Number(options.id);
    if (!id) {
      wx.showToast({ title: '参数错误', icon: 'none' });
      setTimeout(() => wx.navigateBack({ delta: 1 }), 1500);
      return;
    }
    this.setData({
      recordId: String(id),
      sceneName: options.scene || '复盘分析',
    });
    this.startPoll();
  },

  onUnload() {
    if (this.pollTimer) {
      clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
  },

  startPoll() {
    this.pollCount = 0;
    this.failCount = 0;
    this.doPoll();
  },

  doPoll() {
    if (this.pollCount >= 60) {
      this.setData({ loading: false, statusText: '分析超时，请稍后查看' });
      return;
    }
    if (this.failCount >= 5) {
      this.setData({ loading: false, statusText: '网络连接失败，请稍后重试' });
      return;
    }

    this.pollCount++;

    api.get('/skill/review-list.php', {
      data: { record_id: this.data.recordId },
      redirectOnUnauthorized: false,
    })
      .then((res) => {
        this.failCount = 0;
        const record = res.data && res.data.record;

        if (!record) {
          this.pollTimer = setTimeout(() => this.doPoll(), 3000);
          return;
        }

        if (record.status === 'completed') {
          if (this.pollTimer) clearTimeout(this.pollTimer);
          const aiReport = this._parseReport(record.ai_report);
          this.setData({
            loading: false,
            status: 'completed',
            statusText: '分析完成',
            result: Object.assign({}, record, { ai_report: aiReport }),
          });
        } else if (record.status === 'failed') {
          if (this.pollTimer) clearTimeout(this.pollTimer);
          this.setData({
            loading: false,
            status: 'failed',
            statusText: record.error_message || '分析失败',
          });
        } else {
          this.setData({
            status: record.status,
            statusText: STATUS_TEXTS[record.status] || '正在处理中...',
          });
          this.pollTimer = setTimeout(() => this.doPoll(), 3000);
        }
      })
      .catch(() => {
        this.failCount++;
        this.pollTimer = setTimeout(() => this.doPoll(), 3000);
      });
  },

  _parseReport(report) {
    if (!report) return '';
    if (typeof report === 'string') {
      try {
        const parsed = JSON.parse(report);
        if (typeof parsed === 'object') {
          let text = '';
          for (const key in parsed) {
            if (Object.prototype.hasOwnProperty.call(parsed, key)) {
              text += `## ${key}\n\n${parsed[key]}\n\n`;
            }
          }
          return text.trim();
        }
        return report;
      } catch (e) {
        return report;
      }
    }
    if (typeof report === 'object') {
      let text = '';
      for (const key in report) {
        if (Object.prototype.hasOwnProperty.call(report, key)) {
          text += `## ${key}\n\n${report[key]}\n\n`;
        }
      }
      return text.trim();
    }
    return String(report);
  },

  goBackToList() {
    wx.navigateBack({ delta: 1 });
  },

  goUpload() {
    wx.redirectTo({ url: '/pages/skill-review/upload/upload' });
  },
});
