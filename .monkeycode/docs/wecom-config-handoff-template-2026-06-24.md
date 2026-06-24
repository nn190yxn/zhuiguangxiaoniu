# 企业微信正式配置交接模板

日期：2026-06-24

## 1. 用途

- 本模板用于收集企业微信正式配置。
- 配置补齐后，`/api/wecom/status.php` 应从 `enabled = false` 变为 `enabled = true`。
- 本文件只填写配置项名称和交接状态，不填写真实密钥值。

## 2. 配置交接清单

| 配置项 | 是否已提供 | 校验方式 | 备注 |
| --- | --- | --- | --- |
| `WECOM_ENABLED` | pending | 值应为 `1` | 启用企业微信功能 |
| `WECOM_CORP_ID` | pending | 企业微信后台获取 | 企业 ID |
| `WECOM_AGENT_ID` | pending | 企业微信后台获取 | 应用 AgentId |
| `WECOM_APPID` | pending | 小程序后台获取 | 企业微信小程序 AppID |
| `WECOM_AGENT_SECRET` | pending | 企业微信后台获取 | 应用 Secret |
| `WECOM_MINI_PROGRAM_SECRET` | pending | 小程序后台获取 | 小程序 Secret |
| `WECOM_SYNC_ROOT_DEPARTMENT_ID` | pending | 企业微信通讯录确认 | 通讯录同步根部门 ID |

## 3. 线上配置文件位置

- 推荐位置：`/www/wwwroot/122.51.223.46/api/.env.local.php`
- 当前状态：线上未启用正式配置。

## 4. 文件内容模板

```php
<?php

return [
    'WECOM_ENABLED' => '1',
    'WECOM_CORP_ID' => '<企业微信企业ID>',
    'WECOM_AGENT_ID' => '<企业微信应用AgentId>',
    'WECOM_APPID' => '<企业微信小程序AppID>',
    'WECOM_AGENT_SECRET' => '<企业微信应用Secret>',
    'WECOM_MINI_PROGRAM_SECRET' => '<企业微信小程序Secret>',
    'WECOM_SYNC_ROOT_DEPARTMENT_ID' => '<通讯录同步根部门ID>',
];
```

## 5. 安装后复核

```bash
# 检查配置文件语法
php -l /www/wwwroot/122.51.223.46/api/.env.local.php

# 复核企业微信状态接口
curl -s https://supercalf.com/api/wecom/status.php
```

## 6. 预期结果

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "enabled": true,
    "config": {
      "corp_id_configured": true,
      "agent_id_configured": true,
      "app_id_configured": true,
      "agent_secret_configured": true,
      "mini_program_secret_configured": true
    }
  }
}
```

## 7. 配置后执行顺序

1. 复核 `/api/wecom/status.php`。
2. 执行通讯录同步 worker。
3. 复核 `wecom_sync_logs`。
4. 执行提醒 worker。
5. 复核 `wecom_message_logs`。
6. 进入企业微信真机联调。
