# 2026-09-05 本轮升级交接说明

## 生产位置

- 服务器：`root@122.51.223.46`
- 网站目录：`/www/wwwroot/122.51.223.46/`
- 本轮备份：`/www/wwwroot/122.51.223.46/.agent-backups/release-20260905T-deploy-ui-final/`
- GitHub 仓库：`https://github.com/nn190yxn/zhuiguangxiaoniu`

## 本轮完成内容

### 知识中心

- 修复专业知识分类筛选，分类值统一使用版本化 taxonomy 编码。
- `教练成长` 使用 `coach_growth` 编码，并支持生产域编码兼容查询。
- 后端知识列表服务支持 `subcategory_code` 精确筛选。
- 知识卡统一使用浅色卡片背景和深色文字，提升黑底环境下的可读性。
- 移除知识中心标题区域的固定 `padding-right: 620px`，修复桌面布局偏移和右侧空白。
- 移动知识库增加“游戏”内容类型筛选。
- 游戏卡优先显示“游戏”标签，不再统一显示为“知识卡”。

### 游戏知识卡数据核验

- 本地二期 1417 张知识卡中，游戏卡 564 张。
- 生产已发布知识卡中，游戏卡 564 张。
- 游戏卡数据已完成导入，问题来自前端筛选项和展示标签。

### 学习中心与教案库

- 学习中心课程 API 返回非零状态时展示可读错误信息，并结束加载状态。
- 网络异常时展示明确的服务提示，避免页面长期停留在“加载中”。
- 教案库标题字号收窄，改善移动端标题拥挤。
- 预览环境无法连接生产课程 API 时展示明确提示。

### 导航与链接

- 已检查本轮目标页面，没有残留 `8001...monkeycode-ai.online/fitness-assessment.html` 这类错误绝对地址。
- 体测入口继续使用同源 `/fitness-assessment.html` 路径。

## 本轮部署文件

- `api/knowledge/KnowledgeListService.php`
- `knowledge/knowledge.js`
- `knowledge/index.html`
- `js/lesson-library.js`
- `lesson-library.html`
- `mobile/knowledge.html`
- `mobile/learning.html`

## 验证记录

- 知识分类、知识桌面页、员工端知识阅读、教案库和响应式契约测试通过。
- 本轮知识相关测试：10 项通过，1 项环境性跳过。
- 教案库、知识中心和响应式页面契约测试：12 项全部通过。
- PHP 语法检查通过：`api/knowledge/KnowledgeListService.php`。
- 前端 JavaScript 语法检查通过：`knowledge/knowledge.js`、`js/lesson-library.js`。
- `git diff --check` 通过。
- 本地静态预览服务使用端口 `8001`。

## 发布与回滚

发布前已备份本轮目标文件。需要回滚时，将备份目录中的文件按原路径复制回网站目录，然后重新执行 PHP 语法检查和页面冒烟检查。

本轮只覆盖上述白名单文件，没有删除服务器独有文件，也没有执行数据库删除操作。

## 后续交接重点

- 生产知识卡数据以数据库 `knowledge_items` 的 `status=1 AND publication_status='published'` 为准。
- 二期旧知识卡包为 1417 张，游戏卡数量为 564 张。
- 服务器工作区存在历史未提交改动和临时文件，后续同步必须继续采用白名单和备份方式。
- GitHub 同步应以本轮部署后的服务器运行文件和本交接文档为基准，避免提交服务器备份目录和临时文件。
