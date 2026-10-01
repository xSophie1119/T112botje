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

public final class BridgeOpeningService {
    private static final String URL_FEED =
            "https://opendata.ndw.nu/planningsfeed_brugopeningen.xml.gz";
    private static final long CACHE_MS = 120_000L;

    public static final class Event {
        public double lat, lon;
        public long startMs, endMs;
        public String description = "Geplande brugopening";
        public int routeIndex = -1;
        public double aheadMeters = Double.MAX_VALUE;
    }

    private static final Object LOCK = new Object();
    private static long cachedAt = 0L;
    private static List<Event> cached = new ArrayList<>();

    private BridgeOpeningService() {}

    public static List<Event> conflictsForRoute(OnlineServices.RouteResult route) throws Exception {
        List<Event> out = new ArrayList<>();
        if (route == null || route.points == null || route.points.size() < 2) return out;
        long now = System.currentTimeMillis();

        for (Event source : load()) {
            int idx = OnlineServices.closestRoutePointIndex(source.lat, source.lon, route.points);
            if (idx < 0) continue;
            GeoPoint p = route.points.get(idx);
            double d = OnlineServices.distanceMeters(source.lat, source.lon,
                    p.getLatitude(), p.getLongitude());
            if (d > 120.0) continue;

            double fromStart = distanceAlong(route.points, 0, idx);
            double ratio = route.distanceMeters > 1.0
                    ? Math.max(0.0, Math.min(1.0, fromStart / route.distanceMeters)) : 0.0;
            long eta = now + (long)(route.durationSeconds * ratio * 1000.0);

            long start = source.startMs > 0 ? source.startMs : now - 60_000L;
            long end = source.endMs > 0 ? source.endMs : start + 15 * 60_000L;
            boolean overlap = eta >= start - 2 * 60_000L && eta <= end + 2 * 60_000L;
            if (!overlap) continue;

            Event e = new Event();
            e.lat = source.lat; e.lon = source.lon;
            e.startMs = source.startMs; e.endMs = source.endMs;
            e.description = source.description;
            e.routeIndex = idx;
            e.aheadMeters = fromStart;
            out.add(e);
            if (out.size() >= 12) break;
        }
        return out;
    }

    private static List<Event> load() throws Exception {
        synchronized (LOCK) {
            if (!cached.isEmpty() && System.currentTimeMillis() - cachedAt < CACHE_MS)
                return new ArrayList<>(cached);
        }

        HttpURLConnection con = (HttpURLConnection)new URL(URL_FEED).openConnection();
        con.setConnectTimeout(12_000); con.setReadTimeout(22_000);
        con.setRequestProperty("User-Agent", OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept", "application/xml, application/gzip, */*");
        int code = con.getResponseCode();
        if (code < 200 || code >= 300) {
            con.disconnect();
            throw new IllegalStateException("NDW brugopeningen HTTP " + code);
        }

        List<Event> parsed;
        try (InputStream raw = new BufferedInputStream(con.getInputStream());
             GZIPInputStream gz = new GZIPInputStream(raw)) {
            parsed = parse(gz);
        } finally {
            con.disconnect();
        }

        synchronized (LOCK) {
            cached = parsed;
            cachedAt = System.currentTimeMillis();
        }
        return new ArrayList<>(parsed);
    }

    private static List<Event> parse(InputStream in) throws Exception {
        XmlPullParser x = Xml.newPullParser();
        x.setInput(in, "UTF-8");

        List<Event> out = new ArrayList<>();
        boolean inRecord = false;
        String tag = "";
        double lat = Double.NaN, lon = Double.NaN;
        String start = "", end = "", text = "";

        int type = x.getEventType();
        while (type != XmlPullParser.END_DOCUMENT) {
            if (type == XmlPullParser.START_TAG) {
                tag = local(x.getName()).toLowerCase(Locale.ROOT);
                if (tag.contains("situationrecord")) {
                    inRecord = true;
                    lat = Double.NaN; lon = Double.NaN;
                    start = ""; end = ""; text = "";
                }
            } else if (type == XmlPullParser.TEXT && inRecord) {
                String v = x.getText() == null ? "" : x.getText().trim();
                if (!v.isEmpty()) {
                    if ("latitude".equals(tag)) lat = number(v, lat);
                    else if ("longitude".equals(tag)) lon = number(v, lon);
                    else if ("overallstarttime".equals(tag)) start = v;
                    else if ("overallendtime".equals(tag)) end = v;
                    else if (("value".equals(tag) || tag.contains("description")
                            || tag.contains("name")) && useful(v) && text.isEmpty()) text = v;
                }
            } else if (type == XmlPullParser.END_TAG) {
                String endTag = local(x.getName()).toLowerCase(Locale.ROOT);
                if (inRecord && endTag.contains("situationrecord")) {
                    Long s = instant(start), e = instant(end);
                    long now = System.currentTimeMillis();
                    long startMs = s == null ? 0L : s;
                    long endMs = e == null ? 0L : e;
                    boolean futureUseful = (endMs == 0L || endMs >= now - 60_000L)
                            && (startMs == 0L || startMs <= now + 4L * 60L * 60L * 1000L);
                    if (!Double.isNaN(lat) && !Double.isNaN(lon) && futureUseful) {
                        Event ev = new Event();
                        ev.lat = lat; ev.lon = lon;
                        ev.startMs = startMs; ev.endMs = endMs;
                        ev.description = text.isEmpty() ? "Geplande brugopening" : text;
                        out.add(ev);
                    }
                    inRecord = false;
                }
                tag = "";
            }
            type = x.next();
        }
        return out;
    }

    private static double distanceAlong(List<GeoPoint> pts, int from, int to) {
        if (to < from) return -1;
        double d = 0;
        for (int i = from + 1; i <= to && i < pts.size(); i++) {
            GeoPoint a = pts.get(i - 1), b = pts.get(i);
            d += OnlineServices.distanceMeters(a.getLatitude(), a.getLongitude(),
                    b.getLatitude(), b.getLongitude());
        }
        return d;
    }

    private static String local(String s) {
        if (s == null) return "";
        int i = s.indexOf(':');
        return i >= 0 ? s.substring(i + 1) : s;
    }

    private static Long instant(String s) {
        if (s == null || s.isEmpty()) return null;
        try { return Instant.parse(s).toEpochMilli(); } catch (Exception e) { return null; }
    }

    private static boolean useful(String s) {
        return s.length() >= 4 && s.length() <= 180 && !s.matches("[0-9.]+");
    }

    private static double number(String s, double fallback) {
        try { return Double.parseDouble(s.replace(',', '.')); }
        catch (Exception e) { return fallback; }
    }
}
