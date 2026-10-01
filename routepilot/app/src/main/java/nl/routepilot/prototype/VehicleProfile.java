package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

public class VehicleProfile {
    public double lengthM = 5.93;
    public double heightM = 2.76;
    public double widthM = 2.34;
    public double maxWeightT = 3.50;
    public boolean rearLift = true;
    // Conservatieve vrije ruimte achter de bus die RoutePilot voor liftgebruik probeert te bewaken.
    public double rearLiftClearanceM = 2.00;
    public boolean busLaneExemption = true;

    private static final String PREFS = "routepilot_vehicle";

    public static VehicleProfile load(Context context) {
        VehicleProfile p = new VehicleProfile();
        SharedPreferences sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        p.lengthM = readDouble(sp, "lengthM", 5.93);
        p.heightM = readDouble(sp, "heightM", 2.76);
        p.widthM = readDouble(sp, "widthM", 2.34);
        p.maxWeightT = readDouble(sp, "maxWeightT", 3.50);
        p.rearLift = sp.getBoolean("rearLift", true);
        p.rearLiftClearanceM = readDouble(sp, "rearLiftClearanceM", 2.00);
        p.busLaneExemption = sp.getBoolean("busLaneExemption", true);
        return p;
    }

    public void save(Context context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit()
                .putLong("lengthM", Double.doubleToRawLongBits(lengthM))
                .putLong("heightM", Double.doubleToRawLongBits(heightM))
                .putLong("widthM", Double.doubleToRawLongBits(widthM))
                .putLong("maxWeightT", Double.doubleToRawLongBits(maxWeightT))
                .putBoolean("rearLift", rearLift)
                .putLong("rearLiftClearanceM", Double.doubleToRawLongBits(rearLiftClearanceM))
                .putBoolean("busLaneExemption", busLaneExemption)
                .apply();
    }

    private static double readDouble(SharedPreferences sp, String key, double fallback) {
        if (!sp.contains(key)) return fallback;
        try {
            return Double.longBitsToDouble(sp.getLong(key, Double.doubleToRawLongBits(fallback)));
        } catch (Exception ignored) {
            return fallback;
        }
    }
}
