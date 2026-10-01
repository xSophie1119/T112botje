package nl.routepilot.prototype;

import android.util.Xml;

import org.osmdroid.util.GeoPoint;
import org.xmlpull.v1.XmlPullParser;

import java.io.BufferedInputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.zip.GZIPInputStream;

public final class TemporarySpeedService {
    private static final String URL_FEED =
            "https://opendata.ndw.nu/tijdelijke_verkeersmaatregelen_maximum_snelheden.xml.gz";
    private static final long CACHE_MS = 120_000L;

    public static final class Limit {
        public double lat, lon;
        public int kmh;
        public long startMs, endMs;
        public int routeIndex = -1;
    }

    private static final Object LOCK = new Object();
    private static long cachedAt = 0L;
    private static List<Limit> cached = new ArrayList<>();

    private TemporarySpeedService() {}

    public static List<Limit> limitsForRoute(List<GeoPoint> route) throws Exception {
        List<Limit> out = new ArrayList<>();
        if (route == null || route.size() < 2) return out;
        for (Limit source : load()) {
            int idx = OnlineServices.closestRoutePointIndex(source.lat, source.lon, route);
            if (idx < 0) continue;
            GeoPoint p = route.get(idx);
            double d = OnlineServices.distanceMeters(source.lat, source.lon,
                    p.getLatitude(), p.getLongitude());
            if (d > 90) continue;
            Limit l = new Limit();
            l.lat=source.lat;l.lon=source.lon;l.kmh=source.kmh;
            l.startMs=source.startMs;l.endMs=source.endMs;l.routeIndex=idx;
            out.add(l);
            if(out.size()>=40)break;
        }
        return out;
    }

    public static Integer speedAt(int routeIndex, List<Limit> limits) {
        if (limits == null) return null;
        long now = System.currentTimeMillis();
        Integer best = null;
        int bestDelta = Integer.MAX_VALUE;
        for (Limit l : limits) {
            if (l.routeIndex < 0) continue;
            if (l.startMs > 0 && l.startMs > now + 60_000L) continue;
            if (l.endMs > 0 && l.endMs < now - 60_000L) continue;
            int delta = routeIndex - l.routeIndex;
            if (delta >= 0 && delta < bestDelta) {
                best = l.kmh;
                bestDelta = delta;
            }
        }
        return bestDelta <= 80 ? best : null;
    }

    private static List<Limit> load() throws Exception {
        synchronized (LOCK) {
            if (!cached.isEmpty() && System.currentTimeMillis()-cachedAt<CACHE_MS)
                return new ArrayList<>(cached);
        }
        HttpURLConnection con=(HttpURLConnection)new URL(URL_FEED).openConnection();
        con.setConnectTimeout(12_000);con.setReadTimeout(22_000);
        con.setRequestProperty("User-Agent",OnlineServices.USER_AGENT);
        int code=con.getResponseCode();
        if(code<200||code>=300){con.disconnect();throw new IllegalStateException("NDW tijdelijke snelheid HTTP "+code);}
        List<Limit> parsed;
        try(InputStream raw=new BufferedInputStream(con.getInputStream());
            GZIPInputStream gz=new GZIPInputStream(raw)){
            parsed=parse(gz);
        }finally{con.disconnect();}
        synchronized(LOCK){cached=parsed;cachedAt=System.currentTimeMillis();}
        return new ArrayList<>(parsed);
    }

    private static List<Limit> parse(InputStream in)throws Exception{
        XmlPullParser x=Xml.newPullParser();x.setInput(in,"UTF-8");
        List<Limit> out=new ArrayList<>();
        boolean record=false;String tag="";
        double lat=Double.NaN,lon=Double.NaN;int speed=-1;String start="",end="";

        int t=x.getEventType();
        while(t!=XmlPullParser.END_DOCUMENT){
            if(t==XmlPullParser.START_TAG){
                tag=local(x.getName()).toLowerCase(Locale.ROOT);
                if(tag.contains("situationrecord")){
                    record=true;lat=Double.NaN;lon=Double.NaN;speed=-1;start="";end="";
                }
            }else if(t==XmlPullParser.TEXT&&record){
                String v=x.getText()==null?"":x.getText().trim();
                if(!v.isEmpty()){
                    if("latitude".equals(tag))lat=num(v,lat);
                    else if("longitude".equals(tag))lon=num(v,lon);
                    else if("overallstarttime".equals(tag))start=v;
                    else if("overallendtime".equals(tag))end=v;
                    else if(tag.contains("speed")||tag.contains("maximum")){
                        Integer n=integer(v);
                        if(n!=null&&n>=5&&n<=130)speed=n;
                    }
                }
            }else if(t==XmlPullParser.END_TAG){
                String e=local(x.getName()).toLowerCase(Locale.ROOT);
                if(record&&e.contains("situationrecord")){
                    Long s=instant(start),en=instant(end);long now=System.currentTimeMillis();
                    if(!Double.isNaN(lat)&&!Double.isNaN(lon)&&speed>0
                            &&(s==null||s<=now+60_000L)&&(en==null||en>=now-60_000L)){
                        Limit l=new Limit();l.lat=lat;l.lon=lon;l.kmh=speed;
                        l.startMs=s==null?0:s;l.endMs=en==null?0:en;out.add(l);
                    }
                    record=false;
                }
                tag="";
            }
            t=x.next();
        }
        return out;
    }

    private static String local(String s){if(s==null)return"";int i=s.indexOf(':');return i>=0?s.substring(i+1):s;}
    private static Long instant(String s){try{return s==null||s.isEmpty()?null:Instant.parse(s).toEpochMilli();}catch(Exception e){return null;}}
    private static double num(String s,double f){try{return Double.parseDouble(s.replace(',','.'));}catch(Exception e){return f;}}
    private static Integer integer(String s){
        try{
            String n=s.replace(',','.').replaceAll("[^0-9.]","");
            if(n.isEmpty())return null;
            return (int)Math.round(Double.parseDouble(n));
        }catch(Exception e){return null;}
    }
}
