# 统一 AI 能力

`real_sync/api/platform/AiCapabilityGateway.php` 定义平台 AI 能力请求、审批、供应商路由、有限恢复、稳定错误和调用摘要契约。`real_sync/api/ai-runtime.php` 是当前权威装配入口，根目录 `real_sync/ai-runtime.php` 是薄兼容入口。体测、Drill v2 和招聘已通过领域 Adapter 接入。

## 能力

当前生产能力为：

- `text.generate`：报告、语义分析和演练回应
- `assessment.score`：演练、语音通关和技能复盘
- `vision.extract`：文档视觉提取
- `ocr.extract`：体测和招聘简历 OCR
- `speech.transcribe`：演练、技能复盘和语音评测

`image.generate` 仅作为架构扩展点。网关在审批和供应商路由前返回 `capability_unsupported`，调用摘要中的尝试次数为零。

## 请求与结果

`PlatformAiCapabilityGateway::invoke()` 接收固定契约版本、请求 ID、业务用途、数据分类、业务输入、首选供应商、总超时、最大尝试次数、幂等键、留存策略、可选留存时间和审批上下文。请求 ID 复用 `PlatformRequestContext` 的合法格式；最大尝试次数范围为 1 至 5，总超时范围为 100 至 120000 毫秒。

供应商执行器按名称注入，并返回 `model`、`processing_version` 和 `output`。成功结果关联能力、契约版本、请求 ID、requested/actual provider、模型、处理版本、耗时、尝试次数和 fallback 状态。网关只调用审批通过的供应商，默认审批策略允许公开和内部数据，个人、敏感及受限数据要求业务 Adapter 提供显式审批。

## 错误与恢复

稳定错误分类包括请求无效、能力不支持、审批拒绝、供应商未配置、供应商认证失败、限流、超时、传输失败、供应商不可用、响应无效和内部错误。限流、超时、传输失败及供应商不可用允许在请求总预算内重试；预算耗尽后返回 `recovery_required=true`，由业务 Worker 或 Adapter 映射为原有异步恢复状态。

审批拒绝和审批服务故障发生在供应商执行前。审批故障转换为 `internal_error` 并写入脱敏摘要；拒绝状态为 `rejected`，图片生成状态为 `unsupported`。

## 调用摘要

`PlatformPdoAiInvocationStore` 写入 `platform_ai_invocations`。记录包括调用键、请求与幂等身份、能力、用途、数据分类、路由、模型、契约和处理版本、状态、错误、恢复标志、尝试、耗时、审批及留存信息。

原始输入与输出只保存 SHA-256 和字节数。供应商错误通过 `PlatformSensitiveData::sanitize()` 转换为脱敏摘要，prompt、简历正文、OCR 文本、录音内容、Authorization 和供应商原始响应均不进入调用表。默认留存期为 180 天。

## Runtime 与消费者

权威 Runtime 当前注册 DeepSeek `text.generate` 与百度 `ocr.extract` 两条生产路由。`ai_gateway_text_generate()` 和 `ai_gateway_ocr_extract()` 默认拒绝个人数据处理，认证业务入口必须显式传入业务授权和审批标识。调用摘要优先写入 `platform_ai_invocations`；数据库暂不可用时，运行时只向服务日志写入同一组脱敏治理元数据。

体测 `action=ocr` 先通过百度 OCR 获取文字，再由 `ai_parse_fitness_ocr_text()` 确定性提取姓名、性别、年龄、日期、项目数值和图片原始评级。该步骤不调用 DeepSeek 或豆包视觉。体测 `action=plan` 与夏令营报告使用 DeepSeek 文本生成。OCR 业务日志只保存文字 SHA-256 与字节数。

Drill v2 继续通过 `DrillAiAdapter` 生成客户轮次。Adapter 调用 `text.generate`，保持 `content`、`intent`、供应商、实际模型、提示版本、耗时和哈希响应引用语义。

招聘 `RecruitmentPlatformAiAdapter` 固定使用 DeepSeek `text.generate`，并保留 16 字段、证据引用和提示注入防护契约；`RecruitmentPlatformOcrAdapter` 固定使用百度 `ocr.extract`。两者在调用 Runtime 前通过 `ExternalProcessorGateService` 校验已批准处理器和 `approval_id`，审批缺失时保持调用关闭。招聘调用使用敏感个人数据分类、稳定幂等键和 180 天留存策略。

## 验证

```bash
php -l api/platform/AiCapabilityGateway.php
php -l api/ai-runtime.php
php -l ai-runtime.php
php -l database/migration_catalog.php
node --test scripts/platform_ai_capability.test.mjs scripts/ai_runtime_convergence.test.mjs scripts/fitness_assessment_ocr.test.mjs scripts/drill_ai_evaluation_services.test.mjs
node --test scripts/migration_readiness.test.mjs scripts/migration_compatibility.test.mjs scripts/migration_compatibility.property.test.mjs
node scripts/platform_preflight.mjs
```

测试覆盖五类生产能力、完整结果元数据、审批拒绝、审批服务异常、有限重试、已审批 fallback、尝试预算、稳定错误、零调用图片生成、输入输出摘要、错误脱敏、双入口收口、体测职责边界、确定性 OCR 解析和 Drill v2 消费者迁移。正确性属性 8 遍历五类已启用能力，并验证每项成功结果通过请求 ID 唯一关联同能力、同契约版本、同处理版本、同供应商和同完成状态的调用摘要；审批回调同时收到用途、数据分类、留存策略和权限范围上下文。任务 11.5 的文件与 AI 定向回归为 41/41，平台契约回归为 98/98，全量 Node 回归为 930/930。
