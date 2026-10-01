package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class WmoSessionManager {
    public static final long WAIT_LIMIT_MS = 180_000L; // 3 minuten

    public enum Phase {
        IDLE,
        TO_PICKUP,
        WAITING_PICKUP,
        PASSENGER_ONBOARD,
        TO_DROPOFF,
        DISEMBARKING,
        COMPLETED,
        NO_SHOW
    }

    public static final class Snapshot {
        public Phase phase = Phase.IDLE;
        public String pickupLabel = "";
        public String dropoffLabel = "";
        public long sessionStartedAt = 0L;
        public long waitStartedAt = 0L;
        public long boardedAt = 0L;
        public long dropoffArrivedAt = 0L;
        public long completedAt = 0L;
        public boolean waitExpired = false;

        public long waitRemainingMs() {
            if (phase != Phase.WAITING_PICKUP || waitStartedAt <= 0) return 0L;
            return Math.max(0L, WAIT_LIMIT_MS - (System.currentTimeMillis() - waitStartedAt));
        }

        public long waitedMs() {
            if (waitStartedAt <= 0) return 0L;
            long end = phase == Phase.WAITING_PICKUP ? System.currentTimeMillis()
                    : boardedAt > 0 ? boardedAt
                    : completedAt > 0 ? completedAt
                    : System.currentTimeMillis();
            return Math.max(0L, end - waitStartedAt);
        }

        public String phaseLabel() {
            switch (phase) {
                case TO_PICKUP: return "NAAR CLIËNT";
                case WAITING_PICKUP: return "WACHTEN OP CLIËNT";
                case PASSENGER_ONBOARD: return "CLIËNT AAN BOORD";
                case TO_DROPOFF: return "NAAR BESTEMMING";
                case DISEMBARKING: return "UITSTAPPEN";
                case COMPLETED: return "RIT GEREED";
                case NO_SHOW: return "LOOS / NO-SHOW";
                default: return "WMO GEREED";
            }
        }
    }

    public static final class HistoryItem {
        public long startedAt;
        public long endedAt;
        public String pickupLabel;
        public String dropoffLabel;
        public String result;
        public long waitMs;
    }

    private static final String PREFS = "routepilot_wmo";
    private static final String KEY_STATE = "state";
    private static final String KEY_HISTORY = "history";

    private WmoSessionManager() {}

    public static synchronized Snapshot get(Context c) {
        SharedPreferences p = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String raw = p.getString(KEY_STATE, "");
        if (raw == null || raw.isEmpty()) return new Snapshot();
        try {
            JSONObject o = new JSONObject(raw);
            Snapshot s = new Snapshot();
            try {
                s.phase = Phase.valueOf(o.optString("phase", "IDLE"));
            } catch (Exception ignored) {
                s.phase = Phase.IDLE;
            }
            s.pickupLabel = o.optString("pickupLabel", "");
            s.dropoffLabel = o.optString("dropoffLabel", "");
            s.sessionStartedAt = o.optLong("sessionStartedAt", 0L);
            s.waitStartedAt = o.optLong("waitStartedAt", 0L);
            s.boardedAt = o.optLong("boardedAt", 0L);
            s.dropoffArrivedAt = o.optLong("dropoffArrivedAt", 0L);
            s.completedAt = o.optLong("completedAt", 0L);
            s.waitExpired = o.optBoolean("waitExpired", false)
                    || (s.phase == Phase.WAITING_PICKUP
                    && s.waitStartedAt > 0
                    && System.currentTimeMillis() - s.waitStartedAt >= WAIT_LIMIT_MS);
            return s;
        } catch (Exception e) {
            return new Snapshot();
        }
    }

    public static synchronized Snapshot beginPickup(Context c, String pickupLabel) {
        Snapshot s = new Snapshot();
        s.phase = Phase.TO_PICKUP;
        s.pickupLabel = pickupLabel == null ? "" : pickupLabel;
        s.sessionStartedAt = System.currentTimeMillis();
        save(c, s);
        return s;
    }

    public static synchronized Snapshot arrivePickup(Context c) {
        Snapshot s = get(c);
        if (s.phase == Phase.IDLE) {
            s.phase = Phase.WAITING_PICKUP;
            s.sessionStartedAt = System.currentTimeMillis();
        } else {
            s.phase = Phase.WAITING_PICKUP;
        }
        s.waitStartedAt = System.currentTimeMillis();
        s.waitExpired = false;
        save(c, s);
        return s;
    }

    public static synchronized Snapshot passengerBoarded(Context c) {
        Snapshot s = get(c);
        s.phase = Phase.PASSENGER_ONBOARD;
        s.boardedAt = System.currentTimeMillis();
        s.waitExpired = false;
        save(c, s);
        return s;
    }

    public static synchronized Snapshot beginDropoff(Context c, String dropoffLabel) {
        Snapshot s = get(c);
        if (s.sessionStartedAt == 0L) s.sessionStartedAt = System.currentTimeMillis();
        s.phase = Phase.TO_DROPOFF;
        s.dropoffLabel = dropoffLabel == null ? "" : dropoffLabel;
        save(c, s);
        return s;
    }

    public static synchronized Snapshot arriveDropoff(Context c) {
        Snapshot s = get(c);
        s.phase = Phase.DISEMBARKING;
        s.dropoffArrivedAt = System.currentTimeMillis();
        save(c, s);
        return s;
    }

    public static synchronized Snapshot complete(Context c) {
        Snapshot s = get(c);
        s.phase = Phase.COMPLETED;
        s.completedAt = System.currentTimeMillis();
        save(c, s);
        appendHistory(c, s, "COMPLETED");
        clearActiveSoon(c);
        return s;
    }

    public static synchronized Snapshot noShow(Context c) {
        Snapshot s = get(c);
        s.phase = Phase.NO_SHOW;
        s.completedAt = System.currentTimeMillis();
        s.waitExpired = true;
        save(c, s);
        appendHistory(c, s, "NO_SHOW");
        clearActiveSoon(c);
        return s;
    }

    public static synchronized void reset(Context c) {
        c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit().remove(KEY_STATE).apply();
    }

    public static boolean passengerOnboard(Context c) {
        Phase p = get(c).phase;
        return p == Phase.PASSENGER_ONBOARD
                || p == Phase.TO_DROPOFF
                || p == Phase.DISEMBARKING;
    }

    public static boolean expectsPickupNavigation(Context c) {
        Phase p = get(c).phase;
        return p == Phase.IDLE || p == Phase.TO_PICKUP || p == Phase.WAITING_PICKUP;
    }

    public static String formatWait(long ms) {
        long sec = Math.max(0L, (ms + 999L) / 1000L);
        return String.format(new Locale("nl","NL"), "%d:%02d", sec / 60L, sec % 60L);
    }

    public static synchronized List<HistoryItem> history(Context c) {
        List<HistoryItem> out = new ArrayList<>();
        JSONArray arr = historyArray(c);
        for (int i = arr.length() - 1; i >= 0; i--) {
            JSONObject o = arr.optJSONObject(i);
            if (o == null) continue;
            HistoryItem h = new HistoryItem();
            h.startedAt = o.optLong("startedAt", 0L);
            h.endedAt = o.optLong("endedAt", 0L);
            h.pickupLabel = o.optString("pickupLabel", "");
            h.dropoffLabel = o.optString("dropoffLabel", "");
            h.result = o.optString("result", "");
            h.waitMs = o.optLong("waitMs", 0L);
            out.add(h);
        }
        return out;
    }

    public static synchronized double averageWaitSeconds(Context c) {
        List<HistoryItem> items = history(c);
        long total = 0L;
        int n = 0;
        for (HistoryItem h : items) {
            if (h.waitMs <= 0L || !"COMPLETED".equals(h.result)) continue;
            total += h.waitMs;
            n++;
        }
        return n == 0 ? 0.0 : (total / 1000.0) / n;
    }

    public static synchronized int noShowCount(Context c) {
        int n = 0;
        for (HistoryItem h : history(c)) if ("NO_SHOW".equals(h.result)) n++;
        return n;
    }

    private static void save(Context c, Snapshot s) {
        try {
            JSONObject o = new JSONObject();
            o.put("phase", s.phase.name());
            o.put("pickupLabel", s.pickupLabel);
            o.put("dropoffLabel", s.dropoffLabel);
            o.put("sessionStartedAt", s.sessionStartedAt);
            o.put("waitStartedAt", s.waitStartedAt);
            o.put("boardedAt", s.boardedAt);
            o.put("dropoffArrivedAt", s.dropoffArrivedAt);
            o.put("completedAt", s.completedAt);
            o.put("waitExpired", s.waitExpired);
            c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                    .edit().putString(KEY_STATE, o.toString()).apply();
        } catch (Exception ignored) {}
    }

    private static void appendHistory(Context c, Snapshot s, String result) {
        JSONArray arr = historyArray(c);
        try {
            JSONObject o = new JSONObject();
            o.put("startedAt", s.sessionStartedAt);
            o.put("endedAt", s.completedAt);
            o.put("pickupLabel", s.pickupLabel);
            o.put("dropoffLabel", s.dropoffLabel);
            o.put("result", result);
            o.put("waitMs", s.waitedMs());
            arr.put(o);
            while (arr.length() > 150) arr.remove(0);
            c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                    .edit().putString(KEY_HISTORY, arr.toString()).apply();
        } catch (Exception ignored) {}
    }

    private static JSONArray historyArray(Context c) {
        try {
            return new JSONArray(c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                    .getString(KEY_HISTORY, "[]"));
        } catch (Exception e) {
            return new JSONArray();
        }
    }

    private static void clearActiveSoon(Context c) {
        // Bewaar de eindstatus kort in state; de volgende nieuwe rit overschrijft deze.
    }
}
