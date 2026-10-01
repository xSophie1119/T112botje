package nl.routepilot.prototype;

public final class ArrivalDetector {
    public static final class State{
        long candidateSince=0L;
        public void reset(){candidateSince=0L;}
    }

    private ArrivalDetector(){}

    public static boolean update(State state,long nowMs,
                                 double distanceToTargetM,double remainingRouteM,
                                 double offRouteM,double speedKmh,double accuracyM){
        if(state==null)return false;

        boolean candidate=distanceToTargetM<=48.0
                && remainingRouteM<=135.0
                && offRouteM<=38.0
                && speedKmh<=8.0
                && accuracyM<=45.0;

        if(!candidate){
            state.candidateSince=0L;
            return false;
        }
        if(state.candidateSince==0L)state.candidateSince=nowMs;
        return nowMs-state.candidateSince>=3000L;
    }
}
