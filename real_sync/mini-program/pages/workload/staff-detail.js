const app = getApp();

function today() {
  return new Date(Date.now() + 8 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

function statusName(status) {
  return { submitted: '已提交', draft: '草稿', missing: '未提交' }[status] || '未提交';
}

function roleName(role) {
  return { sales: '销售', coach: '教练' }[role] || '员工';
}

function formatPoints(value) {
  return Number(value || 0).toFixed(2).replace(/\.00$/, '');
}

Page({
  data: {
    staffId: 0,
    date: today(),
    maxDate: today(),
    staff: {},
    submitStatus: 'missing',
    statusText: '加载中...',
    values: [],
    evidences: [],
    evidenceCount: 0,
    recentDays: [],
    remarks: '',
    submittedAt: '',
    dailySettlement: null,
    loading: false,
  },

  onLoad(options) {
    this.setData({
      staffId: Number(options.staff_id || 0),
      date: options.date || today(),
      maxDate: today(),
    });
    this.loadDetail();
  },

  onDateChange(e) {
    this.setData({ date: e.detail.value });
    this.loadDetail();
  },

  async loadDetail() {
    if (!this.data.staffId) {
      this.setData({ statusText: '缺少员工 ID' });
      return;
    }
    this.setData({ loading: true, statusText: '正在加载员工明细...' });
    try {
      const res = await app.request({ url: `/workload/staff-detail.php?staff_id=${this.data.staffId}&date=${encodeURIComponent(this.data.date)}` });
      const staff = res.data.staff || {};
      const settlement = res.data.daily_settlement || null;
      this.setData({
        staff: { ...staff, role_name: roleName(staff.role_code) },
        submitStatus: res.data.submit_status || 'missing',
        statusText: statusName(res.data.submit_status),
        values: res.data.values || [],
        evidences: res.data.evidences || [],
        evidenceCount: Number(res.data.evidence_count || 0),
        recentDays: (res.data.recent_days || []).map(day => ({
          ...day,
          display_date: (day.date || '').slice(5),
          status_text: statusName(day.status),
        })),
        remarks: res.data.remarks || '',
        submittedAt: res.data.submitted_at || '',
        dailySettlement: settlement ? {
          ...settlement,
          target_points: formatPoints(settlement.target_points),
          effective_points: formatPoints(settlement.effective_points),
          gap_points: formatPoints(settlement.gap_points),
          makeup_deadline_at: settlement.makeup_deadline_at || '-',
          penalty_text: settlement.penalty_id
            ? `¥${formatPoints(settlement.penalty_amount)} · ${settlement.penalty_status_label}`
            : '暂无处罚',
        } : null,
        loading: false,
      });
    } catch (err) {
      this.setData({ loading: false, statusText: err.message || '加载失败' });
    }
  },

  previewEvidence(e) {
    const current = e.currentTarget.dataset.url;
    const urls = this.data.evidences.map(item => item.file_url).filter(Boolean);
    if (!current || !urls.length) return;
    wx.previewImage({ current, urls });
  },
});
