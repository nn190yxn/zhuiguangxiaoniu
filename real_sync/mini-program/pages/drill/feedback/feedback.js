const drill = require('../../../utils/drill-v2');
const viewState = require('../../../utils/view-state');

Page({
  data: {
    feedbackId: null,
    taskId: null,
    feedback: null,
    loading: true,
    feedbackState: viewState.readState('loading')
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ feedbackId: options.id });
    }
    if (options.task_id) {
      this.setData({ taskId: options.task_id });
    }
    if (options.source) {
      this.setData({ source: options.source });
    }

    this.loadFeedback();
  },

  async loadFeedback() {
    wx.showLoading({ title: '加载中...' });

    try {
      const response = await drill.request(`/results.php${this.data.feedbackId ? `?attempt_id=${this.data.feedbackId}` : ''}`);
      const items = response.data.items || [];
      if (items.length) {
        this.setData({
          feedback: items[0],
          feedbackList: items,
          loading: false,
          feedbackState: viewState.readState('ready')
        });
      } else {
        this.setData({ feedback: null, feedbackList: [], loading: false, feedbackState: viewState.readState('empty') });
      }
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
      this.setData({ loading: false, feedbackState: viewState.fromError(err, '反馈加载失败', 'loadFeedback') });
    } finally {
      wx.hideLoading();
    }
  },

  playAudio(e) {
    const url = e.currentTarget.dataset.url;
    if (!url) return;

    const audioContext = wx.createInnerAudioContext();
    audioContext.src = url;
    audioContext.play();

    audioContext.onPlay(() => {
      wx.showToast({ title: '播放中', icon: 'none' });
    });

    audioContext.onError(() => {
      wx.showToast({ title: '播放失败', icon: 'none' });
    });
  },

  getScoreColor(score) {
    if (score >= 90) return '#4caf50';
    if (score >= 75) return '#8bc34a';
    if (score >= 60) return '#ff9800';
    return '#f44336';
  },

  getLevelName(level) {
    const levelMap = {
      'excellent': '优秀',
      'good': '良好',
      'pass': '合格',
      'fail': '不合格'
    };
    return levelMap[level] || level;
  },

  formatDuration(seconds) {
    if (!seconds) return '0秒';
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return mins > 0 ? `${mins}分${secs}秒` : `${secs}秒`;
  }
});
