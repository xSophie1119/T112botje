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
                canvas.drawColor(0xff070c14);

                Paint grid=new Paint(Paint.ANTI_ALIAS_FLAG);
                grid.setColor(0xff101b2a);grid.setStrokeWidth(2);
                for(int x=-h;x<w+h;x+=Math.max(110,w/8))
                    canvas.drawLine(x,0,x+h,h,grid);
                for(int x=0;x<w+h;x+=Math.max(110,w/8))
                    canvas.drawLine(x,0,x-h,h,grid);

                RoutePilotState.Snapshot s=RoutePilotState.get(getCarContext());
                List<GeoPoint> pts=RoutePilotState.loadRoute(getCarContext());
                List<GeoPoint> visible=visibleRouteWindow(pts,s);

                if(visible.size()>1){
                    double minLat=90,maxLat=-90,minLon=180,maxLon=-180;
                    for(GeoPoint p:visible){
                        minLat=Math.min(minLat,p.getLatitude());maxLat=Math.max(maxLat,p.getLatitude());
                        minLon=Math.min(minLon,p.getLongitude());maxLon=Math.max(maxLon,p.getLongitude());
                    }
                    if(s.lat!=0||s.lon!=0){
                        minLat=Math.min(minLat,s.lat);maxLat=Math.max(maxLat,s.lat);
                        minLon=Math.min(minLon,s.lon);maxLon=Math.max(maxLon,s.lon);
                    }
                    double latSpan=Math.max(0.00045,maxLat-minLat);
                    double lonSpan=Math.max(0.00045,maxLon-minLon);
                    float sidePad=Math.min(w,h)*0.10f;
                    float topPad=Math.min(w,h)*0.13f;
                    float bottomPad=Math.min(w,h)*0.18f;

                    Path routePath=new Path();
                    for(int i=0;i<visible.size();i++){
                        GeoPoint p=visible.get(i);
                        float x=(float)(sidePad+(p.getLongitude()-minLon)/lonSpan*(w-2*sidePad));
                        float y=(float)(h-bottomPad-(p.getLatitude()-minLat)/latSpan*(h-topPad-bottomPad));
                        if(i==0)routePath.moveTo(x,y);else routePath.lineTo(x,y);
                    }

                    Paint casing=new Paint(Paint.ANTI_ALIAS_FLAG);
                    casing.setStyle(Paint.Style.STROKE);casing.setStrokeCap(Paint.Cap.ROUND);
                    casing.setStrokeJoin(Paint.Join.ROUND);casing.setStrokeWidth(22);casing.setColor(0xff02070d);
                    canvas.drawPath(routePath,casing);

                    Paint route=new Paint(Paint.ANTI_ALIAS_FLAG);
                    route.setStyle(Paint.Style.STROKE);route.setStrokeCap(Paint.Cap.ROUND);
                    route.setStrokeJoin(Paint.Join.ROUND);route.setStrokeWidth(13);route.setColor(0xff38bdf8);
                    canvas.drawPath(routePath,route);

                    if(s.lat!=0||s.lon!=0){
                        float x=(float)(sidePad+(s.lon-minLon)/lonSpan*(w-2*sidePad));
                        float y=(float)(h-bottomPad-(s.lat-minLat)/latSpan*(h-topPad-bottomPad));
                        Paint halo=new Paint(Paint.ANTI_ALIAS_FLAG);halo.setColor(0xffffffff);
                        canvas.drawCircle(x,y,19,halo);
                        Paint me=new Paint(Paint.ANTI_ALIAS_FLAG);me.setColor(0xff0ea5e9);
                        canvas.drawCircle(x,y,13,me);
                    }
                }

                Paint panel=new Paint(Paint.ANTI_ALIAS_FLAG);
                panel.setColor(0xe60e1725);
                canvas.drawRoundRect(16,h-82,w-16,h-12,22,22,panel);

                Paint text=new Paint(Paint.ANTI_ALIAS_FLAG);
                text.setColor(0xfff8fafc);text.setTextSize(29);text.setFakeBoldText(true);
                String footer=s.speedLimit>0
                        ?"MAX "+s.speedLimit+"   •   ROUTE "+s.safetyScore+"/100"
                        :"ROUTEPILOT   •   ROUTE "+s.safetyScore+"/100";
                canvas.drawText(shorten(footer,58),34,h-38,text);

                float chipY=18;
                if(s.wmoPhase!=null&&!s.wmoPhase.isEmpty()){
                    String phase=s.wmoPhase;
                    if(s.wmoWaitRemainingMs>0) phase+=" • "+WmoSessionManager.formatWait(s.wmoWaitRemainingMs);
                    else if(s.wmoWaitExpired) phase+=" • LOOS MOGELIJK";
                    chipY=drawChip(canvas,24,chipY,shorten(phase,58),
                            s.wmoWaitExpired?0xff7f1d1d:0xff12314a,0xfff8fafc)+10;
                }

                if(s.warning!=null&&!s.warning.isEmpty()){
                    drawChip(canvas,24,chipY,shorten("⚠ "+s.warning,74),
                            0xff5b1f2a,0xffffd7df);
                }else if(s.lookAhead!=null&&!s.lookAhead.isEmpty()){
                    drawChip(canvas,24,chipY,shorten(s.lookAhead,74),
                            0xff132235,0xffdbeafe);
                }
            }catch(Exception ignored){
            }finally{
                if(canvas!=null)try{surface.unlockCanvasAndPost(canvas);}catch(Exception ignored){}
            }
        }

        private List<GeoPoint> visibleRouteWindow(List<GeoPoint> pts,RoutePilotState.Snapshot s){
            if(pts==null||pts.size()<2)return pts;
            if(s.lat==0&&s.lon==0)return pts;

            int nearest=0;
            double min=Double.MAX_VALUE;
            for(int i=0;i<pts.size();i++){
                GeoPoint p=pts.get(i);
                double d=OnlineServices.distanceMeters(s.lat,s.lon,p.getLatitude(),p.getLongitude());
                if(d<min){min=d;nearest=i;}
            }

            int from=Math.max(0,nearest-8);
            int to=nearest;
            double ahead=0;
            while(to<pts.size()-1&&ahead<3500){
                GeoPoint a=pts.get(to),b=pts.get(to+1);
                ahead+=OnlineServices.distanceMeters(
                        a.getLatitude(),a.getLongitude(),b.getLatitude(),b.getLongitude());
                to++;
            }
            return pts.subList(from,Math.min(pts.size(),to+1));
        }

        private float drawChip(Canvas canvas,float x,float y,String label,int bg,int fg){
            Paint text=new Paint(Paint.ANTI_ALIAS_FLAG);
            text.setTextSize(24);text.setFakeBoldText(true);text.setColor(fg);
            float width=Math.min(canvas.getWidth()-48,text.measureText(label)+34);
            Paint box=new Paint(Paint.ANTI_ALIAS_FLAG);box.setColor(bg);
            canvas.drawRoundRect(x,y,x+width,y+42,18,18,box);
            canvas.drawText(label,x+17,y+29,text);
            return y+42;
        }
        private String shorten(String s,int max){
            if(s==null)return "";
            return s.length()<=max?s:s.substring(0,max-1)+"…";
        }

    }
}
