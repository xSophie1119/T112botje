package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

public final class RoutingProviderSettings {
    private static final String PREFS="routepilot_routing_provider";
    public String provider="AUTO"; // AUTO, HERE, OSRM
    public String hereApiKey="";
    public boolean ndwAccessibility=true;
    public boolean strictBusLaneSegments=false;

    public static RoutingProviderSettings load(Context c){
        RoutingProviderSettings s=new RoutingProviderSettings();
        SharedPreferences p=c.getSharedPreferences(PREFS,Context.MODE_PRIVATE);
        s.provider=p.getString("provider","AUTO");
        s.hereApiKey=p.getString("hereApiKey","");
        s.ndwAccessibility=p.getBoolean("ndwAccessibility",true);
        s.strictBusLaneSegments=p.getBoolean("strictBusLaneSegments",false);
        return s;
    }

    public void save(Context c){
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putString("provider",provider==null?"AUTO":provider)
                .putString("hereApiKey",hereApiKey==null?"":hereApiKey.trim())
                .putBoolean("ndwAccessibility",ndwAccessibility)
                .putBoolean("strictBusLaneSegments",strictBusLaneSegments)
                .apply();
    }

    public boolean hasHereKey(){
        return hereApiKey!=null && !hereApiKey.trim().isEmpty();
    }

    public boolean useHere(){
        return ("HERE".equalsIgnoreCase(provider)||"AUTO".equalsIgnoreCase(provider)) && hasHereKey();
    }
}
