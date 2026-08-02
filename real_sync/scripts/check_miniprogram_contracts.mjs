import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { checkMiniProgramRoutes } from './check_miniprogram_routes.mjs';

const CONTRACT_CATEGORIES = [
  'page_registration',
  'navigation',
  'request_layer',
  'device_session',
  'state_sync',
  'upload',
  'capability_version',
];

function javascriptFiles(directory) {
  return readdirSync(directory).flatMap((name) => {
    const path = join(directory, name);
    return statSync(path).isDirectory()
      ? javascriptFiles(path)
      : (path.endsWith('.js') ? [path] : []);
  });
}

function issue(category, code, file, message) {
  return { category, code, file, message };
}

function requirePattern(issues, category, code, file, source, pattern, message) {
  if (!pattern.test(source)) issues.push(issue(category, code, file, message));
}

function tabRoutes(appConfig) {
  const list = appConfig.tabBar && Array.isArray(appConfig.tabBar.list) ? appConfig.tabBar.list : [];
  return list.map((item) => `/${String(item.pagePath || '').replace(/^\//, '')}`).sort();
}

function navigationTabRoutes(source) {
  const block = source.match(/const TAB_ROUTES = new Set\(\[([\s\S]*?)\]\);/);
  if (!block) return [];
  return [...block[1].matchAll(/['"](\/pages\/[A-Za-z0-9/_-]+)['"]/g)]
    .map((match) => match[1])
    .sort();
}

export function checkMiniProgramContracts(projectRoot) {
  const root = resolve(projectRoot);
  const miniProgramRoot = join(root, 'mini-program');
  const issues = [];
  const read = (path) => readFileSync(join(root, path), 'utf8');
  const appConfig = JSON.parse(read('mini-program/app.json'));
  const appSource = read('mini-program/app.js');
  const apiSource = read('mini-program/utils/api.js');
  const authSource = read('mini-program/utils/auth.js');
  const navigationSource = read('mini-program/utils/navigation.js');
  const capabilitiesSource = read('mini-program/utils/capabilities.js');
  const capabilityEndpoint = read('api/platform/capabilities.php');
  const routeReport = checkMiniProgramRoutes(miniProgramRoot);

  for (const routeIssue of routeReport.errors) {
    const category = routeIssue.message.includes('注册页面缺少基础文件') ? 'page_registration' : 'navigation';
    issues.push(issue(category, 'MINI_PROGRAM_ROUTE', routeIssue.file, routeIssue.message));
  }

  const configuredTabs = tabRoutes(appConfig);
  const navigationTabs = navigationTabRoutes(navigationSource);
  if (JSON.stringify(configuredTabs) !== JSON.stringify(navigationTabs)) {
    issues.push(issue('navigation', 'TAB_ROUTE_DRIFT', 'mini-program/utils/navigation.js', '统一导航 Tab 清单与 app.json 不一致'));
  }
  for (const api of ['switchTab', 'navigateTo', 'redirectTo', 'reLaunch']) {
    requirePattern(issues, 'navigation', `NAVIGATION_${api.toUpperCase()}_MISSING`, 'mini-program/utils/navigation.js', navigationSource, new RegExp(`wx\\.${api}\\s*\\(`), `统一导航缺少 wx.${api}`);
  }

  const apiPath = join(miniProgramRoot, 'utils/api.js');
  for (const path of javascriptFiles(miniProgramRoot)) {
    if (path === apiPath) continue;
    const source = readFileSync(path, 'utf8');
    if (/wx\.(?:request|uploadFile)\s*\(/.test(source)) {
      issues.push(issue('request_layer', 'NATIVE_NETWORK_OUTSIDE_API_CLIENT', relative(root, path), '原生网络调用必须通过统一 API 客户端'));
    }
  }
  requirePattern(issues, 'request_layer', 'REQUEST_DELEGATE_MISSING', 'mini-program/app.js', appSource, /request\(options\)\s*\{\s*return api\.request\(options\)/, 'App 请求入口未委托统一 API 客户端');
  requirePattern(issues, 'request_layer', 'REQUEST_ID_MISSING', 'mini-program/utils/api.js', apiSource, /['"]X-Request-ID['"]/, '统一请求层缺少请求 ID');
  requirePattern(issues, 'request_layer', 'IDEMPOTENCY_KEY_MISSING', 'mini-program/utils/api.js', apiSource, /['"]Idempotency-Key['"]/, '统一请求层缺少幂等键');

  for (const key of ['session_refresh_token', 'session_id', 'session_version', 'session_type']) {
    requirePattern(issues, 'device_session', `DEVICE_SESSION_${key.toUpperCase()}_MISSING`, 'mini-program/utils/auth.js', authSource, new RegExp(`['"]${key}['"]`), `设备会话缺少 ${key}`);
  }
  requirePattern(issues, 'device_session', 'DEVICE_SESSION_REFRESH_MISSING', 'mini-program/utils/api.js', apiSource, /mini-program-session\.php\?action=refresh/, '设备会话缺少刷新端点');
  requirePattern(issues, 'device_session', 'DEVICE_SESSION_LOGOUT_MISSING', 'mini-program/utils/api.js', apiSource, /mini-program-session\.php\?action=logout/, '设备会话缺少退出端点');
  requirePattern(issues, 'device_session', 'DEVICE_SESSION_SINGLE_FLIGHT_MISSING', 'mini-program/utils/api.js', apiSource, /if \(refreshPromise\) return refreshPromise/, '设备会话刷新缺少单飞保护');

  requirePattern(issues, 'state_sync', 'STATE_VERSION_MISSING', 'mini-program/utils/api.js', apiSource, /stateVersionField \|\| ['"]state_version['"]/, '统一请求层缺少状态版本传播');
  requirePattern(issues, 'state_sync', 'CONFLICT_STATUS_MISSING', 'mini-program/utils/api.js', apiSource, /status === 409/, '统一请求层缺少冲突状态分类');
  for (const field of ['baseVersion', 'currentVersion', 'authoritativeState', 'recoveryAction']) {
    requirePattern(issues, 'state_sync', `CONFLICT_${field.toUpperCase()}_MISSING`, 'mini-program/utils/api.js', apiSource, new RegExp(`err\\.${field}\\s*=`), `冲突恢复元数据缺少 ${field}`);
  }

  requirePattern(issues, 'upload', 'UPLOAD_DELEGATE_MISSING', 'mini-program/app.js', appSource, /uploadFile\(options\)\s*\{\s*return api\.uploadFile\(options\)/, 'App 上传入口未委托统一 API 客户端');
  requirePattern(issues, 'upload', 'UPLOAD_SHA256_MISSING', 'mini-program/utils/api.js', apiSource, /digestAlgorithm:\s*['"]sha256['"]/, '统一上传层缺少 SHA-256 摘要');
  requirePattern(issues, 'upload', 'UPLOAD_TIMEOUT_MISSING', 'mini-program/utils/api.js', apiSource, /const UPLOAD_TIMEOUT = 60000/, '统一上传层缺少上传超时');
  requirePattern(issues, 'upload', 'UPLOAD_STATE_VERSION_MISSING', 'mini-program/utils/api.js', apiSource, /formData = requestData\(/, '统一上传层缺少状态版本传播');

  requirePattern(issues, 'capability_version', 'CAPABILITY_CLIENT_MISSING', 'mini-program/app.js', appSource, /capabilities\.resolveFeatures\(/, 'App 未解析能力版本');
  requirePattern(issues, 'capability_version', 'CAPABILITY_FALLBACK_MISSING', 'mini-program/utils/capabilities.js', capabilitiesSource, /CONSERVATIVE_FEATURES/, '能力版本缺少保守降级');
  requirePattern(issues, 'capability_version', 'CAPABILITY_MINIMUM_VERSION_MISSING', 'mini-program/utils/capabilities.js', capabilitiesSource, /minimum_client_version/, '能力版本缺少最低客户端版本判断');
  requirePattern(issues, 'capability_version', 'CAPABILITY_ENDPOINT_MISSING', 'api/platform/capabilities.php', capabilityEndpoint, /'mini_program_feature_versions'/, '能力端点缺少小程序功能版本声明');
  requirePattern(issues, 'capability_version', 'CAPABILITY_ALLOWLIST_MISSING', 'api/platform/capabilities.php', capabilityEndpoint, /'fallback_mode'\s*=>\s*'explicit_allowlist'/, '能力端点缺少显式功能白名单降级');

  return {
    root,
    status: issues.length === 0 ? 'passed' : 'failed',
    categories: CONTRACT_CATEGORIES.map((category) => ({
      category,
      issue_count: issues.filter((item) => item.category === category).length,
    })),
    registeredRoutes: routeReport.registeredRoutes,
    checkedReferences: routeReport.checkedReferences,
    issues,
  };
}

export function formatMiniProgramContractReport(report) {
  if (report.issues.length === 0) {
    return `小程序静态契约检查通过：${report.categories.length} 类契约，${report.registeredRoutes.length} 个页面。`;
  }
  return [
    `小程序静态契约检查失败：${report.issues.length} 个阻断项。`,
    ...report.issues.map((item) => `${item.file} [${item.code}] ${item.message}`),
  ].join('\n');
}

const currentFile = fileURLToPath(import.meta.url);
if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const defaultRoot = resolve(dirname(currentFile), '..');
  const report = checkMiniProgramContracts(process.argv[2] || defaultRoot);
  console.log(formatMiniProgramContractReport(report));
  if (report.issues.length > 0) process.exitCode = 1;
}
