(() => {
  const roleMap = Object.freeze({
    admin: '管理员', ceo: '总经理', operation: '总部运营', ops: '运营', finance: '财务',
    manager: '店长', coach: '教练', sales: '顾问', consultant: '顾问',
    teaching_supervisor: '教学主管', supervisor: '督导', newbie: '新员工',
  });
  const statusMap = Object.freeze({
    pending: '待处理', pending_review: '待审核', approval_pending: '待审批',
    approved: '已通过', rejected: '已驳回', needs_resubmit: '待补凭证',
    draft: '草稿', submitted: '已提交', completed: '已完成', processing: '处理中',
    failed: '处理失败', cancelled: '已取消', queued: '排队中', missing: '缺交',
    locked_missing: '锁定缺交', corrected: '管理更正', not_required: '无需审核',
    uploaded: '已上传', returned: '已退回', closed: '已关闭', open: '待处理',
    resolved: '已处理', published: '已发布', retired: '已停用',
  });
  const errorMap = Object.freeze({
    unauthorized: '登录状态已失效，请重新登录', forbidden: '当前账号没有此项操作权限',
    validation_error: '提交内容需要检查后重试', rate_limited: '登录尝试过于频繁，请稍后再试',
    network_error: '网络连接异常，请检查网络后重试', server_error: '系统服务暂时繁忙，请稍后重试',
  });
  function label(map, value, fallback) {
    const code = String(value == null ? '' : value).toLowerCase();
    return map[code] || fallback || code || '-';
  }
  window.PlatformLabels = Object.freeze({
    roleMap, statusMap, errorMap,
    role(value, fallback) { return label(roleMap, value, fallback || '员工'); },
    status(value, fallback) { return label(statusMap, value, fallback); },
    error(value, fallback) { return label(errorMap, value, fallback || '操作未完成，请稍后重试'); },
  });
})();
