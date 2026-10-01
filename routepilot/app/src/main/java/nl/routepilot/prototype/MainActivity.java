package nl.routepilot.prototype;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
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

import org.json.JSONArray;
import org.osmdroid.config.Configuration;
import org.osmdroid.tileprovider.tilesource.TileSourceFactory;
import org.osmdroid.util.BoundingBox;
import org.osmdroid.util.GeoPoint;
import org.osmdroid.views.MapView;
import org.osmdroid.views.overlay.CopyrightOverlay;
import org.osmdroid.views.overlay.Marker;
import org.osmdroid.views.overlay.Polyline;
import org.osmdroid.views.overlay.TilesOverlay;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;

public class MainActivity extends Activity implements LocationListener, TextToSpeech.OnInitListener {

    private static final int LOCATION_REQUEST = 2002;
    private static final int NOTIFICATION_REQUEST = 2003;
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
    private Polyline replayLine;
    private final List<Marker> routeMarkers = new ArrayList<>();

    private LocationManager locationManager;
    private Location currentLocation;
    private TextToSpeech tts;
    private VehicleProfile vehicle;

    private OnlineServices.SearchResult currentDestination;
    private OnlineServices.RouteResult currentRoute;
    private RouteAnalysis.Result currentAnalysis;
    private RouteConfidence.Result currentConfidence;
    private ArrivalEngine.Result currentArrival;
    private DestinationAccessService.Result currentDestinationAccess;

    private boolean centeredOnce = false;
    private boolean navigating = false;
    private boolean followMode = true;
    private boolean routeLoading = false;
    private boolean learnedThisDeviation = false;

    private long lastRerouteMs = 0L;
    private long lastTrafficRefreshMs = 0L;
    private int currentStepIndex = 0;
    private int currentSpeedLimit = -1;
    private double currentStepDistance = 0;
    private String currentInstruction = "Volg de route";
    private String currentWarning = "";
    private LookAheadEngine.Result currentLookAhead = new LookAheadEngine.Result();

    private final Handler uiHandler = new Handler(Looper.getMainLooper());
    private boolean waitMinuteAnnounced = false;
    private boolean waitExpiredAnnounced = false;

    private final Set<Integer> announcedApproachSteps = new HashSet<>();
    private final Set<Integer> announcedNearSteps = new HashSet<>();
    private final Set<Integer> announcedRestrictions = new HashSet<>();
    private final Set<Integer> announcedTrafficEvents = new HashSet<>();
    private final Set<String> announcedRoadSigns = new HashSet<>();

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
    private TextView navSpeed;
    private TextView navScore;
    private TextView lookAheadText;
    private TextView arrivalText;
    private TextView confidenceText;
    private TextView wmoPhaseText;
    private TextView waitTimerText;
    private Button routeButton;
    private Button startButton;
    private Button favoriteButton;
    private Button boardedButton;
    private Button noShowButton;
    private Button tripDoneButton;

    private LinearLayout searchArea;
    private LinearLayout previewArea;
    private LinearLayout navArea;
    private LinearLayout savedPlaces;
    private LinearLayout wmoArea;

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
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 0.54f);
        root.addView(map, mapLp);

        ScrollView scroll = new ScrollView(this);
        LinearLayout panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setPadding(dp(16), dp(12), dp(16), dp(24));
        panel.setBackgroundColor(BG);
        scroll.addView(panel);

        LinearLayout.LayoutParams scrollLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 0.46f);
        root.addView(scroll, scrollLp);

        buildHeader(panel);
        buildSearch(panel);
        buildSavedPlaces(panel);
        buildPreview(panel);
        buildNavigation(panel);
        buildWmoPanel(panel);

        TextView disclaimer = text(
                "RoutePilot V2 debug • OSM/OSRM/NDW/PDOK • fysieke bebording en actuele afzettingen blijven leidend.",
                10, MUTED, Typeface.NORMAL);
        disclaimer.setGravity(Gravity.CENTER);
        disclaimer.setPadding(0, dp(12), 0, 0);
        panel.addView(disclaimer);

        setContentView(root);
        applyAutoNightMode();
        refreshVehicleSummary();
        refreshSavedPlaces();
        startLocation();
        requestNotificationPermissionIfNeeded();
        handleNavigationIntent(getIntent());
        updateWmoPanel();
        uiHandler.post(waitTicker);
    }

    private void buildHeader(LinearLayout panel) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout titles = new LinearLayout(this);
        titles.setOrientation(LinearLayout.VERTICAL);
        titles.addView(text("ROUTEPILOT", 14, BLUE, Typeface.BOLD));
        titles.addView(text("V2 debug • rolstoelbus-navigatie", 11, MUTED, Typeface.NORMAL));
        row.addView(titles, new LinearLayout.LayoutParams(0, dp(48), 1f));

        Button dashboard = darkButton("▦");
        dashboard.setContentDescription("Dashboard");
        dashboard.setOnClickListener(v ->
                startActivity(new Intent(this, DashboardActivity.class)));
        row.addView(dashboard, new LinearLayout.LayoutParams(dp(48), dp(40)));

        Button replay = darkButton("↺");
        replay.setContentDescription("Laatste rit replay");
        replay.setOnClickListener(v -> replayLatestTrip());
        LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(dp(48), dp(40));
        rp.leftMargin = dp(5);
        row.addView(replay, rp);

        Button settings = darkButton("⚙");
        settings.setOnClickListener(v -> showVehicleSettings());
        LinearLayout.LayoutParams sp = new LinearLayout.LayoutParams(dp(48), dp(40));
        sp.leftMargin = dp(5);
        row.addView(settings, sp);

        panel.addView(row);

        gpsStatus = text("Locatie wordt gestart…", 11, MUTED, Typeface.NORMAL);
        gpsStatus.setPadding(0, 0, 0, dp(6));
        panel.addView(gpsStatus);

        vehicleSummary = text("", 11, GREEN, Typeface.BOLD);
        vehicleSummary.setPadding(0, 0, 0, dp(8));
        panel.addView(vehicleSummary);
    }

    private void buildSearch(LinearLayout panel) {
        searchArea = new LinearLayout(this);
        searchArea.setOrientation(LinearLayout.VERTICAL);

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);

        destinationInput = new EditText(this);
        destinationInput.setHint("Adres, woning, zorglocatie of plaats");
        destinationInput.setHintTextColor(MUTED);
        destinationInput.setTextColor(TEXT);
        destinationInput.setTextSize(15);
        destinationInput.setSingleLine(true);
        destinationInput.setPadding(dp(13), 0, dp(10), 0);
        destinationInput.setBackground(rounded(PANEL, 13));

        routeButton = smallButton("Route");
        routeButton.setOnClickListener(v -> searchAndRoute());

        row.addView(destinationInput, new LinearLayout.LayoutParams(0, dp(50), 1f));
        LinearLayout.LayoutParams bp = new LinearLayout.LayoutParams(dp(88), dp(50));
        bp.leftMargin = dp(8);
        row.addView(routeButton, bp);

        searchArea.addView(row);
        panel.addView(searchArea);
    }

    private void buildSavedPlaces(LinearLayout panel) {
        TextView label = text("FAVORIETEN & RECENT", 10, MUTED, Typeface.BOLD);
        label.setPadding(0, dp(10), 0, dp(4));
        panel.addView(label);

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

        LinearLayout row1 = new LinearLayout(this);
        row1.setOrientation(LinearLayout.HORIZONTAL);
        row1.setPadding(0, dp(9), 0, 0);

        Button why = darkButton("Waarom deze route?");
        why.setOnClickListener(v -> showWhyRoute());
        row1.addView(why, new LinearLayout.LayoutParams(0, dp(44), 1f));

        Button profile = darkButton("Locatieprofiel");
        profile.setOnClickListener(v -> showLocationProfileDialog());
        LinearLayout.LayoutParams pp = new LinearLayout.LayoutParams(0, dp(44), 1f);
        pp.leftMargin = dp(7);
        row1.addView(profile, pp);
        previewArea.addView(row1);

        LinearLayout row2 = new LinearLayout(this);
        row2.setOrientation(LinearLayout.HORIZONTAL);
        row2.setPadding(0, dp(8), 0, 0);

        favoriteButton = darkButton("☆ Favoriet");
        favoriteButton.setOnClickListener(v -> toggleFavorite());
        row2.addView(favoriteButton, new LinearLayout.LayoutParams(0, dp(48), 0.42f));

        startButton = smallButton("Start navigatie");
        startButton.setOnClickListener(v -> startNavigation());
        LinearLayout.LayoutParams startLp = new LinearLayout.LayoutParams(0, dp(48), 0.58f);
        startLp.leftMargin = dp(8);
        row2.addView(startButton, startLp);
        previewArea.addView(row2);

        panel.addView(previewArea);
        LinearLayout.LayoutParams lp = (LinearLayout.LayoutParams) previewArea.getLayoutParams();
        lp.topMargin = dp(10);
        previewArea.setLayoutParams(lp);
    }

    private void buildNavigation(LinearLayout panel) {
        navArea = new LinearLayout(this);
        navArea.setOrientation(LinearLayout.VERTICAL);
        navArea.setVisibility(View.GONE);
        navArea.setPadding(dp(14), dp(13), dp(14), dp(13));
        navArea.setBackground(rounded(PANEL, 15));

        navStatus = text("NAVIGATIE ACTIEF", 11, GREEN, Typeface.BOLD);
        navArea.addView(navStatus);

        navDistance = text("—", 31, BLUE, Typeface.BOLD);
        navDistance.setPadding(0, dp(3), 0, 0);
        navArea.addView(navDistance);

        navInstruction = text("Volg de route", 22, TEXT, Typeface.BOLD);
        navInstruction.setPadding(0, 0, 0, dp(5));
        navArea.addView(navInstruction);

        LinearLayout gauges = new LinearLayout(this);
        gauges.setOrientation(LinearLayout.HORIZONTAL);

        navSpeed = text("MAX —", 16, TEXT, Typeface.BOLD);
        navSpeed.setGravity(Gravity.CENTER);
        navSpeed.setPadding(dp(8), dp(7), dp(8), dp(7));
        navSpeed.setBackground(rounded(PANEL_2, 10));
        gauges.addView(navSpeed, new LinearLayout.LayoutParams(0, dp(42), 1f));

        navScore = text("ROUTE —", 13, GREEN, Typeface.BOLD);
        navScore.setGravity(Gravity.CENTER);
        navScore.setPadding(dp(8), dp(7), dp(8), dp(7));
        navScore.setBackground(rounded(PANEL_2, 10));
        LinearLayout.LayoutParams gp = new LinearLayout.LayoutParams(0, dp(42), 1f);
        gp.leftMargin = dp(7);
        gauges.addView(navScore, gp);
        navArea.addView(gauges);

        navMeta = text("", 12, MUTED, Typeface.NORMAL);
        navMeta.setPadding(0, dp(7), 0, 0);
        navArea.addView(navMeta);

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setPadding(0, dp(9), 0, 0);

        Button follow = darkButton("◎ Volgen");
        follow.setOnClickListener(v -> { followMode = true; centerOnMe(); });
        row.addView(follow, new LinearLayout.LayoutParams(0, dp(46), 1f));

        Button report = darkButton("⚠ Meld");
        report.setOnClickListener(v -> showDriverReportDialog());
        LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(0, dp(46), 1f);
        rp.leftMargin = dp(6);
        row.addView(report, rp);

        Button arrived = darkButton("✓ Aankomst");
        arrived.setOnClickListener(v -> arrive());
        LinearLayout.LayoutParams ap = new LinearLayout.LayoutParams(0, dp(46), 1f);
        ap.leftMargin = dp(6);
        row.addView(arrived, ap);

        Button stop = dangerButton("Stop");
        stop.setOnClickListener(v -> stopNavigation(false));
        LinearLayout.LayoutParams stp = new LinearLayout.LayoutParams(0, dp(46), 0.72f);
        stp.leftMargin = dp(6);
        row.addView(stop, stp);

        navArea.addView(row);
        panel.addView(navArea);
        LinearLayout.LayoutParams navLp = (LinearLayout.LayoutParams) navArea.getLayoutParams();
        navLp.topMargin = dp(9);
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
            locationManager.requestLocationUpdates(LocationManager.GPS_PROVIDER, 1000L, 2f, this);
            locationManager.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 2500L, 8f, this);
            Location last = locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER);
            if (last == null) last = locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER);
            if (last != null) onLocationChanged(last);
        } catch (Exception e) {
            gpsStatus.setText("Kon locatie niet starten: " + e.getMessage());
        }
    }

    private void requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, NOTIFICATION_REQUEST);
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(requestCode, permissions, results);
        if (requestCode == LOCATION_REQUEST) {
            if (results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED) startLocation();
            else gpsStatus.setText("Locatietoegang geweigerd.");
        }
    }

    @Override
    public void onLocationChanged(Location location) {
        if (currentLocation != null
                && location.getAccuracy() > 80
                && currentLocation.getAccuracy() < location.getAccuracy()) return;

        currentLocation = location;
        RoutePilotState.updatePosition(this, location.getLatitude(), location.getLongitude());

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
        gpsStatus.setText(String.format(NL, "GPS ±%d m • %.0f km/u%s",
                accuracy, kmh, MunicipalityService.isInTilburg(
                        location.getLatitude(), location.getLongitude())
                        ? " • Tilburg-profiel" : ""));

        if (!centeredOnce) {
            centeredOnce = true;
            map.getController().setZoom(16.0);
            map.getController().animateTo(point);
        }

        if (navigating) {
            RoutePilotStore.appendTrack(this, location.getLatitude(), location.getLongitude(),
                    location.hasSpeed() ? location.getSpeed() : 0f);
            updateNavigationProgress(location);
        }
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
        previewArea.setVisibility(View.VISIBLE);
        routeTitle.setText("RoutePilot analyseert…");

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
            try { calculateRouteInWorker(destination, false); }
            catch (Exception e) { showRouteError(e); }
        }).start();
    }

    private void calculateRouteInWorker(OnlineServices.SearchResult destination,
                                        boolean isReroute) throws Exception {
        if (currentLocation == null) throw new IllegalStateException("Geen GPS-positie.");

        RouteCoordinator.Prepared prepared =
                RouteCoordinator.prepare(this, currentLocation, destination, vehicle);

        runOnUiThread(() -> {
            currentDestination = destination;
            currentRoute = prepared.route;
            currentAnalysis = prepared.analysis;
            DestinationStore.addRecent(this, destination.toStoredItem());
            refreshSavedPlaces();

            if (isReroute && navigating) applyReroute(prepared.note);
            else showRoutePreview(prepared.note);
            setRouteLoading(false);
        });
    }

    private void showRoutePreview(String note) {
        drawCurrentRoute();
        showDestinationMarker();
        fitRoute(currentRoute.points);

        double km = currentRoute.distanceMeters / 1000.0;
        int minutes = (int) Math.round(currentRoute.durationSeconds / 60.0);
        routeTitle.setText(String.format(NL, "%.1f km • %d min • %d/100 %s",
                km, minutes, currentAnalysis.score, currentAnalysis.label));

        String side = currentAnalysis.destinationOnRight
                ? "rechterdeur aan ingangzijde ✓"
                : "normale aankomstzijde • rechterdeurvoorkeur niet toegepast";
        String meta = shortLabel(currentDestination.label) + " • " + side;
        if (!currentRoute.selectionNote.isEmpty()) meta += "\n" + currentRoute.selectionNote;
        if (note != null && !note.isEmpty()) meta += "\n" + note;
        routeMeta.setText(meta);

        warningText.setText(buildRouteSummary());
        warningText.setTextColor(currentAnalysis.score < 58 ? RED
                : currentAnalysis.score < 82 ? ORANGE : GREEN);

        favoriteButton.setText(
                DestinationStore.isFavorite(this, currentDestination.toStoredItem())
                        ? "★ Favoriet" : "☆ Favoriet");
        previewArea.setVisibility(View.VISIBLE);
        startButton.setEnabled(true);
    }

    private String buildRouteSummary() {
        int live = currentRoute.trafficEvents == null ? 0 : currentRoute.trafficEvents.size();
        int signs = currentRoute.roadSigns == null ? 0 : currentRoute.roadSigns.size();
        StringBuilder b = new StringBuilder();

        if (currentRoute.liveClosureCount() > 0)
            b.append("🚧 ").append(currentRoute.liveClosureCount()).append(" actuele afsluiting(en) • ");
        b.append(currentRoute.criticalCount()).append(" kritieke beperking(en) • ")
                .append(currentRoute.cautionCount()).append(" aandachtspunt(en)");
        if (live > 0) b.append("\n📡 ").append(live).append(" actuele NDW-verkeersmelding(en)");
        if (signs > 0) b.append("\n🛑 ").append(signs).append(" officiële NDW-borden langs route");
        if (currentRoute.officialSpeeds != null && !currentRoute.officialSpeeds.isEmpty())
            b.append("\n🚦 WKD-wegvaksnelheden geladen (dag/nachtlaag)");

        int busInfo = 0;
        for (OnlineServices.Restriction r : currentRoute.restrictions)
            if ("BUSBAAN".equals(r.type) && r.informational) busInfo++;
        if (busInfo > 0)
            b.append("\n🚌 ").append(busInfo)
                    .append(" busbaansegment(en) toegestaan via Tilburg-profiel");

        if (currentAnalysis.learnedPenalty > 0)
            b.append("\n🧠 Leerlaag: eerder vermeden punten beïnvloeden deze route.");
        b.append("\n🚪 Rechterdeurvoorkeur: ")
                .append(currentAnalysis.destinationOnRight ? "gehaald." : "niet betrouwbaar gehaald.");
        return b.toString();
    }

    private void startNavigation() {
        if (currentRoute == null || currentDestination == null
                || currentLocation == null || currentAnalysis == null) return;

        navigating = true;
        followMode = true;
        learnedThisDeviation = false;
        announcedApproachSteps.clear();
        announcedNearSteps.clear();
        announcedRestrictions.clear();
        announcedTrafficEvents.clear();
        announcedRoadSigns.clear();
        currentStepIndex = currentRoute.steps.size() > 1 ? 1 : 0;
        lastRerouteMs = System.currentTimeMillis();
        lastTrafficRefreshMs = System.currentTimeMillis();

        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        searchArea.setVisibility(View.GONE);
        savedPlaces.setVisibility(View.GONE);
        previewArea.setVisibility(View.GONE);
        navArea.setVisibility(View.VISIBLE);

        RoutePilotStore.beginTrip(this, currentDestination.label, currentRoute.distanceMeters);
        RoutePilotStore.savePlannedRoute(this, currentRoute.points);
        RoutePilotState.saveRoute(this, currentRoute);
        RoutePilotState.update(this, true, "Navigatie gestart", "", 0,
                currentRoute.distanceMeters,
                System.currentTimeMillis() + (long)(currentRoute.durationSeconds * 1000),
                -1, currentAnalysis.score, currentDestination.label);

        Intent service = new Intent(this, NavigationService.class);
        if (Build.VERSION.SDK_INT >= 26) startForegroundService(service);
        else startService(service);

        map.getController().setZoom(17.0);
        centerOnMe();
        speak("Navigatie gestart. RoutePilot bewaakt live verkeer, verkeersborden, voertuigbeperkingen en aankomstzijde.");
        updateNavigationProgress(currentLocation);
    }

    private void updateNavigationProgress(Location location) {
        if (!navigating || currentRoute == null || currentDestination == null) return;

        double lat = location.getLatitude(), lon = location.getLongitude();
        int routeIndex = OnlineServices.closestRoutePointIndex(lat, lon, currentRoute.points);
        double offRoute = OnlineServices.distanceFromRouteMeters(lat, lon, currentRoute.points);

        if (offRoute < 55) learnedThisDeviation = false;

        if (offRoute > 110 && !routeLoading
                && System.currentTimeMillis() - lastRerouteMs > 18000) {
            if (!learnedThisDeviation && location.getAccuracy() <= 45) {
                int avoidedIndex = Math.max(0, Math.min(routeIndex, currentRoute.points.size() - 1));
                GeoPoint avoided = currentRoute.points.get(avoidedIndex);
                RoutePilotStore.learnAvoidance(this,
                        avoided.getLatitude(), avoided.getLongitude(),
                        "herhaalde afwijking van voorgestelde weg");
                learnedThisDeviation = true;
            }
            RoutePilotStore.markReroute(this);
            lastRerouteMs = System.currentTimeMillis();
            navStatus.setText("HERROUTEREN…");
            speak("Je bent van de route afgeweken. Nieuwe route wordt berekend.");
            setRouteLoading(true);
            new Thread(() -> {
                try { calculateRouteInWorker(currentDestination, true); }
                catch (Exception e) {
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
                ? currentRoute.durationSeconds * (remaining / currentRoute.distanceMeters) : 0;
        long etaMs = System.currentTimeMillis() + (long)(remainingSeconds * 1000);
        String etaText = new SimpleDateFormat("HH:mm", NL).format(new Date(etaMs));

        Integer wkdSpeed = null;
        if (routeIndex >= 0 && routeIndex < currentRoute.points.size()) {
            wkdSpeed = OfficialSpeedService.speedLimitAt(
                    currentRoute.points.get(routeIndex), currentRoute.officialSpeeds);
        }
        Integer signSpeed = RoadDataService.speedLimitAt(routeIndex, currentRoute.roadSigns);
        currentSpeedLimit = wkdSpeed != null ? wkdSpeed : (signSpeed == null ? -1 : signSpeed);
        if (currentSpeedLimit <= 0) navSpeed.setText("MAX —");
        else navSpeed.setText("MAX " + currentSpeedLimit);

        navScore.setText("ROUTE " + currentAnalysis.score + "/100");
        navScore.setTextColor(currentAnalysis.score >= 82 ? GREEN
                : currentAnalysis.score >= 58 ? ORANGE : RED);

        double kmh = location.hasSpeed() ? Math.max(0, location.getSpeed() * 3.6) : 0;
        navMeta.setText(String.format(NL,
                "%.1f km resterend • aankomst %s • %.0f km/u%s",
                remaining / 1000.0, etaText, kmh,
                currentAnalysis.destinationOnRight ? " • 🚪 rechts" : " • 🚪 controle"));

        updateStepGuidance(lat, lon);
        updateGeofencedWarnings(lat, lon, routeIndex);

        RoutePilotState.update(this, true, currentInstruction, currentWarning,
                currentStepDistance, remaining, etaMs,
                currentSpeedLimit, currentAnalysis.score, currentDestination.label);

        if (!routeLoading && System.currentTimeMillis() - lastTrafficRefreshMs > 130_000L) {
            lastTrafficRefreshMs = System.currentTimeMillis();
            refreshLiveTraffic();
        }

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
            currentInstruction = "Volg de route";
            currentStepDistance = 0;
            navDistance.setText("—");
            navInstruction.setText(currentInstruction);
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

        currentStepDistance = distance;
        currentInstruction = step.instruction;
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

    private void updateGeofencedWarnings(double lat, double lon, int routeIndex) {
        currentWarning = "";

        for (int i = 0; i < currentRoute.restrictions.size(); i++) {
            if (announcedRestrictions.contains(i)) continue;
            OnlineServices.Restriction r = currentRoute.restrictions.get(i);
            if (r.informational) continue;
            int target = OnlineServices.closestRoutePointIndex(r.lat, r.lon, currentRoute.points);
            if (!isAhead(routeIndex, target)) continue;
            double d = OnlineServices.distanceMeters(lat, lon, r.lat, r.lon);
            if (d < (r.critical ? 520 : 360)) {
                announcedRestrictions.add(i);
                currentWarning = r.type + ": " + r.description;
                RoutePilotStore.markWarning(this);
                navStatus.setText(r.critical
                        ? "⚠ KRITIEKE VOERTUIGWAARSCHUWING" : "⚠ ROUTE-AANDACHTSPUNT");
                speak("Let op. " + r.type + ". " + r.description);
            }
        }

        for (int i = 0; i < currentRoute.trafficEvents.size(); i++) {
            if (announcedTrafficEvents.contains(i)) continue;
            LiveTrafficService.TrafficEvent e = currentRoute.trafficEvents.get(i);
            int target = OnlineServices.closestRoutePointIndex(e.lat, e.lon, currentRoute.points);
            if (!isAhead(routeIndex, target)) continue;
            double d = OnlineServices.distanceMeters(lat, lon, e.lat, e.lon);
            double trigger = e.closure ? 900 : 520;
            if (d < trigger) {
                announcedTrafficEvents.add(i);
                currentWarning = e.type + ": " + e.description;
                RoutePilotStore.markWarning(this);
                navStatus.setText(e.closure
                        ? "🚧 ACTUELE AFSLUITING VOORUIT" : "📡 ACTUELE VERKEERSINFO");
                speak((e.closure ? "Let op. Actuele afsluiting. " : "Actuele verkeersmelding. ")
                        + e.description);
            }
        }

        for (RoadDataService.Sign s : currentRoute.roadSigns) {
            if (!RoadDataService.isRestriction(s) || announcedRoadSigns.contains(s.id)) continue;
            if (!isAhead(routeIndex, s.routeIndex)) continue;
            double d = OnlineServices.distanceMeters(lat, lon, s.lat, s.lon);
            if (d < 450) {
                announcedRoadSigns.add(s.id);
                currentWarning = s.description();
                RoutePilotStore.markWarning(this);
                navStatus.setText("🛑 OFFICIEEL VERKEERSBORD VOORUIT");
                speak("Let op. " + s.description());
            }
        }
    }

    private boolean isAhead(int currentIndex, int targetIndex) {
        if (targetIndex < 0) return true;
        int tolerance = Math.max(4, currentRoute.points.size() / 250);
        return targetIndex >= currentIndex - tolerance;
    }

    private void refreshLiveTraffic() {
        if (currentRoute == null || currentDestination == null || !navigating) return;
        final OnlineServices.RouteResult snapshot = currentRoute;

        new Thread(() -> {
            try {
                List<LiveTrafficService.TrafficEvent> events =
                        LiveTrafficService.eventsNearRoute(snapshot.points);
                int oldClosures = snapshot.liveClosureCount();
                int oldImportant = importantTrafficCount(snapshot.trafficEvents);
                int newClosures = 0;
                for (LiveTrafficService.TrafficEvent e : events) if (e.closure) newClosures++;
                final int finalClosures = newClosures;
                final int finalImportant = importantTrafficCount(events);

                runOnUiThread(() -> {
                    if (currentRoute != snapshot) return;
                    currentRoute.trafficEvents = events;
                    drawCurrentRoute();

                    if ((finalClosures > oldClosures || finalImportant > oldImportant) && !routeLoading) {
                        RoutePilotStore.markReroute(this);
                        navStatus.setText(finalClosures > oldClosures
                                ? "🚧 NIEUWE AFSLUITING • HERROUTEREN"
                                : "📡 NIEUWE VERKEERSHINDER • HERROUTEREN");
                        speak(finalClosures > oldClosures
                                ? "Nieuwe actuele afsluiting op of vlak langs de route. RoutePilot berekent opnieuw."
                                : "Nieuwe actuele verkeershinder op of vlak langs de route. RoutePilot vergelijkt alternatieven.");
                        lastRerouteMs = System.currentTimeMillis();
                        setRouteLoading(true);
                        new Thread(() -> {
                            try { calculateRouteInWorker(currentDestination, true); }
                            catch (Exception e) {
                                runOnUiThread(() -> {
                                    navStatus.setText("🚧 AFSLUITING • herrouteren mislukt");
                                    setRouteLoading(false);
                                });
                            }
                        }).start();
                    }
                });
            } catch (Exception ignored) {}
        }).start();
    }

    private int importantTrafficCount(List<LiveTrafficService.TrafficEvent> events) {
        int n = 0;
        if (events == null) return 0;
        for (LiveTrafficService.TrafficEvent e : events) {
            if (e.closure) { n++; continue; }
            String t = e.type == null ? "" : e.type.toUpperCase(Locale.ROOT);
            if (t.contains("ONGEVAL") || t.contains("WERKZAAMHEDEN")
                    || t.contains("OBSTAKEL") || t.contains("VOERTUIG")
                    || t.contains("VERKEERSHINDER") || t.contains("WEGTOESTAND")) n++;
        }
        return n;
    }

    private void applyReroute(String note) {
        drawCurrentRoute();
        showDestinationMarker();
        RoutePilotState.saveRoute(this, currentRoute);
        RoutePilotStore.savePlannedRoute(this, currentRoute.points);

        announcedApproachSteps.clear();
        announcedNearSteps.clear();
        announcedRestrictions.clear();
        announcedTrafficEvents.clear();
        announcedRoadSigns.clear();
        currentStepIndex = currentRoute.steps.size() > 1 ? 1 : 0;
        navStatus.setText("NAVIGATIE ACTIEF • route bijgewerkt");

        if (currentRoute.liveClosureCount() > 0) navStatus.setText("🚧 AFSLUITING OP ROUTE");
        else if (currentRoute.criticalCount() > 0) navStatus.setText("⚠ VOERTUIGWAARSCHUWING");
        else if (!currentAnalysis.destinationOnRight) navStatus.setText("NAVIGATIE ACTIEF • normale aankomstzijde");

        speak("Nieuwe route geladen."
                + (currentAnalysis.destinationOnRight ? " Rechterdeur aan ingangzijde ingesteld." : ""));
        updateNavigationProgress(currentLocation);
    }

    private void arrive() {
        if (!navigating || currentDestination == null || currentAnalysis == null) return;

        RoutePilotStore.LocationProfile p =
                RoutePilotStore.findProfile(this, currentDestination.lat, currentDestination.lon);
        if (p == null) p = new RoutePilotStore.LocationProfile();
        p.label = currentDestination.label;
        p.lat = currentDestination.lat;
        p.lon = currentDestination.lon;
        p.rightDoorToEntrance = true;
        p.preferredArrivalBearing = currentAnalysis.approachBearing;
        p.visits++;
        RoutePilotStore.saveProfile(this, p);

        speak("Bestemming bereikt.");
        stopNavigation(true);

        String side = currentAnalysis.destinationOnRight
                ? "✓ Rechterdeur aan de ingangzijde gebruikt."
                : "Rechterdeurvoorkeur is hier niet toegepast omdat de normale/legale aanrijrichting leidend is.";

        String lift = vehicle.rearLift
                ? "\n\nAchterlift:\n• Houd voldoende vrije ruimte achter de bus."
                + "\n• Controleer fietspad, paaltjes, stoeprand en verkeer."
                + "\n• Zet de bus volledig veilig stil vóór bediening."
                : "";

        final RoutePilotStore.LocationProfile saved = p;
        new AlertDialog.Builder(this)
                .setTitle("Bestemming bereikt")
                .setMessage(side + lift)
                .setNeutralButton("Aanrijzijde opslaan", (d, w) -> {
                    saved.preferredArrivalBearing = currentAnalysis.approachBearing;
                    saved.rightDoorToEntrance = true;
                    RoutePilotStore.saveProfile(this, saved);
                    Toast.makeText(this, "Aanrijzijde opgeslagen voor volgende rit.", Toast.LENGTH_SHORT).show();
                })
                .setPositiveButton("Klaar", null)
                .show();
    }

    private void stopNavigation(boolean keepRoute) {
        navigating = false;
        followMode = false;
        getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        RoutePilotState.update(this, false, "", "", 0, 0, 0,
                -1, currentAnalysis == null ? 0 : currentAnalysis.score,
                currentDestination == null ? "" : currentDestination.label);
        stopService(new Intent(this, NavigationService.class));
        RoutePilotStore.finishTrip(this);

        navArea.setVisibility(View.GONE);
        searchArea.setVisibility(View.VISIBLE);
        savedPlaces.setVisibility(View.VISIBLE);
        previewArea.setVisibility(keepRoute && currentRoute != null ? View.VISIBLE
                : currentRoute == null ? View.GONE : View.VISIBLE);
        navStatus.setText("NAVIGATIE ACTIEF");
    }

    private void showDriverReportDialog() {
        if (currentLocation == null) return;
        String[] options = {
                "Bussluis", "Te laag", "Weg dicht", "Niet bereikbaar",
                "Goede uitstapplek", "Smalle straat", "Vermijd deze weg"
        };
        new AlertDialog.Builder(this)
                .setTitle("Chauffeursmelding")
                .setItems(options, (dialog, which) -> {
                    String type = options[which];
                    showReportNote(type);
                })
                .setNegativeButton("Annuleren", null)
                .show();
    }

    private void showReportNote(String type) {
        EditText input = new EditText(this);
        input.setHint("Optionele notitie");
        new AlertDialog.Builder(this)
                .setTitle(type)
                .setView(input)
                .setPositiveButton("Opslaan", (d, w) -> {
                    RoutePilotStore.addReport(this, type, input.getText().toString(),
                            currentLocation.getLatitude(), currentLocation.getLongitude());
                    Toast.makeText(this, "Melding lokaal opgeslagen.", Toast.LENGTH_SHORT).show();
                })
                .setNegativeButton("Annuleren", null)
                .show();
    }

    private void showWhyRoute() {
        if (currentAnalysis == null) return;
        StringBuilder b = new StringBuilder(currentAnalysis.summary());
        if (currentRoute != null && !currentRoute.selectionNote.isEmpty())
            b.append("\n\n").append(currentRoute.selectionNote);
        b.append("\n\nTilburgse busbaanontheffing is alleen actief binnen de officiële gemeentegrens.");
        new AlertDialog.Builder(this)
                .setTitle("Waarom deze route?")
                .setMessage(b.toString())
                .setPositiveButton("OK", null)
                .show();
    }

    private void showLocationProfileDialog() {
        if (currentDestination == null || currentAnalysis == null) return;
        RoutePilotStore.LocationProfile existing =
                RoutePilotStore.findProfile(this, currentDestination.lat, currentDestination.lon);

        EditText note = new EditText(this);
        note.setHint("Bijv. ingang aan rechterzijde, achterom, liftplek...");
        if (existing != null) note.setText(existing.note);

        new AlertDialog.Builder(this)
                .setTitle("Locatieprofiel")
                .setMessage("Rechterdeur naar de woning-/ingangzijde staat vast aan. "
                        + "De huidige aanrijrichting wordt als voorkeur opgeslagen.")
                .setView(note)
                .setPositiveButton("Opslaan", (d, w) -> {
                    RoutePilotStore.LocationProfile p = existing == null
                            ? new RoutePilotStore.LocationProfile() : existing;
                    p.label = currentDestination.label;
                    p.note = note.getText().toString();
                    p.lat = currentDestination.lat;
                    p.lon = currentDestination.lon;
                    p.preferredArrivalBearing = currentAnalysis.approachBearing;
                    p.rightDoorToEntrance = true;
                    RoutePilotStore.saveProfile(this, p);
                    Toast.makeText(this, "Locatieprofiel opgeslagen.", Toast.LENGTH_SHORT).show();
                })
                .setNegativeButton("Annuleren", null)
                .show();
    }

    private void replayLatestTrip() {
        List<RoutePilotStore.Trip> trips = RoutePilotStore.trips(this);
        if (trips.isEmpty()) {
            Toast.makeText(this, "Nog geen afgeronde rit om te replayen.", Toast.LENGTH_SHORT).show();
            return;
        }
        RoutePilotStore.Trip t = trips.get(0);
        List<GeoPoint> actual = jsonPoints(t.points);
        List<GeoPoint> planned = jsonPoints(t.plannedPoints);

        if (actual.size() < 2) {
            Toast.makeText(this, "Voor deze rit zijn te weinig GPS-punten opgeslagen.", Toast.LENGTH_SHORT).show();
            return;
        }

        if (replayLine != null) map.getOverlays().remove(replayLine);
        replayLine = new Polyline(map);
        replayLine.setPoints(actual);
        replayLine.getOutlinePaint().setColor(ORANGE);
        replayLine.getOutlinePaint().setStrokeWidth(dp(7));
        map.getOverlays().add(replayLine);

        if (routeLine != null) map.getOverlays().remove(routeLine);
        if (planned.size() >= 2) {
            routeLine = new Polyline(map);
            routeLine.setPoints(planned);
            routeLine.getOutlinePaint().setColor(BLUE);
            routeLine.getOutlinePaint().setStrokeWidth(dp(5));
            map.getOverlays().add(routeLine);
        }

        List<GeoPoint> bounds = new ArrayList<>(actual);
        bounds.addAll(planned);
        fitRoute(bounds);
        map.invalidate();

        new AlertDialog.Builder(this)
                .setTitle("Laatste rit replay")
                .setMessage(t.destinationLabel + "\n\nBlauw = gepland\nOranje = werkelijk gereden\n\n"
                        + String.format(NL, "%.1f km gereden • %d herrouteringen • %d waarschuwingen",
                        t.actualDistanceM / 1000.0, t.reroutes, t.warnings))
                .setPositiveButton("OK", null)
                .show();
    }

    private List<GeoPoint> jsonPoints(JSONArray arr) {
        List<GeoPoint> out = new ArrayList<>();
        if (arr == null) return out;
        try {
            for (int i = 0; i < arr.length(); i++) {
                JSONArray p = arr.getJSONArray(i);
                out.add(new GeoPoint(p.getDouble(0), p.getDouble(1)));
            }
        } catch (Exception ignored) {}
        return out;
    }

    private void drawCurrentRoute() {
        if (routeLine != null) map.getOverlays().remove(routeLine);
        for (Marker m : routeMarkers) map.getOverlays().remove(m);
        routeMarkers.clear();

        routeLine = new Polyline(map);
        routeLine.setPoints(currentRoute.points);
        routeLine.getOutlinePaint().setColor(BLUE);
        routeLine.getOutlinePaint().setStrokeWidth(dp(7));
        map.getOverlays().add(routeLine);

        for (OnlineServices.Restriction r : currentRoute.restrictions) {
            Marker marker = marker(r.lat, r.lon, r.type, r.description);
            routeMarkers.add(marker);
            map.getOverlays().add(marker);
        }
        for (LiveTrafficService.TrafficEvent e : currentRoute.trafficEvents) {
            Marker marker = marker(e.lat, e.lon, "🚧 " + e.type + " • " + e.source, e.description);
            routeMarkers.add(marker);
            map.getOverlays().add(marker);
        }
        for (RoadDataService.Sign s : currentRoute.roadSigns) {
            if (!s.isSpeed() && !RoadDataService.isRestriction(s)) continue;
            Marker marker = marker(s.lat, s.lon, "🛑 " + s.rvvCode, s.description());
            routeMarkers.add(marker);
            map.getOverlays().add(marker);
        }
        map.invalidate();
    }

    private Marker marker(double lat, double lon, String title, String snippet) {
        Marker m = new Marker(map);
        m.setPosition(new GeoPoint(lat, lon));
        m.setTitle(title);
        m.setSnippet(snippet);
        m.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
        return m;
    }

    private void showDestinationMarker() {
        if (currentDestination == null) return;
        if (destinationMarker == null) {
            destinationMarker = new Marker(map);
            destinationMarker.setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM);
            map.getOverlays().add(destinationMarker);
        }
        destinationMarker.setPosition(new GeoPoint(currentDestination.lat, currentDestination.lon));
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
            map.zoomToBoundingBox(new BoundingBox(north, east, south, west), true, dp(55));
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
            for (DestinationStore.Item f : favorites) {
                if (Math.abs(f.lat - item.lat) < 0.00001 && Math.abs(f.lon - item.lon) < 0.00001) {
                    duplicate = true; break;
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
            Toast.makeText(this, added ? "Toegevoegd aan favorieten" : "Verwijderd uit favorieten",
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
        boolean added = DestinationStore.toggleFavorite(this, currentDestination.toStoredItem());
        favoriteButton.setText(added ? "★ Favoriet" : "☆ Favoriet");
        refreshSavedPlaces();
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
        box.addView(length); box.addView(height); box.addView(width); box.addView(weight);

        CheckBox rearLift = new CheckBox(this);
        rearLift.setText("Achterlift");
        rearLift.setChecked(vehicle.rearLift);
        box.addView(rearLift);

        CheckBox busLane = new CheckBox(this);
        busLane.setText("Busbaanontheffing gemeente Tilburg");
        busLane.setChecked(vehicle.busLaneExemption);
        box.addView(busLane);

        TextView note = text(
                "De busbaanontheffing wordt alleen binnen de officiële gemeentegrens van Tilburg toegepast. Bussluizen blijven altijd verboden.",
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
                        if (l < 2 || h < 1.5 || w < 1.5 || m < 0.5)
                            throw new IllegalArgumentException();

                        vehicle.lengthM = l; vehicle.heightM = h; vehicle.widthM = w;
                        vehicle.maxWeightT = m; vehicle.rearLift = rearLift.isChecked();
                        vehicle.busLaneExemption = busLane.isChecked();
                        vehicle.save(this);
                        refreshVehicleSummary();
                        dialog.dismiss();
                    } catch (Exception e) {
                        Toast.makeText(this, "Controleer de voertuigwaarden.", Toast.LENGTH_LONG).show();
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
                "🚐 %.2f×%.2f×%.2f m • %.2f t%s • 🚪 rechts-ingang",
                vehicle.lengthM, vehicle.widthM, vehicle.heightM, vehicle.maxWeightT,
                vehicle.busLaneExemption ? " • Tilburg-ontheffing" : ""));
    }

    private void applyAutoNightMode() {
        int hour = Calendar.getInstance().get(Calendar.HOUR_OF_DAY);
        boolean night = hour >= 20 || hour < 7;
        WindowManager.LayoutParams lp = getWindow().getAttributes();
        lp.screenBrightness = night ? 0.45f : -1f;
        getWindow().setAttributes(lp);
        try {
            map.getOverlayManager().getTilesOverlay().setColorFilter(
                    night ? TilesOverlay.INVERT_COLORS : null);
        } catch (Exception ignored) {}
    }

    private void handleNavigationIntent(Intent intent) {
        if (intent == null || intent.getData() == null || destinationInput == null) return;
        Uri data = intent.getData();
        if (!"geo".equalsIgnoreCase(data.getScheme())) return;
        String q = data.getQueryParameter("q");
        if (q != null && !q.trim().isEmpty()) destinationInput.setText(q);
    }

    private void centerOnMe() {
        if (currentLocation == null) {
            Toast.makeText(this, "Nog geen GPS-positie.", Toast.LENGTH_SHORT).show();
            return;
        }
        map.getController().setZoom(navigating ? 17.0 : 16.5);
        map.getController().animateTo(new GeoPoint(
                currentLocation.getLatitude(), currentLocation.getLongitude()));
    }

    private void setRouteLoading(boolean loading) {
        routeLoading = loading;
        routeButton.setEnabled(!loading);
        routeButton.setText(loading ? "…" : "Route");
        if (loading && !navigating) {
            previewArea.setVisibility(View.VISIBLE);
            routeMeta.setText("OSRM + OSM + NDW + Tilburg-profiel worden gecontroleerd…");
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
                    ? (int)(Math.round(meters / 10.0) * 10)
                    : (int)(Math.round(meters / 50.0) * 50);
            return rounded + " m";
        }
        return String.format(NL, "%.1f km", meters / 1000.0);
    }

    private String spokenDistance(double meters) {
        return meters < 1000 ? formatDistance(meters)
                : String.format(NL, "%.1f kilometer", meters / 1000.0);
    }

    private double parseNumber(String value) {
        return Double.parseDouble(value.trim().replace(',', '.'));
    }

    private void hideKeyboard() {
        View v = getCurrentFocus();
        if (v == null) v = destinationInput;
        InputMethodManager imm =
                (InputMethodManager)getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) imm.hideSoftInputFromWindow(v.getWindowToken(), 0);
    }

    private void speak(String message) {
        if (tts != null && message != null && !message.trim().isEmpty())
            tts.speak(message, TextToSpeech.QUEUE_FLUSH, null, "routepilot-v2");
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
        b.setText(label); b.setTextSize(13); b.setTextColor(Color.rgb(3, 18, 28));
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD); b.setAllCaps(false);
        b.setBackground(rounded(BLUE, 13));
        return b;
    }

    private Button darkButton(String label) {
        Button b = new Button(this);
        b.setText(label); b.setTextSize(12); b.setTextColor(TEXT);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD); b.setAllCaps(false);
        b.setBackground(rounded(PANEL_2, 13));
        return b;
    }

    private Button dangerButton(String label) {
        Button b = new Button(this);
        b.setText(label); b.setTextSize(12); b.setTextColor(TEXT);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD); b.setAllCaps(false);
        b.setBackground(rounded(Color.rgb(105, 32, 42), 13));
        return b;
    }

    private TextView text(String value, int sp, int color, int style) {
        TextView v = new TextView(this);
        v.setText(value); v.setTextSize(sp); v.setTextColor(color);
        v.setTypeface(Typeface.DEFAULT, style); v.setLineSpacing(0, 1.10f);
        return v;
    }

    private android.graphics.drawable.GradientDrawable rounded(int color, int radiusDp) {
        android.graphics.drawable.GradientDrawable d =
                new android.graphics.drawable.GradientDrawable();
        d.setColor(color); d.setCornerRadius(dp(radiusDp));
        return d;
    }

    private int dp(int dp) {
        return Math.round(dp * getResources().getDisplayMetrics().density);
    }

    @Override protected void onResume() {
        super.onResume();
        if (map != null) map.onResume();
        applyAutoNightMode();
    }

    @Override protected void onPause() {
        if (map != null) map.onPause();
        super.onPause();
    }

    @Override protected void onDestroy() {
        try { if (locationManager != null) locationManager.removeUpdates(this); }
        catch (Exception ignored) {}
        if (tts != null) { tts.stop(); tts.shutdown(); }
        if (map != null) map.onDetach();
        super.onDestroy();
    }
}
