# 小程序每日提醒与数据一致性核对

## 1. 结论速览

### 1.1 每日提醒

- 当前项目已经具备站内提醒能力：小程序首页待办与通知列表已经接入。
- 当前项目还没有微信订阅消息能力：代码中未发现 `requestSubscribeMessage`、订阅消息发送接口、订阅模板配置、提醒任务表。
- 每日推送到手机可以实现，建议基于微信订阅消息补齐一套提醒链路。

### 1.2 数据一致性

- 工作量日报已经确认是同一套后端、同一套表，小程序提交后，网站后台工作量中心可见。
- 学习、知识、演练、考试数据也已经确认走统一后端落库。
- 后台对工作量已经有完整管理视图，对学习已有汇总视图，细粒度明细能力可以继续补强。

## 2. 数据一致性核对清单

### 2.1 工作量日报

#### 小程序写入路径

- 小程序页面：[real_sync/mini-program/pages/workload/index.js](/tmp/opencode/zhuiguangxiaoniu/real_sync/mini-program/pages/workload/index.js:117)
- 提交接口：[real_sync/api/workload/save-report.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/save-report.php:92)
- 凭证上传接口：[real_sync/api/workload/evidence-upload.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/evidence-upload.php:104)
- 凭证列表接口：[real_sync/api/workload/evidence-list.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/evidence-list.php:14)

#### 后台读取路径

- 工作量中心页面：[real_sync/admin/workload.html](/tmp/opencode/zhuiguangxiaoniu/real_sync/admin/workload.html:130)
- 门店汇总接口：[real_sync/api/workload/store-summary.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/store-summary.php:16)
- 员工明细接口：[real_sync/api/workload/staff-detail.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/staff-detail.php:43)
- 员工活动接口：[real_sync/api/workload/staff-activity.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/staff-activity.php:108)

#### 实际落库表

- `workload_daily_reports`
- `workload_daily_report_values`
- `workload_evidences`
- `workload_audit_tasks`

表结构入口：
- [real_sync/api/workload/_common.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/workload/_common.php:60)

#### 核对结论

- 小程序提交工作量后，后台管理端可以读取到同一份日报记录。
- 小程序上传凭证图片后，后台管理端可以读取到同一份凭证记录。
- 这部分已经满足“同库同表同步一致”。

#### 验收步骤

1. 使用销售或教练账号在小程序提交一份当天工作量日报。
2. 在后台工作量中心按同一天、同门店打开门店汇总。
3. 打开该员工明细抽屉，确认以下字段一致：
   - 提交状态
   - 指标值
   - 备注
   - 凭证图片数量
4. 在小程序修改草稿后再次保存，后台刷新后确认更新立即可见。

### 2.2 课程学习

#### 小程序写入路径

- 章节读取与进度更新：[real_sync/api/learning/lesson.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/learning/lesson.php:41)
- 课程列表读取用户进度：[real_sync/api/learning/list.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/learning/list.php:44)

#### 实际落库表

- `user_lesson_progress`
- `user_course_progress`

#### 后台读取路径

- 学习汇总页面：[real_sync/admin/learning.html](/tmp/opencode/zhuiguangxiaoniu/real_sync/admin/learning.html:215)

#### 核对结论

- 小程序课程学习进度与后台学习汇总走统一后端数据源。
- 后台当前以汇总视图为主，适合确认总量与完成率。

#### 验收步骤

1. 小程序打开一个课程章节，完成课程学习。
2. 打开后台学习汇总，确认课程完成数量有增量。
3. 按员工排行和员工明细确认该员工课程完成统计同步更新。

### 2.3 知识卡学习

#### 小程序写入路径

- 知识完成提交：[real_sync/api/knowledge/progress.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/knowledge/progress.php:60)
- 知识列表读取完成态：[real_sync/api/knowledge/list.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/knowledge/list.php:116)

#### 实际落库表

- `user_knowledge_progress`

#### 后台读取路径

- 学习汇总页面：[real_sync/admin/learning.html](/tmp/opencode/zhuiguangxiaoniu/real_sync/admin/learning.html:266)

#### 核对结论

- 小程序知识卡完成记录与后台学习汇总使用同一张进度表。

#### 验收步骤

1. 小程序打开知识详情页并点击“标记完成”。
2. 再次进入知识列表，确认完成态已变化。
3. 打开后台学习汇总，确认知识完成统计同步增长。

### 2.4 演练任务

#### 小程序写入路径

- 演练详情读取任务：[real_sync/api/drill/detail.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/drill/detail.php:35)
- 步骤提交：[real_sync/api/drill/step.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/drill/step.php:40)
- 录音上传：[real_sync/api/drill/upload-recording.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/drill/upload-recording.php:81)

#### 实际落库表

- `user_drill_tasks`

#### 后台读取路径

- 学习汇总页面统计演练完成数量：[real_sync/admin/learning.html](/tmp/opencode/zhuiguangxiaoniu/real_sync/admin/learning.html:267)

#### 核对结论

- 演练任务进度与后台学习汇总也已统一。

### 2.5 考试

#### 小程序写入路径

- 考试暂存：[real_sync/mini-program/pages/exam/exam.js](/tmp/opencode/zhuiguangxiaoniu/real_sync/mini-program/pages/exam/exam.js:494)
- 考试提交接口：`/api/exam/submit.php`

#### 后台读取路径

- 学习汇总中已有平均考试分字段：[real_sync/admin/learning.html](/tmp/opencode/zhuiguangxiaoniu/real_sync/admin/learning.html:269)

#### 核对结论

- 考试结果已经进入后台学习汇总聚合口径。
- 如需逐份试卷级明细，后台还需要补专门的考试明细页。

## 3. 当前提醒能力盘点

### 3.1 已有能力

- 小程序首页待办接口：[real_sync/api/todos/my.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/todos/my.php:143)
- 制度通知接口：[real_sync/api/policy/notify.php](/tmp/opencode/zhuiguangxiaoniu/real_sync/api/policy/notify.php:25)

#### 当前可提醒内容

- 工作量日报未提交
- 工作量凭证未补齐
- 制度通知未读
- 制度通知待确认

#### 当前呈现位置

- 首页“今日待办”
- 首页“最新通知”
- 通知列表页

### 3.2 缺失能力

- 微信订阅消息授权
- 提醒规则配置
- 定时提醒任务
- 提醒日志与失败重试
- 自定义提醒内容模板
- 学习提醒自动生成逻辑

## 4. 每日提醒落地方案

### 4.1 目标

实现两类提醒：

- 工作量提醒
- 学习提醒

支持：

- 每日定时发送到手机
- 管理端配置提醒时间
- 管理端配置提醒文案
- 按角色、门店或个人定向发送

### 4.2 建议技术方案

#### 方案主体

- 小程序端：微信订阅消息授权 + 提醒偏好设置页
- 后端：提醒规则表 + 提醒任务表 + 定时发送脚本
- 管理端：提醒规则配置页面 + 发送日志页面

#### 小程序端新增能力

1. 订阅授权
   - 使用 `wx.requestSubscribeMessage`
   - 在首次进入工作量页、学习页、提醒设置页时引导订阅
2. 提醒设置页
   - 开关工作量提醒
   - 开关学习提醒
   - 设置提醒时间
   - 选择提醒内容类型
3. 本地状态展示
   - 已订阅模板
   - 已开启的提醒项
   - 最近一次发送结果

#### 后端新增能力

建议新增表：

- `mini_subscribe_templates`
  - 模板编号
  - 模板类型
  - 启用状态
- `mini_user_subscriptions`
  - user_id
  - template_key
  - 授权状态
  - 最近授权时间
- `mini_reminder_rules`
  - 规则名称
  - 规则类型 `workload` / `learning`
  - 发送时间
  - 目标范围 `all` / `role` / `store` / `staff`
  - 文案模板
  - 启用状态
- `mini_reminder_jobs`
  - 规则ID
  - 用户ID
  - 计划发送时间
  - 发送状态
  - 失败原因
  - 重试次数

建议新增接口：

- `GET /api/reminder/settings.php`
- `POST /api/reminder/settings.php`
- `POST /api/reminder/subscribe.php`
- `GET /api/admin/reminder/rules.php`
- `POST /api/admin/reminder/rules.php`
- `GET /api/admin/reminder/logs.php`

#### 定时任务建议

新增 cron 任务，每 5 分钟执行一次：

- 扫描当前时段应发送的提醒规则
- 根据规则筛选目标员工
- 检查是否已授权订阅消息
- 生成提醒任务
- 调微信接口发送
- 写发送日志

### 4.3 提醒内容设计

#### 工作量提醒

触发条件：

- 当天未创建日报
- 当天日报为草稿未提交
- 已填写但凭证未补齐

默认文案示例：

- `今天的工作量日报还没有提交，请在 24:00 前完成。`
- `今天的工作量日报已保存为草稿，请尽快补充并提交。`
- `你今天的工作量凭证还缺 {count} 项，请尽快补齐。`

#### 学习提醒

触发条件：

- 当日未学习课程
- 本周必修未完成
- 通关阶段未推进
- 考试待完成

默认文案示例：

- `今天还没有开始学习，建议先完成一节课程或一张知识卡。`
- `本周必修课程还有 {count} 项待完成，请尽快安排。`
- `当前通关阶段还有 {count} 个任务未完成，请继续推进。`

### 4.4 管理端配置建议

建议做一个“提醒管理”页面，支持：

1. 新建提醒规则
2. 选择提醒类型
3. 选择发送对象
4. 设置发送时间
5. 配置文案模板
6. 查看发送成功率
7. 查看失败原因与重试记录

### 4.5 微信侧约束

- 发送到手机必须走微信订阅消息
- 用户必须先授权订阅
- 模板字段必须使用微信审核通过的模板
- 每条消息内容要映射到模板字段，不能任意拼完整长文

这意味着：

- 可以自定义提醒内容
- 自定义的方式应设计成“模板变量填充”
- 建议优先支持 3 到 5 个固定模板

## 5. 推荐实施顺序

### 第一阶段

- 先补工作量提醒
- 先做站内提醒增强
- 先做订阅授权记录与发送日志

### 第二阶段

- 接入微信订阅消息
- 上线每日工作量提醒
- 后台增加提醒规则配置页

### 第三阶段

- 增加学习提醒
- 增加通关推进提醒
- 增加考试待完成提醒

## 6. 建议你现在确认的业务决策

1. 工作量提醒每天几点发
2. 学习提醒每天几点发
3. 谁可以配置提醒规则
4. 提醒对象是全员、按岗位，还是按门店
5. 是否允许员工自行关闭学习提醒
6. 工作量提醒是否允许重复催办

## 7. 最小可用版本建议

最小可用版本建议先做：

1. 工作量日报每日提醒
2. 后台配置一条固定提醒规则
3. 小程序端授权订阅消息
4. 发送成功和失败日志
5. 后台可查看发送结果

这样上线最快，风险最低，也最容易先验证效果。
