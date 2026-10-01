package nl.routepilot.prototype;

import android.content.Context;

import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;

public final class ArrivalEngine {

    public static final class Result {
        public String title = "Aankomstassistent";
        public String sideMessage = "";
        public String liftMessage = "";
        public String departureMessage = "";
        public String stopMessage = "";
        public GeoPoint recommendedStop = null;
        public boolean useFallback = false;
        public boolean rightDoorPreferred = false;
        public boolean rightDoorSkipped = false;
        public final List<String> warnings = new ArrayList<>();

        public String summary() {
            StringBuilder b = new StringBuilder();
            if (!stopMessage.isEmpty()) b.append(stopMessage);
            if (!sideMessage.isEmpty()) append(b, sideMessage);
            if (!liftMessage.isEmpty()) append(b, liftMessage);
            if (!departureMessage.isEmpty()) append(b, departureMessage);
            for (String w : warnings) append(b, w);
            return b.toString();
        }

        private static void append(StringBuilder b, String s) {
            if (b.length() > 0) b.append("\n");
            b.append("• ").append(s);
        }
    }

    private ArrivalEngine() {}

    public static Result evaluate(Context context,
                                  OnlineServices.SearchResult destination,
                                  OnlineServices.RouteResult route,
                                  RouteAnalysis.Result analysis,
                                  DestinationAccessService.Result access) {
        Result out = new Result();
        RoutePilotStore.LocationProfile profile =
                RoutePilotStore.findProfile(context, destination.lat, destination.lon);
        VehicleProfile vehicle = VehicleProfile.load(context);
        PortalCorrectionStore.Correction portalStop =
                PortalCorrectionStore.nearestOfType(context,"good_stop",destination.lat,destination.lon);
        PortalCorrectionStore.Correction portalLiftOk =
                PortalCorrectionStore.nearestOfType(context,"lift_ok",destination.lat,destination.lon);
        PortalCorrectionStore.Correction portalLiftBad =
                PortalCorrectionStore.nearestOfType(context,"lift_bad",destination.lat,destination.lon);
        PortalCorrectionStore.Correction portalTurn =
                PortalCorrectionStore.nearestOfType(context,"turning_ok",destination.lat,destination.lon);

        if (portalStop != null) {
            out.recommendedStop = new GeoPoint(portalStop.lat, portalStop.lon);
            out.stopMessage = "WMO-stoppunt uit correctieportaal gebruikt."
                    + (portalStop.note==null||portalStop.note.isEmpty()?"":" "+portalStop.note);
        } else if (profile != null && profile.hasStopPoint) {
            out.recommendedStop = new GeoPoint(profile.stopLat, profile.stopLon);
            out.stopMessage = "Opgeslagen WMO-stoppunt gebruikt.";
        } else if (access != null && access.fallbackStop != null) {
            out.recommendedStop = access.fallbackStop;
            out.useFallback = true;
            out.stopMessage = "Voorzichtige fallback-stop vóór het route-einde aanbevolen.";
        } else {
            out.recommendedStop = new GeoPoint(destination.lat, destination.lon);
            out.stopMessage = "Stoppunt rond bestemming; controleer de exacte veilige stoppositie.";
        }

        boolean forcedSkip = (analysis != null && analysis.doorPreferenceSuppressed)
                || (access != null && access.shouldSkipDoorPreference());
        if (analysis != null && analysis.doorPreferenceSuppressed) {
            out.rightDoorSkipped = true;
            out.sideMessage = "Rechterdeurvoorkeur hier bewust uitgeschakeld via correctieportaal.";
        } else if (forcedSkip) {
            out.rightDoorSkipped = true;
            out.sideMessage = "Rechterdeurvoorkeur losgelaten door bereikbaarheid/eenrichtings-/keerbaarheidslogica.";
        } else if (analysis != null && analysis.destinationOnRight) {
            out.rightDoorPreferred = true;
            out.sideMessage = "Rechterdeur ligt aan de vermoedelijke ingangzijde.";
        } else {
            out.rightDoorSkipped = true;
            out.sideMessage = "Normale legale aanrijrichting blijft leidend; deurzijde is alleen voorkeur.";
        }

        if (portalLiftOk != null) {
            out.liftMessage = "Achterliftruimte via correctieportaal als geschikt bevestigd.";
        } else if (portalLiftBad != null) {
            out.liftMessage = "Achterliftruimte via correctieportaal als ongeschikt/aandachtspunt gemarkeerd.";
            out.warnings.add(portalLiftBad.note==null||portalLiftBad.note.isEmpty()
                    ?"Kies indien mogelijk een ander stoppunt voor de achterlift."
                    :portalLiftBad.note);
        } else if (profile != null && profile.liftSpaceStatus > 0) {
            if (profile.liftSpaceStatus >= 2) {
                out.liftMessage = "Achterliftruimte eerder als geschikt bevestigd.";
            } else {
                out.liftMessage = "Achterliftruimte eerder als aandachtspunt gemarkeerd.";
            }
        } else if (access != null && access.cyclewayNearby) {
            out.liftMessage = "Fietsinfrastructuur nabij de stopzone: achterlift extra controleren.";
            out.warnings.add("Controleer vóór uitklappen van de lift altijd fietspad, verkeer en vrije ruimte.");
        } else {
            out.liftMessage = "Achterliftruimte nog niet bevestigd. RoutePilot rekent conservatief met "
                    + String.format(new java.util.Locale("nl","NL"), "%.1f m", vehicle.rearLiftClearanceM)
                    + " vrije ruimte achter de bus.";
        }

        if (portalTurn != null) {
            out.departureMessage = "Keer-/vertrekruimte via correctieportaal als geschikt bevestigd.";
        } else if (access != null) {
            if (access.deadEndSignal && access.turningOptions == 0) {
                out.departureMessage = "Geen betrouwbare keeroptie gevonden; vertrekbaarheid is aandachtspunt.";
                out.warnings.add("Overweeg vóór de laatste straat te stoppen als keren/achteruitrijden onveilig lijkt.");
            } else if (access.turningOptions > 0) {
                out.departureMessage = "Minstens één OSM-keermogelijkheid nabij bestemming gevonden.";
            } else {
                out.departureMessage = "Vertrekbaarheid niet hard bevestigd; ter plaatse beoordelen.";
            }
            if (access.barrierNearby) {
                out.warnings.add("Barrière/toegangsobject nabij bestemming aangetroffen.");
            }
        }

        return out;
    }
}
