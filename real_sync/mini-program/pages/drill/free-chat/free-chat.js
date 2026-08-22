const drill = require('../../../utils/drill-v2');
const plugin = requirePlugin('WechatSI');
const voiceManager = plugin.getRecordRecognitionManager();

Page({
  data: {
    scenarios: [],
    personaOptions: {},
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
    summary: null,
    personaFilters: {
      age_band: '',
      primary_need: '',
      communication_style: '',
      current_status: '',
      course_tag: ''
    },
    personaFields: [
      { key: 'age_band', label: '孩子年龄段', selectedIndex: 0, selectedName: '不限', options: [{ value_code: '', name: '不限' }] },
      { key: 'primary_need', label: '家长核心需求', selectedIndex: 0, selectedName: '不限', options: [{ value_code: '', name: '不限' }] },
      { key: 'communication_style', label: '沟通风格', selectedIndex: 0, selectedName: '不限', options: [{ value_code: '', name: '不限' }] },
      { key: 'current_status', label: '当前决策状态', selectedIndex: 0, selectedName: '不限', options: [{ value_code: '', name: '不限' }] },
      { key: 'course_tag', label: '课程方向', selectedIndex: 0, selectedName: '不限', options: [{ value_code: '', name: '不限' }] }
    ],
    randomMode: false
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
      const scenarios = (res.data.items || []).map(item => ({
        ...item,
        name: item.name || item.title || '销售演练场景',
        description: item.description || (item.objectives || []).join('、')
      }));
      const firstDomainOptions = scenarios[0] ? ((res.data.persona_options || {})[String(scenarios[0].domain_id)] || {}) : {};
      this.setData({
        scenarios,
        selectedScenario: scenarios[0] || null,
        personaOptions: res.data.persona_options || {},
        personaFields: this.buildPersonaFields(firstDomainOptions)
      });
    } catch (err) {
      wx.showToast({ title: '场景加载失败', icon: 'none' });
    }
  },

  onScenarioChange(e) {
    const scenarioIndex = Number(e.detail.value) || 0;
    const selectedScenario = this.data.scenarios[scenarioIndex] || null;
    this.setData({
      scenarioIndex,
      selectedScenario,
      personaFilters: {
        age_band: '',
        primary_need: '',
        communication_style: '',
        current_status: '',
        course_tag: ''
      },
      personaFields: this.buildPersonaFields(selectedScenario
        ? (this.data.personaOptions[String(selectedScenario.domain_id)] || {})
        : {})
    });
  },

  buildPersonaFields(options) {
    return this.data.personaFields.map(field => ({
      ...field,
      selectedIndex: 0,
      selectedName: '不限',
      options: [{ value_code: '', name: '不限' }].concat(options[field.key] || [])
    }));
  },

  onPersonaFilterChange(e) {
    const fieldIndex = Number(e.currentTarget.dataset.index);
    const selectedIndex = Number(e.detail.value) || 0;
    const personaFields = this.data.personaFields.slice();
    const field = personaFields[fieldIndex];
    if (!field) return;
    const selected = field.options[selectedIndex] || field.options[0];
    personaFields[fieldIndex] = { ...field, selectedIndex, selectedName: selected.name };
    this.setData({
      personaFields,
      personaFilters: {
        ...this.data.personaFilters,
        [field.key]: selected.value_code
      }
    });
  },

  toggleRandomMode(e) {
    this.setData({ randomMode: Boolean(e.detail.value) });
  },

  buildSelectionContext() {
    const filters = {};
    Object.keys(this.data.personaFilters).forEach(key => {
      const value = String(this.data.personaFilters[key] || '').trim();
      if (value) filters[key] = value;
    });
    return {
      mode: this.data.randomMode ? 'random' : 'filtered',
      filters,
      random_seed: this.data.randomMode ? Date.now() : null
    };
  },

  async startChat() {
    const scenario = this.data.scenarios[this.data.scenarioIndex];
    if (!scenario) {
      wx.showToast({ title: '请选择场景', icon: 'none' });
      return;
    }

    this.setData({ loading: true });
    try {
      const selectionContext = this.buildSelectionContext();
      const res = await drill.createAttempt({
        action: 'create_self_practice',
        scenario_version_id: scenario.scenario_version_id,
        session_goal: { mode: 'free_chat', selection_context: selectionContext },
        selection_context: selectionContext
      });
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
        messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: (res.customer_turn && res.customer_turn.content) || res.customer_response || res.response || '已记录本轮回答。' }]),
        progress: res.progress || this.data.progress,
        attempt: res.attempt || {
          ...this.data.attempt,
          status_version: res.status_version,
          last_completed_turn_no: res.last_completed_turn_no
        },
        summary: res.feedback || null
      });
    } catch (err) {
      wx.showToast({ title: err.message || '发送失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  async endChat() {
    if (!this.data.sessionId || this.data.loading) return;
    this.setData({ loading: true });
    try {
      const res = await drill.endAttempt(this.data.sessionId, this.data.attempt.status_version || 0);
      this.setData({
        ended: true,
        summary: {
          avg_score: res.status === 'evaluating' ? '评估中' : (res.score || '待生成'),
          level_name: res.status === 'evaluating' ? 'AI 正在评分' : (res.level_name || '待生成'),
          total_responses: Math.floor((this.data.messages.filter(item => item.role === 'user').length))
        },
        messages: this.data.messages.concat([{ role: 'system', label: '系统提示', content: '演练已结束，系统将根据完整对话生成评分与薄弱项反馈。' }])
      });
      wx.navigateTo({ url: `/pages/drill/feedback/feedback?id=${encodeURIComponent(this.data.sessionId)}&pending=1` });
    } catch (err) {
      wx.showToast({ title: err.message || '结束失败', icon: 'none' });
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
