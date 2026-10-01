package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class DestinationAccessService {

    public static final class Result {
        public boolean scanOk = false;
        public boolean oneWayNearby = false;
        public boolean deadEndSignal = false;
        public boolean cyclewayNearby = false;
        public boolean parkingOpportunityNearby = false;
        public boolean barrierNearby = false;
        public int turningOptions = 0;
        public int narrowSignals = 0;
        public GeoPoint fallbackStop = null;
        public final List<String> notes = new ArrayList<>();

        public boolean shouldSkipDoorPreference() {
            return !RoutingRules.doorPreferenceAllowed(
                    oneWayNearby, barrierNearby, deadEndSignal, turningOptions);
        }

        public String summary() {
            if (!scanOk) return "Bestemmingsscan niet beschikbaar.";
            StringBuilder b = new StringBuilder();
            if (oneWayNearby) b.append("eenrichtingsverkeer nabij bestemming • ");
            if (deadEndSignal) b.append("doodlopend-signaal • ");
            if (turningOptions > 0) b.append(turningOptions).append(" keeroptie(s) • ");
            if (cyclewayNearby) b.append("fietsinfrastructuur nabij stopzone • ");
            if (parkingOpportunityNearby) b.append("parkeer/stopruimte-indicatie • ");
            if (barrierNearby) b.append("barrière/toegangsrisico • ");
            if (narrowSignals > 0) b.append(narrowSignals).append(" krappe weg-indicatie(s) • ");
            if (b.length() == 0) return "Geen bijzondere bestemmingssignalen gevonden.";
            if (b.length() >= 3) b.setLength(b.length() - 3);
            return b.toString();
        }
    }

    private DestinationAccessService() {}

    public static Result scan(OnlineServices.SearchResult destination,
                              List<GeoPoint> route) {
        Result out = new Result();
        if (destination == null) return out;

        try {
            String q = "[out:json][timeout:16];("
                    + "way(around:140," + fmt(destination.lat) + "," + fmt(destination.lon) + ")[highway];"
                    + "node(around:150," + fmt(destination.lat) + "," + fmt(destination.lon) + ")[highway=turning_circle];"
                    + "node(around:150," + fmt(destination.lat) + "," + fmt(destination.lon) + ")[highway=turning_loop];"
                    + "node(around:130," + fmt(destination.lat) + "," + fmt(destination.lon) + ")[noexit=yes];"
                    + "nwr(around:100," + fmt(destination.lat) + "," + fmt(destination.lon) + ")[barrier];"
                    + "nwr(around:120," + fmt(destination.lat) + "," + fmt(destination.lon) + ")[amenity=parking];"
                    + ");out center tags;";
            JSONObject root = new JSONObject(postForm(
                    "https://overpass-api.de/api/interpreter",
                    "data=" + URLEncoder.encode(q, "UTF-8"), 22000));
            JSONArray elements = root.optJSONArray("elements");
            if (elements == null) return out;

            for (int i = 0; i < elements.length(); i++) {
                JSONObject e = elements.optJSONObject(i);
                if (e == null) continue;
                JSONObject tags = e.optJSONObject("tags");
                if (tags == null) continue;

                String highway = tags.optString("highway", "");
                String oneway = tags.optString("oneway", "");
                String noexit = tags.optString("noexit", "");
                String barrier = tags.optString("barrier", "");
                String amenity = tags.optString("amenity", "");
                String width = tags.optString("width", "");
                String cycleway = tags.optString("cycleway", "");
                String cycleLeft = tags.optString("cycleway:left", "");
                String cycleRight = tags.optString("cycleway:right", "");
                String bicycle = tags.optString("bicycle", "");
                String service = tags.optString("service", "");

                if ("yes".equalsIgnoreCase(oneway)
                        || "-1".equals(oneway)
                        || "reversible".equalsIgnoreCase(oneway)) {
                    out.oneWayNearby = true;
                }

                if ("yes".equalsIgnoreCase(noexit)) out.deadEndSignal = true;

                if ("turning_circle".equals(highway)
                        || "turning_loop".equals(highway)) {
                    out.turningOptions++;
                }

                if (!barrier.isEmpty()
                        && !"kerb".equals(barrier)
                        && !"entrance".equals(barrier)) {
                    out.barrierNearby = true;
                }

                if ("parking".equals(amenity)
                        || "parking_aisle".equals(service)
                        || "layby".equals(highway)
                        || "rest_area".equals(highway)) {
                    out.parkingOpportunityNearby = true;
                }

                if (!cycleway.isEmpty()
                        || !cycleLeft.isEmpty()
                        || !cycleRight.isEmpty()
                        || "designated".equalsIgnoreCase(bicycle)
                        || "cycleway".equals(highway)) {
                    out.cyclewayNearby = true;
                }

                Double w = number(width);
                if (w != null && w <= 3.25) out.narrowSignals++;
                if ("track".equals(highway)
                        || ("service".equals(highway) && !"parking_aisle".equals(service))) {
                    out.narrowSignals++;
                }
            }

            out.scanOk = true;
        } catch (Exception e) {
            out.scanOk = false;
            out.notes.add("Bestemmingsscan tijdelijk niet beschikbaar.");
        }

        if (route != null && route.size() > 2
                && (out.deadEndSignal || out.barrierNearby)
                && out.turningOptions == 0) {
            out.fallbackStop = pointBeforeDestination(route, 85.0);
            if (out.fallbackStop != null) {
                out.notes.add("Fallback-stoppunt ongeveer 85 m vóór het route-einde berekend.");
            }
        }

        return out;
    }

    private static GeoPoint pointBeforeDestination(List<GeoPoint> route, double meters) {
        double total = 0.0;
        for (int i = route.size() - 1; i > 0; i--) {
            GeoPoint a = route.get(i);
            GeoPoint b = route.get(i - 1);
            double seg = OnlineServices.distanceMeters(
                    a.getLatitude(), a.getLongitude(),
                    b.getLatitude(), b.getLongitude());
            total += seg;
            if (total >= meters) return b;
        }
        return route.get(0);
    }

    private static String fmt(double v) {
        return String.format(Locale.US, "%.6f", v);
    }

    private static Double number(String raw) {
        if (raw == null || raw.trim().isEmpty()) return null;
        String n = raw.replace(',', '.').replaceAll("[^0-9.]", "");
        if (n.isEmpty()) return null;
        try { return Double.parseDouble(n); } catch (Exception e) { return null; }
    }

    private static String postForm(String rawUrl, String payload, int timeoutMs) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(rawUrl).openConnection();
        con.setRequestMethod("POST");
        con.setConnectTimeout(timeoutMs);
        con.setReadTimeout(timeoutMs);
        con.setDoOutput(true);
        con.setRequestProperty("User-Agent", OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept", "application/json");
        con.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        byte[] data = payload.getBytes(StandardCharsets.UTF_8);
        try (OutputStream os = con.getOutputStream()) { os.write(data); }
        int code = con.getResponseCode();
        InputStream stream = code >= 200 && code < 300
                ? con.getInputStream() : con.getErrorStream();
        StringBuilder sb = new StringBuilder();
        if (stream != null) {
            try (BufferedReader br = new BufferedReader(
                    new InputStreamReader(stream, StandardCharsets.UTF_8))) {
                String line; while ((line = br.readLine()) != null) sb.append(line);
            }
        }
        con.disconnect();
        if (code < 200 || code >= 300)
            throw new IllegalStateException("Overpass bestemming HTTP " + code);
        return sb.toString();
    }
}
