const drill = require('../../../utils/drill-v2');
const media = require('../../../utils/media');
const viewState = require('../../../utils/view-state');

let audioContext = null;
let feedbackTimer = null;

const DIMENSION_NAMES = {
  needs_mining: '需求挖掘',
  fab_value: 'FAB 价值转化',
  case_embedding: '案例植入',
  solution_matching: '方案匹配与规划',
  pricing_negotiation: '报价与谈判',
  objection_handling: '异议处理',
  trial_closing: '试关闭',
  urgency_building: '紧迫感制造'
};

// 评分维度 → 建议专项环节
const DIMENSION_STAGE = {
  needs_mining: 'needs_diagnosis',
  fab_value: 'solution_value',
  case_embedding: 'solution_value',
  solution_matching: 'solution_value',
  pricing_negotiation: 'objection_signing_handoff',
  objection_handling: 'objection_signing_handoff',
  trial_closing: 'objection_signing_handoff',
  urgency_building: 'followup_referral'
};

const STAGE_LABELS = {
  lead_preparation: '线索准备',
  invitation_confirmation: '邀约确认',
  arrival_reception: '到店接待',
  needs_diagnosis: '需求诊断',
  assessment_experience: '体测体验',
  solution_value: '方案价值',
  objection_signing_handoff: '异议成交',
  followup_referral: '跟进转介绍'
};

function normalizeFeedbackItem(item) {
  const feedback = media.normalizeMediaFields(item || {}, ['audio_url']);
  const cloudAudio = media.normalizeMediaDescriptor(feedback.audio_media || feedback.cloud_media || feedback.audio_file || feedback.audio_url_media, 'audio_url');
  if (cloudAudio.ready) feedback.audio_url_media = cloudAudio;
  feedback.audio_playable = Boolean(feedback.audio_url || (feedback.audio_url_media && feedback.audio_url_media.ready));
  feedback.level = feedback.level || feedback.evaluation_grade || '';
  const report = feedback.report || {};
  feedback.report = report;
  feedback.feedback = feedback.feedback || report.overall_conclusion || '';
  feedback.suggestions = feedback.suggestions && feedback.suggestions.length
    ? feedback.suggestions
    : (report.priority_improvements || []);
  feedback.strengths = report.strengths || [];
  feedback.deal_risk = report.deal_risk || '';
  feedback.replacement_scripts = report.replacement_scripts || [];
  feedback.smart_actions = report.smart_actions || [];
  feedback.dimension_list = Object.keys(report.dimension_scores || {}).map(code => ({
    code,
    name: DIMENSION_NAMES[code] || code,
    score: Math.round(Number((report.dimension_scores[code] || {}).capability_score || 0))
  }));
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
    if (options.pending === '1') {
      this.setData({ pending: true, loadingMessage: 'AI 正在评分，请稍候...' });
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
      audioContext.destroy();
      audioContext = null;
    }
  },

  retryFeedback() {
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
        this.loadStageJourney();
        this.loadWeakestDimension();
        this.loadMastery();
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

  async loadStageJourney() {
    if (!this.data.feedbackId) return;
    try {
      const status = await drill.loadAttemptStatus(this.data.feedbackId);
      const journey = (status.stage_progress || []).map(item => ({
        ...item,
        label: item.name || STAGE_LABELS[item.stage_code] || item.stage_code
      }));
      this.setData({ stageJourney: journey });
    } catch (err) {
      this.setData({ stageJourney: [] });
    }
  },

  loadWeakestDimension() {
    const dimensions = (this.data.feedback && this.data.feedback.dimension_list) || [];
    if (!dimensions.length) {
      this.setData({ weakestDimension: null });
      return;
    }
    const weakest = dimensions.slice().sort((a, b) => a.score - b.score)[0];
    const stageCode = DIMENSION_STAGE[weakest.code] || '';
    this.setData({
      weakestDimension: {
        ...weakest,
        stage_code: stageCode,
        stage_name: STAGE_LABELS[stageCode] || ''
      }
    });
  },

  async loadMastery() {
    try {
      const progress = await drill.request('/progress.php');
      const mastery = (progress.data.mastery || [])
        .filter(item => item.scope_type !== 'required_section' || item.stage_name)
        .slice(0, 8)
        .map(item => ({
          name: item.stage_name || item.domain_code || '综合',
          score: Math.round(Number(item.mastery_score || item.score || 0))
        }));
      this.setData({ masteryList: mastery });
    } catch (err) {
      this.setData({ masteryList: [] });
    }
  },

  practiceWeakStage() {
    const stageCode = this.data.weakestDimension && this.data.weakestDimension.stage_code;
    if (!stageCode) return;
    wx.navigateTo({ url: `/pages/drill/free-chat/free-chat?mode=stage&stageCode=${encodeURIComponent(stageCode)}` });
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
