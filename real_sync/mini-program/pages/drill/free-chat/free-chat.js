const drill = require('../../../utils/drill-v2');
const privacy = require('../../../utils/privacy');
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
    stageContext: null,
    replyCoaching: null,
    replyMissingText: '',
    voiceActive: false,
    voicePressed: false,
    ignoreVoiceResult: false,
    voiceGestureId: 0,
    voiceStarting: false,
    voiceStatus: '',
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
    const wasActive = this.data.isRecording || this.data.voiceActive;
    this.resetVoiceState();
    if (wasActive) {
      try { voiceManager.stop(); } catch (e) {}
    }
  },

  resetVoiceState() {
    this.setData({
      voicePressed: false,
      isRecording: false,
      voiceActive: false,
      voiceStarting: false,
      voiceStatus: '',
      voiceGestureId: this.data.voiceGestureId + 1
    });
  },

  initVoice() {
    if (typeof voiceManager.onStart === 'function') {
      voiceManager.onStart(() => this.setData({ voiceStatus: '正在听，请开始说话' }));
    }
    voiceManager.onRecognize((res) => {
      if (!this.data.ignoreVoiceResult) this.setData({ inputText: res.result || '', voiceStatus: '正在识别' });
    });
    voiceManager.onStop((res) => {
      const shouldIgnore = this.data.ignoreVoiceResult;
      this.resetVoiceState();
      if (res.result && !shouldIgnore) {
        this.setData({ inputText: res.result });
      } else if (!shouldIgnore) {
        wx.showToast({ title: '没有识别到语音内容', icon: 'none' });
      }
    });
    voiceManager.onError((err) => {
      this.resetVoiceState();
      const message = String((err && err.errMsg) || '');
      wx.showToast({
        title: /privacy agreement|api scope/i.test(message)
          ? '录音隐私声明尚未生效，请先使用文字回答'
          : '语音识别失败',
        icon: 'none'
      });
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
        summary: null,
        stageContext: (res.practice_context && res.practice_context.current_stage) || null,
        replyCoaching: null,
        replyMissingText: ''
      });
      try {
        const opening = await drill.generateOpeningQuestion(attempt.attempt_id);
        const question = opening.customer_turn && opening.customer_turn.content;
        if (question) {
          this.setData({ messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: question }]) });
        }
      } catch (openingError) {
        this.setData({ messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: this.buildOpeningQuestion((res.practice_context && res.practice_context.current_stage) || {}) }]) });
      }
    } catch (err) {
      wx.showToast({ title: err.message || '启动失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  onInput(e) {
    this.setData({ inputText: e.detail.value });
  },

  buildOpeningQuestion(stage) {
    const questions = {
      lead_preparation: '我是在网上看到你们的，想先了解一下适不适合我家孩子，可以先给我介绍一下吗？',
      invitation_confirmation: '体验课具体是哪天？需要提前准备什么，孩子要早点到吗？',
      arrival_reception: '孩子第一次来有点慢热，你们会先怎么带他适应？',
      needs_diagnosis: '我家孩子最近不太愿意主动运动，来这里主要能帮他改善什么？',
      assessment_experience: '刚才你说他这方面需要加强，平时在家里会有什么表现？',
      solution_value: '你们这个课程具体怎么帮到孩子？和普通兴趣班有什么区别？',
      objection_signing_handoff: '我还是有点担心价格和坚持问题，万一不合适怎么办？',
      followup_referral: '我回去和家人商量一下，你把今天的情况发我，明天下午再联系可以吗？'
    };
    return questions[stage.stage_code] || '我家孩子的情况比较特别，你先说说你们准备怎么安排？';
  },

  async startVoice() {
    if (this.data.isRecording || this.data.voiceStarting || this.data.ended) return;
    const voiceGestureId = this.data.voiceGestureId + 1;
    this.setData({ voicePressed: true, voiceStarting: true, voiceStatus: '正在启动录音', ignoreVoiceResult: false, voiceGestureId });
    const authorization = await privacy.getRecordAuthorizationStatus();
    if (!authorization.authorized || !this.data.voicePressed || this.data.voiceGestureId !== voiceGestureId) {
      this.resetVoiceState();
      if (!authorization.authorized) privacy.showAuthorizationPrompt(authorization);
      return;
    }
    this.setData({ isRecording: true, voiceActive: true, voiceStarting: false, voiceStatus: '正在听，请开始说话' });
    wx.vibrateShort();
    try {
      voiceManager.start({
        duration: 30000,
        lang: 'zh_CN'
      });
    } catch (err) {
      this.resetVoiceState();
      wx.showToast({ title: '语音启动失败', icon: 'none' });
    }
  },

  stopVoice() {
    const wasActive = this.data.isRecording || this.data.voiceActive || this.data.voiceStarting;
    this.resetVoiceState();
    if (!wasActive) return;
    wx.vibrateShort();
    try { voiceManager.stop(); } catch (e) {}
  },

  toggleVoice() {
    if (this.data.isRecording || this.data.voiceStarting) {
      this.stopVoice();
      return;
    }
    this.startVoice();
  },

  async sendMessage() {
    const message = this.data.inputText.trim();
    if (!message || !this.data.sessionId || this.data.loading) return;

    const messages = this.data.messages.concat([{ role: 'user', label: '我的回答', content: message }]);
      this.setData({ messages, inputText: '', loading: true, isRecording: false, voiceActive: false, voicePressed: false, ignoreVoiceResult: true });

    try {
      const res = await drill.submitTextTurn(this.data.sessionId, this.data.attempt.status_version || 0, message);
      this.setData({
        messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: (res.customer_turn && res.customer_turn.content) || res.customer_response || res.response || '已记录本轮回答。' }]),
        progress: res.progress || this.data.progress,
        replyCoaching: res.reply_coaching || null,
        replyMissingText: res.reply_coaching ? (res.reply_coaching.missing || []).join('、') : '',
        attempt: res.attempt || {
          ...this.data.attempt,
          status_version: res.status_version,
          last_completed_turn_no: res.last_completed_turn_no
        },
        summary: res.feedback || null
      });
    } catch (err) {
      console.error('销售演练提交失败', {
        message: err && err.message,
        statusCode: err && err.statusCode,
        code: err && err.code,
        requestId: err && err.requestId,
        data: err && err.data
      });
      if (err && (err.category === 'conflict' || Number(err.statusCode) === 409)) {
        try {
          const status = await drill.loadAttemptStatus(this.data.sessionId);
          const latestAttempt = status.attempt || status;
          this.setData({ attempt: { ...this.data.attempt, ...latestAttempt }, inputText: message });
          wx.showToast({ title: '演练状态已更新，请重新发送', icon: 'none' });
          return;
        } catch (statusError) {
          console.error('刷新演练状态失败', statusError);
        }
      }
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
      summary: null,
      stageContext: null,
      replyCoaching: null,
      replyMissingText: '',
      voicePressed: false,
      ignoreVoiceResult: false
    });
  }
});
