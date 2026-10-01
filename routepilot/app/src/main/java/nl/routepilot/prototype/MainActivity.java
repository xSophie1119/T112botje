package nl.routepilot.prototype;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
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
import android.view.WindowManager;
import android.view.inputmethod.InputMethodManager;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.EditText;
import android.widget.HorizontalScrollView;
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

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;

public class MainActivity extends Activity implements LocationListener, TextToSpeech.OnInitListener {

    private static final int LOCATION_REQUEST = 2002;
    private static final Locale NL = new Locale("nl", "NL");

    private final int BG = Color.rgb(9, 15, 27);
    private final int PANEL = Color.rgb(17, 27, 44);
    private final int PANEL_2 = Color.rgb(24, 37, 58);
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
    private TextToSpeech tts;
    private VehicleProfile vehicle;

    private OnlineServices.SearchResult currentDestination;
    private OnlineServices.RouteResult currentRoute;

    private boolean centeredOnce = false;
    private boolean navigating = false;
    private boolean followMode = true;
    private boolean routeLoading = false;

    private long lastRerouteMs = 0L;
    private int currentStepIndex = 0;
    private final Set<Integer> announcedApproachSteps = new HashSet<>();
    private final Set<Integer> announcedNearSteps = new HashSet<>();
    private final Set<Integer> announcedRestrictions = new HashSet<>();

    private EditText destinationInput;
    private TextView gpsStatus;
    private TextView routeTitle;
    private TextView routeMeta;
    private TextView warningText;
    private TextView vehicleSummary;
    private TextView navInstruction;
    private TextView navDistance;
    private TextView navMeta;
    private TextView navStatus;
    private Button routeButton;
    private Button startButton;
    private Button favoriteButton;

    private LinearLayout searchArea;
    private LinearLayout previewArea;
    private LinearLayout navArea;
    private LinearLayout savedPlaces;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Configuration.getInstance().setUserAgentValue(OnlineServices.USER_AGENT);
        Configuration.getInstance().setTileDownloadThreads((short) 2);
        Configuration.getInstance().setTileFileSystemThreads((short) 2);

        vehicle = VehicleProfile.load(this);
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
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 0.52f);
        root.addView(map, mapLp);

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(false);
        LinearLayout panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setPadding(dp(16), dp(12), dp(16), dp(24));
        panel.setBackgroundColor(BG);
        scroll.addView(panel);

        LinearLayout.LayoutParams scrollLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 0.48f);
        root.addView(scroll, scrollLp);

        buildHeader(panel);
        buildSearch(panel);
        buildSavedPlaces(panel);
        buildPreview(panel);
        buildNavigation(panel);

        TextView disclaimer = text(
                "RoutePilot V1 debug • voertuigwaarschuwingen zijn ondersteunend. Verkeersborden, wegafzettingen en actuele regels blijven leidend.",
                10, MUTED, Typeface.NORMAL);
        disclaimer.setGravity(Gravity.CENTER);
        disclaimer.setPadding(0, dp(12), 0, 0);
        panel.addView(disclaimer);

        setContentView(root);
        refreshVehicleSummary();
        refreshSavedPlaces();
        startLocation();
    }

    private void buildHeader(LinearLayout panel) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout titles = new LinearLayout(this);
        titles.setOrientation(LinearLayout.VERTICAL);
        titles.addView(text("ROUTEPILOT", 14, BLUE, Typeface.BOLD));
        titles.addView(text("V1 debug • online rijassistent", 11, MUTED, Typeface.NORMAL));
        row.addView(titles, new LinearLayout.LayoutParams(0, dp(46), 1f));

        Button gps = smallButton("◎ GPS");
        gps.setOnClickListener(v -> {
            followMode = true;
            centerOnMe();
        });
        row.addView(gps, new LinearLayout.LayoutParams(dp(78), dp(40)));

        Button settings = darkButton("⚙");
        LinearLayout.LayoutParams settingsLp = new LinearLayout.LayoutParams(dp(52), dp(40));
        settingsLp.leftMargin = dp(6);
        row.addView(settings, settingsLp);
        settings.setOnClickListener(v -> showVehicleSettings());

        panel.addView(row);

        gpsStatus = text("Locatie wordt gestart…", 11, MUTED, Typeface.NORMAL);
        gpsStatus.setPadding(0, 0, 0, dp(7));
        panel.addView(gpsStatus);

        vehicleSummary = text("", 11, GREEN, Typeface.BOLD);
        vehicleSummary.setPadding(0, 0, 0, dp(9));
        panel.addView(vehicleSummary);
    }

    private void buildSearch(LinearLayout panel) {
        searchArea = new LinearLayout(this);
        searchArea.setOrientation(LinearLayout.VERTICAL);

        LinearLayout searchRow = new LinearLayout(this);
        searchRow.setOrientation(LinearLayout.HORIZONTAL);

        destinationInput = new EditText(this);
        destinationInput.setHint("Adres, plaats of bestemming");
        destinationInput.setHintTextColor(MUTED);
        destinationInput.setTextColor(TEXT);
        destinationInput.setTextSize(15);
        destinationInput.setSingleLine(true);
        destinationInput.setPadding(dp(13), 0, dp(10), 0);
        destinationInput.setBackground(rounded(PANEL, 13));

        routeButton = smallButton("Route");
        routeButton.setOnClickListener(v -> searchAndRoute());

        searchRow.addView(destinationInput, new LinearLayout.LayoutParams(0, dp(50), 1f));
        LinearLayout.LayoutParams sb = new LinearLayout.LayoutParams(dp(88), dp(50));
        sb.leftMargin = dp(8);
        searchRow.addView(routeButton, sb);

        searchArea.addView(searchRow);
        panel.addView(searchArea);
    }

    private void buildSavedPlaces(LinearLayout panel) {
        TextView savedLabel = text("FAVORIETEN & RECENT", 10, MUTED, Typeface.BOLD);
        savedLabel.setPadding(0, dp(11), 0, dp(5));
        panel.addView(savedLabel);

        HorizontalScrollView hsv = new HorizontalScrollView(this);
        hsv.setHorizontalScrollBarEnabled(false);
        savedPlaces = new LinearLayout(this);
        savedPlaces.setOrientation(LinearLayout.HORIZONTAL);
        hsv.addView(savedPlaces);
        panel.addView(hsv, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(42)));
    }

    private void buildPreview(LinearLayout panel) {
        previewArea = new LinearLayout(this);
        previewArea.setOrientation(LinearLayout.VERTICAL);
        previewArea.setVisibility(View.GONE);
        previewArea.setPadding(dp(13), dp(12), dp(13), dp(13));
        previewArea.setBackground(rounded(PANEL, 15));

        routeTitle = text("Nog geen route", 20, TEXT, Typeface.BOLD);
        previewArea.addView(routeTitle);

        routeMeta = text("", 12, MUTED, Typeface.NORMAL);
        routeMeta.setPadding(0, dp(3), 0, dp(9));
        previewArea.addView(routeMeta);

        warningText = text("", 12, TEXT, Typeface.NORMAL);
        warningText.setPadding(dp(10), dp(9), dp(10), dp(9));
        warningText.setBackground(rounded(PANEL_2, 11));
        previewArea.addView(warningText);

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        actions.setPadding(0, dp(10), 0, 0);

        favoriteButton = darkButton("☆ Favoriet");
        favoriteButton.setOnClickListener(v -> toggleFavorite());
        actions.addView(favoriteButton, new LinearLayout.LayoutParams(0, dp(48), 0.42f));

        startButton = smallButton("Start navigatie");
        startButton.setOnClickListener(v -> startNavigation());
        LinearLayout.LayoutParams startLp = new LinearLayout.LayoutParams(0, dp(48), 0.58f);
        startLp.leftMargin = dp(8);
        actions.addView(startButton, startLp);

        previewArea.addView(actions);
        panel.addView(previewArea);

        LinearLayout.LayoutParams previewLp =
                (LinearLayout.LayoutParams) previewArea.getLayoutParams();
        previewLp.topMargin = dp(11);
        previewArea.setLayoutParams(previewLp);
    }

    private void buildNavigation(LinearLayout panel) {
        navArea = new LinearLayout(this);
        navArea.setOrientation(LinearLayout.VERTICAL);
        navArea.setVisibility(View.GONE);
        navArea.setPadding(dp(13), dp(13), dp(13), dp(13));
        navArea.setBackground(rounded(PANEL, 15));

        navStatus = text("NAVIGATIE ACTIEF", 10, GREEN, Typeface.BOLD);
        navArea.addView(navStatus);

        navDistance = text("—", 28, BLUE, Typeface.BOLD);
        navDistance.setPadding(0, dp(4), 0, 0);
        navArea.addView(navDistance);

        navInstruction = text("Volg de route", 21, TEXT, Typeface.BOLD);
        navInstruction.setPadding(0, 0, 0, dp(4));
        navArea.addView(navInstruction);

        navMeta = text("", 12, MUTED, Typeface.NORMAL);
        navArea.addView(navMeta);

        LinearLayout buttons = new LinearLayout(this);
        buttons.setOrientation(LinearLayout.HORIZONTAL);
        buttons.setPadding(0, dp(10), 0, 0);

        Button follow = darkButton("◎ Volgen");
        follow.setOnClickListener(v -> {
            followMode = true;
            centerOnMe();
        });
        buttons.addView(follow, new LinearLayout.LayoutParams(0, dp(46), 1f));

        Button arrived = darkButton("✓ Aangekomen");
        arrived.setOnClickListener(v -> arrive());
        LinearLayout.LayoutParams arrivedLp = new LinearLayout.LayoutParams(0, dp(46), 1f);
        arrivedLp.leftMargin = dp(7);
        buttons.addView(arrived, arrivedLp);

        Button stop = dangerButton("Stop");
        stop.setOnClickListener(v -> stopNavigation(false));
        LinearLayout.LayoutParams stopLp = new LinearLayout.LayoutParams(0, dp(46), 0.72f);
        stopLp.leftMargin = dp(7);
        buttons.addView(stop, stopLp);

        navArea.addView(buttons);
        panel.addView(navArea);

        LinearLayout.LayoutParams navLp =
                (LinearLayout.LayoutParams) navArea.getLayoutParams();
        navLp.topMargin = dp(10);
        navArea.setLayoutParams(navLp);
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
                    LocationManager.GPS_PROVIDER, 1000L, 2f, this);
            locationManager.requestLocationUpdates(
                    LocationManager.NETWORK_PROVIDER, 2500L, 8f, this);

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
                gpsStatus.setText("Locatietoegang geweigerd.");
            }
        }
    }

    @Override
    public void onLocationChanged(Location location) {
        if (currentLocation != null
                && location.getAccuracy() > 80
                && currentLocation.getAccuracy() < location.getAccuracy()) {
            return;
        }

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
        double kmh = location.hasSpeed() ? Math.max(0, location.getSpeed() * 3.6) : 0;
        gpsStatus.setText(String.format(NL,
                "GPS ±%d m • %.0f km/u", accuracy, kmh));

        if (!centeredOnce) {
            centeredOnce = true;
            map.getController().setZoom(16.0);
            map.getController().animateTo(point);
        }

        if (navigating) updateNavigationProgress(location);
        map.invalidate();
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
        setRouteLoading(true);
        routeTitle.setText("Bestemming zoeken…");
        previewArea.setVisibility(View.VISIBLE);

        new Thread(() -> {
            try {
                OnlineServices.SearchResult destination = OnlineServices.searchPlace(query);
                calculateRouteInWorker(destination, false);
            } catch (Exception e) {
                showRouteError(e);
            }
        }).start();
    }

    private void routeToStored(DestinationStore.Item item) {
        if (currentLocation == null) {
            Toast.makeText(this, "Wacht eerst op je GPS-positie.", Toast.LENGTH_SHORT).show();
            return;
        }
        destinationInput.setText(shortLabel(item.label));
        setRouteLoading(true);
        OnlineServices.SearchResult destination =
                new OnlineServices.SearchResult(item.lat, item.lon, item.label);

        new Thread(() -> {
            try {
                calculateRouteInWorker(destination, false);
            } catch (Exception e) {
                showRouteError(e);
            }
        }).start();
    }

    private void calculateRouteInWorker(OnlineServices.SearchResult destination,
                                        boolean isReroute) throws Exception {
        List<OnlineServices.RouteResult> candidates = OnlineServices.routeCandidates(
                currentLocation.getLatitude(),
                currentLocation.getLongitude(),
                destination.lat,
                destination.lon
        );

        OnlineServices.RouteResult selected = candidates.get(0);
        String scanNote = "";

        try {
            selected.restrictions = OnlineServices.scanRestrictions(selected, vehicle);

            if (selected.criticalCount() > 0 && candidates.size() > 1) {
                OnlineServices.RouteResult alternative = candidates.get(1);
                alternative.restrictions = OnlineServices.scanRestrictions(alternative, vehicle);
                OnlineServices.RouteResult safer =
                        OnlineServices.chooseSafer(selected, alternative);

                if (safer == alternative) {
                    safer.selectionNote = "Alternatieve route gekozen na voertuigscan.";
                    selected = safer;
                }

                if (selected.criticalCount() > 0 && candidates.size() > 2) {
                    OnlineServices.RouteResult third = candidates.get(2);
                    third.restrictions = OnlineServices.scanRestrictions(third, vehicle);
                    OnlineServices.RouteResult saferAgain =
                            OnlineServices.chooseSafer(selected, third);
                    if (saferAgain == third) {
                        saferAgain.selectionNote = "Derde route gekozen na voertuigscan.";
                        selected = saferAgain;
                    }
                }
            }
        } catch (Exception scanError) {
            scanNote = "Voertuigscan tijdelijk niet volledig beschikbaar.";
        }

        final OnlineServices.RouteResult finalRoute = selected;
        final String finalScanNote = scanNote;

        runOnUiThread(() -> {
            currentDestination = destination;
            currentRoute = finalRoute;
            DestinationStore.addRecent(this, destination.toStoredItem());
            refreshSavedPlaces();

            if (isReroute && navigating) {
                applyReroute(finalScanNote);
            } else {
                showRoutePreview(finalScanNote);
            }
            setRouteLoading(false);
        });
    }

    private void showRoutePreview(String scanNote) {
        drawCurrentRoute();
        showDestinationMarker();
        fitRoute(currentRoute.points);

        double km = currentRoute.distanceMeters / 1000.0;
        int minutes = (int) Math.round(currentRoute.durationSeconds / 60.0);

        routeTitle.setText(String.format(NL, "%.1f km • %d min", km, minutes));

        String meta = shortLabel(currentDestination.label);
        if (!currentRoute.selectionNote.isEmpty()) {
            meta += " • " + currentRoute.selectionNote;
        }
        routeMeta.setText(meta);

        warningText.setText(buildRestrictionSummary(scanNote));
        warningText.setTextColor(restrictionSummaryColor());

        favoriteButton.setText(
                DestinationStore.isFavorite(this, currentDestination.toStoredItem())
                        ? "★ Favoriet" : "☆ Favoriet"
        );

        previewArea.setVisibility(View.VISIBLE);
        startButton.setEnabled(true);
    }

    private String buildRestrictionSummary(String scanNote) {
        int critical = currentRoute == null ? 0 : currentRoute.criticalCount();
        int caution = currentRoute == null ? 0 : currentRoute.cautionCount();
        int info = 0;
        if (currentRoute != null) {
            for (OnlineServices.Restriction r : currentRoute.restrictions) {
                if (r.informational) info++;
            }
        }

        StringBuilder sb = new StringBuilder();

        if (critical == 0 && caution == 0) {
            sb.append("✓ Geen kritieke maat-, gewicht- of bussluisbeperking gevonden in de online routescan.");
        } else {
            sb.append("⚠ ").append(critical).append(" kritisch • ")
                    .append(caution).append(" aandachtspunt");
            if (caution != 1) sb.append("en");

            int shown = 0;
            for (OnlineServices.Restriction r : currentRoute.restrictions) {
                if (r.informational) continue;
                if (shown >= 3) break;
                sb.append("\n• ").append(r.type).append(": ").append(r.description);
                shown++;
            }
        }

        if (info > 0) {
            sb.append("\n• ").append(info)
                    .append(" busbaan/bustoegang als toegestaan profielsignaal gevonden.");
        }
        if (scanNote != null && !scanNote.isEmpty()) {
            sb.append("\n").append(scanNote);
        }
        sb.append("\nControleer ter plaatse altijd de bebording.");
        return sb.toString();
    }

    private int restrictionSummaryColor() {
        if (currentRoute == null) return MUTED;
        if (currentRoute.criticalCount() > 0) return RED;
        if (currentRoute.cautionCount() > 0) return ORANGE;
        return GREEN;
    }

    private void startNavigation() {
        if (currentRoute == null || currentDestination == null || currentLocation == null) return;

        navigating = true;
        followMode = true;
        announcedApproachSteps.clear();
        announcedNearSteps.clear();
        announcedRestrictions.clear();
        currentStepIndex = currentRoute.steps.size() > 1 ? 1 : 0;
        lastRerouteMs = System.currentTimeMillis();

        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        searchArea.setVisibility(View.GONE);
        savedPlaces.setVisibility(View.GONE);
        previewArea.setVisibility(View.GONE);
        navArea.setVisibility(View.VISIBLE);

        map.getController().setZoom(17.0);
        centerOnMe();

        speak("Navigatie gestart. RoutePilot bewaakt de route en voertuigbeperkingen.");
        updateNavigationProgress(currentLocation);
    }

    private void updateNavigationProgress(Location location) {
        if (!navigating || currentRoute == null || currentDestination == null) return;

        double lat = location.getLatitude();
        double lon = location.getLongitude();
        int routeIndex = OnlineServices.closestRoutePointIndex(lat, lon, currentRoute.points);
        double offRoute = OnlineServices.distanceFromRouteMeters(lat, lon, currentRoute.points);

        if (offRoute > 110
                && !routeLoading
                && System.currentTimeMillis() - lastRerouteMs > 18000) {
            lastRerouteMs = System.currentTimeMillis();
            navStatus.setText("HERROUTEREN…");
            speak("Je bent van de route afgeweken. Nieuwe route wordt berekend.");
            setRouteLoading(true);

            new Thread(() -> {
                try {
                    calculateRouteInWorker(currentDestination, true);
                } catch (Exception e) {
                    runOnUiThread(() -> {
                        navStatus.setText("NAVIGATIE ACTIEF • herrouteren mislukt");
                        setRouteLoading(false);
                    });
                }
            }).start();
        }

        double remaining = OnlineServices.remainingRouteDistanceMeters(
                Math.max(0, routeIndex), currentRoute.points);
        double remainingSeconds = currentRoute.distanceMeters > 1
                ? currentRoute.durationSeconds * (remaining / currentRoute.distanceMeters)
                : 0;

        Date eta = new Date(System.currentTimeMillis() + (long) (remainingSeconds * 1000));
        String etaText = new SimpleDateFormat("HH:mm", NL).format(eta);

        double kmh = location.hasSpeed() ? Math.max(0, location.getSpeed() * 3.6) : 0;
        navMeta.setText(String.format(NL,
                "%.1f km resterend • aankomst %s • %.0f km/u",
                remaining / 1000.0, etaText, kmh));

        updateStepGuidance(lat, lon);
        updateRestrictionWarnings(lat, lon);

        double toDestination = OnlineServices.distanceMeters(
                lat, lon, currentDestination.lat, currentDestination.lon);
        if (toDestination < 35) {
            arrive();
            return;
        }

        if (followMode) {
            map.getController().animateTo(new GeoPoint(lat, lon));
            if (map.getZoomLevelDouble() < 16.0) map.getController().setZoom(17.0);
        }
    }

    private void updateStepGuidance(double lat, double lon) {
        if (currentRoute.steps == null || currentRoute.steps.isEmpty()) {
            navDistance.setText("—");
            navInstruction.setText("Volg de route");
            return;
        }

        currentStepIndex = Math.min(currentStepIndex, currentRoute.steps.size() - 1);
        OnlineServices.NavStep step = currentRoute.steps.get(currentStepIndex);
        double distance = OnlineServices.distanceMeters(lat, lon, step.lat, step.lon);

        while (distance < 32 && currentStepIndex < currentRoute.steps.size() - 1) {
            currentStepIndex++;
            step = currentRoute.steps.get(currentStepIndex);
            distance = OnlineServices.distanceMeters(lat, lon, step.lat, step.lon);
        }

        navDistance.setText(formatDistance(distance));
        navInstruction.setText(step.instruction);

        if (distance < 380 && !announcedApproachSteps.contains(currentStepIndex)) {
            announcedApproachSteps.add(currentStepIndex);
            speak("Over " + spokenDistance(distance) + ". " + step.instruction);
        }

        if (distance < 95 && !announcedNearSteps.contains(currentStepIndex)) {
            announcedNearSteps.add(currentStepIndex);
            speak(step.instruction);
        }
    }

    private void updateRestrictionWarnings(double lat, double lon) {
        if (currentRoute.restrictions == null) return;

        for (int i = 0; i < currentRoute.restrictions.size(); i++) {
            if (announcedRestrictions.contains(i)) continue;
            OnlineServices.Restriction r = currentRoute.restrictions.get(i);
            if (r.informational) continue;

            double d = OnlineServices.distanceMeters(lat, lon, r.lat, r.lon);
            if (d < 420) {
                announcedRestrictions.add(i);
                if (r.critical) {
                    navStatus.setText("⚠ KRITIEKE VOERTUIGWAARSCHUWING");
                    speak("Let op. " + r.type + ". " + r.description
                            + " Controleer de bebording.");
                } else {
                    speak("Let op. Aandachtspunt voor " + r.type + ". "
                            + r.description);
                }
            }
        }
    }

    private void applyReroute(String scanNote) {
        drawCurrentRoute();
        showDestinationMarker();
        announcedApproachSteps.clear();
        announcedNearSteps.clear();
        announcedRestrictions.clear();
        currentStepIndex = currentRoute.steps.size() > 1 ? 1 : 0;
        navStatus.setText("NAVIGATIE ACTIEF • route bijgewerkt");

        if (currentRoute.criticalCount() > 0) {
            navStatus.setText("⚠ ROUTE HEEFT VOERTUIGWAARSCHUWING");
        }

        speak("Nieuwe route geladen.");
        updateNavigationProgress(currentLocation);
    }

    private void arrive() {
        if (!navigating) return;
        speak("Bestemming bereikt.");
        stopNavigation(true);

        String liftText = vehicle.rearLift
                ? "Achterliftmodus:\n\n• Zoek een zo vlak mogelijke stopplek.\n"
                + "• Houd vrije ruimte achter de bus.\n"
                + "• Controleer fietspad, paaltjes, stoeprand en verkeer.\n"
                + "• Zet de bus veilig stil vóór je de lift bedient."
                : "Controleer een veilige uitstapplaats en voldoende vrije ruimte.";

        new AlertDialog.Builder(this)
                .setTitle("Bestemming bereikt")
                .setMessage(liftText)
                .setPositiveButton("Klaar", null)
                .show();
    }

    private void stopNavigation(boolean keepRoute) {
        navigating = false;
        followMode = false;
        getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        navArea.setVisibility(View.GONE);
        searchArea.setVisibility(View.VISIBLE);
        savedPlaces.setVisibility(View.VISIBLE);

        if (keepRoute && currentRoute != null) {
            previewArea.setVisibility(View.VISIBLE);
        } else {
            previewArea.setVisibility(currentRoute == null ? View.GONE : View.VISIBLE);
        }

        navStatus.setText("NAVIGATIE ACTIEF");
    }

    private void drawCurrentRoute() {
        if (routeLine != null) map.getOverlays().remove(routeLine);
        for (Marker m : restrictionMarkers) map.getOverlays().remove(m);
        restrictionMarkers.clear();

        routeLine = new Polyline(map);
        routeLine.setPoints(currentRoute.points);
        routeLine.getOutlinePaint().setColor(BLUE);
        routeLine.getOutlinePaint().setStrokeWidth(dp(7));
        map.getOverlays().add(routeLine);

        for (OnlineServices.Restriction r : currentRoute.restrictions) {
            Marker marker = new Marker(map);
            marker.setPosition(new GeoPoint(r.lat, r.lon));
            marker.setTitle(r.type);
            marker.setSnippet(r.description);
            marker.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
            restrictionMarkers.add(marker);
            map.getOverlays().add(marker);
        }
        map.invalidate();
    }

    private void showDestinationMarker() {
        if (currentDestination == null) return;
        if (destinationMarker == null) {
            destinationMarker = new Marker(map);
            destinationMarker.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
            map.getOverlays().add(destinationMarker);
        }
        destinationMarker.setPosition(
                new GeoPoint(currentDestination.lat, currentDestination.lon));
        destinationMarker.setTitle(shortLabel(currentDestination.label));
        map.invalidate();
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
            map.zoomToBoundingBox(
                    new BoundingBox(north, east, south, west),
                    true, dp(55));
        } catch (Exception ignored) {
            map.getController().animateTo(points.get(points.size() / 2));
        }
    }

    private void refreshSavedPlaces() {
        if (savedPlaces == null) return;
        savedPlaces.removeAllViews();

        List<DestinationStore.Item> favorites = DestinationStore.favorites(this);
        List<DestinationStore.Item> recent = DestinationStore.recent(this);

        int count = 0;
        for (DestinationStore.Item item : favorites) {
            if (count >= 5) break;
            savedPlaces.addView(placeButton("★ " + compactPlace(item.label), item));
            count++;
        }

        for (DestinationStore.Item item : recent) {
            if (count >= 8) break;
            boolean duplicate = false;
            for (DestinationStore.Item favorite : favorites) {
                if (Math.abs(favorite.lat - item.lat) < 0.00001
                        && Math.abs(favorite.lon - item.lon) < 0.00001) {
                    duplicate = true;
                    break;
                }
            }
            if (duplicate) continue;
            savedPlaces.addView(placeButton("↺ " + compactPlace(item.label), item));
            count++;
        }

        if (count == 0) {
            TextView empty = text("Nog geen bestemmingen", 11, MUTED, Typeface.NORMAL);
            empty.setGravity(Gravity.CENTER_VERTICAL);
            savedPlaces.addView(empty, new LinearLayout.LayoutParams(dp(180), dp(38)));
        }
    }

    private View placeButton(String label, DestinationStore.Item item) {
        Button b = darkButton(label);
        b.setTextSize(11);
        b.setOnClickListener(v -> routeToStored(item));
        b.setOnLongClickListener(v -> {
            boolean added = DestinationStore.toggleFavorite(this, item);
            Toast.makeText(this,
                    added ? "Toegevoegd aan favorieten" : "Verwijderd uit favorieten",
                    Toast.LENGTH_SHORT).show();
            refreshSavedPlaces();
            return true;
        });

        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, dp(36));
        lp.rightMargin = dp(6);
        b.setLayoutParams(lp);
        return b;
    }

    private void toggleFavorite() {
        if (currentDestination == null) return;
        boolean added = DestinationStore.toggleFavorite(
                this, currentDestination.toStoredItem());
        favoriteButton.setText(added ? "★ Favoriet" : "☆ Favoriet");
        refreshSavedPlaces();
        Toast.makeText(this,
                added ? "Favoriet opgeslagen" : "Favoriet verwijderd",
                Toast.LENGTH_SHORT).show();
    }

    private void showVehicleSettings() {
        ScrollView sv = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(8), dp(20), dp(8));
        sv.addView(box);

        EditText length = numberField("Lengte (m)", vehicle.lengthM);
        EditText height = numberField("Hoogte (m)", vehicle.heightM);
        EditText width = numberField("Breedte (m)", vehicle.widthM);
        EditText weight = numberField("Max. massa (ton)", vehicle.maxWeightT);

        box.addView(length);
        box.addView(height);
        box.addView(width);
        box.addView(weight);

        CheckBox rearLift = new CheckBox(this);
        rearLift.setText("Achterlift");
        rearLift.setChecked(vehicle.rearLift);
        box.addView(rearLift);

        CheckBox busLane = new CheckBox(this);
        busLane.setText("Busbaanvrijstelling actief");
        busLane.setChecked(vehicle.busLaneExemption);
        box.addView(busLane);

        TextView note = text(
                "Bussluizen worden ook met busbaanvrijstelling als kritisch behandeld.",
                11, Color.DKGRAY, Typeface.NORMAL);
        note.setPadding(0, dp(8), 0, 0);
        box.addView(note);

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Voertuigprofiel")
                .setView(sv)
                .setNegativeButton("Annuleren", null)
                .setPositiveButton("Opslaan", null)
                .create();

        dialog.setOnShowListener(d -> dialog.getButton(AlertDialog.BUTTON_POSITIVE)
                .setOnClickListener(v -> {
                    try {
                        double l = parseNumber(length.getText().toString());
                        double h = parseNumber(height.getText().toString());
                        double w = parseNumber(width.getText().toString());
                        double m = parseNumber(weight.getText().toString());

                        if (l < 2 || h < 1.5 || w < 1.5 || m < 0.5) {
                            throw new IllegalArgumentException();
                        }

                        vehicle.lengthM = l;
                        vehicle.heightM = h;
                        vehicle.widthM = w;
                        vehicle.maxWeightT = m;
                        vehicle.rearLift = rearLift.isChecked();
                        vehicle.busLaneExemption = busLane.isChecked();
                        vehicle.save(this);
                        refreshVehicleSummary();
                        dialog.dismiss();

                        Toast.makeText(this,
                                "Voertuigprofiel opgeslagen",
                                Toast.LENGTH_SHORT).show();
                    } catch (Exception e) {
                        Toast.makeText(this,
                                "Controleer de ingevoerde voertuigwaarden.",
                                Toast.LENGTH_LONG).show();
                    }
                }));

        dialog.show();
    }

    private EditText numberField(String label, double value) {
        EditText e = new EditText(this);
        e.setHint(label);
        e.setText(String.format(Locale.US, "%.2f", value));
        e.setInputType(android.text.InputType.TYPE_CLASS_NUMBER
                | android.text.InputType.TYPE_NUMBER_FLAG_DECIMAL);
        e.setSingleLine(true);
        return e;
    }

    private void refreshVehicleSummary() {
        vehicleSummary.setText(String.format(NL,
                "🚐 %.2f m lang • %.2f m hoog • %.2f m breed • %.2f t%s",
                vehicle.lengthM, vehicle.heightM, vehicle.widthM, vehicle.maxWeightT,
                vehicle.busLaneExemption ? " • busbaanvrijstelling" : ""));
    }

    private void centerOnMe() {
        if (currentLocation == null) {
            Toast.makeText(this, "Nog geen GPS-positie.", Toast.LENGTH_SHORT).show();
            startLocation();
            return;
        }
        map.getController().setZoom(navigating ? 17.0 : 16.5);
        map.getController().animateTo(new GeoPoint(
                currentLocation.getLatitude(),
                currentLocation.getLongitude()));
    }

    private void setRouteLoading(boolean loading) {
        routeLoading = loading;
        routeButton.setEnabled(!loading);
        routeButton.setText(loading ? "…" : "Route");
        if (loading && !navigating) {
            previewArea.setVisibility(View.VISIBLE);
            routeMeta.setText("Online route en voertuigscan worden geladen…");
            startButton.setEnabled(false);
        }
    }

    private void showRouteError(Exception e) {
        runOnUiThread(() -> {
            setRouteLoading(false);
            routeTitle.setText("Route niet geladen");
            routeMeta.setText(e.getMessage() == null ? "Onbekende fout." : e.getMessage());
            warningText.setText("Controleer internet, GPS en de bestemming.");
            warningText.setTextColor(RED);
            Toast.makeText(this, routeMeta.getText(), Toast.LENGTH_LONG).show();
        });
    }

    private String compactPlace(String label) {
        if (label == null) return "Bestemming";
        String[] p = label.split(",");
        return p.length == 0 ? label : p[0].trim();
    }

    private String shortLabel(String label) {
        if (label == null) return "";
        String[] p = label.split(",");
        if (p.length <= 2) return label.trim();
        return p[0].trim() + ", " + p[1].trim();
    }

    private String formatDistance(double meters) {
        if (meters < 1000) {
            int rounded = meters < 100
                    ? (int) (Math.round(meters / 10.0) * 10)
                    : (int) (Math.round(meters / 50.0) * 50);
            return rounded + " m";
        }
        return String.format(NL, "%.1f km", meters / 1000.0);
    }

    private String spokenDistance(double meters) {
        if (meters < 1000) return formatDistance(meters);
        return String.format(NL, "%.1f kilometer", meters / 1000.0);
    }

    private double parseNumber(String value) {
        return Double.parseDouble(value.trim().replace(',', '.'));
    }

    private void hideKeyboard() {
        View v = getCurrentFocus();
        if (v == null) v = destinationInput;
        InputMethodManager imm =
                (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) imm.hideSoftInputFromWindow(v.getWindowToken(), 0);
    }

    private void speak(String message) {
        if (tts != null && message != null && !message.trim().isEmpty()) {
            tts.speak(message, TextToSpeech.QUEUE_FLUSH, null, "routepilot-v1");
        }
    }

    @Override
    public void onInit(int status) {
        if (status == TextToSpeech.SUCCESS && tts != null) {
            tts.setLanguage(NL);
            tts.setSpeechRate(0.96f);
        }
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

    private Button darkButton(String label) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(12);
        b.setTextColor(TEXT);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setAllCaps(false);
        b.setBackground(rounded(PANEL_2, 13));
        return b;
    }

    private Button dangerButton(String label) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(12);
        b.setTextColor(TEXT);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setAllCaps(false);
        b.setBackground(rounded(Color.rgb(105, 32, 42), 13));
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
        android.graphics.drawable.GradientDrawable d =
                new android.graphics.drawable.GradientDrawable();
        d.setColor(color);
        d.setCornerRadius(dp(radiusDp));
        return d;
    }

    private int dp(int dp) {
        return Math.round(dp * getResources().getDisplayMetrics().density);
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
}
