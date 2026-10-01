package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;

public final class PortalCorrectionStore {
    private static final String PREFS = "routepilot_portal";
    private static final String KEY_CORRECTIONS = "corrections";
    private static final String KEY_URL = "portal_url";
    private static final String KEY_TOKEN = "portal_token";
    private static final String KEY_LAST_SYNC = "last_sync";

    public static final class Correction {
        public int id;
        public String type;
        public double lat, lon, radiusM;
        public int strength;
        public String note;
    }

    private PortalCorrectionStore() {}

    public static void saveSettings(Context c, String url, String token) {
        c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putString(KEY_URL, url == null ? "" : url.trim())
                .putString(KEY_TOKEN, token == null ? "" : token.trim())
                .apply();
    }

    public static String portalUrl(Context c) {
        return c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_URL, "");
    }

    public static String portalToken(Context c) {
        return c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_TOKEN, "");
    }

    public static long lastSync(Context c) {
        return c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong(KEY_LAST_SYNC, 0L);
    }

    public static synchronized void replaceFromJson(Context c, JSONObject root) {
        JSONArray input = root == null ? null : root.optJSONArray("corrections");
        JSONArray stored = new JSONArray();
        if (input != null) {
            for (int i = 0; i < input.length(); i++) {
                JSONObject o = input.optJSONObject(i);
                if (o == null || o.optInt("active", 1) == 0) continue;
                JSONObject x = new JSONObject();
                try {
                    x.put("id", o.optInt("id", 0));
                    x.put("type", o.optString("type", ""));
                    x.put("lat", o.optDouble("lat"));
                    x.put("lon", o.optDouble("lon"));
                    x.put("radius_m", Math.max(5.0, o.optDouble("radius_m", 45.0)));
                    x.put("strength", Math.max(1, Math.min(5, o.optInt("strength", 1))));
                    x.put("note", o.optString("note", ""));
                    stored.put(x);
                } catch (Exception ignored) {}
            }
        }
        c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putString(KEY_CORRECTIONS, stored.toString())
                .putLong(KEY_LAST_SYNC, System.currentTimeMillis())
                .apply();
    }

    public static List<Correction> all(Context c) {
        List<Correction> out = new ArrayList<>();
        try {
            JSONArray arr = new JSONArray(c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                    .getString(KEY_CORRECTIONS, "[]"));
            for (int i = 0; i < arr.length(); i++) {
                JSONObject o = arr.optJSONObject(i);
                if (o == null) continue;
                Correction x = new Correction();
                x.id = o.optInt("id", 0);
                x.type = o.optString("type", "");
                x.lat = o.optDouble("lat");
                x.lon = o.optDouble("lon");
                x.radiusM = o.optDouble("radius_m", 45.0);
                x.strength = o.optInt("strength", 1);
                x.note = o.optString("note", "");
                out.add(x);
            }
        } catch (Exception ignored) {}
        return out;
    }

    public static int count(Context c) {
        return all(c).size();
    }

    public static int routeAdjustment(Context c, List<GeoPoint> route) {
        if (route == null || route.isEmpty()) return 0;
        int adjustment = 0;
        for (Correction x : all(c)) {
            double d = minDistance(route, x.lat, x.lon);
            if (d > x.radiusM) continue;
            int s = Math.max(1, x.strength);
            switch (x.type) {
                case "road_closed":
                case "bus_trap":
                case "height_block":
                    adjustment += 50 * s;
                    break;
                case "too_narrow":
                    adjustment += 28 * s;
                    break;
                case "avoid":
                case "bus_lane_forbidden":
                case "bad_stop":
                case "lift_bad":
                    adjustment += 16 * s;
                    break;
                case "prefer":
                case "good_stop":
                case "bus_lane_allowed":
                case "turning_ok":
                case "lift_ok":
                    adjustment -= 5 * s;
                    break;
                default:
                    break;
            }
        }
        return Math.max(-20, Math.min(90, adjustment));
    }

    public static int hardHitCount(Context c, List<GeoPoint> route) {
        if (route == null || route.isEmpty()) return 0;
        int n = 0;
        for (Correction x : all(c)) {
            if (!("road_closed".equals(x.type)
                    || "bus_trap".equals(x.type)
                    || "height_block".equals(x.type)
                    || "too_narrow".equals(x.type))) continue;
            if (minDistance(route, x.lat, x.lon) <= x.radiusM) n++;
        }
        return n;
    }

    public static boolean ignoreDoorSide(Context c, double lat, double lon) {
        return hasTypeNear(c, "ignore_door_side", lat, lon);
    }

    public static Correction nearestOfType(Context c, String type, double lat, double lon) {
        Correction best = null;
        double bestD = Double.MAX_VALUE;
        for (Correction x : all(c)) {
            if (!type.equals(x.type)) continue;
            double d = OnlineServices.distanceMeters(lat, lon, x.lat, x.lon);
            if (d <= Math.max(20.0, x.radiusM) && d < bestD) {
                best = x;
                bestD = d;
            }
        }
        return best;
    }

    public static boolean hasTypeNear(Context c, String type, double lat, double lon) {
        return nearestOfType(c, type, lat, lon) != null;
    }

    private static double minDistance(List<GeoPoint> route, double lat, double lon) {
        double best = Double.MAX_VALUE;
        int stride = Math.max(1, route.size() / 900);
        for (int i = 0; i < route.size(); i += stride) {
            GeoPoint p = route.get(i);
            double d = OnlineServices.distanceMeters(lat, lon, p.getLatitude(), p.getLongitude());
            if (d < best) best = d;
            if (best < 5.0) break;
        }
        return best;
    }
}
