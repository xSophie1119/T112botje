package nl.routepilot.prototype;

import android.content.Context;

import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class RouteAnalysis {
    private RouteAnalysis(){}

    public static final class Result {
        public int score;
        public String label;
        public final List<String> reasons = new ArrayList<>();
        public boolean destinationOnRight;
        public double approachBearing;
        public int uTurns;
        public int narrowSignals;
        public int learnedPenalty;

        public String summary(){
            StringBuilder b=new StringBuilder();
            b.append(score).append("/100 • ").append(label);
            for(String r:reasons) b.append("\n• ").append(r);
            return b.toString();
        }
    }

    public static Result analyze(Context c, OnlineServices.RouteResult route,
                                 OnlineServices.SearchResult destination) {
        Result r=new Result();
        int score=100;

        int closures=route.liveClosureCount();
        int critical=route.criticalCount();
        int caution=route.cautionCount();
        r.uTurns=countUTurns(route);
        r.narrowSignals=countType(route,"SMALLE WEG");
        r.learnedPenalty=RoutePilotStore.learnedPenalty(c,route.points);

        r.approachBearing=approachBearing(route.points);
        r.destinationOnRight=isDestinationOnRight(route.points,destination.lat,destination.lon);

        if(closures>0){score-=Math.min(80,closures*40);r.reasons.add(closures+" actuele afsluiting(en)");}
        if(critical>0){score-=Math.min(70,critical*24);r.reasons.add(critical+" kritieke voertuigbeperking(en)");}
        if(caution>0){score-=Math.min(30,caution*7);r.reasons.add(caution+" voertuig-aandachtspunt(en)");}
        if(r.narrowSignals>0){score-=Math.min(24,r.narrowSignals*5);r.reasons.add(r.narrowSignals+" smalle/krappe weg-signaal(en)");}
        if(r.uTurns>0){score-=Math.min(25,r.uTurns*12);r.reasons.add(r.uTurns+" keerbeweging(en) in route");}
        if(r.learnedPenalty>0){score-=r.learnedPenalty;r.reasons.add("route raakt eerder vermeden punten");}
        if(!r.destinationOnRight){score-=12;r.reasons.add("woning/ingang lijkt links bij aankomst; rechterdeurvoorkeur niet gehaald");}
        else r.reasons.add("aankomstzijde past bij rechterdeurvoorkeur");

        RoutePilotStore.LocationProfile profile=RoutePilotStore.findProfile(c,destination.lat,destination.lon);
        if(profile!=null && !Double.isNaN(profile.preferredArrivalBearing)){
            double diff=angleDiff(r.approachBearing,profile.preferredArrivalBearing);
            if(diff>70){score-=10;r.reasons.add("wijkt af van opgeslagen voorkeursaanrijrichting");}
            else r.reasons.add("opgeslagen aanrijrichting gevolgd");
        }

        score=Math.max(0,Math.min(100,score));
        r.score=score;
        r.label=score>=82?"GESCHIKT":score>=58?"AANDACHT":"AFRADEN";
        return r;
    }

    private static int countType(OnlineServices.RouteResult route,String type){
        int n=0;
        for(OnlineServices.Restriction x:route.restrictions) if(type.equals(x.type))n++;
        return n;
    }

    private static int countUTurns(OnlineServices.RouteResult route){
        int n=0;
        for(OnlineServices.NavStep s:route.steps){
            String t=(s.maneuverType+" "+s.modifier+" "+s.instruction).toLowerCase(Locale.ROOT);
            if(t.contains("uturn")||t.contains("keer om"))n++;
        }
        return n;
    }

    public static double approachBearing(List<GeoPoint> points){
        if(points==null||points.size()<2)return 0;
        int end=points.size()-1;
        GeoPoint b=points.get(end);
        int a=end-1;
        while(a>0 && OnlineServices.distanceMeters(
                points.get(a).getLatitude(),points.get(a).getLongitude(),
                b.getLatitude(),b.getLongitude())<25) a--;
        GeoPoint p=points.get(a);
        return bearing(p.getLatitude(),p.getLongitude(),b.getLatitude(),b.getLongitude());
    }

    public static boolean isDestinationOnRight(List<GeoPoint> points,double destLat,double destLon){
        if(points==null||points.size()<2)return true;
        GeoPoint road=points.get(points.size()-1);
        int a=points.size()-2;
        while(a>0 && OnlineServices.distanceMeters(
                points.get(a).getLatitude(),points.get(a).getLongitude(),
                road.getLatitude(),road.getLongitude())<20) a--;
        GeoPoint prev=points.get(a);

        double lat0=Math.toRadians(road.getLatitude());
        double dxRoute=(road.getLongitude()-prev.getLongitude())*Math.cos(lat0);
        double dyRoute=(road.getLatitude()-prev.getLatitude());
        double dxDest=(destLon-road.getLongitude())*Math.cos(lat0);
        double dyDest=(destLat-road.getLatitude());
        double cross=dxRoute*dyDest-dyRoute*dxDest;

        if(Math.abs(cross)<1e-8) return true;
        return cross<0;
    }

    private static double bearing(double lat1,double lon1,double lat2,double lon2){
        double y=Math.sin(Math.toRadians(lon2-lon1))*Math.cos(Math.toRadians(lat2));
        double x=Math.cos(Math.toRadians(lat1))*Math.sin(Math.toRadians(lat2))
                -Math.sin(Math.toRadians(lat1))*Math.cos(Math.toRadians(lat2))
                *Math.cos(Math.toRadians(lon2-lon1));
        return (Math.toDegrees(Math.atan2(y,x))+360)%360;
    }

    private static double angleDiff(double a,double b){
        double d=Math.abs(a-b)%360; return d>180?360-d:d;
    }
}
