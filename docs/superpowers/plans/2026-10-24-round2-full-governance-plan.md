# 追光小牛第二轮全量治理方案（2026-10-24）

> 本方案面向**执行模型**（flash）。所有任务已拆成独立任务卡，按顺序执行。每张卡自包含：目标、涉及文件、精确步骤、验收命令、提交要求。凡验收不通过或步骤与实际代码不符，**立即停止该任务并在报告中说明**，不要自行发挥。

## 0. 执行总则（先读，必须遵守）

### 0.1 环境事实
- 工作目录：`E:\程序开发\追光小牛\git\zhuiguangxiaoniu\real_sync\`（下文相对路径均基于此目录）。
- 本机 Windows + Git Bash，Node v24 可用；**本机没有 PHP**（无法本地 `php -l`，PHP 语法靠谨慎编辑 + 最终在服务器检查）。
- 全量测试命令：`node --test scripts/*.test.mjs`（约 4-6 分钟）。
- 本机测试基线存在约 **209 个环境性失败**（无 PHP、Windows 路径差异所致），属正常现象。**开工第一步必须先跑一次全量测试存基线，之后每个任务结束对比：不得出现基线之外的新失败。**

### 0.2 红线（违反即停止）
1. 不修改 `api/.env.local.php`、`wp-*` 文件、`database/migrations/`（本轮零数据库迁移）。
2. 不连接服务器、不部署、不推送 GitHub（由主控/用户另行安排）。
3. 不改 `api/config.php` 中第一轮已收敛的函数（`wp_verify_password`、`httpGetJsonWithTimeout`、`httpPostJsonWithTimeout`、`normalizeStaffRoleCode`、`getWordPressAuthRole`、`getStaffByUserId` 的缓存语义）。
4. 不删除任何测试文件；只允许按任务卡明确指示更新测试断言。
5. 每张任务卡完成后单独 `git commit`（见各卡提交规范），禁止多卡混提。
6. 所有对用户的说明使用中文，简单直接。

### 0.3 每张任务卡的固定流程
1. `git status --short` 确认工作区干净（除本卡产物外）。
2. 按步骤修改。
3. 跑卡内"验收命令"，全部通过。
4. 跑该卡指定的局部测试 + （B/C 工作流每 3 张卡）一次全量测试对比基线。
5. 按卡内规范提交 commit。
6. 在工作日志（见 §6）追加一行。

### 0.4 基线准备（开工第一件事）
```bash
cd real_sync
node --test scripts/*.test.mjs > /tmp/round2-baseline.log 2>&1
grep "^✖" /tmp/round2-baseline.log | sed 's/ ([0-9.]*ms)//' | tr -d '\r' | sed 's/^\s*//' | sort -u > /tmp/round2-base-fails.txt
wc -l < /tmp/round2-base-fails.txt   # 记下这个数字，约 209
```
后续每次全量对比：
```bash
node --test scripts/*.test.mjs > /tmp/round2-after.log 2>&1
grep "^✖" /tmp/round2-after.log | sed 's/ ([0-9.]*ms)//' | tr -d '\r' | sed 's/^\s*//' | sort -u > /tmp/round2-now-fails.txt
comm -13 /tmp/round2-base-fails.txt /tmp/round2-now-fails.txt   # 必须为空输出
```

---

## 1. 响应契约标准（本轮治理的目标态，先立规矩）

全部 `/api/*.php` 端点统一遵守：

| 场景 | code | HTTP 状态 | data |
|---|---|---|---|
| 成功 | 0 | 200 | 业务数据 |
| 业务/校验拒绝（用户输入错、状态不允许） | 1 | 400 | null |
| 未登录 | 401 | 401 | null |
| 无权限 | 403 | 403 | null |
| 资源不存在 | 404 | 404 | null |
| 冲突（乐观锁/幂等键/重复） | 409 | 409 | null |
| 限流 | 429 | 429 | null |
| 服务器错误（catch 兜底） | 500 | 500 | null |

补充规则：
- 服务器错误消息**不得**包含异常堆栈、SQL、内部路径；统一为"XX失败，请稍后重试"或既有文案。
- 已接入新内核（`platformApiContext` 系列，现有 49 个端点）的响应额外带 `request_id` 与 `meta`，属同一契约的超集。
- 本轮**不改变**任何端点的 URL、请求参数、`data` 内部结构——前端零改动是硬约束。

**任务卡 A0：把上表写入仓库文档**
- 新建 `docs/api-response-contract.md`，内容为上表 + 补充规则 + 迁移期说明（旧别名仍在但已废弃）。
- 提交：`docs(api): define unified response contract`

---

## 2. 工作流 A：错误码与响应契约全量治理

### 任务卡 A1：残留错误码审计与修正（catch 块清零复查）
第一轮已把 72 处 catch 兜底改为 500。本轮目标：**确认 catch 块内 `jsonResponse(1` 清零**（wecom 3 处除外，归 D3），并产出全量审计表。

步骤：
1. 用以下 Node 脚本扫描（已验证可用的正则，支持两层嵌套大括号）：
```js
// /tmp/scan-catch.mjs —— 用法: node /tmp/scan-catch.mjs > /tmp/catch-audit.txt
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
const sep = join('a','b').slice(1,-1);
const catchBlock = /catch \((?:Throwable|Exception|PDOException|RuntimeException|InvalidArgumentException|DomainException)[^)]*\)\s*\{(?:[^{}]|\{[^{}]*\})*?\}/g;
function walk(dir, out) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (name.endsWith('.php')) {
      const src = readFileSync(p, 'utf8');
      for (const m of src.matchAll(catchBlock)) {
        if (/jsonResponse\(1,/.test(m[0])) out.push(p.split(sep).join('/') + ' :: ' + m[0].replace(/\s+/g,' ').slice(0,160));
      }
    }
  }
}
const hits = []; walk('api', hits);
console.log(hits.length ? hits.join('\n') : 'CLEAN');
```
   预期结果：只剩 `api/wecom/bind-member.php`、`api/wecom/retry-message.php`、`api/wecom/unbind-member.php` 三行（归 D3 处理）。若出现其他文件：该 catch 块内的 `jsonResponse(1,` 改为 `jsonResponse(500,`（消息保持不变），逐个修改。
2. 生成非 catch 块的 `jsonResponse(1` 全量清单：
```bash
grep -rn "jsonResponse(1," --include="*.php" api/ > /tmp/code1-all.txt && wc -l < /tmp/code1-all.txt   # 约 181 行
```
3. 新建审计表 `docs/audits/error_code_audit_round2.md`：把 `/tmp/code1-all.txt` 内容按目录分组列入，每行标注分类（`校验/业务` 或 `疑似服务器错误待人工`）。分类规则：消息以"仅支持/缺少/不能为空/必须/无效/不存在/未找到/无权/已绑定/已存在/格式/超出/请"开头或含这些词 → 校验/业务；含"服务器/系统/异常/失败:"且语义是服务端故障 → 疑似服务器错误待人工（**只标注，不修改**，除非它在 catch 块内）。
- 验收：`node /tmp/scan-catch.mjs` 输出仅含 wecom 3 行；审计表存在且行数 ≥ 181。
- 提交：`fix(api): zero out remaining catch-block code-1 responses and add audit table`

### 任务卡 A2：废弃别名 `json_response` 收敛
现状：`json_response(` 全库 99 处调用（定义在 `api/config.php`，是 `jsonResponse` 的兼容包装）。全量替换为 `jsonResponse(`，然后删除 `api/config.php` 中的 `json_response` 函数定义。

步骤：
1. `grep -rln "json_response(" --include="*.php" api/ | xargs sed -i 's/json_response(/jsonResponse(/g'`
2. 删除 `api/config.php` 中 `function json_response(...)` 整个函数（含注释，约 583-596 行区域，以实际内容为准）。
3. 确认清零：`grep -rn "json_response" --include="*.php" api/ | wc -l` → 0。
4. 注意 `jsonSuccess`/`jsonError`/`appJsonSuccess`/`appJsonError` **保留不动**（使用面广且语义清晰，文档标注即可）。
- 验收：清零命令输出 0；`node --test scripts/platform_pwa_session_runtime.test.mjs scripts/knowledge_personal_features.test.mjs` 通过数不低于基线。
- 提交：`refactor(api): replace deprecated json_response alias with jsonResponse`

---

## 3. 工作流 B：API 风格统一（分批迁移到新内核）

### 目标态与迁移模板
新内核端点结构（范本：`api/knowledge/list.php`，迁移前先通读它）：
```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';   // 需要员工上下文的端点加这行
require_once __DIR__ . '/../kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => '<域名>', 'action' => '<域>.<动作>.read']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {          // 按实际允许的方法调整
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();                        // 或 requirePermission('<权限串>')
$context = $context->withActor($auth->userId(), $auth->staffId());

// ……原业务逻辑与查询保持不变（输入校验可继续用 appRequire* 系列）……
// 成功返回：
$logger->log('info', '<域>.<动作>.read', $context, ['total' => count($list)]);
platformApiResponse($context, $result)->send();
// 业务拒绝改抛异常（替代 jsonResponse 直接输出）：
// throw new PlatformApiException(403, 'permission_denied', '无权限查看该门店数据');
```
迁移硬规则：
- URL、请求参数、`data` 结构、权限语义**不变**；响应 JSON 外壳从 `{code,message,data}` 变为 `{code,message,data,request_id,meta}`（前端读 code/message/data，兼容）。
- 原来用 `getCurrentUserId()+jsonResponse` 的校验拒绝（缺参数等）改为抛 `PlatformApiException(400, 'invalid_xxx', 原消息)` 或保留 `jsonResponse(1, 原消息)` 均可——同一文件内保持一种风格。
- 迁移完每个端点：`node --check` 不适用（PHP），用括号配平脚本自查 + 全量测试对比。

### 任务卡 B1：statistics 域 3 个端点试点（先走通流程）
文件：`api/statistics/device.php`、`api/statistics/store.php`、`api/statistics/staff.php`。
- 权限要求：device/store/staff 统计均为管理侧数据 → `$auth->requirePermission('staff.audit_view')` 之外的既有语义以现文件为准：**先读现文件的鉴权分支**（现文件用 `appRequireStaffContext()` + `appCanViewStore` 判断的，改为 `$auth->requireAuthenticated()` 后保留原 store 范围判断逻辑，不引入新权限串）。
- domain 值：`statistics`；action 例：`statistics.store.read`。
- 验收：`node --test scripts/*.test.mjs` 全量对比基线零新增失败；`grep -c "platformApiContext" api/statistics/*.php` → 3。
- 提交：`refactor(api): migrate statistics endpoints to platform kernel`

### 任务卡 B2：points 域 4 个端点
文件：`api/points/index.php`、`checkin.php`、`ranking.php`、`records.php`（`points/index.php` 若已是内核则跳过，先 `grep -l platformApiContext api/points/*.php` 确认）。
- 鉴权：员工本人数据 → `requireAuthenticated()`；范围校验保留原逻辑。
- domain：`points`。
- 验收：全量对比零新增；`node --test scripts/knowledge_personal_features.test.mjs`（含积分相关断言的文件名可能不同，先 `grep -rl "points" scripts/*.test.mjs | head` 找到并跑）。
- 提交：`refactor(api): migrate points endpoints to platform kernel`

### 任务卡 B3：learning 域剩余端点
先确认哪些未迁移：`grep -L "platformApiContext" api/learning/*.php`（预期 `category.php`、`detail.php`、`list.php`，`lesson.php` 已迁移）。
- domain：`learning`；鉴权 `requireAuthenticated()`。
- 提交：`refactor(api): migrate remaining learning endpoints to platform kernel`

### 任务卡 B4：pass 域 5 个端点
文件：`api/pass/map.php`、`stage.php`、`certificate.php`、`voice-assess.php`、`voice-submit.php`。
- 这 5 个文件较大且含 AI 调用（voice-*），**只改外壳**（引入内核、鉴权、异常处理、响应发送），AI 与业务代码一行不动。
- domain：`pass`。
- 验收：全量对比零新增失败。
- 提交：`refactor(api): migrate pass endpoints to platform kernel`

### B5（明确不在本轮）：`api/drill/` 旧接口 13 处、`api/admin/` 各域——涉及 TrainingAccessPolicy 与细粒度权限串，判断成本高，留第三轮。

---

## 4. 工作流 C：大页面拆分（内联资源外置）

### 通用拆分方法（每张卡都按这个流程）
1. 读目标 HTML，统计 `<style>...</style>` 块与无 `src` 的 `<script>...</script>` 块。
2. 新建 `assets/pages/<页面名>.css`：依次粘贴所有 style 块内容（**按原顺序**，多个块之间加 `/* ---- 原第 N 个 style 块 ---- */` 分隔注释）。
3. 新建 `assets/pages/<页面名>.js`：依次粘贴所有内联 script 内容（按原顺序，块间加 `// ---- 原第 N 个 script 块 ----`）。`'use strict'` 若多个块都有，只在文件头保留一个。
4. HTML 中原位置替换为：
   - `<link rel="stylesheet" href="/assets/pages/<页面名>.css?v=r2">`
   - `<script src="/assets/pages/<页面名>.js?v=r2"></script>`（保持原 script 标签在文档中的相对位置：head 里的 style 原位替换；body 末尾的 script 原位替换）。
5. 自查三项：
   - `grep -c "document.write" <页面>.js` —— 若大于 0，**停止**，报告说明（document.write 外置会改变行为）。
   - 拆分后 HTML 中不得再有无 src 的 `<script>`（允许保留极短的 3 行以内内联，如重定向判断）。
   - `node --check assets/pages/<页面名>.js` 通过。
6. 验收：
   - `curl -s -o /dev/null -w "%{http_code}"` 本地预览 `python -m http.server 8001 --directory . ` 后访问该页面 → 200（如本机无 python 则跳过此步，改用 grep 验证引用路径存在）。
   - 全量测试对比零新增失败（部分页面有对应 contract 测试：`grep -rl "<页面名>" scripts/*.test.mjs` 找到并优先跑）。
7. 提交规范：`refactor(ui): extract inline assets from <页面名>`

### 任务卡 C1：`fitness-assessment-app.html`（216KB，最大）
目标产物：`assets/pages/fitness-assessment-app.css` + `assets/pages/fitness-assessment-app.js`。原 HTML 预计降到 <30KB。
注意：该页有 2091 行内联脚本，粘贴量大，**分多次 Edit 完成，每次以完整函数为边界**，避免截断。

### 任务卡 C2：`admin/workload.html`（144KB）
产物：`assets/pages/admin-workload.css/.js`。
注意：同目录 `admin/` 下引用路径写 `/assets/pages/...`（绝对路径，从站点根出发）。

### 任务卡 C3：`admin/staffs.html`（144KB）
产物：`assets/pages/admin-staffs.css/.js`。

### 任务卡 C4：`admin/recruitment-resumes.html`（60KB）与 `admin/wecom.html`（56KB）
两张小卡合并一次提交：`refactor(ui): extract inline assets from admin recruitment and wecom pages`

### 任务卡 C5：`mobile/workload-v2.html`（56KB）
产物：`assets/pages/workload-v2.css/.js`。
**特别验收**：`node --test scripts/` 中与 workload-v2 相关的测试（`grep -rl "workload-v2" scripts/*.test.mjs`）必须全过；该页是员工日报主页面，PWA 下次访问走网络加载新文件，无需改 sw.js（它不在 SHELL 白名单内，正常联网加载）。

### 明确不拆（不要碰）
`index.html`（对外官网，SEO 敏感）、`training/**`（内容页）、`showcase/**`、`training-cards/**`、`summer-camp-assessment-app.html` 与 `smart-lessons.html`（后续单独评估）。

---

## 5. 工作流 D：功能补齐与零散修复

### 任务卡 D1：小程序知识页补齐收藏/最近/风险筛选（规格 = 现成测试）
**验收标准就是让这两个现存失败测试通过**：
`node --test scripts/knowledge_employee_reading_experience.test.mjs`
其中这两个用例当前失败（基线旧疾，本轮修复）：
- `mini program knowledge pages are reading-oriented and expose favorite/recent/filter entry points`
- `mobile H5 knowledge pages share reading filters and remove learning completion flow`

步骤：
1. 通读上述测试文件第 43-100 行，逐条断言即功能清单（quickMode 快捷筛选、favorite=1/recent=1 模式、content_type 与 risk_level 筛选、7 个内容类型值 action/game/training_plan/teaching_organization/teaching_knowledge/assessment/safety、高风险等文案、`item.risk_class`、coach_growth、G01-G08、wxml 出现"高级筛选/我的收藏/最近浏览"、详情页 favorite.php/阅读信息/来源摘要/display_type_name 等）。
2. 修改 4 个文件实现上述断言：
   - `mini-program/pages/knowledge/list.js`（在第一轮已加 searchKeyword 清空的基础上扩展）
   - `mini-program/pages/knowledge/list.wxml`
   - `mini-program/pages/knowledge/detail.js`
   - `mini-program/pages/knowledge/detail.wxml`
3. 实现约束：
   - 列表请求复用现有 `/knowledge/list.php` 的查询参数：`favorite=1`、`recent=1`、`content_type=`、`risk_level=`（后端已支持，前端只管传参）。
   - 详情页收藏复用 `/api/knowledge/favorite.php`（H5 版 `mobile/knowledge-detail.html` 已有实现可参考其请求格式）。
   - 不得引入"学习进度/完成/积分"入口（断言明确 doesNotMatch）。
   - wxml 用现有样式类，不新造设计。
4. 跑测试直至上述两个用例 ✔，且同文件其余用例不退步。
- 提交：`feat(miniprogram): restore knowledge reading filters with favorite, recent and risk entries`

### 任务卡 D2：H5 知识页补齐（`mobile/knowledge.html`、`mobile/knowledge-detail.html`）
同 D1 的 H5 断言集（quick-tab、favorite=1、recent=1、content_type、risk_level、高级筛选、coach_growth 芯片、G01-G08 芯片、renderSafeTextContent 等）。参考桌面版 `knowledge/knowledge.js` 已有的筛选实现（它有完整的 primary_category/topic 逻辑），把交互移植为移动版交互。
- 验收：同 D1，两个用例中 H5 部分断言通过（同一测试文件）。
- 提交：`feat(mobile): restore H5 knowledge reading filters`

### 任务卡 D3：wecom 3 个端点错误分层
文件：`api/wecom/bind-member.php`、`unbind-member.php`、`retry-message.php`。
现状：单层 `catch (Throwable $e) { jsonResponse(1, $e->getMessage() ?: '服务器错误'); }`——业务异常（如"该成员已绑定其他账号"）与服务器故障混在一起。
改法（三文件相同）：
```php
} catch (RuntimeException $e) {
    error_log('[wecom.<端点>] ' . $e->getMessage());
    jsonResponse(1, $e->getMessage() !== '' ? $e->getMessage() : '操作失败');
} catch (Throwable $e) {
    error_log('[wecom.<端点>] ' . $e->getMessage());
    jsonResponse(500, '服务器错误，请稍后重试');
}
```
注意：`api/wecom/_common.php` 抛的 RuntimeException 既有业务规则也有上游接口失败，本分层按"RuntimeException=可读消息给操作者"处理，可接受。
- 验收：`node /tmp/scan-catch.mjs` 输出 CLEAN（wecom 三行消失）；`node --test scripts/platform_business_domain_migration.test.mjs` 不低于基线。
- 提交：`fix(wecom): split business and server error responses in member endpoints`

### 任务卡 D4：wecom 回调 POST 事件落库
文件：`api/wecom/callback.php`。现状 POST 分支只 `echo 'success'`（第 76-80 行）。
改法：POST 时先按 GET 分支同款方式验签（复用文件内已有的 `wecomCallbackVerifySignature`），验签失败 `wecomCallbackFail(403, ...)`；验签通过后读取 body（`file_get_contents('php://input')`），用已有 `wecomCallbackDecrypt` 解密（POST 的密文在 body，Encrypt 格式同 GET echostr；若解密抛异常，记录原始 body 长度并仍回 `success` 避免企微重试风暴），把解密后的 XML/文本截断到 2000 字符写入 `wecom_message_logs` 表（表已存在，字段参考 `api/wecom/_common.php` 中已有的写日志函数；若无直接可复用函数，则 `error_log('[wecom.callback] ' . substr($plain,0,2000))` 兜底，**不新建表**）。
- 验收：括号配平脚本通过；`grep -c "php://input" api/wecom/callback.php` ≥ 1。
- 提交：`feat(wecom): log decrypted callback events instead of silent success`

### 任务卡 D5：前端 cookie/storage 工具收敛
现状 4 份重复实现：`js/app-auth.js`、`internal-auth.js`、`internal.html`、`mobile/login.html`（均含 `function readCookie`）。
步骤：
1. 新建 `js/web-storage.js`：暴露全局 `window.WebStorage = { readCookie, writeCookie, clearCookie, readStoredValue, writeStoredValue, removeStoredValue }`，实现以 `js/app-auth.js` 中现有版本为准。
2. 四个文件删除本地实现，改调 `window.WebStorage.*`。注意：
   - `js/app-auth.js` 与 `internal-auth.js` 是认证核心链路，**改完必须跑**：`node --test scripts/platform_pwa_session_runtime.test.mjs scripts/platform_pwa_session_security.test.mjs scripts/internal_auth_navigation_contract.test.mjs scripts/mobile_pwa_shell.test.mjs`。
   - 页面引用顺序：`web-storage.js` 必须在 `app-auth.js`/`internal-auth.js` 之前加载。检查每个引用 app-auth.js 的 HTML（`grep -rln "js/app-auth" --include="*.html" . | grep -v assets`）同步加引用。
3. 兜底原则：若某页面难以保证加载顺序，允许该页面保留内联实现并在审计表标注（收敛率 >50% 即可接受，不强行 100%）。
- 提交：`refactor(ui): consolidate duplicated cookie and storage helpers into js/web-storage.js`

### 任务卡 D6：修复测试的 Windows 路径 bug
文件：`scripts/miniprogram_auth_proxy.test.mjs` 第 10 行 `const projectRoot = new URL('../', import.meta.url).pathname;` 在 Windows 产生百分号编码路径导致 4 个用例必挂。
改法：
```js
import { fileURLToPath } from 'node:url';
const projectRoot = fileURLToPath(new URL('../', import.meta.url));
```
再全库排查同款：`grep -rn "\.pathname" scripts/*.mjs | grep -v fileURLToPath`，凡用于拼文件路径的 `.pathname` 一并修复（只修测试/脚本，不修业务代码）。
- 验收：`node --test scripts/miniprogram_auth_proxy.test.mjs`——之前因 ENOENT 失败的用例（小程序绑定页不再跨页携带账号密码 等 4 个）转为通过或转为明确的断言结果；全量对比时**这些修复会让失败数下降，属预期**。
- 提交：`fix(tests): use fileURLToPath for Windows-safe project roots`

### 任务卡 D7：仓库缺口收口（需服务器权限，可选）
若执行环境可用 SSH 密钥（`E:\程序开发\追光小牛\zhuiguangxiaoniu.pem`，端口 2222）：
```bash
scp -i <pem> -P 2222 root@122.51.223.46:/www/wwwroot/122.51.223.46/common.php real_sync/common.php
scp -i <pem> -P 2222 -r root@122.51.223.46:/www/wwwroot/122.51.223.46/services real_sync/services
```
拉回后检查两个文件/目录内容是否含敏感信息（密码/密钥），无则提交：`chore(repo): mirror server-only common.php and services into repo`。**不可用 SSH 就跳过**，报告说明。

### 明确不做（第三轮）
`api/drill/` 旧接口迁移、`api/admin/` 域迁移、知识库全文索引、`summer-camp`/`smart-lessons` 大页拆分、`css/mobile.css` 与 `mobile-shell.css` 合并。

---

## 6. 执行顺序与依赖

```
基线准备(§0.4) → A0 → A1 → A2 → B1 → B2 → B3 → B4 → C1 → C2 → C3 → C4 → C5 → D1 → D2 → D3 → D4 → D5 → D6 → D7(可选)
```
- A 系列互不依赖但必须先做（B 的响应规范建立在 A0 文档上）。
- C 系列与 B/D 无依赖，但 C1 工作量大，安排在中段换脑。
- D1/D2 是同一测试文件验收，连续执行。
- 每完成 B1/B4/C5/D5（高风险点）跑一次全量对比。

## 7. 收尾交付（全部任务完成后）
1. 全量测试最终对比：`comm -13 /tmp/round2-base-fails.txt /tmp/round2-now-fails.txt` 必须为空，且失败总数应**下降**（D1/D2/D6 修复旧疾）。
2. 产出总结报告（对用户，中文）：每张卡的完成状态、测试数字变化、遗留项清单。
3. 工作日志：在 `docs/ops/` 新建 `2026-XX-XX-round2-execution-log.md` 记录每卡一行（日期/卡号/commit/验收结果）。
4. **不部署、不推送**——报告完成状态，等用户指令。

## 8. 快速参考
- 第一轮已建立的事实（勿重复劳动）：`wp_verify_password`/HTTP 客户端/角色映射唯一实现均在 `api/config.php`；`getStaffByUserId($id, true)` 仅限纯读路径；企微 token 缓存在 `ai_settings` 表 `wecom_access_token_cache`；sw.js v21 缓存键=路径名；生产备份目录 `.agent-backups/round1-opt-20261024-220900`。
- 已在新内核的 49 个端点分布域：admin/organization、admin/platform、admin/recruitment、admin/staff、auth、campaign、drill/v2、exam、knowledge、learning(部分)、lesson-*、platform、points(部分)。
- 大文件参考值：fitness-assessment-app 216KB / admin/workload 144KB / admin/staffs 144KB / admin/recruitment-resumes 60KB / admin/wecom 56KB / mobile/workload-v2 56KB。
