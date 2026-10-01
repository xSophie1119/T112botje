package nl.routepilot.prototype;

import android.content.Context;

import org.json.JSONObject;

import java.util.List;

public final class PortalTripSyncService {
    private PortalTripSyncService(){}

    public static void uploadLatestAsync(Context context){
        final Context app=context.getApplicationContext();
        String base=PortalCorrectionStore.portalUrl(app);
        if(base==null||base.trim().isEmpty())return;

        new Thread(() -> {
            try{
                List<RoutePilotStore.Trip> trips=RoutePilotStore.trips(app);
                if(trips.isEmpty())return;
                RoutePilotStore.Trip t=trips.get(0);

                JSONObject o=new JSONObject();
                o.put("trip_id",t.id);
                o.put("destination",t.destinationLabel);
                o.put("started_at",t.startedAt);
                o.put("ended_at",t.endedAt);
                o.put("planned_distance_m",t.plannedDistanceM);
                o.put("actual_distance_m",t.actualDistanceM);
                o.put("warnings",t.warnings);
                o.put("reroutes",t.reroutes);
                o.put("planned_points",t.plannedPoints);
                o.put("actual_points",t.points);

                String url=base.trim();
                while(url.endsWith("/"))url=url.substring(0,url.length()-1);
                String token=PortalCorrectionStore.portalToken(app);
                HttpClient.postJson(url+"/api/driver-trips",o.toString(),7000,
                        "X-RoutePilot-Token",token);
            }catch(Exception ignored){}
        }).start();
    }
}
