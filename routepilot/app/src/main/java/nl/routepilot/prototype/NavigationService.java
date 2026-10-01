package nl.routepilot.prototype;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.speech.tts.TextToSpeech;
import android.os.IBinder;
import android.os.Handler;
import android.os.Looper;

import org.osmdroid.util.GeoPoint;

import java.util.List;
import java.util.Locale;

public class NavigationService extends Service implements LocationListener, TextToSpeech.OnInitListener {
    private static final String CHANNEL="routepilot_navigation";
    private static final int NOTIFICATION_ID=2201;
    private LocationManager lm;
    private TextToSpeech tts;
    private final Handler handler=new Handler(Looper.getMainLooper());
    private long lastBackgroundRerouteMs=0L;
    private volatile long providerEtaMs=0L;
    private volatile long lastProviderEtaRefreshMs=0L;
    private int backgroundStepIndex=0;
    private final NavigationMapMatcher.State mapMatchState=new NavigationMapMatcher.State();
    private final ArrivalDetector.State arrivalDetectorState=new ArrivalDetector.State();

    @Override public void onCreate(){
        super.onCreate();
        createChannel();
        tts=new TextToSpeech(this,this);
        startForeground(NOTIFICATION_ID, buildNotification(RoutePilotState.get(this)));
        lm=(LocationManager)getSystemService(LOCATION_SERVICE);
        startGps();
        handler.post(notificationTicker);
    }

    @Override public int onStartCommand(Intent intent,int flags,int startId){
        startGps();
        startForeground(NOTIFICATION_ID, buildNotification(RoutePilotState.get(this)));
        return START_STICKY;
    }

    private void startGps(){
        if(lm==null) return;
        if(checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED) return;
        try{
            lm.requestLocationUpdates(LocationManager.GPS_PROVIDER,1500,3f,this);
            lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER,4000,15f,this);
        }catch(Exception ignored){}
    }

    @Override public void onLocationChanged(Location location){
        RoutePilotState.updatePosition(this,location.getLatitude(),location.getLongitude());
        RoutePilotStore.appendTrack(this,location.getLatitude(),location.getLongitude(),
                location.hasSpeed()?location.getSpeed():0f);

        RoutePilotState.Snapshot s=RoutePilotState.get(this);
        if(!RoutePilotState.isActivityForeground(this) && s.active){
            updateBackgroundNavigation(location,s);
            s=RoutePilotState.get(this);
        }

        NotificationManager nm=(NotificationManager)getSystemService(NOTIFICATION_SERVICE);
        if(nm!=null) nm.notify(NOTIFICATION_ID,buildNotification(s));
        WmoSessionManager.Snapshot wmo=WmoSessionManager.get(this);
        if(!s.active && wmo.phase!=WmoSessionManager.Phase.WAITING_PICKUP) stopSelf();
    }

    private void updateBackgroundNavigation(Location location, RoutePilotState.Snapshot s){
        List<GeoPoint> route=RoutePilotState.loadRoute(this);
        if(route.size()<2)return;

        NavigationMapMatcher.Match matched=
                NavigationMapMatcher.match(location,route,mapMatchState);
        int routeIndex=matched.index>=0?matched.index:
                OnlineServices.closestRoutePointIndex(
                        location.getLatitude(),location.getLongitude(),route);
        double remaining=OnlineServices.remainingRouteDistanceMeters(Math.max(0,routeIndex),route);
        double offRoute=matched.index>=0?matched.distanceM:
                OnlineServices.distanceFromRouteMeters(
                        location.getLatitude(),location.getLongitude(),route);

        double remainingSeconds=s.planDistanceM>1.0
                ? s.planDurationS*(remaining/s.planDistanceM):0.0;
        long nowForEta=System.currentTimeMillis();
        long eta=nowForEta+(long)(remainingSeconds*1000.0);
        if(providerEtaMs>nowForEta
                && nowForEta-lastProviderEtaRefreshMs<180_000L){
            eta=providerEtaMs;
        }
        if((s.destLat!=0.0||s.destLon!=0.0)
                && nowForEta-lastProviderEtaRefreshMs>150_000L){
            refreshProviderEtaAsync(location,s);
        }

        List<OnlineServices.NavStep> steps=RoutePilotState.loadSteps(this);
        String instruction=s.instruction==null||s.instruction.isEmpty()?"Volg de route":s.instruction;
        double stepDistance=0.0;
        if(!steps.isEmpty()){
            backgroundStepIndex=Math.max(0,Math.min(backgroundStepIndex,steps.size()-1));
            OnlineServices.NavStep step=steps.get(backgroundStepIndex);
            double d=OnlineServices.distanceMeters(
                    location.getLatitude(),location.getLongitude(),step.lat,step.lon);
            while(d<32.0 && backgroundStepIndex<steps.size()-1){
                backgroundStepIndex++;
                step=steps.get(backgroundStepIndex);
                d=OnlineServices.distanceMeters(
                        location.getLatitude(),location.getLongitude(),step.lat,step.lon);
            }
            instruction=step.instruction;
            stepDistance=d;
        }

        RoutePilotState.update(this,true,instruction,s.warning,
                stepDistance,remaining,eta,s.speedLimit,s.safetyScore,s.destination);

        if(s.destLat!=0.0 || s.destLon!=0.0){
            double toDestination=OnlineServices.distanceMeters(
                    location.getLatitude(),location.getLongitude(),s.destLat,s.destLon);
            double speedKmh=location.hasSpeed()?Math.max(0.0,location.getSpeed()*3.6):0.0;
            double accuracy=location.hasAccuracy()?location.getAccuracy():35.0;
            if(ArrivalDetector.update(arrivalDetectorState,System.currentTimeMillis(),
                    toDestination,remaining,offRoute,speedKmh,accuracy)){
                handleBackgroundArrival();
                return;
            }
        }

        if(offRoute>120.0
                && System.currentTimeMillis()-lastBackgroundRerouteMs>25_000L){
            lastBackgroundRerouteMs=System.currentTimeMillis();
            RoutePilotStore.markReroute(this);
            rerouteInBackground(location,s);
        }
    }

    private void refreshProviderEtaAsync(Location location, RoutePilotState.Snapshot s){
        RoutingProviderSettings settings=RoutingProviderSettings.load(this);
        if(!settings.useHere())return;
        final double lat=location.getLatitude(),lon=location.getLongitude();
        final double dLat=s.destLat,dLon=s.destLon;
        final String label=s.destination;
        lastProviderEtaRefreshMs=System.currentTimeMillis();
        new Thread(() -> {
            try{
                OnlineServices.SearchResult destination=
                        new OnlineServices.SearchResult(dLat,dLon,label);
                double seconds=HereRoutingService.estimateDurationSeconds(
                        NavigationService.this,lat,lon,destination,
                        VehicleProfile.load(NavigationService.this),settings);
                providerEtaMs=System.currentTimeMillis()+(long)(seconds*1000.0);
            }catch(Exception ignored){
                providerEtaMs=0L;
            }
        }).start();
    }

    private void rerouteInBackground(Location location, RoutePilotState.Snapshot s){
        if(s.destLat==0.0 && s.destLon==0.0)return;
        new Thread(() -> {
            try{
                OnlineServices.SearchResult destination=new OnlineServices.SearchResult(
                        s.destLat,s.destLon,s.destination);
                RouteCoordinator.Prepared p=RouteCoordinator.prepare(
                        NavigationService.this,location,destination,
                        VehicleProfile.load(NavigationService.this));
                RoutePilotState.saveRoute(NavigationService.this,p.route);
                RoutePilotState.savePlan(NavigationService.this,p.route,destination);
                RoutePilotStore.savePlannedRoute(NavigationService.this,p.route.points);
                backgroundStepIndex=0;
                providerEtaMs=0L;
                lastProviderEtaRefreshMs=0L;
                mapMatchState.reset();
                arrivalDetectorState.reset();
                RoutePilotState.update(NavigationService.this,true,
                        "Nieuwe route geladen","",
                        0,p.route.distanceMeters,
                        System.currentTimeMillis()+(long)(p.route.durationSeconds*1000.0),
                        -1,p.analysis.score,s.destination);
                RoutePilotState.updateContext(NavigationService.this,
                        "Achtergrondroute opnieuw berekend",
                        p.arrival==null?"":p.arrival.summary(),
                        p.confidence==null?"":p.confidence.summary());
                speak("Je bent van de route afgeweken. Nieuwe RoutePilot route geladen.");
            }catch(Exception ignored){}
        }).start();
    }

    private void handleBackgroundArrival(){
        WmoSessionManager.Snapshot wmo=WmoSessionManager.get(this);
        if(wmo.phase==WmoSessionManager.Phase.TO_PICKUP
                || wmo.phase==WmoSessionManager.Phase.IDLE){
            WmoSessionManager.arrivePickup(this);
            RoutePilotState.update(this,false,"Aangekomen bij cliënt","",
                    0,0,0,-1,0,"");
            speak("Aangekomen bij de cliënt. De wachttijd van drie minuten is gestart.");
        }else if(wmo.phase==WmoSessionManager.Phase.TO_DROPOFF
                || wmo.phase==WmoSessionManager.Phase.PASSENGER_ONBOARD){
            WmoSessionManager.arriveDropoff(this);
            RoutePilotState.update(this,false,"Brengbestemming bereikt","",
                    0,0,0,-1,0,"");
            speak("Brengbestemming bereikt.");
        }
    }

    private void speak(String text){
        if(tts!=null && text!=null && !text.trim().isEmpty())
            tts.speak(text,TextToSpeech.QUEUE_FLUSH,null,"routepilot-v3-service");
    }

    private Notification buildNotification(RoutePilotState.Snapshot s){
        Intent open=new Intent(this,MainActivity.class);
        PendingIntent pi=PendingIntent.getActivity(this,0,open,
                PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
        WmoSessionManager.Snapshot wmo=WmoSessionManager.get(this);
        String title;
        String text;
        if(wmo.phase==WmoSessionManager.Phase.WAITING_PICKUP){
            long left=wmo.waitRemainingMs();
            title=left>0?"WMO • wachten op cliënt":"WMO • LOOS MOGELIJK";
            text=left>0
                    ?"Nog "+WmoSessionManager.formatWait(left)+" van 3:00 wachttijd"
                    :"3:00 verstreken • registreer loos/no-show in RoutePilot";
        }else{
            title=s.active && !s.instruction.isEmpty()?s.instruction:"RoutePilot navigatie";
            text=s.active
                    ? String.format(new Locale("nl","NL"),"%.1f km resterend%s",
                    s.remainingM/1000.0,s.speedLimit>0?" • "+s.speedLimit+" km/u":"")
                    :"Navigatie wordt afgerond";
        }
        Notification.Builder b=Build.VERSION.SDK_INT>=26
                ?new Notification.Builder(this,CHANNEL):new Notification.Builder(this);
        return b.setContentTitle(title).setContentText(text)
                .setSmallIcon(android.R.drawable.ic_dialog_map)
                .setContentIntent(pi).setOngoing(s.active).build();
    }

    private void createChannel(){
        if(Build.VERSION.SDK_INT>=26){
            NotificationChannel c=new NotificationChannel(CHANNEL,
                    "RoutePilot navigatie",NotificationManager.IMPORTANCE_LOW);
            c.setDescription("Achtergrondnavigatie en ritregistratie");
            NotificationManager nm=(NotificationManager)getSystemService(NOTIFICATION_SERVICE);
            if(nm!=null)nm.createNotificationChannel(c);
        }
    }

    private final Runnable notificationTicker=new Runnable(){
        @Override public void run(){
            try{
                RoutePilotState.Snapshot s=RoutePilotState.get(NavigationService.this);
                WmoSessionManager.Snapshot wmo=WmoSessionManager.get(NavigationService.this);
                RoutePilotState.updateWmo(NavigationService.this,
                        wmo.phaseLabel(),wmo.waitRemainingMs(),
                        wmo.phase==WmoSessionManager.Phase.WAITING_PICKUP
                                && wmo.waitRemainingMs()<=0);
                NotificationManager nm=(NotificationManager)getSystemService(NOTIFICATION_SERVICE);
                if(nm!=null)nm.notify(NOTIFICATION_ID,buildNotification(s));
                if(!s.active && wmo.phase!=WmoSessionManager.Phase.WAITING_PICKUP){
                    stopSelf();
                    return;
                }
            }catch(Exception ignored){}
            handler.postDelayed(this,1000L);
        }
    };

    @Override public void onInit(int status){
        if(status==TextToSpeech.SUCCESS && tts!=null){
            tts.setLanguage(new Locale("nl","NL"));
            tts.setSpeechRate(0.96f);
        }
    }

    @Override public void onDestroy(){
        handler.removeCallbacks(notificationTicker);
        try{if(lm!=null)lm.removeUpdates(this);}catch(Exception ignored){}
        if(tts!=null){tts.stop();tts.shutdown();}
        super.onDestroy();
    }

    @Override public IBinder onBind(Intent intent){return null;}
}
