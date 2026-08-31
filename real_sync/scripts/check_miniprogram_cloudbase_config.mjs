import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REQUIRED_FUNCTIONS = ['api-proxy', 'auth-proxy', 'media-ticket'];
const REQUIRED_ENV_PLACEHOLDER = '__CLOUD_ENV_ID__';
const CLOUD_ENV_ID_PATTERN = /^[a-z0-9][a-z0-9-]{5,63}$/;
const REQUIRED_UPSTREAM_ORIGIN = 'https://supercalf.com/api';
const REQUIRED_SIGNATURE_VERSION = 'v1';
const MIN_BASE_LIBRARY = '2.10.0';

function compareVersion(left, right) {
  const a = String(left || '').split('.').map((value) => Number(value) || 0);
  const b = String(right || '').split('.').map((value) => Number(value) || 0);
  for (let index = 0; index < Math.max(a.length, b.length); index += 1) {
    if ((a[index] || 0) > (b[index] || 0)) return 1;
    if ((a[index] || 0) < (b[index] || 0)) return -1;
  }
  return 0;
}

function issue(file, code, message) {
  return { file, code, message };
}

function readRequired(root, relativePath, issues) {
  const path = join(root, relativePath);
  if (!existsSync(path)) {
    issues.push(issue(relativePath, 'FILE_MISSING', `${relativePath} 不存在`));
    return '';
  }
  return readFileSync(path, 'utf8');
}

function readJson(root, relativePath, issues) {
  const source = readRequired(root, relativePath, issues);
  if (!source) return null;
  try {
    return JSON.parse(source);
  } catch (error) {
    issues.push(issue(relativePath, 'JSON_INVALID', `${relativePath} 不是合法 JSON: ${error.message}`));
    return null;
  }
}

function assertSourceIncludes(issues, file, source, expected, code, message) {
  if (!source.includes(expected)) issues.push(issue(file, code, message));
}

function assertNoConcreteSecret(issues, file, source) {
  const secretPattern = /(?:secret|token|api[_-]?key|hmac|signature)[A-Za-z0-9_\-]*\s*[:=]\s*['"][A-Za-z0-9_\-.]{24,}['"]/gi;
  const allowedPlaceholders = ['__CLOUD_ENV_ID__', 'your-api-key-here', 'your-secret-here'];
  for (const match of source.matchAll(secretPattern)) {
    const raw = match[0];
    if (allowedPlaceholders.some((placeholder) => raw.includes(placeholder))) continue;
    issues.push(issue(file, 'CONCRETE_SECRET', `${file} 存在疑似真实密钥配置`));
  }
}

function isValidCloudEnvId(value) {
  return value === REQUIRED_ENV_PLACEHOLDER || CLOUD_ENV_ID_PATTERN.test(String(value || ''));
}

export function checkMiniProgramCloudbaseConfig(projectRoot) {
  const root = resolve(projectRoot);
  const issues = [];
  const projectConfig = readJson(root, 'mini-program/project.config.json', issues);
  const matrix = readJson(root, 'mini-program/business-domain-matrix.json', issues);
  const cloudSource = readRequired(root, 'mini-program/config/cloud.js', issues);
  const appSource = readRequired(root, 'mini-program/app.js', issues);

  if (projectConfig) {
    if (projectConfig.miniprogramRoot !== './') {
      issues.push(issue('mini-program/project.config.json', 'MINIPROGRAM_ROOT_INVALID', 'project.config.json 必须将 miniprogramRoot 指向当前目录'));
    }
    if (projectConfig.cloudfunctionRoot !== '../cloudfunctions/') {
      issues.push(issue('mini-program/project.config.json', 'CLOUD_FUNCTION_ROOT_MISSING', 'project.config.json 必须将 cloudfunctionRoot 指向 ../cloudfunctions/'));
    }
    if (compareVersion(projectConfig.libVersion, MIN_BASE_LIBRARY) < 0) {
      issues.push(issue('mini-program/project.config.json', 'BASE_LIBRARY_TOO_LOW', `基础库版本必须不低于 ${MIN_BASE_LIBRARY}`));
    }
  }

  if (matrix && matrix.migration) {
    if (!isValidCloudEnvId(matrix.migration.environment?.cloud_env_id)) {
      issues.push(issue('mini-program/business-domain-matrix.json', 'ENV_PLACEHOLDER_MISSING', '迁移清单缺少合法云环境 ID'));
    }
    if (matrix.migration.environment?.upstream_origin !== REQUIRED_UPSTREAM_ORIGIN) {
      issues.push(issue('mini-program/business-domain-matrix.json', 'UPSTREAM_ORIGIN_DRIFT', '迁移清单上游 origin 必须固定为现有 PHP API origin'));
    }
    if (matrix.migration.environment?.gateway_signature_version !== REQUIRED_SIGNATURE_VERSION) {
      issues.push(issue('mini-program/business-domain-matrix.json', 'SIGNATURE_VERSION_MISSING', '迁移清单缺少网关签名版本'));
    }
  }

  if (cloudSource) {
    const envMatch = cloudSource.match(/ENV_ID:\s*['"]([^'"]+)['"]/);
    if (!envMatch || !isValidCloudEnvId(envMatch[1])) {
      issues.push(issue('mini-program/config/cloud.js', 'ENV_PLACEHOLDER_MISSING', '云配置缺少合法云环境 ID'));
    }
    assertSourceIncludes(issues, 'mini-program/config/cloud.js', cloudSource, REQUIRED_UPSTREAM_ORIGIN, 'UPSTREAM_ORIGIN_MISSING', '云配置必须登记现有 PHP API origin');
    assertSourceIncludes(issues, 'mini-program/config/cloud.js', cloudSource, REQUIRED_SIGNATURE_VERSION, 'SIGNATURE_VERSION_MISSING', '云配置必须登记签名版本');
    assertSourceIncludes(issues, 'mini-program/config/cloud.js', cloudSource, "TRANSPORT: 'cloud'", 'TRANSPORT_MISSING', '云配置必须声明 cloud transport');
    assertSourceIncludes(issues, 'mini-program/config/cloud.js', cloudSource, 'SHADOW_SAMPLE_RATE: 0', 'SHADOW_SAMPLE_RATE_MISSING', '云配置必须声明影子抽样率');
    assertSourceIncludes(issues, 'mini-program/config/cloud.js', cloudSource, "RULE_MODE: 'cloud-function-only'", 'STORAGE_RULE_MISSING', '云存储规则必须限定云函数受控访问');
    for (const name of REQUIRED_FUNCTIONS) {
      assertSourceIncludes(issues, 'mini-program/config/cloud.js', cloudSource, name, 'CLOUD_FUNCTION_MISSING', `云配置缺少函数名 ${name}`);
    }
    assertNoConcreteSecret(issues, 'mini-program/config/cloud.js', cloudSource);
  }

  if (appSource) {
    assertSourceIncludes(issues, 'mini-program/app.js', appSource, './config/cloud', 'APP_CLOUD_CONFIG_MISSING', 'app.js 必须读取云开发配置');
    assertSourceIncludes(issues, 'mini-program/app.js', appSource, 'wx.cloud.init', 'APP_CLOUD_INIT_MISSING', 'app.js 必须初始化 wx.cloud');
    assertSourceIncludes(issues, 'mini-program/app.js', appSource, 'cloudConfig.ENV_ID', 'APP_CLOUD_ENV_MISSING', 'app.js 必须使用配置中的云环境 ID');
  }

  return {
    root,
    status: issues.length === 0 ? 'passed' : 'failed',
    issues,
  };
}

export function formatMiniProgramCloudbaseConfigReport(report) {
  if (report.issues.length === 0) return '小程序云开发配置检查通过。';
  return [
    `小程序云开发配置检查失败：${report.issues.length} 个阻断项。`,
    ...report.issues.map((item) => `${item.file} [${item.code}] ${item.message}`),
  ].join('\n');
}

const currentFile = fileURLToPath(import.meta.url);
if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const defaultRoot = resolve(dirname(currentFile), '..');
  const report = checkMiniProgramCloudbaseConfig(process.argv[2] || defaultRoot);
  console.log(formatMiniProgramCloudbaseConfigReport(report));
  if (report.issues.length > 0) process.exitCode = 1;
}
