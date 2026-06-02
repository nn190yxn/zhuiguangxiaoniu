# 常态化工作量与经营系统 API 草案 V1

## 目标

为销售和教练日报录入提供首版 API 协议，并为后续门店/总部汇总保留统一出口。

## 认证

- 继续复用现有 JWT 登录体系。
- 所有接口通过 `Authorization: Bearer <token>` 传递身份。

## 角色约束

- 销售只能提交自己的销售日报。
- 教练只能提交自己的教练日报。
- 店长可以查看本店日报汇总。
- 总部运营、财务、管理员可以查看全部汇总。

## 1. 获取日报模板

- 方法：`GET`
- 路径：`/api/workload/template.php?role=sales`

响应示例：

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "template_code": "sales_daily_v1",
    "role": "sales",
    "items": [
      {
        "metric_code": "sales_resources",
        "metric_name": "新增资源数",
        "unit": "count",
        "required": true,
        "editable": true
      }
    ]
  }
}
```

## 2. 获取我的日报

- 方法：`GET`
- 路径：`/api/workload/my-report.php?date=2026-05-09&store_id=2&role=coach`

用途：

- 员工进入页面时回填当天已保存数据。
- 同一天重复进入时支持继续编辑。

## 3. 保存日报

- 方法：`POST`
- 路径：`/api/workload/save-report.php`

请求示例：

```json
{
  "report_date": "2026-05-09",
  "store_id": 2,
  "role_code": "coach",
  "submit_status": "submitted",
  "remarks": "今日有两位家长需继续跟进",
  "values": [
    { "metric_code": "coach_plan_hours", "value": 6 },
    { "metric_code": "coach_actual_hours", "value": 5 },
    { "metric_code": "coach_actual_comm", "value": 8 },
    { "metric_code": "coach_body_test", "value": 2 }
  ]
}
```

校验规则：

- `report_date` 必填，格式 `YYYY-MM-DD`
- `store_id` 必填，必须属于当前人员可录入门店
- `role_code` 必填，且必须与本人允许岗位一致
- `values` 必填，至少 1 项
- 不允许提交字典中不存在的 `metric_code`
- 系统计算字段不允许前端提交

响应示例：

```json
{
  "code": 0,
  "message": "保存成功",
  "data": {
    "report_id": 123,
    "submit_status": "submitted",
    "updated_at": "2026-05-09 14:30:00"
  }
}
```

## 4. 获取门店日报汇总

- 方法：`GET`
- 路径：`/api/workload/store-summary.php?date=2026-05-09&store_id=2`

返回内容：

- 门店当天提交人数
- 应提交人数
- 按岗位汇总的关键指标
- 自动计算率值

## 5. 获取总部趋势汇总

- 方法：`GET`
- 路径：`/api/workload/hq-summary.php?date_from=2026-05-01&date_to=2026-05-31`

返回内容：

- 各门店提交率趋势
- 销售关键漏斗趋势
- 教练关键交付趋势
- 门店排行
- 异常门店列表

## 6. 计算任务

建议新增定时任务或保存后同步计算：

- 保存日报后，刷新对应员工当天汇总
- 刷新对应门店当天汇总
- 刷新总部当天汇总

## 错误码建议

- `400` 参数错误
- `401` 未登录
- `403` 无权限
- `404` 模板不存在
- `409` 同一日报冲突
- `500` 服务器错误

## V1 实施顺序

1. `template.php`
2. `my-report.php`
3. `save-report.php`
4. `store-summary.php`
5. `hq-summary.php`
