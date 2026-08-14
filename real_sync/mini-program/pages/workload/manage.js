const app = getApp();

function today() {
  return new Date(Date.now() + 8 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

function daysAgo(days) {
  return new Date(Date.now() + 8 * 60 * 60 * 1000 - days * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
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

function reportHasEvidenceGap(report) {
  const values = report.values || [];
  const evidences = report.evidences || [];
  const evidenceCountByMetric = evidences.reduce((acc, item) => {
    const code = item.metric_code || '';
    acc[code] = Number(acc[code] || 0) + 1;
    return acc;
  }, {});
  return values.some((item) => {
    if (Number(item.need_evidence || 0) !== 1) return false;
    if (Number(item.numeric_value || 0) <= 0) return false;
    const requiredCount = Math.max(1, Number(item.min_evidence_count || 1));
    return Number(evidenceCountByMetric[item.metric_code] || 0) < requiredCount;
  });
}

Page({
  data: {
    dateFrom: daysAgo(6),
    dateTo: today(),
    maxDate: today(),
    role: '',
    roleTabs: [
      { label: '全部', value: '' },
      { label: '销售', value: 'sales' },
      { label: '教练', value: 'coach' },
    ],
    loading: false,
    staffRows: [],
    summary: {
      expected: 0,
      submitted: 0,
      draft: 0,
      missing: 0,
      evidence_missing: 0,
    },
    dailySummary: {
      today_open_count: 0,
      makeup_open_count: 0,
      overdue_count: 0,
      pending_review_count: 0,
      pending_penalty_count: 0,
    },
    statusText: '',
  },

  onLoad() {
    this.loadData();
  },

  onPullDownRefresh() {
    this.loadData().finally(() => wx.stopPullDownRefresh());
  },

  onDateFromChange(e) {
    this.setData({ dateFrom: e.detail.value });
    this.loadData();
  },

  onDateToChange(e) {
    this.setData({ dateTo: e.detail.value });
    this.loadData();
  },

  selectRole(e) {
    this.setData({ role: e.currentTarget.dataset.role || '' });
    this.loadData();
  },

  async loadData() {
    if (!app.isLoggedIn()) {
      wx.navigateTo({ url: '/pages/login/login' });
      return;
    }
    this.setData({ loading: true, statusText: '正在加载员工工作量...' });
    try {
      let url = `/workload/staff-activity.php?date_from=${encodeURIComponent(this.data.dateFrom)}&date_to=${encodeURIComponent(this.data.dateTo)}`;
      if (this.data.role) url += `&role=${encodeURIComponent(this.data.role)}`;
      let trackingUrl = `/workload/daily-tracking.php?date_from=${encodeURIComponent(this.data.dateTo)}&date_to=${encodeURIComponent(this.data.dateTo)}`;
      if (this.data.role) trackingUrl += `&role_code=${encodeURIComponent(this.data.role)}`;
      const [res, trackingRes] = await Promise.all([
        app.request({ url }),
        app.request({ url: trackingUrl }),
      ]);
      const settlementsByStaff = (trackingRes.data.items || []).reduce((result, settlement) => {
        const key = `${settlement.staff_id}:${settlement.role_code}`;
        result[key] = settlement;
        return result;
      }, {});
      const staffRows = (res.data.staff_rows || []).map(row => this.normalizeStaff(row, settlementsByStaff));
      this.setData({
        staffRows,
        summary: this.buildSummary(staffRows),
        dailySummary: trackingRes.data.summary || this.data.dailySummary,
        loading: false,
        statusText: staffRows.length ? '' : '当前范围暂无员工工作量数据',
      });
    } catch (err) {
      this.setData({ loading: false, statusText: err.message || '加载失败' });
    }
  },

  normalizeStaff(row, settlementsByStaff) {
    const reports = row.reports || [];
    const latest = reports[0] || { submit_status: 'missing', report_date: this.data.dateTo, evidence_count: 0 };
    const evidenceMissingCount = reports.filter(report => report.submit_status !== 'missing' && reportHasEvidenceGap(report)).length;
    const submitRate = row.expected_count ? Math.round((Number(row.submitted_count || 0) / Number(row.expected_count || 1)) * 100) : 0;
    const settlement = settlementsByStaff[`${row.staff_id}:${row.role_code}`] || null;
    return {
      ...row,
      role_name: roleName(row.role_code),
      latest_status: latest.submit_status || 'missing',
      latest_status_name: statusName(latest.submit_status),
      latest_date: latest.report_date || this.data.dateTo,
      evidence_missing_count: evidenceMissingCount,
      submit_rate: submitRate,
      daily_status: settlement ? settlement.settlement_status : '',
      daily_status_label: settlement ? settlement.settlement_status_label : '暂无每日结算',
      target_points: settlement ? formatPoints(settlement.target_points) : '-',
      effective_points: settlement ? formatPoints(settlement.effective_points) : '-',
      gap_points: settlement ? formatPoints(settlement.gap_points) : '-',
      makeup_deadline_at: settlement ? settlement.makeup_deadline_at || '-' : '-',
      penalty_text: settlement && settlement.penalty_id
        ? `¥${formatPoints(settlement.penalty_amount)} · ${settlement.penalty_status_label}`
        : '暂无处罚',
      next_action: settlement ? settlement.next_action : '等待每日结算生成',
    };
  },

  buildSummary(rows) {
    return rows.reduce((acc, row) => {
      acc.expected += Number(row.expected_count || 0);
      acc.submitted += Number(row.submitted_count || 0);
      acc.draft += Number(row.draft_count || 0);
      acc.missing += Number(row.missing_count || 0);
      acc.evidence_missing += Number(row.evidence_missing_count || 0);
      return acc;
    }, { expected: 0, submitted: 0, draft: 0, missing: 0, evidence_missing: 0 });
  },

  goDetail(e) {
    const staffId = e.currentTarget.dataset.id;
    const date = e.currentTarget.dataset.date || this.data.dateTo;
    wx.navigateTo({ url: `/pages/workload/staff-detail?staff_id=${staffId}&date=${date}` });
  },
});
