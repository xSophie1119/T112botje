#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import math
import os
import random
import re
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
USER_AGENT = "RoutePilot-Simulator/3.5.0 (+https://github.com/xSophie1119/T112botje)"

# Regiovervoer Midden-Brabant: 8 gemeenten vormen het binnengebied.
INNER_MUNICIPALITIES={
    "Tilburg","Waalwijk","Dongen","Gilze en Rijen",
    "Oisterwijk","Hilvarenbeek","Goirle","Loon op Zand",
}

# Veel meer spreiding dan de oude Tilburg-only wijkankers.
# tuple: label, lat, lon, gemeente, zone
AREA_SEEDS=[
    # Binnengebied — Tilburg
    ("Tilburg Centrum",51.5555,5.0913,"Tilburg","inside"),
    ("Tilburg Reeshof",51.5750,5.0030,"Tilburg","inside"),
    ("Tilburg Noord",51.5865,5.0710,"Tilburg","inside"),
    ("Tilburg Zuid",51.5350,5.0830,"Tilburg","inside"),
    ("Tilburg Oost",51.5590,5.1170,"Tilburg","inside"),
    ("Berkel-Enschot",51.5860,5.1420,"Tilburg","inside"),
    ("Udenhout",51.6090,5.1430,"Tilburg","inside"),

    # Overige binnengebied-gemeenten
    ("Waalwijk",51.6820,5.0700,"Waalwijk","inside"),
    ("Sprang-Capelle",51.6720,5.0490,"Waalwijk","inside"),
    ("Waspik",51.6860,4.9440,"Waalwijk","inside"),
    ("Dongen",51.6260,4.9380,"Dongen","inside"),
    ("Rijen",51.5900,4.9190,"Gilze en Rijen","inside"),
    ("Gilze",51.5440,4.9400,"Gilze en Rijen","inside"),
    ("Oisterwijk",51.5790,5.1950,"Oisterwijk","inside"),
    ("Moergestel",51.5440,5.1840,"Oisterwijk","inside"),
    ("Haaren",51.6020,5.2220,"Oisterwijk","inside"),
    ("Hilvarenbeek",51.4860,5.1370,"Hilvarenbeek","inside"),
    ("Diessen",51.4750,5.1760,"Hilvarenbeek","inside"),
    ("Goirle",51.5200,5.0670,"Goirle","inside"),
    ("Riel",51.5230,5.0220,"Goirle","inside"),
    ("Kaatsheuvel",51.6600,5.0300,"Loon op Zand","inside"),
    ("Loon op Zand",51.6270,5.0750,"Loon op Zand","inside"),
    ("De Moer",51.6270,5.0130,"Loon op Zand","inside"),

    # Buitenring / omliggende gemeenten. Een adres hier is pas geldig als
    # de uiteindelijke rit <=25 km is en de andere zijde in het binnengebied ligt.
    ("Breda Noord",51.6020,4.7750,"Breda","outside"),
    ("Breda Oost",51.5840,4.8220,"Breda","outside"),
    ("Oosterhout",51.6450,4.8600,"Oosterhout","outside"),
    ("Raamsdonksveer",51.6960,4.8730,"Geertruidenberg","outside"),
    ("Drunen",51.6870,5.1340,"Heusden","outside"),
    ("Vlijmen",51.6960,5.2110,"Heusden","outside"),
    ("Vught",51.6540,5.2870,"Vught","outside"),
    ("Boxtel",51.5900,5.3290,"Boxtel","outside"),
    ("Oirschot",51.5050,5.3130,"Oirschot","outside"),
    ("Alphen",51.4810,4.9580,"Alphen-Chaam","outside"),
    ("Chaam",51.5050,4.8610,"Alphen-Chaam","outside"),
    ("Baarle-Nassau",51.4430,4.9300,"Baarle-Nassau","outside"),
]

# Alias voor bestaande smoke tests.
ANCHORS=[(name,lat,lon) for name,lat,lon,_municipality,_zone in AREA_SEEDS]

HOSPITAL_SPECS=[
    # naam, adresquery, gemeente, zone
    ("ETZ Elisabeth","Hilvarenbeekseweg 60 5022 GC Tilburg","Tilburg","inside"),
    ("ETZ TweeSteden","Dr. Deelenlaan 5 5042 AD Tilburg","Tilburg","inside"),
    ("ETZ Waalwijk","Kasteellaan 2 5141 BM Waalwijk","Waalwijk","inside"),
    ("Amphia Breda","Molengracht 21 4818 CK Breda","Breda","outside"),
    ("Amphia Oosterhout","Pasteurlaan 9 4901 DH Oosterhout","Oosterhout","outside"),
    ("Jeroen Bosch Ziekenhuis","Henri Dunantstraat 1 5223 GZ 's-Hertogenbosch","'s-Hertogenbosch","outside"),
]

# Steden/plaatsen waarmee OSM-zorginstellingen aan de 8 regiogemeenten
# gekoppeld kunnen worden.
PLACE_TO_MUNICIPALITY={
    "tilburg":"Tilburg","berkel-enschot":"Tilburg","udenhout":"Tilburg",
    "waalwijk":"Waalwijk","sprang-capelle":"Waalwijk","waspik":"Waalwijk",
    "dongen":"Dongen","rijen":"Gilze en Rijen","gilze":"Gilze en Rijen",
    "oisterwijk":"Oisterwijk","moergestel":"Oisterwijk","haaren":"Oisterwijk",
    "hilvarenbeek":"Hilvarenbeek","diessen":"Hilvarenbeek",
    "goirle":"Goirle","riel":"Goirle",
    "kaatsheuvel":"Loon op Zand","loon op zand":"Loon op Zand","de moer":"Loon op Zand",
}


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
CREATE TABLE IF NOT EXISTS training_locations (
    cache_key TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    lat REAL NOT NULL,
    lon REAL NOT NULL,
    source TEXT NOT NULL DEFAULT 'PDOK',
    area_name TEXT NOT NULL DEFAULT '',
    municipality TEXT NOT NULL DEFAULT '',
    zone TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'general',
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS batch_runs (
    batch_id TEXT PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'queued',
    stage TEXT NOT NULL DEFAULT 'Wachten',
    requested_count INTEGER NOT NULL,
    completed_count INTEGER NOT NULL DEFAULT 0,
    success_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0,
    message TEXT NOT NULL DEFAULT '',
    seed INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
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
    origin_target_lat REAL NOT NULL DEFAULT 0,
    origin_target_lon REAL NOT NULL DEFAULT 0,
    destination_target_lat REAL NOT NULL DEFAULT 0,
    destination_target_lon REAL NOT NULL DEFAULT 0,
    origin_stop_name TEXT NOT NULL DEFAULT '',
    destination_stop_name TEXT NOT NULL DEFAULT '',
    origin_target_to_stop_m REAL NOT NULL DEFAULT 0,
    destination_target_to_stop_m REAL NOT NULL DEFAULT 0,
    generator_version TEXT NOT NULL DEFAULT 'legacy',
    trip_category TEXT NOT NULL DEFAULT 'general',
    origin_zone TEXT NOT NULL DEFAULT '',
    destination_zone TEXT NOT NULL DEFAULT '',
    origin_municipality TEXT NOT NULL DEFAULT '',
    destination_municipality TEXT NOT NULL DEFAULT '',
    ring_rule TEXT NOT NULL DEFAULT '',
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
    existing={row["name"] for row in con.execute("PRAGMA table_info(scenarios)").fetchall()}
    additions={
        "origin_target_lat":"REAL NOT NULL DEFAULT 0",
        "origin_target_lon":"REAL NOT NULL DEFAULT 0",
        "destination_target_lat":"REAL NOT NULL DEFAULT 0",
        "destination_target_lon":"REAL NOT NULL DEFAULT 0",
        "origin_stop_name":"TEXT NOT NULL DEFAULT ''",
        "destination_stop_name":"TEXT NOT NULL DEFAULT ''",
        "origin_target_to_stop_m":"REAL NOT NULL DEFAULT 0",
        "destination_target_to_stop_m":"REAL NOT NULL DEFAULT 0",
        "generator_version":"TEXT NOT NULL DEFAULT 'legacy'",
        "trip_category":"TEXT NOT NULL DEFAULT 'general'",
        "origin_zone":"TEXT NOT NULL DEFAULT ''",
        "destination_zone":"TEXT NOT NULL DEFAULT ''",
        "origin_municipality":"TEXT NOT NULL DEFAULT ''",
        "destination_municipality":"TEXT NOT NULL DEFAULT ''",
        "ring_rule":"TEXT NOT NULL DEFAULT ''",
    }
    for name,definition in additions.items():
        if name not in existing:
            con.execute(f"ALTER TABLE scenarios ADD COLUMN {name} {definition}")

    tl_existing={row["name"] for row in con.execute("PRAGMA table_info(training_locations)").fetchall()}
    tl_additions={
        "municipality":"TEXT NOT NULL DEFAULT ''",
        "zone":"TEXT NOT NULL DEFAULT ''",
        "category":"TEXT NOT NULL DEFAULT 'general'",
    }
    for name,definition in tl_additions.items():
        if name not in tl_existing:
            con.execute(f"ALTER TABLE training_locations ADD COLUMN {name} {definition}")
    # Oude scenario's zijn gegenereerd met grove wijkankers en worden niet
    # langer als geldige training aangeboden. Correcties staan in een aparte
    # tabel en blijven behouden.
    con.execute(
        "UPDATE scenarios SET generator_version='legacy' "
        "WHERE generator_version IS NULL OR generator_version='' OR generator_version<>'3.5.0'"
    )
    con.execute(
        "UPDATE batch_runs SET status='failed',stage='Onderbroken',"
        "message='De simulator is opnieuw gestart tijdens deze batch.',updated_at=? "
        "WHERE status IN ('queued','running')",
        (now_ms() if 'now_ms' in globals() else int(time.time()*1000),)
    )


def now_ms():
    return int(time.time() * 1000)


def haversine_m(a_lat, a_lon, b_lat, b_lon):
    r = 6371000.0
    p1, p2 = math.radians(a_lat), math.radians(b_lat)
    dp = math.radians(b_lat - a_lat)
    dl = math.radians(b_lon - a_lon)
    h = math.sin(dp/2)**2 + math.cos(p1) * math.cos(p2) * math.sin(dl/2)**2
    return 2 * r * math.asin(min(1, math.sqrt(h)))


PDOK_FREE="https://api.pdok.nl/bzk/locatieserver/search/v3_1/free"
OVERPASS_URL="https://overpass-api.de/api/interpreter"
TRAINING_LOCATION_TTL_MS=30*24*3600*1000
_training_location_cache={}
_training_location_lock=threading.Lock()

def _parse_wkt_point(value):
    text=str(value or "").strip()
    if not text.upper().startswith("POINT(") or not text.endswith(")"):
        return None
    try:
        parts=text[text.find("(")+1:-1].strip().split()
        if len(parts)<2:
            return None
        lon=float(parts[0]);lat=float(parts[1])
        return lat,lon
    except Exception:
        return None

def _cached_training_locations(area_name,category=None):
    cutoff=now_ms()-TRAINING_LOCATION_TTL_MS
    where="area_name=?"
    args=[area_name]
    if category:
        where+=" AND category=?"
        args.append(category)
    with db_lock,db() as con:
        fresh=con.execute(
            "SELECT name,lat,lon,source,area_name,municipality,zone,category "
            "FROM training_locations WHERE "+where+" AND created_at>=? "
            "ORDER BY name LIMIT 150",
            tuple(args+[cutoff])
        ).fetchall()
        if fresh:
            return [dict(r) for r in fresh]
        stale=con.execute(
            "SELECT name,lat,lon,source,area_name,municipality,zone,category "
            "FROM training_locations WHERE "+where+" ORDER BY created_at DESC LIMIT 150",
            tuple(args)
        ).fetchall()
    return [dict(r) for r in stale]

def _store_training_locations(area_name,items):
    if not items:
        return
    stamp=now_ms()
    with db_lock,db() as con:
        for item in items:
            key_raw=(
                f"{item['name']}|{item['lat']:.6f}|{item['lon']:.6f}|"
                f"{item.get('category','general')}"
            )
            key=hashlib.sha256(key_raw.encode()).hexdigest()
            con.execute(
                "INSERT OR REPLACE INTO training_locations("
                "cache_key,name,lat,lon,source,area_name,municipality,zone,category,created_at"
                ") VALUES(?,?,?,?,?,?,?,?,?,?)",
                (
                    key,item["name"],item["lat"],item["lon"],
                    item.get("source","PDOK"),area_name,
                    item.get("municipality",""),item.get("zone",""),
                    item.get("category","general"),stamp
                )
            )

def discover_training_locations(anchor):
    area_name,lat,lon,expected_municipality,zone=anchor
    key=(area_name,round(lat,4),round(lon,4),expected_municipality,zone)
    with _training_location_lock:
        cached=_training_location_cache.get(key)
    if cached is not None:
        return list(cached)

    disk=_cached_training_locations(area_name,"general")
    fresh_cutoff=now_ms()-TRAINING_LOCATION_TTL_MS
    with db_lock,db() as con:
        fresh_count=con.execute(
            "SELECT COUNT(*) FROM training_locations "
            "WHERE area_name=? AND category='general' AND created_at>=?",
            (area_name,fresh_cutoff)
        ).fetchone()[0]
    if fresh_count>=16 and disk:
        with _training_location_lock:
            _training_location_cache[key]=list(disk)
        return list(disk)

    params={
        "q":"*:*",
        "rows":"100",
        "lat":f"{lat:.7f}",
        "lon":f"{lon:.7f}",
        "fq":"type:adres",
        "fl":"weergavenaam,centroide_ll,type,gemeentenaam",
        "wt":"json",
    }
    req=urllib.request.Request(
        PDOK_FREE+"?"+urllib.parse.urlencode(params),
        headers={"User-Agent":USER_AGENT,"Accept":"application/json"},
    )

    found=[]
    try:
        with urllib.request.urlopen(req,timeout=9) as resp:
            root=json.loads(resp.read().decode("utf-8"))
        docs=((root.get("response") or {}).get("docs") or [])
        seen=set()
        for doc in docs:
            point=_parse_wkt_point(doc.get("centroide_ll"))
            name=str(doc.get("weergavenaam","") or "").strip()
            municipality=str(doc.get("gemeentenaam","") or "").strip()
            if not point or not name:
                continue
            if municipality and municipality.lower()!=expected_municipality.lower():
                continue
            la,lo=point
            dist=haversine_m(lat,lon,la,lo)
            if dist>2400.0:
                continue
            k=(name.lower(),round(la,6),round(lo,6))
            if k in seen:
                continue
            seen.add(k)
            found.append({
                "name":name,"lat":la,"lon":lo,
                "area":area_name,"source":"PDOK/BAG",
                "distance_m":dist,
                "municipality":expected_municipality,
                "zone":zone,
                "category":"general",
            })
        # Niet steeds de eerste straat rond een seed pakken: verdeel over
        # afstandsringen en sorteer pas daarna.
        found.sort(key=lambda x:x["distance_m"])
        _store_training_locations(area_name,found[:80])
        found=found[:80]
    except Exception:
        found=list(disk)

    if not found:
        raise RuntimeError(f"Geen BAG-adressen beschikbaar rond {area_name}.")
    with _training_location_lock:
        _training_location_cache[key]=list(found)
    return found

def _pdok_geocode(query,label,municipality,zone,category):
    params={
        "q":query,"rows":"8","fq":"type:adres",
        "fl":"weergavenaam,centroide_ll,gemeentenaam","wt":"json",
    }
    req=urllib.request.Request(
        PDOK_FREE+"?"+urllib.parse.urlencode(params),
        headers={"User-Agent":USER_AGENT,"Accept":"application/json"},
    )
    with urllib.request.urlopen(req,timeout=9) as resp:
        root=json.loads(resp.read().decode("utf-8"))
    docs=((root.get("response") or {}).get("docs") or [])
    best=None
    for doc in docs:
        point=_parse_wkt_point(doc.get("centroide_ll"))
        if not point:
            continue
        mun=str(doc.get("gemeentenaam","") or "")
        if municipality and mun and mun.lower()!=municipality.lower():
            continue
        best=(doc,point)
        break
    if not best:
        raise RuntimeError("Adres niet gevonden: "+query)
    doc,(lat,lon)=best
    return {
        "name":label+" · "+str(doc.get("weergavenaam") or query),
        "lat":lat,"lon":lon,"source":"PDOK/BAG",
        "area":label,"municipality":municipality,"zone":zone,
        "category":category,
    }

def hospital_locations():
    cached=_cached_training_locations("__hospitals__","hospital")
    if len(cached)>=5:
        return cached

    found=[]
    for label,query,municipality,zone in HOSPITAL_SPECS:
        try:
            found.append(_pdok_geocode(
                query,label,municipality,zone,"hospital"
            ))
        except Exception:
            continue
    if found:
        _store_training_locations("__hospitals__",found)
    if len(found)<3 and cached:
        found=cached
    if len(found)<3:
        raise RuntimeError("Te weinig ziekenhuislocaties beschikbaar.")
    return found

def _osm_point(element):
    if "lat" in element and "lon" in element:
        return float(element["lat"]),float(element["lon"])
    center=element.get("center") or {}
    if "lat" in center and "lon" in center:
        return float(center["lat"]),float(center["lon"])
    return None

def _nearest_inner_municipality(lat,lon):
    candidates=[]
    for name,a_lat,a_lon,municipality,zone in AREA_SEEDS:
        if zone!="inside":
            continue
        candidates.append((
            haversine_m(lat,lon,a_lat,a_lon),municipality,name
        ))
    candidates.sort(key=lambda x:x[0])
    return candidates[0] if candidates else (1e9,"","")

def care_locations():
    cached=_cached_training_locations("__care__","care")
    if len(cached)>=12:
        return cached

    # Alleen categorie-ontdekking gebruikt Overpass; gewone adressen en
    # iedere route zelf zijn hier niet meer van afhankelijk.
    query=(
        '[out:json][timeout:20];('
        'nwr["social_facility"~"nursing_home|assisted_living|group_home"](51.43,4.88,51.72,5.25);'
        'nwr["amenity"="nursing_home"](51.43,4.88,51.72,5.25);'
        'nwr["healthcare"="nursing_home"](51.43,4.88,51.72,5.25);'
        ');out center tags;'
    )
    payload=urllib.parse.urlencode({"data":query}).encode("utf-8")
    req=urllib.request.Request(
        OVERPASS_URL,data=payload,method="POST",
        headers={
            "User-Agent":USER_AGENT,
            "Accept":"application/json",
            "Content-Type":"application/x-www-form-urlencoded; charset=UTF-8",
        }
    )

    found=[]
    try:
        with urllib.request.urlopen(req,timeout=20) as resp:
            root=json.loads(resp.read().decode("utf-8"))
        seen=set()
        for element in root.get("elements",[]):
            point=_osm_point(element)
            tags=element.get("tags") or {}
            name=str(tags.get("name","") or "").strip()
            if not point or not name:
                continue
            lat,lon=point
            city=str(tags.get("addr:city","") or "").strip().lower()
            municipality=PLACE_TO_MUNICIPALITY.get(city,"")
            if not municipality:
                dist,municipality,_seed=_nearest_inner_municipality(lat,lon)
                if dist>9000:
                    continue
            if municipality not in INNER_MUNICIPALITIES:
                continue
            street=str(tags.get("addr:street","") or "").strip()
            number=str(tags.get("addr:housenumber","") or "").strip()
            address=" ".join(x for x in (street,number) if x).strip()
            label=name+(" · "+address if address else "")
            key=(name.lower(),round(lat,5),round(lon,5))
            if key in seen:
                continue
            seen.add(key)
            found.append({
                "name":label,"lat":lat,"lon":lon,
                "source":"OpenStreetMap zorg-POI",
                "area":municipality,"municipality":municipality,
                "zone":"inside","category":"care",
            })
        found=found[:100]
        if found:
            _store_training_locations("__care__",found)
    except Exception:
        found=list(cached)

    if len(found)<5:
        if len(cached)>=5:
            return cached
        raise RuntimeError("Te weinig zorginstellingen gevonden.")
    return found

def build_training_pools(rng,count,progress=None):
    general=[]
    seen=set()
    seeds=list(AREA_SEEDS)
    rng.shuffle(seeds)

    def fetch_seed(anchor):
        try:
            return anchor,discover_training_locations(anchor),None
        except Exception as exc:
            return anchor,[],str(exc)

    # Eerste run: maximaal vier gelijktijdige PDOK-requests. Daarna wordt
    # vrijwel alles uit de 30-dagen-cache gelezen.
    workers=max(1,min(4,int(os.environ.get("ROUTEPILOT_LOCATION_WORKERS","4"))))
    finished=0
    results=[]
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures=[pool.submit(fetch_seed,a) for a in seeds]
        for future in as_completed(futures):
            anchor,items,error=future.result()
            finished+=1
            if progress:
                progress(
                    "Bestemmingen verzamelen",
                    f"Regio-adressen {finished}/{len(seeds)} • {anchor[0]}"
                    +(f" • overgeslagen: {error[:60]}" if error else "")
                )
            results.append((anchor,items))

    # Verwerk pas na ophalen. Daardoor beïnvloedt netwerkvolgorde niet de
    # randomisatie/verdeling van een batch.
    rng.shuffle(results)
    for anchor,items in results:
        items=list(items)
        rng.shuffle(items)
        for item in items[:8]:
            key=(item["name"].lower(),round(item["lat"],6),round(item["lon"],6))
            if key in seen:
                continue
            seen.add(key)
            general.append(item)

    if len(general)<40:
        raise RuntimeError(
            f"Te weinig verspreide adressen gevonden ({len(general)})."
        )

    if progress:
        progress("Zorglocaties verzamelen","Ziekenhuizen en zorginstellingen voorbereiden…")
    hospitals=hospital_locations()
    care=care_locations()
    rng.shuffle(general);rng.shuffle(hospitals);rng.shuffle(care)
    return {
        "general":general,
        "hospital":hospitals,
        "care":care,
        "inside":[x for x in general if x.get("zone")=="inside"],
        "outside":[x for x in general if x.get("zone")=="outside"],
    }


def quota_plan(count):
    # Bij 25: exact minimaal 5 ziekenhuis + 5 zorg. Bij andere batchgroottes
    # dezelfde 20/20%-verhouding, met minstens 1 zodra er ruimte is.
    hospital=max(5,round(count*0.20)) if count>=25 else max(1,round(count*0.20))
    care=max(5,round(count*0.20)) if count>=25 else max(1,round(count*0.20))
    if hospital+care>count:
        care=max(0,count-hospital)
    general=count-hospital-care
    plan=["hospital"]*hospital+["care"]*care+["general"]*general
    return plan


SAFE_ENDPOINT_CLASSES = {
    "service": 0.0,
    "residential": 0.0,
    "living_street": 0.0,
    "unclassified": 8.0,
    "tertiary": 20.0,
    "secondary": 45.0,
    "primary": 85.0,
    "road": 25.0,
}
FORBIDDEN_ENDPOINT_CLASSES = {
    "motorway", "motorway_link", "trunk", "trunk_link",
    "construction", "proposed", "raceway", "steps", "path",
    "footway", "cycleway", "bridleway", "track",
}
_endpoint_cache = {}
_endpoint_cache_lock = threading.Lock()


def _project_on_segment(lat, lon, a_lat, a_lon, b_lat, b_lon):
    lat0 = math.radians((lat + a_lat + b_lat) / 3.0)
    mx = 111320.0 * math.cos(lat0)
    my = 110540.0
    ax, ay = a_lon * mx, a_lat * my
    bx, by = b_lon * mx, b_lat * my
    px, py = lon * mx, lat * my
    vx, vy = bx - ax, by - ay
    denom = vx * vx + vy * vy
    t = 0.0 if denom < 0.01 else ((px - ax) * vx + (py - ay) * vy) / denom
    t = max(0.0, min(1.0, t))
    qx, qy = ax + t * vx, ay + t * vy
    q_lon, q_lat = qx / mx, qy / my
    return q_lat, q_lon, haversine_m(lat, lon, q_lat, q_lon)


def _overpass_safe_endpoint(lat, lon):
    key = (round(lat, 4), round(lon, 4))
    with _endpoint_cache_lock:
        cached = _endpoint_cache.get(key)
    if cached:
        return cached

    query = (
        f'[out:json][timeout:9];'
        f'way(around:420,{lat:.6f},{lon:.6f})["highway"];'
        f'out geom tags;'
    )
    payload = urllib.parse.urlencode({"data": query}).encode("utf-8")
    req = urllib.request.Request(
        "https://overpass-api.de/api/interpreter",
        data=payload,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "application/json",
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=12) as resp:
        root = json.loads(resp.read().decode("utf-8"))

    best = None
    for element in root.get("elements", []):
        tags = element.get("tags") or {}
        highway = str(tags.get("highway", "")).strip()
        if not highway or highway in FORBIDDEN_ENDPOINT_CLASSES:
            continue
        if highway not in SAFE_ENDPOINT_CLASSES:
            continue

        access = str(tags.get("access", "")).lower()
        motor_vehicle = str(tags.get("motor_vehicle", "")).lower()
        if access in ("no", "private") or motor_vehicle in ("no", "private"):
            continue

        geometry = element.get("geometry") or []
        if len(geometry) < 2:
            continue

        for i in range(1, len(geometry)):
            a, b = geometry[i - 1], geometry[i]
            q_lat, q_lon, dist = _project_on_segment(
                lat, lon,
                float(a["lat"]), float(a["lon"]),
                float(b["lat"]), float(b["lon"]),
            )
            # Lokale wegen zijn sterk gewenst; hoofdwegen alleen als er echt
            # geen betere WMO-achtige straat dichtbij ligt.
            score = dist + SAFE_ENDPOINT_CLASSES[highway]
            if tags.get("service") == "parking_aisle":
                score -= 6.0
            if tags.get("bus") == "yes" and tags.get("motor_vehicle") == "no":
                score += 500.0

            if best is None or score < best["score"]:
                best = {
                    "lat": q_lat,
                    "lon": q_lon,
                    "distance_m": dist,
                    "score": score,
                    "highway": highway,
                    "road_name": str(tags.get("name", "") or tags.get("ref", "") or highway),
                }

    if best is None:
        raise RuntimeError("Geen geschikte lokale trainingsweg binnen 420 meter gevonden.")

    # Een trainingsbestemming hoort geen halve wijk van het anker te liggen.
    if best["distance_m"] > 260.0:
        raise RuntimeError(
            f"Geen geschikte lokale trainingsweg dichtbij genoeg gevonden "
            f"({best['distance_m']:.0f} m)."
        )

    with _endpoint_cache_lock:
        _endpoint_cache[key] = best
    return best


def safe_training_endpoint(name, lat, lon):
    # Concrete BAG-adressen zijn al betrouwbare doelen. Laat OSRM met een
    # beperkte radius zelf naar het wegennet snappen en valideer daarna het
    # werkelijke waypoint. Geen blokkerende Overpass-call meer.
    return (name,lat,lon,{
        "lat":lat,"lon":lon,"distance_m":0.0,
        "highway":"unknown","road_name":"","source":"PDOK/BAG",
    })

def looks_like_motorway_name(name):
    text=str(name or "").strip().upper().replace(" ","")
    return bool(re.match(r"^(A|E)\d{1,4}(\b|$)",text))


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
    key_raw = f"async-pdok-v4:{o_lat:.5f},{o_lon:.5f}>{d_lat:.5f},{d_lon:.5f}"
    cache_key = hashlib.sha256(key_raw.encode()).hexdigest()
    with db_lock, db() as con:
        row = con.execute("SELECT response_json,created_at FROM route_cache WHERE cache_key=?", (cache_key,)).fetchone()
    if row and now_ms() - int(row["created_at"]) < 7 * 24 * 3600 * 1000:
        return json.loads(row["response_json"])

    url = (
        f"{ROUTER}/route/v1/driving/{o_lon:.6f},{o_lat:.6f};{d_lon:.6f},{d_lat:.6f}"
        "?overview=full&geometries=geojson&steps=false&alternatives=3"
        "&continue_straight=true&radiuses=55;55"
    )
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"})
    last_error=None
    data=None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=12) as resp:
                data = json.loads(resp.read().decode("utf-8"))
            if data.get("code") == "Ok" and data.get("routes"):
                break
            last_error=RuntimeError(data.get("message") or "Geen OSRM-route")
        except Exception as exc:
            last_error=exc
        if attempt<2:
            time.sleep(0.7*(attempt+1))
    if not data or data.get("code") != "Ok" or not data.get("routes"):
        raise RuntimeError("OSRM-route mislukt na retries: "+str(last_error or "onbekend"))
    with db_lock, db() as con:
        con.execute(
            "INSERT OR REPLACE INTO route_cache(cache_key,response_json,created_at) VALUES(?,?,?)",
            (cache_key, json.dumps(data, separators=(",", ":")), now_ms()),
        )
    return data


def _is_point_destination_exception(origin,destination):
    o_mun=origin.get("municipality","")
    d_mun=destination.get("municipality","")
    o_name=origin.get("name","").lower()
    d_name=destination.get("name","").lower()
    municipalities={"Goirle","Hilvarenbeek"}
    return (
        (o_mun in municipalities and "etz waalwijk" in d_name)
        or (d_mun in municipalities and "etz waalwijk" in o_name)
    )

def validate_regio_trip(origin,destination,route_distance_m):
    o_zone=origin.get("zone","")
    d_zone=destination.get("zone","")

    if o_zone=="outside" and d_zone=="outside":
        return False,"buiten→buiten is niet toegestaan"

    if o_zone!="inside" and d_zone!="inside":
        return False,"rit moet beginnen of eindigen in het binnengebied"

    if route_distance_m<=25000.0:
        if o_zone=="inside" and d_zone=="inside":
            return True,"binnen→binnen • ≤25 km"
        if o_zone=="inside" and d_zone=="outside":
            return True,"binnen→buiten • ≤25 km"
        if o_zone=="outside" and d_zone=="inside":
            return True,"buiten→binnen • ≤25 km"

    if _is_point_destination_exception(origin,destination):
        return True,"puntbestemming ETZ Waalwijk • Goirle/Hilvarenbeek"

    return False,f"ritafstand {route_distance_m/1000.0:.1f} km > 25 km"

def simulate_pair(batch_id, origin, destination, corrections):
    original_o_name=origin["name"]
    original_o_lat=float(origin["lat"])
    original_o_lon=float(origin["lon"])
    original_d_name=destination["name"]
    original_d_lat=float(destination["lat"])
    original_d_lon=float(destination["lon"])
    category=destination.get("category","general")
    created=now_ms()

    o_lat,o_lon=original_o_lat,original_o_lon
    d_lat,d_lon=original_d_lat,original_d_lon
    o_stop_name=d_stop_name=""
    o_target_stop=d_target_stop=0.0
    ring_rule=""

    success=False
    try:
        _,route_o_lat,route_o_lon,o_meta=safe_training_endpoint(
            original_o_name,original_o_lat,original_o_lon
        )
        _,route_d_lat,route_d_lon,d_meta=safe_training_endpoint(
            original_d_name,original_d_lat,original_d_lon
        )
        data=cached_osrm_route(route_o_lat,route_o_lon,route_d_lat,route_d_lon)

        waypoints=data.get("waypoints") or []
        if len(waypoints)<2:
            raise RuntimeError("Router gaf geen exacte begin/eind-waypoints terug.")

        o_wp,d_wp=waypoints[0],waypoints[1]
        o_loc=o_wp.get("location") or []
        d_loc=d_wp.get("location") or []
        if len(o_loc)<2 or len(d_loc)<2:
            raise RuntimeError("Router-waypoint mist coördinaten.")

        o_lon,o_lat=float(o_loc[0]),float(o_loc[1])
        d_lon,d_lat=float(d_loc[0]),float(d_loc[1])
        o_stop_name=str(o_wp.get("name") or o_meta.get("road_name") or "route-stoppunt")
        d_stop_name=str(d_wp.get("name") or d_meta.get("road_name") or "route-stoppunt")
        if looks_like_motorway_name(o_stop_name):
            raise RuntimeError("Startpunt snapte naar een snelweg: "+o_stop_name)
        if looks_like_motorway_name(d_stop_name):
            raise RuntimeError("Eindpunt snapte naar een snelweg: "+d_stop_name)

        o_target_stop=haversine_m(original_o_lat,original_o_lon,o_lat,o_lon)
        d_target_stop=haversine_m(original_d_lat,original_d_lon,d_lat,d_lon)
        if o_target_stop>95.0:
            raise RuntimeError(
                f"Startlocatie ligt {o_target_stop:.0f} m van het echte route-stoppunt."
            )
        if d_target_stop>95.0:
            raise RuntimeError(
                f"Eindlocatie ligt {d_target_stop:.0f} m van het echte route-stoppunt."
            )

        candidates=[]
        all_route_distances=[]
        for route in data.get("routes",[])[:3]:
            coords=route.get("geometry",{}).get("coordinates",[])
            if not coords:
                continue
            last=coords[-1]
            geometry_gap=haversine_m(d_lat,d_lon,float(last[1]),float(last[0]))
            if geometry_gap>12.0:
                continue
            distance=float(route.get("distance",0))
            all_route_distances.append(distance)
            score,hits=route_score(route,corrections)
            candidates.append((score,route,hits))

        if not candidates:
            raise RuntimeError("Geen route eindigde betrouwbaar op het opgegeven route-stoppunt.")

        # Voor de 25-km spelregel gebruiken we de kortste door OSRM
        # gerapporteerde kandidaat. Dit is een simulatorbenadering van de
        # voorgeschreven routeplanner van Regiovervoer.
        eligibility_distance=min(all_route_distances)
        allowed,ring_rule=validate_regio_trip(origin,destination,eligibility_distance)
        if not allowed:
            raise RuntimeError("Regiovervoer-regel: "+ring_rule)

        candidates.sort(key=lambda x:x[0])
        score,chosen,hits=candidates[0]
        alternatives=[]
        for s,r,h in candidates:
            alternatives.append({
                "score":round(s,1),
                "distance_m":round(float(r.get("distance",0)),1),
                "duration_s":round(float(r.get("duration",0)),1),
                "correction_hits":h,
                "geometry":r.get("geometry",{}).get("coordinates",[]),
            })

        success=True
        row=(
            batch_id,
            original_o_name,o_lat,o_lon,
            original_d_name,d_lat,d_lon,
            original_o_lat,original_o_lon,
            original_d_lat,original_d_lon,
            o_stop_name,d_stop_name,
            o_target_stop,d_target_stop,
            "3.5.0",
            category,
            origin.get("zone",""),destination.get("zone",""),
            origin.get("municipality",""),destination.get("municipality",""),
            ring_rule,
            float(chosen.get("distance",0)),float(chosen.get("duration",0)),
            float(score),len(hits),
            json.dumps(chosen.get("geometry",{}).get("coordinates",[]),separators=(",",":")),
            json.dumps(alternatives,separators=(",",":")),
            "new","","",created,
        )
    except Exception as exc:
        row=(
            batch_id,
            original_o_name,o_lat,o_lon,
            original_d_name,d_lat,d_lon,
            original_o_lat,original_o_lon,
            original_d_lat,original_d_lon,
            o_stop_name,d_stop_name,
            o_target_stop,d_target_stop,
            "3.5.0",
            category,
            origin.get("zone",""),destination.get("zone",""),
            origin.get("municipality",""),destination.get("municipality",""),
            ring_rule,
            0,0,0,0,"[]","[]","error","",str(exc)[:500],created,
        )

    with db_lock,db() as con:
        con.execute(
            """INSERT INTO scenarios(
            batch_id,origin_name,origin_lat,origin_lon,destination_name,destination_lat,destination_lon,
            origin_target_lat,origin_target_lon,destination_target_lat,destination_target_lon,
            origin_stop_name,destination_stop_name,origin_target_to_stop_m,destination_target_to_stop_m,
            generator_version,trip_category,origin_zone,destination_zone,
            origin_municipality,destination_municipality,ring_rule,
            distance_m,duration_s,score,correction_hits,geometry_json,
            alternatives_json,status,review_note,error,created_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            row,
        )
    return success


def _update_batch(batch_id, *, status=None, stage=None, completed=None,
                  success=None, errors=None, message=None):
    fields=[];values=[]
    for key,value in (
        ("status",status),("stage",stage),("completed_count",completed),
        ("success_count",success),("error_count",errors),("message",message),
    ):
        if value is not None:
            fields.append(key+"=?");values.append(value)
    fields.append("updated_at=?");values.append(now_ms())
    values.append(batch_id)
    with db_lock,db() as con:
        con.execute(
            "UPDATE batch_runs SET "+",".join(fields)+" WHERE batch_id=?",
            tuple(values)
        )

def _batch_snapshot(batch_id):
    with db_lock,db() as con:
        row=con.execute("SELECT * FROM batch_runs WHERE batch_id=?",(batch_id,)).fetchone()
    return dict(row) if row else None

def _loc_key(item):
    return (
        item.get("name","").lower(),
        round(float(item.get("lat",0)),5),
        round(float(item.get("lon",0)),5),
    )

def _least_used_choice(rng,items,usage,max_repeat=None):
    if not items:
        return None
    shuffled=list(items)
    rng.shuffle(shuffled)
    min_use=min(usage.get(_loc_key(x),0) for x in shuffled)
    candidates=[
        x for x in shuffled
        if usage.get(_loc_key(x),0)==min_use
        and (max_repeat is None or usage.get(_loc_key(x),0)<max_repeat)
    ]
    if not candidates:
        candidates=shuffled
    return rng.choice(candidates)

def _pick_pair_for_category(
    rng,pools,category,used_pairs,origin_usage,destination_usage,
    municipality_usage,outside_pickups,target_outside_pickups
):
    for _ in range(900):
        if category=="hospital":
            dest_pool=pools["hospital"]
            destination=_least_used_choice(
                rng,dest_pool,destination_usage,max_repeat=2
            )
        elif category=="care":
            destination=_least_used_choice(
                rng,pools["care"],destination_usage,max_repeat=1
            )
        else:
            # Gewone ritten spreiden expliciet over binnen/buiten.
            mode_roll=rng.random()
            if mode_roll<0.50:
                mode="inside_inside"
            elif mode_roll<0.76:
                mode="inside_outside"
            else:
                mode="outside_inside"

            if mode=="inside_outside" and pools["outside"]:
                destination=_least_used_choice(
                    rng,pools["outside"],destination_usage,max_repeat=1
                )
            else:
                destination=_least_used_choice(
                    rng,pools["inside"],destination_usage,max_repeat=1
                )

        if destination is None:
            continue

        # Buitenbestemming => oorsprong verplicht binnengebied.
        if destination.get("zone")=="outside":
            origin_pool=pools["inside"]
        else:
            # Probeer ongeveer 25-30% van de ophaallocaties uit de buitenring
            # te laten komen. Nooit buiten->buiten.
            want_outside=(
                outside_pickups<target_outside_pickups
                and pools["outside"]
                and rng.random()<0.62
            )
            origin_pool=pools["outside"] if want_outside else pools["inside"]

        # Kies bij voorkeur gemeenten die in deze batch nog weinig aan bod kwamen.
        candidates=list(origin_pool)
        rng.shuffle(candidates)
        if not candidates:
            continue
        min_mun=min(
            municipality_usage.get(x.get("municipality",""),0)
            for x in candidates
        )
        spread=[
            x for x in candidates
            if municipality_usage.get(x.get("municipality",""),0)<=min_mun+1
        ]
        origin=_least_used_choice(rng,spread,origin_usage,max_repeat=1)
        if origin is None:
            continue

        if _loc_key(origin)==_loc_key(destination):
            continue
        if origin.get("zone")=="outside" and destination.get("zone")=="outside":
            continue

        direct=haversine_m(
            origin["lat"],origin["lon"],destination["lat"],destination["lon"]
        )
        # Snelle voorfilter. Definitieve grens is de OSRM-routeafstand.
        if direct<650.0 or direct>30000.0:
            continue

        key=(_loc_key(origin),_loc_key(destination),category)
        if key in used_pairs:
            continue
        used_pairs.add(key)
        return origin,destination
    return None

def run_batch(batch_id,count,seed):
    rng=random.Random(seed)
    completed=success=errors=0
    try:
        _update_batch(
            batch_id,status="running",stage="Bestemmingen verzamelen",
            message="Hele Regiovervoer-gebied en zorglocaties voorbereiden…"
        )

        def progress(stage,message):
            _update_batch(batch_id,stage=stage,message=message)

        pools=build_training_pools(rng,count,progress=progress)
        plan=quota_plan(count)
        rng.shuffle(plan)

        hospital_required=plan.count("hospital")
        care_required=plan.count("care")
        general_required=plan.count("general")
        _update_batch(
            batch_id,stage="Routes simuleren",
            message=(
                f"{len(pools['general'])} adressen • "
                f"{len(pools['hospital'])} ziekenhuizen • "
                f"{len(pools['care'])} zorginstellingen. "
                f"Doel: {hospital_required} ziekenhuis / "
                f"{care_required} zorg / {general_required} overig."
            )
        )

        corrections=active_corrections()
        workers=max(1,min(5,int(os.environ.get("ROUTEPILOT_SIM_WORKERS","4"))))
        used_pairs=set()
        origin_usage={}
        destination_usage={}
        municipality_usage={}
        target_outside_pickups=max(1,round(count*0.28))
        outside_pickups=0
        max_attempts=max(count*7,count+25)

        # Ieder vereist categorie-slot blijft in de queue totdat precies
        # die categorie een geldige rit heeft opgeleverd.
        pending=list(plan)
        successful_by_category={"hospital":0,"care":0,"general":0}

        with ThreadPoolExecutor(max_workers=workers) as pool:
            while pending and completed<max_attempts:
                wave_size=min(workers,len(pending),max_attempts-completed)
                jobs=[]
                selected_categories=[]

                for _ in range(wave_size):
                    category=pending.pop(0)
                    pair=_pick_pair_for_category(
                        rng,pools,category,used_pairs,
                        origin_usage,destination_usage,municipality_usage,
                        outside_pickups,target_outside_pickups
                    )
                    if pair is None:
                        pending.append(category)
                        continue
                    origin,destination=pair
                    future=pool.submit(
                        simulate_pair,batch_id,origin,destination,corrections
                    )
                    jobs.append((future,category,origin,destination))
                    selected_categories.append(category)

                if not jobs:
                    break

                for future,category,origin,destination in jobs:
                    completed+=1
                    try:
                        ok=bool(future.result())
                    except Exception:
                        ok=False

                    if ok:
                        success+=1
                        successful_by_category[category]+=1
                        ok_origin=_loc_key(origin)
                        ok_dest=_loc_key(destination)
                        origin_usage[ok_origin]=origin_usage.get(ok_origin,0)+1
                        destination_usage[ok_dest]=destination_usage.get(ok_dest,0)+1
                        municipality=origin.get("municipality","")
                        municipality_usage[municipality]=municipality_usage.get(municipality,0)+1
                        if origin.get("zone")=="outside":
                            outside_pickups+=1
                    else:
                        errors+=1
                        # Dezelfde categorie moet opnieuw geprobeerd worden.
                        pending.append(category)

                    _update_batch(
                        batch_id,
                        completed=completed,success=success,errors=errors,
                        stage="Routes simuleren",
                        message=(
                            f"{success}/{count} geldig • "
                            f"🏥 {successful_by_category['hospital']}/{hospital_required} • "
                            f"🏡 zorg {successful_by_category['care']}/{care_required} • "
                            f"📍 overig {successful_by_category['general']}/{general_required} • "
                            f"buitenring-ophaal {outside_pickups}"
                        )
                    )

        if not pending and success>=count:
            _update_batch(
                batch_id,status="completed",stage="Klaar",
                completed=completed,success=success,errors=errors,
                message=(
                    f"{success} geldige ritten: "
                    f"{successful_by_category['hospital']} ziekenhuis, "
                    f"{successful_by_category['care']} zorginstelling, "
                    f"{successful_by_category['general']} overig. "
                    f"{outside_pickups} ophaallocaties uit buitenring."
                )
            )
        elif success>0:
            _update_batch(
                batch_id,status="partial",stage="Gedeeltelijk klaar",
                completed=completed,success=success,errors=errors,
                message=(
                    f"{success}/{count} geldig. Quotum nog open: "
                    +", ".join(pending[:12])
                )
            )
        else:
            _update_batch(
                batch_id,status="failed",stage="Mislukt",
                completed=completed,success=0,errors=errors,
                message="Geen geldige Regiovervoer-trainingsrit gemaakt."
            )
    except Exception as exc:
        _update_batch(
            batch_id,status="failed",stage="Mislukt",
            completed=completed,success=success,errors=errors,
            message=str(exc)[:500]
        )


def create_batch(count,seed):
    count=max(1,min(200,int(count)))
    seed=int(seed)
    rng=random.Random(seed ^ int(time.time()*1000))
    batch_id=f"sim-{int(time.time())}-{rng.randint(1000,9999)}"
    stamp=now_ms()
    with db_lock,db() as con:
        con.execute(
            """INSERT INTO batch_runs(
            batch_id,status,stage,requested_count,completed_count,success_count,
            error_count,message,seed,created_at,updated_at
            ) VALUES(?,?,?,?,0,0,0,?,?,?,?)""",
            (batch_id,"queued","Wachten",count,"Batch wordt gestart…",seed,stamp,stamp)
        )
    thread=threading.Thread(
        target=run_batch,args=(batch_id,count,seed),
        name="routepilot-sim-"+batch_id,daemon=True
    )
    thread.start()
    return batch_id

def source_status():
    result={
        "pdok":{"ok":False,"detail":""},
        "osrm":{"ok":False,"detail":""},
        "cache":{"locations":0,"routes":0},
    }
    with db_lock,db() as con:
        result["cache"]["locations"]=con.execute(
            "SELECT COUNT(*) FROM training_locations"
        ).fetchone()[0]
        result["cache"]["routes"]=con.execute(
            "SELECT COUNT(*) FROM route_cache"
        ).fetchone()[0]

    try:
        params={
            "q":"*:*","rows":"1","lat":"51.5555","lon":"5.0913",
            "fq":"type:adres","fl":"weergavenaam,centroide_ll","wt":"json",
        }
        req=urllib.request.Request(
            PDOK_FREE+"?"+urllib.parse.urlencode(params),
            headers={"User-Agent":USER_AGENT,"Accept":"application/json"}
        )
        started=time.time()
        with urllib.request.urlopen(req,timeout=5) as resp:
            root=json.loads(resp.read().decode("utf-8"))
        docs=((root.get("response") or {}).get("docs") or [])
        if docs:
            result["pdok"]={
                "ok":True,
                "detail":f"PDOK/BAG bereikbaar ({(time.time()-started)*1000:.0f} ms)"
            }
    except Exception as exc:
        result["pdok"]["detail"]="PDOK/BAG fout: "+str(exc)[:160]

    try:
        url=(
            ROUTER+"/nearest/v1/driving/5.0913,51.5555"
            "?number=1"
        )
        req=urllib.request.Request(
            url,headers={"User-Agent":USER_AGENT,"Accept":"application/json"}
        )
        started=time.time()
        with urllib.request.urlopen(req,timeout=5) as resp:
            root=json.loads(resp.read().decode("utf-8"))
        if root.get("code")=="Ok":
            result["osrm"]={
                "ok":True,
                "detail":f"OSRM bereikbaar ({(time.time()-started)*1000:.0f} ms)"
            }
    except Exception as exc:
        result["osrm"]["detail"]="OSRM fout: "+str(exc)[:160]
    return result


class Handler(BaseHTTPRequestHandler):
    server_version = "RoutePilotSimulator/3.5.0"

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
            return self.send_json({"ok": True, "version": "3.5.0", "router": ROUTER, "db": str(DB_PATH)})

        if path == "/api/source-status":
            return self.send_json(source_status())

        if path.startswith("/api/batches/"):
            batch_id=path.rsplit("/",1)[1]
            snap=_batch_snapshot(batch_id)
            if not snap:
                return self.send_json({"error":"batch not found"},404)
            requested=max(1,int(snap.get("requested_count",1)))
            snap["progress_pct"]=min(
                100,
                round((int(snap.get("success_count",0))/requested)*100)
            )
            return self.send_json(snap)

        if path == "/api/batches":
            with db_lock,db() as con:
                rows=con.execute(
                    "SELECT * FROM batch_runs ORDER BY created_at DESC LIMIT 20"
                ).fetchall()
            return self.send_json({"batches":[dict(r) for r in rows]})

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
                include_legacy = q.get("include_legacy", ["0"])[0] == "1"
                if include_legacy:
                    rows = con.execute("SELECT * FROM scenarios ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
                else:
                    rows = con.execute(
                        "SELECT * FROM scenarios WHERE generator_version='3.5.0' ORDER BY id DESC LIMIT ?",
                        (limit,)
                    ).fetchall()
            out = []
            for r in rows:
                d = dict(r)
                d["geometry"] = json.loads(d.pop("geometry_json"))
                d["alternatives"] = json.loads(d.pop("alternatives_json"))
                out.append(d)
            return self.send_json({"scenarios": out})

        if path == "/api/stats":
            with db_lock, db() as con:
                total = con.execute("SELECT COUNT(*) FROM scenarios WHERE generator_version='3.5.0'").fetchone()[0]
                accepted = con.execute("SELECT COUNT(*) FROM scenarios WHERE generator_version='3.5.0' AND status='accepted'").fetchone()[0]
                rejected = con.execute("SELECT COUNT(*) FROM scenarios WHERE generator_version='3.5.0' AND status='rejected'").fetchone()[0]
                errors = con.execute("SELECT COUNT(*) FROM scenarios WHERE generator_version='3.5.0' AND status='error'").fetchone()[0]
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
            try:
                count=max(1,min(200,int(payload.get("count",25))))
                seed=int(payload.get("seed",int(time.time())))
            except Exception:
                return self.send_json({"error":"ongeldige count/seed"},400)
            batch=create_batch(count,seed)
            return self.send_json(
                {"ok":True,"batch_id":batch,"status":"queued"},
                202
            )

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
    print(f"RoutePilot Simulator 3.5.0 → http://{HOST}:{PORT}")
    print(f"Database: {DB_PATH}")
    if not TOKEN and HOST not in ("127.0.0.1", "localhost", "::1"):
        print("WAARSCHUWING: geen ROUTEPILOT_PORTAL_TOKEN ingesteld op een niet-lokale bind.")
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
