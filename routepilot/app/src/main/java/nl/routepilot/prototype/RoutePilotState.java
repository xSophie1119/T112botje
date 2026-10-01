package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

public final class RoutePilotState {
    private static final String PREFS="routepilot_nav_state";
    private RoutePilotState(){}

    public static void update(Context c, boolean active, String instruction, String warning,
                              double stepDistanceM, double remainingM, long etaMs,
                              int speedLimit, int safetyScore, String destination) {
        c.getSharedPreferences(PREFS,Context.MODE_PRIVATE).edit()
                .putBoolean("active",active)
                .putString("instruction",instruction==null?"":instruction)
                .putString("warning",warning==null?"":warning)
                .putLong("stepDistance",Double.doubleToRawLongBits(stepDistanceM))
                .putLong("remaining",Double.doubleToRawLongBits(remainingM))
                .putLong("eta",etaMs)
                .putInt("speedLimit",speedLimit)
                .putInt("safetyScore",safetyScore)
                .putString("destination",destination==null?"":destination)
                .apply();
    }

    public static Snapshot get(Context c){
        SharedPreferences p=c.getSharedPreferences(PREFS,Context.MODE_PRIVATE);
        Snapshot s=new Snapshot();
        s.active=p.getBoolean("active",false);s.instruction=p.getString("instruction","");
        s.warning=p.getString("warning","");s.etaMs=p.getLong("eta",0);
        s.speedLimit=p.getInt("speedLimit",-1);s.safetyScore=p.getInt("safetyScore",0);
        s.destination=p.getString("destination","");
        s.stepDistanceM=Double.longBitsToDouble(p.getLong("stepDistance",Double.doubleToRawLongBits(0)));
        s.remainingM=Double.longBitsToDouble(p.getLong("remaining",Double.doubleToRawLongBits(0)));
        return s;
    }

    public static class Snapshot{
        public boolean active; public String instruction,warning,destination;
        public double stepDistanceM,remainingM; public long etaMs;
        public int speedLimit,safetyScore;
    }
}
