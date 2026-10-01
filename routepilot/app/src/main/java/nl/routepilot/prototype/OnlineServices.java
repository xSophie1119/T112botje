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
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

public final class OnlineServices {

    public static final String USER_AGENT =
            "RoutePilot/0.2 (personal Android prototype; https://github.com/xSophie1119)";

    private static final Map<String, SearchResult> SEARCH_CACHE =
            new LinkedHashMap<String, SearchResult>(40, 0.75f, true) {
                @Override
                protected boolean removeEldestEntry(Map.Entry<String, SearchResult> eldest) {
                    return size() > 40;
                }
            };

    private static long lastNominatimRequestMs = 0L;

    private OnlineServices() {}

    public static class SearchResult {
        public final double lat;
        public final double lon;
        public final String label;

        SearchResult(double lat, double lon, String label) {
            this.lat = lat;
            this.lon = lon;
            this.label = label;
        }
    }

    public static class RouteResult {
        public final List<GeoPoint> points;
        public final double distanceMeters;
        public final double durationSeconds;
        public final String firstInstruction;

        RouteResult(List<GeoPoint> points, double distanceMeters,
                    double durationSeconds, String firstInstruction) {
            this.points = points;
            this.distanceMeters = distanceMeters;
            this.durationSeconds = durationSeconds;
            this.firstInstruction = firstInstruction;
        }
    }

    public static class Restriction {
        public final double lat;
        public final double lon;
        public final String type;
        public final String value;
        public final String description;
        public final boolean critical;

        Restriction(double lat, double lon, String type, String value,
                    String description, boolean critical) {
            this.lat = lat;
            this.lon = lon;
            this.type = type;
            this.value = value;
            this.description = description;
            this.critical = critical;
        }
    }

    public static SearchResult searchPlace(String rawQuery) throws Exception {
        String query = rawQuery == null ? "" : rawQuery.trim();
        if (query.length() < 3) throw new IllegalArgumentException("Voer minimaal 3 tekens in.");

        synchronized (SEARCH_CACHE) {
            SearchResult cached = SEARCH_CACHE.get(query.toLowerCase(Locale.ROOT));
            if (cached != null) return cached;
        }

        synchronized (OnlineServices.class) {
            long wait = 1000L - (System.currentTimeMillis() - lastNominatimRequestMs);
            if (wait > 0) Thread.sleep(wait);
            lastNominatimRequestMs = System.currentTimeMillis();
        }

        String url = "https://nominatim.openstreetmap.org/search"
                + "?format=jsonv2&limit=1&addressdetails=1&accept-language=nl"
                + "&q=" + URLEncoder.encode(query, "UTF-8");

        String body = get(url, 12000);
        JSONArray arr = new JSONArray(body);
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

    public static RouteResult route(double fromLat, double fromLon,
                                    double toLat, double toLon) throws Exception {
        String url = String.format(Locale.US,
                "https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f"
                        + "?overview=full&geometries=geojson&steps=true&alternatives=false",
                fromLon, fromLat, toLon, toLat);

        JSONObject root = new JSONObject(get(url, 20000));
        if (!"Ok".equalsIgnoreCase(root.optString("code"))) {
            throw new IllegalArgumentException("Geen autoroute gevonden.");
        }

        JSONArray routes = root.getJSONArray("routes");
        if (routes.length() == 0) throw new IllegalArgumentException("Geen route beschikbaar.");
        JSONObject r = routes.getJSONObject(0);

        JSONArray coordinates = r.getJSONObject("geometry").getJSONArray("coordinates");
        List<GeoPoint> points = new ArrayList<>(coordinates.length());
        for (int i = 0; i < coordinates.length(); i++) {
            JSONArray c = coordinates.getJSONArray(i);
            points.add(new GeoPoint(c.getDouble(1), c.getDouble(0)));
        }

        String firstInstruction = "Volg de route.";
        JSONArray legs = r.optJSONArray("legs");
        if (legs != null && legs.length() > 0) {
            JSONArray steps = legs.getJSONObject(0).optJSONArray("steps");
            if (steps != null && steps.length() > 1) {
                JSONObject step = steps.getJSONObject(1);
                String name = step.optString("name", "");
                String type = step.optJSONObject("maneuver") != null
                        ? step.getJSONObject("maneuver").optString("type", "")
                        : "";
                if (!name.isEmpty()) {
                    firstInstruction = humanManeuver(type) + " " + name;
                }
            }
        }

        return new RouteResult(
                points,
                r.optDouble("distance", 0),
                r.optDouble("duration", 0),
                firstInstruction.trim()
        );
    }

    private static String humanManeuver(String type) {
        if ("turn".equals(type)) return "Ga richting";
        if ("depart".equals(type)) return "Vertrek via";
        if ("merge".equals(type)) return "Voeg in op";
        if ("on ramp".equals(type)) return "Neem de oprit naar";
        if ("off ramp".equals(type)) return "Neem de afrit naar";
        if ("roundabout".equals(type)) return "Neem de rotonde naar";
        return "Vervolg via";
    }

    public static List<Restriction> scanRestrictions(List<GeoPoint> routePoints) throws Exception {
        List<Restriction> out = new ArrayList<>();
        if (routePoints == null || routePoints.size() < 2) return out;

        double minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
        for (GeoPoint p : routePoints) {
            minLat = Math.min(minLat, p.getLatitude());
            maxLat = Math.max(maxLat, p.getLatitude());
            minLon = Math.min(minLon, p.getLongitude());
            maxLon = Math.max(maxLon, p.getLongitude());
        }

        double latSpan = maxLat - minLat;
        double lonSpan = maxLon - minLon;
        if (latSpan > 0.28 || lonSpan > 0.38) {
            return out;
        }

        double pad = 0.0012;
        minLat -= pad; minLon -= pad; maxLat += pad; maxLon += pad;

        String bbox = String.format(Locale.US, "%.6f,%.6f,%.6f,%.6f",
                minLat, minLon, maxLat, maxLon);
        String q = "[out:json][timeout:18];("
                + "nwr[\"maxheight\"](" + bbox + ");"
                + "nwr[\"maxwidth\"](" + bbox + ");"
                + "nwr[\"maxweight\"](" + bbox + ");"
                + "nwr[\"barrier\"=\"bus_trap\"](" + bbox + ");"
                + ");out center tags;";

        String body = postForm(
                "https://overpass-api.de/api/interpreter",
                "data=" + URLEncoder.encode(q, "UTF-8"),
                25000
        );

        JSONArray elements = new JSONObject(body).optJSONArray("elements");
        if (elements == null) return out;

        for (int i = 0; i < elements.length() && out.size() < 12; i++) {
            JSONObject el = elements.getJSONObject(i);
            JSONObject tags = el.optJSONObject("tags");
            if (tags == null) continue;

            double lat;
            double lon;
            if (el.has("lat") && el.has("lon")) {
                lat = el.getDouble("lat");
                lon = el.getDouble("lon");
            } else {
                JSONObject center = el.optJSONObject("center");
                if (center == null) continue;
                lat = center.getDouble("lat");
                lon = center.getDouble("lon");
            }

            if (distanceToRouteMeters(lat, lon, routePoints) > 65.0) continue;

            if ("bus_trap".equals(tags.optString("barrier"))) {
                out.add(new Restriction(lat, lon, "BUSSLUIS", "bus_trap",
                        "Mogelijke bussluis vlak langs de berekende route.", true));
            }

            addNumericRestriction(out, lat, lon, "HOOGTE", "maxheight",
                    tags.optString("maxheight", ""), 2.76, "m");
            addNumericRestriction(out, lat, lon, "BREEDTE", "maxwidth",
                    tags.optString("maxwidth", ""), 2.34, "m");
            addNumericRestriction(out, lat, lon, "GEWICHT", "maxweight",
                    tags.optString("maxweight", ""), 3.50, "t");
        }
        return out;
    }

    private static void addNumericRestriction(List<Restriction> out, double lat, double lon,
                                              String type, String key, String raw,
                                              double vehicleValue, String unit) {
        if (raw == null || raw.isEmpty() || out.size() >= 12) return;
        Double limit = parseFirstNumber(raw);
        if (limit == null) return;

        boolean critical = limit <= vehicleValue;
        boolean close = limit <= vehicleValue + ("m".equals(unit) ? 0.20 : 0.30);
        if (!critical && !close) return;

        String description = critical
                ? String.format(new Locale("nl", "NL"), "%s-limiet %s %s is te laag voor het voertuigprofiel.",
                type.toLowerCase(Locale.ROOT), raw, unit)
                : String.format(new Locale("nl", "NL"), "%s-limiet %s %s ligt dicht bij het voertuigprofiel.",
                type.toLowerCase(Locale.ROOT), raw, unit);

        out.add(new Restriction(lat, lon, type, raw, description, critical));
    }

    private static Double parseFirstNumber(String raw) {
        String cleaned = raw.toLowerCase(Locale.ROOT)
                .replace(',', '.')
                .replace("meter", "")
                .replace("metre", "")
                .replace("meters", "")
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

    private static double distanceToRouteMeters(double lat, double lon, List<GeoPoint> points) {
        double min = Double.MAX_VALUE;
        int stride = Math.max(1, points.size() / 900);
        for (int i = 0; i < points.size(); i += stride) {
            GeoPoint p = points.get(i);
            min = Math.min(min, haversine(lat, lon, p.getLatitude(), p.getLongitude()));
            if (min < 18) break;
        }
        return min;
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
