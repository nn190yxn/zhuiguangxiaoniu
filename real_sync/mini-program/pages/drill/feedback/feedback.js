const drill = require('../../../utils/drill-v2');
const api = require('../../../utils/api');
const media = require('../../../utils/media');
const viewState = require('../../../utils/view-state');

let audioContext = null;
let feedbackTimer = null;

const dimensionNames = {
  needs_discovery: '需求挖掘',
  fab_conversion: 'FAB 价值转化',
  case_insertion: '案例植入',
  solution_planning: '方案匹配与规划',
  pricing_negotiation: '报价与谈判策略',
  objection_handling: '异议处理',
  trial_close: '试关闭',
  urgency_creation: '紧迫感制造'
};

function valueOr(value, fallback) {
  return value === undefined || value === null ? fallback : value;
}

function normalizeFeedbackItem(item) {
  const feedback = media.normalizeMediaFields(item || {}, ['audio_url']);
  const cloudAudio = media.normalizeMediaDescriptor(feedback.audio_media || feedback.cloud_media || feedback.audio_file || feedback.audio_url_media, 'audio_url');
  if (cloudAudio.ready) feedback.audio_url_media = cloudAudio;
  feedback.audio_playable = Boolean(feedback.audio_url || (feedback.audio_url_media && feedback.audio_url_media.ready));
  feedback.level = feedback.level || feedback.evaluation_grade || '';
  feedback.feedback = feedback.feedback || (feedback.report && feedback.report.overall_conclusion) || '';
  feedback.suggestions = feedback.suggestions && feedback.suggestions.length
    ? feedback.suggestions
    : ((feedback.report && feedback.report.priority_improvements) || []);
  const rawDimensions = (feedback.report && feedback.report.dimension_scores) || feedback.dimension_scores || {};
  const evidence = feedback.evidence || (feedback.report && feedback.report.evidence) || [];
  feedback.dimension_scores = Array.isArray(rawDimensions)
    ? rawDimensions
    : Object.keys(rawDimensions).map(code => ({ code, name: code, ...(rawDimensions[code] || {}) }));
  feedback.dimension_scores = feedback.dimension_scores.map(dimension => ({
    ...dimension,
    name: dimensionNames[dimension.code] || dimension.name || dimension.dimension_name || dimension.code,
    score: Number(valueOr(dimension.score, valueOr(dimension.capability_score, 0))),
    max_score: Number(valueOr(dimension.max_score, valueOr(dimension.weight, 100))),
    evidence: dimension.evidence || evidence.filter(item => item.dimension_code === dimension.code)
  }));
  feedback.dimension_scores = feedback.dimension_scores.map(dimension => ({
    ...dimension,
    score_percent: dimension.max_score > 0 ? Math.round(dimension.score / dimension.max_score * 100) : 0
  }));
  feedback.evidence = evidence;
  feedback.deal_risk = feedback.deal_risk || (feedback.report && feedback.report.deal_risk) || '';
  feedback.replacement_scripts = (feedback.replacement_scripts || (feedback.report && feedback.report.replacement_scripts) || []).map(item => (
    typeof item === 'string' ? item : (item.script || item.replacement || item.text || '')
  )).filter(Boolean);
  feedback.critical_results = feedback.critical_results || (feedback.report && feedback.report.critical_results) || {};
  const rawRisks = (feedback.report && feedback.report.critical_risks) || [];
  feedback.critical_risks = Array.isArray(rawRisks)
    ? rawRisks
    : Object.keys(rawRisks).map(code => ({ code, ...(rawRisks[code] || {}) }));
  feedback.critical_risks = feedback.critical_risks.filter(item => (
    item.risk || item.message || item.description || item.passed === false || item.status === 'failed'
  )).map(item => ({
    ...item,
    text: item.risk || item.message || item.description || `${item.code} 未通过`
  }));
  feedback.training_tasks = (feedback.report && feedback.report.training_tasks) || [];
  return feedback;
}

function normalizeFeedbackList(items) {
  return (items || []).map(item => normalizeFeedbackItem(item));
}

Page({
  data: {
    feedbackId: null,
    taskId: null,
    feedback: null,
    feedbackList: [],
    loading: true,
    feedbackState: viewState.readState('loading'),
    audioState: { status: 'idle', message: '', currentKey: '' },
    pending: false,
    pollCount: 0,
    loadingMessage: '加载中...'
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
    if (options.pending === '1') {
      this.setData({ pending: true, loadingMessage: 'AI 正在评分，请稍候...' });
    }

    if (this.data.feedbackId && this.data.source === 'analysis') {
      this.loadLegacyFeedback();
      return;
    }
    this.loadFeedback();
  },

  onUnload() {
    if (feedbackTimer) {
      clearTimeout(feedbackTimer);
      feedbackTimer = null;
    }
    this.destroyAudio();
    media.clearMediaCache();
  },

  destroyAudio() {
    if (audioContext) {
      try { audioContext.destroy(); } catch (e) {}
      audioContext = null;
    }
  },

  async loadLegacyFeedback() {
    wx.showLoading({ title: '加载中...' });
    try {
      const response = await api.request({
        url: `/drill/recording-feedback.php?recording_id=${encodeURIComponent(this.data.feedbackId)}`
      });
      const feedback = response.data ? normalizeFeedbackItem(response.data) : null;
      this.setData({
        feedback,
        feedbackList: [],
        loading: false,
        feedbackState: viewState.readState(feedback ? 'ready' : 'empty')
      });
    } catch (err) {
      wx.showToast({ title: '历史反馈加载失败', icon: 'none' });
      this.setData({ loading: false, feedbackState: viewState.fromError(err, '历史反馈加载失败', 'loadLegacyFeedback') });
    } finally {
      wx.hideLoading();
    }
  },

  retryFeedback() {
    if (this.data.source === 'analysis') {
      return this.loadLegacyFeedback();
    }
    return this.loadFeedback();
  },

  async loadFeedback() {
    wx.showLoading({ title: '加载中...' });

    try {
      const response = await drill.request(`/results.php${this.data.feedbackId ? `?attempt_id=${this.data.feedbackId}` : ''}`);
      const items = normalizeFeedbackList(response.data.items || []);
      const completedItems = items.filter(item => item.evaluation_status === 'completed');
      if (completedItems.length) {
        this.setData({
          feedback: completedItems[0],
          feedbackList: completedItems,
          loading: false,
          pending: false,
          feedbackState: viewState.readState('ready')
        });
      } else if (this.data.pending && this.data.pollCount < 30) {
        const status = await drill.loadAttemptStatus(this.data.feedbackId);
        const retryPending = (status.evaluations || []).some(item => item.status === 'retry_pending');
        if (retryPending) {
          this.setData({
            loading: false,
            pending: false,
            feedbackState: { status: 'error', message: 'AI 评分暂未完成，请稍后重新加载' }
          });
        } else {
          this.setData({ loading: true, pollCount: this.data.pollCount + 1 });
          feedbackTimer = setTimeout(() => this.loadFeedback(), Number(status.poll_after_seconds || 2) * 1000);
        }
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

  async playAudio(e) {
    const dataset = e.currentTarget.dataset || {};
    const descriptor = media.normalizeMediaDescriptor({
      url: dataset.url,
      fileID: dataset.fileId,
      asset_key: dataset.assetKey,
      status: dataset.status || 'ready'
    }, 'audio_url');
    const currentKey = descriptor.asset_key || descriptor.fileID || descriptor.url;
    if (!descriptor.ready || descriptor.status !== 'ready') {
      wx.showToast({ title: '音频未就绪', icon: 'none' });
      this.setData({ audioState: { status: 'error', message: '音频未就绪', currentKey } });
      return;
    }

    try {
      this.destroyAudio();
      this.setData({ audioState: { status: 'loading', message: '', currentKey } });
      const src = descriptor.fileID ? await media.getPlayableTempFile(descriptor) : descriptor.url;
      audioContext = wx.createInnerAudioContext();
      audioContext.src = src;
      audioContext.onPlay(() => this.setData({ audioState: { status: 'playing', message: '', currentKey } }));
      audioContext.onEnded(() => this.setData({ audioState: { status: 'ended', message: '', currentKey: '' } }));
      audioContext.onStop(() => this.setData({ audioState: { status: 'idle', message: '', currentKey: '' } }));
      audioContext.onError(() => {
        this.setData({ audioState: { status: 'error', message: '播放失败', currentKey } });
        wx.showToast({ title: '播放失败', icon: 'none' });
      });
      audioContext.play();
    } catch (err) {
      this.setData({ audioState: { status: 'error', message: '播放失败', currentKey } });
      wx.showToast({ title: '播放失败', icon: 'none' });
    }
  },

  viewFeedback(e) {
    const id = e.currentTarget.dataset.id;
    if (!id) return;
    wx.navigateTo({ url: `/pages/drill/feedback/feedback?id=${id}` });
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
      'qualified': '合格',
      'unqualified': '待提升',
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
