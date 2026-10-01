package nl.routepilot.prototype;

import android.Manifest;
import android.app.Activity;
import android.content.Context;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Bundle;
import android.speech.tts.TextToSpeech;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.InputMethodManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import org.osmdroid.config.Configuration;
import org.osmdroid.tileprovider.tilesource.TileSourceFactory;
import org.osmdroid.util.BoundingBox;
import org.osmdroid.util.GeoPoint;
import org.osmdroid.views.MapView;
import org.osmdroid.views.overlay.CopyrightOverlay;
import org.osmdroid.views.overlay.Marker;
import org.osmdroid.views.overlay.Polyline;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public class MainActivity extends Activity implements LocationListener, TextToSpeech.OnInitListener {

    private static final int LOCATION_REQUEST = 2002;

    private final int BG = Color.rgb(9, 15, 27);
    private final int PANEL = Color.rgb(17, 27, 44);
    private final int TEXT = Color.rgb(241, 245, 249);
    private final int MUTED = Color.rgb(148, 163, 184);
    private final int BLUE = Color.rgb(56, 189, 248);
    private final int GREEN = Color.rgb(74, 222, 128);
    private final int ORANGE = Color.rgb(251, 146, 60);
    private final int RED = Color.rgb(248, 113, 113);

    private MapView map;
    private Marker locationMarker;
    private Marker destinationMarker;
    private Polyline routeLine;
    private final List<Marker> restrictionMarkers = new ArrayList<>();

    private LocationManager locationManager;
    private Location currentLocation;
    private boolean centeredOnce = false;
    private TextToSpeech tts;

    private EditText destinationInput;
    private TextView gpsStatus;
    private TextView routeTitle;
    private TextView routeMeta;
    private TextView warningText;
    private Button searchButton;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Configuration.getInstance().setUserAgentValue(OnlineServices.USER_AGENT);
        Configuration.getInstance().setTileDownloadThreads((short) 2);
        Configuration.getInstance().setTileFileSystemThreads((short) 2);

        tts = new TextToSpeech(this, this);
        locationManager = (LocationManager) getSystemService(LOCATION_SERVICE);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(BG);

        map = new MapView(this);
        map.setTileSource(TileSourceFactory.MAPNIK);
        map.setMultiTouchControls(true);
        map.setTilesScaledToDpi(true);
        map.setMinZoomLevel(4.0);
        map.setMaxZoomLevel(19.0);
        map.getController().setZoom(13.5);
        map.getController().setCenter(new GeoPoint(51.5555, 5.0913));
        map.getOverlays().add(new CopyrightOverlay(this));

        LinearLayout.LayoutParams mapLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 0.54f);
        root.addView(map, mapLp);

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(false);
        LinearLayout panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setPadding(dp(16), dp(14), dp(16), dp(24));
        panel.setBackgroundColor(BG);
        scroll.addView(panel);

        LinearLayout.LayoutParams scrollLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 0.46f);
        root.addView(scroll, scrollLp);

        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);

        TextView logo = text("ROUTEPILOT  ONLINE", 13, BLUE, Typeface.BOLD);
        top.addView(logo, new LinearLayout.LayoutParams(0, dp(36), 1f));

        Button myLocation = smallButton("◎ GPS");
        myLocation.setOnClickListener(v -> centerOnMe());
        top.addView(myLocation, new LinearLayout.LayoutParams(dp(88), dp(38)));
        panel.addView(top);

        gpsStatus = text("Locatie wordt gestart…", 12, MUTED, Typeface.NORMAL);
        gpsStatus.setPadding(0, 0, 0, dp(10));
        panel.addView(gpsStatus);

        LinearLayout searchRow = new LinearLayout(this);
        searchRow.setOrientation(LinearLayout.HORIZONTAL);
        destinationInput = new EditText(this);
        destinationInput.setHint("Waar wil je heen?");
        destinationInput.setHintTextColor(MUTED);
        destinationInput.setTextColor(TEXT);
        destinationInput.setTextSize(15);
        destinationInput.setSingleLine(true);
        destinationInput.setPadding(dp(13), 0, dp(10), 0);
        destinationInput.setBackground(rounded(PANEL, 13));

        searchButton = smallButton("Route");
        searchButton.setOnClickListener(v -> searchAndRoute());

        searchRow.addView(destinationInput, new LinearLayout.LayoutParams(0, dp(50), 1f));
        LinearLayout.LayoutParams sb = new LinearLayout.LayoutParams(dp(92), dp(50));
        sb.leftMargin = dp(8);
        searchRow.addView(searchButton, sb);
        panel.addView(searchRow);

        routeTitle = text("Nog geen route", 19, TEXT, Typeface.BOLD);
        routeTitle.setPadding(0, dp(14), 0, dp(2));
        panel.addView(routeTitle);

        routeMeta = text("Zoek een bestemming om online te routeren.", 13, MUTED, Typeface.NORMAL);
        panel.addView(routeMeta);

        TextView vehicle = text(
                "🚐 Rolstoelbus  •  5,93 m  •  2,76 m hoog  •  2,34 m breed  •  3.500 kg",
                12, GREEN, Typeface.BOLD);
        vehicle.setPadding(0, dp(11), 0, dp(10));
        panel.addView(vehicle);

        warningText = text(
                "Voertuigscan: wacht op route. RoutePilot controleert online OSM-objecten op bussluizen en relevante hoogte-, breedte- en gewichtslimieten.",
                13, MUTED, Typeface.NORMAL);
        warningText.setPadding(dp(12), dp(11), dp(12), dp(11));
        warningText.setBackground(rounded(PANEL, 12));
        panel.addView(warningText);

        TextView disclaimer = text(
                "Prototype: OSRM berekent momenteel een gewone autoroute. De extra voertuigscan waarschuwt, maar blokkeert een ongeschikte route nog niet automatisch. Verkeersborden blijven altijd leidend.",
                11, MUTED, Typeface.NORMAL);
        disclaimer.setPadding(0, dp(12), 0, 0);
        panel.addView(disclaimer);

        setContentView(root);
        startLocation();
    }

    private void startLocation() {
        if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)
                != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION
            }, LOCATION_REQUEST);
            return;
        }

        try {
            gpsStatus.setText("GPS actief • wacht op positie…");
            locationManager.requestLocationUpdates(
                    LocationManager.GPS_PROVIDER, 1500L, 3f, this);
            locationManager.requestLocationUpdates(
                    LocationManager.NETWORK_PROVIDER, 3000L, 10f, this);

            Location last = locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER);
            if (last == null) {
                last = locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER);
            }
            if (last != null) onLocationChanged(last);
        } catch (Exception e) {
            gpsStatus.setText("Kon locatie niet starten: " + e.getMessage());
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(requestCode, permissions, results);
        if (requestCode == LOCATION_REQUEST) {
            if (results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED) {
                startLocation();
            } else {
                gpsStatus.setText("Locatietoegang geweigerd. Route vanaf GPS is niet beschikbaar.");
            }
        }
    }

    @Override
    public void onLocationChanged(Location location) {
        currentLocation = location;
        GeoPoint point = new GeoPoint(location.getLatitude(), location.getLongitude());

        if (locationMarker == null) {
            locationMarker = new Marker(map);
            locationMarker.setTitle("Mijn locatie");
            locationMarker.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
            map.getOverlays().add(locationMarker);
        }
        locationMarker.setPosition(point);

        int accuracy = Math.round(location.getAccuracy());
        gpsStatus.setText(String.format(new Locale("nl", "NL"),
                "GPS actief • nauwkeurigheid ±%d m", accuracy));

        if (!centeredOnce) {
            centeredOnce = true;
            map.getController().setZoom(16.0);
            map.getController().animateTo(point);
        }
        map.invalidate();
    }

    private void centerOnMe() {
        if (currentLocation == null) {
            Toast.makeText(this, "Nog geen GPS-positie.", Toast.LENGTH_SHORT).show();
            startLocation();
            return;
        }
        map.getController().setZoom(16.5);
        map.getController().animateTo(new GeoPoint(
                currentLocation.getLatitude(), currentLocation.getLongitude()));
    }

    private void searchAndRoute() {
        String query = destinationInput.getText().toString().trim();
        if (query.length() < 3) {
            Toast.makeText(this, "Vul een bestemming in.", Toast.LENGTH_SHORT).show();
            return;
        }
        if (currentLocation == null) {
            Toast.makeText(this, "Wacht eerst op je GPS-positie.", Toast.LENGTH_LONG).show();
            return;
        }

        hideKeyboard();
        searchButton.setEnabled(false);
        searchButton.setText("…");
        routeTitle.setText("Bestemming zoeken…");
        routeMeta.setText("Online zoeken via OpenStreetMap.");

        new Thread(() -> {
            try {
                OnlineServices.SearchResult destination = OnlineServices.searchPlace(query);
                runOnUiThread(() -> showDestination(destination));

                OnlineServices.RouteResult route = OnlineServices.route(
                        currentLocation.getLatitude(),
                        currentLocation.getLongitude(),
                        destination.lat,
                        destination.lon
                );

                runOnUiThread(() -> showRoute(destination, route));

                List<OnlineServices.Restriction> restrictions;
                try {
                    restrictions = OnlineServices.scanRestrictions(route.points);
                } catch (Exception scanError) {
                    restrictions = new ArrayList<>();
                    final String msg = scanError.getMessage();
                    runOnUiThread(() -> warningText.setText(
                            "Voertuigscan tijdelijk niet beschikbaar: " + msg));
                }

                final List<OnlineServices.Restriction> finalRestrictions = restrictions;
                runOnUiThread(() -> showRestrictions(finalRestrictions));

            } catch (Exception e) {
                runOnUiThread(() -> {
                    routeTitle.setText("Route niet geladen");
                    routeMeta.setText(e.getMessage() == null ? "Onbekende fout." : e.getMessage());
                    Toast.makeText(this, routeMeta.getText(), Toast.LENGTH_LONG).show();
                });
            } finally {
                runOnUiThread(() -> {
                    searchButton.setEnabled(true);
                    searchButton.setText("Route");
                });
            }
        }).start();
    }

    private void showDestination(OnlineServices.SearchResult destination) {
        if (destinationMarker == null) {
            destinationMarker = new Marker(map);
            destinationMarker.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
            map.getOverlays().add(destinationMarker);
        }
        destinationMarker.setPosition(new GeoPoint(destination.lat, destination.lon));
        destinationMarker.setTitle(destination.label);
        routeTitle.setText("Route berekenen…");
        routeMeta.setText(destination.label);
        map.invalidate();
    }

    private void showRoute(OnlineServices.SearchResult destination,
                           OnlineServices.RouteResult route) {
        if (routeLine != null) map.getOverlays().remove(routeLine);

        routeLine = new Polyline(map);
        routeLine.setPoints(route.points);
        routeLine.getOutlinePaint().setColor(BLUE);
        routeLine.getOutlinePaint().setStrokeWidth(dp(6));
        map.getOverlays().add(routeLine);

        for (Marker m : restrictionMarkers) map.getOverlays().remove(m);
        restrictionMarkers.clear();

        double km = route.distanceMeters / 1000.0;
        int minutes = (int) Math.round(route.durationSeconds / 60.0);
        routeTitle.setText(String.format(new Locale("nl", "NL"), "%.1f km • %d min", km, minutes));
        routeMeta.setText(route.firstInstruction + "  •  " + shortLabel(destination.label));

        fitRoute(route.points);
        warningText.setText("Voertuigscan loopt…");

        speak(String.format(new Locale("nl", "NL"),
                "Route gevonden. %.1f kilometer, ongeveer %d minuten. %s",
                km, minutes, route.firstInstruction));
    }

    private void showRestrictions(List<OnlineServices.Restriction> restrictions) {
        if (restrictions == null || restrictions.isEmpty()) {
            warningText.setText(
                    "✓ Online voertuigscan: geen relevante bussluis of kritieke maat-/gewichtslimiet gevonden vlak langs deze route. Dit is geen garantie dat de route geschikt is.");
            warningText.setTextColor(GREEN);
            return;
        }

        int critical = 0;
        StringBuilder sb = new StringBuilder();
        sb.append("⚠ Online voertuigscan: ").append(restrictions.size())
                .append(" aandachtspunt").append(restrictions.size() == 1 ? "" : "en").append("\n");

        int shown = 0;
        for (OnlineServices.Restriction r : restrictions) {
            if (r.critical) critical++;
            if (shown < 4) {
                sb.append("\n• ").append(r.type).append(": ").append(r.description);
                shown++;
            }

            Marker marker = new Marker(map);
            marker.setPosition(new GeoPoint(r.lat, r.lon));
            marker.setTitle(r.type);
            marker.setSnippet(r.description);
            marker.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
            restrictionMarkers.add(marker);
            map.getOverlays().add(marker);
        }
        if (restrictions.size() > shown) {
            sb.append("\n\n+ ").append(restrictions.size() - shown).append(" meer op de kaart.");
        }

        warningText.setText(sb.toString());
        warningText.setTextColor(critical > 0 ? RED : ORANGE);
        map.invalidate();

        if (critical > 0) {
            speak("Let op. RoutePilot heeft " + critical
                    + " mogelijk kritieke voertuigbeperking gevonden. Controleer de kaart en bebording.");
        }
    }

    private void fitRoute(List<GeoPoint> points) {
        if (points == null || points.isEmpty()) return;
        double north = -90, south = 90, east = -180, west = 180;
        for (GeoPoint p : points) {
            north = Math.max(north, p.getLatitude());
            south = Math.min(south, p.getLatitude());
            east = Math.max(east, p.getLongitude());
            west = Math.min(west, p.getLongitude());
        }
        try {
            map.zoomToBoundingBox(new BoundingBox(north, east, south, west), true, dp(55));
        } catch (Exception ignored) {
            map.getController().animateTo(points.get(points.size() / 2));
        }
    }

    private String shortLabel(String label) {
        if (label == null) return "";
        String[] p = label.split(",");
        if (p.length <= 2) return label;
        return p[0].trim() + ", " + p[1].trim();
    }

    private void hideKeyboard() {
        View v = getCurrentFocus();
        if (v == null) v = destinationInput;
        InputMethodManager imm = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) imm.hideSoftInputFromWindow(v.getWindowToken(), 0);
    }

    private void speak(String message) {
        if (tts != null) tts.speak(message, TextToSpeech.QUEUE_FLUSH, null, "routepilot-online");
    }

    @Override
    public void onInit(int status) {
        if (status == TextToSpeech.SUCCESS && tts != null) {
            tts.setLanguage(new Locale("nl", "NL"));
            tts.setSpeechRate(0.96f);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (map != null) map.onResume();
    }

    @Override
    protected void onPause() {
        if (map != null) map.onPause();
        super.onPause();
    }

    @Override
    protected void onDestroy() {
        try {
            if (locationManager != null) locationManager.removeUpdates(this);
        } catch (Exception ignored) {}
        if (tts != null) {
            tts.stop();
            tts.shutdown();
        }
        if (map != null) map.onDetach();
        super.onDestroy();
    }

    private Button smallButton(String label) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(13);
        b.setTextColor(Color.rgb(3, 18, 28));
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setAllCaps(false);
        b.setBackground(rounded(BLUE, 13));
        return b;
    }

    private TextView text(String value, int sp, int color, int style) {
        TextView v = new TextView(this);
        v.setText(value);
        v.setTextSize(sp);
        v.setTextColor(color);
        v.setTypeface(Typeface.DEFAULT, style);
        v.setLineSpacing(0, 1.10f);
        return v;
    }

    private android.graphics.drawable.GradientDrawable rounded(int color, int radiusDp) {
        android.graphics.drawable.GradientDrawable d = new android.graphics.drawable.GradientDrawable();
        d.setColor(color);
        d.setCornerRadius(dp(radiusDp));
        return d;
    }

    private int dp(int dp) {
        return Math.round(dp * getResources().getDisplayMetrics().density);
    }
}
