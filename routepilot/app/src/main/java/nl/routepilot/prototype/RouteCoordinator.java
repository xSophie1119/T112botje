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
