package nl.routepilot.prototype;

import android.util.Xml;

import org.osmdroid.util.GeoPoint;
import org.xmlpull.v1.XmlPullParser;

import java.io.BufferedInputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.zip.GZIPInputStream;

public final class LiveTrafficService {

    private static final String CLOSURES_URL =
            "https://opendata.ndw.nu/tijdelijke_verkeersmaatregelen_afsluitingen.xml.gz";
    private static final long CACHE_MS = 120_000L;

    private static final Object LOCK = new Object();
    private static long cachedAt = 0L;
    private static List<TrafficEvent> cached = new ArrayList<>();

    private LiveTrafficService() {}

    public static class TrafficEvent {
        public final double lat;
        public final double lon;
        public final String type;
        public final String description;
        public final boolean closure;
        public final String source;

        public TrafficEvent(double lat, double lon, String type,
                            String description, boolean closure, String source) {
            this.lat = lat;
            this.lon = lon;
            this.type = type;
            this.description = description;
            this.closure = closure;
            this.source = source;
        }
    }

    public static List<TrafficEvent> eventsNearRoute(List<GeoPoint> routePoints) throws Exception {
        return eventsNearRoute(routePoints, 140.0);
    }

    public static List<TrafficEvent> eventsNearRoute(List<GeoPoint> routePoints,
                                                     double corridorMeters) throws Exception {
        List<TrafficEvent> all = loadCurrentClosures();
        List<TrafficEvent> out = new ArrayList<>();
        if (routePoints == null || routePoints.isEmpty()) return out;

        for (TrafficEvent event : all) {
            if (distanceToRouteMeters(event.lat, event.lon, routePoints) <= corridorMeters) {
                out.add(event);
                if (out.size() >= 30) break;
            }
        }
        return out;
    }

    private static List<TrafficEvent> loadCurrentClosures() throws Exception {
        synchronized (LOCK) {
            if (!cached.isEmpty() && System.currentTimeMillis() - cachedAt < CACHE_MS) {
                return new ArrayList<>(cached);
            }
        }

        HttpURLConnection con = (HttpURLConnection) new URL(CLOSURES_URL).openConnection();
        con.setConnectTimeout(12_000);
        con.setReadTimeout(20_000);
        con.setRequestProperty("User-Agent", OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept", "application/xml, application/gzip, */*");

        int code = con.getResponseCode();
        if (code < 200 || code >= 300) {
            con.disconnect();
            throw new IllegalStateException("NDW afsluitingen gaf HTTP " + code);
        }

        List<TrafficEvent> parsed;
        try (InputStream raw = new BufferedInputStream(con.getInputStream());
             GZIPInputStream gzip = new GZIPInputStream(raw)) {
            parsed = parse(gzip);
        } finally {
            con.disconnect();
        }

        synchronized (LOCK) {
            cached = parsed;
            cachedAt = System.currentTimeMillis();
            return new ArrayList<>(cached);
        }
    }

    private static List<TrafficEvent> parse(InputStream input) throws Exception {
        XmlPullParser parser = Xml.newPullParser();
        parser.setInput(input, "UTF-8");

        List<TrafficEvent> out = new ArrayList<>();

        boolean inRecord = false;
        String currentTag = "";
        double lat = Double.NaN;
        double lon = Double.NaN;
        String type = "AFSLUITING";
        String description = "";
        String validityStatus = "";
        String startTime = "";
        String endTime = "";
        boolean closureSignal = false;

        int eventType = parser.getEventType();
        while (eventType != XmlPullParser.END_DOCUMENT) {
            if (eventType == XmlPullParser.START_TAG) {
                currentTag = local(parser.getName());

                if (currentTag.toLowerCase(Locale.ROOT).contains("situationrecord")) {
                    inRecord = true;
                    lat = Double.NaN;
                    lon = Double.NaN;
                    type = "AFSLUITING";
                    description = "";
                    validityStatus = "";
                    startTime = "";
                    endTime = "";
                    closureSignal = false;

                    String xsiType = parser.getAttributeValue(
                            "http://www.w3.org/2001/XMLSchema-instance", "type");
                    if (xsiType != null && !xsiType.trim().isEmpty()) {
                        type = cleanType(xsiType);
                    }
                }
            } else if (eventType == XmlPullParser.TEXT && inRecord) {
                String value = parser.getText() == null ? "" : parser.getText().trim();
                if (!value.isEmpty()) {
                    String tag = currentTag.toLowerCase(Locale.ROOT);

                    if ("latitude".equals(tag)) {
                        lat = parseDouble(value, lat);
                    } else if ("longitude".equals(tag)) {
                        lon = parseDouble(value, lon);
                    } else if ("validitystatus".equals(tag)) {
                        validityStatus = value;
                    } else if ("overallstarttime".equals(tag)) {
                        startTime = value;
                    } else if ("overallendtime".equals(tag)) {
                        endTime = value;
                    } else if (tag.contains("managementtype")
                            || tag.contains("closuretype")
                            || tag.contains("trafficmanagementtype")) {
                        closureSignal = true;
                        if (description.isEmpty()) description = prettify(value);
                    } else if ("value".equals(tag) && isUsefulText(value)) {
                        if (description.isEmpty()) description = value;
                    }
                }
            } else if (eventType == XmlPullParser.END_TAG) {
                String end = local(parser.getName());
                if (inRecord && end.toLowerCase(Locale.ROOT).contains("situationrecord")) {
                    if (isUsable(lat, lon)
                            && isCurrent(validityStatus, startTime, endTime)) {
                        String desc = description.trim();
                        if (desc.isEmpty()) {
                            desc = "Actuele tijdelijke afsluiting gemeld door NDW.";
                        }

                        boolean closure = closureSignal
                                || type.toLowerCase(Locale.ROOT).contains("management")
                                || type.toLowerCase(Locale.ROOT).contains("closure");

                        out.add(new TrafficEvent(
                                lat, lon,
                                "ACTUELE AFSLUITING",
                                desc,
                                closure,
                                "NDW"
                        ));
                    }
                    inRecord = false;
                }
                currentTag = "";
            }
            eventType = parser.next();
        }

        return out;
    }

    private static boolean isCurrent(String validityStatus, String start, String end) {
        String s = validityStatus == null ? "" : validityStatus.toLowerCase(Locale.ROOT);
        if (s.contains("suspended") || s.contains("cancel") || s.contains("definedbyvaliditytime")) {
            // definedByValidityTime can still be valid; continue to time check.
            if (!s.contains("definedbyvaliditytime")) return false;
        }

        long now = System.currentTimeMillis();
        Long startMs = parseInstant(start);
        Long endMs = parseInstant(end);

        if (startMs != null && startMs > now + 60_000L) return false;
        if (endMs != null && endMs < now - 60_000L) return false;
        return true;
    }

    private static Long parseInstant(String value) {
        if (value == null || value.trim().isEmpty()) return null;
        try {
            return Instant.parse(value.trim()).toEpochMilli();
        } catch (Exception ignored) {
            return null;
        }
    }

    private static String local(String name) {
        if (name == null) return "";
        int colon = name.indexOf(':');
        return colon >= 0 ? name.substring(colon + 1) : name;
    }

    private static String cleanType(String value) {
        String v = value;
        int colon = v.indexOf(':');
        if (colon >= 0) v = v.substring(colon + 1);
        return prettify(v);
    }

    private static String prettify(String value) {
        if (value == null) return "";
        String v = value.replace('_', ' ').replace('-', ' ');
        return v.replaceAll("([a-z])([A-Z])", "$1 $2").trim();
    }

    private static boolean isUsefulText(String value) {
        String v = value.trim();
        if (v.length() < 6 || v.length() > 220) return false;
        String lower = v.toLowerCase(Locale.ROOT);
        return !lower.matches("[a-z]{2}")
                && !lower.matches("[0-9.]+")
                && !lower.startsWith("http");
    }

    private static double parseDouble(String value, double fallback) {
        try {
            return Double.parseDouble(value.replace(',', '.'));
        } catch (Exception e) {
            return fallback;
        }
    }

    private static boolean isUsable(double lat, double lon) {
        return !Double.isNaN(lat) && !Double.isNaN(lon)
                && lat >= 50.5 && lat <= 54.0
                && lon >= 3.0 && lon <= 7.8;
    }

    private static double distanceToRouteMeters(double lat, double lon,
                                                List<GeoPoint> points) {
        double min = Double.MAX_VALUE;
        int stride = Math.max(1, points.size() / 1200);
        for (int i = 0; i < points.size(); i += stride) {
            GeoPoint p = points.get(i);
            double d = OnlineServices.distanceMeters(
                    lat, lon, p.getLatitude(), p.getLongitude());
            if (d < min) min = d;
            if (min < 25) break;
        }
        return min;
    }
}
