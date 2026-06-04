const app = getApp();

Page({
  data: {
    feedbackId: null,
    taskId: null,
    feedback: null,
    loading: true
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

    if (options.id && options.source === 'analysis') {
      this.loadAnalysisFeedback();
    } else if (options.id) {
      this.loadFeedback();
    } else if (options.task_id) {
      this.loadFeedbackList();
    }
  },

  async loadAnalysisFeedback() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/script-knowledge.php?action=my_feedback_detail&id=${this.data.feedbackId}`
      });

      if (res.code === 0) {
        this.setData({
          feedback: this.normalizeFeedback(res.data),
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

  async loadFeedback() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/recording-feedback.php?recording_id=${this.data.feedbackId}`
      });

      if (res.code === 0) {
        this.setData({
          feedback: this.normalizeFeedback(res.data),
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

  async loadFeedbackList() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/recording-feedback.php?task_id=${this.data.taskId}`
      });

      if (res.code === 0) {
        this.setData({
          feedbackList: this.normalizeFeedbackList(res.data.list || []),
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
  },

  normalizeFeedback(feedback = {}) {
    const dimensionScores = (feedback.dimension_scores || []).map(item => ({
      ...item,
      scoreColor: this.getScoreColor(item.score),
      weightPercent: Math.round(Number(item.weight || 0) * 100)
    }));
    const stageScores = (feedback.stage_scores || []).map(item => ({
      ...item,
      scoreColor: this.getScoreColor(item.score)
    }));
    return {
      ...feedback,
      totalScoreColor: this.getScoreColor(feedback.total_score),
      levelName: this.getLevelName(feedback.level),
      durationText: this.formatDuration(feedback.audio_duration),
      dimension_scores: dimensionScores,
      stage_scores: stageScores
    };
  },

  normalizeFeedbackList(list = []) {
    return list.map(item => ({
      ...item,
      totalScoreColor: this.getScoreColor(item.total_score),
      levelName: this.getLevelName(item.level),
      durationText: this.formatDuration(item.audio_duration)
    }));
  }
});
