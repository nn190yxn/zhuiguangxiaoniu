import { readFileSync, readdirSync } from 'node:fs';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const DOMAIN_TYPES = ['request', 'uploadFile', 'downloadFile', 'webView'];

function readJson(path, label, errors) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch (error) {
    errors.push({ file: label, line: 1, message: `无法读取 ${label}: ${error.message}` });
    return {};
  }
}

function listSourceFiles(root) {
  const files = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      if (entry.name.startsWith('.') || entry.name === 'node_modules') continue;
      const path = join(directory, entry.name);
      if (entry.isDirectory()) visit(path);
      if (entry.isFile() && ['.js', '.wxml'].includes(extname(entry.name))) files.push(path);
    }
  };
  visit(root);
  return files;
}

function validHttpsOrigin(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && value === url.origin;
  } catch {
    return false;
  }
}

export function checkMiniProgramRelease(projectRoot) {
  const root = resolve(projectRoot);
  const errors = [];
  const warnings = [];
  const appConfig = readJson(join(root, 'app.json'), 'app.json', errors);
  const projectConfig = readJson(join(root, 'project.config.json'), 'project.config.json', errors);
  const releaseConfig = readJson(join(root, 'release-check.config.json'), 'release-check.config.json', errors);
  const files = listSourceFiles(root);
  const sources = files.map((file) => ({ file, content: readFileSync(file, 'utf8') }));
  const allSource = sources.map((item) => item.content).join('\n');

  const domains = releaseConfig.domains || {};
  for (const type of DOMAIN_TYPES) {
    const values = Array.isArray(domains[type]) ? domains[type] : [];
    if (values.length === 0) {
      errors.push({ file: 'release-check.config.json', line: 1, message: `${type} 合法域名清单为空` });
    }
    for (const value of values) {
      if (!validHttpsOrigin(value)) {
        errors.push({ file: 'release-check.config.json', line: 1, message: `${type} 域名必须是无路径的 HTTPS origin: ${value}` });
      }
    }
  }

  const requestOrigins = new Set(Array.isArray(domains.request) ? domains.request : []);
  const absoluteUrls = [...allSource.matchAll(/https?:\/\/[^\s'"`<>)]+/g)].map((match) => match[0].replace(/[.,;]+$/, ''));
  for (const value of new Set(absoluteUrls)) {
    let url;
    try {
      url = new URL(value);
    } catch {
      continue;
    }
    if (url.protocol !== 'https:') {
      errors.push({ file: 'mini-program source', line: 1, message: `发现非 HTTPS 地址 ${value}` });
    } else if (!requestOrigins.has(url.origin)) {
      errors.push({ file: 'release-check.config.json', line: 1, message: `代码使用的域名未列入 request 清单: ${url.origin}` });
    }
  }

  const appId = String(projectConfig.appid || '');
  if (!/^wx[0-9a-f]{16}$/i.test(appId)) {
    errors.push({ file: 'project.config.json', line: 1, message: 'appid 必须是正式微信小程序 AppID' });
  }
  if (projectConfig.compileType !== 'miniprogram') {
    errors.push({ file: 'project.config.json', line: 1, message: 'compileType 必须为 miniprogram' });
  }
  for (const setting of ['urlCheck', 'autoAudits', 'scopeDataCheck', 'checkInvalidKey', 'checkSiteMap', 'minified']) {
    if (projectConfig.setting?.[setting] !== true) {
      errors.push({ file: 'project.config.json', line: 1, message: `构建设置 setting.${setting} 必须启用` });
    }
  }

  if (appConfig.__usePrivacyCheck__ !== true) {
    errors.push({ file: 'app.json', line: 1, message: '__usePrivacyCheck__ 必须启用' });
  }
  const privacy = releaseConfig.privacyCapabilities || {};
  const privacyPolicy = sources.find((item) => relative(root, item.file) === 'pages/agreement/privacy.wxml')?.content || '';
  const capabilityChecks = [
    ['wx.chooseMedia', 'chooseMedia', ['camera', 'album'], ['相机', '相册']],
    ['wx.getRecorderManager', 'record', [], ['录音']],
    ['wx.requestSubscribeMessage', 'subscribeMessage', [], ['订阅消息']],
  ];
  for (const [api, capability, related, policyTerms] of capabilityChecks) {
    if (!allSource.includes(api)) continue;
    if (privacy[capability] !== true || related.some((key) => privacy[key] !== true)) {
      errors.push({ file: 'release-check.config.json', line: 1, message: `${api} 使用的隐私能力声明不完整` });
    }
    for (const term of policyTerms) {
      if (!privacyPolicy.includes(term)) {
        errors.push({ file: 'pages/agreement/privacy.wxml', line: 1, message: `隐私政策缺少“${term}”用途说明` });
      }
    }
  }
  if (allSource.includes('wx.getRecorderManager') && !String(appConfig.permission?.['scope.record']?.desc || '').trim()) {
    errors.push({ file: 'app.json', line: 1, message: '录音能力缺少 permission.scope.record.desc' });
  }
  if (allSource.includes('wx.getRecorderManager') && (!allSource.includes('wx.getSetting') || !allSource.includes('wx.openSetting'))) {
    errors.push({ file: 'mini-program source', line: 1, message: '录音能力缺少 scope.record 检查与授权设置入口' });
  }
  if (allSource.includes('wx.chooseMedia') && (!allSource.includes('wx.getPrivacySetting') || !allSource.includes('wx.openPrivacyContract'))) {
    errors.push({ file: 'mini-program source', line: 1, message: '相册和相机能力缺少隐私授权流程' });
  }

  const manualChecks = Array.isArray(releaseConfig.manualChecks) ? releaseConfig.manualChecks.filter(Boolean) : [];
  if (manualChecks.length === 0) {
    warnings.push('未配置微信后台、开发者工具和真机人工检查清单');
  }

  return {
    root,
    appId,
    domains,
    manualChecks,
    checkedFiles: files.length,
    errors,
    warnings,
  };
}

export function formatReleaseReport(report) {
  const lines = report.errors.length === 0
    ? [`小程序发布代码检查通过：${report.checkedFiles} 个源码文件，AppID ${report.appId}。`]
    : [`小程序发布代码检查失败：${report.errors.length} 个阻断项。`, ...report.errors.map((issue) => `${issue.file}:${issue.line} ${issue.message}`)];
  if (report.warnings.length > 0) lines.push(...report.warnings.map((warning) => `警告：${warning}`));
  if (report.manualChecks.length > 0) {
    lines.push('仍需人工核验：', ...report.manualChecks.map((item) => `- ${item}`));
  }
  return lines.join('\n');
}

const currentFile = fileURLToPath(import.meta.url);
if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const defaultRoot = resolve(dirname(currentFile), '../mini-program');
  const report = checkMiniProgramRelease(process.argv[2] || defaultRoot);
  console.log(formatReleaseReport(report));
  if (report.errors.length > 0) process.exitCode = 1;
}
