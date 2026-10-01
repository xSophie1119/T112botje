package nl.routepilot.prototype;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

public final class DestinationStore {

    public static class Item {
        public final String label;
        public final double lat;
        public final double lon;

        public Item(String label, double lat, double lon) {
            this.label = label;
            this.lat = lat;
            this.lon = lon;
        }
    }

    private static final String PREFS = "routepilot_destinations";
    private static final String KEY_RECENT = "recent";
    private static final String KEY_FAVORITES = "favorites";

    private DestinationStore() {}

    public static List<Item> recent(Context context) {
        return read(context, KEY_RECENT);
    }

    public static List<Item> favorites(Context context) {
        return read(context, KEY_FAVORITES);
    }

    public static void addRecent(Context context, Item item) {
        List<Item> items = recent(context);
        dedupe(items, item);
        items.add(0, item);
        while (items.size() > 6) items.remove(items.size() - 1);
        write(context, KEY_RECENT, items);
    }

    public static boolean toggleFavorite(Context context, Item item) {
        List<Item> items = favorites(context);
        for (int i = 0; i < items.size(); i++) {
            if (same(items.get(i), item)) {
                items.remove(i);
                write(context, KEY_FAVORITES, items);
                return false;
            }
        }
        items.add(0, item);
        while (items.size() > 8) items.remove(items.size() - 1);
        write(context, KEY_FAVORITES, items);
        return true;
    }

    public static boolean isFavorite(Context context, Item item) {
        for (Item existing : favorites(context)) {
            if (same(existing, item)) return true;
        }
        return false;
    }

    private static void dedupe(List<Item> items, Item item) {
        for (int i = items.size() - 1; i >= 0; i--) {
            if (same(items.get(i), item)) items.remove(i);
        }
    }

    private static boolean same(Item a, Item b) {
        return Math.abs(a.lat - b.lat) < 0.00001
                && Math.abs(a.lon - b.lon) < 0.00001;
    }

    private static List<Item> read(Context context, String key) {
        List<Item> out = new ArrayList<>();
        SharedPreferences sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String raw = sp.getString(key, "[]");
        try {
            JSONArray arr = new JSONArray(raw);
            for (int i = 0; i < arr.length(); i++) {
                JSONObject o = arr.getJSONObject(i);
                out.add(new Item(
                        o.optString("label", "Bestemming"),
                        o.getDouble("lat"),
                        o.getDouble("lon")
                ));
            }
        } catch (Exception ignored) {}
        return out;
    }

    private static void write(Context context, String key, List<Item> items) {
        JSONArray arr = new JSONArray();
        try {
            for (Item item : items) {
                JSONObject o = new JSONObject();
                o.put("label", item.label);
                o.put("lat", item.lat);
                o.put("lon", item.lon);
                arr.put(o);
            }
        } catch (Exception ignored) {}
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit()
                .putString(key, arr.toString())
                .apply();
    }
}
