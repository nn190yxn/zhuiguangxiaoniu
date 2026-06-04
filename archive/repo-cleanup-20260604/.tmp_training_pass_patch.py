from pathlib import Path
path = Path('/www/wwwroot/122.51.223.46/training-pass.html')
text = path.read_text(encoding='utf-8')
marker = "        let selectedRole = null;\n"
helper = """        function getToken() {\n            return localStorage.getItem('jwt_token') || localStorage.getItem('token') || '';\n        }\n\n        function authHeaders(extraHeaders) {\n            extraHeaders = extraHeaders || {};\n            var token = getToken();\n            if (!token) return extraHeaders;\n            return Object.assign({}, extraHeaders, { Authorization: 'Bearer ' + token });\n        }\n\n        async function checkAuth() {\n            const res = await fetch('/api/auth/me.php', { headers: authHeaders(), cache: 'no-store' });\n            const data = await res.json();\n            if (!res.ok || data.code !== 0) {\n                window.location.href = '/mobile/login.html?redirect=' + encodeURIComponent(window.location.pathname);\n                throw new Error('未登录');\n            }\n            return data.data || {};\n        }\n\n"""
if 'function getToken() {' not in text:
    if marker not in text:
        raise SystemExit('marker not found')
    text = text.replace(marker, helper + marker, 1)
text = text.replace("const res = await fetch(`${API_BASE}/training-modules.php?action=list${roleParam}`);", "const res = await fetch(API_BASE + '/training-modules.php?action=list' + roleParam, { headers: authHeaders() });")
text = text.replace("const progressRes = await fetch(`${API_BASE}/training-modules.php?action=list`);", "const progressRes = await fetch(API_BASE + '/training-modules.php?action=list', { headers: authHeaders() });")
text = text.replace("const certRes = await fetch(`${API_BASE}/certificates.php?action=list`);", "const certRes = await fetch(API_BASE + '/certificates.php?action=list', { headers: authHeaders() });")
path.write_text(text, encoding='utf-8')
