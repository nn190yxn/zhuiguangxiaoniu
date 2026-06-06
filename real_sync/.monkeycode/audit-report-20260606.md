# 深度审计报告：登录闪退、上传失败、凭证不一致

审计时间：2026-06-06
审计范围：所有认证、上传、凭证相关代码完整分析
审计方式：逐一代码片段完整读取 + 全场景可能性分析

---

## 问题1：登录闪退 / 无法登录

### 代码片段完整分析

#### internal-auth.js (169行)

**核心流程**：
```javascript
// L54-56: Token获取
function getToken() {
  return localStorage.getItem('jwt_token') || localStorage.getItem('token') || '';
}

// L107-128: 用户身份验证
async function fetchCurrentUser() {
  const token = getToken();
  if (!token) {
    return { ok: false, reason: 'missing_token' };
  }
  try {
    const response = await fetch('/api/auth/me.php', {
      method: 'GET',
      cache: 'no-store',
      headers: authHeaders()
    });
    const data = await response.json();
    if (response.ok && data && data.code === 0 && data.data) {
      localStorage.setItem('user_info', JSON.stringify(data.data));
      return { ok: true, user: data.data };
    }
    return { ok: false, reason: 'invalid_token', response: data };
  } catch (error) {
    return { ok: false, reason: 'network_error', error };
  }
}

// L130-149: 页面认证强制跳转
async function requirePageAuth(options = {}) {
  const result = await fetchCurrentUser();
  if (!result.ok) {
    clearAuth();  // 立即清除所有Token
    const loginUrl = getLoginUrl();
    const redirect = getRedirectPath();
    sessionStorage.setItem(redirectKey, redirect);
    window.location.replace(loginUrl);  // 强制跳转登录页
    return null;
  }
  // ...
}
```

**关键问题点**：
1. **L132-137**: 一旦验证失败立即清空Token并强制跳转，没有任何二次确认或用户提示
2. **L58-62**: `clearAuth()` 同时删除 `jwt_token`、`token`、`user_info`，导致用户状态完全丢失
3. **L168**: 所有页面自动执行 `requirePageAuth()`，无任何缓冲机制

#### app-auth.js (95行)

**核心流程**：
```javascript
// L41-45: Token过期检查
function isTokenExpired(bufferSeconds){
  var buffer=typeof bufferSeconds==='number'?bufferSeconds:60;
  return payload.exp <= Math.floor(Date.now()/1000)+buffer;
}

// L19-24: Token获取（多来源尝试）
function getToken(){
  return localStorage.getItem('jwt_token')
    || localStorage.getItem('token')
    || sessionStorage.getItem('jwt_token')
    || sessionStorage.getItem('token')
    || localStorage.getItem('auth_token')
    || localStorage.getItem('access_token')
    || '';
}
```

**关键问题点**：
1. **L44**: 过期缓冲时间固定60秒，但生产环境Token有效期可能只有15分钟，用户操作稍慢就会被判定过期
2. **L19-24**: Token获取逻辑混乱，尝试6个不同存储位置，容易读取到过期Token

#### api-client.js (71行)

**核心流程**：
```javascript
// L14-20: 401自动跳转
function handleUnauthorized(resp, data, options){
  var shouldRedirect=!(options&&options.redirectOnUnauthorized===false);
  if(shouldRedirect && window.AppAuth && typeof window.AppAuth.redirectToLogin==='function'){
    window.AppAuth.redirectToLogin();  // 立即跳转
  }
  throw normalizeError(resp, data||{code:401,message:'登录已过期，请重新登录',data:null});
}

// L23-26: 请求前预检查过期
if(window.AppAuth && typeof window.AppAuth.isTokenExpired==='function' && window.AppAuth.getToken && window.AppAuth.getToken() && window.AppAuth.isTokenExpired()){
  handleUnauthorized(null, {code:401,message:'登录已过期，请重新登录',data:null}, options);
}

// L53-54: 收到401响应立即跳转
if(resp.status===401 || (data && Number(data.code)===401)){
  handleUnauthorized(resp, data, options);
}
```

**关键问题点**：
1. **L24-26**: 每次API请求前都检查过期，用户打开页面后操作慢就会被拦截
2. **L53-54**: 收到401响应立即跳转，没有任何二次验证或用户提示

---

### 全场景可能性分析

#### 场景A：网络延迟导致误判
**触发条件**：用户登录后，打开页面加载缓慢
**根因**：`fetchCurrentUser()` 网络请求超时或失败 → `catch` 返回 `{reason:'network_error'}` → `requirePageAuth()` 判定失败 → 清空Token并跳转
**复现概率**：高（移动端网络不稳定）

#### 场景B：Token时间窗口过窄
**触发条件**：用户登录后，操作较慢（如填写日报）
**根因**：Token过期缓冲只有60秒，用户操作耗时超过缓冲 → `isTokenExpired()` 返回true → API请求前拦截跳转
**复现概率**：高（日报填写耗时通常超过1分钟）

#### 场景C：localStorage读写异常
**触发条件**：浏览器隐私模式、存储空间不足、跨域访问
**根因**：`localStorage.getItem()` 返回null或抛出异常 → Token获取失败 → `fetchCurrentUser()` 返回 `{reason:'missing_token'}` → 跳转登录
**复现概率**：中（部分浏览器隐私模式下localStorage受限）

#### 场景D：并发请求401误判
**触发条件**：用户打开多个页面或同时发起多个请求
**根因**：第一个请求触发401 → `clearAuth()` 清空所有Token → 其他请求全部失败 → 全部页面跳转登录
**复现概率**：高（多标签页场景）

#### 场景E：Token存储混乱
**触发条件**：用户多次登录或切换账号
**根因**：Token存储在6个不同位置，读取到过期Token → 请求失败 → 跳转登录
**复现概率**：中（账号切换场景）

---

### 根因定位

**P0根因**：
- `internal-auth.js:132-137` 一旦验证失败立即清空Token并强制跳转，没有任何缓冲机制
- `api-client.js:24-26` 每次请求前预检查过期，60秒缓冲窗口过窄

**P1根因**：
- `app-auth.js:19-24` Token获取逻辑混乱，尝试6个存储位置
- `internal-auth.js:168` 所有页面自动执行认证检查，无差异化处理

---

## 问题2：H5上传无反应

### 代码片段完整分析

#### workload-v2.html 上传流程 (关键片段)

```javascript
// L229-247: 上传处理函数
async function handleUpload(file){
  if(!file){setStatus('未选择图片，请重新点击上传图片','err');return;}
  setStatus('正在上传图片...','loading');
  try{
    var formData=new FormData();
    formData.append('file',file);
    formData.append('report_id',currentReportId);
    formData.append('metric_code',currentMetricCode);
    
    var resp=await fetch('/api/workload/evidence-upload.php',{
      method:'POST',
      headers:window.AppAuth.authHeaders(),
      body:formData
    });
    
    var data=await resp.json();
    if(!resp.ok || data.code!==0){
      throw new Error(data.message||'上传失败');
    }
    setStatus('上传成功','ok');
    loadEvidences();  // 刷新凭证列表
  }catch(err){
    setStatus(err.message||'上传失败，请重试','err');
  }
}
```

**关键问题点**：
1. **L229**: 未选择文件时只显示错误提示，没有阻止后续流程
2. **L244-246**: 失败时只显示错误消息，没有详细错误码或网络状态反馈
3. **缺少超时处理**: fetch没有timeout参数，移动端网络慢时请求可能长时间pending

#### evidence-upload.php (130行)

```php
// L26-35: 上传错误映射
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
  $uploadErrors = [
    UPLOAD_ERR_INI_SIZE => '图片超过服务器上传限制，请压缩后重试',
    UPLOAD_ERR_FORM_SIZE => '图片超过页面上传限制，请压缩后重试',
    UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请检查网络后重试',
    UPLOAD_ERR_NO_FILE => '未收到图片文件，请重新选择图片',
    UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用，请联系管理员',
    UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件，请联系管理员',
    UPLOAD_ERR_EXTENSION => '图片上传被服务器扩展阻止，请联系管理员',
  ];
  respond(400, $uploadErrors[$uploadError] ?? '上传失败');
}

// L67-68: 文件大小校验（严格）
if (strlen($decoded) < 1024) {
  respond(400, '图片文件过小，请上传有效图片');
}

// L88-89: 图片尺寸校验（严格）
if ($width < 80 || $height < 80) {
  respond(400, '图片尺寸太小，请上传至少80x80像素的图片');
}

// L118-119: 凭证数量上限校验
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM workload_evidences WHERE report_id = ? AND metric_code = ?");
$currentCount = $countStmt->fetchColumn();
if ($currentCount >= $maxEvidenceCount) {
  respond(400, '凭证数量已达上限，无法继续上传');
}
```

**关键问题点**：
1. **L67-68**: 文件大小<1KB直接拒绝，但某些有效小图标可能<1KB
2. **L88-89**: 图片尺寸<80px直接拒绝，但某些截图可能只有部分内容
3. **L118-119**: 凭证数量上限校验严格，没有提示当前数量和上限
4. **缺少超时日志**: 上传失败时没有记录详细错误信息到日志

---

### 全场景可能性分析

#### 场景A：网络超时无反馈
**触发条件**：移动端网络慢或不稳定，上传大图片
**根因**：`fetch`没有timeout参数 → 请求长时间pending → 用户看不到任何反馈 → 点击上传后无反应
**复现概率**：高（移动端网络环境）

#### 场景B：图片尺寸过小被拒绝
**触发条件**：用户上传小图标或部分截图
**根因**：`evidence-upload.php:67-68` 文件<1KB直接拒绝 → 前端收到400错误 → 显示"图片文件过小" → 用户以为上传失败
**复现概率**：中（图标上传场景）

#### 场景C：凭证数量已达上限
**触发条件**：用户已上传多个凭证，继续上传
**根因**：`evidence-upload.php:118-119` 数量达到上限 → 返回400错误 → 前端显示"凭证数量已达上限" → 用户以为上传失败
**复现概率**：中（多凭证场景）

#### 场景D：FormData缺少必要字段
**触发条件**：用户未选择门店或日期，currentReportId为空
**根因**：`formData.append('report_id',currentReportId)` → report_id为undefined → 后端校验失败 → 返回400
**复现概率**：高（用户操作顺序错误）

#### 场景E：localStorage Token丢失
**触发条件**：用户上传时Token被清除（问题1触发）
**根因**：`window.AppAuth.authHeaders()` 返回空Token → 后端返回401 → 前端跳转登录 → 上传中断
**复现概率**：高（与问题1关联）

---

### 根因定位

**P0根因**：
- `workload-v2.html:241-247` fetch请求无timeout，网络慢时pending无反馈
- `evidence-upload.php:67-68` 文件大小校验过严，<1KB直接拒绝

**P1根因**：
- `workload-v2.html:67` 上传控件未加载时的错误提示不完整
- `evidence-upload.php:118-119` 凭证数量上限校验缺少当前数量提示

---

## 问题3：凭证显示不一致

### 代码片段完整分析

#### dashboard.php 凭证统计 (关键片段)

```php
// L219-229: 驾驶舱异常统计
SELECT 
  r.report_date, r.store_id, st.name AS store_name, 
  r.staff_id, s.name AS staff_name, r.role_code, 
  m.metric_code, m.metric_name, 
  COALESCE(v.numeric_value, 0) AS metric_value, 
  rules.min_evidence_count, 
  COUNT(e.id) AS evidence_count  // 统计凭证数量
FROM workload_daily_reports r
JOIN stores st ON r.store_id = st.id
JOIN staffs s ON r.staff_id = s.id
JOIN workload_metrics m ON m.metric_code = r.metric_code
LEFT JOIN workload_metric_rules rules ON rules.metric_code = r.metric_code AND rules.role_code = r.role_code
LEFT JOIN workload_report_values v ON v.report_id = r.id AND v.metric_code = r.metric_code
LEFT JOIN workload_evidences e ON e.report_id = r.id AND e.metric_code = r.metric_code
GROUP BY r.report_date, r.store_id, st.name, r.staff_id, s.name, r.role_code, m.metric_code, m.metric_name, v.numeric_value, rules.min_evidence_count
HAVING evidence_count < rules.min_evidence_count  // 筛选凭证不足的记录
```

**关键问题点**：
1. **L219**: 使用 `COUNT(e.id)` 统计凭证数量，但JOIN条件可能遗漏部分凭证
2. **L229**: HAVING筛选凭证不足的记录，但可能漏统计已删除的凭证

#### staff-detail.php 凭证查询 (关键片段)

```php
// L68-72: 员工明细凭证列表
SELECT 
  e.id, e.file_url, e.metric_code, e.created_at,
  m.metric_name, m.unit
FROM workload_evidences e
LEFT JOIN workload_metrics m ON e.metric_code = m.metric_code
WHERE e.report_id = ?
ORDER BY e.created_at ASC
```

**关键问题点**：
1. **L68**: 直接查询 `workload_evidences` 表，没有JOIN校验report_id有效性
2. **缺少软删除校验**: 没有检查 `e.deleted_at` 或 `e.is_deleted` 字段

#### store-summary.php 凭证统计 (关键片段)

```php
// L49-53: 门店日报凭证统计
SELECT 
  metric_code, COUNT(*) AS evidence_count
FROM workload_evidences
WHERE report_id = ?
GROUP BY metric_code
```

**关键问题点**：
1. **L49**: 直接统计凭证数量，没有JOIN校验report状态
2. **缺少软删除校验**: 没有检查 `deleted_at` 或 `is_deleted` 字段

#### evidence-delete.php 凭证删除 (关键片段)

```php
// L36-37: 硬删除凭证
$deleteStmt = $pdo->prepare("DELETE FROM workload_evidences WHERE id = ?");
$deleteStmt->execute([$evidenceId]);
```

**关键问题点**：
1. **L36**: 硬删除凭证记录，没有标记软删除字段
2. **缺少缓存清理**: 删除后没有通知其他接口更新缓存

---

### 全场景可能性分析

#### 场景A：硬删除导致统计不一致
**触发条件**：用户删除凭证后，查看驾驶舱或门店日报
**根因**：`evidence-delete.php:36` 硬删除凭证 → `workload_evidences` 表记录消失 → `dashboard.php` 统计数量减少 → 前端显示不一致
**复现概率**：高（删除凭证后立即查看）

#### 场景B：并发上传计数错误
**触发条件**：用户同时上传多个凭证
**根因**：`evidence-upload.php:118` 查询当前数量 → 上传插入新记录 → 并发请求时计数可能不准确 → 达到上限提示错误
**复现概率**：中（快速上传多个凭证）

#### 场景C：JOIN条件遗漏凭证
**触发条件**：凭证记录的metric_code与report不匹配
**根因**：`dashboard.php:219` LEFT JOIN条件 `e.metric_code = r.metric_code` → 某些凭证可能未JOIN到 → 统计数量遗漏
**复现概率**：中（metric_code不一致）

#### 场景D：软删除字段缺失
**触发条件**：数据库设计缺少软删除字段
**根因**：所有接口查询凭证时没有 `WHERE deleted_at IS NULL` 条件 → 已删除凭证仍被统计 → 数量不一致
**复现概率**：高（数据库设计问题）

#### 场景E：缓存未更新
**触发条件**：前端缓存凭证列表，删除后未刷新
**根因**：`workload-v2.html:loadEvidences()` 获取凭证列表 → 删除后重新调用 → 但其他页面缓存未更新
**复现概率**：中（多页面查看）

---

### 根因定位

**P0根因**：
- `evidence-delete.php:36` 硬删除凭证，导致统计不一致
- `dashboard.php:219` JOIN条件可能遗漏部分凭证

**P1根因**：
- 所有凭证查询接口缺少软删除校验（`deleted_at IS NULL`）
- `evidence-upload.php:118` 并发上传时计数可能不准确

---

## 修复优先级建议

### P0（立即修复）

#### 问题1修复
1. **修改 `internal-auth.js:132-137`**：添加二次确认机制，验证失败时先提示用户，而不是立即清空Token跳转
2. **修改 `api-client.js:24-26`**：延长过期缓冲窗口至300秒（5分钟），给用户更多操作时间
3. **修改 `app-auth.js:19-24`**：统一Token存储位置，只使用 `jwt_token`

#### 问题2修复
1. **修改 `workload-v2.html:241-247`**：添加timeout参数（30秒），超时后显示明确错误提示
2. **修改 `evidence-upload.php:67-68`**：降低文件大小校验阈值至512字节，或改为警告而非拒绝

#### 问题3修复
1. **修改 `evidence-delete.php:36`**：改为软删除（UPDATE `deleted_at = NOW()`），而不是硬删除
2. **修改所有凭证查询接口**：添加 `WHERE deleted_at IS NULL` 条件

### P1（本周修复）

#### 问题1优化
1. **修改 `internal-auth.js:168`**：添加页面差异化认证，部分页面可跳过强制认证
2. **添加Token续期机制**：用户活跃时自动续期Token

#### 问题2优化
1. **修改 `workload-v2.html:67`**：完善上传控件未加载时的错误提示和重试机制
2. **修改 `evidence-upload.php:118-119`**：添加当前数量和上限的详细提示

#### 问题3优化
1. **修改 `dashboard.php:219`**：优化JOIN条件，确保不遗漏凭证
2. **添加凭证缓存清理机制**：删除后通知所有相关接口更新缓存

---

## 验证建议

### 问题1验证
```bash
# 生成测试Token（有效期15分钟）
ssh root@122.51.223.46 'cd /www/wwwroot/122.51.223.46 && php -r "require \"api/config.php\"; print(generate_jwt(40, \"18285031172\", \"operation\", 900));"'

# 模拟网络延迟场景
curl -X GET "https://supercalf.com/api/auth/me.php" \
  -H "Authorization: Bearer <token>" \
  --max-time 5 \
  --delay 3

# 模拟并发请求场景
for i in {1..5}; do
  curl -X GET "https://supercalf.com/api/auth/me.php" \
    -H "Authorization: Bearer <token>" &
done
```

### 问题2验证
```bash
# 模拟上传超时场景
curl -X POST "https://supercalf.com/api/workload/evidence-upload.php" \
  -H "Authorization: Bearer <token>" \
  -F "file=@small-image.jpg" \
  -F "report_id=123" \
  -F "metric_code=daily_checkin" \
  --max-time 60

# 模拟文件大小过小场景
curl -X POST "https://supercalf.com/api/workload/evidence-upload.php" \
  -H "Authorization: Bearer <token>" \
  -F "file=@tiny-icon.ico" \
  -F "report_id=123" \
  -F "metric_code=daily_checkin"
```

### 问题3验证
```bash
# 查询凭证数量（驾驶舱）
curl -X GET "https://supercalf.com/api/workload/dashboard.php?date=2026-06-05" \
  -H "Authorization: Bearer <token>"

# 删除凭证后再次查询
curl -X POST "https://supercalf.com/api/workload/evidence-delete.php" \
  -H "Authorization: Bearer <token>" \
  -d '{"evidence_id":456}'

# 再次查询驾驶舱，验证数量是否一致
curl -X GET "https://supercalf.com/api/workload/dashboard.php?date=2026-06-05" \
  -H "Authorization: Bearer <token>"
```

---

## 总结

通过逐一代码片段完整读取和全场景可能性分析，定位到三个问题的根因：

1. **登录闪退**：认证逻辑过于严格，验证失败立即清空Token并强制跳转，缺少缓冲机制和二次确认
2. **上传无反应**：上传请求无timeout参数，网络慢时pending无反馈；后端校验过严，小文件直接拒绝
3. **凭证不一致**：硬删除凭证导致统计遗漏；JOIN条件可能遗漏部分凭证；缺少软删除字段校验

建议按P0优先级立即修复核心问题，P1优化提升用户体验。