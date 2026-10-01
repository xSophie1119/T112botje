#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import math
import os
import random
import sqlite3
import threading
import time
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
STATIC = ROOT / "static"
DB_PATH = Path(os.environ.get("ROUTEPILOT_SIM_DB", ROOT / "routepilot-simulator.sqlite3"))
HOST = os.environ.get("ROUTEPILOT_SIM_HOST", "127.0.0.1")
PORT = int(os.environ.get("ROUTEPILOT_SIM_PORT", "8765"))
TOKEN = os.environ.get("ROUTEPILOT_PORTAL_TOKEN", "")
ROUTER = os.environ.get("ROUTEPILOT_ROUTER_URL", "https://router.project-osrm.org").rstrip("/")
USER_AGENT = "RoutePilot-Simulator/3.2 (+https://github.com/xSophie1119/T112botje)"

# Public / neighborhood-level anchors only; no client data is baked into the simulator.
ANCHORS = [
    ("Centrum", 51.5555, 5.0913),
    ("Spoorzone", 51.5606, 5.0837),
    ("Tilburg-West", 51.5570, 5.0420),
    ("Universiteit", 51.5640, 5.0410),
    ("Reeshof", 51.5750, 5.0030),
    ("Dalem", 51.5870, 4.9880),
    ("Witbrant", 51.5585, 5.0050),
    ("Noord", 51.5865, 5.0710),
    ("Wagnerplein", 51.5895, 5.0690),
    ("Quirijnstok", 51.5880, 5.0920),
    ("Stokhasselt", 51.5805, 5.1010),
    ("Oud-Noord", 51.5710, 5.0880),
    ("Korvel", 51.5500, 5.0700),
    ("Broekhoven", 51.5410, 5.0880),
    ("Stappegoor", 51.5350, 5.0830),
    ("Piushaven", 51.5510, 5.1050),
    ("Jeruzalem", 51.5530, 5.1210),
    ("Berkel-Enschot", 51.5860, 5.1420),
    ("Udenhout", 51.6090, 5.1430),
    ("TweeSteden", 51.5780, 5.0540),
    ("Elisabeth", 51.5415, 5.0835),
    ("Goirke", 51.5720, 5.0730),
    ("Zorgvlied", 51.5480, 5.0520),
    ("Armhoef", 51.5590, 5.1170),
    ("Groeseind", 51.5780, 5.0860),
]

SCHEMA = """
CREATE TABLE IF NOT EXISTS corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    lat REAL NOT NULL,
    lon REAL NOT NULL,
    radius_m REAL NOT NULL DEFAULT 45,
    strength INTEGER NOT NULL DEFAULT 1,
    note TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS route_cache (
    cache_key TEXT PRIMARY KEY,
    response_json TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS driver_trips (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trip_id TEXT NOT NULL UNIQUE,
    destination TEXT NOT NULL DEFAULT '',
    started_at INTEGER NOT NULL DEFAULT 0,
    ended_at INTEGER NOT NULL DEFAULT 0,
    planned_distance_m REAL NOT NULL DEFAULT 0,
    actual_distance_m REAL NOT NULL DEFAULT 0,
    warnings INTEGER NOT NULL DEFAULT 0,
    reroutes INTEGER NOT NULL DEFAULT 0,
    planned_json TEXT NOT NULL DEFAULT '[]',
    actual_json TEXT NOT NULL DEFAULT '[]',
    review_status TEXT NOT NULL DEFAULT 'new',
    review_note TEXT NOT NULL DEFAULT '',
    uploaded_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS scenarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id TEXT NOT NULL,
    origin_name TEXT NOT NULL,
    origin_lat REAL NOT NULL,
    origin_lon REAL NOT NULL,
    destination_name TEXT NOT NULL,
    destination_lat REAL NOT NULL,
    destination_lon REAL NOT NULL,
    distance_m REAL NOT NULL DEFAULT 0,
    duration_s REAL NOT NULL DEFAULT 0,
    score REAL NOT NULL DEFAULT 0,
    correction_hits INTEGER NOT NULL DEFAULT 0,
    geometry_json TEXT NOT NULL DEFAULT '[]',
    alternatives_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'new',
    review_note TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL
);
"""

db_lock = threading.Lock()

def db():
    con = sqlite3.connect(DB_PATH, check_same_thread=False)
    con.row_factory = sqlite3.Row
    return con

with db() as con:
    con.executescript(SCHEMA)


def now_ms():
    return int(time.time() * 1000)


def haversine_m(a_lat, a_lon, b_lat, b_lon):
    r = 6371000.0
    p1, p2 = math.radians(a_lat), math.radians(b_lat)
    dp = math.radians(b_lat - a_lat)
    dl = math.radians(b_lon - a_lon)
    h = math.sin(dp/2)**2 + math.cos(p1) * math.cos(p2) * math.sin(dl/2)**2
    return 2 * r * math.asin(min(1, math.sqrt(h)))


def min_distance_to_geometry(lat, lon, coords):
    if not coords:
        return 1e12
    stride = max(1, len(coords) // 1200)
    best = 1e12
    for i in range(0, len(coords), stride):
        x = coords[i]
        if len(x) < 2:
            continue
        d = haversine_m(lat, lon, x[1], x[0])
        if d < best:
            best = d
    return best


def active_corrections():
    with db_lock, db() as con:
        rows = con.execute("SELECT * FROM corrections WHERE active=1 ORDER BY id DESC").fetchall()
    return [dict(r) for r in rows]


def route_score(route, corrections):
    distance = float(route.get("distance", 0))
    duration = float(route.get("duration", 0))
    coords = route.get("geometry", {}).get("coordinates", [])
    score = duration + distance / 50.0
    hits = []
    for c in corrections:
        d = min_distance_to_geometry(float(c["lat"]), float(c["lon"]), coords)
        if d > float(c["radius_m"]):
            continue
        t = c["type"]
        strength = max(1, int(c["strength"]))
        if t in ("avoid", "road_closed", "too_narrow", "bus_trap", "height_block"):
            score += 5000 * strength
        elif t == "prefer":
            score -= 350 * strength
        elif t == "good_stop":
            score -= 90 * strength
        elif t == "bad_stop":
            score += 300 * strength
        hits.append({"id": c["id"], "type": t, "distance_m": round(d, 1), "note": c["note"]})
    return score, hits


def cached_osrm_route(o_lat, o_lon, d_lat, d_lon):
    key_raw = f"{o_lat:.5f},{o_lon:.5f}>{d_lat:.5f},{d_lon:.5f}"
    cache_key = hashlib.sha256(key_raw.encode()).hexdigest()
    with db_lock, db() as con:
        row = con.execute("SELECT response_json,created_at FROM route_cache WHERE cache_key=?", (cache_key,)).fetchone()
    if row and now_ms() - int(row["created_at"]) < 7 * 24 * 3600 * 1000:
        return json.loads(row["response_json"])

    url = (
        f"{ROUTER}/route/v1/driving/{o_lon:.6f},{o_lat:.6f};{d_lon:.6f},{d_lat:.6f}"
        "?overview=full&geometries=geojson&steps=false&alternatives=3&continue_straight=true"
    )
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=12) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    if data.get("code") != "Ok" or not data.get("routes"):
        raise RuntimeError(data.get("message") or "Geen OSRM-route")
    with db_lock, db() as con:
        con.execute(
            "INSERT OR REPLACE INTO route_cache(cache_key,response_json,created_at) VALUES(?,?,?)",
            (cache_key, json.dumps(data, separators=(",", ":")), now_ms()),
        )
    return data


def simulate_pair(batch_id, origin, destination, corrections):
    o_name, o_lat, o_lon = origin
    d_name, d_lat, d_lon = destination
    created = now_ms()
    try:
        data = cached_osrm_route(o_lat, o_lon, d_lat, d_lon)
        candidates = []
        for route in data.get("routes", [])[:3]:
            score, hits = route_score(route, corrections)
            candidates.append((score, route, hits))
        candidates.sort(key=lambda x: x[0])
        score, chosen, hits = candidates[0]
        alternatives = []
        for s, r, h in candidates:
            alternatives.append({
                "score": round(s, 1),
                "distance_m": round(float(r.get("distance", 0)), 1),
                "duration_s": round(float(r.get("duration", 0)), 1),
                "correction_hits": h,
                "geometry": r.get("geometry", {}).get("coordinates", []),
            })
        row = (
            batch_id, o_name, o_lat, o_lon, d_name, d_lat, d_lon,
            float(chosen.get("distance", 0)), float(chosen.get("duration", 0)),
            float(score), len(hits),
            json.dumps(chosen.get("geometry", {}).get("coordinates", []), separators=(",", ":")),
            json.dumps(alternatives, separators=(",", ":")),
            "new", "", "", created,
        )
    except Exception as exc:
        row = (
            batch_id, o_name, o_lat, o_lon, d_name, d_lat, d_lon,
            0, 0, 0, 0, "[]", "[]", "error", "", str(exc)[:500], created,
        )
    with db_lock, db() as con:
        con.execute(
            """INSERT INTO scenarios(
            batch_id,origin_name,origin_lat,origin_lon,destination_name,destination_lat,destination_lon,
            distance_m,duration_s,score,correction_hits,geometry_json,alternatives_json,status,review_note,error,created_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            row,
        )


def simulate_batch(count, seed):
    count = max(1, min(200, int(count)))
    rng = random.Random(seed)
    batch_id = f"sim-{int(time.time())}-{rng.randint(1000,9999)}"
    pairs = []
    used = set()
    while len(pairs) < count:
        a, b = rng.sample(range(len(ANCHORS)), 2)
        key = (a, b)
        if key in used:
            continue
        used.add(key)
        pairs.append((ANCHORS[a], ANCHORS[b]))
    corrections = active_corrections()
    workers = max(1, min(6, int(os.environ.get("ROUTEPILOT_SIM_WORKERS", "4"))))
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(simulate_pair, batch_id, a, b, corrections) for a, b in pairs]
        for f in as_completed(futures):
            f.result()
    return batch_id


class Handler(BaseHTTPRequestHandler):
    server_version = "RoutePilotSimulator/3.3"

    def log_message(self, fmt, *args):
        print("[sim]", fmt % args)

    def _authorized(self):
        if not TOKEN:
            return True
        auth = self.headers.get("Authorization", "")
        return auth == f"Bearer {TOKEN}" or self.headers.get("X-RoutePilot-Token", "") == TOKEN

    def send_json(self, payload, status=200):
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def read_json(self):
        length = int(self.headers.get("Content-Length", "0"))
        if length > 2_000_000:
            raise ValueError("Payload te groot")
        raw = self.rfile.read(length) if length else b"{}"
        return json.loads(raw.decode("utf-8") or "{}")

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        if path.startswith("/api/") and not self._authorized():
            return self.send_json({"error": "unauthorized"}, 401)

        if path == "/api/health":
            return self.send_json({"ok": True, "version": "3.3", "router": ROUTER, "db": str(DB_PATH)})

        if path == "/api/corrections" or path == "/api/corrections/export":
            return self.send_json({"version": 1, "generated_at": now_ms(), "corrections": active_corrections()})

        if path == "/api/driver-trips":
            q = urllib.parse.parse_qs(parsed.query)
            limit = max(1, min(200, int(q.get("limit", ["50"])[0])))
            with db_lock, db() as con:
                rows = con.execute(
                    "SELECT * FROM driver_trips ORDER BY uploaded_at DESC LIMIT ?", (limit,)
                ).fetchall()
            out = []
            for r in rows:
                d = dict(r)
                d["planned_points"] = json.loads(d.pop("planned_json"))
                d["actual_points"] = json.loads(d.pop("actual_json"))
                out.append(d)
            return self.send_json({"driver_trips": out})

        if path == "/api/scenarios":
            q = urllib.parse.parse_qs(parsed.query)
            limit = max(1, min(500, int(q.get("limit", ["150"])[0])))
            with db_lock, db() as con:
                rows = con.execute("SELECT * FROM scenarios ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
            out = []
            for r in rows:
                d = dict(r)
                d["geometry"] = json.loads(d.pop("geometry_json"))
                d["alternatives"] = json.loads(d.pop("alternatives_json"))
                out.append(d)
            return self.send_json({"scenarios": out})

        if path == "/api/stats":
            with db_lock, db() as con:
                total = con.execute("SELECT COUNT(*) FROM scenarios").fetchone()[0]
                accepted = con.execute("SELECT COUNT(*) FROM scenarios WHERE status='accepted'").fetchone()[0]
                rejected = con.execute("SELECT COUNT(*) FROM scenarios WHERE status='rejected'").fetchone()[0]
                errors = con.execute("SELECT COUNT(*) FROM scenarios WHERE status='error'").fetchone()[0]
                corrections = con.execute("SELECT COUNT(*) FROM corrections WHERE active=1").fetchone()[0]
                driver_trips = con.execute("SELECT COUNT(*) FROM driver_trips").fetchone()[0]
            return self.send_json({
                "total": total, "accepted": accepted, "rejected": rejected,
                "errors": errors, "corrections": corrections, "driver_trips": driver_trips
            })

        if path.startswith("/api/scenarios/"):
            try:
                sid = int(path.rsplit("/", 1)[1])
            except Exception:
                return self.send_json({"error": "bad id"}, 400)
            with db_lock, db() as con:
                r = con.execute("SELECT * FROM scenarios WHERE id=?", (sid,)).fetchone()
            if not r:
                return self.send_json({"error": "not found"}, 404)
            d = dict(r)
            d["geometry"] = json.loads(d.pop("geometry_json"))
            d["alternatives"] = json.loads(d.pop("alternatives_json"))
            return self.send_json(d)

        return self.serve_static(path)

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        if path.startswith("/api/") and not self._authorized():
            return self.send_json({"error": "unauthorized"}, 401)
        try:
            payload = self.read_json()
        except Exception as exc:
            return self.send_json({"error": str(exc)}, 400)

        if path == "/api/driver-trips":
            trip_id = str(payload.get("trip_id", "")).strip()[:120]
            if not trip_id:
                return self.send_json({"error": "trip_id ontbreekt"}, 400)
            destination = str(payload.get("destination", ""))[:300]
            planned = payload.get("planned_points") or []
            actual = payload.get("actual_points") or []
            if not isinstance(planned, list) or not isinstance(actual, list):
                return self.send_json({"error": "ongeldige trackdata"}, 400)
            if len(planned) > 2500 or len(actual) > 2500:
                return self.send_json({"error": "trackdata te groot"}, 400)
            with db_lock, db() as con:
                con.execute(
                    """INSERT INTO driver_trips(
                    trip_id,destination,started_at,ended_at,planned_distance_m,actual_distance_m,
                    warnings,reroutes,planned_json,actual_json,review_status,review_note,uploaded_at
                    ) VALUES(?,?,?,?,?,?,?,?,?,?, 'new','',?)
                    ON CONFLICT(trip_id) DO UPDATE SET
                    destination=excluded.destination,started_at=excluded.started_at,
                    ended_at=excluded.ended_at,planned_distance_m=excluded.planned_distance_m,
                    actual_distance_m=excluded.actual_distance_m,warnings=excluded.warnings,
                    reroutes=excluded.reroutes,planned_json=excluded.planned_json,
                    actual_json=excluded.actual_json,uploaded_at=excluded.uploaded_at""",
                    (
                        trip_id, destination, int(payload.get("started_at", 0) or 0),
                        int(payload.get("ended_at", 0) or 0),
                        float(payload.get("planned_distance_m", 0) or 0),
                        float(payload.get("actual_distance_m", 0) or 0),
                        int(payload.get("warnings", 0) or 0),
                        int(payload.get("reroutes", 0) or 0),
                        json.dumps(planned, separators=(",", ":")),
                        json.dumps(actual, separators=(",", ":")),
                        now_ms(),
                    ),
                )
            return self.send_json({"ok": True, "trip_id": trip_id}, 201)

        if path == "/api/simulate":
            count = payload.get("count", 25)
            seed = payload.get("seed", int(time.time()))
            batch = simulate_batch(count, seed)
            return self.send_json({"ok": True, "batch_id": batch})

        if path == "/api/corrections":
            t = str(payload.get("type", "")).strip()
            allowed = {
                "avoid", "prefer", "road_closed", "too_narrow", "bus_trap",
                "height_block", "entrance", "good_stop", "bad_stop",
                "ignore_door_side", "bus_lane_allowed", "bus_lane_forbidden",
                "turning_ok", "lift_ok", "lift_bad"
            }
            if t not in allowed:
                return self.send_json({"error": "ongeldig correctietype"}, 400)
            try:
                lat = float(payload["lat"]); lon = float(payload["lon"])
                radius = max(5.0, min(1000.0, float(payload.get("radius_m", 45))))
                strength = max(1, min(5, int(payload.get("strength", 1))))
            except Exception:
                return self.send_json({"error": "ongeldige locatie/waarden"}, 400)
            note = str(payload.get("note", ""))[:500]
            with db_lock, db() as con:
                cur = con.execute(
                    "INSERT INTO corrections(type,lat,lon,radius_m,strength,note,active,created_at) VALUES(?,?,?,?,?,?,1,?)",
                    (t, lat, lon, radius, strength, note, now_ms()),
                )
                cid = cur.lastrowid
            return self.send_json({"ok": True, "id": cid}, 201)

        if path.startswith("/api/scenarios/") and path.endswith("/review"):
            try:
                sid = int(path.split("/")[3])
            except Exception:
                return self.send_json({"error": "bad id"}, 400)
            status = str(payload.get("status", "")).strip()
            if status not in ("accepted", "rejected", "new"):
                return self.send_json({"error": "bad status"}, 400)
            note = str(payload.get("note", ""))[:1000]
            with db_lock, db() as con:
                con.execute("UPDATE scenarios SET status=?,review_note=? WHERE id=?", (status, note, sid))
            return self.send_json({"ok": True})

        return self.send_json({"error": "not found"}, 404)

    def do_DELETE(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        if path.startswith("/api/") and not self._authorized():
            return self.send_json({"error": "unauthorized"}, 401)
        if path.startswith("/api/corrections/"):
            try:
                cid = int(path.rsplit("/", 1)[1])
            except Exception:
                return self.send_json({"error": "bad id"}, 400)
            with db_lock, db() as con:
                con.execute("UPDATE corrections SET active=0 WHERE id=?", (cid,))
            return self.send_json({"ok": True})
        return self.send_json({"error": "not found"}, 404)

    def serve_static(self, path):
        if path == "/":
            path = "/index.html"
        safe = Path(path.lstrip("/"))
        if ".." in safe.parts:
            self.send_error(403)
            return
        file = STATIC / safe
        if not file.exists() or not file.is_file():
            self.send_error(404)
            return
        mime = {
            ".html": "text/html; charset=utf-8",
            ".js": "application/javascript; charset=utf-8",
            ".css": "text/css; charset=utf-8",
            ".json": "application/json; charset=utf-8",
        }.get(file.suffix.lower(), "application/octet-stream")
        data = file.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", mime)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        self.wfile.write(data)


if __name__ == "__main__":
    print(f"RoutePilot Simulator 3.3 → http://{HOST}:{PORT}")
    print(f"Database: {DB_PATH}")
    if not TOKEN and HOST not in ("127.0.0.1", "localhost", "::1"):
        print("WAARSCHUWING: geen ROUTEPILOT_PORTAL_TOKEN ingesteld op een niet-lokale bind.")
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
