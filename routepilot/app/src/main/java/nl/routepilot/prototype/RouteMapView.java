package nl.routepilot.prototype;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.location.Location;
import android.os.Bundle;
import android.widget.FrameLayout;

import org.maplibre.android.MapLibre;
import org.maplibre.android.annotations.Icon;
import org.maplibre.android.annotations.IconFactory;
import org.maplibre.android.annotations.Marker;
import org.maplibre.android.annotations.MarkerOptions;
import org.maplibre.android.annotations.Polyline;
import org.maplibre.android.annotations.PolylineOptions;
import org.maplibre.android.camera.CameraPosition;
import org.maplibre.android.camera.CameraUpdateFactory;
import org.maplibre.android.geometry.LatLng;
import org.maplibre.android.geometry.LatLngBounds;
import org.maplibre.android.maps.MapLibreMap;
import org.maplibre.android.maps.MapView;
import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;

@SuppressWarnings("deprecation")
public class RouteMapView extends FrameLayout {
    private static final String STYLE_DAY="https://tiles.openfreemap.org/styles/liberty";
    private static final String STYLE_NIGHT="https://tiles.openfreemap.org/styles/dark";

    private final MapView mapView;
    private MapLibreMap map;
    private boolean ready=false;
    private boolean night=false;
    private boolean follow=true;

    private Marker userMarker;
    private Marker destinationMarker;
    private Polyline routeShadow;
    private Polyline routeLine;
    private Polyline replayLine;
    private final List<Marker> warningMarkers=new ArrayList<>();

    private List<GeoPoint> cachedRoute=new ArrayList<>();
    private List<GeoPoint> cachedReplay=new ArrayList<>();
    private List<GeoPoint> cachedPlanned=new ArrayList<>();
    private OnlineServices.RouteResult cachedRouteResult;
    private OnlineServices.SearchResult cachedDestination;
    private Location cachedLocation;

    private final IconFactory icons;
    private final Icon userIcon;
    private final Icon destinationIcon;
    private final Icon criticalIcon;
    private final Icon cautionIcon;
    private final Icon infoIcon;

    public RouteMapView(Context context){
        super(context);
        MapLibre.getInstance(context);
        icons=IconFactory.getInstance(context);
        userIcon=icons.fromBitmap(dotIcon(Color.rgb(56,189,248),true));
        destinationIcon=icons.fromBitmap(pinIcon(Color.rgb(56,189,248)));
        criticalIcon=icons.fromBitmap(badgeIcon(Color.rgb(248,113,113),"!"));
        cautionIcon=icons.fromBitmap(badgeIcon(Color.rgb(251,146,60),"!"));
        infoIcon=icons.fromBitmap(badgeIcon(Color.rgb(167,139,250),"i"));

        mapView=new MapView(context);
        mapView.onCreate((Bundle)null);
        addView(mapView,new LayoutParams(LayoutParams.MATCH_PARENT,LayoutParams.MATCH_PARENT));
        mapView.getMapAsync(m -> {
            map=m;
            configureMap();
            loadStyle();
        });
    }

    private void configureMap(){
        map.setMinZoomPreference(4.0);
        map.setMaxZoomPreference(20.0);
        map.getUiSettings().setCompassEnabled(true);
        map.getUiSettings().setTiltGesturesEnabled(true);
        map.getUiSettings().setRotateGesturesEnabled(true);
        map.getUiSettings().setAttributionEnabled(true);
        map.getUiSettings().setLogoEnabled(true);
        map.setCameraPosition(new CameraPosition.Builder()
                .target(new LatLng(51.5555,5.0913))
                .zoom(13.5)
                .tilt(0)
                .build());
    }

    private void loadStyle(){
        if(map==null)return;
        ready=false;
        map.setStyle(night?STYLE_NIGHT:STYLE_DAY, style -> {
            ready=true;
            redrawAll();
        });
    }

    public void setNightMode(boolean nightMode){
        if(night==nightMode)return;
        night=nightMode;
        if(map!=null)loadStyle();
    }

    public void setFollowMode(boolean value){follow=value;}

    public void setUserLocation(Location location,boolean navigating){
        cachedLocation=location;
        if(!ready||map==null||location==null)return;
        LatLng ll=new LatLng(location.getLatitude(),location.getLongitude());
        if(userMarker==null){
            userMarker=map.addMarker(new MarkerOptions()
                    .position(ll).title("Mijn locatie").icon(userIcon));
        }else{
            userMarker.setPosition(ll);
        }

        if(follow){
            double bearing=navigating && location.hasBearing()?location.getBearing():map.getCameraPosition().bearing;
            double tilt=navigating?48.0:0.0;
            double zoom=navigating?17.2:16.2;
            map.animateCamera(CameraUpdateFactory.newCameraPosition(
                    new CameraPosition.Builder()
                            .target(ll)
                            .zoom(zoom)
                            .bearing(bearing)
                            .tilt(tilt)
                            .build()),420);
        }
    }

    public void centerOn(double lat,double lon,boolean navigating,float bearing){
        if(!ready||map==null)return;
        follow=true;
        map.animateCamera(CameraUpdateFactory.newCameraPosition(
                new CameraPosition.Builder()
                        .target(new LatLng(lat,lon))
                        .zoom(navigating?17.2:16.4)
                        .bearing(navigating?bearing:0.0)
                        .tilt(navigating?48.0:0.0)
                        .build()),450);
    }

    public void setDestination(OnlineServices.SearchResult destination){
        cachedDestination=destination;
        if(!ready||map==null||destination==null)return;
        LatLng ll=new LatLng(destination.lat,destination.lon);
        if(destinationMarker==null){
            destinationMarker=map.addMarker(new MarkerOptions()
                    .position(ll)
                    .title(destination.label)
                    .icon(destinationIcon));
        }else{
            destinationMarker.setPosition(ll);
            destinationMarker.setTitle(destination.label);
        }
    }

    public void setRoute(OnlineServices.RouteResult route){
        cachedRouteResult=route;
        cachedRoute=route==null?new ArrayList<>():route.points;
        if(!ready||map==null)return;
        redrawRouteAndWarnings();
    }

    public void showReplay(List<GeoPoint> planned,List<GeoPoint> actual){
        cachedPlanned=planned==null?new ArrayList<>():planned;
        cachedReplay=actual==null?new ArrayList<>():actual;
        if(!ready||map==null)return;

        clearRouteLines();
        if(cachedPlanned.size()>=2){
            routeShadow=map.addPolyline(new PolylineOptions()
                    .addAll(latLngs(cachedPlanned))
                    .color(Color.argb(180,5,12,24)).width(13f));
            routeLine=map.addPolyline(new PolylineOptions()
                    .addAll(latLngs(cachedPlanned))
                    .color(Color.rgb(56,189,248)).width(7f));
        }
        if(cachedReplay.size()>=2){
            replayLine=map.addPolyline(new PolylineOptions()
                    .addAll(latLngs(cachedReplay))
                    .color(Color.rgb(251,146,60)).width(7f));
        }

        List<GeoPoint> all=new ArrayList<>(cachedReplay);
        all.addAll(cachedPlanned);
        fitPoints(all,70);
    }

    public void fitPoints(List<GeoPoint> points,int paddingPx){
        if(!ready||map==null||points==null||points.isEmpty())return;
        try{
            LatLngBounds.Builder b=new LatLngBounds.Builder();
            for(GeoPoint p:points)b.include(new LatLng(p.getLatitude(),p.getLongitude()));
            map.animateCamera(CameraUpdateFactory.newLatLngBounds(b.build(),paddingPx),500);
        }catch(Exception ignored){
            GeoPoint p=points.get(points.size()/2);
            map.animateCamera(CameraUpdateFactory.newLatLngZoom(
                    new LatLng(p.getLatitude(),p.getLongitude()),14.5),350);
        }
    }

    private void redrawAll(){
        if(!ready||map==null)return;
        userMarker=null;
        destinationMarker=null;
        routeShadow=null;
        routeLine=null;
        replayLine=null;
        warningMarkers.clear();

        if(cachedLocation!=null)setUserLocation(cachedLocation,false);
        if(cachedDestination!=null)setDestination(cachedDestination);
        if(cachedRouteResult!=null)redrawRouteAndWarnings();
        else if(!cachedReplay.isEmpty()||!cachedPlanned.isEmpty())showReplay(cachedPlanned,cachedReplay);
    }

    private void redrawRouteAndWarnings(){
        if(!ready||map==null)return;
        clearRouteLines();
        clearWarningMarkers();
        if(cachedRoute==null||cachedRoute.size()<2)return;

        routeShadow=map.addPolyline(new PolylineOptions()
                .addAll(latLngs(cachedRoute))
                .color(Color.argb(210,4,10,20)).width(15f));
        routeLine=map.addPolyline(new PolylineOptions()
                .addAll(latLngs(cachedRoute))
                .color(Color.rgb(56,189,248)).width(8f));

        if(cachedRouteResult!=null){
            if(cachedRouteResult.restrictions!=null){
                for(OnlineServices.Restriction r:cachedRouteResult.restrictions){
                    if(r.informational)continue;
                    addWarning(r.lat,r.lon,r.type,r.description,r.critical?criticalIcon:cautionIcon);
                }
            }
            if(cachedRouteResult.trafficEvents!=null){
                for(LiveTrafficService.TrafficEvent e:cachedRouteResult.trafficEvents){
                    addWarning(e.lat,e.lon,e.type,e.description,e.closure?criticalIcon:cautionIcon);
                }
            }
            if(cachedRouteResult.roadSigns!=null){
                for(RoadDataService.Sign s:cachedRouteResult.roadSigns){
                    if(!s.isSpeed()&&!RoadDataService.isRestriction(s))continue;
                    addWarning(s.lat,s.lon,s.rvvCode,s.description(),
                            RoadDataService.isRestriction(s)?cautionIcon:infoIcon);
                }
            }
        }
    }

    private void addWarning(double lat,double lon,String title,String snippet,Icon icon){
        Marker marker=map.addMarker(new MarkerOptions()
                .position(new LatLng(lat,lon))
                .title(title)
                .snippet(snippet)
                .icon(icon));
        warningMarkers.add(marker);
    }

    private void clearRouteLines(){
        if(map==null)return;
        if(routeShadow!=null){map.removePolyline(routeShadow);routeShadow=null;}
        if(routeLine!=null){map.removePolyline(routeLine);routeLine=null;}
        if(replayLine!=null){map.removePolyline(replayLine);replayLine=null;}
    }

    private void clearWarningMarkers(){
        if(map==null)return;
        for(Marker m:warningMarkers){
            try{map.removeMarker(m);}catch(Exception ignored){}
        }
        warningMarkers.clear();
    }

    private List<LatLng> latLngs(List<GeoPoint> points){
        List<LatLng> out=new ArrayList<>(points.size());
        for(GeoPoint p:points)out.add(new LatLng(p.getLatitude(),p.getLongitude()));
        return out;
    }

    private Bitmap dotIcon(int color,boolean halo){
        int s=dp(34);
        Bitmap b=Bitmap.createBitmap(s,s,Bitmap.Config.ARGB_8888);
        Canvas c=new Canvas(b);
        Paint p=new Paint(Paint.ANTI_ALIAS_FLAG);
        if(halo){
            p.setColor(Color.WHITE);
            c.drawCircle(s/2f,s/2f,s*0.42f,p);
        }
        p.setColor(color);
        c.drawCircle(s/2f,s/2f,s*0.30f,p);
        return b;
    }

    private Bitmap badgeIcon(int color,String text){
        int s=dp(34);
        Bitmap b=Bitmap.createBitmap(s,s,Bitmap.Config.ARGB_8888);
        Canvas c=new Canvas(b);
        Paint p=new Paint(Paint.ANTI_ALIAS_FLAG);
        p.setColor(Color.WHITE);
        c.drawCircle(s/2f,s/2f,s*0.46f,p);
        p.setColor(color);
        c.drawCircle(s/2f,s/2f,s*0.37f,p);
        p.setColor(Color.WHITE);
        p.setTextAlign(Paint.Align.CENTER);
        p.setTextSize(s*0.48f);
        p.setFakeBoldText(true);
        Paint.FontMetrics fm=p.getFontMetrics();
        c.drawText(text,s/2f,s/2f-(fm.ascent+fm.descent)/2f,p);
        return b;
    }

    private Bitmap pinIcon(int color){
        int w=dp(40),h=dp(48);
        Bitmap b=Bitmap.createBitmap(w,h,Bitmap.Config.ARGB_8888);
        Canvas c=new Canvas(b);
        Paint p=new Paint(Paint.ANTI_ALIAS_FLAG);
        p.setColor(Color.WHITE);
        c.drawCircle(w/2f,h*0.38f,w*0.38f,p);
        p.setColor(color);
        c.drawCircle(w/2f,h*0.38f,w*0.30f,p);
        float[] xs={w*0.38f,w*0.62f,w*0.50f};
        float[] ys={h*0.54f,h*0.54f,h*0.92f};
        android.graphics.Path path=new android.graphics.Path();
        path.moveTo(xs[0],ys[0]);path.lineTo(xs[1],ys[1]);path.lineTo(xs[2],ys[2]);path.close();
        c.drawPath(path,p);
        p.setColor(Color.WHITE);
        c.drawCircle(w/2f,h*0.38f,w*0.10f,p);
        return b;
    }

    private int dp(int v){
        return Math.round(v*getResources().getDisplayMetrics().density);
    }

    public void onStart(){mapView.onStart();}
    public void onResume(){mapView.onResume();}
    public void onPause(){mapView.onPause();}
    public void onStop(){mapView.onStop();}
    public void onLowMemory(){mapView.onLowMemory();}
    public void onDestroy(){mapView.onDestroy();}
}
