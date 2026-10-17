#!/usr/bin/env python3
"""Stub Spotify Web API + accounts endpoint. Logs every request to $MOCK_LOG as 'METHOD PATH'."""
import json, os, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit, parse_qs

LOG = os.environ.get("MOCK_LOG", "/tmp/mock-spotify.log")
CTRL = os.environ.get("MOCK_CTRL", "/tmp/mock-spotify.ctrl")  # json: {"fail": {"PUT /v1/me/player/seek": 404}, "count": n}
lock = threading.Lock()

def control():
    try:
        return json.load(open(CTRL))
    except Exception:
        return {}

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def handle_any(self):
        parts = urlsplit(self.path)
        key = f"{self.command} {parts.path}"
        n = int(self.headers.get("Content-Length") or 0)
        if n: self.rfile.read(n)
        with lock:
            with open(LOG, "a") as f:
                f.write(key + " " + parts.query + "\n")
            ctrl = control()
            fail = (ctrl.get("fail") or {}).get(key)
            if fail:
                ctrl["fail"].pop(key)
                json.dump(ctrl, open(CTRL, "w"))
        if fail:
            self.send_response(fail)
            if fail == 429: self.send_header("Retry-After", "30")
            self.end_headers(); self.wfile.write(b'{"error":{"status":%d}}' % fail); return
        body = {}
        p = parts.path
        if p == "/api/token": body = {"access_token": "stub", "expires_in": 3600}
        elif p == "/v1/me/player/devices": body = {"devices": [{"id": "dev-thor", "name": "Thor", "is_active": True}]}
        elif p == "/v1/me/player/queue" and self.command == "GET":
            body = {"currently_playing": {"uri": "spotify:track:now"}, "queue": [{"uri": "spotify:track:n1", "name": "Next One", "artists": [{"name": "Stub"}]}]}
        elif p == "/v1/me/playlists": body = {"items": [{"id": "p1", "name": "Stub list", "uri": "spotify:playlist:p1", "tracks": {"total": 3}}], "next": None}
        elif p == "/v1/search": body = {"tracks": {"items": []}, "artists": {"items": []}, "playlists": {"items": []}}
        elif self.command in ("PUT", "POST", "DELETE"):
            self.send_response(204); self.end_headers(); return
        raw = json.dumps(body).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw))); self.end_headers(); self.wfile.write(raw)
    do_GET = do_PUT = do_POST = do_DELETE = handle_any

ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1] if len(sys.argv) > 1 else 18099)), H).serve_forever()
