package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class RoutePilotStore {
    private static final String PREFS = "routepilot_v2_store";
    private static final String KEY_REPORTS = "reports";
    private static final String KEY_PROFILES = "profiles";
    private static final String KEY_LEARNED = "learned";
    private static final String KEY_TRIPS = "trips";
    private static final String KEY_ACTIVE = "active_trip";

    private RoutePilotStore() {}

    public static final class Report {
        public String type, note;
        public double lat, lon;
        public long time;
    }

    public static final class LocationProfile {
        public String label, note;
        public double lat, lon;
        public double preferredArrivalBearing = Double.NaN;
        public boolean rightDoorToEntrance = true;
        public int visits = 0;
        public int successfulArrivals = 0;
        public long lastSuccessAt = 0L;
        public long updatedAt = 0L;

        public boolean hasEntrancePoint = false;
        public double entranceLat = 0.0;
        public double entranceLon = 0.0;

        public boolean hasStopPoint = false;
        public double stopLat = 0.0;
        public double stopLon = 0.0;

        // 0 = onbekend, 1 = aandacht, 2 = geschikt
        public int liftSpaceStatus = 0;
        // 0 = onbekend, 1 = lastig, 2 = geschikt
        public int departureStatus = 0;
    }

    public static final class LearnedPoint {
        public double lat, lon;
        public int count;
        public String reason;
        public boolean confirmed;
        public long firstSeenAt;
        public long lastSeenAt;
    }

    public static final class Trip {
        public String id, destinationLabel;
        public long startedAt, endedAt;
        public double plannedDistanceM, actualDistanceM;
        public int warnings, reroutes;
        public JSONArray points = new JSONArray();
        public JSONArray plannedPoints = new JSONArray();
    }

    public static synchronized void addReport(Context c, String type, String note, double lat, double lon) {
        JSONArray arr = getArray(c, KEY_REPORTS);
        try {
            JSONObject o = new JSONObject();
            o.put("type", type); o.put("note", note == null ? "" : note);
            o.put("lat", lat); o.put("lon", lon); o.put("time", System.currentTimeMillis());
            arr.put(o);
            trimFront(arr, 250);
            putArray(c, KEY_REPORTS, arr);
        } catch (Exception ignored) {}
        if (type != null && (type.contains("vermijd") || type.contains("niet bereikbaar")
                || type.contains("smalle") || type.contains("bussluis") || type.contains("te laag"))) {
            learnAvoidance(c, lat, lon, type);
        }
    }

    public static synchronized List<Report> reports(Context c) {
        List<Report> out = new ArrayList<>();
        JSONArray arr = getArray(c, KEY_REPORTS);
        for (int i = 0; i < arr.length(); i++) {
            try {
                JSONObject o = arr.getJSONObject(i);
                Report r = new Report();
                r.type=o.optString("type"); r.note=o.optString("note");
                r.lat=o.optDouble("lat"); r.lon=o.optDouble("lon"); r.time=o.optLong("time");
                out.add(r);
            } catch (Exception ignored) {}
        }
        return out;
    }

    public static synchronized void saveProfile(Context c, LocationProfile p) {
        JSONArray arr = getArray(c, KEY_PROFILES);
        int found = -1;
        for (int i=0;i<arr.length();i++) {
            JSONObject o=arr.optJSONObject(i);
            if (o==null) continue;
            if (OnlineServices.distanceMeters(p.lat,p.lon,o.optDouble("lat"),o.optDouble("lon")) < 80) {
                found=i; break;
            }
        }
        try {
            JSONObject o = new JSONObject();
            o.put("label",p.label); o.put("note",p.note==null?"":p.note);
            o.put("lat",p.lat); o.put("lon",p.lon);
            o.put("preferredArrivalBearing",p.preferredArrivalBearing);
            o.put("rightDoorToEntrance",p.rightDoorToEntrance);
            o.put("visits",p.visits);
            o.put("successfulArrivals",p.successfulArrivals);
            o.put("lastSuccessAt",p.lastSuccessAt);
            o.put("updatedAt",System.currentTimeMillis());
            o.put("hasEntrancePoint",p.hasEntrancePoint);
            o.put("entranceLat",p.entranceLat);
            o.put("entranceLon",p.entranceLon);
            o.put("hasStopPoint",p.hasStopPoint);
            o.put("stopLat",p.stopLat);
            o.put("stopLon",p.stopLon);
            o.put("liftSpaceStatus",p.liftSpaceStatus);
            o.put("departureStatus",p.departureStatus);
            if (found>=0) arr.put(found,o); else arr.put(o);
            trimFront(arr,100);
            putArray(c,KEY_PROFILES,arr);
        } catch(Exception ignored){}
    }

    public static synchronized LocationProfile findProfile(Context c,double lat,double lon) {
        JSONArray arr=getArray(c,KEY_PROFILES);
        LocationProfile best=null; double bestD=Double.MAX_VALUE;
        for(int i=0;i<arr.length();i++){
            JSONObject o=arr.optJSONObject(i); if(o==null)continue;
            double d=OnlineServices.distanceMeters(lat,lon,o.optDouble("lat"),o.optDouble("lon"));
            if(d<100 && d<bestD){
                LocationProfile p=new LocationProfile();
                p.label=o.optString("label"); p.note=o.optString("note");
                p.lat=o.optDouble("lat"); p.lon=o.optDouble("lon");
                p.preferredArrivalBearing=o.optDouble("preferredArrivalBearing",Double.NaN);
                p.rightDoorToEntrance=o.optBoolean("rightDoorToEntrance",true);
                p.visits=o.optInt("visits",0);
                p.successfulArrivals=o.optInt("successfulArrivals",0);
                p.lastSuccessAt=o.optLong("lastSuccessAt",0L);
                p.updatedAt=o.optLong("updatedAt",0L);
                p.hasEntrancePoint=o.optBoolean("hasEntrancePoint",false);
                p.entranceLat=o.optDouble("entranceLat",0.0);
                p.entranceLon=o.optDouble("entranceLon",0.0);
                p.hasStopPoint=o.optBoolean("hasStopPoint",false);
                p.stopLat=o.optDouble("stopLat",0.0);
                p.stopLon=o.optDouble("stopLon",0.0);
                p.liftSpaceStatus=o.optInt("liftSpaceStatus",0);
                p.departureStatus=o.optInt("departureStatus",0);
                best=p; bestD=d;
            }
        }
        return best;
    }

    public static synchronized void learnAvoidance(Context c,double lat,double lon,String reason){
        JSONArray arr=getArray(c,KEY_LEARNED);
        long now=System.currentTimeMillis();
        for(int i=0;i<arr.length();i++){
            JSONObject o=arr.optJSONObject(i); if(o==null)continue;
            if(OnlineServices.distanceMeters(lat,lon,o.optDouble("lat"),o.optDouble("lon"))<90){
                try{
                    o.put("count",o.optInt("count",0)+1);
                    o.put("lastSeenAt",now);
                    if(reason!=null && !reason.trim().isEmpty()) o.put("reason",reason);
                    putArray(c,KEY_LEARNED,arr);
                }catch(Exception ignored){}
                return;
            }
        }
        try{
            JSONObject o=new JSONObject();
            o.put("lat",lat);o.put("lon",lon);o.put("count",1);
            o.put("reason",reason==null?"":reason);
            o.put("confirmed",false);
            o.put("firstSeenAt",now);
            o.put("lastSeenAt",now);
            arr.put(o); trimFront(arr,200); putArray(c,KEY_LEARNED,arr);
        }catch(Exception ignored){}
    }

    public static synchronized void confirmAvoidance(Context c,double lat,double lon,String reason){
        JSONArray arr=getArray(c,KEY_LEARNED);
        long now=System.currentTimeMillis();
        for(int i=0;i<arr.length();i++){
            JSONObject o=arr.optJSONObject(i); if(o==null)continue;
            if(OnlineServices.distanceMeters(lat,lon,o.optDouble("lat"),o.optDouble("lon"))<100){
                try{
                    o.put("confirmed",true);
                    o.put("reason",reason==null?"bevestigd door chauffeur":reason);
                    o.put("lastSeenAt",now);
                    putArray(c,KEY_LEARNED,arr);
                }catch(Exception ignored){}
                return;
            }
        }
        try{
            JSONObject o=new JSONObject();
            o.put("lat",lat);o.put("lon",lon);o.put("count",1);
            o.put("reason",reason==null?"bevestigd door chauffeur":reason);
            o.put("confirmed",true);o.put("firstSeenAt",now);o.put("lastSeenAt",now);
            arr.put(o);trimFront(arr,200);putArray(c,KEY_LEARNED,arr);
        }catch(Exception ignored){}
    }

    public static synchronized List<LearnedPoint> learned(Context c){
        List<LearnedPoint> out=new ArrayList<>();
        JSONArray arr=getArray(c,KEY_LEARNED);
        for(int i=0;i<arr.length();i++){
            JSONObject o=arr.optJSONObject(i); if(o==null)continue;
            LearnedPoint p=new LearnedPoint();
            p.lat=o.optDouble("lat");p.lon=o.optDouble("lon");p.count=o.optInt("count");
            p.reason=o.optString("reason");
            p.confirmed=o.optBoolean("confirmed",false);
            p.firstSeenAt=o.optLong("firstSeenAt",0L);
            p.lastSeenAt=o.optLong("lastSeenAt",0L);
            out.add(p);
        }
        return out;
    }

    public static int learnedPenalty(Context c,List<GeoPoint> route){
        int penalty=0;
        long now=System.currentTimeMillis();
        for(LearnedPoint p:learned(c)){
            if(!p.confirmed) continue;
            if(p.lastSeenAt>0 && now-p.lastSeenAt>180L*24L*60L*60L*1000L) continue;
            double min=Double.MAX_VALUE;
            int stride=Math.max(1,route.size()/600);
            for(int i=0;i<route.size();i+=stride){
                GeoPoint g=route.get(i);
                min=Math.min(min,OnlineServices.distanceMeters(p.lat,p.lon,g.getLatitude(),g.getLongitude()));
                if(min<45) break;
            }
            if(min<55) penalty+=Math.min(18,3+p.count*2);
        }
        return penalty;
    }

    public static synchronized void markArrivalSuccess(Context c,double lat,double lon,
                                                       double bearing,boolean liftOk,
                                                       boolean departureOk){
        LocationProfile p=findProfile(c,lat,lon);
        if(p==null){
            p=new LocationProfile();
            p.lat=lat;p.lon=lon;p.label="Bestemming";
        }
        p.visits++;
        p.successfulArrivals++;
        p.lastSuccessAt=System.currentTimeMillis();
        p.preferredArrivalBearing=bearing;
        if(liftOk)p.liftSpaceStatus=2;
        if(departureOk)p.departureStatus=2;
        saveProfile(c,p);
    }

    public static synchronized int pendingLearningCount(Context c){
        int n=0;
        for(LearnedPoint p:learned(c)){
            if(!p.confirmed && p.count>=3)n++;
        }
        return n;
    }

    public static synchronized LearnedPoint firstPendingLearning(Context c){
        LearnedPoint best=null;
        for(LearnedPoint p:learned(c)){
            if(p.confirmed || p.count<3) continue;
            if(best==null || p.lastSeenAt>best.lastSeenAt) best=p;
        }
        return best;
    }

    public static synchronized String beginTrip(Context c,String destination,double plannedDistanceM){
        String id=Long.toString(System.currentTimeMillis());
        try{
            JSONObject o=new JSONObject();
            o.put("id",id);o.put("destinationLabel",destination);
            o.put("startedAt",System.currentTimeMillis());o.put("endedAt",0);
            o.put("plannedDistanceM",plannedDistanceM);o.put("actualDistanceM",0);
            o.put("warnings",0);o.put("reroutes",0);
            o.put("points",new JSONArray());
            o.put("plannedPoints",new JSONArray());
            prefs(c).edit().putString(KEY_ACTIVE,o.toString()).apply();
        }catch(Exception ignored){}
        return id;
    }

    public static synchronized void savePlannedRoute(Context c, List<GeoPoint> route) {
        String raw=prefs(c).getString(KEY_ACTIVE,"");
        if(raw.isEmpty()||route==null)return;
        try{
            JSONObject o=new JSONObject(raw);
            JSONArray arr=new JSONArray();
            int stride=Math.max(1,route.size()/500);
            for(int i=0;i<route.size();i+=stride){
                GeoPoint p=route.get(i);
                JSONArray pt=new JSONArray();
                pt.put(p.getLatitude());pt.put(p.getLongitude());arr.put(pt);
            }
            if(!route.isEmpty()){
                GeoPoint p=route.get(route.size()-1);
                JSONArray pt=new JSONArray();
                pt.put(p.getLatitude());pt.put(p.getLongitude());arr.put(pt);
            }
            o.put("plannedPoints",arr);
            prefs(c).edit().putString(KEY_ACTIVE,o.toString()).apply();
        }catch(Exception ignored){}
    }

    public static synchronized void appendTrack(Context c,double lat,double lon,float speed){
        String raw=prefs(c).getString(KEY_ACTIVE,"");
        if(raw.isEmpty())return;
        try{
            JSONObject o=new JSONObject(raw); JSONArray pts=o.optJSONArray("points");
            if(pts==null)pts=new JSONArray();
            if(pts.length()>1800) return;
            if(pts.length()>0){
                JSONArray prev=pts.getJSONArray(pts.length()-1);
                double d=OnlineServices.distanceMeters(prev.getDouble(0),prev.getDouble(1),lat,lon);
                if(d<4) return;
                o.put("actualDistanceM",o.optDouble("actualDistanceM",0)+d);
            }
            JSONArray p=new JSONArray();p.put(lat);p.put(lon);p.put(System.currentTimeMillis());p.put(speed);
            pts.put(p);o.put("points",pts);
            prefs(c).edit().putString(KEY_ACTIVE,o.toString()).apply();
        }catch(Exception ignored){}
    }

    public static synchronized void markWarning(Context c){
        mutateActive(c,"warnings");
    }

    public static synchronized void markReroute(Context c){
        mutateActive(c,"reroutes");
    }

    private static void mutateActive(Context c,String key){
        String raw=prefs(c).getString(KEY_ACTIVE,""); if(raw.isEmpty())return;
        try{
            JSONObject o=new JSONObject(raw);o.put(key,o.optInt(key,0)+1);
            prefs(c).edit().putString(KEY_ACTIVE,o.toString()).apply();
        }catch(Exception ignored){}
    }

    public static synchronized void finishTrip(Context c){
        String raw=prefs(c).getString(KEY_ACTIVE,""); if(raw.isEmpty())return;
        try{
            JSONObject o=new JSONObject(raw);o.put("endedAt",System.currentTimeMillis());
            JSONArray trips=getArray(c,KEY_TRIPS);trips.put(o);trimFront(trips,40);
            SharedPreferences.Editor e=prefs(c).edit();
            e.putString(KEY_TRIPS,trips.toString());e.remove(KEY_ACTIVE);e.apply();
        }catch(Exception ignored){}
    }

    public static synchronized List<Trip> trips(Context c){
        List<Trip> out=new ArrayList<>();JSONArray arr=getArray(c,KEY_TRIPS);
        for(int i=arr.length()-1;i>=0;i--){
            JSONObject o=arr.optJSONObject(i);if(o==null)continue;
            Trip t=new Trip();
            t.id=o.optString("id");t.destinationLabel=o.optString("destinationLabel");
            t.startedAt=o.optLong("startedAt");t.endedAt=o.optLong("endedAt");
            t.plannedDistanceM=o.optDouble("plannedDistanceM");t.actualDistanceM=o.optDouble("actualDistanceM");
            t.warnings=o.optInt("warnings");t.reroutes=o.optInt("reroutes");
            t.points=o.optJSONArray("points"); if(t.points==null)t.points=new JSONArray();
            t.plannedPoints=o.optJSONArray("plannedPoints"); if(t.plannedPoints==null)t.plannedPoints=new JSONArray();
            out.add(t);
        }
        return out;
    }

    public static String summary(Context c){
        List<Trip> trips=trips(c); List<Report> reports=reports(c);
        double km=0;int reroutes=0,warnings=0;
        for(Trip t:trips){km+=t.actualDistanceM/1000.0;reroutes+=t.reroutes;warnings+=t.warnings;}
        return String.format(new Locale("nl","NL"),"%d ritten • %.1f km • %d herrouteringen • %d waarschuwingen • %d chauffeursmeldingen",
                trips.size(),km,reroutes,warnings,reports.size());
    }

    private static SharedPreferences prefs(Context c){return c.getSharedPreferences(PREFS,Context.MODE_PRIVATE);}
    private static JSONArray getArray(Context c,String key){
        try{return new JSONArray(prefs(c).getString(key,"[]"));}catch(Exception e){return new JSONArray();}
    }
    private static void putArray(Context c,String key,JSONArray arr){prefs(c).edit().putString(key,arr.toString()).apply();}
    private static void trimFront(JSONArray arr,int max){
        while(arr.length()>max){
            JSONArray copy=new JSONArray();
            for(int i=1;i<arr.length();i++) copy.put(arr.opt(i));
            for(int i=arr.length()-1;i>=0;i--) arr.remove(i);
            for(int i=0;i<copy.length();i++) arr.put(copy.opt(i));
        }
    }
}
