package nl.routepilot.prototype;

import android.content.Context;

public final class WmoAccessPolicy {
    private WmoAccessPolicy(){}

    public static boolean busLaneAllowed(Context context,VehicleProfile vehicle,
                                         double lat,double lon){
        if(vehicle==null||!vehicle.busLaneExemption)return false;
        if(!MunicipalityService.isInTilburg(lat,lon))return false;

        if(context!=null){
            if(PortalCorrectionStore.hasTypeNear(context,"bus_lane_forbidden",lat,lon))
                return false;
            if(PortalCorrectionStore.hasTypeNear(context,"bus_lane_allowed",lat,lon))
                return true;

            RoutingProviderSettings settings=RoutingProviderSettings.load(context);
            if(settings.strictBusLaneSegments)return false;
        }

        // Legacy fallback: alleen binnen Tilburg wanneer de profielontheffing aanstaat.
        // Voor maximale zekerheid kan strictBusLaneSegments worden ingeschakeld.
        return true;
    }

    public static String busLaneReason(Context context,VehicleProfile vehicle,
                                       double lat,double lon){
        if(vehicle==null||!vehicle.busLaneExemption)
            return "Geen busbaanontheffing in het voertuigprofiel.";
        if(!MunicipalityService.isInTilburg(lat,lon))
            return "Tilburgse ontheffing geldt hier niet.";
        if(context!=null&&PortalCorrectionStore.hasTypeNear(
                context,"bus_lane_forbidden",lat,lon))
            return "Dit segment is via het correctieportaal expliciet verboden.";
        if(context!=null&&PortalCorrectionStore.hasTypeNear(
                context,"bus_lane_allowed",lat,lon))
            return "Dit segment is via het correctieportaal expliciet toegestaan.";
        if(context!=null&&RoutingProviderSettings.load(context).strictBusLaneSegments)
            return "Strenge segmentmodus: geen expliciete toestemming voor dit busbaansegment.";
        return "Tilburg-profiel actief; fysieke bebording en ontheffingsvoorwaarden blijven leidend.";
    }
}
