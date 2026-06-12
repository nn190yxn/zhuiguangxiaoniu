# 制度中心长期治理专项修复方案

## 1. 背景

用户反馈在 `https://supercalf.com/制度标准/` 搜索“转正”没有结果。排查确认：

- `/api/policy/search.php?keyword=转正&page=1&page_size=10` 登录后可返回 4 条制度。
- `/api/search/global.php?q=转正&type=制度` 登录后可返回 4 条制度。
- `/制度标准/` 页面原先使用静态卡片本地过滤，只搜索卡片标题和 `data-name`。
- “转正”存在于后端制度正文或关键词中，静态卡片上没有这个词，因此该入口搜不到。

已做生产热修：`/制度标准/index.html` 在本地卡片过滤后补查 `/api/policy/search.php?keyword=...`，把后端制度结果合并到搜索结果。该热修解决当前用户问题，但还不是长期数据源治理方案。

生产热修备份路径：

`/www/wwwroot/122.51.223.46/backup-policy-center-search-20260612/制度标准/index.html`

## 2. 治理目标

把 `/制度标准/` 从静态卡片页面治理为后端制度库驱动的统一制度中心，使以下入口使用同一套制度数据和搜索口径：

- 网页制度中心：`/制度标准/`
- H5 制度搜索：`/mobile/policy-search.html`
- 小程序制度搜索：`mini-program/pages/policy-search/index.*`
- 全局搜索制度分类：`/search.html`、`/api/search/global.php`
- 文档阅读器：`/doc-viewer.html`

治理完成后，制度新增或关键词调整应只需要维护后端制度数据，避免手工同步静态卡片。

## 3. 修复边界

### 3.1 本次纳入范围

- `/www/wwwroot/122.51.223.46/制度标准/index.html`
- `/www/wwwroot/122.51.223.46/api/policy/search.php`
- `/www/wwwroot/122.51.223.46/api/policy/detail.php`
- `/www/wwwroot/122.51.223.46/api/search/global.php`
- `/www/wwwroot/122.51.223.46/api/search/search-service.php`
- 本地仓库对应文件：`/workspace/real_sync/real_sync/api/policy/search.php`
- 本地仓库对应文件：`/workspace/real_sync/real_sync/api/policy/detail.php`
- 本地仓库对应文件：`/workspace/real_sync/real_sync/api/search/global.php`
- 本地仓库对应文件：`/workspace/real_sync/real_sync/api/search/search-service.php`

### 3.2 本次排除范围

- 制度后台新增、编辑、删除管理页面。
- 数据库结构大改。
- 权限模型重构。
- 小程序提审和微信后台配置。
- 非制度类知识库、课程、演练、考试搜索治理。
- 会员收费、自动续费、月卡、季卡、年卡等无关模块。

### 3.3 重要边界提醒

当前 GitHub 只追踪 `real_sync/page-zhidu-biaozhun.php`，没有追踪生产静态文件 `real_sync/制度标准/index.html`。生产真实入口是：

`/www/wwwroot/122.51.223.46/制度标准/index.html`

执行长期治理时必须先确定代码归口：

1. 推荐方案：把生产静态文件纳入仓库 `real_sync/制度标准/index.html` 管理。
2. 备选方案：统一回到 WordPress 模板 `page-zhidu-biaozhun.php` 生成制度中心页面。

两种方案只能选一种作为长期口径，避免 GitHub 模板和生产静态页继续分叉。

## 4. 当前关键事实

### 4.1 后端制度搜索可用

登录后搜索“转正”当前返回 4 条制度：

- 教练星级晋升体系
- 开关店SOP
- 新员工入职培训
- 薪酬结构

### 4.2 `/制度标准/` 页面原始缺陷

原页面过滤逻辑类似：

```javascript
const matched = allCards.filter(card => {
  const haystack = (card.dataset.name + ' ' + card.textContent).toLowerCase();
  return haystack.includes(keyword);
});
```

该逻辑只能命中静态 DOM 卡片中的可见文本和 `data-name`，无法命中后端 `policies.content`、`policies.keywords`、`policies.doc_key`。

### 4.3 生产热修状态

生产 `/制度标准/index.html` 已临时补查 `/api/policy/search.php`，能让“转正”出现在当前入口的搜索结果中。

热修验证：模拟输入“转正”后前端显示 `4 项制度`，包含“教练星级晋升体系”。

## 5. 长期技术方案

### 5.1 数据源统一

以 `policies` 表作为制度中心唯一主数据源。前端页面不再依赖静态卡片作为真实制度清单。

制度卡片字段应至少来自：

- `id`
- `title`
- `doc_key`
- `category`
- `workflow`
- `keywords`
- `version`
- `is_need_confirm`
- `updated_at`

### 5.2 接口统一

`/api/policy/search.php` 作为制度中心主接口，支持两类模式：

- 列表模式：无关键词时返回制度列表。
- 搜索模式：有关键词时按标题、正文、关键词、分类、流程、`doc_key` 搜索。

接口语义：

- 未登录返回 HTTP `401`。
- 登录成功且请求有效返回 `code=0`。
- 不支持的方法返回 `405` 或现有统一错误结构。
- 不产生写库行为。

### 5.3 页面统一

`/制度标准/` 页面改为 API 驱动：

1. 页面加载时调用 `/api/policy/search.php?page=1&page_size=50`。
2. 前端按 `category`、`workflow` 渲染角色与工作流分组。
3. 搜索时调用 `/api/policy/search.php?keyword=...&page=1&page_size=50`。
4. 搜索结果点击优先打开 `/doc-viewer.html?doc={doc_key}`。
5. 无 `doc_key` 时打开 `/mobile/policy-detail.html?id={id}`。
6. 接口失败时展示错误状态，并保留静态兜底卡片。
7. 登录态失效时跳转 `/mobile/login.html?redirect=/制度标准/`。

### 5.4 搜索建议词

空结果时建议用户尝试：

- 转正
- 体测
- 绩效
- 请假
- 门店
- 薪酬
- 入职
- 晋升

## 6. 执行步骤

### 6.1 现状固化

1. 记录当前 Git 状态。
2. 备份生产 `/制度标准/index.html`。
3. 备份相关接口文件。
4. 导出或只读检查 `policies` 表中 `title/category/workflow/keywords/doc_key` 字段覆盖情况。
5. 记录治理前搜索结果基线。

推荐基线关键词：

- 转正
- 体测
- 绩效
- 请假
- 门店
- 薪酬
- 入职
- 晋升

### 6.2 数据检查

检查 `policies` 表：

- 是否存在制度标题为空。
- 是否存在 `doc_key` 缺失。
- 是否存在 `keywords` 缺失。
- 是否存在 `category` 或 `workflow` 为空。
- “教练星级晋升体系”“开关店SOP”“新员工入职培训”“薪酬结构”是否包含“转正”相关关键词或正文。

数据修补原则：

- 优先补 `keywords`。
- 不随意改制度正文。
- 不删除历史制度。
- 不改业务口径。

### 6.3 接口改造

检查并必要时调整 `/api/policy/search.php`：

- 确保空关键词可返回列表。
- 确保关键词搜索覆盖 `title/keywords/category/workflow/content/doc_key`。
- 确保返回字段稳定。
- 确保未登录返回 HTTP `401`。
- 确保 GET 请求不写库。

检查并必要时调整 `/api/search/search-service.php`：

- 确保制度分类搜索与 `/api/policy/search.php` 的字段口径一致。
- 确保 `q=转正&type=制度` 能返回制度结果。
- 不引入与小程序冲突的新参数名。

### 6.4 前端改造

推荐把生产静态页面纳入仓库：

`/workspace/real_sync/real_sync/制度标准/index.html`

前端实现要求：

- 页面启动后从接口加载制度列表。
- 搜索统一调用后端接口。
- 保留静态兜底数据，接口失败时显示兜底卡片和错误提示。
- 结果点击按 `doc_key` 优先打开文档阅读器。
- 接口返回 `401` 时跳登录。
- 空结果展示建议词。
- 移动端布局不溢出。

### 6.5 生产部署

部署顺序：

1. 接口文件。
2. 制度中心页面。
3. 线上只读接口回归。
4. 浏览器页面回归。
5. 手机 H5 回归。

## 7. 备份方案

生产改动前执行备份，日期按实际执行日替换。

```bash
# 创建制度中心备份目录
mkdir -p /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/制度标准

# 备份制度中心页面
cp /www/wwwroot/122.51.223.46/制度标准/index.html /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/制度标准/index.html

# 创建制度接口备份目录
mkdir -p /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/policy

# 备份制度接口
cp /www/wwwroot/122.51.223.46/api/policy/search.php /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/policy/search.php
cp /www/wwwroot/122.51.223.46/api/policy/detail.php /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/policy/detail.php

# 创建全局搜索接口备份目录
mkdir -p /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/search

# 备份全局搜索接口
cp /www/wwwroot/122.51.223.46/api/search/global.php /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/search/global.php
cp /www/wwwroot/122.51.223.46/api/search/search-service.php /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/search/search-service.php
```

## 8. 回滚方案

页面回滚：

```bash
# 回滚制度中心页面
cp /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/制度标准/index.html /www/wwwroot/122.51.223.46/制度标准/index.html
```

接口回滚：

```bash
# 回滚制度接口
cp /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/policy/search.php /www/wwwroot/122.51.223.46/api/policy/search.php
cp /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/policy/detail.php /www/wwwroot/122.51.223.46/api/policy/detail.php

# 回滚全局搜索接口
cp /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/search/global.php /www/wwwroot/122.51.223.46/api/search/global.php
cp /www/wwwroot/122.51.223.46/backup-policy-center-governance-YYYYMMDD/api/search/search-service.php /www/wwwroot/122.51.223.46/api/search/search-service.php
```

回滚后验证：

- `/制度标准/` 返回 `200`。
- 登录后 `/api/policy/search.php?keyword=转正&page=1&page_size=10` 返回 `code=0`。
- 登录后 `/doc-viewer.html?doc=v4-01` 能打开正文。

## 9. 回测清单

### 9.1 接口回测

- 未登录 `/api/policy/search.php?keyword=转正&page=1&page_size=10` 返回 `401`。
- 登录后 `/api/policy/search.php?keyword=转正&page=1&page_size=10` 返回 4 条左右结果。
- 登录后搜索 `体测` 有结果。
- 登录后搜索 `绩效` 有结果。
- 登录后搜索 `请假` 有结果。
- 登录后搜索 `门店` 有结果。
- 登录后搜索 `薪酬` 有结果。
- 登录后搜索 `入职` 有结果。
- 登录后搜索 `晋升` 有结果。
- 登录后空关键词能返回制度列表。
- `category` 筛选有效。
- `workflow` 筛选有效。
- GET 请求不写库。

### 9.2 页面回测

- `/制度标准/` 返回 `200`。
- 初始制度列表正常显示。
- 搜索 `转正` 显示制度结果。
- 搜索 `体测` 显示制度结果。
- 搜索 `绩效` 显示制度结果。
- 搜索 `请假` 显示制度结果。
- 搜索结果点击能进入 `/doc-viewer.html` 或 `/mobile/policy-detail.html`。
- 清空搜索后恢复默认分组。
- 角色分组切换正常。
- 工作流分组切换正常。
- 接口失败时显示错误状态或兜底卡片。
- 登录态失效时跳转登录。
- 手机窄屏布局不溢出。

### 9.3 相邻功能回测

- `/mobile/policy-search.html` 搜索正常。
- 小程序制度搜索正常。
- `/search.html?q=转正` 全局搜索正常。
- `/doc-viewer.html?doc=v4-01` 文档读取正常。
- `/api/doc-content.php?doc=v4-01` 鉴权正常。

### 9.4 生产观察

观察 24 到 72 小时：

- 搜索接口是否出现 `5xx`。
- `/制度标准/` 是否出现页面加载异常。
- `/api/policy/search.php` 是否出现明显慢查询。
- 用户是否继续反馈制度关键词搜不到。

## 10. 验收标准

满足以下条件后，可认为制度中心长期治理完成：

- `/制度标准/` 搜索 `转正/体测/绩效/请假/门店/薪酬/入职/晋升` 均能命中后端制度库结果。
- 网页制度中心、H5 制度搜索、小程序制度搜索、全局搜索的制度结果口径一致。
- 生产文件与 GitHub 文件存在明确同步关系。
- 新增制度时只需维护后端制度记录和关键词，无需手工改静态卡片。
- 生产备份可以按本文命令回滚页面和接口。
- 修复后没有新增 `404/5xx`。
- 登录态失效时前端跳登录，不显示假空结果。

## 11. 建议执行顺序

1. 先确定 `/制度标准/` 长期代码归口。
2. 备份生产页面和接口。
3. 只读导出制度数据覆盖情况。
4. 补齐必要关键词。
5. 改造 `/制度标准/` 为 API 驱动。
6. 复核 `/api/policy/search.php` 和全局搜索制度分类一致性。
7. 部署生产并回测。
8. 提交 GitHub。
9. 观察 24 到 72 小时。
