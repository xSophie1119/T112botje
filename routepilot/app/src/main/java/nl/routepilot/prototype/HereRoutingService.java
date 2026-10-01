package nl.routepilot.prototype;

import android.content.Context;

import org.json.JSONArray;
import org.json.JSONObject;
import org.osmdroid.util.GeoPoint;

import java.net.URLEncoder;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class HereRoutingService {
    private static final String BASE="https://router.hereapi.com/v8/routes";
    private HereRoutingService(){}

    public static List<OnlineServices.RouteResult> routeCandidates(
            Context context,double fromLat,double fromLon,
            OnlineServices.SearchResult destination,VehicleProfile vehicle,
            RoutingProviderSettings settings)throws Exception{
        if(settings==null||!settings.hasHereKey())
            throw new IllegalStateException("HERE API-key ontbreekt.");

        String dest=String.format(Locale.US,"%.6f,%.6f",destination.lat,destination.lon);
        double[] hint=sideHint(context,destination);
        if(hint!=null){
            dest+=String.format(Locale.US,
                    ";sideOfStreetHint=%.6f,%.6f;matchSideOfStreet=always",
                    hint[0],hint[1]);
        }

        StringBuilder url=new StringBuilder(BASE).append("?")
                .append("origin=").append(enc(String.format(Locale.US,"%.6f,%.6f",fromLat,fromLon)))
                .append("&destination=").append(enc(dest))
                .append("&transportMode=car")
                .append("&routingMode=fast")
                .append("&departureTime=now")
                .append("&alternatives=2")
                .append("&lang=nl-NL")
                .append("&units=metric")
                .append("&return=polyline,summary,actions,instructions")
                .append("&avoid%5Bfeatures%5D=uTurns")
                .append("&vehicle%5Bheight%5D=").append((int)Math.round(vehicle.heightM*100.0))
                .append("&vehicle%5Bwidth%5D=").append((int)Math.round(vehicle.widthM*100.0))
                .append("&vehicle%5Blength%5D=").append((int)Math.round(vehicle.lengthM*100.0))
                .append("&vehicle%5BcurrentWeight%5D=").append((int)Math.round(vehicle.maxWeightT*1000.0))
                .append("&vehicle%5BgrossWeight%5D=").append((int)Math.round(vehicle.maxWeightT*1000.0))
                .append("&apiKey=").append(enc(settings.hereApiKey.trim()));

        JSONObject root=new JSONObject(HttpClient.get(url.toString(),16000));
        JSONArray routes=root.optJSONArray("routes");
        if(routes==null||routes.length()==0)throw new IllegalArgumentException("HERE gaf geen route.");

        List<OnlineServices.RouteResult> out=new ArrayList<>();
        for(int i=0;i<routes.length()&&i<3;i++){
            JSONObject rr=routes.optJSONObject(i);if(rr==null)continue;
            JSONArray sections=rr.optJSONArray("sections");if(sections==null)continue;

            List<GeoPoint> points=new ArrayList<>();
            List<OnlineServices.NavStep> steps=new ArrayList<>();
            List<String> notices=new ArrayList<>();
            double distance=0.0,duration=0.0;

            for(int s=0;s<sections.length();s++){
                JSONObject section=sections.optJSONObject(s);if(section==null)continue;
                String encoded=section.optString("polyline","");
                List<GeoPoint> sectionPoints=encoded.isEmpty()
                        ?new ArrayList<>():FlexiblePolylineDecoder.decode(encoded);
                int baseOffset=points.size();
                appendGeometry(points,sectionPoints);

                JSONObject summary=section.optJSONObject("summary");
                if(summary!=null){
                    distance+=summary.optDouble("length",0.0);
                    duration+=summary.optDouble("duration",0.0);
                }

                JSONArray actions=section.optJSONArray("actions");
                if(actions!=null){
                    for(int a=0;a<actions.length();a++){
                        JSONObject action=actions.optJSONObject(a);if(action==null)continue;
                        int offset=action.optInt("offset",0);
                        int idx=Math.max(0,Math.min(points.size()-1,baseOffset+offset));
                        GeoPoint p=points.isEmpty()?new GeoPoint(destination.lat,destination.lon):points.get(idx);
                        String act=action.optString("action","");
                        String dir=action.optString("direction","");
                        String instruction=action.optString("instruction",fallbackInstruction(act,dir));
                        steps.add(new OnlineServices.NavStep(
                                p.getLatitude(),p.getLongitude(),
                                action.optDouble("length",0.0),instruction,"",act,dir));
                    }
                }

                JSONArray sectionNotices=section.optJSONArray("notices");
                collectNotices(sectionNotices,notices);
            }
            collectNotices(rr.optJSONArray("notices"),notices);
            if(points.size()<2)continue;

            OnlineServices.RouteResult result=new OnlineServices.RouteResult(points,distance,duration,steps);
            result.providerName="HERE";
            result.providerVehicleAware=true;
            result.providerNoticeTexts.addAll(notices);
            for(String n:notices){
                String lower=n.toLowerCase(Locale.ROOT);
                if(lower.contains("violatedvehiclerestriction")
                        ||lower.contains("violated vehicle restriction")
                        ||lower.contains("critical")){
                    result.providerCriticalNotices++;
                }
            }
            result.selectionNote="HERE voertuigbewuste basisroute";
            out.add(result);
        }
        if(out.isEmpty())throw new IllegalArgumentException("HERE-route kon niet worden gelezen.");
        return out;
    }

    public static double estimateDurationSeconds(
            Context context,double fromLat,double fromLon,
            OnlineServices.SearchResult destination,VehicleProfile vehicle,
            RoutingProviderSettings settings)throws Exception{
        if(settings==null||!settings.hasHereKey())
            throw new IllegalStateException("HERE API-key ontbreekt.");

        String dest=String.format(Locale.US,"%.6f,%.6f",destination.lat,destination.lon);
        double[] hint=sideHint(context,destination);
        if(hint!=null){
            dest+=String.format(Locale.US,
                    ";sideOfStreetHint=%.6f,%.6f;matchSideOfStreet=always",
                    hint[0],hint[1]);
        }

        StringBuilder url=new StringBuilder(BASE).append("?")
                .append("origin=").append(enc(String.format(Locale.US,"%.6f,%.6f",fromLat,fromLon)))
                .append("&destination=").append(enc(dest))
                .append("&transportMode=car")
                .append("&routingMode=fast")
                .append("&departureTime=now")
                .append("&alternatives=0")
                .append("&return=summary")
                .append("&avoid%5Bfeatures%5D=uTurns")
                .append("&vehicle%5Bheight%5D=").append((int)Math.round(vehicle.heightM*100.0))
                .append("&vehicle%5Bwidth%5D=").append((int)Math.round(vehicle.widthM*100.0))
                .append("&vehicle%5Blength%5D=").append((int)Math.round(vehicle.lengthM*100.0))
                .append("&vehicle%5BcurrentWeight%5D=").append((int)Math.round(vehicle.maxWeightT*1000.0))
                .append("&vehicle%5BgrossWeight%5D=").append((int)Math.round(vehicle.maxWeightT*1000.0))
                .append("&apiKey=").append(enc(settings.hereApiKey.trim()));

        JSONObject root=new JSONObject(HttpClient.get(url.toString(),9000));
        JSONArray routes=root.optJSONArray("routes");
        if(routes==null||routes.length()==0)throw new IllegalArgumentException("Geen HERE ETA.");
        JSONArray sections=routes.getJSONObject(0).optJSONArray("sections");
        if(sections==null)throw new IllegalArgumentException("Geen HERE ETA-secties.");
        double duration=0.0;
        for(int i=0;i<sections.length();i++){
            JSONObject s=sections.optJSONObject(i);
            JSONObject summary=s==null?null:s.optJSONObject("summary");
            if(summary!=null)duration+=summary.optDouble("duration",0.0);
        }
        if(duration<=0)throw new IllegalArgumentException("Ongeldige HERE ETA.");
        return duration;
    }

    private static void appendGeometry(List<GeoPoint> all,List<GeoPoint> section){
        for(GeoPoint p:section){
            if(!all.isEmpty()){
                GeoPoint last=all.get(all.size()-1);
                if(OnlineServices.distanceMeters(last.getLatitude(),last.getLongitude(),
                        p.getLatitude(),p.getLongitude())<0.4)continue;
            }
            all.add(p);
        }
    }

    private static void collectNotices(JSONArray arr,List<String> out){
        if(arr==null)return;
        for(int i=0;i<arr.length();i++){
            JSONObject n=arr.optJSONObject(i);if(n==null)continue;
            String code=n.optString("code","");
            String title=n.optString("title","");
            String severity=n.optString("severity","");
            String text=(severity+" "+code+" "+title).trim();
            if(!text.isEmpty()&&!out.contains(text))out.add(text);
        }
    }

    private static double[] sideHint(Context c,OnlineServices.SearchResult d){
        PortalCorrectionStore.Correction portal=
                PortalCorrectionStore.nearestOfType(c,"entrance",d.lat,d.lon);
        if(portal!=null){
            double m=OnlineServices.distanceMeters(d.lat,d.lon,portal.lat,portal.lon);
            if(m>=2&&m<=100)return new double[]{portal.lat,portal.lon};
        }
        RoutePilotStore.LocationProfile p=RoutePilotStore.findProfile(c,d.lat,d.lon);
        if(p!=null&&p.hasEntrancePoint){
            double m=OnlineServices.distanceMeters(d.lat,d.lon,p.entranceLat,p.entranceLon);
            if(m>=2&&m<=100)return new double[]{p.entranceLat,p.entranceLon};
        }
        return null;
    }

    private static String fallbackInstruction(String action,String direction){
        if("depart".equals(action))return "Vertrek";
        if("arrive".equals(action))return "Bestemming bereikt";
        if("turn".equals(action)){
            if(direction.contains("left"))return "Sla linksaf";
            if(direction.contains("right"))return "Sla rechtsaf";
        }
        return "Volg de route";
    }

    private static String enc(String s)throws Exception{
        return URLEncoder.encode(s,"UTF-8").replace("+","%20");
    }
}
