import http.server
import socketserver
import os
import sys

class CharsetHandler(http.server.SimpleHTTPRequestHandler):
    extensions_map = http.server.SimpleHTTPRequestHandler.extensions_map.copy()
    extensions_map['.md'] = 'text/plain'
    extensions_map['.json'] = 'application/manifest+json'

    def send_header(self, keyword, value):
        if keyword.lower() == 'content-type':
            if value.startswith('text/') and 'charset' not in value:
                value = value + '; charset=utf-8'
        super().send_header(keyword, value)

PORT = 8080
DIR = '/workspace/H2体系优化'
os.chdir(DIR)
print(f"Serving {DIR} on port {PORT} with charset=utf-8")

class ReusableServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

with ReusableServer(("", PORT), CharsetHandler) as httpd:
    httpd.serve_forever()
