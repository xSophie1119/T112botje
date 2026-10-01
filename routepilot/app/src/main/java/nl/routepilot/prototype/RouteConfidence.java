package nl.routepilot.prototype;

import android.content.Context;

import java.util.ArrayList;
import java.util.List;

public final class RouteConfidence {
    public static final class Result {
        public int score;
        public String label;
        public final List<String> reasons = new ArrayList<>();

        public String summary() {
            StringBuilder b = new StringBuilder();
            b.append(score).append("/100 • ").append(label);
            for (String r : reasons) b.append("\n• ").append(r);
            return b.toString();
        }
    }

    private RouteConfidence() {}

    public static Result calculate(Context context,
                                   RouteCoordinator.Prepared p,
                                   OnlineServices.SearchResult destination) {
        Result r = new Result();
        int score = 100;

        if (!p.vehicleScanOk) {
            score -= 22;
            r.reasons.add("OSM-voertuigscan niet volledig beschikbaar");
        } else {
            r.reasons.add("OSM-voertuigscan beschikbaar");
        }

        if (!p.trafficScanOk) {
            score -= 20;
            r.reasons.add("live NDW-verkeerslaag niet volledig beschikbaar");
        } else {
            r.reasons.add("live NDW-verkeerslaag beschikbaar");
        }

        if (!p.signScanOk) {
            score -= 18;
            r.reasons.add("officiële NDW-verkeersborden niet volledig beschikbaar");
        } else if (p.route.roadSigns != null && !p.route.roadSigns.isEmpty()) {
            r.reasons.add("officiële NDW-borden langs route gecontroleerd");
        } else {
            score -= 4;
            r.reasons.add("geen relevante NDW-borden langs route gevonden");
        }

        if (p.route.officialSpeeds == null || p.route.officialSpeeds.isEmpty()) {
            score -= 6;
            r.reasons.add("officiële snelheidslaag ontbreekt voor (deel van) route");
        } else {
            r.reasons.add("officiële WKD-snelheidslaag geladen");
        }

        if (p.destinationAccess == null || !p.destinationAccess.scanOk) {
            score -= 12;
            r.reasons.add("bestemmings-/keerbaarheidsanalyse niet bevestigd");
        } else {
            r.reasons.add("bestemmingsomgeving apart gescand");
        }

        RoutePilotStore.LocationProfile profile =
                RoutePilotStore.findProfile(context, destination.lat, destination.lon);
        if (profile != null && profile.visits >= 2) {
            score += Math.min(8, profile.visits);
            r.reasons.add("locatieprofiel bevestigd door eerdere bezoeken");
        }

        score = Math.max(0, Math.min(100, score));
        r.score = score;
        r.label = score >= 85 ? "HOGE DATAZEKERHEID"
                : score >= 65 ? "MIDDELHOGE DATAZEKERHEID"
                : "BEPERKTE DATAZEKERHEID";
        return r;
    }
}
