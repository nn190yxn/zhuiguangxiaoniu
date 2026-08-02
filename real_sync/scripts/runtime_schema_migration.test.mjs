import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

const migrations = {
  identity: read('../database/migrations/202607310005_admin_identity_audit.sql'),
  wecom: read('../database/migrations/202607310006_wecom_delivery.sql'),
  reminder: read('../database/migrations/202607310007_reminder_delivery.sql'),
  skill: read('../database/migrations/202607310008_skill_review.sql'),
  topics: read('../database/migrations/202607310009_campaign_schema.sql'),
};

const runtimeSources = {
  admin: read('../api/admin/common.php'),
  auth: read('../api/auth-jwt.php'),
  wecom: read('../api/wecom/_common.php'),
  reminder: read('../api/reminder/_common.php'),
  config: read('../api/config.php'),
  skillUpload: read('../api/skill/upload-recording.php'),
  skillList: read('../api/skill/review-list.php'),
  skillWorker: read('../api/skill/skill-worker.php'),
  campaignCreate: read('../api/campaign/create-tables.php'),
  campaignSave: read('../api/campaign/save.php'),
  campaignList: read('../api/campaign/list.php'),
  campaignSummary: read('../api/campaign/summary.php'),
  summerCamp: read('../api/summer-camp/_common.php'),
};

test('versioned runtime migrations cover identity, WeCom, reminders, skills, and topics', () => {
  const expectedTables = {
    identity: ['login_audit_logs', 'device_logins'],
    wecom: ['wecom_sync_logs', 'wecom_message_logs'],
    reminder: ['mini_reminder_rules', 'mini_reminder_jobs', 'mini_user_subscriptions', 'mini_user_notifications'],
    skill: ['ai_settings', 'skill_review_records'],
    topics: ['campaign_daily_entries', 'campaign_channel_entries', 'summer_camp_assessments', 'summer_camp_test_data', 'summer_camp_reports'],
  };

  for (const [domain, tables] of Object.entries(expectedTables)) {
    for (const table of tables) {
      assert.match(migrations[domain], new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
    }
  }
  assert.match(migrations.identity, /ALTER TABLE staffs ADD COLUMN wecom_userid/);
  assert.match(migrations.identity, /ALTER TABLE login_audit_logs ADD COLUMN device_fingerprint/);
  assert.match(migrations.identity, /ALTER TABLE device_logins ADD COLUMN device_fingerprint/);
  assert.match(migrations.topics, /ALTER TABLE campaign_daily_entries ADD COLUMN data_json/);
  assert.match(migrations.topics, /ALTER TABLE summer_camp_test_data ADD COLUMN metric_text/);
});

test('runtime migrations remain additive and preserve historical partial schemas', () => {
  for (const sql of Object.values(migrations)) {
    assert.doesNotMatch(sql, /\bDROP\s+(?:TABLE|COLUMN|INDEX)\b/i);
    assert.doesNotMatch(sql, /\bRENAME\s+(?:TABLE|COLUMN)\b/i);
    assert.doesNotMatch(sql, /\bTRUNCATE\s+TABLE\b/i);
  }
  assert.match(migrations.identity, /information_schema\.COLUMNS/);
  assert.match(migrations.identity, /PREPARE migration_statement/);
  assert.match(migrations.topics, /information_schema\.COLUMNS/);
  assert.match(migrations.reminder, /ON DUPLICATE KEY UPDATE/);
});

test('non-frozen runtime paths use read-only migration readiness without DDL', () => {
  for (const [name, source] of Object.entries(runtimeSources)) {
    assert.doesNotMatch(source, /\b(?:CREATE|ALTER)\s+TABLE\b/i, `${name} still contains request-time DDL`);
  }

  assert.match(runtimeSources.admin, /platformRequireMigrationReadiness\(\$db, \['202607310005'\]\)/);
  assert.match(runtimeSources.auth, /platformRequireMigrationReadiness\(\$db, \['202607310005'\]\)/);
  assert.match(runtimeSources.wecom, /\['202607310005', '202607310006'\]/);
  assert.match(runtimeSources.reminder, /'202607310007'/);
  assert.match(runtimeSources.config, /\['202607310008'\]/);
  assert.match(runtimeSources.skillUpload, /\['202607310008', '202607310010', '202607310012'\]/);
  assert.match(runtimeSources.skillList, /\['202607310008'\]/);
  assert.match(runtimeSources.skillWorker, /\['202607310008', '202607310010', '202607310012'\]/);
  assert.match(runtimeSources.campaignCreate, /\['202607310009'\]/);
  assert.match(runtimeSources.campaignSave, /\['202607310009'\]/);
  assert.match(runtimeSources.campaignList, /\['202607310009'\]/);
  assert.match(runtimeSources.campaignSummary, /\['202607310009'\]/);
  assert.match(runtimeSources.summerCamp, /\['202607310009'\]/);
});

test('legacy campaign schema endpoint is a compatibility readiness probe', () => {
  assert.match(runtimeSources.campaignCreate, /数据库结构已就绪/);
  assert.match(runtimeSources.campaignCreate, /PlatformApiException/);
  assert.doesNotMatch(runtimeSources.campaignCreate, /表创建成功|创建失败/);
});
