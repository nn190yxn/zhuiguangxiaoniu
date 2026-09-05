import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { checkMiniProgramRoutes } from './check_miniprogram_routes.mjs';
import { compareEndpointSets, normalizeEndpoint } from './miniprogram_endpoint_contract.mjs';

const CONTRACT_CATEGORIES = [
  'page_registration',
  'navigation',
  'request_layer',
  'device_session',
  'state_sync',
  'upload',
  'capability_version',
  'endpoint_sync',
];

const CENTRAL_BUSINESS_URL_FILES = new Set([
  'mini-program/app.js',
  'mini-program/config/cloud.js',
  'mini-program/utils/api.js',
]);

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

function requirePatterns(issues, category, file, source, contracts) {
  for (const [code, pattern, message] of contracts) {
    requirePattern(issues, category, code, file, source, pattern, message);
  }
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

function endpointEntries(matrix) {
  return matrix.migration_domains.flatMap((domain) => domain.endpoints || []);
}

function endpointSet(entries) {
  return new Set(entries.map((entry) => normalizeEndpoint(entry)));
}

function lineNumber(source, index) {
  return source.slice(0, index).split('\n').length;
}

function inferClientMethods(source, index) {
  const context = source.slice(Math.max(0, index - 180), Math.min(source.length, index + 300));
  const conditionalMethods = context.match(/method\s*:\s*[^,\n}]*['"]([A-Za-z]+)['"][^,\n}]*['"]([A-Za-z]+)['"]/);
  if (conditionalMethods) return [conditionalMethods[1].toUpperCase(), conditionalMethods[2].toUpperCase()];
  const explicitMethod = context.match(/method\s*:\s*['"]([A-Za-z]+)['"]/);
  if (explicitMethod) return [explicitMethod[1].toUpperCase()];
  if (/\.uploadFile\s*\(/.test(source.slice(Math.max(0, index - 100), index))) return ['POST'];
  if (/\bmutation\s*\(/.test(source.slice(Math.max(0, index - 100), index))) return ['POST'];
  return ['GET'];
}

function clientEndpointReferences(root, files) {
  const references = [];
  const endpointPattern = /(?:\$\{[^}]+\})?(\/[-A-Za-z0-9_/${}]+\.php(?:\?[^'"`\s}]*)?)/g;
  for (const file of files) {
    if (relative(root, file).startsWith('mini-program/utils/transports/')) continue;
    const source = readFileSync(file, 'utf8');
    for (const match of source.matchAll(endpointPattern)) {
      const context = source.slice(Math.max(0, match.index - 120), Math.min(source.length, match.index + 120));
      const rawPath = match[1].replace(/\$\{[^}]+\}/g, '');
      const relativePath = relative(root, file);
      const isDrillV2 = /\bdrill\.(?:request|mutation)\s*\(/.test(context) || relativePath.endsWith('utils/drill-v2.js');
      const path = isDrillV2 && !rawPath.startsWith('/drill/')
        ? `/drill/v2${rawPath}`
        : rawPath;
      for (const method of inferClientMethods(source, match.index)) {
        references.push({ method, path, file: relativePath, line: lineNumber(source, match.index) });
      }
    }
  }
  return references;
}

function authProxyEndpoints(source) {
  return [...source.matchAll(/\[['"]([A-Z]+)\s+(\/[^'"]+)['"]/g)].map((match) => ({
    method: match[1],
    path: match[2],
  }));
}

function checkEndpointSynchronization(root, miniProgramJavascriptFiles) {
  const deploymentMatrixPath = join(root, 'cloudfunctions/api-proxy/business-domain-matrix.json');
  const authProxyPath = join(root, 'cloudfunctions/auth-proxy/index.js');
  if (!existsSync(deploymentMatrixPath) || !existsSync(authProxyPath)) {
    return {
      clientReferences: [],
      clientOnly: [],
      sourceOnlyDeployment: [],
      deploymentOnlySource: [],
      sourceOnlyProxy: [],
      proxyOnlySource: [],
      issues: [],
    };
  }
  const sourceMatrix = JSON.parse(readFileSync(join(root, 'mini-program/business-domain-matrix.json'), 'utf8'));
  const deploymentMatrix = JSON.parse(readFileSync(deploymentMatrixPath, 'utf8'));
  const authProxySource = readFileSync(authProxyPath, 'utf8');
  const sourceEndpoints = endpointEntries(sourceMatrix);
  const deploymentEndpoints = endpointEntries(deploymentMatrix);
  const proxyEndpoints = [...deploymentEndpoints, ...authProxyEndpoints(authProxySource)];
  const clientReferences = clientEndpointReferences(root, miniProgramJavascriptFiles);
  const clientEndpoints = clientReferences.map(({ method, path }) => ({ method, path }));
  const sourceSet = endpointSet(sourceEndpoints);
  const deploymentSet = endpointSet(deploymentEndpoints);
  const proxySet = endpointSet(proxyEndpoints);
  const clientSet = endpointSet(clientEndpoints);
  const issues = [];
  const addDiffIssues = (code, left, right, message) => {
    for (const key of compareEndpointSets(left, right).leftOnly) {
      issues.push(issue('endpoint_sync', code, 'mini-program/business-domain-matrix.json', `${message}: ${key}`));
    }
  };

  for (const reference of clientReferences) {
    const key = normalizeEndpoint(reference);
    if (!sourceSet.has(key)) {
      issues.push(issue('endpoint_sync', 'CLIENT_ENDPOINT_NOT_REGISTERED', `${reference.file}:${reference.line}`, `客户端调用未登记到源业务矩阵: ${key}`));
    }
  }
  addDiffIssues('SOURCE_ENDPOINT_MISSING_DEPLOYMENT', sourceEndpoints, deploymentEndpoints, '源业务矩阵 endpoint 未登记到部署矩阵');
  addDiffIssues('DEPLOYMENT_ENDPOINT_MISSING_SOURCE', deploymentEndpoints, sourceEndpoints, '部署矩阵 endpoint 未登记到源业务矩阵');
  addDiffIssues('SOURCE_ENDPOINT_MISSING_PROXY_ALLOWLIST', sourceEndpoints, proxyEndpoints, '源业务矩阵 endpoint 未登记到代理白名单');

  return {
    clientReferences,
    clientOnly: [...compareEndpointSets([...clientSet], [...sourceSet]).leftOnly],
    sourceOnlyDeployment: [...compareEndpointSets([...sourceSet], [...deploymentSet]).leftOnly],
    deploymentOnlySource: [...compareEndpointSets([...deploymentSet], [...sourceSet]).leftOnly],
    sourceOnlyProxy: [...compareEndpointSets([...sourceSet], [...proxySet]).leftOnly],
    proxyOnlySource: [],
    issues,
  };
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
  const cloudConfigSource = read('mini-program/config/cloud.js');
  const mediaSource = read('mini-program/utils/media.js');
  const navigationSource = read('mini-program/utils/navigation.js');
  const capabilitiesSource = read('mini-program/utils/capabilities.js');
  const capabilityEndpoint = read('api/platform/capabilities.php');
  const routeReport = checkMiniProgramRoutes(miniProgramRoot);
  const miniProgramJavascriptFiles = javascriptFiles(miniProgramRoot);

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
  for (const path of miniProgramJavascriptFiles) {
    if (path === apiPath) continue;
    const source = readFileSync(path, 'utf8');
    const relativePath = relative(root, path);
    if (/wx\.(?:request|uploadFile)\s*\(/.test(source) && !relativePath.startsWith('mini-program/utils/transports/')) {
      issues.push(issue('request_layer', 'NATIVE_NETWORK_OUTSIDE_API_CLIENT', relative(root, path), '原生网络调用必须通过统一 API 客户端'));
    }
    if (!CENTRAL_BUSINESS_URL_FILES.has(relativePath) && /supercalf\.com\/api/.test(source)) {
      issues.push(issue('request_layer', 'ABSOLUTE_BUSINESS_URL_OUTSIDE_API_CLIENT', relativePath, '业务 API 绝对地址只能出现在统一配置或请求客户端'));
    }
  }
  requirePattern(issues, 'request_layer', 'REQUEST_DELEGATE_MISSING', 'mini-program/app.js', appSource, /request\(options\)\s*\{\s*return api\.request\(options\)/, 'App 请求入口未委托统一 API 客户端');
  requirePattern(issues, 'request_layer', 'REQUEST_ID_MISSING', 'mini-program/utils/api.js', apiSource, /['"]X-Request-ID['"]/, '统一请求层缺少请求 ID');
  requirePattern(issues, 'request_layer', 'IDEMPOTENCY_KEY_MISSING', 'mini-program/utils/api.js', apiSource, /['"]Idempotency-Key['"]/, '统一请求层缺少幂等键');
  requirePatterns(issues, 'request_layer', 'mini-program/utils/api.js', apiSource, [
    ['TRANSPORT_POLICY_READER_MISSING', /function readTransportPolicy\(/, '统一请求层缺少版本化传输策略读取'],
    ['TRANSPORT_SELECTOR_MISSING', /function resolveTransportMode\(/, '统一请求层缺少传输模式选择器'],
    ['TRANSPORT_EXPLICIT_MODE_MISSING', /normalizeTransportMode\(options\.transport\)/, '统一请求层缺少显式 transport 覆盖'],
    ['TRANSPORT_POLICY_VERSION_FALLBACK_MISSING', /policy\.version !== 1[\s\S]*?cloudConfig\.TRANSPORT/, '统一请求层缺少未知策略版本回退'],
    ['TRANSPORT_EMERGENCY_SWITCH_MISSING', /policy\.emergencyActive && policy\.emergencyMode/, '统一请求层缺少紧急回退开关'],
    ['TRANSPORT_VERSIONED_MODE_MISSING', /policy\.mode === ['"]versioned['"]/, '统一请求层缺少版本化切换模式'],
    ['TRANSPORT_MIN_CLIENT_VERSION_MISSING', /compareVersions\(clientVersion, policy\.minimumClientVersion\)/, '统一请求层缺少最低客户端版本判断'],
    ['TRANSPORT_READ_SHADOW_WRITE_CLOUD_MISSING', /isWriteMethod\(options\.method\) \? ['"]cloud['"] : ['"]shadow['"]/, '统一请求层缺少读影子写云的版本化策略'],
  ]);
  requirePatterns(issues, 'request_layer', 'mini-program/config/cloud.js', cloudConfigSource, [
    ['CLOUD_ENV_ID_MISSING', /ENV_ID:\s*['"][a-z0-9][a-z0-9-]{5,63}['"]/, '云开发配置缺少合法环境 ID'],
    ['CLOUD_API_PROXY_NAME_MISSING', /API_PROXY:\s*['"]api-proxy['"]/, '云开发配置缺少 api-proxy 函数名'],
    ['CLOUD_AUTH_PROXY_NAME_MISSING', /AUTH_PROXY:\s*['"]auth-proxy['"]/, '云开发配置缺少 auth-proxy 函数名'],
    ['CLOUD_MEDIA_TICKET_NAME_MISSING', /MEDIA_TICKET:\s*['"]media-ticket['"]/, '云开发配置缺少 media-ticket 函数名'],
    ['CLOUD_TRANSPORT_POLICY_VERSION_MISSING', /TRANSPORT_POLICY_VERSION:\s*1/, '云开发配置缺少传输策略版本'],
    ['CLOUD_TRANSPORT_MIN_CLIENT_VERSION_MISSING', /TRANSPORT_MIN_CLIENT_VERSION:/, '云开发配置缺少 transport 最低客户端版本'],
    ['CLOUD_TRANSPORT_EMERGENCY_SWITCH_MISSING', /TRANSPORT_EMERGENCY_ACTIVE:\s*false/, '云开发配置缺少默认关闭的紧急开关'],
    ['CLOUD_SHADOW_SAMPLE_RATE_MISSING', /SHADOW_SAMPLE_RATE:\s*0/, '云开发配置缺少默认关闭的影子抽样率'],
    ['CLOUD_STORAGE_RULE_MODE_MISSING', /RULE_MODE:\s*['"]cloud-function-only['"]/, '云开发配置缺少云函数专用存储规则'],
  ]);
  requirePattern(issues, 'request_layer', 'CLOUDBASE_INIT_MISSING', 'mini-program/app.js', appSource, /wx\.cloud\.init\(\{[\s\S]*env:\s*cloudConfig\.ENV_ID/, 'App 缺少云开发初始化');

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
  requirePatterns(issues, 'upload', 'mini-program/utils/media.js', mediaSource, [
    ['MEDIA_CLOUD_PATH_MISSING', /function createCloudPath\(/, '媒体工具缺少受控云路径生成'],
    ['MEDIA_CLOUD_UPLOAD_MISSING', /wx\.cloud\.uploadFile/, '媒体工具缺少云存储上传'],
    ['MEDIA_TICKET_CALL_MISSING', /name:\s*['"]media-ticket['"]/, '媒体工具缺少 media-ticket 登记调用'],
    ['MEDIA_UPLOAD_REGISTER_MISSING', /function uploadAndRegister\(/, '媒体工具缺少上传并登记入口'],
    ['MEDIA_DESCRIPTOR_NORMALIZER_MISSING', /function normalizeMediaDescriptor\(/, '媒体工具缺少媒体描述标准化'],
    ['MEDIA_TEMP_FILE_MISSING', /function getPlayableTempFile\(/, '媒体工具缺少可播放临时文件解析'],
    ['MEDIA_CACHE_CLEAR_MISSING', /function clearMediaCache\(/, '媒体工具缺少媒体缓存清理'],
  ]);

  requirePattern(issues, 'capability_version', 'CAPABILITY_CLIENT_MISSING', 'mini-program/app.js', appSource, /capabilities\.resolveFeatures\(/, 'App 未解析能力版本');
  requirePattern(issues, 'capability_version', 'CAPABILITY_FALLBACK_MISSING', 'mini-program/utils/capabilities.js', capabilitiesSource, /CONSERVATIVE_FEATURES/, '能力版本缺少保守降级');
  requirePattern(issues, 'capability_version', 'CAPABILITY_MINIMUM_VERSION_MISSING', 'mini-program/utils/capabilities.js', capabilitiesSource, /minimum_client_version/, '能力版本缺少最低客户端版本判断');
  requirePattern(issues, 'capability_version', 'CAPABILITY_ENDPOINT_MISSING', 'api/platform/capabilities.php', capabilityEndpoint, /'mini_program_feature_versions'/, '能力端点缺少小程序功能版本声明');
  requirePattern(issues, 'capability_version', 'CAPABILITY_ALLOWLIST_MISSING', 'api/platform/capabilities.php', capabilityEndpoint, /'fallback_mode'\s*=>\s*'explicit_allowlist'/, '能力端点缺少显式功能白名单降级');

  const endpointSync = checkEndpointSynchronization(root, miniProgramJavascriptFiles);
  issues.push(...endpointSync.issues);

  return {
    root,
    status: issues.length === 0 ? 'passed' : 'failed',
    categories: CONTRACT_CATEGORIES.map((category) => ({
      category,
      issue_count: issues.filter((item) => item.category === category).length,
    })),
    registeredRoutes: routeReport.registeredRoutes,
    checkedReferences: routeReport.checkedReferences,
    endpointSync,
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
