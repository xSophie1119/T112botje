package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URLEncoder;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

public final class OnlineServices {

    public static final String USER_AGENT =
            "RoutePilot/3.3-debug (WMO navigation; https://github.com/xSophie1119)";

    private static final Locale NL = new Locale("nl", "NL");

    private static final Map<String, SearchResult> SEARCH_CACHE =
            new LinkedHashMap<String, SearchResult>(50, 0.75f, true) {
                @Override
                protected boolean removeEldestEntry(Map.Entry<String, SearchResult> eldest) {
                    return size() > 50;
                }
            };

    private static final long ROUTE_CACHE_MS = 20_000L;
    private static final long RESTRICTION_CACHE_MS = 90_000L;

    private static final Map<String, TimedText> ROUTE_RESPONSE_CACHE =
            new LinkedHashMap<String, TimedText>(12, 0.75f, true) {
                @Override protected boolean removeEldestEntry(Map.Entry<String, TimedText> eldest) {
                    return size() > 12;
                }
            };

    private static final Map<String, TimedRestrictions> RESTRICTION_CACHE =
            new LinkedHashMap<String, TimedRestrictions>(20, 0.75f, true) {
                @Override protected boolean removeEldestEntry(
                        Map.Entry<String, TimedRestrictions> eldest) {
                    return size() > 20;
                }
            };

    private static final class TimedText {
        final long at; final String body;
        TimedText(long at,String body){this.at=at;this.body=body;}
    }

    private static final class TimedRestrictions {
        final long at; final List<Restriction> items;
        TimedRestrictions(long at,List<Restriction> items){
            this.at=at;this.items=new ArrayList<>(items);
        }
    }

    private static long lastNominatimRequestMs = 0L;

    private OnlineServices() {}

    public static class SearchResult {
        public final double lat;
        public final double lon;
        public final String label;

        public SearchResult(double lat, double lon, String label) {
            this.lat = lat;
            this.lon = lon;
            this.label = label;
        }

        public DestinationStore.Item toStoredItem() {
            return new DestinationStore.Item(label, lat, lon);
        }
    }

    public static class NavStep {
        public final double lat;
        public final double lon;
        public final double distanceMeters;
        public final String instruction;
        public final String roadName;
        public final String maneuverType;
        public final String modifier;

        public NavStep(double lat, double lon, double distanceMeters,
                       String instruction, String roadName,
                       String maneuverType, String modifier) {
            this.lat = lat;
            this.lon = lon;
            this.distanceMeters = distanceMeters;
            this.instruction = instruction;
            this.roadName = roadName;
            this.maneuverType = maneuverType;
            this.modifier = modifier;
        }
    }

    public static class Restriction {
        public final double lat;
        public final double lon;
        public final String type;
        public final String value;
        public final String description;
        public final boolean critical;
        public final boolean informational;

        public Restriction(double lat, double lon, String type, String value,
                           String description, boolean critical, boolean informational) {
            this.lat = lat;
            this.lon = lon;
            this.type = type;
            this.value = value;
            this.description = description;
            this.critical = critical;
            this.informational = informational;
        }
    }

    public static class RouteResult {
        public final List<GeoPoint> points;
        public final double distanceMeters;
        public final double durationSeconds;
        public final List<NavStep> steps;
        public List<Restriction> restrictions = new ArrayList<>();
        public List<LiveTrafficService.TrafficEvent> trafficEvents = new ArrayList<>();
        public List<RoadDataService.Sign> roadSigns = new ArrayList<>();
        public List<OfficialSpeedService.SpeedPoint> officialSpeeds = new ArrayList<>();
        public List<BridgeOpeningService.Event> bridgeEvents = new ArrayList<>();
        public List<TemporarySpeedService.Limit> temporarySpeeds = new ArrayList<>();
        public String providerName = "OSRM";
        public boolean providerVehicleAware = false;
        public int providerCriticalNotices = 0;
        public final List<String> providerNoticeTexts = new ArrayList<>();
        public boolean ndwAccessibilityChecked = false;
        public int ndwAccessibilityHardHits = 0;
        public final List<String> ndwAccessibilityReasons = new ArrayList<>();
        public String selectionNote = "";

        public RouteResult(List<GeoPoint> points, double distanceMeters,
                           double durationSeconds, List<NavStep> steps) {
            this.points = points;
            this.distanceMeters = distanceMeters;
            this.durationSeconds = durationSeconds;
            this.steps = steps;
        }

        public int criticalCount() {
            int n = 0;
            for (Restriction r : restrictions) if (r.critical) n++;
            return n;
        }

        public int cautionCount() {
            int n = 0;
            for (Restriction r : restrictions) {
                if (!r.critical && !r.informational) n++;
            }
            return n;
        }

        public int liveClosureCount() {
            int n = 0;
            for (LiveTrafficService.TrafficEvent e : trafficEvents) {
                if (e.closure) n++;
            }
            return n;
        }

        public int bridgeConflictCount() {
            return bridgeEvents == null ? 0 : bridgeEvents.size();
        }
    }

    public static SearchResult searchPlace(String rawQuery) throws Exception {
        String query = rawQuery == null ? "" : rawQuery.trim();
        if (query.length() < 3) throw new IllegalArgumentException("Voer minimaal 3 tekens in.");

        synchronized (SEARCH_CACHE) {
            SearchResult cached = SEARCH_CACHE.get(query.toLowerCase(Locale.ROOT));
            if (cached != null) return cached;
        }

        // Nederlandse adressen eerst via de officiële PDOK Location API.
        try {
            SearchResult pdok = PdokLocationService.searchBest(query);
            synchronized (SEARCH_CACHE) {
                SEARCH_CACHE.put(query.toLowerCase(Locale.ROOT), pdok);
            }
            return pdok;
        } catch (Exception ignored) {
            // Voor buitenlandse / niet-adres zoekopdrachten blijft Nominatim fallback.
        }

        synchronized (OnlineServices.class) {
            long wait = 1000L - (System.currentTimeMillis() - lastNominatimRequestMs);
            if (wait > 0) Thread.sleep(wait);
            lastNominatimRequestMs = System.currentTimeMillis();
        }

        String url = "https://nominatim.openstreetmap.org/search"
                + "?format=jsonv2&limit=1&addressdetails=1&accept-language=nl"
                + "&countrycodes=nl,be,de"
                + "&q=" + URLEncoder.encode(query, "UTF-8");

        JSONArray arr = new JSONArray(get(url, 12000));
        if (arr.length() == 0) throw new IllegalArgumentException("Bestemming niet gevonden.");

        JSONObject item = arr.getJSONObject(0);
        SearchResult result = new SearchResult(
                Double.parseDouble(item.getString("lat")),
                Double.parseDouble(item.getString("lon")),
                item.optString("display_name", query)
        );

        synchronized (SEARCH_CACHE) {
            SEARCH_CACHE.put(query.toLowerCase(Locale.ROOT), result);
        }
        return result;
    }

    public static List<RouteResult> routeCandidates(android.content.Context context,
                                                    double fromLat,double fromLon,
                                                    SearchResult destination,
                                                    VehicleProfile vehicle) throws Exception {
        RoutingProviderSettings settings=RoutingProviderSettings.load(context);
        if(settings.useHere()){
            try{
                return HereRoutingService.routeCandidates(
                        context,fromLat,fromLon,destination,vehicle,settings);
            }catch(Exception e){
                List<RouteResult> fallback=routeCandidates(
                        fromLat,fromLon,destination.lat,destination.lon);
                for(RouteResult r:fallback){
                    r.providerName="OSRM fallback";
                    r.selectionNote="HERE niet beschikbaar; veilige OSRM-fallback gebruikt.";
                }
                return fallback;
            }
        }
        return routeCandidates(fromLat,fromLon,destination.lat,destination.lon);
    }

    public static List<RouteResult> routeCandidates(double fromLat, double fromLon,
                                                    double toLat, double toLon) throws Exception {
        String url = String.format(Locale.US,
                "https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f"
                        + "?overview=full&geometries=geojson&steps=true&alternatives=true",
                fromLon, fromLat, toLon, toLat);

        String routeKey = String.format(Locale.US, "%.4f,%.4f>%.5f,%.5f",
                fromLat, fromLon, toLat, toLon);
        String body = null;
        synchronized (ROUTE_RESPONSE_CACHE) {
            TimedText hit = ROUTE_RESPONSE_CACHE.get(routeKey);
            if (hit != null && System.currentTimeMillis() - hit.at < ROUTE_CACHE_MS)
                body = hit.body;
        }
        if (body == null) {
            body = get(url, 22000);
            synchronized (ROUTE_RESPONSE_CACHE) {
                ROUTE_RESPONSE_CACHE.put(routeKey,
                        new TimedText(System.currentTimeMillis(), body));
            }
        }

        JSONObject root = new JSONObject(body);
        if (!"Ok".equalsIgnoreCase(root.optString("code"))) {
            throw new IllegalArgumentException("Geen autoroute gevonden.");
        }

        JSONArray routesJson = root.optJSONArray("routes");
        if (routesJson == null || routesJson.length() == 0) {
            throw new IllegalArgumentException("Geen route beschikbaar.");
        }

        List<RouteResult> result = new ArrayList<>();
        for (int i = 0; i < routesJson.length() && i < 3; i++) {
            JSONObject route = routesJson.getJSONObject(i);
            List<GeoPoint> points = parseGeometry(route);
            List<NavStep> steps = parseSteps(route);
            RouteResult parsed=new RouteResult(
                    points,
                    route.optDouble("distance", 0),
                    route.optDouble("duration", 0),
                    steps
            );
            parsed.providerName="OSRM";
            parsed.providerVehicleAware=false;
            result.add(parsed);
        }
        return result;
    }

    private static List<GeoPoint> parseGeometry(JSONObject route) throws Exception {
        JSONArray coordinates = route.getJSONObject("geometry").getJSONArray("coordinates");
        List<GeoPoint> points = new ArrayList<>(coordinates.length());
        for (int i = 0; i < coordinates.length(); i++) {
            JSONArray c = coordinates.getJSONArray(i);
            points.add(new GeoPoint(c.getDouble(1), c.getDouble(0)));
        }
        return points;
    }

    private static List<NavStep> parseSteps(JSONObject route) throws Exception {
        List<NavStep> out = new ArrayList<>();
        JSONArray legs = route.optJSONArray("legs");
        if (legs == null) return out;

        for (int l = 0; l < legs.length(); l++) {
            JSONArray steps = legs.getJSONObject(l).optJSONArray("steps");
            if (steps == null) continue;

            for (int i = 0; i < steps.length(); i++) {
                JSONObject step = steps.getJSONObject(i);
                JSONObject maneuver = step.optJSONObject("maneuver");
                if (maneuver == null) continue;

                JSONArray location = maneuver.optJSONArray("location");
                if (location == null || location.length() < 2) continue;

                String type = maneuver.optString("type", "");
                String modifier = maneuver.optString("modifier", "");
                String road = step.optString("name", "");
                String instruction = buildInstruction(type, modifier, road);

                out.add(new NavStep(
                        location.getDouble(1),
                        location.getDouble(0),
                        step.optDouble("distance", 0),
                        instruction,
                        road,
                        type,
                        modifier
                ));
            }
        }
        return out;
    }

    private static String buildInstruction(String type, String modifier, String road) {
        String target = road == null || road.trim().isEmpty() ? "" : " " + road.trim();

        if ("depart".equals(type)) return "Vertrek" + (target.isEmpty() ? "" : " via" + target);
        if ("arrive".equals(type)) return "Je bestemming is bereikt";
        if ("roundabout".equals(type) || "rotary".equals(type)) {
            return "Ga de rotonde op" + (target.isEmpty() ? "" : " richting" + target);
        }
        if ("merge".equals(type)) return "Voeg in" + (target.isEmpty() ? "" : " op" + target);
        if ("on ramp".equals(type)) return "Neem de oprit" + (target.isEmpty() ? "" : " naar" + target);
        if ("off ramp".equals(type)) return "Neem de afrit" + (target.isEmpty() ? "" : " naar" + target);
        if ("fork".equals(type)) {
            return "Houd " + directionWord(modifier) + (target.isEmpty() ? "" : " richting" + target);
        }
        if ("continue".equals(type) || "new name".equals(type)) {
            return "Ga rechtdoor" + (target.isEmpty() ? "" : " op" + target);
        }
        if ("end of road".equals(type)) {
            return "Aan het einde " + turnWord(modifier) + (target.isEmpty() ? "" : " naar" + target);
        }
        if ("turn".equals(type)) {
            return turnWord(modifier) + (target.isEmpty() ? "" : " naar" + target);
        }

        return target.isEmpty() ? "Volg de route" : "Vervolg via" + target;
    }

    private static String turnWord(String modifier) {
        if (modifier == null) modifier = "";
        if (modifier.contains("left")) return "Sla linksaf";
        if (modifier.contains("right")) return "Sla rechtsaf";
        if ("uturn".equals(modifier)) return "Keer om";
        if ("straight".equals(modifier)) return "Ga rechtdoor";
        return "Ga verder";
    }

    private static String directionWord(String modifier) {
        if (modifier == null) return "de juiste richting aan";
        if (modifier.contains("left")) return "links aan";
        if (modifier.contains("right")) return "rechts aan";
        return "de juiste richting aan";
    }

    public static List<Restriction> scanRestrictions(android.content.Context context,
                                                     RouteResult route,
                                                     VehicleProfile vehicle) throws Exception {
        List<Restriction> out = new ArrayList<>();
        if (route == null || route.points.size() < 2) return out;

        String restrictionKey = restrictionCacheKey(route, vehicle);
        synchronized (RESTRICTION_CACHE) {
            TimedRestrictions hit = RESTRICTION_CACHE.get(restrictionKey);
            if (hit != null && System.currentTimeMillis() - hit.at < RESTRICTION_CACHE_MS)
                return new ArrayList<>(hit.items);
        }

        String line = buildOverpassLine(route.points);
        if (line.isEmpty()) return out;

        String physicalQueries = route.providerVehicleAware ? "" :
                "nwr[\"maxheight\"](around:70," + line + ");"
                + "nwr[\"maxwidth\"](around:70," + line + ");"
                + "nwr[\"maxweight\"](around:70," + line + ");";

        String q = "[out:json][timeout:14];("
                + physicalQueries
                + "nwr[\"barrier\"=\"bus_trap\"](around:75," + line + ");"
                + "way[\"highway\"=\"busway\"](around:70," + line + ");"
                + "way[\"highway\"=\"bus_guideway\"](around:70," + line + ");"
                + "way[\"busway\"](around:70," + line + ");"
                + "way[\"busway:left\"](around:70," + line + ");"
                + "way[\"busway:right\"](around:70," + line + ");"
                + "way[\"lanes:bus\"](around:70," + line + ");"
                + "way[\"lanes:bus:forward\"](around:70," + line + ");"
                + "way[\"lanes:bus:backward\"](around:70," + line + ");"
                + "way[\"lanes:psv\"](around:70," + line + ");"
                + "way[\"lanes:psv:forward\"](around:70," + line + ");"
                + "way[\"lanes:psv:backward\"](around:70," + line + ");"
                + "way[\"bus:lanes\"](around:70," + line + ");"
                + "way[\"bus:lanes:forward\"](around:70," + line + ");"
                + "way[\"bus:lanes:backward\"](around:70," + line + ");"
                + "way[\"psv:lanes\"](around:70," + line + ");"
                + "way[\"psv:lanes:forward\"](around:70," + line + ");"
                + "way[\"psv:lanes:backward\"](around:70," + line + ");"
                + "way[\"access\"=\"no\"][\"bus\"~\"yes|designated|permissive\"](around:70," + line + ");"
                + "way[\"access\"=\"no\"][\"psv\"~\"yes|designated|permissive\"](around:70," + line + ");"
                + "way[\"motor_vehicle\"=\"no\"][\"bus\"~\"yes|designated|permissive\"](around:70," + line + ");"
                + "way[\"motor_vehicle\"=\"no\"][\"psv\"~\"yes|designated|permissive\"](around:70," + line + ");"
                + "way[\"vehicle\"=\"no\"][\"bus\"~\"yes|designated|permissive\"](around:70," + line + ");"
                + "way[\"vehicle\"=\"no\"][\"psv\"~\"yes|designated|permissive\"](around:70," + line + ");"
                + "way[\"width\"](around:35," + line + ");"
                + "way[\"highway\"~\"living_street|service|track\"](around:24," + line + ");"
                + ");out center tags;";

        JSONObject root = new JSONObject(postForm(
                "https://overpass-api.de/api/interpreter",
                "data=" + URLEncoder.encode(q, "UTF-8"),
                26000
        ));

        JSONArray elements = root.optJSONArray("elements");
        if (elements == null) return out;

        Set<String> seen = new HashSet<>();

        for (int i = 0; i < elements.length() && out.size() < 24; i++) {
            JSONObject el = elements.getJSONObject(i);
            JSONObject tags = el.optJSONObject("tags");
            if (tags == null) continue;

            double[] center = getCenter(el);
            if (center == null) continue;

            double lat = center[0];
            double lon = center[1];
            if (distanceToRouteMeters(lat, lon, route.points) > 85.0) continue;

            String barrier = tags.optString("barrier", "");

            if ("bus_trap".equals(barrier)) {
                addUnique(out, seen, new Restriction(
                        lat, lon, "BUSSLUIS", "bus_trap",
                        "Bussluis vlak langs de route. Deze blijft verboden in het voertuigprofiel.",
                        true, false
                ));
            }

            if (hasBusLaneSignal(tags)) {
                String busLaneDetail = busLaneDetail(tags);
                boolean exemptionHere = WmoAccessPolicy.busLaneAllowed(
                        context, vehicle, lat, lon);
                if (exemptionHere) {
                    addUnique(out, seen, new Restriction(
                            lat, lon, "BUSBAAN", busLaneDetail,
                            "Busbaan/bus-/PSV-rijstrook in gemeente Tilburg gevonden (" + busLaneDetail + "). "
                                    + "Tilburgse busbaanontheffing is hier actief.",
                            false, true
                    ));
                } else {
                    String why = WmoAccessPolicy.busLaneReason(
                            context, vehicle, lat, lon);
                    addUnique(out, seen, new Restriction(
                            lat, lon, "BUSBAAN", busLaneDetail,
                            "Busbaan/bus-/PSV-rijstrook gevonden (" + busLaneDetail + "). " + why,
                            true, false
                    ));
                }
            }

            addNumericRestriction(out, seen, lat, lon, "HOOGTE",
                    tags.optString("maxheight", ""), vehicle.heightM, "m", 0.18);
            addNumericRestriction(out, seen, lat, lon, "BREEDTE",
                    tags.optString("maxwidth", ""), vehicle.widthM, "m", 0.16);
            addNumericRestriction(out, seen, lat, lon, "GEWICHT",
                    tags.optString("maxweight", ""), vehicle.maxWeightT, "t", 0.35);

            addNarrowRoadSignal(out, seen, lat, lon, tags, route.points);
        }

        synchronized (RESTRICTION_CACHE) {
            RESTRICTION_CACHE.put(restrictionKey,
                    new TimedRestrictions(System.currentTimeMillis(), out));
        }
        return new ArrayList<>(out);
    }

    public static List<Restriction> scanRestrictions(RouteResult route,
                                                     VehicleProfile vehicle) throws Exception {
        return scanRestrictions(null, route, vehicle);
    }

    private static String restrictionCacheKey(RouteResult route, VehicleProfile vehicle) {
        int n = route.points.size();
        GeoPoint a = route.points.get(0);
        GeoPoint m = route.points.get(n / 2);
        GeoPoint z = route.points.get(n - 1);
        return String.format(Locale.US,
                "%.4f,%.4f|%.4f,%.4f|%.4f,%.4f|%.0f|%.2f,%.2f,%.2f,%.2f,%b",
                a.getLatitude(), a.getLongitude(),
                m.getLatitude(), m.getLongitude(),
                z.getLatitude(), z.getLongitude(),
                route.distanceMeters / 100.0,
                vehicle.lengthM, vehicle.widthM, vehicle.heightM, vehicle.maxWeightT,
                vehicle.busLaneExemption);
    }

    private static void addNarrowRoadSignal(List<Restriction> out, Set<String> seen,
                                            double lat, double lon, JSONObject tags,
                                            List<GeoPoint> routePoints) {
        if (distanceToRouteMeters(lat, lon, routePoints) > 24.0) return;
        String highway = tags.optString("highway", "");
        Double width = parseFirstNumber(tags.optString("width", ""));
        String service = tags.optString("service", "");
        boolean narrow = width != null && width <= 3.25;
        boolean crampedType = "living_street".equals(highway)
                || "track".equals(highway)
                || ("service".equals(highway) && !"parking_aisle".equals(service));
        if (!narrow && !crampedType) return;

        String value = width == null ? highway : String.format(NL, "%.2f m", width);
        String desc = narrow
                ? "Wegbreedte rond " + value + "; krap voor rolstoelbus."
                : "Krap wegtype " + highway + "; extra manoeuvreerruimte controleren.";
        addUnique(out, seen, new Restriction(
                lat, lon, "SMALLE WEG", value, desc, false, false
        ));
    }

    private static boolean hasBusLaneSignal(JSONObject tags) {
        String highway = tags.optString("highway", "");
        if ("busway".equals(highway) || "bus_guideway".equals(highway)) return true;

        String[] directKeys = {
                "lanes:bus", "lanes:bus:forward", "lanes:bus:backward",
                "lanes:psv", "lanes:psv:forward", "lanes:psv:backward"
        };
        for (String key : directKeys) {
            String value = tags.optString(key, "").trim();
            if (!value.isEmpty() && !"0".equals(value) && !"no".equalsIgnoreCase(value)) {
                return true;
            }
        }

        String[] laneAccessKeys = {
                "bus:lanes", "bus:lanes:forward", "bus:lanes:backward",
                "psv:lanes", "psv:lanes:forward", "psv:lanes:backward"
        };
        for (String key : laneAccessKeys) {
            String value = tags.optString(key, "").toLowerCase(Locale.ROOT);
            if (value.contains("designated")) return true;
        }

        String[] legacyKeys = {"busway", "busway:left", "busway:right"};
        for (String key : legacyKeys) {
            String value = tags.optString(key, "").toLowerCase(Locale.ROOT);
            if (value.contains("lane") || value.contains("opposite") || "yes".equals(value)) {
                return true;
            }
        }

        String access = tags.optString("access", "");
        String motorVehicle = tags.optString("motor_vehicle", "");
        String vehicle = tags.optString("vehicle", "");
        boolean generalRestricted = "no".equals(access)
                || "no".equals(motorVehicle)
                || "no".equals(vehicle);

        String bus = tags.optString("bus", "").toLowerCase(Locale.ROOT);
        String psv = tags.optString("psv", "").toLowerCase(Locale.ROOT);
        boolean busAllowed = bus.matches("yes|designated|permissive")
                || psv.matches("yes|designated|permissive");

        return generalRestricted && busAllowed;
    }

    private static String busLaneDetail(JSONObject tags) {
        String[] keys = {
                "highway", "lanes:bus", "lanes:bus:forward", "lanes:bus:backward",
                "lanes:psv", "lanes:psv:forward", "lanes:psv:backward",
                "bus:lanes", "bus:lanes:forward", "bus:lanes:backward",
                "psv:lanes", "psv:lanes:forward", "psv:lanes:backward",
                "busway", "busway:left", "busway:right",
                "access", "motor_vehicle", "vehicle", "bus", "psv"
        };
        StringBuilder sb = new StringBuilder();
        for (String key : keys) {
            String value = tags.optString(key, "").trim();
            if (value.isEmpty()) continue;
            if (sb.length() > 0) sb.append(", ");
            sb.append(key).append('=').append(value);
            if (sb.length() > 120) break;
        }
        return sb.length() == 0 ? "bus/PSV" : sb.toString();
    }

    private static void addNumericRestriction(List<Restriction> out, Set<String> seen,
                                              double lat, double lon,
                                              String type, String raw,
                                              double vehicleValue, String unit,
                                              double cautionMargin) {
        if (raw == null || raw.trim().isEmpty()) return;
        Double limit = parseFirstNumber(raw);
        if (limit == null) return;

        boolean critical = limit + 0.001 < vehicleValue;
        boolean close = limit <= vehicleValue + cautionMargin;
        if (!critical && !close) return;

        String description;
        if (critical) {
            description = String.format(NL,
                    "%s %s %s is lager dan jouw voertuigwaarde %.2f %s.",
                    type.toLowerCase(Locale.ROOT), raw, unit, vehicleValue, unit);
        } else {
            description = String.format(NL,
                    "%s %s %s ligt dicht bij jouw voertuigwaarde %.2f %s.",
                    type.toLowerCase(Locale.ROOT), raw, unit, vehicleValue, unit);
        }

        addUnique(out, seen, new Restriction(
                lat, lon, type, raw, description, critical, false
        ));
    }

    private static void addUnique(List<Restriction> out, Set<String> seen, Restriction r) {
        String key = r.type + ":" + Math.round(r.lat * 100000)
                + ":" + Math.round(r.lon * 100000);
        if (seen.add(key)) out.add(r);
    }

    private static double[] getCenter(JSONObject el) {
        try {
            if (el.has("lat") && el.has("lon")) {
                return new double[]{el.getDouble("lat"), el.getDouble("lon")};
            }
            JSONObject center = el.optJSONObject("center");
            if (center != null) {
                return new double[]{center.getDouble("lat"), center.getDouble("lon")};
            }
        } catch (Exception ignored) {}
        return null;
    }

    private static String buildOverpassLine(List<GeoPoint> points) {
        if (points == null || points.size() < 2) return "";
        int maxPairs = 48;
        int stride = Math.max(1, (int) Math.ceil(points.size() / (double) maxPairs));
        StringBuilder sb = new StringBuilder();

        for (int i = 0; i < points.size(); i += stride) {
            GeoPoint p = points.get(i);
            if (sb.length() > 0) sb.append(',');
            sb.append(String.format(Locale.US, "%.6f,%.6f",
                    p.getLatitude(), p.getLongitude()));
        }

        GeoPoint last = points.get(points.size() - 1);
        String lastText = String.format(Locale.US, "%.6f,%.6f",
                last.getLatitude(), last.getLongitude());
        if (!sb.toString().endsWith(lastText)) {
            sb.append(',').append(lastText);
        }
        return sb.toString();
    }

    public static RouteResult routeViaWaypoint(double fromLat, double fromLon,
                                               double viaLat, double viaLon,
                                               double toLat, double toLon) throws Exception {
        String url = String.format(Locale.US,
                "https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f;%.6f,%.6f"
                        + "?overview=full&geometries=geojson&steps=true&alternatives=false&continue_straight=true",
                fromLon, fromLat, viaLon, viaLat, toLon, toLat);
        JSONObject root = new JSONObject(get(url, 22000));
        JSONArray routes = root.optJSONArray("routes");
        if (routes == null || routes.length() == 0)
            throw new IllegalArgumentException("Geen route via omleiding beschikbaar.");
        JSONObject route = routes.getJSONObject(0);
        return new RouteResult(
                parseGeometry(route),
                route.optDouble("distance", 0),
                route.optDouble("duration", 0),
                parseSteps(route)
        );
    }

    public static RouteResult reverseApproachCandidate(double fromLat, double fromLon,
                                                       double toLat, double toLon,
                                                       RouteResult reference) throws Exception {
        double bearing = reference == null ? 0.0 : RouteAnalysis.approachBearing(reference.points);
        double[] beyond = project(toLat, toLon, bearing, 130.0);
        String url = String.format(Locale.US,
                "https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f;%.6f,%.6f"
                        + "?overview=full&geometries=geojson&steps=true&alternatives=false&continue_straight=true",
                fromLon, fromLat, beyond[1], beyond[0], toLon, toLat);
        JSONObject root = new JSONObject(get(url, 22000));
        JSONArray routes = root.optJSONArray("routes");
        if (routes == null || routes.length() == 0) {
            throw new IllegalArgumentException("Geen omgekeerde aanrijroute beschikbaar.");
        }
        JSONObject route = routes.getJSONObject(0);
        return new RouteResult(
                parseGeometry(route),
                route.optDouble("distance", 0),
                route.optDouble("duration", 0),
                parseSteps(route)
        );
    }

    private static double[] project(double lat, double lon, double bearingDeg, double meters) {
        double r = 6371000.0;
        double br = Math.toRadians(bearingDeg);
        double lat1 = Math.toRadians(lat);
        double lon1 = Math.toRadians(lon);
        double dr = meters / r;
        double lat2 = Math.asin(Math.sin(lat1) * Math.cos(dr)
                + Math.cos(lat1) * Math.sin(dr) * Math.cos(br));
        double lon2 = lon1 + Math.atan2(
                Math.sin(br) * Math.sin(dr) * Math.cos(lat1),
                Math.cos(dr) - Math.sin(lat1) * Math.sin(lat2));
        return new double[]{Math.toDegrees(lat2), Math.toDegrees(lon2)};
    }

    public static RouteResult chooseSafer(RouteResult current, RouteResult challenger) {
        if (current == null) return challenger;
        if (challenger == null) return current;

        int aClosures = current.liveClosureCount();
        int bClosures = challenger.liveClosureCount();
        if (aClosures != bClosures) return bClosures < aClosures ? challenger : current;

        int aCritical = current.criticalCount();
        int bCritical = challenger.criticalCount();
        if (aCritical != bCritical) return bCritical < aCritical ? challenger : current;

        int aCaution = current.cautionCount();
        int bCaution = challenger.cautionCount();
        if (aCaution != bCaution) return bCaution < aCaution ? challenger : current;

        if (challenger.durationSeconds < current.durationSeconds * 1.15) {
            return challenger.durationSeconds < current.durationSeconds ? challenger : current;
        }
        return current;
    }

    public static int closestRoutePointIndex(double lat, double lon, List<GeoPoint> points) {
        if (points == null || points.isEmpty()) return -1;
        int best = 0;
        double min = Double.MAX_VALUE;
        int stride = Math.max(1, points.size() / 1200);

        for (int i = 0; i < points.size(); i += stride) {
            GeoPoint p = points.get(i);
            double d = haversine(lat, lon, p.getLatitude(), p.getLongitude());
            if (d < min) {
                min = d;
                best = i;
            }
        }

        int from = Math.max(0, best - stride);
        int to = Math.min(points.size() - 1, best + stride);
        for (int i = from; i <= to; i++) {
            GeoPoint p = points.get(i);
            double d = haversine(lat, lon, p.getLatitude(), p.getLongitude());
            if (d < min) {
                min = d;
                best = i;
            }
        }
        return best;
    }

    public static double distanceFromRouteMeters(double lat, double lon, List<GeoPoint> points) {
        int idx = closestRoutePointIndex(lat, lon, points);
        if (idx < 0) return Double.MAX_VALUE;
        GeoPoint p = points.get(idx);
        return haversine(lat, lon, p.getLatitude(), p.getLongitude());
    }

    public static double remainingRouteDistanceMeters(int fromIndex, List<GeoPoint> points) {
        if (points == null || points.size() < 2) return 0;
        int start = Math.max(0, Math.min(fromIndex, points.size() - 1));
        double total = 0;
        for (int i = start + 1; i < points.size(); i++) {
            GeoPoint a = points.get(i - 1);
            GeoPoint b = points.get(i);
            total += haversine(a.getLatitude(), a.getLongitude(),
                    b.getLatitude(), b.getLongitude());
        }
        return total;
    }

    public static double distanceMeters(double lat1, double lon1, double lat2, double lon2) {
        return haversine(lat1, lon1, lat2, lon2);
    }

    private static double distanceToRouteMeters(double lat, double lon, List<GeoPoint> points) {
        return distanceFromRouteMeters(lat, lon, points);
    }

    private static double haversine(double lat1, double lon1, double lat2, double lon2) {
        double r = 6371000.0;
        double p1 = Math.toRadians(lat1);
        double p2 = Math.toRadians(lat2);
        double dp = Math.toRadians(lat2 - lat1);
        double dl = Math.toRadians(lon2 - lon1);
        double a = Math.sin(dp / 2) * Math.sin(dp / 2)
                + Math.cos(p1) * Math.cos(p2)
                * Math.sin(dl / 2) * Math.sin(dl / 2);
        return 2 * r * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    private static Double parseFirstNumber(String raw) {
        String cleaned = raw.toLowerCase(Locale.ROOT)
                .replace(',', '.')
                .replace("meters", "")
                .replace("meter", "")
                .replace("metre", "")
                .replace("tonnes", "")
                .replace("tonne", "")
                .replace("tons", "")
                .replace("ton", "")
                .replace("t", "")
                .trim();

        StringBuilder n = new StringBuilder();
        boolean started = false;
        for (int i = 0; i < cleaned.length(); i++) {
            char c = cleaned.charAt(i);
            if ((c >= '0' && c <= '9') || c == '.') {
                n.append(c);
                started = true;
            } else if (started) {
                break;
            }
        }

        try {
            return n.length() == 0 ? null : Double.parseDouble(n.toString());
        } catch (Exception e) {
            return null;
        }
    }

    private static String get(String rawUrl, int timeoutMs) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(rawUrl).openConnection();
        con.setRequestMethod("GET");
        con.setConnectTimeout(timeoutMs);
        con.setReadTimeout(timeoutMs);
        con.setRequestProperty("User-Agent", USER_AGENT);
        con.setRequestProperty("Accept", "application/json");
        return readResponse(con);
    }

    private static String postForm(String rawUrl, String payload, int timeoutMs) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(rawUrl).openConnection();
        con.setRequestMethod("POST");
        con.setConnectTimeout(timeoutMs);
        con.setReadTimeout(timeoutMs);
        con.setDoOutput(true);
        con.setRequestProperty("User-Agent", USER_AGENT);
        con.setRequestProperty("Accept", "application/json");
        con.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        byte[] data = payload.getBytes(StandardCharsets.UTF_8);
        con.setFixedLengthStreamingMode(data.length);

        try (OutputStream out = con.getOutputStream()) {
            out.write(data);
        }

        return readResponse(con);
    }

    private static String readResponse(HttpURLConnection con) throws Exception {
        int code = con.getResponseCode();
        InputStream stream = code >= 200 && code < 300
                ? con.getInputStream() : con.getErrorStream();

        StringBuilder sb = new StringBuilder();
        if (stream != null) {
            try (BufferedReader br = new BufferedReader(
                    new InputStreamReader(stream, StandardCharsets.UTF_8))) {
                String line;
                while ((line = br.readLine()) != null) sb.append(line);
            }
        }
        con.disconnect();

        if (code < 200 || code >= 300) {
            throw new IllegalStateException("Online dienst gaf HTTP " + code);
        }
        return sb.toString();
    }
}
