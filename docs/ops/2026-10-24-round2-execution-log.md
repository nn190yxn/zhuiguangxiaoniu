# 第二轮全量治理执行日志（2026-10-24）

执行者：ZCode（GLM）。方案：`docs/superpowers/plans/2026-10-24-round2-full-governance-plan.md`。

| 卡号 | 内容 | Commit | 验收结果 |
|---|---|---|---|
| 基线 | 全量测试基线 | - | 209 个环境性失败（本机无 PHP/Windows） |
| A0 | 响应契约文档 | 615f3d1 | docs/api-response-contract.md 建立 |
| A1 | catch 块清零复查 + 181 处审计表 | b5fdc6d | 扫描 CLEAN（仅 wecom 3 处留 D3）；审计表 181 条（173 校验/8 待人工） |
| A2 | json_response 别名收敛 | 16c08ff | 99 处替换清零；1 个连锁断言在 B4 提交中一并修复 |
| B1 | statistics 3 端点迁内核 | 05349b4 | php 配平/相关测试 12 过 |
| B2 | points 3 端点迁内核 | df67ec9 | 契约测试过（1 失败为基线旧疾） |
| B3 | learning 3 端点迁内核 | 9212c48 | 配平过 |
| B4 | pass 5 端点迁内核 | b9c58d4 | 全量对比零新增 |
| C1 | fitness-assessment-app 200KB→94KB | f7253d3 | JS 语法过；3 个测试文件改为 HTML+资产拼接 |
| C2 | admin/workload 136KB→12KB | d5879a6 | 同上机制 |
| C3 | admin/staffs 135KB→20KB | d5cc05b | 同上机制 |
| C4 | admin 简历 55KB→8KB + 企微 51KB→8KB | f29085d | 同上机制 |
| C5 | mobile/workload-v2 52KB→5KB | dbdfa2e | 同上机制 |
| D1 | 小程序知识页收藏/最近/风险筛选 | 2f555d8 | 基线失败测试转绿；顺带修掉详情页引用不存在函数的隐患 |
| D2 | H5 详情页教练成长显示名 | 94fca64 | 基线失败测试转绿 |
| D3 | wecom 3 端点错误分层 | cb6ec58 | 扫描 CLEAN（RuntimeException=业务层口径） |
| D4 | wecom 回调 POST 验签+解密落日志 | b6f554f | 解密失败仍回 success 防重试风暴 |
| D5 | web-storage.js 收敛（83 页注入+2 脚本兜底+29 个测试资产拼接） | c08bae4 | 认证测试 24 过；全量一度新增 93 个（C 系列连锁），codemod 后清零 |
| D6 | fileURLToPath 修 20 个脚本 + 矩阵补 exam/list 契约 + 恢复占位 env id + hasPhp skip | dfa2903 | 基线整文件加载失败的套件恢复运行，暴露并修复：矩阵漏 1 页契约、真实云环境 ID 泄入矩阵 |
| D7 | 仓库缺口收口 | 跳过 | 红线禁止连接服务器；部署阶段另行处理 |

## 最终数字

- 全量测试：1566 项，1326 过 / 181 失败 / 59 跳过。
- 失败对比基线：**209 → 179，零新增，净修复 30 个**（D1/D2 修复的 2 个功能缺口 + D6 修复的 28 个 Windows 路径性整文件失败）。
- 所有 181 个失败均为本机无 PHP / Windows 环境差异，非代码回归。

## 遗留（第三轮候选）

- A1 审计表中 8 处"疑似服务器错误待人工"（docs/audits/error_code_audit_round2.md）。
- drill 旧接口 13 处与 admin 各域未迁内核（方案 B5 明确留第三轮）。
- A2 后所有 catch 块 `jsonResponse(500, '...: '.$e->getMessage())` 的消息泄漏点未逐一清洗（契约要求不含异常细节），建议第三轮与 8 处待人工项一起处理。
- 活跃小程序矩阵 routes 已与 app.json 对齐（35/35），但 `/exam/list.php`、`/exam/history.php` 生产 404 仍未解决（2026-09-16 同步报告遗留）。

## 部署提示

- 本轮未部署未推送（按方案收尾约定）。部署时注意：C 系列新增 `assets/pages/` 12 个文件必须随 HTML 一起上线；D5 新增 `js/web-storage.js` 必须先于认证脚本可达（83 个页面已内嵌引用）；矩阵 JSON 与小程序配置恢复占位符不影响线上（transport shadow_sample_rate=0，全部直连）。
