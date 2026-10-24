const drill = require('../../../utils/drill-v2');
const privacy = require('../../../utils/privacy');
const plugin = requirePlugin('WechatSI');
const voiceManager = plugin.getRecordRecognitionManager();
let voiceTimer = null;
let voiceTouchStartY = 0;

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

Page({
  data: {
    // 模式：flow=完整流程闯关 stage=单环节专项 free=自由聊天 qa=销售问答
    mode: 'free',
    stageCode: '',
    modeName: '自由对练',
    scenarios: [],
    personaOptions: {},
    scenarioIndex: 0,
    selectedScenario: null,
    processStages: [],
    sessionId: '',
    started: false,
    ended: false,
    loading: false,
    isRecording: false,
    inputText: '',
    messages: [],
    progress: null,
    summary: null,
    stageProgress: [],
    currentStage: null,
    coveredActions: [],
    advanceSuggestion: null,
    retryingTurn: false,
    pendingRetryContent: '',
    replyCoaching: null,
    replyMissingText: '',
    voiceActive: false,
    voicePressed: false,
    ignoreVoiceResult: false,
    voiceGestureId: 0,
    voiceStarting: false,
    voiceStatus: '',
    voiceDuration: '00:00',
    voiceCancelArmed: false,
    voiceBars: [1, 2, 3, 4, 5],
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

  onLoad(options) {
    const mode = ['flow', 'stage', 'free', 'qa'].includes(options && options.mode) ? options.mode : 'free';
    this.setData({
      mode,
      stageCode: (options && options.stageCode) || '',
      modeName: mode === 'flow' ? '完整流程对练' : mode === 'stage' ? '专项对练' : mode === 'qa' ? '销售问答' : '自由对练'
    });
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
    if (voiceTimer) {
      clearInterval(voiceTimer);
      voiceTimer = null;
    }
    this.setData({
      voicePressed: false,
      isRecording: false,
      voiceActive: false,
      voiceStarting: false,
      voiceStatus: '',
      voiceDuration: '00:00',
      voiceCancelArmed: false,
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
      const rawStages = res.data.process_stages || {};
      const stageCache = {};
      Object.keys(rawStages).forEach(domainKey => {
        stageCache[domainKey] = (rawStages[domainKey] || []).map(stage => ({
          ...stage,
          label: stage.name || STAGE_LABELS[stage.stage_code] || stage.stage_code
        }));
      });
      this.catalogStageCache = stageCache;
      const domainId = scenarios[0] ? String(scenarios[0].domain_id) : '';
      this.setData({
        scenarios,
        selectedScenario: scenarios[0] || null,
        personaOptions: res.data.persona_options || {},
        processStages: stageCache[domainId] || []
      });
      if (this.data.mode === 'flow' && !(stageCache[domainId] || []).length) {
        wx.showToast({ title: '当前场景暂未配置销售流程，将按自由对练进行', icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '场景加载失败', icon: 'none' });
    }
  },

  onScenarioChange(e) {
    const scenarioIndex = Number(e.detail.value) || 0;
    const selectedScenario = this.data.scenarios[scenarioIndex] || null;
    const domainId = selectedScenario ? String(selectedScenario.domain_id) : '';
    this.setData({
      scenarioIndex,
      selectedScenario,
      processStages: (this.catalogStageCache && this.catalogStageCache[domainId]) || this.data.processStages,
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

  stageContract(stageCode) {
    const stage = this.data.processStages.find(item => item.stage_code === stageCode);
    if (stage) {
      return {
        stage_code: stage.stage_code,
        name: stage.label || stage.name,
        core_goal: stage.core_goal || '',
        validation_actions: stage.validation_actions || []
      };
    }
    const label = STAGE_LABELS[stageCode];
    return label ? { stage_code: stageCode, name: label, core_goal: '', validation_actions: [] } : null;
  },

  applyStageProgress(stageProgress, fallbackStageCode) {
    const list = (stageProgress || []).map(item => ({
      ...item,
      label: item.name || STAGE_LABELS[item.stage_code] || item.stage_code
    }));
    const active = list.find(item => item.is_current || item.status === 'active');
    let currentStage = active ? this.stageContract(active.stage_code) : null;
    if (!currentStage && fallbackStageCode) {
      const fallback = this.stageContract(fallbackStageCode);
      if (fallback) currentStage = { ...fallback, validation_actions: (fallback.validation_actions || []) };
    }
    this.setData({
      stageProgress: this.data.mode === 'stage' ? list.filter(item => item.stage_code === this.data.stageCode || item.status !== 'pending') : list,
      currentStage,
      coveredActions: []
    });
  },

  async startChat() {
    const scenario = this.data.scenarios[this.data.scenarioIndex];
    if (!scenario) {
      wx.showToast({ title: '请选择场景', icon: 'none' });
      return;
    }
    if (this.data.mode === 'stage' && !this.data.stageCode) {
      wx.showToast({ title: '缺少专项环节参数', icon: 'none' });
      return;
    }

    this.setData({ loading: true });
    try {
      const selectionContext = this.buildSelectionContext();
      const input = {
        action: 'create_self_practice',
        scenario_version_id: scenario.scenario_version_id,
        session_goal: {
          mode: this.data.mode === 'qa' ? 'sales_qa' : 'sales_flow',
          selection_context: selectionContext
        },
        selection_context: selectionContext
      };
      if (this.data.mode === 'flow') {
        input.practice_type = 'full_process';
      } else if (this.data.mode === 'stage') {
        input.practice_type = 'stage_practice';
        input.start_stage_code = this.data.stageCode;
      }
      const res = await drill.createAttempt(input);
      const attempt = res.attempt || res;
      this.setData({
        sessionId: attempt.attempt_id,
        attempt,
        started: true,
        ended: false,
        progress: attempt.progress || 0,
        messages: [
          { role: 'system', label: '系统提示', content: this.data.mode === 'flow' ? '完整流程对练开始，先完成第一个环节的目标。' : this.data.mode === 'stage' ? `专项对练：${this.stageContract(this.data.stageCode) ? this.stageContract(this.data.stageCode).name : ''}。` : '已创建自由演练，请开始回答。' }
        ],
        summary: null,
        advanceSuggestion: null,
        replyCoaching: null,
        replyMissingText: '',
        pendingRetryContent: ''
      });
      this.applyStageProgress(res.stage_progress, (res.practice_context && res.practice_context.current_stage && res.practice_context.current_stage.stage_code) || this.data.stageCode);
      try {
        const opening = await drill.generateOpeningQuestion(attempt.attempt_id);
        const question = opening.customer_turn && opening.customer_turn.content;
        if (question) {
          this.setData({ messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: question }]) });
        }
      } catch (openingError) {
        const fallbackStage = (this.data.currentStage && this.data.currentStage.stage_code) || '';
        const contractQuestion = (this.stageContract(fallbackStage) || {});
        this.setData({ messages: this.data.messages.concat([{ role: 'assistant', label: 'AI 家长', content: this.buildOpeningQuestion({ stage_code: fallbackStage }) || contractQuestion.core_goal || '我想先了解一下你们这边适不适合我家孩子。' }]) });
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
    const startedAt = Date.now();
    voiceTimer = setInterval(() => {
      const elapsed = Math.floor((Date.now() - startedAt) / 1000);
      const minutes = String(Math.floor(elapsed / 60)).padStart(2, '0');
      const seconds = String(elapsed % 60).padStart(2, '0');
      this.setData({ voiceDuration: `${minutes}:${seconds}` });
    }, 500);
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

  onVoiceTouchStart(e) {
    voiceTouchStartY = Number(e.touches && e.touches[0] && e.touches[0].clientY) || 0;
    this.setData({ voiceCancelArmed: false });
    this.startVoice();
  },

  onVoiceTouchMove(e) {
    if (!this.data.isRecording && !this.data.voiceStarting) return;
    const currentY = Number(e.touches && e.touches[0] && e.touches[0].clientY) || voiceTouchStartY;
    this.setData({ voiceCancelArmed: currentY < voiceTouchStartY - 60 });
  },

  onVoiceTouchEnd() {
    if (this.data.voiceCancelArmed) {
      this.cancelVoice();
      return;
    }
    this.stopVoice();
  },

  cancelVoice() {
    this.setData({ ignoreVoiceResult: true });
    const shouldStop = this.data.isRecording || this.data.voiceActive || this.data.voiceStarting;
    this.resetVoiceState();
    if (shouldStop) {
      try { voiceManager.stop(); } catch (e) {}
    }
    wx.showToast({ title: '已取消录音', icon: 'none' });
  },

  applyTurnResult(res, message) {
    const updates = {
      messages: this.data.messages.concat([
        { role: 'user', label: '我的回答', content: message },
        { role: 'assistant', label: 'AI 家长', content: (res.customer_turn && res.customer_turn.content) || res.customer_response || res.response || '' }
      ]),
      inputText: '',
      progress: res.progress || this.data.progress,
      replyCoaching: res.reply_coaching || null,
      replyMissingText: res.reply_coaching ? (res.reply_coaching.missing || []).join('、') : '',
      attempt: res.attempt || {
        ...this.data.attempt,
        status_version: res.status_version,
        last_completed_turn_no: res.last_completed_turn_no
      },
      summary: res.feedback || null,
      pendingRetryContent: ''
    };
    const advancement = res.stage_advancement;
    updates.advanceSuggestion = advancement && advancement.recommended
      ? { reason: advancement.reason || '本环节验证动作已覆盖', next_stage: advancement.next_stage || null }
      : null;
    if (res.reply_coaching && this.data.currentStage) {
      const covered = new Set(this.data.coveredActions);
      (res.reply_coaching.covered || []).forEach(label => covered.add(label));
      (advancement && advancement.covered_actions || []).forEach(label => covered.add(label));
      updates.coveredActions = Array.from(covered);
    }
    this.setData(updates);
  },

  async sendMessage() {
    const message = this.data.inputText.trim();
    if (!message || !this.data.sessionId || this.data.loading) return;
    await this.submitTurn(message, false);
  },

  retryPendingTurn() {
    const content = this.data.pendingRetryContent;
    if (!content || this.data.loading) return;
    this.submitTurn(content, true);
  },

  async submitTurn(message, isRetry) {
    this.setData({
      loading: true,
      retryingTurn: isRetry,
      isRecording: false,
      voiceActive: false,
      voicePressed: false,
      ignoreVoiceResult: true,
      messages: isRetry ? this.data.messages : this.data.messages.concat([{ role: 'user', label: '我的回答', content: message }]),
      inputText: ''
    });

    try {
      const res = await drill.submitTextTurn(this.data.sessionId, this.data.attempt.status_version || 0, message);
      if (res && res.status === 'retry_pending') {
        this.handleRetryPending(message);
        return;
      }
      // 发送成功后整体替换（含用户消息），保持单一路径
      if (isRetry) {
        this.data.messages = this.data.messages.concat([{ role: 'user', label: '我的回答', content: message }]);
      } else {
        this.data.messages = this.data.messages.slice(0, -1);
      }
      this.applyTurnResult(res, message);
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
          if (!isRetry) this.setData({ messages: this.data.messages.slice(0, -1) });
          wx.showToast({ title: '演练状态已更新，请重新发送', icon: 'none' });
          return;
        } catch (statusError) {
          console.error('刷新演练状态失败', statusError);
        }
      }
      if (!isRetry) this.setData({ messages: this.data.messages.slice(0, -1) });
      this.setData({ pendingRetryContent: message });
      wx.showToast({ title: (err && err.message) || '发送失败，可点击重试', icon: 'none' });
    } finally {
      this.setData({ loading: false, retryingTurn: false });
    }
  },

  handleRetryPending(message) {
    // AI 家长回应暂时不可用：等待 3 秒自动换幂等键重发一次，仍失败则保留重试入口
    setTimeout(() => {
      if (this.data.pendingRetryContent === '' && this.data.ended !== true) {
        this.submitTurn(message, true);
      }
    }, 3000);
    this.setData({
      loading: false,
      retryingTurn: false,
      pendingRetryContent: message,
      messages: this.data.messages.concat([{ role: 'system', label: '系统提示', content: 'AI 家长正在组织回应，稍候会自动重试…' }])
    });
  },

  async confirmAdvance() {
    if (!this.data.sessionId || this.data.loading) return;
    this.setData({ loading: true });
    try {
      const res = await drill.advanceStage(this.data.sessionId, this.data.attempt.status_version || 0);
      const transition = res.stage_transition || {};
      const nextStage = transition.next_stage || {};
      const messages = this.data.messages.concat([
        { role: 'stage-transition', label: '环节完成', content: `已完成【${STAGE_LABELS[transition.completed_stage_code] || transition.completed_stage_code || '本环节'}】，进入【${nextStage.name || '下一环节'}】：${nextStage.core_goal || ''}` }
      ]);
      if (transition.opening_question) {
        messages.push({ role: 'assistant', label: 'AI 家长', content: transition.opening_question });
      }
      this.setData({
        messages,
        attempt: { ...this.data.attempt, status_version: res.status_version, current_stage_id: res.current_stage_id },
        advanceSuggestion: null,
        replyCoaching: null,
        replyMissingText: ''
      });
      this.applyStageProgress(res.stage_progress);
    } catch (err) {
      if (err && (err.category === 'conflict' || Number(err.statusCode) === 409)) {
        try {
          const status = await drill.loadAttemptStatus(this.data.sessionId);
          this.setData({ attempt: { ...this.data.attempt, ...(status.attempt || status) } });
          this.applyStageProgress(status.stage_progress);
        } catch (statusError) {
          console.error('刷新演练状态失败', statusError);
        }
      }
      wx.showToast({ title: (err && err.message) || '推进失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  dismissAdvance() {
    this.setData({ advanceSuggestion: null });
  },

  manualAdvance() {
    if (this.data.mode !== 'flow' || !this.data.sessionId || this.data.loading) return;
    const current = this.data.currentStage ? this.data.currentStage.name : '当前环节';
    wx.showModal({
      title: '进入下一环节',
      content: `确认已完成【${current}】的目标，进入下一环节继续对练？`,
      confirmText: '进入下一环节',
      cancelText: '再练练',
      success: (res) => {
        if (res.confirm) this.confirmAdvance();
      }
    });
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
      stageProgress: [],
      currentStage: null,
      coveredActions: [],
      advanceSuggestion: null,
      pendingRetryContent: '',
      replyCoaching: null,
      replyMissingText: '',
      voicePressed: false,
      ignoreVoiceResult: false
    });
  }
});
