const drill = require('../../../utils/drill-v2');
const recorderManager = wx.getRecorderManager();
const innerAudioContext = wx.createInnerAudioContext();
const plugin = requirePlugin('WechatSI');
const voiceManager = plugin.getRecordRecognitionManager();
let statusPollTimer = null;

Page({
  data: {
    id: null,
    template: {},
    task: {},
    knowledge: {},
    scripts: [],
    steps: [],
    currentStep: 1,
    progress: 0,
    actionBtnText: '开始学习',
    isRecording: false,
    recordingDuration: 0,
    recordingPath: '',
    quizAnswer: '',
    aiFeedback: null,
    showFeedbackModal: false,
    currentScriptId: null,
    voiceText: '',
    voiceTempPath: '',
    voiceMode: '',
    attempt: null,
    statusVersion: 0,
    textFallbackAvailable: false,
    minimumVersionMessage: ''
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ id: options.id, assignmentId: options.assignment_id || options.id, planItemId: options.plan_item_id });
      this.loadDrill();
      this.initRecorder();
      this.initVoice();
    }
  },

  onUnload() {
    if (statusPollTimer) {
      clearTimeout(statusPollTimer);
      statusPollTimer = null;
    }
    recorderManager.stop();
    try { voiceManager.stop(); } catch (e) {}
    innerAudioContext.destroy();
  },

  initRecorder() {
    recorderManager.onStop((res) => {
      const tempPath = res.tempFilePath;
      const duration = res.duration || 0;

      if (tempPath) {
        this.setData({
          recordingPath: tempPath,
          recordingDuration: duration
        });
        this.uploadRecording();
      }
    });

    recorderManager.onError((err) => {
      console.error('录音错误', err);
      wx.showToast({ title: '录音失败', icon: 'none' });
      this.setData({ isRecording: false });
    });
  },

  initVoice() {
    voiceManager.onRecognize((res) => {
      this.setData({ voiceText: res.result || '' });
    });
    voiceManager.onStop((res) => {
      this.setData({ isRecording: false });
      if (res.result) {
        this.setData({ voiceText: res.result });
      }
    });
    voiceManager.onError(() => {
      this.setData({ isRecording: false });
      wx.showToast({ title: '语音识别失败', icon: 'none' });
    });
  },

  async loadDrill() {
    wx.showLoading({ title: '加载中...' });

    try {
      let resumed = await drill.resumeActiveAttempt();
      if (!resumed && this.data.assignmentId) {
        resumed = await drill.createAttempt({ action: 'create', assignment_id: Number(this.data.assignmentId), plan_item_id: Number(this.data.planItemId), session_goal: {} });
      }
      const attempt = resumed && (resumed.attempt || resumed);
      this.setData({
        attempt,
        task: attempt || {},
        template: attempt && attempt.scenario ? attempt.scenario : {},
        steps: (attempt && attempt.process_sections) || [],
        currentStep: (attempt && attempt.current_step) || 3,
        progress: (attempt && attempt.progress) || 0,
        statusVersion: (attempt && attempt.status_version) || 0
      });
      this.updateActionBtn();
    } catch (err) {
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  updateActionBtn() {
    const stepStatus = this.getStepStatus();

    if (stepStatus === 'completed') {
      this.setData({
        actionBtnText: this.data.currentStep === 4 ? '完成演练' : '下一步'
      });
    } else {
      this.setData({ actionBtnText: '完成当前步骤' });
    }
  },

  getStepStatus() {
    const stepStatus = this.data.task.step_status || {};
    return stepStatus[this.data.currentStep] || 'pending';
  },

  async handleAction() {
    const status = this.getStepStatus();

    if (status === 'completed') {
      if (this.data.currentStep < 4) {
        this.setData({ currentStep: this.data.currentStep + 1 });
        this.updateActionBtn();
      } else {
        wx.showToast({ title: '演练已完成！', icon: 'success' });
        setTimeout(() => wx.navigateBack(), 1500);
      }
    } else {
      if (this.data.currentStep === 3) {
        if (!this.data.recordingPath) {
          wx.showToast({ title: '请先完成录音', icon: 'none' });
          return;
        }
      }
      await this.completeStep();
    }
  },

  async completeStep() {
    let score = 100;
    let feedback = '';

    if (this.data.currentStep === 4) {
      score = this.data.quizAnswer === 'A' ? 100 : 60;
    }

    try {
      if (this.data.currentStep === 3 && this.data.voiceText) {
        await this.submitVoiceText();
      } else {
        const result = await drill.loadAttemptStatus(this.data.attempt.attempt_id);
        if (result) this.applyAttemptStatus(result);
        wx.showToast({
          title: this.data.currentStep === 4 ? '演练完成！' : '步骤完成',
          icon: 'success'
        });
      }
    } catch (err) {
      wx.showToast({ title: '操作失败', icon: 'none' });
    }
  },

  selectQuiz(e) {
    this.setData({ quizAnswer: e.currentTarget.dataset.answer });
  },

  onVoiceTextInput(e) {
    this.setData({ voiceText: e.detail.value });
  },

  onVoiceChooseAvatar(e) {
  },

  onVoiceInput(e) {
  },

  submitVoiceText() {
    const voiceText = this.data.voiceText.trim();
    if (!voiceText) {
      wx.showToast({ title: '请先输入内容', icon: 'none' });
      return;
    }

    if (!this.data.attempt || !this.data.attempt.attempt_id) {
      wx.showToast({ title: '演练实例恢复中，请稍后重试', icon: 'none' });
      return;
    }

    wx.showLoading({ title: '正在分析...' });

    drill.submitTextTurn(this.data.attempt.attempt_id, this.data.statusVersion, voiceText)
      .then(result => this.showFeedback(result))
      .catch(() => this.setData({ textFallbackAvailable: true }))
      .finally(() => wx.hideLoading());
  },

  getDimensionCode() {
    const scriptId = this.data.currentScriptId;
    const script = this.data.scripts.find(s => s.id === scriptId);
    if (script && script.dimension_code) {
      return script.dimension_code;
    }
    return 'qa';
  },

  startVoice() {
    if (this.data.isRecording) return;

    this.setData({ isRecording: true, voiceMode: 'text' });
    wx.vibrateShort();

    voiceManager.start({
      duration: 30000,
      lang: 'zh_CN'
    });
  },

  stopVoice() {
    if (!this.data.isRecording || this.data.voiceMode !== 'text') return;

    this.setData({ isRecording: false });
    wx.vibrateShort();
    voiceManager.stop();
  },

  showFeedback(feedback) {
    if (drill.isRetryPending(feedback)) {
      this.setData({
        aiFeedback: feedback,
        showFeedbackModal: true,
        textFallbackAvailable: true,
        minimumVersionMessage: '分析处理中，请稍后刷新结果'
      });
      this.scheduleStatusPoll();
      return;
    }
    this.setData({
      aiFeedback: feedback,
      showFeedbackModal: true
    });
  },

  applyAttemptStatus(status) {
    const attempt = status.attempt || status;
    const latestAudio = (status.audio_assets || [])[0] || {};
    const retryableAudio = ['transcription_failed', 'transcription_timeout'].includes(latestAudio.status || '');
    this.setData({
      attempt,
      statusVersion: attempt.status_version || this.data.statusVersion,
      textFallbackAvailable: retryableAudio || this.data.textFallbackAvailable,
      minimumVersionMessage: retryableAudio ? '音频分析失败，可重试或改用文本提交' : this.data.minimumVersionMessage
    });
  },

  scheduleStatusPoll() {
    if (!this.data.attempt || !this.data.attempt.attempt_id) return;
    if (statusPollTimer) clearTimeout(statusPollTimer);
    statusPollTimer = setTimeout(async () => {
      statusPollTimer = null;
      try {
        const status = await drill.loadAttemptStatus(this.data.attempt.attempt_id);
        this.applyAttemptStatus(status);
      } catch (err) {}
    }, 2000);
  },

  toggleRecording() {
    if (this.data.isRecording) {
      this.stopRecording();
    } else {
      this.startRecording();
    }
  },

  startRecording() {
    wx.showLoading({ title: '正在录音...' });

    recorderManager.start({
      format: 'mp3',
      sampleRate: 16000,
      numberOfChannels: 1,
      encodeBitRate: 48000,
      duration: 60000
    });

    this.setData({ isRecording: true });
    wx.hideLoading();

    wx.showToast({
      title: '录音中...',
      icon: 'none',
      duration: 1000
    });
  },

  stopRecording() {
    recorderManager.stop();
    this.setData({ isRecording: false });
    wx.showLoading({ title: '上传中...' });
  },

  async uploadRecording() {
    if (!this.data.recordingPath || !this.data.attempt || !this.data.attempt.attempt_id) {
      wx.hideLoading();
      return;
    }

    try {
      const result = await drill.uploadAudioTurn(this.data.recordingPath, this.data.attempt.attempt_id, this.data.statusVersion, this.data.recordingDuration, this.data.voiceText);
      this.showFeedback(result);
      wx.hideLoading();

    } catch (err) {
      wx.hideLoading();
      this.setData({ textFallbackAvailable: true });
      wx.showToast({ title: '音频上传中断，可改用文本提交', icon: 'none' });
    }
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

  showFeedbackDetail(feedback) {
    wx.navigateTo({
      url: `/pages/drill/feedback/feedback?id=${feedback.feedback_id || ''}&task_id=${this.data.task.id}`
    });
  },

  playAudio(e) {
    const url = e.currentTarget.dataset.url;
    if (!url) return;

    wx.showLoading({ title: '加载音频...' });

    innerAudioContext.src = url;
    innerAudioContext.play();

    innerAudioContext.onPlay(() => {
      wx.hideLoading();
      wx.showToast({ title: '播放中', icon: 'none' });
    });

    innerAudioContext.onError((err) => {
      wx.hideLoading();
      wx.showToast({ title: '播放失败', icon: 'none' });
    });
  },

  onRecordTimerUpdate() {
  }
});
