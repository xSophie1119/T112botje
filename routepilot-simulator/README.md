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

## Makkelijk synchroniseren via Windows LAN

Gebruik **`START_SIMULATOR_LAN.bat`** als je de Android-app met het correctieportaal wilt synchroniseren.

De starter:

- bindt de simulator automatisch aan `0.0.0.0:8765`;
- zoekt het lokale IPv4-adres van de actieve netwerkadapter;
- maakt bij de eerste start automatisch een sterk portal-token;
- bewaart dat token in `portal-token.txt`, zodat je het niet iedere keer opnieuw in de app hoeft te wijzigen;
- toont exact de **Portal URL** en het **Portal token** die je in RoutePilot moet invullen;
- opent het webportaal automatisch op de computer.

Voorbeeld:

```text
Portal URL:
http://192.168.1.20:8765

Portal token:
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Open daarna in RoutePilot **⚙ Voertuigprofiel → Simulator / Correctieportaal**, vul beide waarden in en tik op **Sync portalcorrecties**.



## Trainingspunten en snelwegen

Vanaf simulator **3.3.1** worden trainingsankers eerst naar een geschikte lokale autoweg gesnapt. Snelwegen, snelwegopritten en trunk/trunk-link-wegen zijn uitgesloten als start- of eindpunt. Daarna gebruikt OSRM een kleine snapradius, zodat een wijk- of zorganker niet alsnog naar een verderop gelegen snelweg kan springen.


## Exacte trainingsbestemmingen (3.3.2)

De simulator gebruikt niet langer wijkankers als zichtbare eindbestemming. De wijkpunten dienen alleen nog om concrete publieke adressen en zorg-/maatschappelijke POI's te ontdekken.

Per trainingsrit worden apart opgeslagen en weergegeven:

- de echte doellocatie;
- de lokale weg waarop RoutePilot probeert te stoppen;
- het werkelijke OSRM-waypoint waar de route eindigt;
- de afstand tussen doellocatie en route-stop.

In de kaartreview is **paars** de echte bestemming en **groen** het werkelijke route-eindpunt. Als de router meer dan 95 meter van de concrete bestemming eindigt, wordt het scenario afgekeurd in plaats van stilzwijgend naar een andere locatie te verschuiven.

Oude scenario's die nog met grove wijkankers zijn gemaakt worden als legacy behandeld en verdwijnen uit de normale trainingslijst. Opgeslagen RoutePilot-correcties blijven behouden.


## Route slepen (3.6)

Open een trainingsrit en kies **✥ Route slepen**.

- witte/blauwe punten op de route zijn versleepbaar;
- het groene eindpunt is versleepbaar naar het gewenste WMO-stoppunt;
- klik op de route om een extra via-punt toe te voegen;
- rechtsklik een extra/routepunt om het te verwijderen;
- tijdens slepen wordt via OSRM direct een nieuwe previewroute berekend;
- de oorspronkelijke route blijft als referentie zichtbaar;
- **Correcties opslaan** zet verplaatste routepunten om in sterke `prefer`-correcties en een verplaatst eindpunt in een `good_stop`-correctie.

Daardoor verschijnen de wijzigingen in de normale correctielijst en worden ze via de bestaande portal-sync ook naar RoutePilot Android gestuurd.
