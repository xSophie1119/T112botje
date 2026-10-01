package nl.routepilot.prototype;

import android.content.Intent;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Path;
import android.os.Handler;
import android.os.Looper;
import android.view.Surface;

import androidx.car.app.AppManager;
import androidx.car.app.CarAppService;
import androidx.car.app.CarContext;
import androidx.car.app.Screen;
import androidx.car.app.Session;
import androidx.car.app.SessionInfo;
import androidx.car.app.SurfaceCallback;
import androidx.car.app.SurfaceContainer;
import androidx.car.app.model.Distance;
import androidx.car.app.model.Template;
import androidx.car.app.navigation.model.Maneuver;
import androidx.car.app.navigation.model.NavigationTemplate;
import androidx.car.app.navigation.model.RoutingInfo;
import androidx.car.app.navigation.model.Step;
import androidx.car.app.navigation.model.TravelEstimate;
import androidx.car.app.validation.HostValidator;

import org.osmdroid.util.GeoPoint;

import java.time.Instant;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.util.List;

public class RoutePilotCarAppService extends CarAppService {
    @Override public HostValidator createHostValidator(){
        return HostValidator.ALLOW_ALL_HOSTS_VALIDATOR;
    }

    @Override public Session onCreateSession(SessionInfo sessionInfo){
        return new RoutePilotSession();
    }

    private static final class RoutePilotSession extends Session {
        @Override public Screen onCreateScreen(Intent intent){
            return new RoutePilotCarScreen(getCarContext());
        }
    }

    private static final class RoutePilotCarScreen extends Screen implements SurfaceCallback {
        private final Handler handler=new Handler(Looper.getMainLooper());
        private SurfaceContainer surfaceContainer;

        RoutePilotCarScreen(CarContext context){
            super(context);
            context.getCarService(AppManager.class).setSurfaceCallback(this);
            handler.post(refresh);
        }

        private final Runnable refresh=new Runnable(){
            @Override public void run(){
                invalidate();
                drawMap();
                handler.postDelayed(this,1500);
            }
        };

        @Override public Template onGetTemplate(){
            RoutePilotState.Snapshot s=RoutePilotState.get(getCarContext());
            RoutingInfo info;
            if(!s.active || s.instruction==null || s.instruction.isEmpty()){
                String idleInstruction=s.wmoPhase!=null&&!s.wmoPhase.isEmpty()
                        ?s.wmoPhase:"RoutePilot gereed";
                Step idleStep=new Step.Builder(idleInstruction)
                        .setManeuver(new Maneuver.Builder(Maneuver.TYPE_UNKNOWN).build())
                        .build();
                info=new RoutingInfo.Builder()
                        .setCurrentStep(idleStep,Distance.create(0,Distance.UNIT_METERS))
                        .build();
            }else{
                Step step=new Step.Builder(s.instruction)
                        .setManeuver(new Maneuver.Builder(Maneuver.TYPE_UNKNOWN).build())
                        .build();
                info=new RoutingInfo.Builder()
                        .setCurrentStep(step,Distance.create(Math.max(0,s.stepDistanceM),Distance.UNIT_METERS))
                        .build();
            }

            NavigationTemplate.Builder b=new NavigationTemplate.Builder()
                    .setNavigationInfo(info);

            if(s.active && s.etaMs>0){
                ZonedDateTime eta=ZonedDateTime.ofInstant(
                        Instant.ofEpochMilli(s.etaMs), ZoneId.systemDefault());
                TravelEstimate estimate=new TravelEstimate.Builder(
                        Distance.create(Math.max(0,s.remainingM),Distance.UNIT_METERS),eta)
                        .setRemainingTimeSeconds(Math.max(0,(s.etaMs-System.currentTimeMillis())/1000))
                        .build();
                b.setDestinationTravelEstimate(estimate);
            }
            return b.build();
        }

        @Override public void onSurfaceAvailable(SurfaceContainer container){
            surfaceContainer=container;
            drawMap();
        }

        @Override public void onSurfaceDestroyed(SurfaceContainer container){
            if(surfaceContainer==container) surfaceContainer=null;
            try{container.getSurface().release();}catch(Exception ignored){}
        }

        private void drawMap(){
            SurfaceContainer sc=surfaceContainer;
            if(sc==null)return;
            Surface surface=sc.getSurface();
            if(surface==null||!surface.isValid())return;

            Canvas canvas=null;
            try{
                canvas=surface.lockCanvas(null);
                int w=canvas.getWidth(),h=canvas.getHeight();
                canvas.drawColor(0xff09101b);

                Paint grid=new Paint(Paint.ANTI_ALIAS_FLAG);
                grid.setColor(0xff16263d);grid.setStrokeWidth(2);
                for(int x=0;x<w;x+=Math.max(80,w/10))canvas.drawLine(x,0,x,h,grid);
                for(int y=0;y<h;y+=Math.max(80,h/7))canvas.drawLine(0,y,w,y,grid);

                List<GeoPoint> pts=RoutePilotState.loadRoute(getCarContext());
                if(pts.size()>1){
                    double minLat=90,maxLat=-90,minLon=180,maxLon=-180;
                    for(GeoPoint p:pts){
                        minLat=Math.min(minLat,p.getLatitude());maxLat=Math.max(maxLat,p.getLatitude());
                        minLon=Math.min(minLon,p.getLongitude());maxLon=Math.max(maxLon,p.getLongitude());
                    }
                    double latSpan=Math.max(0.0005,maxLat-minLat);
                    double lonSpan=Math.max(0.0005,maxLon-minLon);
                    float pad=Math.min(w,h)*0.08f;

                    Path path=new Path();
                    for(int i=0;i<pts.size();i++){
                        GeoPoint p=pts.get(i);
                        float x=(float)(pad+(p.getLongitude()-minLon)/lonSpan*(w-2*pad));
                        float y=(float)(h-pad-(p.getLatitude()-minLat)/latSpan*(h-2*pad));
                        if(i==0)path.moveTo(x,y);else path.lineTo(x,y);
                    }
                    Paint route=new Paint(Paint.ANTI_ALIAS_FLAG);
                    route.setStyle(Paint.Style.STROKE);route.setStrokeCap(Paint.Cap.ROUND);
                    route.setStrokeJoin(Paint.Join.ROUND);route.setStrokeWidth(12);route.setColor(0xff38bdf8);
                    canvas.drawPath(path,route);

                    RoutePilotState.Snapshot s=RoutePilotState.get(getCarContext());
                    if(s.lat!=0||s.lon!=0){
                        float x=(float)(pad+(s.lon-minLon)/lonSpan*(w-2*pad));
                        float y=(float)(h-pad-(s.lat-minLat)/latSpan*(h-2*pad));
                        Paint me=new Paint(Paint.ANTI_ALIAS_FLAG);me.setColor(0xfff1f5f9);
                        canvas.drawCircle(x,y,12,me);
                        me.setStyle(Paint.Style.STROKE);me.setStrokeWidth(5);me.setColor(0xff38bdf8);
                        canvas.drawCircle(x,y,18,me);
                    }
                }

                RoutePilotState.Snapshot s=RoutePilotState.get(getCarContext());
                Paint text=new Paint(Paint.ANTI_ALIAS_FLAG);
                text.setColor(0xfff1f5f9);text.setTextSize(31);
                String footer=s.speedLimit>0
                        ?"RoutePilot • "+s.speedLimit+" km/u • route "+s.safetyScore+"/100"
                        :"RoutePilot • route "+s.safetyScore+"/100";
                canvas.drawText(footer,24,h-28,text);

                text.setTextSize(28);
                float y=44;
                if(s.wmoPhase!=null&&!s.wmoPhase.isEmpty()){
                    String phase=s.wmoPhase;
                    if(s.wmoWaitRemainingMs>0) phase+=" • "+WmoSessionManager.formatWait(s.wmoWaitRemainingMs);
                    else if(s.wmoWaitExpired) phase+=" • LOOS MOGELIJK";
                    canvas.drawText(shorten(phase,70),24,y,text);
                    y+=38;
                }

                text.setTextSize(23);
                if(s.warning!=null&&!s.warning.isEmpty()){
                    canvas.drawText(shorten("WAARSCHUWING • "+s.warning,95),24,y,text);
                    y+=32;
                }else if(s.lookAhead!=null&&!s.lookAhead.isEmpty()){
                    canvas.drawText(shorten(s.lookAhead,95),24,y,text);
                    y+=32;
                }

                if(s.arrival!=null&&!s.arrival.isEmpty()){
                    canvas.drawText(shorten("AANKOMST • "+s.arrival.replace("\n"," • "),95),24,y,text);
                }
            }catch(Exception ignored){
            }finally{
                if(canvas!=null)try{surface.unlockCanvasAndPost(canvas);}catch(Exception ignored){}
            }
        }
        private String shorten(String s,int max){
            if(s==null)return "";
            return s.length()<=max?s:s.substring(0,max-1)+"…";
        }

    }
}
