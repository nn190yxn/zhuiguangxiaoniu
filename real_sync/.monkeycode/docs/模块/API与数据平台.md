# API 与数据平台

该模块为 Web 和小程序提供业务接口、认证授权、数据访问、异步任务、审计与 migration 能力。

## 结构

- `api/config.php`：环境配置、PDO、JWT 与基础请求工具。
- `api/kernel/`：API 核心基础设施。
- `api/platform/`：作业、同步、文件、AI、outbox 与健康能力。
- `api/{domain}/`：按工作量、学习、知识、考试、演练等业务域拆分的端点。
- `database/migrations/`：版本化 SQL migration。
- `database/migration_manifest.php`：migration 预期结构清单。

## 依赖

- MySQL 数据库。
- PHP PDO MySQL 扩展。
- 环境变量提供数据库密码和 JWT secret。
- WordPress 兼容用户表用于部分身份查询。

## 约定

- 受保护接口验证 JWT 和服务端权限。
- 写请求按业务需要执行幂等和状态版本检查。
- 管理操作记录审计信息。
- 数据结构变更通过新增 migration 推进。
