# 工作量凭证链路验收与测试说明

## 1. 适用范围

本文档用于说明“工作量日报凭证上传链路”的当前功能边界、验收步骤、脚本测试方式和异常口径。

当前覆盖对象：

1. H5 工作量页：`/mobile/workload.html`
2. H5 工作量页新版入口：`/mobile/workload-v2.html`
3. 小程序工作量页：`/mini-program/pages/workload/index`
4. 后端接口：`/api/workload/template.php`
5. 后端接口：`/api/workload/my-report.php`
6. 后端接口：`/api/workload/save-report.php`
7. 后端接口：`/api/workload/evidence-upload.php`
8. 后端接口：`/api/workload/evidence-list.php`
9. 后端接口：`/api/workload/evidence-delete.php`

## 2. 当前功能口径

### 2.1 凭证规则来源

工作量凭证规则来自 `workload_metric_rules`。

当前前后端统一按模板接口返回的规则执行：

1. `need_evidence`
2. `min_evidence_count`
3. `max_evidence_count`

### 2.2 最大上传数统一口径

单个指标的凭证图片上传最大数统一限制在 `10` 张以内。

该限制当前在三层同时生效：

1. 模板返回值层：`template.php` 返回给前端的 `max_evidence_count` 已被压到不超过 `10`
2. 前端上传层：H5 和小程序达到上限后不允许继续上传
3. 后端接口层：`evidence-upload.php` 达到上限后直接拒绝第 `11` 张

### 2.3 最小上传数统一口径

正式提交 `submitted` 时，若某个 `need_evidence=1` 且本次填写值大于 `0` 的指标未达到最少凭证数量，提交会被拒绝。

该限制当前在两层同时生效：

1. 前端提交前校验
2. 后端 `save-report.php` 强校验

### 2.4 删除口径

当前支持删除凭证，但存在以下限制：

1. 本人可删除自己的凭证
2. 可看本店的店长可删除本店凭证
3. HQ / 全量权限角色可删除凭证
4. 非 HQ 在日报已 `submitted` 后不能删除凭证

### 2.5 当前前端体验边界

H5 当前已支持：

1. 上传图片
2. 删除图片
3. 站内预览图片
4. 每项显示 `已上传 X / 最多 Y 张`
5. 草稿态黄色提示块展示“还差几张”

小程序当前已支持：

1. 上传图片
2. 删除图片
3. 图片预览
4. 每项显示 `已上传 X / 最多 Y 张`
5. 页面内黄色提示块展示“还差几张”

## 3. 当前已接入的默认凭证指标

当前默认要求上传凭证的指标包括：

### 3.1 销售

1. `sales_calls`
2. `sales_moments`
3. `sales_douyin_review`
4. `sales_meituan_review`

### 3.2 教练

1. `coach_body_test`
2. `coach_motion_plan`
3. `coach_parent_comm`
4. `coach_moments`
5. `coach_douyin_review`
6. `coach_meituan_review`

## 4. 手工验收清单

### 4.1 H5 验收

使用销售或教练账号登录后，打开：

1. `/mobile/workload.html`
2. `/mobile/workload-v2.html`

逐项确认：

1. 需要凭证的指标会展示“图片凭证”区块
2. 区块内会显示：`已上传 X / 最多 Y 张`
3. 区块内会显示：`需上传凭证 A-B 张`
4. 草稿态下，若缺图，页面顶部会出现黄色提示块
5. 点击 `上传图片` 可正常选择图片并上传
6. 点击 `查看` 可在页内弹层预览图片
7. 点击 `删除` 可删除草稿态凭证
8. 达到 10 张后继续上传会被阻止
9. 未达到最少张数时点击“提交日报”会被阻止
10. 满足最少张数后点击“提交日报”可成功

### 4.2 小程序验收

使用销售或教练账号登录后，打开工作量日报页。

逐项确认：

1. 需要凭证的指标会展示“图片凭证”区块
2. 页面顶部会显示黄色缺口提示块
3. 每项会显示：`已上传 X / 最多 Y 张`
4. 上传图片后图片列表会刷新
5. 点击 `查看` 可预览图片
6. 点击 `删除` 可删除草稿态凭证
7. 达到 10 张后继续上传会被阻止
8. 未达到最少张数时提交会被阻止
9. 满足规则后提交成功

### 4.3 后端接口验收

建议至少验证以下场景：

1. `evidence-upload.php` 在第 `11` 张时返回失败
2. `save-report.php` 在缺图时返回失败
3. `save-report.php` 在图数满足时返回成功
4. `evidence-delete.php` 在已提交状态下对普通员工返回失败
5. `evidence-delete.php` 在草稿状态下对本人返回成功

## 5. 回归测试脚本

### 5.1 最小规则脚本

文件：`real_sync/scripts/test_workload_evidence_requirement.php`

用途：

1. 验证缺图提交失败
2. 验证上传 1 张后提交成功

### 5.2 专项回归脚本

文件：`real_sync/scripts/test_workload_evidence_regression.php`

用途：

1. 验证草稿创建成功
2. 验证缺图提交失败
3. 验证连续上传 10 张成功
4. 验证第 11 张上传失败
5. 验证已满足规则后正式提交成功
6. 验证已提交状态删除失败
7. 验证回到草稿后删除成功
8. 验证删除 1 张后剩余 `9` 张，仍可再次提交

### 5.3 建议执行方式

在真实服务器或与真实环境一致的环境中执行：

```bash
# 最小规则验证
php /workspace/real_sync/scripts/test_workload_evidence_requirement.php

# 专项回归验证
php /workspace/real_sync/scripts/test_workload_evidence_regression.php
```

若需要指定手机号测试账号：

```bash
# 指定测试账号手机号
php /workspace/real_sync/scripts/test_workload_evidence_regression.php 18385534850
```

## 6. 常见失败口径

当前常见失败提示包括：

1. `该指标无需上传凭证`
2. `该指标最多只能上传 10 张凭证图片`
3. `体测人数 至少需要上传 1 张凭证图片`
4. `日报已提交，当前不允许删除凭证`
5. `无权限删除该凭证`

若出现以下情况，应优先按对应方向排查：

1. 上传按钮可点但无回显
说明：优先检查 `evidence-list.php` 返回和前端列表刷新逻辑

2. 前端提示可提交，但后端仍拒绝
说明：优先检查数据库中实际凭证数量、是否传到了错误指标码、是否存在跨日报引用

3. 已提交后仍能删除
说明：优先检查 `evidence-delete.php` 是否已部署到现网

4. 第 11 张仍能上传
说明：优先检查 `evidence-upload.php` 是否已部署到现网，或模板返回是否仍为旧缓存

## 7. 现网自动化复核记录

2026-05-18 已基于现网真实接口执行一轮最小闭环与增强校验，口径如下：

1. 测试账号：教练 `孙志友 / 18385534850`
2. 认证方式：服务端临时 JWT
3. 测试日期：`2026-05-18`
4. 测试门店：`store_id=3`
5. 测试指标：`coach_body_test`

结果如下：

1. 草稿保存成功：`report_id=24`
2. 未上传凭证直接正式提交失败：`体测人数 至少需要上传 1 张凭证图片`
3. 上传 1 张凭证成功后正式提交成功：`submit_status=submitted`
4. 已提交后删除凭证失败：`日报已提交，当前不允许删除凭证`
5. 当前模板上限为 `3` 张，第 4 张上传失败：`该指标最多只能上传 3 张凭证图片`

## 8. 当前已知边界

当前仍属于“完整可用版”，但还不是最终豪华版。

当前未覆盖或未增强的部分：

1. H5 预览层不支持左右切图
2. 小程序预览当前按单图打开，不是同组轮播
3. 删除凭证后不会即时重算后台审核任务，需再次保存/提交时重走审核逻辑
4. 目前未提供凭证排序能力
5. 目前未提供凭证备注能力

## 9. 建议交接说明

若后续由其他人继续维护该链路，建议先确认以下三件事：

1. 现网是否已同步部署以下文件：
   - `/api/workload/evidence-upload.php`
   - `/api/workload/evidence-list.php`
   - `/api/workload/evidence-delete.php`
   - `/api/workload/save-report.php`
   - `/mobile/workload.html`
   - `/mobile/workload-v2.html`

2. 先执行回归脚本，再做人工点击验收

3. 若未来要改 `workload_metric_rules.max_evidence_count`，必须知道当前系统会自动把该值压到不超过 `10`
