const app = getApp();
const api = require('../../utils/api');
const media = require('../../utils/media');
const navigation = require('../../utils/navigation');

function today() {
  return new Date(Date.now() + 8 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

function categoryLabel(v) {
  return { behavior: '行为', process: '过程', result: '结果', derived: '计算' }[v] || v;
}

function formatPoints(value) {
  const number = Number(value || 0);
  return Number.isInteger(number) ? String(number) : number.toFixed(2);
}

Page({
  metricValues: {},
  pendingUploadPaths: {},
  scopeRequestVersion: 0,

  data: {
    context: {},
    reportDate: today(),
    maxDate: today(),
    messageEntry: null,
    messageEntryText: '',
    messageEntryWarning: '',
    roleOptions: [{ label: '销售', value: 'sales' }, { label: '教练', value: 'coach' }, { label: '店长', value: 'manager' }, { label: '教学主管', value: 'teaching_supervisor' }, { label: '督导', value: 'supervisor' }],
    roleIndex: 1,
    currentRoleLabel: '教练',
    storeId: '',
    items: [],
    values: {},
    currentReportId: 0,
    evidenceMap: {},
    storeMetricSummary: {},
    templateDescription: '',
    draftEvidenceTip: '',
    remarks: '',
    statusText: '准备加载日报模板...',
    statusType: '',
    uploadingMetricCode: '',
    uploadProgress: 0,
    uploadFailedMetricCode: '',
    fieldErrors: {},
    dailyStatus: null,
    makeupDate: '',
    makeupDateLabel: '',
    dailyTargetPoints: 4,
    dailyEffectivePoints: 0,
    dailyGapPoints: 4,
    dailyReportedPoints: 0,
    dailyPendingPoints: 0,
    dailyRejectedPoints: 0,
    dailyActionText: '正在读取每日工作量状态...',
    dailyActionClass: '',
    yesterdayMakeupText: '上一工作日补齐状态读取中...',
    penaltySummaryText: '本月处罚状态读取中...',
    conversionTable: [],
    progressPercent: 0,
    reportStatusLabel: '读取状态',
    reportStatusClass: '',
    deadlineText: '截止时间读取中',
    isWritable: true,
    isWeeklyRestDay: false,
    completionStatus: 'missing',
    submitStatus: 'missing',
    conversionResults: [],
    conversionSummary: null,
    isDirty: false,
    lastSavedText: '尚未保存',
    busyAction: '',
    roleLocked: true,
    showPrivacyModal: false,
    privacyContractName: '《用户隐私保护指引》',
  },

  onLoad(options) {
    options = Object.assign({}, options || {}, navigation.consumeTabQuery('/pages/workload/index'));
    this.syncDateLimit();
    this.applyWecomMessageEntry(options);
    this.init();
  },

  onShow() {
    this.syncDateLimit();
    const options = navigation.consumeTabQuery('/pages/workload/index');
    if (Object.keys(options).length) {
      this.applyWecomMessageEntry(options);
      this.init();
    }
  },

  onHide() {
    this.persistRecovery();
    if (this.data.isDirty && !this.data.busyAction) this.saveReport('draft', { silent: true }).catch(() => {});
  },

  onUnload() {
    this.persistRecovery();
  },

  syncDateLimit() {
    const maxDate = today();
    const next = { maxDate };
    if (!this.data.reportDate || this.data.reportDate > maxDate) {
      next.reportDate = maxDate;
    }
    this.setData(next);
  },

  applyWecomMessageEntry(options) {
    const messageEntry = app.getWecomMessageEntry(options);
    if (!messageEntry || messageEntry.scene !== 'workload') {
      return;
    }
    const nextData = {
      messageEntry,
      messageEntryText: this.buildMessageEntryText(messageEntry)
    };
    if (messageEntry.date) {
      nextData.reportDate = messageEntry.date;
    }
    this.setData(nextData);
  },

  buildMessageEntryText(messageEntry) {
    const phaseMap = {
      first: '首次提醒',
      second: '二次提醒',
      store_summary: '门店汇总提醒',
      hq_summary: '总部汇总提醒'
    };
    const parts = ['来自企业微信消息'];
    if (messageEntry.date) {
      parts.push(`日期 ${messageEntry.date}`);
    }
    if (messageEntry.phase && phaseMap[messageEntry.phase]) {
      parts.push(phaseMap[messageEntry.phase]);
    }
    return parts.join(' · ');
  },

  async init() {
    try {
      const res = await app.request({ url: '/common/context-info.php' });
      const context = res.data.context || {};
      const roleIndex = this.data.roleOptions.findIndex(option => option.value === context.role);
      if (roleIndex < 0) {
        this.setData({ context, items: [], storeId: context.store_id || '' });
        this.setStatus('当前岗位暂无工作量日报模板', 'ok');
        return;
      }
       const canViewAll = !!(context.permissions && context.permissions.can_view_all);
       const nextData = { context, roleIndex, currentRoleLabel: this.data.roleOptions[roleIndex].label, storeId: context.store_id || '', roleLocked: !canViewAll };
      if (this.data.messageEntry && this.data.messageEntry.staffId > 0 && Number(context.staff_id || 0) > 0 && Number(context.staff_id || 0) !== Number(this.data.messageEntry.staffId)) {
        nextData.messageEntryWarning = '当前消息对应的员工身份与当前登录身份不一致，请先确认账号归属';
      }
      this.setData(nextData);
      this.maybePromptReminderSubscription();
      await this.loadTemplate(++this.scopeRequestVersion);
    } catch (err) {
      this.setStatus(err.message || '读取身份失败', 'err');
    }
  },

  async maybePromptReminderSubscription() {
    const templateKeys = ['workload_daily_first', 'workload_daily_second'];
    if (!app.isReminderTemplateReady(templateKeys)) {
      return;
    }
    const promptKey = `workload_reminder_prompt_${today()}_${this.currentRole()}`;
    if (wx.getStorageSync(promptKey)) {
      return;
    }
    try {
      const result = await app.requestReminderSubscription({
        sceneCode: 'workload',
        templateKeys
      });
      if (result.requested) {
        wx.setStorageSync(promptKey, Date.now());
      }
      if (result.requested && result.acceptedKeys.length > 0) {
        wx.showToast({ title: '已开启工作量提醒', icon: 'success' });
      }
    } catch (err) {
      console.error('工作量提醒授权失败:', err);
    }
  },

  setStatus(statusText, statusType = '') {
    this.setData({ statusText, statusType });
  },

  decorateItems(items, values, evidenceMap, fieldErrors = this.data.fieldErrors, storeMetricSummary = this.data.storeMetricSummary) {
    return (items || []).map(item => {
      const code = item.metric_code;
      const evidenceList = evidenceMap[code] || [];
      return {
        ...item,
        current_value: Number(values[code] || 0),
        aggregate_tip: this.aggregateTip(code, storeMetricSummary),
        input_hint: item.input_hint || '',
        evidence_list: evidenceList,
        evidence_count: evidenceList.length,
        has_evidence: evidenceList.length > 0,
        field_error: fieldErrors[code] || '',
        has_error: !!fieldErrors[code],
        upload_failed: this.data.uploadFailedMetricCode === code,
      };
    });
  },

  refreshDisplayItems(values = this.data.values, evidenceMap = this.data.evidenceMap) {
    this.setData({
      items: this.decorateItems(this.data.items, values, evidenceMap)
    });
  },

  applyStoreMetricSummary(values, summary = this.data.storeMetricSummary) {
    const next = { ...(values || {}) };
    Object.keys(summary || {}).forEach(code => {
      const item = summary[code] || {};
      const value = Number(item.value || 0);
      if (value > 0 && Number(next[code] || 0) <= 0) next[code] = value;
    });
    return next;
  },

  aggregateTip(metricCode, storeMetricSummary = this.data.storeMetricSummary) {
    const summary = storeMetricSummary || {};
    return summary[metricCode] && summary[metricCode].tip ? summary[metricCode].tip : '';
  },

  currentRole() {
    return this.data.roleOptions[this.data.roleIndex].value;
  },

  async loadTemplate(version = ++this.scopeRequestVersion) {
    const requestedScope = `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`;
    this.setStatus('正在加载模板...');
    try {
      const role = this.currentRole();
      const res = await app.request({ url: `/workload/template.php?role=${encodeURIComponent(role)}&date=${encodeURIComponent(this.data.reportDate)}` });
      if (version !== this.scopeRequestVersion || requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`) return;
      const items = this.decorateItems((res.data.items || []).map(item => ({ ...item, category_label: categoryLabel(item.category) })), this.data.values, this.data.evidenceMap);
      this.setData({ items, templateDescription: res.data.description || '' });
      await this.loadReport(version);
      if (version !== this.scopeRequestVersion || requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`) return;
      this.setStatus(`模板已加载，共 ${items.length} 项`, 'ok');
    } catch (err) {
      if (version !== this.scopeRequestVersion || requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`) return;
      this.setData({ items: [] });
      this.setStatus(err.message || '模板加载失败', 'err');
    }
  },

  async loadReport(version = ++this.scopeRequestVersion) {
    if (!this.data.storeId || !this.data.reportDate) return;
    const requestedScope = `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`;
    try {
      const role = this.currentRole();
      const res = await app.request({ url: `/workload/my-report.php?date=${encodeURIComponent(this.data.reportDate)}&store_id=${encodeURIComponent(this.data.storeId)}&role=${encodeURIComponent(role)}` });
      if (version !== this.scopeRequestVersion || requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`) return;
      const report = res.data.report || null;
      const currentReportId = report && report.id ? Number(report.id) : 0;
      let evidenceMap = {};
      if (currentReportId) {
        evidenceMap = await this.loadEvidence(currentReportId);
        if (version !== this.scopeRequestVersion || requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`) return;
      }
      const storeMetricSummary = res.data.store_metric_summary || {};
      const values = this.applyStoreMetricSummary(res.data.values || {}, storeMetricSummary);
      this.metricValues = { ...values };
      const completionStatus = res.data.completion_status || 'missing';
      const submitStatus = report && report.submit_status ? report.submit_status : 'missing';
      const conversionResults = res.data.conversion_results || [];
      const conversionSummary = res.data.conversion_summary || null;
      const state = this.reportStatePresentation(completionStatus, submitStatus, !!res.data.is_weekly_rest_day, res.data.is_writable !== false);
      this.setData({
        values,
        remarks: report && report.remarks ? report.remarks : '',
        currentReportId,
        evidenceMap,
        storeMetricSummary,
        items: this.decorateItems(this.data.items, values, evidenceMap, this.data.fieldErrors, storeMetricSummary),
        completionStatus,
        submitStatus,
        conversionResults,
        conversionSummary,
        isWeeklyRestDay: !!res.data.is_weekly_rest_day,
        isWritable: res.data.is_writable !== false,
        reportStatusLabel: state.label,
        reportStatusClass: state.className,
        deadlineText: res.data.deadline_at ? `截止 ${res.data.deadline_at}` : '当日 24:00 截止',
        lastSavedText: report && (report.updated_at || report.created_at) ? `最后保存 ${report.updated_at || report.created_at}` : '尚未保存',
        isDirty: false,
      }, () => this.renderDailyStatus());
      this.restoreRecovery();
      this.updateDraftEvidenceTip();
      await this.loadDailyStatus(version, requestedScope);
    } catch (err) {
      if (version !== this.scopeRequestVersion || requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`) return;
      this.setStatus(err.message || '日报读取失败', 'err');
    }
  },

  async loadDailyStatus(version, requestedScope) {
    try {
      const res = await app.request({ url: '/workload/my-status.php' });
      if (version && (version !== this.scopeRequestVersion || (requestedScope && requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`))) return;
      this.setData({ dailyStatus: res.data || null }, () => this.renderDailyStatus());
    } catch (err) {
      if (version && (version !== this.scopeRequestVersion || (requestedScope && requestedScope !== `${this.data.reportDate}|${this.data.storeId}|${this.currentRole()}`))) return;
      this.setData({ dailyActionText: '每日工作量状态读取失败，请下拉刷新后重试。' });
    }
  },

  selectedDailyState() {
    const status = this.data.dailyStatus || {};
    if (status.today && status.today.business_date === this.data.reportDate) return status.today;
    if (status.yesterday_makeup && status.yesterday_makeup.business_date === this.data.reportDate) return status.yesterday_makeup;
    return null;
  },

  dailyAction(state) {
    if (!state) return '当前日期暂无每日结算记录，提交后将按已发布规则计算点数。';
    if (Number(state.rejected_points || 0) > 0) return `有 ${formatPoints(state.rejected_points)} 点已驳回，请查看审核意见并补充后重新提交。`;
    if (state.status === 'completed') return `今日已达标。有效 ${formatPoints(state.effective_points)} 点，继续保持。`;
    if (state.status === 'makeup_open') return `上一工作日还差 ${formatPoints(state.gap_points)} 点，请在 ${state.makeup_deadline_at} 前补齐并重新提交。`;
    if (state.status === 'overdue') {
      const penalty = state.penalty;
      return `该日已逾期，还差 ${formatPoints(state.gap_points)} 点${penalty ? `，处罚 ${formatPoints(penalty.amount)} 元，状态：${penalty.status_label}` : '，处罚记录正在生成或待确认。'}`;
    }
    return `今天还差 ${formatPoints(state.gap_points)} 点，请在当日结束前完成并提交日报。`;
  },

  renderDailyStatus() {
    const state = this.selectedDailyState();
    const target = Number((state && state.target_points) || 4);
    const effective = Number((state && state.effective_points) || 0);
    const reported = Number((state && state.reported_points) || 0);
    const pending = Number((state && state.pending_points) || 0);
    const rejected = Number((state && state.rejected_points) || 0);
    const gap = state ? Number(state.gap_points || Math.max(0, target - effective)) : target;
    const yesterday = this.data.dailyStatus && this.data.dailyStatus.yesterday_makeup;
    const summary = this.data.dailyStatus && this.data.dailyStatus.monthly_penalty_summary;
    const conversionTable = (this.data.dailyStatus && this.data.dailyStatus.conversion_table || []).map(row => ({
      metricsText: (row.metrics || []).join('、'),
      description: row.description || `${formatPoints(row.threshold_value)} 个项目 = ${formatPoints(row.points_per_unit)} 点工作量`,
    }));
    const yesterdayMakeupText = yesterday && yesterday.status === 'makeup_open'
      ? `上一工作日可补 ${formatPoints(yesterday.gap_points)} 点，截止 ${yesterday.makeup_deadline_at}`
      : yesterday && yesterday.status === 'overdue'
        ? `昨日已逾期，还差 ${formatPoints(yesterday.gap_points)} 点${yesterday.penalty ? `，处罚 ${formatPoints(yesterday.penalty.amount)} 元，状态：${yesterday.penalty.status_label}` : ''}`
        : `上一工作日工作量：${yesterday ? yesterday.status_label : '暂无记录'}`;
    const penaltySummaryText = summary
      ? `本月处罚：${formatPoints(summary.amount)} 元，待处理 ${formatPoints(summary.pending_amount)} 元，共 ${summary.record_count} 条记录`
      : '本月暂无处罚记录';
    this.setData({
      dailyTargetPoints: formatPoints(target),
      dailyEffectivePoints: formatPoints(effective),
      dailyGapPoints: formatPoints(gap),
      dailyReportedPoints: formatPoints(reported),
      dailyPendingPoints: formatPoints(pending),
      dailyRejectedPoints: formatPoints(rejected),
      dailyActionText: this.dailyAction(state),
      dailyActionClass: state && state.status === 'completed' ? 'complete' : state && state.status === 'overdue' ? 'overdue' : '',
      makeupDate: this.data.dailyStatus && this.data.dailyStatus.makeup_business_date ? this.data.dailyStatus.makeup_business_date : '',
      makeupDateLabel: this.data.dailyStatus && this.data.dailyStatus.makeup_business_date
        ? `补交 ${this.data.dailyStatus.makeup_business_date} 工作量`
        : '',
      yesterdayMakeupText,
      penaltySummaryText,
      conversionTable,
      progressPercent: Math.min(100, Math.round(effective / Math.max(1, target) * 100)),
    });
  },

  async openMakeupReport() {
    const date = this.data.makeupDate;
    if (!date || !this.data.dailyStatus?.yesterday_makeup || this.data.dailyStatus.yesterday_makeup.status !== 'makeup_open') return;
    if (!(await this.guardScopeChange())) return;
    this.metricValues = {};
    this.setData({ reportDate: date, currentReportId: 0, evidenceMap: {}, values: {}, remarks: '' });
    this.refreshDisplayItems({}, {});
    this.loadTemplate(++this.scopeRequestVersion);
  },

  async loadEvidence(reportId) {
    const res = await app.request({ url: `/workload/evidence-list.php?report_id=${encodeURIComponent(reportId)}` });
    const evidenceMap = {};
    (res.data.list || []).forEach(item => {
      const code = item.metric_code || '';
      if (!evidenceMap[code]) evidenceMap[code] = [];
      evidenceMap[code].push(media.normalizeMediaFields(item, ['file_url', 'image_file']));
    });
    return evidenceMap;
  },

  async onDateChange(e) {
    const maxDate = today();
    const reportDate = e.detail.value > maxDate ? maxDate : e.detail.value;
    if (!(await this.guardScopeChange())) return;
    this.metricValues = {};
    this.setData({ reportDate, maxDate, currentReportId: 0, evidenceMap: {}, values: {}, remarks: '' });
    this.refreshDisplayItems({}, {});
    this.loadTemplate(++this.scopeRequestVersion);
  },

  async onRoleChange(e) {
    if (this.data.roleLocked) return;
    if (!(await this.guardScopeChange())) return;
    const roleIndex = Number(e.detail.value);
    this.metricValues = {};
    this.setData({ roleIndex, currentRoleLabel: this.data.roleOptions[roleIndex].label, currentReportId: 0, evidenceMap: {}, values: {}, remarks: '' });
    this.loadTemplate(++this.scopeRequestVersion);
  },

  onMetricInput(e) {
    const code = e.currentTarget.dataset.code;
    const values = { ...this.data.values, [code]: Number(e.detail.value || 0) };
    this.metricValues = { ...this.metricValues, [code]: values[code] };
    const fieldErrors = { ...this.data.fieldErrors };
    delete fieldErrors[code];
    this.setData({ values, fieldErrors, items: this.decorateItems(this.data.items, values, this.data.evidenceMap, fieldErrors), isDirty: true, lastSavedText: '尚未保存' }, () => {
      this.renderDailyStatus();
      this.persistRecovery();
    });
  },

  currentMetricValues() {
    const values = { ...this.data.values, ...this.metricValues };
    (this.data.items || []).forEach(item => {
      const code = item.metric_code;
      if (typeof values[code] === 'undefined') {
        values[code] = Number(item.current_value || 0);
      }
    });
    return values;
  },

  onRemarksInput(e) {
    this.setData({ remarks: e.detail.value, isDirty: true, lastSavedText: '尚未保存' }, () => this.persistRecovery());
  },

  reportStatePresentation(completionStatus, submitStatus, isRest, isWritable) {
    if (isRest) return { label: '今日公休', className: 'rest' };
    if (completionStatus === 'locked_missing' || !isWritable) return { label: '已锁定', className: 'locked' };
    if (submitStatus === 'submitted' || ['submitted', 'corrected'].includes(completionStatus)) return { label: '已提交', className: 'submitted' };
    if (submitStatus === 'draft' || completionStatus === 'draft') return { label: '草稿待提交', className: 'draft' };
    return { label: '未开始', className: '' };
  },

  isFormDisabled() {
    return !!this.data.busyAction || !this.data.isWritable || this.data.isWeeklyRestDay || this.data.submitStatus === 'submitted' || this.data.completionStatus === 'locked_missing';
  },

  beginOperation(action) {
    if (this.data.busyAction || this.isFormDisabled()) return false;
    this.setData({ busyAction: action });
    return true;
  },

  endOperation() {
    this.setData({ busyAction: '' });
  },

  recoveryKey() {
    return `workload_mp_recovery_${this.data.reportDate}_${this.data.storeId}_${this.currentRole()}`;
  },

  persistRecovery() {
    if (!this.data.isDirty || !this.data.items.length) return;
    try {
      wx.setStorageSync(this.recoveryKey(), { values: this.currentMetricValues(), remarks: this.data.remarks, updatedAt: Date.now() });
    } catch (err) {
      console.error('保存工作量恢复数据失败:', err);
    }
  },

  restoreRecovery() {
    try {
      const recovery = wx.getStorageSync(this.recoveryKey());
      if (!recovery || !recovery.values) return;
      const values = { ...this.data.values, ...recovery.values };
      this.metricValues = { ...values };
      this.setData({ values, remarks: recovery.remarks || '', items: this.decorateItems(this.data.items, values, this.data.evidenceMap), isDirty: true, lastSavedText: '已恢复本机草稿' }, () => this.renderDailyStatus());
      this.setStatus('已恢复本机尚未提交的草稿，请核对后保存', 'ok');
    } catch (err) {
      console.error('恢复工作量草稿失败:', err);
    }
  },

  async guardScopeChange() {
    if (!this.data.isDirty) return true;
    try {
      await this.saveReport('draft', { silent: true });
      return true;
    } catch (err) {
      const modal = await wx.showModal({ title: '草稿保存失败', content: '切换后本次修改可能丢失，确认继续吗？' });
      return modal.confirm;
    }
  },

  async chooseEvidence(e) {
    const metricCode = e.currentTarget.dataset.code;
    if (!metricCode || !this.beginOperation('uploading')) return;
    try {
      const privacyAllowed = await this.ensureMediaPrivacyAuthorized();
      if (!privacyAllowed) {
        this.setStatus('需要先同意隐私指引后才能上传图片', 'err');
        return;
      }
      const metric = this.findMetricItem(metricCode);
      const existingList = this.data.evidenceMap[metricCode] || [];
      const maxCount = metric ? Math.min(10, Math.max(1, Number(metric.max_evidence_count || 3))) : 10;
      if (existingList.length >= maxCount) {
        throw new Error(`该指标最多只能上传 ${maxCount} 张凭证图片`);
      }
       this.setStatus('请选择凭证图片...');
       const media = await wx.chooseMedia({ count: 1, mediaType: ['image'], sourceType: ['album', 'camera'] });
       const file = media.tempFiles && media.tempFiles[0];
       if (!file || !file.tempFilePath) throw new Error('未选择图片');
       this.validateEvidenceFile(file);
       const reportId = await this.ensureReportForEvidence(true);
       if (!reportId) throw new Error('请先保存日报后再上传图片');
       this.pendingUploadPaths[metricCode] = file.tempFilePath;
       this.setData({ uploadingMetricCode: metricCode, uploadProgress: 0, uploadFailedMetricCode: '' });
       this.setStatus('正在上传凭证图片...');
       await this.uploadEvidenceFile(reportId, metricCode, file.tempFilePath);
      const evidenceMap = await this.loadEvidence(reportId);
       delete this.pendingUploadPaths[metricCode];
       this.setData({ evidenceMap, items: this.decorateItems(this.data.items, this.data.values, evidenceMap), uploadProgress: 100, uploadFailedMetricCode: '' });
      this.updateDraftEvidenceTip();
      this.setStatus('凭证图片上传成功', 'ok');
    } catch (err) {
      if (err && /cancel/.test(String(err.errMsg || err.message || ''))) {
        return;
      }
       if (this.pendingUploadPaths[metricCode]) this.setData({ uploadFailedMetricCode: metricCode });
       this.setStatus(`${(err && err.message) || '图片上传失败'}，可点击重试`, 'err');
     } finally {
       this.setData({ uploadingMetricCode: '' });
       this.endOperation();
     }
  },

  async retryEvidence(e) {
    const metricCode = e.currentTarget.dataset.code;
    const filePath = this.pendingUploadPaths[metricCode];
    if (!metricCode || !filePath || !this.beginOperation('uploading')) return;
    try {
      const reportId = await this.ensureReportForEvidence(true);
      this.setData({ uploadingMetricCode: metricCode, uploadProgress: 0, uploadFailedMetricCode: '' });
      await this.uploadEvidenceFile(reportId, metricCode, filePath);
      const evidenceMap = await this.loadEvidence(reportId);
      delete this.pendingUploadPaths[metricCode];
      this.setData({ evidenceMap, items: this.decorateItems(this.data.items, this.data.values, evidenceMap), uploadProgress: 100 });
      this.updateDraftEvidenceTip();
      this.setStatus('凭证图片上传成功', 'ok');
    } catch (err) {
      this.setData({ uploadFailedMetricCode: metricCode });
      this.setStatus((err && err.message) || '图片上传失败', 'err');
    } finally {
      this.setData({ uploadingMetricCode: '' });
      this.endOperation();
    }
  },

  previewEvidence(e) {
    const url = e.currentTarget.dataset.url;
    if (!url) return;
    wx.previewImage({ current: url, urls: [url] });
  },

  async deleteEvidence(e) {
    const evidenceId = Number(e.currentTarget.dataset.id || 0);
    const metricCode = e.currentTarget.dataset.code || '';
    if (!evidenceId || !metricCode) return;
    const modal = await wx.showModal({ title: '确认删除', content: '确认删除这张凭证图片吗？' });
    if (!modal.confirm) return;
    if (!this.beginOperation('deleting')) return;
    try {
      this.setData({ uploadingMetricCode: metricCode });
      this.setStatus('正在删除凭证图片...');
      await app.request({
        url: '/workload/evidence-delete.php',
        method: 'POST',
        idempotencyKey: api.createIdempotencyKey(`workload_evidence_delete_${evidenceId}`),
        data: { id: evidenceId },
      });
      const evidenceMap = this.data.currentReportId ? await this.loadEvidence(this.data.currentReportId) : {};
      this.setData({ evidenceMap, items: this.decorateItems(this.data.items, this.data.values, evidenceMap) });
      this.updateDraftEvidenceTip();
      this.setStatus('凭证图片已删除', 'ok');
    } catch (err) {
      this.setStatus((err && err.message) || '删除凭证图片失败', 'err');
    } finally {
      this.setData({ uploadingMetricCode: '' });
      this.endOperation();
    }
  },

  validateEvidenceFile(file) {
    const size = Number(file.size || 0);
    if (size > 0 && size < 1024) {
      throw new Error('图片文件异常，请重新拍摄或选择');
    }
    if (size > 5 * 1024 * 1024) {
      throw new Error('图片不能超过 5MB，请压缩后重新上传');
    }
  },

  uploadEvidenceFile(reportId, metricCode, filePath) {
    return app.uploadFile({
      url: '/workload/evidence-upload.php',
      filePath,
      name: 'image_file',
      idempotencyKey: api.createIdempotencyKey(`workload_evidence_upload_${reportId}_${metricCode}`),
      formData: {
        report_id: String(reportId),
        metric_code: metricCode,
      },
      timeout: 60000,
      onProgress: (uploadProgress) => this.setData({ uploadProgress }),
    });
  },

  saveDraft() {
    this.saveReport('draft').catch(() => {});
  },

  submitReport() {
    this.saveReport('submitted').catch(() => {});
  },

  async saveReport(submitStatus, options = {}) {
    if (!this.data.storeId) {
      this.setStatus('请先填写门店 ID', 'err');
      throw new Error('请先确认门店');
    }
    const currentValues = this.currentMetricValues();
    if (!this.validateMetrics(currentValues, submitStatus === 'submitted')) throw new Error('请修正表单错误');
    if (!this.beginOperation(submitStatus === 'submitted' ? 'submitting' : 'saving')) throw new Error('操作正在进行中');
    const values = this.data.items.map(item => ({ metric_code: item.metric_code, value: Number(currentValues[item.metric_code] || 0) }));
    if (!options.silent) this.setStatus(submitStatus === 'submitted' ? '正在提交...' : '正在保存...');
    try {
      const res = await app.request({
        url: '/workload/save-report.php',
        method: 'POST',
        idempotencyKey: api.createIdempotencyKey(`workload_${submitStatus}_${this.data.reportDate}`),
        data: {
          report_date: this.data.reportDate,
          store_id: Number(this.data.storeId),
          role_code: this.currentRole(),
          submit_status: submitStatus,
          source: 'mini_program',
          remarks: this.data.remarks,
          values,
        },
      });
      try { wx.removeStorageSync(this.recoveryKey()); } catch (err) {}
      this.setData({ currentReportId: Number(res.data.report_id || 0), isDirty: false, submitStatus, completionStatus: submitStatus, lastSavedText: `最后保存 ${new Date().toLocaleTimeString()}` });
      if (!options.silent) this.setStatus(`${res.message || '保存成功'} · 报告ID ${res.data.report_id}`, 'ok');
      if (!options.skipReload) await this.loadReport();
      else await this.loadDailyStatus();
      if (submitStatus === 'submitted' && !options.silent) wx.showModal({ title: '提交成功', content: '日报已进入后台今日统计。', showCancel: false });
      return res;
    } catch (err) {
      if (!options.silent) this.setStatus(err.message || '保存失败', 'err');
      throw err;
    } finally {
      this.endOperation();
    }
  },

  async ensureReportForEvidence(skipSave = false) {
    if (this.data.currentReportId > 0) return this.data.currentReportId;
    if (!skipSave) {
      const res = await this.saveReport('draft', { silent: true, skipReload: true });
      return Number(res.data.report_id || 0);
    }
    const currentValues = this.currentMetricValues();
    const values = this.data.items.map(item => ({ metric_code: item.metric_code, value: Number(currentValues[item.metric_code] || 0) }));
    const res = await app.request({
      url: '/workload/save-report.php',
      method: 'POST',
      idempotencyKey: api.createIdempotencyKey(`workload_draft_${this.data.reportDate}`),
      data: {
        report_date: this.data.reportDate,
        store_id: Number(this.data.storeId),
        role_code: this.currentRole(),
        submit_status: 'draft',
        source: 'mini_program',
        remarks: this.data.remarks,
        values,
      },
    });
    const currentReportId = Number(res.data.report_id || 0);
    try { wx.removeStorageSync(this.recoveryKey()); } catch (err) {}
    this.setData({ currentReportId, isDirty: false, submitStatus: 'draft', completionStatus: 'draft', lastSavedText: `最后保存 ${new Date().toLocaleTimeString()}` });
    return currentReportId;
  },

  validateEvidenceRequirements() {
    const gaps = this.getEvidenceGaps();
    if (!gaps.length) return '';
    return gaps[0].message;
  },

  evidenceCountForMetric(metricCode) {
    const ownCount = (this.data.evidenceMap[metricCode] || []).length;
    const summary = (this.data.storeMetricSummary || {})[metricCode] || {};
    const aggregateCount = Number(summary.value || 0);
    return metricCode === 'manager_store_poi_checkin' ? Math.max(ownCount, aggregateCount) : ownCount;
  },

  updateDraftEvidenceTip() {
    const gaps = this.getEvidenceGaps();
    this.setData({
      draftEvidenceTip: gaps.length ? `还有 ${gaps.length} 个已填写指标缺少图片凭证，提交前请补齐` : '',
    });
    this.renderDailyStatus();
  },

  validateMetrics(values, forSubmit) {
    const fieldErrors = {};
    this.data.items.forEach(item => {
      const raw = values[item.metric_code];
      const value = Number(raw);
      let message = '';
      if (!Number.isFinite(value)) message = '请输入有效数字';
      else if (value < 0) message = '数值不能小于 0';
      else if (item.min_value !== null && typeof item.min_value !== 'undefined' && value < Number(item.min_value)) message = `数值不能小于 ${item.min_value}`;
      else if (item.max_value !== null && typeof item.max_value !== 'undefined' && value > Number(item.max_value)) message = `数值不能大于 ${item.max_value}`;
      else if (forSubmit && Number(item.required) && !Number(item.allow_zero) && value === 0) message = '该指标需要填写大于 0 的数值';
      if (message) fieldErrors[item.metric_code] = message;
    });
    if (forSubmit) this.getEvidenceGaps().forEach(gap => { if (!fieldErrors[gap.metricCode]) fieldErrors[gap.metricCode] = gap.message; });
    this.setData({ fieldErrors, items: this.decorateItems(this.data.items, values, this.data.evidenceMap, fieldErrors) });
    const firstCode = Object.keys(fieldErrors)[0];
    if (firstCode) {
      this.setStatus(fieldErrors[firstCode], 'err');
      wx.pageScrollTo({ selector: `#metric_${firstCode}`, duration: 250 });
      return false;
    }
    return true;
  },

  getEvidenceGaps() {
    const currentValues = this.currentMetricValues();
    return this.data.items.reduce((gaps, item) => {
      if (!Number(item.need_evidence)) return gaps;
      const value = Number(currentValues[item.metric_code] || 0);
      if (value <= 0) return gaps;
      const requiredCount = Math.max(1, Number(item.min_evidence_count || 1));
      const currentCount = this.evidenceCountForMetric(item.metric_code);
      if (currentCount < requiredCount) {
        gaps.push({
          metricCode: item.metric_code,
          message: `${item.metric_name || item.metric_code} 至少需要上传 ${requiredCount} 张凭证图片`
        });
      }
      return gaps;
    }, []);
  },

  findMetricItem(metricCode) {
    return this.data.items.find(item => item.metric_code === metricCode) || null;
  },

  ensureMediaPrivacyAuthorized() {
    if (typeof wx.getPrivacySetting !== 'function') {
      return Promise.resolve(true);
    }
    return new Promise((resolve) => {
      wx.getPrivacySetting({
        success: (res) => {
          if (!res.needAuthorization) {
            resolve(true);
            return;
          }
          this.privacyResolve = resolve;
          this.setData({
            showPrivacyModal: true,
            privacyContractName: res.privacyContractName || '《用户隐私保护指引》',
          });
        },
        fail: () => resolve(true),
      });
    });
  },

  handleOpenPrivacyContract() {
    if (typeof wx.openPrivacyContract !== 'function') {
      wx.showToast({ title: '当前微信版本较低', icon: 'none' });
      return;
    }
    wx.openPrivacyContract({
      fail: () => {
        wx.showToast({ title: '打开隐私指引失败', icon: 'none' });
      }
    });
  },

  handleAgreePrivacyAuthorization() {
    const resolve = this.privacyResolve;
    this.privacyResolve = null;
    this.setData({ showPrivacyModal: false });
    if (resolve) resolve(true);
  },

  handleRejectPrivacyAuthorization() {
    const resolve = this.privacyResolve;
    this.privacyResolve = null;
    this.setData({ showPrivacyModal: false });
    if (resolve) resolve(false);
  },
});
