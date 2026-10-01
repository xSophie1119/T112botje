package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;

import org.osmdroid.util.GeoPoint;

public final class MunicipalityService {
    private static final String URL_TILBURG =
            "https://api.pdok.nl/kadaster/brk-bestuurlijke-gebieden/ogc/v1/collections/gemeentegebied/items?naam=Tilburg&f=json&limit=5";
    private static final Object LOCK = new Object();
    private static List<List<double[]>> rings = null;
    private static long loadedAt = 0L;

    private MunicipalityService() {}

    public static boolean isInTilburg(double lat, double lon) {
        try {
            ensureLoaded();
            if (rings == null || rings.isEmpty()) return conservativeFallback(lat, lon);
            boolean inside = false;
            for (List<double[]> ring : rings) {
                if (pointInRing(lat, lon, ring)) inside = !inside;
            }
            return inside;
        } catch (Exception e) {
            return conservativeFallback(lat, lon);
        }
    }

    public static List<String> countyCodesForRoute(List<GeoPoint> route) {
        Set<String> out = new LinkedHashSet<>();
        if (route == null || route.isEmpty()) return new ArrayList<>(out);
        try {
            double minLat=90,maxLat=-90,minLon=180,maxLon=-180;
            for (GeoPoint p : route) {
                minLat=Math.min(minLat,p.getLatitude());
                maxLat=Math.max(maxLat,p.getLatitude());
                minLon=Math.min(minLon,p.getLongitude());
                maxLon=Math.max(maxLon,p.getLongitude());
            }
            double pad=0.006;
            String url=String.format(java.util.Locale.US,
                    "https://api.pdok.nl/kadaster/brk-bestuurlijke-gebieden/ogc/v1/collections/gemeentegebied/items"
                            + "?bbox=%.6f,%.6f,%.6f,%.6f&f=json&limit=100",
                    minLon-pad,minLat-pad,maxLon+pad,maxLat+pad);
            JSONObject root=new JSONObject(get(url));
            JSONArray features=root.optJSONArray("features");
            if(features!=null){
                for(int i=0;i<features.length();i++){
                    JSONObject feature=features.optJSONObject(i);
                    JSONObject props=feature==null?null:feature.optJSONObject("properties");
                    if(props==null)continue;
                    String code=normalizeCountyCode(props.optString("code",""));
                    if(code.isEmpty())
                        code=normalizeCountyCode(props.optString("identificatie",""));
                    if(!code.isEmpty())out.add(code);
                }
            }
        } catch(Exception ignored) {}

        if(out.isEmpty()){
            for(GeoPoint p:route){
                if(isInTilburg(p.getLatitude(),p.getLongitude())){
                    out.add("GM0855");
                    break;
                }
            }
        }
        return new ArrayList<>(out);
    }

    private static String normalizeCountyCode(String raw) {
        if(raw==null)return "";
        String s=raw.trim().toUpperCase(java.util.Locale.ROOT);
        java.util.regex.Matcher m=java.util.regex.Pattern.compile("GM\\d{4}").matcher(s);
        if(m.find())return m.group();
        m=java.util.regex.Pattern.compile("\\b\\d{4}\\b").matcher(s);
        if(m.find())return "GM"+m.group();
        return "";
    }

    private static void ensureLoaded() throws Exception {
        synchronized (LOCK) {
            if (rings != null && System.currentTimeMillis() - loadedAt < 86_400_000L) return;
            String body = get(URL_TILBURG);
            JSONObject root = new JSONObject(body);
            JSONArray features = root.optJSONArray("features");
            List<List<double[]>> parsed = new ArrayList<>();
            if (features != null) {
                for (int i = 0; i < features.length(); i++) {
                    JSONObject f = features.getJSONObject(i);
                    JSONObject props = f.optJSONObject("properties");
                    if (props == null || !"Tilburg".equalsIgnoreCase(props.optString("naam"))) continue;
                    JSONObject geometry = f.optJSONObject("geometry");
                    if (geometry != null) parseGeometry(geometry, parsed);
                }
            }
            rings = parsed;
            loadedAt = System.currentTimeMillis();
        }
    }

    private static void parseGeometry(JSONObject geometry, List<List<double[]>> out) {
        String type = geometry.optString("type", "");
        JSONArray c = geometry.optJSONArray("coordinates");
        if (c == null) return;

        if ("Polygon".equalsIgnoreCase(type)) {
            parsePolygon(c, out);
        } else if ("MultiPolygon".equalsIgnoreCase(type)) {
            for (int p = 0; p < c.length(); p++) {
                JSONArray polygon = c.optJSONArray(p);
                if (polygon != null) parsePolygon(polygon, out);
            }
        }
    }

    private static void parsePolygon(JSONArray polygon, List<List<double[]>> out) {
        for (int r = 0; r < polygon.length(); r++) {
            JSONArray ring = polygon.optJSONArray(r);
            if (ring == null) continue;
            List<double[]> pts = new ArrayList<>();
            for (int i = 0; i < ring.length(); i++) {
                JSONArray xy = ring.optJSONArray(i);
                if (xy == null || xy.length() < 2) continue;
                pts.add(new double[]{xy.optDouble(1), xy.optDouble(0)});
            }
            if (pts.size() >= 4) out.add(pts);
        }
    }

    private static boolean pointInRing(double lat, double lon, List<double[]> ring) {
        boolean inside = false;
        for (int i = 0, j = ring.size() - 1; i < ring.size(); j = i++) {
            double yi = ring.get(i)[0], xi = ring.get(i)[1];
            double yj = ring.get(j)[0], xj = ring.get(j)[1];
            boolean intersect = ((yi > lat) != (yj > lat))
                    && (lon < (xj - xi) * (lat - yi) / ((yj - yi) + 1e-12) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }

    private static boolean conservativeFallback(double lat, double lon) {
        return lat >= 51.50 && lat <= 51.68 && lon >= 4.98 && lon <= 5.22;
    }

    private static String get(String raw) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(raw).openConnection();
        con.setConnectTimeout(10000);
        con.setReadTimeout(15000);
        con.setRequestProperty("User-Agent", OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept", "application/geo+json, application/json");
        int code = con.getResponseCode();
        BufferedReader br = new BufferedReader(new InputStreamReader(
                code >= 200 && code < 300 ? con.getInputStream() : con.getErrorStream(),
                StandardCharsets.UTF_8));
        StringBuilder sb = new StringBuilder();
        String line;
        while ((line = br.readLine()) != null) sb.append(line);
        br.close();
        con.disconnect();
        if (code < 200 || code >= 300) throw new IllegalStateException("PDOK HTTP " + code);
        return sb.toString();
    }
}
