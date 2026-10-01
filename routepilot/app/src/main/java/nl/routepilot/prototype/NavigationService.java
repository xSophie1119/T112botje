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
import android.os.IBinder;

import java.util.Locale;

public class NavigationService extends Service implements LocationListener {
    private static final String CHANNEL="routepilot_navigation";
    private static final int NOTIFICATION_ID=2201;
    private LocationManager lm;

    @Override public void onCreate(){
        super.onCreate();
        createChannel();
        startForeground(NOTIFICATION_ID, buildNotification(RoutePilotState.get(this)));
        lm=(LocationManager)getSystemService(LOCATION_SERVICE);
        startGps();
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
        NotificationManager nm=(NotificationManager)getSystemService(NOTIFICATION_SERVICE);
        if(nm!=null) nm.notify(NOTIFICATION_ID,buildNotification(s));
        if(!s.active) stopSelf();
    }

    private Notification buildNotification(RoutePilotState.Snapshot s){
        Intent open=new Intent(this,MainActivity.class);
        PendingIntent pi=PendingIntent.getActivity(this,0,open,
                PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
        String title=s.active && !s.instruction.isEmpty()?s.instruction:"RoutePilot navigatie";
        String text=s.active
                ? String.format(Locale.NL,"%.1f km resterend%s",
                s.remainingM/1000.0,s.speedLimit>0?" • "+s.speedLimit+" km/u":"")
                :"Navigatie wordt afgerond";
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

    @Override public void onDestroy(){
        try{if(lm!=null)lm.removeUpdates(this);}catch(Exception ignored){}
        super.onDestroy();
    }

    @Override public IBinder onBind(Intent intent){return null;}
}
