package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URLEncoder;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;
import java.util.Locale;

public final class OfficialSpeedService {

    private static final String WFS =
            "https://geo.rijkswaterstaat.nl/services/ogc/gdr/weggeg/ows";

    private OfficialSpeedService(){}

    public static final class SpeedPoint {
        public final double lat, lon;
        public final int speed;
        SpeedPoint(double lat,double lon,int speed){
            this.lat=lat;this.lon=lon;this.speed=speed;
        }
    }

    public static List<SpeedPoint> loadForRoute(List<GeoPoint> route) throws Exception {
        List<SpeedPoint> out=new ArrayList<>();
        if(route==null||route.size()<2)return out;

        double minLat=90,maxLat=-90,minLon=180,maxLon=-180;
        for(GeoPoint p:route){
            minLat=Math.min(minLat,p.getLatitude());
            maxLat=Math.max(maxLat,p.getLatitude());
            minLon=Math.min(minLon,p.getLongitude());
            maxLon=Math.max(maxLon,p.getLongitude());
        }

        // Avoid requesting an enormous national rectangle for very long debug routes.
        if(maxLat-minLat>0.75 || maxLon-minLon>1.10) return out;

        int hour=Calendar.getInstance().get(Calendar.HOUR_OF_DAY);
        String layer=(hour>=6&&hour<19)
                ?"maximum_snelheden_wegvak_overdag"
                :"maximum_snelheden_wegvak_nacht";

        String bbox=String.format(Locale.US,"%.6f,%.6f,%.6f,%.6f,EPSG:4326",
                minLon-0.003,minLat-0.003,maxLon+0.003,maxLat+0.003);

        String url=WFS
                +"?service=WFS&version=2.0.0&request=GetFeature"
                +"&typeName="+URLEncoder.encode(layer,"UTF-8")
                +"&outputFormat=json&srsName=EPSG:4326"
                +"&count=5000&bbox="+URLEncoder.encode(bbox,"UTF-8");

        JSONObject root=new JSONObject(get(url));
        JSONArray features=root.optJSONArray("features");
        if(features==null)return out;

        for(int i=0;i<features.length();i++){
            JSONObject f=features.optJSONObject(i);
            if(f==null)continue;
            JSONObject props=f.optJSONObject("properties");
            JSONObject geom=f.optJSONObject("geometry");
            if(props==null||geom==null)continue;

            int speed=parseSpeed(props);
            if(speed<=0)continue;

            addGeometryPoints(geom,out,speed);
            if(out.size()>12000)break;
        }
        return out;
    }

    public static Integer speedLimitAt(GeoPoint current,List<SpeedPoint> points){
        if(current==null||points==null||points.isEmpty())return null;
        double min=Double.MAX_VALUE;
        Integer best=null;
        for(SpeedPoint p:points){
            double d=OnlineServices.distanceMeters(
                    current.getLatitude(),current.getLongitude(),p.lat,p.lon);
            if(d<min){
                min=d;best=p.speed;
            }
        }
        return min<=38?best:null;
    }

    private static int parseSpeed(JSONObject p){
        String[] keys={"omschr","OMSCHR","maxshd","MAXSHD","maximumsnelheid","MaximumSnelheid"};
        for(String key:keys){
            String raw=p.optString(key,"").trim();
            if(raw.isEmpty())continue;
            String n=raw.replaceAll("[^0-9]","");
            if(n.isEmpty())continue;
            try{
                int v=Integer.parseInt(n);
                if(v>=5&&v<=130)return v;
            }catch(Exception ignored){}
        }
        return -1;
    }

    private static void addGeometryPoints(JSONObject geom,List<SpeedPoint> out,int speed){
        String type=geom.optString("type","");
        JSONArray coords=geom.optJSONArray("coordinates");
        if(coords==null)return;

        if("LineString".equalsIgnoreCase(type)){
            addLine(coords,out,speed);
        }else if("MultiLineString".equalsIgnoreCase(type)){
            for(int i=0;i<coords.length();i++){
                JSONArray line=coords.optJSONArray(i);
                if(line!=null)addLine(line,out,speed);
            }
        }
    }

    private static void addLine(JSONArray line,List<SpeedPoint> out,int speed){
        int stride=Math.max(1,line.length()/40);
        for(int i=0;i<line.length();i+=stride){
            JSONArray xy=line.optJSONArray(i);
            if(xy==null||xy.length()<2)continue;
            double a=xy.optDouble(0),b=xy.optDouble(1);
            // WFS srsName should return WGS84. Accept both common axis orders defensively.
            double lon=a,lat=b;
            if(Math.abs(a)<=90 && Math.abs(b)>90){lat=a;lon=b;}
            if(lat<50||lat>54||lon<3||lon>8)continue;
            out.add(new SpeedPoint(lat,lon,speed));
        }
    }

    private static String get(String raw) throws Exception{
        HttpURLConnection con=(HttpURLConnection)new URL(raw).openConnection();
        con.setConnectTimeout(12000);con.setReadTimeout(25000);
        con.setRequestProperty("User-Agent",OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept","application/json, application/geo+json");
        int code=con.getResponseCode();
        InputStream stream=code>=200&&code<300?con.getInputStream():con.getErrorStream();
        StringBuilder sb=new StringBuilder();
        if(stream!=null){
            BufferedReader br=new BufferedReader(new InputStreamReader(stream,StandardCharsets.UTF_8));
            String line;while((line=br.readLine())!=null)sb.append(line);
            br.close();
        }
        con.disconnect();
        if(code<200||code>=300)throw new IllegalStateException("WKD snelheid HTTP "+code);
        return sb.toString();
    }
}
