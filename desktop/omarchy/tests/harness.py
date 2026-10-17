#!/usr/bin/env python3
"""In-process test of the-shit.music against the stub API (no Spotify traffic)."""
import collections, importlib.machinery, importlib.util, json, os, sys, time
SCRIPT = sys.argv[1]
T = os.environ["T"]
MOCK_LOG = os.environ["MOCK_LOG"]; CTRL = os.environ["MOCK_CTRL"]
loader = importlib.machinery.SourceFileLoader("tsm", SCRIPT)
spec = importlib.util.spec_from_loader("tsm", loader); tsm = importlib.util.module_from_spec(spec); loader.exec_module(tsm)
from gi.repository import GLib, Gtk

assert tsm.API_BASE.startswith("http://127.0.0.1"), tsm.API_BASE
assert tsm.CONFIG == os.path.join(T, "cfg")
results = []
def check(name, ok, detail=""):
    results.append((name, ok)); print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""), flush=True)
def calls():
    try: lines = open(MOCK_LOG).read().split("\n")
    except OSError: return collections.Counter()
    return collections.Counter(" ".join(l.split(" ")[:2]) for l in lines if l.strip())
def total(): return sum(calls().values())
RL = os.path.join(T, "cfg", "rate-limit.json")

# 1. deadline formats
now = time.time()
fut = time.localtime(now + 3600)
check("deadline epoch int", abs(tsm.parse_deadline(int(now) + 5) - (int(now) + 5)) < 1)
check("deadline 'YYYY-MM-DD HH:MM:SS'", abs(tsm.parse_deadline(time.strftime("%Y-%m-%d %H:%M:%S", fut)) - (now + 3600)) < 2)
check("deadline 'YYYY-MM-DDTHH:MM:SS'", abs(tsm.parse_deadline(time.strftime("%Y-%m-%dT%H:%M:%S", fut)) - (now + 3600)) < 2)
for raw in ('{"resumes_at": %d}', '{"resumes_at":%d}'):
    open(RL, "w").write(raw % (int(now) + 600))
    check(f"rate-limit.json {raw[:15]!r} -> limited", tsm.rate_limited())
open(RL, "w").write(json.dumps({"resumes_at": time.strftime("%Y-%m-%d %H:%M:%S", fut)}))
check("rate-limit.json string datetime -> limited", tsm.rate_limited())

# 2. with the deadline in place nothing touches the network (token expired => would refresh)
before = total()
tok = os.path.join(T, "cfg", "token.json")
json.dump({"access_token": "old", "refresh_token": "r", "expires_at": 1}, open(tok, "w"))
r = (tsm.access_token(), tsm.account_playlists(), tsm.upcoming(), tsm.seek_to(1000), tsm.set_stream_volume(5), tsm.search_catalog("abc"), tsm.enqueue("spotify:track:x"))
check("deadline: zero calls (token refresh, playlists, queue, seek, volume, search, enqueue)", total() == before, f"results={r!r} calls={total()-before}")
os.remove(RL)

# 3. account_playlists: no automatic retry on a failed page
open(CTRL, "w").write(json.dumps({"fail": {"GET /v1/me/playlists": 401}}))
b = calls()["GET /v1/me/playlists"]; tsm.account_playlists()
check("account_playlists makes 1 call on failure (no retry)", calls()["GET /v1/me/playlists"] - b == 1)

# 4. GUI run under broadway, visible
m = tsm.Music()
steps = []
def at(ms, fn): steps.append((ms, fn))
snap = {}
def mark(name): snap[name] = (calls(), time.process_time(), time.monotonic())
def drag(scale_cb, scale, lo, hi, n, gap_ms):
    def go(i=[0]):
        v = lo + (hi - lo) * i[0] / max(1, n - 1)
        scale_cb(scale, Gtk.ScrollType.JUMP, v); i[0] += 1
        return i[0] < n
    GLib.timeout_add(gap_ms, go)

t = 0
at(t := 2500, lambda: mark("startup"))
at(t := t + 100, lambda: (m.__setattr__("track_length_ms", m.track_length_ms or 200000), drag(m.on_seek, m.seek, 100, 900, 40, 15)))
at(t := t + 2000, lambda: mark("seek1"))
at(t := t + 100, lambda: drag(m.on_seek, m.seek, 900, 200, 40, 15))
at(t := t + 2000, lambda: mark("seek2"))
at(t := t + 100, lambda: drag(m.on_volume, m.volume, 10, 80, 30, 15))
at(t := t + 2000, lambda: mark("vol"))
at(t := t + 100, lambda: (open(CTRL, "w").write(json.dumps({"fail": {"PUT /v1/me/player/seek": 404}})), drag(m.on_seek, m.seek, 500, 520, 5, 15)))
at(t := t + 2000, lambda: mark("seek404"))
at(t := t + 100, lambda: m.search.set_text("a"))
at(t := t + 1000, lambda: mark("search1"))
for i, s in enumerate(["ab", "abc", "abcd", "abcde"]):
    at(t := t + 100, lambda s=s: m.search.set_text(s))
at(t := t + 1200, lambda: mark("search2"))
at(t := t + 4000, lambda: mark("idle_vis_start"))
at(t := t + 15000, lambda: mark("idle_vis_end"))
at(t := t + 100, lambda: m.__setattr__("track_key", "fake-previous-track"))
at(t := t + 4000, lambda: mark("track"))
at(t := t + 100, lambda: (m.__setattr__("force_visible", ""), m.set_visible(False), mark("hid")))
for k in range(5):  # things that used to fire API calls while hidden
    at(t := t + 2000, lambda: (m.queue_safety_poll(), m.paint_queue(), m.schedule_queue(0, "test"), m.__setattr__("track_key", "zzz")))
at(t := t + 15000, lambda: mark("hidden_end"))
at(t := t + 100, lambda: (m.__setattr__("force_visible", "1"), m.set_visible(True), mark("shown")))
at(t := t + 4000, lambda: (mark("shown_done"), open(CTRL, "w").write(json.dumps({"fail": {"GET /v1/me/player/queue": 429}})), m.schedule_queue(0, "test429")))
at(t := t + 4000, lambda: (mark("after429"), m.schedule_queue(0, "x"), m.paint_queue(), drag(m.on_seek, m.seek, 100, 200, 5, 15)))
at(t := t + 3000, lambda: (mark("end"), m.loop.quit()))
for ms, fn in steps:
    GLib.timeout_add(ms, lambda fn=fn: (fn(), False)[1])
# remember_workspace would look for our pid in Hyprland: we're on broadway, so pin visible via env.
m.run()

def d(a, b, key=None):
    ca, cb = snap[a][0], snap[b][0]
    if key: return cb[key] - ca[key]
    return sum(cb.values()) - sum(ca.values())
st = snap["startup"][0]
check("startup: 1 playlists GET + 1 queue GET", st["GET /v1/me/playlists"] >= 1 and st["GET /v1/me/player/queue"] == 1, dict(st))
check("seek drag 1 (40 steps): 1 devices GET + 1 seek PUT", d("startup", "seek1", "GET /v1/me/player/devices") == 1 and d("startup", "seek1", "PUT /v1/me/player/seek") == 1 and d("startup", "seek1") == 2, dict(snap["seek1"][0] - snap["startup"][0]))
check("seek drag 2 (40 steps): 1 seek PUT, device cached", d("seek1", "seek2") == 1 and d("seek1", "seek2", "PUT /v1/me/player/seek") == 1)
check("volume drag (30 steps): 1 volume PUT, device cached", d("seek2", "vol") == 1 and d("seek2", "vol", "PUT /v1/me/player/volume") == 1)
check("seek after 404: refresh device + 1 retry", d("vol", "seek404", "PUT /v1/me/player/seek") == 2 and d("vol", "seek404", "GET /v1/me/player/devices") == 1, dict(snap["seek404"][0] - snap["vol"][0]))
check("search 'a' (1 char): 0 calls", d("seek404", "search1") == 0)
check("search typed ab..abcde 100ms apart: 1 search call", d("search1", "search2", "GET /v1/search") == 1 and d("search1", "search2") == 1)
vis_s = snap["idle_vis_end"][2] - snap["idle_vis_start"][2]
check(f"visible idle {vis_s:.0f}s: 0 calls", d("idle_vis_start", "idle_vis_end") == 0)
check("MPRIS track change: 1 queue GET", d("idle_vis_end", "track", "GET /v1/me/player/queue") == 1 and d("idle_vis_end", "track") == 1)
hid_s = snap["hidden_end"][2] - snap["hid"][2]
check(f"hidden {hid_s:.0f}s incl. forced polls/track changes: 0 calls", d("hid", "hidden_end") == 0)
check("shown again: 1 queue GET", d("shown", "shown_done", "GET /v1/me/player/queue") == 1)
check("429 on queue: 1 call, then nothing (deadline written)", d("shown_done", "end") == 1 and os.path.exists(RL), dict(snap["end"][0] - snap["shown_done"][0]))
for a, b in (("idle_vis_start", "idle_vis_end"), ("hid", "hidden_end")):
    cpu = snap[b][1] - snap[a][1]; wall = snap[b][2] - snap[a][2]
    print(f"CPU {a}->{b}: {100*cpu/wall:.1f}% of one core over {wall:.0f}s")
print("calls total:", dict(calls()))
print("SUMMARY", sum(ok for _, ok in results), "/", len(results))
sys.exit(0 if all(ok for _, ok in results) else 1)
