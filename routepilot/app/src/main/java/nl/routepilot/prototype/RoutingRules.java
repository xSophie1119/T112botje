package nl.routepilot.prototype;

public final class RoutingRules {
    private RoutingRules() {}

    public static boolean busLaneExemptionAllowed(boolean profileEnabled, boolean inTilburg) {
        return profileEnabled && inTilburg;
    }

    public static boolean doorPreferenceAllowed(boolean oneWayNearby,
                                                boolean barrierNearby,
                                                boolean deadEndSignal,
                                                int turningOptions) {
        if (oneWayNearby) return false;
        if (barrierNearby) return false;
        if (deadEndSignal && turningOptions <= 0) return false;
        return true;
    }

    public static boolean mayMarkNoShow(long waitedMs) {
        return waitedMs >= WmoSessionManager.WAIT_LIMIT_MS;
    }
}
