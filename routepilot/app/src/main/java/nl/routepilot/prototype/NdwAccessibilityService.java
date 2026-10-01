package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.time.ZonedDateTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;

public final class NdwAccessibilityService {
    private static final String URL=
            "https://data.ndw.nu/api/rest/static-road-data/accessibility-map/v2/accessibility.geojson";

    public static final class Result {
        public boolean checked=false;
        public final List<OnlineServices.Restriction> restrictions=new ArrayList<>();
        public final List<String> reasons=new ArrayList<>();
    }

    private NdwAccessibilityService(){}

    public static Result validate(OnlineServices.RouteResult route,
                                  OnlineServices.SearchResult destination,
                                  VehicleProfile vehicle)throws Exception{
        Result out=new Result();
        if(route==null||route.points==null||route.points.size()<2)return out;

        double minLat=90,maxLat=-90,minLon=180,maxLon=-180;
        for(GeoPoint p:route.points){
            minLat=Math.min(minLat,p.getLatitude());maxLat=Math.max(maxLat,p.getLatitude());
            minLon=Math.min(minLon,p.getLongitude());maxLon=Math.max(maxLon,p.getLongitude());
        }
        // API is Nederland-only; skip impossible bounding boxes cleanly.
        if(maxLat<50.5||minLat>54.0||maxLon<3.0||minLon>7.5)return out;
        double pad=0.0025;
        minLat-=pad;maxLat+=pad;minLon-=pad;maxLon+=pad;

        GeoPoint from=route.points.get(0);
        JSONObject req=new JSONObject();
        req.put("includeAccessibleRoadSections",false);
        req.put("includeInaccessibleRoadSections",true);
        req.put("effectivelyAccessible",false);

        JSONObject area=new JSONObject();
        area.put("type","boundingBox");
        area.put("minLatitude",minLat);area.put("maxLatitude",maxLat);
        area.put("minLongitude",minLon);area.put("maxLongitude",maxLon);
        req.put("area",area);

        req.put("from",location(from.getLatitude(),from.getLongitude()));
        req.put("destination",location(destination.lat,destination.lon));

        JSONObject v=new JSONObject();
        // Geen bijzondere busrechten aannemen: juridische uitzonderingen blijven RoutePilot-policy.
        v.put("type","car");
        v.put("width",vehicle.widthM);
        v.put("height",vehicle.heightM);
        v.put("weight",vehicle.maxWeightT);
        v.put("length",vehicle.lengthM);
        v.put("hasTrailer",false);
        req.put("vehicle",v);

        ZonedDateTime start=ZonedDateTime.now(ZoneId.of("Europe/Amsterdam"));
        JSONObject window=new JSONObject();
        window.put("start",start.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME));
        window.put("end",start.plusHours(2).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME));
        req.put("visitingWindow",window);

        JSONObject root=new JSONObject(HttpClient.postJson(URL,req.toString(),14000));
        JSONArray features=root.optJSONArray("features");
        if(features==null){out.checked=true;return out;}

        int added=0;
        for(int i=0;i<features.length()&&added<20;i++){
            JSONObject f=features.optJSONObject(i);if(f==null)continue;
            JSONObject props=f.optJSONObject("properties");
            if(props==null||props.optBoolean("accessible",true))continue;
            String type=props.optString("type","");
            JSONObject geom=f.optJSONObject("geometry");
            if("roadSectionSegment".equals(type)&&geom!=null){
                double[] near=nearestGeometryPoint(route.points,geom);
                if(near==null||near[2]>18.0)continue;
                String desc="Officiële NDW Bereikbaarheidskaart markeert dit wegsegment "
                        +"voor het ingestelde voertuig als niet bereikbaar.";
                out.restrictions.add(new OnlineServices.Restriction(
                        near[0],near[1],"NDW BEREIKBAARHEID","onbereikbaar",
                        desc,true,false));
                added++;
            }else if("destination".equals(type)){
                JSONArray rs=props.optJSONArray("reasons");
                collectReasons(rs,out.reasons);
            }
        }
        out.checked=true;
        return out;
    }

    private static JSONObject location(double lat,double lon)throws Exception{
        JSONObject o=new JSONObject();o.put("latitude",lat);o.put("longitude",lon);return o;
    }

    private static double[] nearestGeometryPoint(List<GeoPoint> route,JSONObject geom){
        JSONArray coords=geom.optJSONArray("coordinates");
        if(coords==null)return null;
        String t=geom.optString("type","");
        double best=Double.MAX_VALUE,bLat=0,bLon=0;
        if("LineString".equals(t)){
            for(int i=0;i<coords.length();i++){
                JSONArray p=coords.optJSONArray(i);if(p==null||p.length()<2)continue;
                double lon=p.optDouble(0),lat=p.optDouble(1);
                double d=distanceToRoute(lat,lon,route);
                if(d<best){best=d;bLat=lat;bLon=lon;}
            }
        }else if("Point".equals(t)&&coords.length()>=2){
            bLon=coords.optDouble(0);bLat=coords.optDouble(1);
            best=distanceToRoute(bLat,bLon,route);
        }
        return best==Double.MAX_VALUE?null:new double[]{bLat,bLon,best};
    }

    private static double distanceToRoute(double lat,double lon,List<GeoPoint> route){
        double best=Double.MAX_VALUE;
        int stride=Math.max(1,route.size()/1200);
        for(int i=0;i<route.size();i+=stride){
            GeoPoint p=route.get(i);
            best=Math.min(best,OnlineServices.distanceMeters(
                    lat,lon,p.getLatitude(),p.getLongitude()));
            if(best<3)break;
        }
        return best;
    }

    private static void collectReasons(JSONArray arr,List<String> out){
        if(arr==null)return;
        for(int i=0;i<arr.length();i++){
            Object x=arr.opt(i);
            if(x instanceof JSONArray)collectReasons((JSONArray)x,out);
            else if(x instanceof JSONObject){
                JSONObject o=(JSONObject)x;
                String type=o.optString("type","");
                String condition=o.optString("condition","");
                String unit=o.optString("unitSymbol","");
                String value=o.has("value")?String.valueOf(o.opt("value")):"";
                String s=(type+" "+condition+" "+value+" "+unit).trim();
                if(!s.isEmpty()&&!out.contains(s))out.add(s);
            }
        }
    }
}
