# Agent 交接文档：最近变更、记忆规则与接手口径

更新时间：2026-06-09

本文档用于后续 Agent 直接从 GitHub 接手追光小牛企业内网项目。内容汇总了项目记忆中的关键执行规则、真实运行基线、最近工作量与暑假班变更、生产备份点和验证方式。

## 1. 项目接手口径

- 真实 Git 仓库：`/workspace/real_sync`
- GitHub remote：`https://github.com/nn190yxn/zhuiguangxiaoniu.git`
- 主代码目录：`/workspace/real_sync/real_sync/`
- 旧副本目录：`/workspace/real_sync/追光小牛/`，仅在明确要求时处理
- 生产 Web 根目录：`/www/wwwroot/122.51.223.46/`
- 生产域名：`https://supercalf.com/`
- 线上真实基线优先级高于 GitHub 历史代码；清理或同步 GitHub 时，默认以生产运行文件为准

## 2. 长期执行规则

- 做架构修复、代码修复或功能增设前，先制定完整计划，再执行。
- 计划必须覆盖影响面、入口、数据链路、接口、前端、后台、缓存、部署、回滚和验证方式。
- 修改生产文件前必须备份，备份路径要在交接或最终回复中记录。
- 每次改动后必须回测相关入口、关键接口和相邻功能，避免修复一个点造成回归。
- 线上功能异常优先做整条链路检查，覆盖入口、缓存版本、登录态、鉴权、接口、日志和回归验证。
- 工作量系统相关改动优先检查 `real_sync/api/workload/`、`real_sync/mobile/workload*.html`、`real_sync/admin/workload.html` 和小程序工作量页面。
- 工作量凭证图片数量上限统一为每个指标最多 10 张，前端提示、前端拦截和后端强校验都要保持一致。
- 新网页前端请求优先使用 `/js/app-auth.js` 与 `/js/api-client.js`，避免散写鉴权和 fetch 封装。
- 后端新接口优先基于 `/api/common/context.php`、`/api/common/helpers.php` 和共享权限函数实现。

## 3. 工作量系统当前关键口径

- 长期建设主线是常态化工作量与经营系统，优先覆盖销售和教练两个岗位。
- 工作量指标按行为层、过程层、结果层、自动计算层分层。
- 小程序后续接同一套 `/api/workload/*`，避免发展小程序专用数据口径。
- 员工当天可以修改当天工作量日报，最终以最后一次提交为准。
- 工作量后台默认检查最近 7 天。
- 超过 7 天进入归档口径，30 天以后再按确认范围删除。
- 每个需要上传图片的工作量指标，最多允许上传 10 张图片。

## 4. 最近提交记录

以下提交均已推送到 GitHub `main` 分支：

- `69ec1ad fix(workload): add social video evidence metric`
- `c523b95 fix(workload): add small package evidence metric`
- `dec9b6c fix(workload): refresh today entry points`
- `40bac15 fix(summer-camp): add direct pdf export`
- `6ed9d5a fix(summer-camp): improve report export saving`
- `2ce911b fix(workload): refresh current date selection`
- `5b0ecab fix(workload): harden evidence upload release path`
- `92518fc fix(workload): avoid false invalid image uploads`
- `aa48278 fix(workload): clarify invalid evidence images`
- `6e7d02c fix(workload): repair staff detail evidence queries`
- `75d7f8e fix(summer-camp): repair assessment history flow`

## 5. 2026-06-09 工作量系统变更记录

### 5.1 社交媒体视频指标

- 新增销售指标：`sales_social_video`，名称为 `拍摄视频上传社交媒体`。
- 新增教练指标：`coach_social_video`，名称为 `拍摄视频上传社交媒体`。
- 两个指标均配置为需要图片凭证，最少 1 张，最多 10 张。
- 指标会进入审核任务和异常口径。
- 入口缓存版本刷新为 `20260609socialvideo`。
- 涉及文件：
  - `real_sync/api/workload/_common.php`
  - `real_sync/internal.html`
  - `real_sync/mobile/mine.html`
  - `real_sync/mobile-mine.html`
  - `real_sync/mobile/workload.html`
- 生产备份目录：`/www/wwwroot/122.51.223.46/backup-workload-socialvideo-20260609`
- 验证结果：远端 `php -l` 通过，生产页面 HTTP 200，销售和教练模板均返回新指标且 `need_evidence=1`。

### 5.2 小课包指标

- 新增销售指标：`sales_small_package`，名称为 `小课包`。
- 新增教练指标：`coach_small_package`，名称为 `小课包`。
- 两个指标均配置为需要图片凭证，最少 1 张，最多 10 张。
- 手机端提交校验已增强：填写数量后至少需要上传对应凭证图片。
- 入口缓存版本刷新为 `20260609smallpkg`，后续已被 `20260609socialvideo` 替代。
- 涉及文件：
  - `real_sync/api/workload/_common.php`
  - `real_sync/mobile/workload-v2.html`
  - `real_sync/internal.html`
  - `real_sync/mobile/mine.html`
  - `real_sync/mobile-mine.html`
  - `real_sync/mobile/workload.html`
- 生产备份目录：`/www/wwwroot/122.51.223.46/backup-workload-smallpkg-20260609`
- 验证结果：远端 `php -l` 通过，生产页面 HTTP 200，销售和教练模板均返回小课包且 `need_evidence=1`。

### 5.3 日期入口修复

- 工作量 H5 入口统一刷新到新版，解决旧入口继续加载历史版本导致今天不可选的问题。
- 后台 `admin/workload.html` 默认日期和最大日期改为今天。
- 7 天范围从 `daysAgo(6)` 到今天，30 天范围从 `daysAgo(29)` 到今天。
- 生产备份目录：`/www/wwwroot/122.51.223.46/backup-workload-today-20260609063500`
- 验证结果：H5 今天可选，后台默认今天，销售/教练今天读取 `code=0`，未来日期仍拦截。

### 5.4 上传链路修复

- 上传接口版本常量：`WORKLOAD_EVIDENCE_UPLOAD_VERSION = '20260609-uploadfix'`。
- 修复二进制图片被文本关键字误判为非法内容的问题。
- 修复上传接口生产发布路径不一致导致旧代码继续生效的问题。
- 真实销售和教练账号均完成过端到端上传验证。
- 测试凭证已通过业务删除接口清理。
- 生产备份目录：
  - `/www/wwwroot/122.51.223.46/backup-workload-upload-20260609051658`
  - `/www/wwwroot/122.51.223.46/backup-workload-upload-full-20260609052457`

### 5.5 后台明细和异常图片提示

- 修复员工明细凭证查询问题。
- 增强异常小图提示：图片文件过小或原图不可见时，提示重新上传清晰凭证。
- 生产备份目录：
  - `/www/wwwroot/122.51.223.46/backup-workload-detail-20260609025501`
  - `/www/wwwroot/122.51.223.46/backup-workload-image-20260609032926`

## 6. 2026-06-09 暑假班变更记录

### 6.1 真正一键保存 PDF

- 暑假班评估生成页和历史页新增 `保存PDF`。
- 动态加载 `html2canvas@1.4.1` 与 `jspdf@2.5.1`。
- 将 `.report-page` 渲染成 A4 PDF 并通过 `pdf.save(filename)` 保存。
- 保留原 `导出/打印` 作为兜底。
- 入口版本：`260609pdffix`。
- 生产备份目录：`/www/wwwroot/122.51.223.46/backup-summer-camp-pdf-20260609062000`
- 涉及文件：
  - `real_sync/summer-camp-assessment-app.html`
  - `real_sync/summer-camp-history.html`
  - `real_sync/summer-camp-assessment.html`

### 6.2 JPG 导出保存增强

- JPG 导出优先使用 `navigator.share`。
- 浏览器下载失败时展示图片预览，用户可长按保存。
- 生成页和历史页都已增强。
- 生产备份目录：`/www/wwwroot/122.51.223.46/backup-summer-camp-export-20260609061246`

### 6.3 评估历史流程修复

- 修复暑假班评估历史流程问题。
- 已完成生产部署、回归测试和 GitHub 同步。
- 相关提交：`75d7f8e fix(summer-camp): repair assessment history flow`

## 7. 常用验证方式

### 7.1 本地检查

```bash
# 查看工作树状态
git status --short

# 查看最近提交
git log --oneline -10

# 检查前端内联脚本语法
node - <<'NODE'
const fs = require('fs');
const vm = require('vm');
for (const file of ['real_sync/mobile/workload-v2.html','real_sync/mobile/workload.html','real_sync/mobile/mine.html','real_sync/mobile-mine.html']) {
  const html = fs.readFileSync(file, 'utf8');
  const scripts = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/gi)].map(m => m[1]);
  scripts.forEach((script, index) => new vm.Script(script, { filename: `${file}#script${index + 1}` }));
  console.log(`${file}: checked ${scripts.length} inline scripts`);
}
NODE
```

### 7.2 生产 PHP 语法检查

```bash
# 检查工作量公共文件语法
ssh root@122.51.223.46 "php -l /www/wwwroot/122.51.223.46/api/workload/_common.php"
```

### 7.3 生产页面状态检查

```bash
# 检查工作量入口和新版页面
ssh root@122.51.223.46 "curl -I -m 20 https://supercalf.com/mobile/workload.html?v=20260609socialvideo && curl -I -m 20 https://supercalf.com/mobile/workload-v2.html?v=20260609socialvideo"
```

### 7.4 工作量模板验证

生产模板验证需要使用真实系统登录态或在服务器内生成临时 JWT，输出时只展示状态、指标名和规则，不输出 token。

期望结果：

- 销售模板包含 `sales_small_package` 和 `sales_social_video`。
- 教练模板包含 `coach_small_package` 和 `coach_social_video`。
- 四个指标均为 `need_evidence=1`、`min_evidence_count=1`、`max_evidence_count=10`。

### 7.5 日志检查

```bash
# 检查工作量关键错误
ssh root@122.51.223.46 "grep -E 'workload.template_error|workload.save_report_error|workload.evidence_upload_error' /www/wwwroot/122.51.223.46/logs/api-YYYY-MM-DD.log"
```

## 8. 敏感信息处理

- 不要把 SSH 私钥、JWT、API Key、数据库密码、WordPress 配置密钥写入 GitHub 文档或提交内容。
- 生产连接方式可以记录主机、用户和目录；具体凭据只在运行环境中使用。
- `wp-config.php`、`api/config.php`、`.env.local.php` 等敏感配置文件提交前必须重点检查。

## 9. 当前 Git 状态说明

截至本文档创建前，业务代码已推送到 GitHub `main` 分支。

本次将把以下文档类内容提交到 GitHub，方便后续 Agent 直接读取：

- `.monkeycode/MEMORY.md`
- `.monkeycode/docs/agent-handoff-latest-changes-2026-06-09.md`
