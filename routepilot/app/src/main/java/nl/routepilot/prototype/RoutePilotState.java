package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;

public final class RoutePilotState {
    private static final String PREFS="routepilot_nav_state";
    private RoutePilotState(){}

    public static void update(Context c, boolean active, String instruction, String warning,
                              double stepDistanceM, double remainingM, long etaMs,
                              int speedLimit, int safetyScore, String destination) {
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putBoolean("active",active)
                .putString("instruction",instruction==null?"":instruction)
                .putString("warning",warning==null?"":warning)
                .putLong("stepDistance",Double.doubleToRawLongBits(stepDistanceM))
                .putLong("remaining",Double.doubleToRawLongBits(remainingM))
                .putLong("eta",etaMs)
                .putInt("speedLimit",speedLimit)
                .putInt("safetyScore",safetyScore)
                .putString("destination",destination==null?"":destination)
                .apply();
    }

    public static void saveRoute(Context c, OnlineServices.RouteResult route) {
        if (route == null || route.points == null) return;
        JSONArray arr = new JSONArray();
        try {
            int stride = Math.max(1, route.points.size() / 350);
            for (int i = 0; i < route.points.size(); i += stride) {
                GeoPoint p = route.points.get(i);
                JSONArray pt = new JSONArray();
                pt.put(p.getLatitude());
                pt.put(p.getLongitude());
                arr.put(pt);
            }
            if (!route.points.isEmpty()) {
                GeoPoint p = route.points.get(route.points.size() - 1);
                JSONArray pt = new JSONArray();
                pt.put(p.getLatitude());
                pt.put(p.getLongitude());
                arr.put(pt);
            }
        } catch (Exception ignored) {}
        JSONArray steps = new JSONArray();
        try {
            if (route.steps != null) {
                for (OnlineServices.NavStep s : route.steps) {
                    JSONObject o = new JSONObject();
                    o.put("lat", s.lat);
                    o.put("lon", s.lon);
                    o.put("distanceMeters", s.distanceMeters);
                    o.put("instruction", s.instruction);
                    o.put("roadName", s.roadName);
                    o.put("maneuverType", s.maneuverType);
                    o.put("modifier", s.modifier);
                    steps.put(o);
                }
            }
        } catch (Exception ignored) {}

        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putString("route_geometry",arr.toString())
                .putString("route_steps",steps.toString())
                .apply();
    }

    public static List<GeoPoint> loadRoute(Context c) {
        List<GeoPoint> out = new ArrayList<>();
        try {
            JSONArray arr = new JSONArray(c.getSharedPreferences(PREFS,Context.MODE_PRIVATE)
                    .getString("route_geometry","[]"));
            for (int i=0;i<arr.length();i++) {
                JSONArray p=arr.getJSONArray(i);
                out.add(new GeoPoint(p.getDouble(0),p.getDouble(1)));
            }
        } catch(Exception ignored){}
        return out;
    }

    public static List<OnlineServices.NavStep> loadSteps(Context c) {
        List<OnlineServices.NavStep> out = new ArrayList<>();
        try {
            JSONArray arr = new JSONArray(c.getSharedPreferences(PREFS,Context.MODE_PRIVATE)
                    .getString("route_steps","[]"));
            for (int i=0;i<arr.length();i++) {
                JSONObject o=arr.getJSONObject(i);
                out.add(new OnlineServices.NavStep(
                        o.optDouble("lat"),o.optDouble("lon"),
                        o.optDouble("distanceMeters"),
                        o.optString("instruction","Volg de route"),
                        o.optString("roadName",""),
                        o.optString("maneuverType",""),
                        o.optString("modifier","")
                ));
            }
        } catch(Exception ignored){}
        return out;
    }

    public static void setActivityForeground(Context c, boolean foreground) {
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putBoolean("activityForeground",foreground).apply();
    }

    public static boolean isActivityForeground(Context c) {
        return c.getSharedPreferences(PREFS,Context.MODE_PRIVATE)
                .getBoolean("activityForeground",false);
    }

    public static void savePlan(Context c, OnlineServices.RouteResult route,
                                OnlineServices.SearchResult destination) {
        if (route == null || destination == null) return;
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putLong("planDistance",Double.doubleToRawLongBits(route.distanceMeters))
                .putLong("planDuration",Double.doubleToRawLongBits(route.durationSeconds))
                .putLong("destLat",Double.doubleToRawLongBits(destination.lat))
                .putLong("destLon",Double.doubleToRawLongBits(destination.lon))
                .apply();
    }

    public static void updatePosition(Context c,double lat,double lon){
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putLong("lat",Double.doubleToRawLongBits(lat))
                .putLong("lon",Double.doubleToRawLongBits(lon)).apply();
    }

    public static void updateWmo(Context c, String phase, long waitRemainingMs,
                                 boolean waitExpired) {
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putString("wmoPhase",phase==null?"":phase)
                .putLong("wmoWaitRemaining",waitRemainingMs)
                .putBoolean("wmoWaitExpired",waitExpired)
                .apply();
    }

    public static void updateContext(Context c, String lookAhead, String arrival,
                                     String confidence) {
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putString("lookAhead",lookAhead==null?"":lookAhead)
                .putString("arrival",arrival==null?"":arrival)
                .putString("confidence",confidence==null?"":confidence)
                .apply();
    }

    public static Snapshot get(Context c){
        SharedPreferences p=c.getSharedPreferences(PREFS,Context.MODE_PRIVATE);
        Snapshot s=new Snapshot();
        s.active=p.getBoolean("active",false);s.instruction=p.getString("instruction","");
        s.warning=p.getString("warning","");s.etaMs=p.getLong("eta",0);
        s.speedLimit=p.getInt("speedLimit",-1);s.safetyScore=p.getInt("safetyScore",0);
        s.destination=p.getString("destination","");
        s.wmoPhase=p.getString("wmoPhase","");
        s.wmoWaitRemainingMs=p.getLong("wmoWaitRemaining",0L);
        s.wmoWaitExpired=p.getBoolean("wmoWaitExpired",false);
        s.lookAhead=p.getString("lookAhead","");
        s.arrival=p.getString("arrival","");
        s.confidence=p.getString("confidence","");
        s.planDistanceM=Double.longBitsToDouble(p.getLong("planDistance",Double.doubleToRawLongBits(0)));
        s.planDurationS=Double.longBitsToDouble(p.getLong("planDuration",Double.doubleToRawLongBits(0)));
        s.destLat=Double.longBitsToDouble(p.getLong("destLat",Double.doubleToRawLongBits(0)));
        s.destLon=Double.longBitsToDouble(p.getLong("destLon",Double.doubleToRawLongBits(0)));
        s.lat=Double.longBitsToDouble(p.getLong("lat",Double.doubleToRawLongBits(0)));
        s.lon=Double.longBitsToDouble(p.getLong("lon",Double.doubleToRawLongBits(0)));
        s.stepDistanceM=Double.longBitsToDouble(p.getLong("stepDistance",Double.doubleToRawLongBits(0)));
        s.remainingM=Double.longBitsToDouble(p.getLong("remaining",Double.doubleToRawLongBits(0)));
        return s;
    }

    public static class Snapshot{
        public boolean active;
        public String instruction,warning,destination;
        public String wmoPhase,lookAhead,arrival,confidence;
        public boolean wmoWaitExpired;
        public long wmoWaitRemainingMs;
        public double stepDistanceM,remainingM;
        public double planDistanceM,planDurationS,destLat,destLon;
        public long etaMs;
        public int speedLimit,safetyScore;
        public double lat,lon;
    }
}
