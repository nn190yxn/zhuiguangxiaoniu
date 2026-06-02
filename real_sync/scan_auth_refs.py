import os

root = '/www/wwwroot/122.51.223.46'
hits = []

for dp, _, fs in os.walk(root):
    for f in fs:
        if not f.endswith('.html'):
            continue
        p = os.path.join(dp, f)
        try:
            s = open(p, 'r', encoding='utf-8', errors='ignore').read()
        except Exception:
            continue
        if '/internal-auth.js?v=5' in s:
            tag = 'v5'
        elif '/internal-auth.js?v=4' in s:
            tag = 'v4'
        elif '/internal-auth.js' in s:
            tag = 'plain'
        else:
            continue
        hits.append((tag, p))

print('TOTAL', len(hits))
for tag, path in sorted(hits):
    print(tag, path)
