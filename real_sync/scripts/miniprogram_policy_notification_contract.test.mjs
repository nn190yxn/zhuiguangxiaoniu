import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url).pathname;

test('制度详情返回通知确认标识并保留制度关联', () => {
  const detailApi = readFileSync(join(projectRoot, 'api/policy/detail.php'), 'utf8');
  const policyPage = readFileSync(join(projectRoot, 'mini-program/pages/policy/detail.js'), 'utf8');

  assert.ok(detailApi.includes('policy_notifications WHERE policy_id = ? AND user_id = ?'));
  assert.ok(detailApi.includes("$readStatus['notification_id'] = 'policy:'"));
  assert.ok(detailApi.includes("'read_status' => $readStatus"));
  assert.ok(policyPage.includes('notificationId: readStatus.notification_id'));
  assert.ok(policyPage.includes('id: this.data.notificationId'));
  assert.ok(policyPage.includes('policy_id: this.data.policyId'));
  assert.doesNotMatch(policyPage, /\n\s*id:\s*this\.data\.policyId/);
});

test('通知确认服务校验通知归属和制度关系', () => {
  const notifyApi = readFileSync(join(projectRoot, 'api/policy/notify.php'), 'utf8');
  const service = readFileSync(join(projectRoot, 'api/policy/PolicyNotificationService.php'), 'utf8');

  assert.ok(notifyApi.includes("$service->confirm($userId"));
  assert.ok(notifyApi.includes("$input['policy_id']"));
  assert.match(service, /SELECT policy_id FROM policy_notifications WHERE id = \? AND user_id = \? FOR UPDATE/);
  assert.ok(service.includes('$expectedPolicyId > 0 && $expectedPolicyId !== $policyId'));
  assert.ok(service.includes('policy_notification_mismatch'));
  assert.ok(service.includes('INSERT IGNORE INTO policy_read_history'));
});

test('通关阶段参数和协议状态使用迁移后的统一契约', () => {
  const passMap = readFileSync(join(projectRoot, 'mini-program/pages/pass/map.js'), 'utf8');
  const passStage = readFileSync(join(projectRoot, 'mini-program/pages/pass/stage.js'), 'utf8');
  const appSource = readFileSync(join(projectRoot, 'mini-program/app.js'), 'utf8');
  const loginSource = readFileSync(join(projectRoot, 'mini-program/pages/login/login.js'), 'utf8');

  assert.ok(passMap.includes('stage_id='));
  assert.ok(passStage.includes('options.stage_id'));
  assert.ok(passStage.includes('/pass/stage.php?stage_id='));
  assert.equal(passMap.includes('stage='), false);
  assert.ok(appSource.includes('AGREEMENT_STORAGE_KEY'));
  assert.ok(appSource.includes('agreement_accepted_v'));
  assert.ok(appSource.includes('privacy_agreed'));
  assert.ok(appSource.includes('agreement_accepted'));
  assert.ok(loginSource.includes('app.checkAgreementStatus()'));
  assert.ok(loginSource.includes('app.setAgreementAccepted(agreed)'));
  assert.equal(loginSource.includes("wx.setStorageSync('privacy_agreed'"), false);
});

test('知识示范音频播放器维护完整状态并在卸载时清理', () => {
  const detailSource = readFileSync(join(projectRoot, 'mini-program/pages/knowledge/detail.js'), 'utf8');
  const detailView = readFileSync(join(projectRoot, 'mini-program/pages/knowledge/detail.wxml'), 'utf8');

  assert.ok(detailSource.includes('wx.createInnerAudioContext()'));
  assert.ok(detailSource.includes("audioStatus: 'idle'"));
  assert.ok(detailSource.includes("audioStatus: 'loading'"));
  assert.ok(detailSource.includes("audioStatus: 'playing'"));
  assert.ok(detailSource.includes("audioStatus: 'paused'"));
  assert.ok(detailSource.includes("audioStatus: 'ended'"));
  assert.ok(detailSource.includes("audioStatus: 'error'"));
  assert.ok(detailSource.includes('onUnload()'));
  assert.ok(detailSource.includes('destroyAudioPlayer()'));
  assert.ok(detailView.includes('暂停示范'));
  assert.ok(detailView.includes('继续播放'));
  assert.ok(detailView.includes('script-audio-error'));
});

test('考试分配使用幂等 POST 并稳定保存首次分配结果', () => {
  const examPage = readFileSync(join(projectRoot, 'mini-program/pages/exam/exam.js'), 'utf8');
  const examApi = readFileSync(join(projectRoot, 'api/exam/index.php'), 'utf8');
  const migration = readFileSync(join(projectRoot, 'database/migrations/202608210002_exam_assignment_idempotency.sql'), 'utf8');

  assert.ok(examPage.includes("url: '/exam/index.php?action=assign'"));
  assert.ok(examPage.includes("method: 'POST'"));
  assert.ok(examPage.includes('idempotencyKey: this.examAssignmentIdempotencyKey(sourceExamId)'));
  assert.ok(examPage.includes('api.createIdempotencyKey(`exam_assign_${sourceExamId}`)'));
  assert.ok(examPage.includes('clearExamAssignmentIdempotencyKey'));
  assert.ok(examPage.includes('source_exam_id: sourceExamId'));
  assert.ok(examApi.includes("$_SERVER['REQUEST_METHOD'] !== 'POST'"));
  assert.ok(examApi.includes('getExamAssignmentIdempotencyKey'));
  assert.ok(examApi.includes('findExamAssignmentIdempotency'));
  assert.ok(examApi.includes('saveExamAssignmentIdempotency'));
  assert.ok(examApi.includes('random_int(0, count($candidates) - 1)'));
  assert.ok(migration.includes('UNIQUE KEY uniq_exam_assignment_idempotency'));
});

test('普通业务写请求通过统一请求层传递幂等键', () => {
  const app = readFileSync(join(projectRoot, 'mini-program/app.js'), 'utf8');
  const examPage = readFileSync(join(projectRoot, 'mini-program/pages/exam/exam.js'), 'utf8');
  const knowledgePage = readFileSync(join(projectRoot, 'mini-program/pages/knowledge/detail.js'), 'utf8');
  const minePage = readFileSync(join(projectRoot, 'mini-program/pages/mine/mine.js'), 'utf8');
  const workloadPage = readFileSync(join(projectRoot, 'mini-program/pages/workload/index.js'), 'utf8');

  assert.ok(app.includes("idempotencyKey: api.createIdempotencyKey('device_report')"));
  assert.ok(app.includes('subscription_${sceneCode}_${item.key}_${acceptStatus}'));
  assert.ok(examPage.includes('exam_save_${this.data.sourceExamId}'));
  assert.ok(examPage.includes('exam_submit_${this.data.sourceExamId}'));
  assert.ok(knowledgePage.includes('knowledge_complete_${this.data.id}'));
  assert.ok(minePage.includes("api.createIdempotencyKey('password_change')"));
  assert.ok(minePage.includes('profile_correction_${field.value}'));
  assert.ok(workloadPage.includes('workload_evidence_delete_${evidenceId}'));
  assert.ok(workloadPage.includes('workload_evidence_upload_${reportId}_${metricCode}'));
  assert.ok(workloadPage.includes('workload_${submitStatus}_${this.data.reportDate}'));
});

test('普通业务媒体字段标准化为稳定媒体描述', () => {
  const mediaUtil = readFileSync(join(projectRoot, 'mini-program/utils/media.js'), 'utf8');
  const learningList = readFileSync(join(projectRoot, 'mini-program/pages/learning/list.js'), 'utf8');
  const learningDetail = readFileSync(join(projectRoot, 'mini-program/pages/learning/detail.js'), 'utf8');
  const learningLesson = readFileSync(join(projectRoot, 'mini-program/pages/learning/lesson.js'), 'utf8');
  const knowledgePage = readFileSync(join(projectRoot, 'mini-program/pages/knowledge/detail.js'), 'utf8');
  const workloadPage = readFileSync(join(projectRoot, 'mini-program/pages/workload/index.js'), 'utf8');
  const workloadStaffDetail = readFileSync(join(projectRoot, 'mini-program/pages/workload/staff-detail.js'), 'utf8');

  assert.ok(mediaUtil.includes('normalizeMediaDescriptor'));
  assert.ok(mediaUtil.includes("source: url.startsWith('cloud://') ? 'cloud_file' : 'legacy_url'"));
  assert.ok(mediaUtil.includes('asset_key'));
  assert.ok(learningList.includes("media.normalizeMediaFields(course, ['cover_image', 'cover_url'])"));
  assert.ok(learningDetail.includes("media.normalizeMediaFields(lesson, ['media_url'])"));
  assert.ok(learningLesson.includes("media.normalizeMediaFields(res.data.lesson || {}, ['media_url'])"));
  assert.ok(knowledgePage.includes("['media_url', 'cover_url', 'demo_audio_url', 'audio_url']"));
  assert.ok(workloadPage.includes("media.normalizeMediaFields(item, ['file_url', 'image_file'])"));
  assert.ok(workloadStaffDetail.includes("media.normalizeMediaFields(item, ['file_url', 'image_file'])"));
});
