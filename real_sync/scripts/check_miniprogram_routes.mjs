import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PAGE_FILES = ['.js', '.json', '.wxml', '.wxss'];
const ROUTE_PATTERN = /\/pages\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*/g;

function sourceFiles(root, registeredRoutes) {
  const files = [];
  const registeredPageSources = new Set(
    registeredRoutes.flatMap((route) => ['.js', '.wxml'].map((extension) => `${route}${extension}`)),
  );
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      if (entry.name.startsWith('.') || entry.name === 'node_modules') continue;
      const path = join(directory, entry.name);
      if (entry.isDirectory()) visit(path);
      if (!entry.isFile() || !['.js', '.wxml'].includes(extname(entry.name))) continue;
      const sourcePath = relative(root, path).replaceAll('\\', '/');
      if (!sourcePath.startsWith('pages/') || registeredPageSources.has(sourcePath)) files.push(path);
    }
  };
  visit(root);
  return files;
}

function lineNumber(content, index) {
  return content.slice(0, index).split('\n').length;
}

export function checkMiniProgramRoutes(projectRoot) {
  const root = resolve(projectRoot);
  const errors = [];
  const appPath = join(root, 'app.json');
  let appConfig;

  try {
    appConfig = JSON.parse(readFileSync(appPath, 'utf8'));
  } catch (error) {
    return { root, registeredRoutes: [], checkedReferences: 0, errors: [{ file: 'app.json', line: 1, message: `无法读取 app.json: ${error.message}` }] };
  }

  const registeredRoutes = Array.isArray(appConfig.pages) ? appConfig.pages : [];
  const registered = new Set(registeredRoutes);
  for (const route of registeredRoutes) {
    for (const extension of PAGE_FILES) {
      const pageFile = `${route}${extension}`;
      if (!existsSync(join(root, pageFile))) {
        errors.push({ file: 'app.json', line: 1, route, message: `注册页面缺少基础文件 ${pageFile}` });
      }
    }
  }

  let checkedReferences = 0;
  const seen = new Set();
  for (const file of sourceFiles(root, registeredRoutes)) {
    const content = readFileSync(file, 'utf8');
    for (const match of content.matchAll(ROUTE_PATTERN)) {
      const route = match[0].slice(1);
      const line = lineNumber(content, match.index || 0);
      const key = `${file}:${line}:${route}`;
      if (seen.has(key)) continue;
      seen.add(key);
      checkedReferences += 1;
      if (!registered.has(route)) {
        errors.push({
          file: relative(root, file),
          line,
          route,
          message: `固定路由 /${route} 未在 app.json 注册`,
        });
      }
    }
  }

  return { root, registeredRoutes, checkedReferences, errors };
}

export function formatRouteReport(report) {
  if (report.errors.length === 0) {
    return `小程序路由检查通过：${report.registeredRoutes.length} 个注册页面，${report.checkedReferences} 处固定路由。`;
  }
  return [
    `小程序路由检查失败：${report.errors.length} 个阻断项。`,
    ...report.errors.map((issue) => `${issue.file}:${issue.line} ${issue.message}`),
  ].join('\n');
}

const currentFile = fileURLToPath(import.meta.url);
if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const defaultRoot = resolve(dirname(currentFile), '../mini-program');
  const report = checkMiniProgramRoutes(process.argv[2] || defaultRoot);
  console.log(formatRouteReport(report));
  if (report.errors.length > 0) process.exitCode = 1;
}
