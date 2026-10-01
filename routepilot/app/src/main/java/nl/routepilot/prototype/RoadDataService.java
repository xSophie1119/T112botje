package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

public final class RoadDataService {
    private static final String SIGNS_BASE =
            "https://data.ndw.nu/api/rest/static-road-data/traffic-signs/v4/current-state";
    private static final long CACHE_MS = 6 * 60 * 60 * 1000L;
    private static final Object LOCK = new Object();
    private static final Map<String,List<Sign>> SIGN_CACHE = new HashMap<>();
    private static final Map<String,Long> CACHE_TIME = new HashMap<>();

    private RoadDataService() {}

    public static final class Sign {
        public final String id, rvvCode, blackCode, roadName, countyCode;
        public final double lat, lon;
        public final int bearing;
        public int routeIndex = -1;
        public double routeDistance = Double.MAX_VALUE;

        Sign(String id, String rvvCode, String blackCode, String roadName,
             String countyCode, double lat, double lon, int bearing) {
            this.id = id; this.rvvCode = rvvCode; this.blackCode = blackCode;
            this.roadName = roadName; this.countyCode = countyCode;
            this.lat = lat; this.lon = lon; this.bearing = bearing;
        }

        public boolean isSpeed() { return rvvCode != null && rvvCode.startsWith("A1"); }
        public Integer speedKmh() {
            try {
                if (!isSpeed()) return null;
                String n = blackCode == null ? "" : blackCode.replaceAll("[^0-9]", "");
                return n.isEmpty() ? null : Integer.parseInt(n);
            } catch (Exception e) { return null; }
        }

        public String description() {
            if (isSpeed() && speedKmh() != null) return "Maximum " + speedKmh() + " km/u";
            if ("C1".equals(rvvCode)) return "Gesloten in beide richtingen";
            if ("C6".equals(rvvCode)) return "Gesloten voor motorvoertuigen op meer dan twee wielen";
            if ("C7".equals(rvvCode)) return "Gesloten voor vrachtauto's";
            if ("C12".equals(rvvCode)) return "Gesloten voor motorvoertuigen";
            if ("C17".equals(rvvCode)) return "Lengtebeperking " + blackCode;
            if ("C18".equals(rvvCode)) return "Breedtebeperking " + blackCode;
            if ("C20".equals(rvvCode)) return "Aslastbeperking " + blackCode;
            if ("C21".equals(rvvCode)) return "Gewichtsbeperking " + blackCode;
            if ("C19".equals(rvvCode)) return "Hoogtebeperking " + blackCode;
            return "Verkeersbord " + rvvCode + (blackCode == null || blackCode.isEmpty() ? "" : " " + blackCode);
        }
    }

    public static List<Sign> signsNearRoute(List<GeoPoint> route) throws Exception {
        List<Sign> out = new ArrayList<>();
        if (route == null || route.size() < 2) return out;

        List<String> counties = MunicipalityService.countyCodesForRoute(route);
        if (counties.isEmpty()) counties.add("GM0855");

        for (String countyCode : counties) {
            List<Sign> countySigns;
            try {
                countySigns = loadSigns(countyCode);
            } catch (Exception e) {
                continue;
            }

            for (Sign source : countySigns) {
                Sign s = new Sign(source.id, source.rvvCode, source.blackCode,
                        source.roadName, source.countyCode, source.lat, source.lon, source.bearing);
                int idx = closestIndex(s.lat, s.lon, route);
                GeoPoint p = route.get(idx);
                double d = OnlineServices.distanceMeters(
                        s.lat, s.lon, p.getLatitude(), p.getLongitude());
                if (d <= 70) {
                    s.routeIndex = idx;
                    s.routeDistance = d;
                    if (directionRelevant(s, route)) out.add(s);
                }
            }
        }

        out.sort(Comparator.comparingInt(a -> a.routeIndex));
        return out;
    }

    public static Integer speedLimitAt(int routeIndex, List<Sign> signs) {
        Integer current = null;
        int best = -1;
        if (signs == null) return null;
        for (Sign s : signs) {
            if (!s.isSpeed() || s.routeIndex < 0 || s.routeIndex > routeIndex) continue;
            Integer kmh = s.speedKmh();
            if (kmh != null && s.routeIndex >= best) {
                current = kmh;
                best = s.routeIndex;
            }
        }
        return current;
    }

    public static boolean isRestriction(Sign s) {
        if (s == null || s.rvvCode == null) return false;
        return s.rvvCode.matches("C1|C6|C7|C12|C17|C18|C19|C20|C21");
    }

    private static boolean directionRelevant(Sign s, List<GeoPoint> route) {
        if (s.bearing < 0 || s.routeIndex < 0 || route.size() < 2) return true;
        int a = Math.max(0, Math.min(route.size() - 2, s.routeIndex));
        GeoPoint p1 = route.get(a), p2 = route.get(a + 1);
        double routeBearing = bearing(p1.getLatitude(), p1.getLongitude(),
                p2.getLatitude(), p2.getLongitude());
        double diff = angleDiff(routeBearing, s.bearing);
        return diff <= 105 || angleDiff(routeBearing, (s.bearing + 180) % 360) <= 35;
    }

    private static int closestIndex(double lat, double lon, List<GeoPoint> route) {
        int best = 0;
        double min = Double.MAX_VALUE;
        int stride = Math.max(1, route.size() / 1200);
        for (int i = 0; i < route.size(); i += stride) {
            GeoPoint p = route.get(i);
            double d = OnlineServices.distanceMeters(lat, lon, p.getLatitude(), p.getLongitude());
            if (d < min) { min = d; best = i; }
        }
        return best;
    }

    private static double bearing(double lat1, double lon1, double lat2, double lon2) {
        double y = Math.sin(Math.toRadians(lon2 - lon1)) * Math.cos(Math.toRadians(lat2));
        double x = Math.cos(Math.toRadians(lat1)) * Math.sin(Math.toRadians(lat2))
                - Math.sin(Math.toRadians(lat1)) * Math.cos(Math.toRadians(lat2))
                * Math.cos(Math.toRadians(lon2 - lon1));
        return (Math.toDegrees(Math.atan2(y, x)) + 360.0) % 360.0;
    }

    private static double angleDiff(double a, double b) {
        double d = Math.abs(a - b) % 360.0;
        return d > 180 ? 360 - d : d;
    }

    private static List<Sign> loadSigns(String countyCode) throws Exception {
        synchronized (LOCK) {
            List<Sign> cached = SIGN_CACHE.get(countyCode);
            Long at = CACHE_TIME.get(countyCode);
            if (cached != null && at != null
                    && System.currentTimeMillis() - at < CACHE_MS) {
                return new ArrayList<>(cached);
            }
        }

        String url = SIGNS_BASE + "?countyCode="
                + java.net.URLEncoder.encode(countyCode, "UTF-8")
                + "&status=PLACED";
        JSONObject root = new JSONObject(get(url));
        JSONArray features = root.optJSONArray("features");
        List<Sign> parsed = new ArrayList<>();
        if (features != null) {
            for (int i = 0; i < features.length(); i++) {
                JSONObject feature = features.optJSONObject(i);
                if (feature == null) continue;
                JSONObject p = feature.optJSONObject("properties");
                JSONObject g = feature.optJSONObject("geometry");
                if (p == null || g == null) continue;
                JSONArray coords = g.optJSONArray("coordinates");
                if (coords == null || coords.length() < 2) continue;

                JSONObject loc = p.optJSONObject("location");
                JSONObject road = loc == null ? null : loc.optJSONObject("road");
                JSONObject county = loc == null ? null : loc.optJSONObject("county");

                int bearing = p.has("bearing")
                        ? p.optInt("bearing", -1)
                        : (loc == null ? -1 : loc.optInt("bearing", -1));

                String roadName = p.optString("roadName", "");
                if (roadName.isEmpty() && road != null) roadName = road.optString("name", "");

                String parsedCounty = p.optString("countyCode", "");
                if (parsedCounty.isEmpty() && county != null)
                    parsedCounty = county.optString("code", "");
                if (parsedCounty.isEmpty()) parsedCounty = countyCode;

                parsed.add(new Sign(
                        feature.optString("id", p.optString("id", "")),
                        p.optString("rvvCode", ""),
                        p.optString("blackCode", ""),
                        roadName,
                        parsedCounty,
                        coords.optDouble(1), coords.optDouble(0), bearing
                ));
            }
        }

        synchronized (LOCK) {
            SIGN_CACHE.put(countyCode, parsed);
            CACHE_TIME.put(countyCode, System.currentTimeMillis());
        }
        return new ArrayList<>(parsed);
    }

    private static String get(String raw) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(raw).openConnection();
        con.setConnectTimeout(12000); con.setReadTimeout(25000);
        con.setRequestProperty("User-Agent", OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept", "application/geo+json");
        int code = con.getResponseCode();
        BufferedReader br = new BufferedReader(new InputStreamReader(
                code >= 200 && code < 300 ? con.getInputStream() : con.getErrorStream(),
                StandardCharsets.UTF_8));
        StringBuilder sb = new StringBuilder();
        String line; while ((line = br.readLine()) != null) sb.append(line);
        br.close(); con.disconnect();
        if (code < 200 || code >= 300) throw new IllegalStateException("NDW borden HTTP " + code);
        return sb.toString();
    }
}
