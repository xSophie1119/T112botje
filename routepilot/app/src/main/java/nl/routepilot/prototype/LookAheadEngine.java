package nl.routepilot.prototype;

import org.osmdroid.util.GeoPoint;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class LookAheadEngine {
    public static final class Item {
        public int priority;
        public double aheadMeters;
        public String text;
    }

    public static final class Result {
        public final List<Item> items = new ArrayList<>();
        public String compact = "Route verder vrij";
        public Item top = null;
    }

    private LookAheadEngine() {}

    public static Result analyze(OnlineServices.RouteResult route, int currentIndex,
                                 double horizonMeters) {
        Result out = new Result();
        if (route == null || route.points == null || route.points.size() < 2
                || currentIndex < 0) return out;

        for (OnlineServices.Restriction r : route.restrictions) {
            int idx = OnlineServices.closestRoutePointIndex(r.lat, r.lon, route.points);
            double ahead = distanceAlong(route.points, currentIndex, idx);
            if (ahead < 0 || ahead > horizonMeters) continue;
            Item i = new Item();
            i.priority = r.critical ? 100 : r.informational ? 20 : 60;
            i.aheadMeters = ahead;
            i.text = r.type + ": " + r.description;
            out.items.add(i);
        }

        if (route.trafficEvents != null) {
            for (LiveTrafficService.TrafficEvent e : route.trafficEvents) {
                int idx = OnlineServices.closestRoutePointIndex(e.lat, e.lon, route.points);
                double ahead = distanceAlong(route.points, currentIndex, idx);
                if (ahead < 0 || ahead > horizonMeters) continue;
                Item i = new Item();
                i.priority = e.closure ? 110 : 55;
                i.aheadMeters = ahead;
                i.text = e.type + ": " + e.description;
                out.items.add(i);
            }
        }

        if (route.roadSigns != null) {
            for (RoadDataService.Sign s : route.roadSigns) {
                if (!RoadDataService.isRestriction(s)) continue;
                int idx = s.routeIndex >= 0 ? s.routeIndex
                        : OnlineServices.closestRoutePointIndex(s.lat, s.lon, route.points);
                double ahead = distanceAlong(route.points, currentIndex, idx);
                if (ahead < 0 || ahead > horizonMeters) continue;
                Item i = new Item();
                i.priority = 80;
                i.aheadMeters = ahead;
                i.text = s.rvvCode + ": " + s.description();
                out.items.add(i);
            }
        }

        if (route.bridgeEvents != null) {
            for (BridgeOpeningService.Event e : route.bridgeEvents) {
                int idx = e.routeIndex >= 0 ? e.routeIndex
                        : OnlineServices.closestRoutePointIndex(e.lat, e.lon, route.points);
                double ahead = distanceAlong(route.points, currentIndex, idx);
                if (ahead < 0 || ahead > horizonMeters) continue;
                Item i = new Item();
                i.priority = 90;
                i.aheadMeters = ahead;
                i.text = "BRUGOPENING: " + e.description;
                out.items.add(i);
            }
        }

        if (route.temporarySpeeds != null) {
            for (TemporarySpeedService.Limit l : route.temporarySpeeds) {
                int idx = l.routeIndex;
                double ahead = distanceAlong(route.points, currentIndex, idx);
                if (ahead < 0 || ahead > horizonMeters) continue;
                Item i = new Item();
                i.priority = 45;
                i.aheadMeters = ahead;
                i.text = "TIJDELIJK MAX " + l.kmh + " km/u";
                out.items.add(i);
            }
        }

        Item top = null;
        for (Item i : out.items) {
            if (top == null
                    || i.priority > top.priority
                    || (i.priority == top.priority && i.aheadMeters < top.aheadMeters)) {
                top = i;
            }
        }
        out.top = top;

        if (out.items.isEmpty()) {
            out.compact = "Komende 5 km: route verder vrij";
        } else {
            int critical = 0, attention = 0;
            for (Item i : out.items) {
                if (i.priority >= 90) critical++; else if (i.priority >= 50) attention++;
            }
            StringBuilder b = new StringBuilder("Komende 5 km: ");
            if (critical > 0) b.append(critical).append(" kritiek");
            if (attention > 0) {
                if (critical > 0) b.append(" • ");
                b.append(attention).append(" aandacht");
            }
            if (critical == 0 && attention == 0) b.append(out.items.size()).append(" info");
            if (top != null) {
                b.append(" • eerst over ").append(formatDistance(top.aheadMeters))
                        .append(": ").append(shorten(top.text, 76));
            }
            out.compact = b.toString();
        }
        return out;
    }

    public static double distanceAlong(List<GeoPoint> points, int from, int to) {
        if (points == null || from < 0 || to < 0 || from >= points.size() || to >= points.size())
            return -1;
        if (to < from) return -1;
        double d = 0;
        for (int i = from + 1; i <= to; i++) {
            GeoPoint a = points.get(i - 1), b = points.get(i);
            d += OnlineServices.distanceMeters(
                    a.getLatitude(), a.getLongitude(),
                    b.getLatitude(), b.getLongitude());
        }
        return d;
    }

    private static String formatDistance(double m) {
        if (m < 950) return String.format(new Locale("nl","NL"), "%.0f m", m);
        return String.format(new Locale("nl","NL"), "%.1f km", m / 1000.0);
    }

    private static String shorten(String s, int max) {
        if (s == null) return "";
        return s.length() <= max ? s : s.substring(0, max - 1) + "…";
    }
}
