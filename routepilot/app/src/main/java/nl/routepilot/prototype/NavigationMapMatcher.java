package nl.routepilot.prototype;

import android.location.Location;

import org.osmdroid.util.GeoPoint;

import java.util.List;

public final class NavigationMapMatcher {
    public static final class State {
        public int lastIndex=-1;
        public void reset(){lastIndex=-1;}
    }

    public static final class Match {
        public int index=-1;
        public double lat,lon;
        public double distanceM=Double.MAX_VALUE;
        public double confidence=0.0;
    }

    private NavigationMapMatcher(){}

    public static Match match(Location location,List<GeoPoint> route,State state){
        Match best=new Match();
        if(location==null||route==null||route.size()<2)return best;

        int from=0,to=route.size()-2;
        if(state!=null&&state.lastIndex>=0){
            from=Math.max(0,state.lastIndex-45);
            to=Math.min(route.size()-2,state.lastIndex+320);
        }

        double lat=location.getLatitude(),lon=location.getLongitude();
        double heading=location.hasBearing()?location.getBearing():Double.NaN;
        boolean headingUseful=location.hasSpeed()&&location.getSpeed()>2.0f&&!Double.isNaN(heading);
        double bestScore=Double.MAX_VALUE;

        for(int i=from;i<=to;i++){
            GeoPoint a=route.get(i),b=route.get(i+1);
            Projection p=project(lat,lon,a,b);
            double score=p.distanceM;

            if(headingUseful){
                double roadBearing=bearing(a.getLatitude(),a.getLongitude(),
                        b.getLatitude(),b.getLongitude());
                double diff=angleDiff(heading,roadBearing);
                score+=Math.min(55.0,diff/180.0*55.0);
            }

            if(state!=null&&state.lastIndex>=0&&i<state.lastIndex-12){
                score+=(state.lastIndex-i)*1.7;
            }

            if(score<bestScore){
                bestScore=score;
                best.index=i;
                best.lat=p.lat;best.lon=p.lon;
                best.distanceM=p.distanceM;
            }
        }

        if(best.index<0){
            best.index=OnlineServices.closestRoutePointIndex(lat,lon,route);
            GeoPoint p=route.get(Math.max(0,best.index));
            best.lat=p.getLatitude();best.lon=p.getLongitude();
            best.distanceM=OnlineServices.distanceMeters(lat,lon,best.lat,best.lon);
        }

        best.confidence=Math.max(0.0,Math.min(1.0,1.0-best.distanceM/90.0));
        if(state!=null&&best.distanceM<90.0){
            if(state.lastIndex<0||best.index>=state.lastIndex-10)
                state.lastIndex=best.index;
        }
        return best;
    }

    private static final class Projection{
        double lat,lon,distanceM;
    }

    private static Projection project(double lat,double lon,GeoPoint a,GeoPoint b){
        double lat0=Math.toRadians((a.getLatitude()+b.getLatitude()+lat)/3.0);
        double mx=111320.0*Math.cos(lat0);
        double my=110540.0;

        double ax=a.getLongitude()*mx, ay=a.getLatitude()*my;
        double bx=b.getLongitude()*mx, by=b.getLatitude()*my;
        double px=lon*mx, py=lat*my;
        double vx=bx-ax,vy=by-ay;
        double len2=vx*vx+vy*vy;
        double t=len2<0.01?0.0:((px-ax)*vx+(py-ay)*vy)/len2;
        t=Math.max(0.0,Math.min(1.0,t));

        double qx=ax+t*vx,qy=ay+t*vy;
        Projection out=new Projection();
        out.lon=qx/mx;out.lat=qy/my;
        double dx=px-qx,dy=py-qy;
        out.distanceM=Math.sqrt(dx*dx+dy*dy);
        return out;
    }

    private static double bearing(double lat1,double lon1,double lat2,double lon2){
        double y=Math.sin(Math.toRadians(lon2-lon1))*Math.cos(Math.toRadians(lat2));
        double x=Math.cos(Math.toRadians(lat1))*Math.sin(Math.toRadians(lat2))
                -Math.sin(Math.toRadians(lat1))*Math.cos(Math.toRadians(lat2))
                *Math.cos(Math.toRadians(lon2-lon1));
        return (Math.toDegrees(Math.atan2(y,x))+360.0)%360.0;
    }

    private static double angleDiff(double a,double b){
        double d=Math.abs(a-b)%360.0;
        return d>180.0?360.0-d:d;
    }
}
