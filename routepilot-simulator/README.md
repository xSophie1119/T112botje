# RoutePilot Simulator + Correctieportaal

Lokale simulator voor RoutePilot. Het portaal genereert virtuele WMO-achtige ritten tussen openbare Tilburgse wijk-/zorgankers, vergelijkt meerdere OSRM-routes en laat de chauffeur de gekozen route op de kaart beoordelen en corrigeren.

## Starten

```bash
cd routepilot-simulator
python server.py
```

Open daarna: **http://127.0.0.1:8765**

Er zijn geen Python packages nodig; de server gebruikt alleen de standaardbibliotheek en SQLite.

## Wat je kunt corrigeren

- route/straat vermijden of juist prefereren;
- tijdelijke/vaste afsluiting;
- te smal / hoogteprobleem / bussluis;
- echte ingang;
- goed of slecht WMO-stoppunt;
- deurzijde hier negeren;
- busbaan toegestaan / verboden;
- keerruimte bevestigd;
- achterliftruimte goed / slecht.

Correcties worden lokaal in `routepilot-simulator.sqlite3` opgeslagen. Via `/api/corrections/export` kun je de actieve correctielaag als JSON ophalen.

## Massasimulatie

De portal kan per batch 1–200 ritten simuleren. Standaard wordt de publieke OSRM-demo gebruikt en worden resultaten 7 dagen gecachet. Voor veel/intensief testen is een eigen router aanbevolen:

```bash
ROUTEPILOT_ROUTER_URL=http://localhost:5000 python server.py
```

Je kunt het aantal parallelle routercalls beperken:

```bash
ROUTEPILOT_SIM_WORKERS=2 python server.py
```

## Beveiliging / netwerk

Standaard bindt de portal alleen aan localhost. Voor gebruik vanaf een andere computer/tablet op hetzelfde netwerk:

```bash
ROUTEPILOT_SIM_HOST=0.0.0.0 \
ROUTEPILOT_PORTAL_TOKEN='kies-een-lang-token' \
python server.py
```

De browser vraagt dan om het token. Publiceer deze ontwikkelserver niet rechtstreeks op internet.


## Correcties naar de Android-app synchroniseren

RoutePilot V3.2 kan de actieve portalcorrecties rechtstreeks ophalen.

1. Start het portaal op je computer.
2. Als de telefoon dezelfde computer niet via `127.0.0.1` kan bereiken, start de server op je LAN:
   ```bat
   set ROUTEPILOT_SIM_HOST=0.0.0.0
   set ROUTEPILOT_PORTAL_TOKEN=kies-een-lang-token
   python server.py
   ```
3. Open in RoutePilot **⚙ Voertuigprofiel**.
4. Vul bij Simulator / Correctieportaal de LAN-URL in, bijvoorbeeld `http://192.168.1.20:8765`, plus hetzelfde token.
5. Tik op **Sync portalcorrecties**.

De debugbuild staat lokale HTTP toe zodat een laptop op hetzelfde netwerk bereikbaar is. Een releasebuild houdt cleartext-verkeer uitgeschakeld.

### Wat de app met portalcorrecties doet

- `road_closed`, `bus_trap`, `height_block`, `too_narrow`: harde correctiehits; kunnen alternative/adaptive detours triggeren.
- `avoid` / `prefer`: beïnvloeden de routekeuze.
- `entrance`: vervangt het adrespunt voor de deurzijde-analyse.
- `good_stop`: wordt gebruikt als WMO-stoppunt.
- `ignore_door_side`: schakelt de rechterdeurvoorkeur lokaal uit.
- `lift_ok` / `lift_bad`: voedt de aankomst-/liftassistent.
- `turning_ok`: bevestigt keer-/vertrekruimte.
- busbaancorrecties beïnvloeden de voorkeur, maar officiële verkeersregels en fysieke bebording blijven leidend.
