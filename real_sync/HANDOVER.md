# 项目完整交接文档

## 1. 服务器信息

| 项目 | 值 |
|------|---|
| **服务器 IP** | `122.51.223.46` |
| **SSH 用户** | `root` |
| **SSH 密码** | 不入库；通过安全渠道获取，并在公开暴露后立即轮换 |
| **站点根目录** | `/www/wwwroot/122.51.223.46/` |
| **Nginx 配置** | `/www/server/panel/vhost/nginx/122.51.223.46.conf` |
| **PHP 版本** | 8.2 (`/www/server/php/82/`) |
| **PHP-FPM Socket** | `/tmp/php-cgi-82.sock` |
| **Python 后端** | `api.supercalf.com` → `127.0.0.1:8000` (FastAPI) |
| **Python 路径** | `/www/wwwroot/api.supercalf.com/backend/` |
| **数据库** | 本地 MySQL，库名 `_122_51_223_46`，用户 `_122_51_223_46` |
| **DB 密码** | 在 `.env.local.php` 中 |

## 2. GitHub 信息

| 项目 | 值 |
|------|---|
| **主仓库** | `https://github.com/nn190yxn/zhuiguangxiaoniu.git` |
| **远程名称** | `zhuiguangxiaoniu` |
| **主分支** | `main` |
| **当前状态** | ✅ **已同步** (orphan 分支，361 文件，84K 行代码) |
| **备用仓库** | `https://github.com/nn190yxn/Alex` (原开发分支) |

## 3. 本地工作区路径

| 路径 | 说明 |
|------|------|
| `/workspace/` | 根工作区 |
| `/workspace/real_sync/` | 主项目代码 (Git 仓库) |
| `/workspace/mini-program-dev/` | 小程序开发版 |
| `/workspace/backup_20260530_full/` | Phase 1 备份 (2.3M) |
| `/tmp/real_sync_full.bundle` | Git Bundle 备份 (3.4MB，含完整历史) |

## 4. 已修复的关键问题

| 问题 | 状态 | 说明 |
|------|------|------|
| 员工登录 | ✅ 恢复 | `internal.html` → `/api/auth-jwt.php` |
| 体测 OCR | ✅ 恢复 | `/api/ai-services.php` Nginx 放行 |
| PHP 源码泄露 | ✅ 阻止 | 所有 `.php` 默认 `deny all` |
| JWT 密钥 | ✅ 强化 | 88 字符随机密钥 |
| 安全头 | ✅ 添加 | X-Frame-Options, CSP 等 6 项 |
| 速率限制 | ✅ 添加 | Auth 5r/m, 通用 60r/m |
| CORS | ✅ 白名单 | 仅 `supercalf.com` |
| Admin 访问 | ✅ IP 限制 | 仅 localhost + 服务器 IP |

## 5. Nginx 关键白名单

```nginx
# /www/server/panel/vhost/nginx/122.51.223.46.conf

# 认证/上下文/OCR 相关（需要 PHP 执行，严格限流）
location ~ ^/api/(auth/.*|auth-jwt|ai-services|common/context-info)\.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/tmp/php-cgi-82.sock;
}

# 通用 API（workload/admin 等）必须在 catch-all PHP deny 之前进入 PHP-FPM
location ~ ^/api/.*\.php$ {
    limit_req zone=api_general burst=60 nodelay;
    include fastcgi_params;
    fastcgi_pass unix:/tmp/php-cgi-82.sock;
}

# 其他非白名单 PHP 默认禁止，避免源码泄露
location ~* \.php$ {
    deny all;
    return 403;
}

# Admin 页面 Basic Auth
location /admin/ {
    auth_basic "Admin Access";
    auth_basic_user_file /www/server/panel/vhost/nginx/.admin_htpasswd;
}
```

## 6. 核心 API 端点

| 端点 | 说明 | 鉴权 |
|------|------|------|
| `POST /api/auth-jwt.php` | 登录获取 JWT | 无 |
| `GET /api/auth/me.php` | 获取当前用户 | Bearer Token |
| `POST /api/ai-services.php` | OCR/运动规划 | 需登录 |

## 7. 员工账号规则

- 登录名 = 手机号（如 `18586849521`）
- 必须在 `wp_users` 和 `staffs` 表中都有记录
- `staffs.status=1` 表示正常
- 不在文档中记录默认密码；测试账号密码通过安全渠道单独提供

## 8. 快速上手命令

```bash
# 切换到项目目录
cd /workspace/real_sync

# 检查状态
git status
git remote -v
git branch -a

# 从服务器拉取最新代码前，先通过安全渠道完成 SSH 认证
ssh root@122.51.223.46 \
  "tar -czf /tmp/site_backup.tar.gz -C /www/wwwroot/122.51.223.46/ ."
scp root@122.51.223.46:/tmp/site_backup.tar.gz /tmp/

# 推送到 GitHub
git add .
git commit -m "chore: 更新说明"
git push zhuiguangxiaoniu main
```

## 9. 验证命令

```bash
# 测试登录接口
curl -X POST https://supercalf.com/api/auth-jwt.php \
  -F 'username=<测试账号>' -F 'password=<通过安全渠道获取>'

# 测试 OCR 接口（需登录）
curl -X POST https://supercalf.com/api/ai-services.php \
  -H 'Authorization: Bearer TOKEN' \
  -d '{"action":"ocr",...}'
```

## 10. 回滚方案

```bash
# 从 Bundle 恢复
cd /tmp
git clone /tmp/real_sync_full.bundle zhuiguangxiaoniu-recovered
cd zhuiguangxiaoniu-recovered
git remote add origin https://github.com/nn190yxn/zhuiguangxiaoniu.git
git push --force --all
```

---
**最后同步时间**: 2026-06-02
**同步分支**: main (orphan, 361 文件)
