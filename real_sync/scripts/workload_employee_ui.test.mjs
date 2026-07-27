import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const h5 = read('../mobile/workload-v2.html');
const miniJs = read('../mini-program/pages/workload/index.js');
const miniWxml = read('../mini-program/pages/workload/index.wxml');
const miniApi = read('../mini-program/utils/api.js');
const h5Mine = read('../mobile/mine.html');
const miniMineJs = read('../mini-program/pages/mine/mine.js');
const miniMineWxml = read('../mini-program/pages/mine/mine.wxml');

test('H5 and mini program expose the same employee report state', () => {
  for (const marker of ['completion_status', 'is_writable', 'is_weekly_rest_day', 'deadline_at']) {
    assert.match(h5, new RegExp(marker));
    assert.match(miniJs, new RegExp(marker.replaceAll('_', '.*'), 'i'));
  }
  for (const label of ['姓名', '门店', '岗位', '已完成', '待补凭证', '保存草稿', '提交日报']) {
    assert.match(h5, new RegExp(label));
    assert.match(miniWxml, new RegExp(label));
  }
});

test('both clients validate inline and navigate to the first invalid metric', () => {
  for (const rule of ['min_value', 'max_value', 'allow_zero', 'minimumPositive']) {
    assert.match(h5, new RegExp(rule, 'i'));
    assert.match(miniJs, new RegExp(rule, 'i'));
  }
  assert.match(h5, /fieldErrors[\s\S]*scrollIntoView[\s\S]*\.focus\(\)/);
  assert.match(miniJs, /fieldErrors[\s\S]*wx\.pageScrollTo/);
  assert.match(h5, /getEvidenceGaps\(\)/);
  assert.match(miniJs, /getEvidenceGaps\(\)/);
});

test('draft recovery, upload feedback, and operation locks exist on both clients', () => {
  assert.match(h5, /beforeunload/);
  assert.match(h5, /persistRecovery[\s\S]*localStorage\.setItem/);
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
