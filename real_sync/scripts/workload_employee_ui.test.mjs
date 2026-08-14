import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const h5 = read('../mobile/workload-v2.html');
const miniJs = read('../mini-program/pages/workload/index.js');
const miniWxml = read('../mini-program/pages/workload/index.wxml');
const manageJs = read('../mini-program/pages/workload/manage.js');
const manageWxml = read('../mini-program/pages/workload/manage.wxml');
const staffDetailJs = read('../mini-program/pages/workload/staff-detail.js');
const staffDetailWxml = read('../mini-program/pages/workload/staff-detail.wxml');
const dailyTrackingApi = read('../api/workload/daily-tracking.php');
const staffDetailApi = read('../api/workload/staff-detail.php');
const miniApi = read('../mini-program/utils/api.js');
const h5Mine = read('../mobile/mine.html');
const miniMineJs = read('../mini-program/pages/mine/mine.js');
const miniMineWxml = read('../mini-program/pages/mine/mine.wxml');

test('H5 and mini program expose the same employee report state', () => {
  for (const marker of ['completion_status', 'is_writable', 'is_weekly_rest_day', 'deadline_at']) {
    assert.match(h5, new RegExp(marker));
    assert.match(miniJs, new RegExp(marker.replaceAll('_', '.*'), 'i'));
  }
  for (const label of ['姓名', '门店', '岗位', '保存草稿', '提交日报']) {
    assert.match(h5, new RegExp(label));
    assert.match(miniWxml, new RegExp(label));
  }
  for (const label of ['每日目标', '有效', '还差', '昨日可补', '本月处罚']) {
    assert.match(h5, new RegExp(label));
    assert.match(miniWxml, new RegExp(label));
  }
});

test('mini program management and employee detail expose the daily closure state', () => {
  assert.match(manageJs, /\/workload\/daily-tracking\.php\?date_from=/);
  assert.match(manageJs, /target_points[\s\S]*effective_points[\s\S]*gap_points[\s\S]*makeup_deadline_at/);
  for (const label of ['今日待完成', '昨日待补', '已逾期', '待审核', '待确认处罚', '补齐截止', '处罚']) {
    assert.match(manageWxml, new RegExp(label));
  }
  assert.match(staffDetailJs, /daily_settlement/);
  assert.match(staffDetailWxml, /每日结算/);
  assert.match(staffDetailWxml, /目标 \{\{dailySettlement\.target_points\}\} 点 · 有效 \{\{dailySettlement\.effective_points\}\} 点 · 差额 \{\{dailySettlement\.gap_points\}\} 点/);
  assert.match(staffDetailWxml, /补齐截止 \{\{dailySettlement\.makeup_deadline_at\}\}/);
  assert.match(staffDetailWxml, /处罚 \{\{dailySettlement\.penalty_text\}\}/);
  assert.match(dailyTrackingApi, /WorkloadDailyTrackingService/);
  assert.match(dailyTrackingApi, /appCanAccessWorkload/);
  assert.match(staffDetailApi, /'daily_settlement' => \$dailySettlement/);
});

test('both clients validate metric boundaries and navigate to the first invalid metric', () => {
  for (const rule of ['min_value', 'max_value', 'allow_zero']) {
    assert.match(h5, new RegExp(rule, 'i'));
    assert.match(miniJs, new RegExp(rule, 'i'));
  }
  assert.doesNotMatch(h5, /minimumPositiveCount/);
  assert.doesNotMatch(miniJs, /minimumPositiveCount/);
  assert.match(h5, /fieldErrors[\s\S]*scrollIntoView[\s\S]*\.focus\(\)/);
  assert.match(miniJs, /fieldErrors[\s\S]*wx\.pageScrollTo/);
  assert.match(h5, /getEvidenceGaps\(\)/);
  assert.match(miniJs, /getEvidenceGaps\(\)/);
});

test('draft recovery, upload feedback, and operation locks exist on both clients', () => {
  assert.match(h5, /beforeunload/);
  assert.match(h5, /src="\/js\/draft-store\.js"/);
  assert.match(h5, /DraftStore\.setIdentity/);
  assert.match(h5, /createDraftStore[\s\S]*saveLocal/);
  assert.match(h5, /getLocal/);
  assert.match(h5, /clearLocal/);
  assert.doesNotMatch(h5, /workload_h5_recovery_/);
  assert.match(h5, /beginOperation[\s\S]*operationState/);
  assert.match(h5, /xhr\.upload\.onprogress/);
  assert.match(h5, /retryEvidence/);
  assert.match(miniJs, /onHide[\s\S]*persistRecovery/);
  assert.match(miniJs, /wx\.setStorageSync\(this\.recoveryKey/);
  assert.match(miniJs, /beginOperation[\s\S]*busyAction/);
  assert.match(miniJs, /retryEvidence/);
  assert.match(miniApi, /uploadTask\.onProgressUpdate/);
  assert.match(miniApi, /module\.exports[\s\S]*uploadFile/);
});

test('H5 reconciles remote drafts after network recovery with explicit conflict choice', () => {
  assert.match(h5, /getRemote\(\)/);
  assert.match(h5, /saveRemote\(/);
  assert.match(h5, /pwa:network-restored/);
  assert.match(h5, /pwa:session-restored/);
  assert.match(h5, /draft_version_conflict/);
  assert.match(h5, /chooseDraftVersion/);
  assert.match(h5, /window\.confirm/);
});

test('scope requests ignore stale template, report, and evidence responses', () => {
  assert.match(h5, /scopeRequestVersion/);
  assert.match(h5, /version!==scopeRequestVersion\|\|requestedScope!==scopeKey\(\)/);
  assert.match(h5, /var loadedEvidence=await loadEvidence\(currentReportId\)[\s\S]*evidenceMap=loadedEvidence/);
  assert.match(miniJs, /scopeRequestVersion/);
  assert.match(miniJs, /version !== this\.scopeRequestVersion \|\| requestedScope !==/);
});

test('H5 upload renders a numeric progress state before XHR progress events', () => {
  assert.match(h5, /setUploadState\(metricCode,1\);renderMetrics\(\);updateActionState\(\)/);
  assert.match(h5, /typeof uploadState\[item\.metric_code\]==='number'/);
  assert.match(h5, /xhr\.upload\.onprogress[\s\S]*querySelector\('#metric_'/);
});

test('personal centers expose profile, correction, and password operations', () => {
  for (const endpoint of ['/api/staff/profile.php', '/api/staff/profile-corrections.php', '/api/auth-change-password.php']) {
    assert.match(h5Mine, new RegExp(endpoint.replaceAll('/', '\\/')));
    assert.match(miniMineJs, new RegExp(endpoint.replace('/api', '').replaceAll('/', '\\/')));
  }
  for (const label of ['员工档案', '申请档案更正', '修改密码']) {
    assert.match(h5Mine, new RegExp(label));
    assert.match(miniMineWxml, new RegExp(label));
  }
});

test('H5 correction dialog traps focus, closes on Escape, and restores focus', () => {
  assert.match(h5Mine, /id="correctionModal" role="dialog" aria-modal="true" aria-labelledby="correctionModalTitle" aria-hidden="true"/);
  assert.match(h5Mine, /correctionReturnFocus=document\.activeElement/);
  assert.match(h5Mine, /event\.key==='Escape'[\s\S]*closeCorrectionModal\(\)/);
  assert.match(h5Mine, /event\.shiftKey[\s\S]*last\.focus\(\)[\s\S]*first\.focus\(\)/);
  assert.match(h5Mine, /correctionReturnFocus&&correctionReturnFocus\.focus[\s\S]*correctionReturnFocus\.focus\(\)/);
});
