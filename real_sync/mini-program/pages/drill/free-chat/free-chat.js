const drill = require('../../../utils/drill-v2');
const plugin = requirePlugin('WechatSI');
const voiceManager = plugin.getRecordRecognitionManager();

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
    this.initVoice();
    this.loadScenarios();
  },

  onUnload() {
    try { voiceManager.stop(); } catch (e) {}
  },

  initVoice() {
    voiceManager.onRecognize((res) => {
      this.setData({ inputText: res.result || '' });
    });
    voiceManager.onStop((res) => {
      this.setData({ isRecording: false });
      if (res.result) {
        this.setData({ inputText: res.result });
      }
    });
    voiceManager.onError(() => {
      this.setData({ isRecording: false });
      wx.showToast({ title: '语音识别失败', icon: 'none' });
    });
  },

  async loadScenarios() {
    try {
      const res = await drill.request('/catalog.php');
      const scenarios = res.data.items || [];
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
      const res = await drill.createAttempt({ action: 'create_self_practice', scenario_version_id: scenario.scenario_version_id, session_goal: { mode: 'free_chat' } });
      const attempt = res.attempt || res;
      this.setData({
        sessionId: attempt.attempt_id,
        attempt,
        started: true,
        ended: false,
        progress: attempt.progress || 0,
        messages: [
          { role: 'system', label: '系统提示', content: '已创建自由演练，请开始回答。' }
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
    wx.vibrateShort();
    voiceManager.start({
      duration: 30000,
      lang: 'zh_CN'
    });
  },

  stopVoice() {
    if (!this.data.isRecording) return;
    wx.vibrateShort();
    voiceManager.stop();
  },

  async sendMessage() {
    const message = this.data.inputText.trim();
    if (!message || !this.data.sessionId || this.data.loading) return;

    const messages = this.data.messages.concat([{ role: 'user', label: '我的回答', content: message }]);
    this.setData({ messages, inputText: '', loading: true });

    try {
      const res = await drill.submitTextTurn(this.data.sessionId, this.data.attempt.status_version || 0, message);
      this.setData({
        messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 客户', content: res.customer_response || res.response || '已记录本轮回答。' }]),
        progress: res.progress || this.data.progress,
        attempt: res.attempt || this.data.attempt,
        summary: res.feedback || null
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
