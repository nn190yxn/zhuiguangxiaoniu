const app = getApp();
const recorderManager = wx.getRecorderManager();

Page({
  data: {
    scenarios: [],
    scenarioIndex: 0,
    selectedScenario: null,
    sessionId: '',
    started: false,
    ended: false,
    loading: false,
    isRecording: false,
    inputText: '',
    messages: [],
    progress: null,
    summary: null
  },

  onLoad() {
    this.initRecorder();
    this.loadScenarios();
  },

  onUnload() {
    recorderManager.stop();
  },

  initRecorder() {
    recorderManager.onStop((res) => {
      this.setData({ isRecording: false });
      if (res.tempFilePath) {
        this.recognizeVoice(res.tempFilePath);
      }
    });
    recorderManager.onError(() => {
      this.setData({ isRecording: false });
      wx.showToast({ title: '录音失败', icon: 'none' });
    });
  },

  async loadScenarios() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/free-chat.php`,
        method: 'POST',
        data: { action: 'scenarios' }
      });
      const scenarios = res.data.list || [];
      this.setData({
        scenarios,
        selectedScenario: scenarios[0] || null
      });
    } catch (err) {
      wx.showToast({ title: '场景加载失败', icon: 'none' });
    }
  },

  onScenarioChange(e) {
    const scenarioIndex = Number(e.detail.value) || 0;
    this.setData({
      scenarioIndex,
      selectedScenario: this.data.scenarios[scenarioIndex] || null
    });
  },

  async startChat() {
    const scenario = this.data.scenarios[this.data.scenarioIndex];
    if (!scenario) {
      wx.showToast({ title: '请选择场景', icon: 'none' });
      return;
    }

    this.setData({ loading: true });
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/free-chat.php`,
        method: 'POST',
        data: { action: 'start', scenario: scenario.id }
      });
      this.setData({
        sessionId: res.data.session_id,
        started: true,
        ended: false,
        progress: res.data.progress,
        messages: [
          { role: 'system', label: '系统提示', content: res.data.welcome },
          { role: 'assistant', label: 'AI 家长', content: res.data.message }
        ],
        summary: null
      });
    } catch (err) {
      wx.showToast({ title: err.message || '启动失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  onInput(e) {
    this.setData({ inputText: e.detail.value });
  },

  startVoice() {
    if (this.data.isRecording || this.data.ended) return;
    this.setData({ isRecording: true });
    recorderManager.start({
      format: 'mp3',
      sampleRate: 16000,
      numberOfChannels: 1,
      encodeBitRate: 48000,
      duration: 30000
    });
  },

  stopVoice() {
    if (!this.data.isRecording) return;
    recorderManager.stop();
  },

  recognizeVoice(tempFilePath) {
    wx.showLoading({ title: '正在识别...' });
    wx.uploadFile({
      url: `${app.globalData.apiBase}/drill/voice-to-text.php`,
      filePath: tempFilePath,
      name: 'audio',
      header: { Authorization: `Bearer ${wx.getStorageSync('token') || ''}` },
      success: (res) => {
        wx.hideLoading();
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0 && data.data.text) {
            this.setData({ inputText: data.data.text });
          } else {
            wx.showToast({ title: data.message || '识别失败', icon: 'none' });
          }
        } catch (err) {
          wx.showToast({ title: '识别失败', icon: 'none' });
        }
      },
      fail: () => {
        wx.hideLoading();
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  async sendMessage() {
    const message = this.data.inputText.trim();
    if (!message || !this.data.sessionId || this.data.loading) return;

    const messages = this.data.messages.concat([{ role: 'user', label: '我的回答', content: message }]);
    this.setData({ messages, inputText: '', loading: true });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/drill/free-chat.php`,
        method: 'POST',
        data: { action: 'chat', session_id: this.data.sessionId, message }
      });
      this.setData({
        messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: res.data.message }]),
        progress: res.data.progress,
        ended: res.data.type === 'end',
        summary: res.data.summary || null
      });
    } catch (err) {
      wx.showToast({ title: err.message || '发送失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  restart() {
    this.setData({
      sessionId: '',
      started: false,
      ended: false,
      inputText: '',
      messages: [],
      progress: null,
      summary: null
    });
  }
});
