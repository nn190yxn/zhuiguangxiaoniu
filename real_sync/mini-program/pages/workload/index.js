const app = getApp();

function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function categoryLabel(v) {
  return { behavior: '行为', process: '过程', result: '结果', derived: '计算' }[v] || v;
}

Page({
  data: {
    context: {},
    reportDate: today(),
    maxDate: today(),
    roleOptions: [{ label: '销售', value: 'sales' }, { label: '教练', value: 'coach' }],
    roleIndex: 1,
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
    rawItems: [],
    hasItems: false,
    contextRoleText: '',
    currentRoleLabel: '教练',
  },

  onLoad() {
    this.init();
  },

  async init() {
    try {
      const res = await app.request({ url: '/common/context-info.php' });
      const context = res.data.context || {};
      if (context.role !== 'sales' && context.role !== 'coach') {
        this.setData({
          context,
          items: [],
          rawItems: [],
          hasItems: false,
          storeId: context.store_id || '',
          contextRoleText: context.role_name || context.role || '',
        });
        this.setStatus('当前岗位无需提交销售/教练工作量日报', 'ok');
        return;
      }
      const roleIndex = context.role === 'sales' ? 0 : 1;
      this.setData({
        context,
        roleIndex,
        storeId: context.store_id || '',
        contextRoleText: context.role_name || context.role || '',
        currentRoleLabel: this.data.roleOptions[roleIndex].label,
      });
      await this.loadTemplate();
    } catch (err) {
      this.setStatus(err.message || '读取身份失败', 'err');
    }
  },

  setStatus(statusText, statusType = '') {
    this.setData({ statusText, statusType });
  },

  currentRole() {
    return this.data.roleOptions[this.data.roleIndex].value;
  },

  async loadTemplate() {
    this.setStatus('正在加载模板...');
    try {
      const role = this.currentRole();
      const res = await app.request({ url: `/workload/template.php?role=${encodeURIComponent(role)}` });
      const rawItems = (res.data.items || []).map(item => ({ ...item, category_label: categoryLabel(item.category) }));
      this.setData({ rawItems });
      this.refreshItems();
      await this.loadReport();
      this.setStatus(`模板已加载，共 ${rawItems.length} 项`, 'ok');
    } catch (err) {
      this.setData({ rawItems: [], items: [], hasItems: false });
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
      this.setData({ values: res.data.values || {}, remarks: report && report.remarks ? report.remarks : '', currentReportId, evidenceMap });
      this.refreshItems();
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
    this.setData({ reportDate: e.detail.value });
    this.loadReport();
  },

  onRoleChange(e) {
    const roleIndex = Number(e.detail.value);
    this.setData({
      roleIndex,
      values: {},
      currentRoleLabel: this.data.roleOptions[roleIndex].label,
    });
    this.loadTemplate();
  },

  onStoreInput(e) {
    this.setData({ storeId: e.detail.value });
  },

  onMetricInput(e) {
    const code = e.currentTarget.dataset.code;
    const values = { ...this.data.values, [code]: Number(e.detail.value || 0) };
    this.setData({ values });
    this.refreshItems();
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
      const imagePath = await this.compressEvidenceImage(file.tempFilePath);
      const imageData = await this.readFileAsDataUrl(imagePath);
      await app.request({
        url: '/workload/evidence-upload.php',
        method: 'POST',
        data: { report_id: reportId, metric_code: metricCode, image_data: imageData },
      });
      const evidenceMap = await this.loadEvidence(reportId);
      this.setData({ evidenceMap });
      this.refreshItems();
      this.updateDraftEvidenceTip();
      this.setStatus('凭证图片上传成功', 'ok');
    } catch (err) {
      if (err && /cancel/.test(String(err.errMsg || err.message || ''))) {
        return;
      }
      this.setStatus((err && err.message) || '图片上传失败', 'err');
    } finally {
      this.setData({ uploadingMetricCode: '' });
      this.refreshItems();
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
      this.setData({ evidenceMap });
      this.refreshItems();
      this.updateDraftEvidenceTip();
      this.setStatus('凭证图片已删除', 'ok');
    } catch (err) {
      this.setStatus((err && err.message) || '删除凭证图片失败', 'err');
    } finally {
      this.setData({ uploadingMetricCode: '' });
      this.refreshItems();
    }
  },

  readFileAsDataUrl(filePath) {
    return new Promise((resolve, reject) => {
      const fs = wx.getFileSystemManager();
      fs.readFile({
        filePath,
        encoding: 'base64',
        success: res => resolve(`data:image/jpeg;base64,${res.data}`),
        fail: () => reject(new Error('读取图片失败，请重试')),
      });
    });
  },

  compressEvidenceImage(filePath) {
    return new Promise(resolve => {
      if (!wx.compressImage) {
        resolve(filePath);
        return;
      }
      wx.compressImage({
        src: filePath,
        quality: 70,
        success: res => resolve(res.tempFilePath || filePath),
        fail: () => resolve(filePath),
      });
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
    const values = this.data.rawItems.map(item => ({ metric_code: item.metric_code, value: Number(this.data.values[item.metric_code] || 0) }));
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
    const values = this.data.rawItems.map(item => ({ metric_code: item.metric_code, value: Number(this.data.values[item.metric_code] || 0) }));
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
    const names = gaps.slice(0, 3).map(item => `${item.metric_name}需${item.requiredCount}张，已上传${item.actualCount}张`).join('；');
    const more = gaps.length > 3 ? `等 ${gaps.length} 项` : '';
    return `请先补齐凭证图片：${names}${more}`;
  },

  updateDraftEvidenceTip() {
    const gaps = this.getEvidenceGaps();
    if (!gaps.length) {
      this.setData({ draftEvidenceTip: '' });
      return;
    }
    this.setData({ draftEvidenceTip: `提交前需补齐 ${gaps.length} 项凭证图片` });
  },

  getEvidenceGaps() {
    const values = this.data.values || {};
    const evidenceMap = this.data.evidenceMap || {};
    return (this.data.rawItems || []).filter(item => {
      if (Number(item.need_evidence || 0) !== 1) return false;
      const metricCode = item.metric_code;
      const value = Number(values[metricCode] || 0);
      if (value <= 0) return false;
      const minCount = Math.max(1, Number(item.min_evidence_count || 1));
      const actualCount = (evidenceMap[metricCode] || []).length;
      return actualCount < minCount;
    }).map(item => ({
      metric_code: item.metric_code,
      metric_name: item.metric_name || item.metric_code,
      requiredCount: Math.max(1, Number(item.min_evidence_count || 1)),
      actualCount: (evidenceMap[item.metric_code] || []).length,
    }));
  },

  findMetricItem(metricCode) {
    return this.data.rawItems.find(item => item.metric_code === metricCode) || null;
  },

  refreshItems() {
    const values = this.data.values || {};
    const evidenceMap = this.data.evidenceMap || {};
    const currentReportId = Number(this.data.currentReportId || 0);
    const uploadingMetricCode = this.data.uploadingMetricCode || '';
    const evidenceTip = currentReportId ? '保存后可继续补传，审核侧会读取这里的图片' : '请先保存草稿，再上传对应图片';
    const items = (this.data.rawItems || []).map(item => {
      const metricCode = item.metric_code;
      const needEvidence = Number(item.need_evidence || 0) === 1;
      const evidenceList = (evidenceMap[metricCode] || []).map(file => ({
        ...file,
        displayName: file.file_name || '已上传凭证'
      }));
      const minEvidenceCount = Number(item.min_evidence_count || 1);
      const maxEvidenceCount = Number(item.max_evidence_count || 10);
      return {
        ...item,
        needEvidence,
        requiredMark: item.required ? ' *' : '',
        value: Number(values[metricCode] || 0),
        minEvidenceCount,
        maxEvidenceCount,
        evidenceCount: evidenceList.length,
        hasEvidence: evidenceList.length > 0,
        evidenceList,
        evidenceTip,
        isUploading: uploadingMetricCode === metricCode,
      };
    });
    this.setData({
      items,
      hasItems: items.length > 0,
    });
  },
});
