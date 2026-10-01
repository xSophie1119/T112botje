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
                && selected.route.criticalCount()==0){
            try{
                OnlineServices.RouteResult reversed=OnlineServices.reverseApproachCandidate(
                        start.getLatitude(),start.getLongitude(),
                        destination.lat,destination.lon,selected.route);
                Prepared rp=enrich(context,reversed,destination,vehicle);
                if(rp.analysis.destinationOnRight
                        && rp.route.liveClosureCount()==0
                        && rp.route.criticalCount()==0
                        && rp.analysis.score>=Math.max(45,selected.analysis.score-18)){
                    rp.reversedForDoor=true;
                    rp.route.selectionNote="Andere aanrijrichting gekozen voor rechterdeur aan ingangzijde.";
                    selected=rp;
                }else if(!rp.analysis.destinationOnRight){
                    selected.note=append(selected.note,
                            "Rechterdeur-aan-ingang kon niet veilig met de beschikbare routevarianten worden afgedwongen.");
                }
            }catch(Exception e){
                selected.note=append(selected.note,
                        "Rechterdeur-aan-ingang kon niet met een extra aanrijroute worden bevestigd.");
            }
        }

        if(selected.analysis.uTurns>0){
            selected.note=append(selected.note,
                    "Route bevat nog "+selected.analysis.uTurns+" keerbeweging(en); controleer of omrijden praktischer is.");
        }
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

        p.analysis=RouteAnalysis.analyze(context,route,destination);
        return p;
    }

    private static Prepared select(List<Prepared> all){
        all.sort(new Comparator<Prepared>(){
            @Override public int compare(Prepared a,Prepared b){
                int aIllegal=a.route.liveClosureCount()*100+a.route.criticalCount()*20;
                int bIllegal=b.route.liveClosureCount()*100+b.route.criticalCount()*20;
                if(aIllegal!=bIllegal)return Integer.compare(aIllegal,bIllegal);

                if(a.analysis.destinationOnRight!=b.analysis.destinationOnRight)
                    return a.analysis.destinationOnRight?-1:1;

                if(a.analysis.score!=b.analysis.score)
                    return Integer.compare(b.analysis.score,a.analysis.score);

                return Double.compare(a.route.durationSeconds,b.route.durationSeconds);
            }
        });
        Prepared p=all.get(0);
        if(all.size()>1){
            p.route.selectionNote=append(p.route.selectionNote,
                    "Beste van "+all.size()+" routevarianten op voertuig, live hinder, aankomstzijde en leerdata.");
        }
        return p;
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
