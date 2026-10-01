package nl.routepilot.prototype;

import android.content.Context;
import android.location.Location;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;

public final class RouteCoordinator {
    private RouteCoordinator(){}

    public static final class Prepared {
        public OnlineServices.RouteResult route;
        public RouteAnalysis.Result analysis;
        public boolean vehicleScanOk=true;
        public boolean trafficScanOk=true;
        public boolean signScanOk=true;
        public boolean reversedForDoor=false;
        public DestinationAccessService.Result destinationAccess;
        public RouteConfidence.Result confidence;
        public ArrivalEngine.Result arrival;
        public String note="";
    }

    public static Prepared prepare(Context context, Location start,
                                   OnlineServices.SearchResult destination,
                                   VehicleProfile vehicle) throws Exception {
        List<OnlineServices.RouteResult> candidates=OnlineServices.routeCandidates(
                start.getLatitude(),start.getLongitude(),destination.lat,destination.lon);
        List<Prepared> prepared=new ArrayList<>();

        for(int i=0;i<candidates.size() && i<3;i++){
            prepared.add(enrich(context,candidates.get(i),destination,vehicle));
        }
        if(prepared.isEmpty()) throw new IllegalArgumentException("Geen bruikbare route gevonden.");

        Prepared selected=select(prepared);

        if(hardScore(selected)>0){
            Prepared detoured=tryAdaptiveDetours(context,start,destination,vehicle,selected);
            if(detoured!=null && hardScore(detoured)<hardScore(selected)){
                detoured.route.selectionNote=append(detoured.route.selectionNote,
                        "Adaptive voertuigomleiding gekozen omdat de standaardroute een harde beperking of afsluiting raakte.");
                prepared.add(detoured);
                selected=select(prepared);
            }
        }

        if(!selected.analysis.destinationOnRight
                && selected.route.liveClosureCount()==0
                && selected.route.criticalCount()==0
                && (selected.destinationAccess==null
                    || !selected.destinationAccess.shouldSkipDoorPreference())){
            Prepared base=selected;
            try{
                OnlineServices.RouteResult reversed=OnlineServices.reverseApproachCandidate(
                        start.getLatitude(),start.getLongitude(),
                        destination.lat,destination.lon,base.route);
                Prepared rp=enrich(context,reversed,destination,vehicle);

                if(isPracticalDoorApproach(base,rp)){
                    rp.reversedForDoor=true;
                    rp.route.selectionNote="Andere legale en praktische aanrijrichting gekozen voor rechterdeur aan ingangzijde.";
                    selected=rp;
                }else{
                    selected.note=append(selected.note,
                            "Rechterdeurvoorkeur losgelaten: andere aanrijrichting was niet praktisch, niet veiliger of niet logisch bereikbaar.");
                }
            }catch(Exception e){
                selected.note=append(selected.note,
                        "Rechterdeurvoorkeur losgelaten: geen bruikbare alternatieve aanrijrichting beschikbaar.");
            }
        }

        if(selected.analysis.uTurns>0){
            selected.note=append(selected.note,
                    "Route bevat nog "+selected.analysis.uTurns+" keerbeweging(en); controleer of omrijden praktischer is.");
        }

        if(selected.destinationAccess!=null
                && selected.destinationAccess.shouldSkipDoorPreference()){
            selected.note=append(selected.note,
                    "Rechterdeurvoorkeur bewust losgelaten door bestemmingsbereikbaarheid/eenrichtings-/keerbaarheidsanalyse.");
        }

        selected.confidence=RouteConfidence.calculate(context,selected,destination);
        selected.arrival=ArrivalEngine.evaluate(context,destination,selected.route,
                selected.analysis,selected.destinationAccess);
        return selected;
    }

    public static Prepared enrich(Context context, OnlineServices.RouteResult route,
                                  OnlineServices.SearchResult destination,
                                  VehicleProfile vehicle) {
        Prepared p=new Prepared();
        p.route=route;

        try{
            route.restrictions=OnlineServices.scanRestrictions(route,vehicle);
        }catch(Exception e){p.vehicleScanOk=false;}

        try{
            route.trafficEvents=LiveTrafficService.eventsNearRoute(route.points);
        }catch(Exception e){p.trafficScanOk=false;}

        try{
            route.roadSigns=RoadDataService.signsNearRoute(route.points);
            applyFormalSignRestrictions(route,vehicle);
        }catch(Exception e){p.signScanOk=false;}

        try{
            route.officialSpeeds=OfficialSpeedService.loadForRoute(route.points);
        }catch(Exception ignored){
            route.officialSpeeds=new ArrayList<>();
        }

        try{
            p.destinationAccess=DestinationAccessService.scan(destination,route.points);
        }catch(Exception ignored){
            p.destinationAccess=new DestinationAccessService.Result();
        }

        p.analysis=RouteAnalysis.analyze(context,route,destination);
        p.confidence=RouteConfidence.calculate(context,p,destination);
        p.arrival=ArrivalEngine.evaluate(context,destination,route,p.analysis,p.destinationAccess);
        return p;
    }

    private static Prepared select(List<Prepared> all){
        all.sort(new Comparator<Prepared>(){
            @Override public int compare(Prepared a,Prepared b){
                int aIllegal=a.route.liveClosureCount()*100+a.route.criticalCount()*20;
                int bIllegal=b.route.liveClosureCount()*100+b.route.criticalCount()*20;
                if(aIllegal!=bIllegal)return Integer.compare(aIllegal,bIllegal);

                if(a.analysis.score!=b.analysis.score)
                    return Integer.compare(b.analysis.score,a.analysis.score);

                if(a.analysis.learnedPenalty!=b.analysis.learnedPenalty)
                    return Integer.compare(a.analysis.learnedPenalty,b.analysis.learnedPenalty);

                if(a.analysis.uTurns!=b.analysis.uTurns)
                    return Integer.compare(a.analysis.uTurns,b.analysis.uTurns);

                boolean aDoorEligible=a.destinationAccess==null
                        || !a.destinationAccess.shouldSkipDoorPreference();
                boolean bDoorEligible=b.destinationAccess==null
                        || !b.destinationAccess.shouldSkipDoorPreference();
                if(aDoorEligible && bDoorEligible
                        && a.analysis.destinationOnRight!=b.analysis.destinationOnRight)
                    return a.analysis.destinationOnRight?-1:1;

                return Double.compare(a.route.durationSeconds,b.route.durationSeconds);
            }
        });
        Prepared p=all.get(0);
        if(all.size()>1){
            p.route.selectionNote=append(p.route.selectionNote,
                    "Beste van "+all.size()+" routevarianten op veiligheid, voertuig, live hinder en leerdata; aankomstzijde is alleen voorkeur.");
        }
        return p;
    }

    private static Prepared tryAdaptiveDetours(Context context, Location start,
                                                OnlineServices.SearchResult destination,
                                                VehicleProfile vehicle,
                                                Prepared base){
        double[] hazard=firstHardPoint(base);
        if(hazard==null)return null;

        Prepared best=null;
        for(int side:new int[]{-1,1}){
            try{
                double[] via=detourPoint(base.route,hazard[0],hazard[1],side,360.0);
                OnlineServices.RouteResult r=OnlineServices.routeViaWaypoint(
                        start.getLatitude(),start.getLongitude(),
                        via[0],via[1],destination.lat,destination.lon);
                if(r.distanceMeters>base.route.distanceMeters*1.35)continue;
                if(r.durationSeconds>base.route.durationSeconds+600.0)continue;

                Prepared p=enrich(context,r,destination,vehicle);
                if(best==null || hardScore(p)<hardScore(best)
                        || (hardScore(p)==hardScore(best)
                        && p.analysis.score>best.analysis.score)){
                    best=p;
                }
            }catch(Exception ignored){}
        }
        return best;
    }

    private static int hardScore(Prepared p){
        if(p==null||p.route==null)return Integer.MAX_VALUE;
        return p.route.liveClosureCount()*100+p.route.criticalCount()*20;
    }

    private static double[] firstHardPoint(Prepared p){
        if(p==null||p.route==null)return null;
        if(p.route.trafficEvents!=null){
            for(LiveTrafficService.TrafficEvent e:p.route.trafficEvents)
                if(e.closure)return new double[]{e.lat,e.lon};
        }
        if(p.route.restrictions!=null){
            for(OnlineServices.Restriction r:p.route.restrictions)
                if(r.critical)return new double[]{r.lat,r.lon};
        }
        return null;
    }

    private static double[] detourPoint(OnlineServices.RouteResult route,double lat,double lon,
                                        int side,double meters){
        int idx=OnlineServices.closestRoutePointIndex(lat,lon,route.points);
        int a=Math.max(0,idx-1);
        int b=Math.min(route.points.size()-1,idx+1);
        double lat1=route.points.get(a).getLatitude();
        double lon1=route.points.get(a).getLongitude();
        double lat2=route.points.get(b).getLatitude();
        double lon2=route.points.get(b).getLongitude();

        double y=Math.sin(Math.toRadians(lon2-lon1))*Math.cos(Math.toRadians(lat2));
        double x=Math.cos(Math.toRadians(lat1))*Math.sin(Math.toRadians(lat2))
                -Math.sin(Math.toRadians(lat1))*Math.cos(Math.toRadians(lat2))
                *Math.cos(Math.toRadians(lon2-lon1));
        double bearing=(Math.toDegrees(Math.atan2(y,x))+360.0)%360.0;
        double perpendicular=(bearing+(side<0?-90.0:90.0)+360.0)%360.0;

        double earth=6371000.0;
        double br=Math.toRadians(perpendicular);
        double p1=Math.toRadians(lat);
        double l1=Math.toRadians(lon);
        double dr=meters/earth;
        double p2=Math.asin(Math.sin(p1)*Math.cos(dr)
                +Math.cos(p1)*Math.sin(dr)*Math.cos(br));
        double l2=l1+Math.atan2(Math.sin(br)*Math.sin(dr)*Math.cos(p1),
                Math.cos(dr)-Math.sin(p1)*Math.sin(p2));
        return new double[]{Math.toDegrees(p2),Math.toDegrees(l2)};
    }

    private static boolean isPracticalDoorApproach(Prepared base, Prepared candidate){
        if(base==null||candidate==null) return false;
        if(!candidate.analysis.destinationOnRight) return false;
        if(candidate.destinationAccess!=null
                && candidate.destinationAccess.shouldSkipDoorPreference()) return false;

        // OSRM levert alleen een route die volgens de kaartgrafiek berijdbaar is.
        // Daardoor wordt een onmogelijke tegenrichting in een eenrichtingsstraat niet afgedwongen.
        if(candidate.route.liveClosureCount()>base.route.liveClosureCount()) return false;
        if(candidate.route.criticalCount()>base.route.criticalCount()) return false;

        // Geen extra keerbewegingen creëren puur voor de deurzijde.
        if(candidate.analysis.uTurns>base.analysis.uTurns) return false;

        // Rechterdeur mag geen duidelijk slechtere veiligheidsroute opleveren.
        if(candidate.analysis.score<base.analysis.score-4) return false;
        if(candidate.analysis.learnedPenalty>base.analysis.learnedPenalty+4) return false;

        // Geen absurde omweg: maximaal 20%, 1,5 km én 3 minuten extra.
        double extraDistance=candidate.route.distanceMeters-base.route.distanceMeters;
        double extraSeconds=candidate.route.durationSeconds-base.route.durationSeconds;
        if(candidate.route.distanceMeters>base.route.distanceMeters*1.20) return false;
        if(extraDistance>1500.0) return false;
        if(candidate.route.durationSeconds>base.route.durationSeconds*1.20) return false;
        if(extraSeconds>180.0) return false;

        return true;
    }

    private static void applyFormalSignRestrictions(OnlineServices.RouteResult route,
                                                    VehicleProfile vehicle){
        for(RoadDataService.Sign s:route.roadSigns){
            if(!RoadDataService.isRestriction(s))continue;
            boolean critical=false;
            String code=s.rvvCode==null?"":s.rvvCode;
            String black=s.blackCode==null?"":s.blackCode;

            if("C1".equals(code)||"C6".equals(code)||"C12".equals(code)) critical=true;
            if("C17".equals(code)) critical=number(black)<vehicle.lengthM;
            if("C18".equals(code)) critical=number(black)<vehicle.widthM;
            if("C19".equals(code)) critical=number(black)<vehicle.heightM;
            if("C21".equals(code)) critical=number(black)<vehicle.maxWeightT;

            String desc="Officieel NDW-bord "+code+": "+s.description()
                    +(s.roadName==null||s.roadName.isEmpty()?"":" op "+s.roadName)+".";
            route.restrictions.add(new OnlineServices.Restriction(
                    s.lat,s.lon,"NDW "+code,black,desc,critical,!critical
            ));
        }
    }

    private static double number(String raw){
        if(raw==null)return Double.MAX_VALUE;
        try{
            String n=raw.replace(',','.').replaceAll("[^0-9.]","");
            return n.isEmpty()?Double.MAX_VALUE:Double.parseDouble(n);
        }catch(Exception e){return Double.MAX_VALUE;}
    }

    private static String append(String a,String b){
        if(a==null||a.trim().isEmpty())return b;
        if(b==null||b.trim().isEmpty())return a;
        return a+" "+b;
    }
}
