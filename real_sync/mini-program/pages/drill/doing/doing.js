const app = getApp();
const recorderManager = wx.getRecorderManager();
const innerAudioContext = wx.createInnerAudioContext();
const REQUEST_TIMEOUT = 10000;

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
    voiceMode: ''
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ id: options.id });
      this.loadDrill();
      this.initRecorder();
    }
  },

  onUnload() {
    recorderManager.stop();
    innerAudioContext.destroy();
  },

  initRecorder() {
    recorderManager.onStart(() => {
    });

    recorderManager.onStop((res) => {
      const tempPath = res.tempFilePath;
      const duration = res.duration || 0;

      if (tempPath) {
        if (this.data.voiceMode === 'text') {
          this.recognizeVoice(tempPath);
        } else {
          this.setData({
            recordingPath: tempPath,
            recordingDuration: duration
          });
          this.uploadRecording();
        }
      }
    });

    recorderManager.onError((err) => {
      console.error('录音错误', err);
      wx.showToast({ title: '录音失败', icon: 'none' });
      this.setData({ isRecording: false, voiceMode: '' });
    });
  },

  async loadDrill() {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/detail.php?id=${this.data.id}`
      });

      if (res.code === 0) {
        const data = res.data;
        const template = data.template;
        const task = data.task;

        this.setData({
          template: template,
          task: task,
          knowledge: data.knowledge_card || {},
          scripts: data.scripts || [],
          steps: template.steps || [],
          currentStep: task.current_step || 1,
          progress: task.progress || 0
        });

        if (task.current_step >= 3 && data.scripts && data.scripts.length > 0) {
          this.setData({ currentScriptId: data.scripts[0].id });
        }

        this.updateActionBtn();
      }
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
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/step.php`,
        method: 'POST',
        data: {
          task_id: this.data.task.id || this.data.id,
          step: this.data.currentStep,
          action: 'complete',
          score: score,
          feedback: feedback,
          recording_url: this.data.recordingPath || null
        }
      });

      if (res.code === 0) {
        wx.showToast({
          title: this.data.currentStep === 4 ? '演练完成！' : '步骤完成',
          icon: 'success'
        });

        if (res.data.is_passed) {
          wx.showToast({ title: '恭喜通关！', icon: 'success' });
          setTimeout(() => wx.navigateBack(), 1500);
        } else {
          await this.loadDrill();
        }
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
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

    if (!this.data.currentScriptId) {
      wx.showToast({ title: '请先选择话术', icon: 'none' });
      return;
    }

    wx.showLoading({ title: '正在分析...' });

    const url = `${app.globalData.apiBase}/drill/analyze-script.php`;
    wx.request({
      url,
      method: 'POST',
      timeout: REQUEST_TIMEOUT,
      header: {
        'Authorization': `Bearer ${wx.getStorageSync('token') || ''}`,
        'Content-Type': 'application/json'
      },
      data: {
        dimension: this.getDimensionCode(),
        script_id: this.data.currentScriptId,
        transcribed_text: voiceText
      },
      success: (res) => {
        wx.hideLoading();
        if (res.data.code === 0) {
          this.showFeedback(res.data.data);
        } else {
          wx.showToast({ title: res.data.message || '分析失败', icon: 'none' });
        }
      },
      fail: (err) => {
        wx.hideLoading();
        console.error('[DRILL REQUEST FAIL]', 'POST', url, err);
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
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

    recorderManager.start({
      format: 'mp3',
      sampleRate: 16000,
      numberOfChannels: 1,
      encodeBitRate: 48000,
      duration: 30000
    });
  },

  stopVoice() {
    if (!this.data.isRecording || this.data.voiceMode !== 'text') return;

    this.setData({ isRecording: false });
    wx.vibrateShort();
    recorderManager.stop();
  },

  recognizeVoice(tempFilePath) {
    wx.showLoading({ title: '正在识别...' });

    const token = wx.getStorageSync('token');
    const url = `${app.globalData.apiBase}/drill/voice-to-text.php`;
    wx.uploadFile({
      url,
      filePath: tempFilePath,
      name: 'audio',
      formData: {
        task_id: this.data.id,
        script_id: this.data.currentScriptId
      },
      header: {
        'Authorization': `Bearer ${token}`
      },
      success: (res) => {
        wx.hideLoading();
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0 && data.data.text) {
            this.setData({ voiceText: data.data.text });
          } else {
            wx.showToast({ title: data.message || '识别失败，请重试', icon: 'none' });
          }
        } catch (e) {
          wx.showToast({ title: '识别失败', icon: 'none' });
        }
      },
      fail: (err) => {
        wx.hideLoading();
        console.error('[DRILL UPLOAD FAIL]', 'POST', url, err);
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  showFeedback(feedback) {
    this.setData({
      aiFeedback: feedback,
      showFeedbackModal: true
    });
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
    if (!this.data.recordingPath || !this.data.currentScriptId) {
      wx.hideLoading();
      return;
    }

    try {
      const token = wx.getStorageSync('token');
      const url = `${app.globalData.apiBase}/drill/upload-recording.php`;
      const uploadTask = wx.uploadFile({
        url,
        filePath: this.data.recordingPath,
        name: 'audio',
        formData: {
          task_id: this.data.task.id || this.data.id,
          script_id: this.data.currentScriptId,
          step: 3,
          duration: Math.ceil(this.data.recordingDuration / 1000)
        },
        header: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'multipart/form-data'
        },
        success: (res) => {
          wx.hideLoading();
          const data = JSON.parse(res.data);

          if (data.code === 0) {
            const aiFeedback = data.data.ai_feedback;
            this.setData({ aiFeedback });

            wx.showModal({
              title: 'AI分析结果',
              content: `总分：${aiFeedback.total_score}分\n等级：${this.getLevelName(aiFeedback.level)}\n\n${aiFeedback.feedback}`,
              confirmText: '查看详情',
              cancelText: '关闭',
              success: (modalRes) => {
                if (modalRes.confirm) {
                  this.showFeedbackDetail(aiFeedback);
                }
              }
            });
          } else {
            wx.showToast({ title: data.message || '上传失败', icon: 'none' });
          }
        },
        fail: (err) => {
          wx.hideLoading();
          console.error('[DRILL UPLOAD FAIL]', 'POST', url, err);
          wx.showToast({ title: '上传失败', icon: 'none' });
        }
      });

      uploadTask.onProgressUpdate((res) => {
      });

    } catch (err) {
      wx.hideLoading();
      console.error('上传错误', err);
      wx.showToast({ title: '上传失败', icon: 'none' });
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
