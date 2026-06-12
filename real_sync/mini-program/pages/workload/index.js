const app = getApp();

function today() {
  return new Date(Date.now() + 8 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

function categoryLabel(v) {
  return { behavior: '行为', process: '过程', result: '结果', derived: '计算' }[v] || v;
}

Page({
  metricValues: {},

  data: {
    context: {},
    reportDate: today(),
    maxDate: today(),
    roleOptions: [{ label: '销售', value: 'sales' }, { label: '教练', value: 'coach' }],
    roleIndex: 1,
    currentRoleLabel: '教练',
    storeId: '',
    items: [],
    values: {},
    currentReportId: 0,
    evidenceMap: {},
    draftEvidenceTip: '',
    remarks: '',
    statusText: '准备加载日报模板...',
    statusType: '',
    uploadingMetricCode: '',
  },

  onLoad() {
    this.syncDateLimit();
    this.init();
  },

  onShow() {
    this.syncDateLimit();
  },

  syncDateLimit() {
    const maxDate = today();
    const next = { maxDate };
    if (!this.data.reportDate || this.data.reportDate > maxDate) {
      next.reportDate = maxDate;
    }
    this.setData(next);
  },

  async init() {
    try {
      const res = await app.request({ url: '/common/context-info.php' });
      const context = res.data.context || {};
      if (context.role !== 'sales' && context.role !== 'coach') {
        this.setData({ context, items: [], storeId: context.store_id || '' });
        this.setStatus('当前岗位无需提交销售/教练工作量日报', 'ok');
        return;
      }
      const roleIndex = context.role === 'sales' ? 0 : 1;
      this.setData({ context, roleIndex, currentRoleLabel: this.data.roleOptions[roleIndex].label, storeId: context.store_id || '' });
      await this.loadTemplate();
    } catch (err) {
      this.setStatus(err.message || '读取身份失败', 'err');
    }
  },

  setStatus(statusText, statusType = '') {
    this.setData({ statusText, statusType });
  },

  decorateItems(items, values, evidenceMap) {
    return (items || []).map(item => {
      const code = item.metric_code;
      const evidenceList = evidenceMap[code] || [];
      return {
        ...item,
        current_value: Number(values[code] || 0),
        evidence_list: evidenceList,
        evidence_count: evidenceList.length,
        has_evidence: evidenceList.length > 0,
      };
    });
  },

  refreshDisplayItems(values = this.data.values, evidenceMap = this.data.evidenceMap) {
    this.setData({
      items: this.decorateItems(this.data.items, values, evidenceMap)
    });
  },

  currentRole() {
    return this.data.roleOptions[this.data.roleIndex].value;
  },

  async loadTemplate() {
    this.setStatus('正在加载模板...');
    try {
      const role = this.currentRole();
      const res = await app.request({ url: `/workload/template.php?role=${encodeURIComponent(role)}` });
      const items = this.decorateItems((res.data.items || []).map(item => ({ ...item, category_label: categoryLabel(item.category) })), this.data.values, this.data.evidenceMap);
      this.setData({ items });
      await this.loadReport();
      this.setStatus(`模板已加载，共 ${items.length} 项`, 'ok');
    } catch (err) {
      this.setData({ items: [] });
      this.setStatus(err.message || '模板加载失败', 'err');
    }
  },

  async loadReport() {
    if (!this.data.storeId || !this.data.reportDate) return;
    try {
      const role = this.currentRole();
      const res = await app.request({ url: `/workload/my-report.php?date=${encodeURIComponent(this.data.reportDate)}&store_id=${encodeURIComponent(this.data.storeId)}&role=${encodeURIComponent(role)}` });
      const report = res.data.report || null;
      const currentReportId = report && report.id ? Number(report.id) : 0;
      let evidenceMap = {};
      if (currentReportId) {
        evidenceMap = await this.loadEvidence(currentReportId);
      }
      const values = res.data.values || {};
      this.metricValues = { ...values };
      this.setData({
        values,
        remarks: report && report.remarks ? report.remarks : '',
        currentReportId,
        evidenceMap,
        items: this.decorateItems(this.data.items, values, evidenceMap),
      });
      this.updateDraftEvidenceTip();
    } catch (err) {
      this.setStatus(err.message || '日报读取失败', 'err');
    }
  },

  async loadEvidence(reportId) {
    const res = await app.request({ url: `/workload/evidence-list.php?report_id=${encodeURIComponent(reportId)}` });
    const evidenceMap = {};
    (res.data.list || []).forEach(item => {
      const code = item.metric_code || '';
      if (!evidenceMap[code]) evidenceMap[code] = [];
      evidenceMap[code].push(item);
    });
    return evidenceMap;
  },

  onDateChange(e) {
    const maxDate = today();
    const reportDate = e.detail.value > maxDate ? maxDate : e.detail.value;
    this.metricValues = {};
    this.setData({ reportDate, maxDate, currentReportId: 0, evidenceMap: {}, values: {}, remarks: '' });
    this.refreshDisplayItems({}, {});
    this.loadReport();
  },

  onRoleChange(e) {
    const roleIndex = Number(e.detail.value);
    this.metricValues = {};
    this.setData({ roleIndex, currentRoleLabel: this.data.roleOptions[roleIndex].label, currentReportId: 0, evidenceMap: {}, values: {}, remarks: '' });
    this.loadTemplate();
  },

  onStoreInput(e) {
    this.metricValues = {};
    this.setData({ storeId: e.detail.value, currentReportId: 0, evidenceMap: {}, values: {}, remarks: '' });
    this.refreshDisplayItems({}, {});
  },

  onMetricInput(e) {
    const code = e.currentTarget.dataset.code;
    const values = { ...this.data.values, [code]: Number(e.detail.value || 0) };
    this.metricValues = { ...this.metricValues, [code]: values[code] };
    this.setData({ values, items: this.decorateItems(this.data.items, values, this.data.evidenceMap) });
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
    this.setData({ remarks: e.detail.value });
  },

  async chooseEvidence(e) {
    const metricCode = e.currentTarget.dataset.code;
    if (!metricCode) return;
    try {
      const metric = this.findMetricItem(metricCode);
      const existingList = this.data.evidenceMap[metricCode] || [];
      const maxCount = metric ? Math.min(10, Math.max(1, Number(metric.max_evidence_count || 3))) : 10;
      if (existingList.length >= maxCount) {
        throw new Error(`该指标最多只能上传 ${maxCount} 张凭证图片`);
      }
      const reportId = await this.ensureReportForEvidence();
      if (!reportId) throw new Error('请先保存日报后再上传图片');
      this.setData({ uploadingMetricCode: metricCode });
      this.setStatus('正在选择并上传凭证图片...');
      const media = await wx.chooseMedia({ count: 1, mediaType: ['image'], sourceType: ['album', 'camera'] });
      const file = media.tempFiles && media.tempFiles[0];
      if (!file || !file.tempFilePath) throw new Error('未选择图片');
      this.validateEvidenceFile(file);
      await this.uploadEvidenceFile(reportId, metricCode, file.tempFilePath);
      const evidenceMap = await this.loadEvidence(reportId);
      this.setData({ evidenceMap, items: this.decorateItems(this.data.items, this.data.values, evidenceMap) });
      this.updateDraftEvidenceTip();
      this.setStatus('凭证图片上传成功', 'ok');
    } catch (err) {
      if (err && /cancel/.test(String(err.errMsg || err.message || ''))) {
        return;
      }
      this.setStatus((err && err.message) || '图片上传失败', 'err');
    } finally {
      this.setData({ uploadingMetricCode: '' });
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
    try {
      this.setData({ uploadingMetricCode: metricCode });
      this.setStatus('正在删除凭证图片...');
      await app.request({
        url: '/workload/evidence-delete.php',
        method: 'POST',
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
      formData: {
        report_id: String(reportId),
        metric_code: metricCode,
      },
      timeout: 60000,
    });
  },

  saveDraft() {
    this.saveReport('draft');
  },

  submitReport() {
    this.saveReport('submitted');
  },

  async saveReport(submitStatus) {
    if (!this.data.storeId) {
      this.setStatus('请先填写门店 ID', 'err');
      return;
    }
    if (submitStatus === 'submitted') {
      const evidenceError = this.validateEvidenceRequirements();
      if (evidenceError) {
        this.setStatus(evidenceError, 'err');
        return;
      }
    }
    const currentValues = this.currentMetricValues();
    const values = this.data.items.map(item => ({ metric_code: item.metric_code, value: Number(currentValues[item.metric_code] || 0) }));
    this.setStatus('正在保存...');
    try {
      const res = await app.request({
        url: '/workload/save-report.php',
        method: 'POST',
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
      this.setData({ currentReportId: Number(res.data.report_id || 0) });
      this.setStatus(`${res.message || '保存成功'} · 报告ID ${res.data.report_id}`, 'ok');
      await this.loadReport();
    } catch (err) {
      this.setStatus(err.message || '保存失败', 'err');
    }
  },

  async ensureReportForEvidence() {
    if (this.data.currentReportId > 0) return this.data.currentReportId;
    const currentValues = this.currentMetricValues();
    const values = this.data.items.map(item => ({ metric_code: item.metric_code, value: Number(currentValues[item.metric_code] || 0) }));
    const res = await app.request({
      url: '/workload/save-report.php',
      method: 'POST',
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
    this.setData({ currentReportId });
    return currentReportId;
  },

  validateEvidenceRequirements() {
    const gaps = this.getEvidenceGaps();
    if (!gaps.length) return '';
    return gaps[0].message;
  },

  updateDraftEvidenceTip() {
    const gaps = this.getEvidenceGaps();
    this.setData({
      draftEvidenceTip: gaps.length ? `还有 ${gaps.length} 个已填写指标缺少图片凭证，提交前请补齐` : ''
    });
  },

  getEvidenceGaps() {
    const currentValues = this.currentMetricValues();
    return this.data.items.reduce((gaps, item) => {
      if (!Number(item.need_evidence)) return gaps;
      const value = Number(currentValues[item.metric_code] || 0);
      if (value <= 0) return gaps;
      const requiredCount = Math.max(1, Number(item.min_evidence_count || 1));
      const currentCount = (this.data.evidenceMap[item.metric_code] || []).length;
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
});
