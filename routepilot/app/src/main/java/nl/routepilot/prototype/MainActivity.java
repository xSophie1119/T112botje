package nl.routepilot.prototype;

import android.app.Activity;
import android.graphics.Color;
import android.graphics.Typeface;
import android.os.Bundle;
import android.speech.tts.TextToSpeech;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Space;
import android.widget.TextView;
import android.widget.Toast;

import java.util.Locale;

public class MainActivity extends Activity implements TextToSpeech.OnInitListener {

    private final int BG = Color.rgb(11, 18, 32);
    private final int CARD = Color.rgb(20, 30, 48);
    private final int CARD_ALT = Color.rgb(25, 38, 60);
    private final int TEXT = Color.rgb(241, 245, 249);
    private final int MUTED = Color.rgb(148, 163, 184);
    private final int BLUE = Color.rgb(56, 189, 248);
    private final int GREEN = Color.rgb(74, 222, 128);
    private final int ORANGE = Color.rgb(251, 146, 60);
    private final int RED = Color.rgb(248, 113, 113);

    private TextToSpeech tts;
    private TextView tripStatus;
    private Button startButton;
    private boolean testRideRunning = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        tts = new TextToSpeech(this, this);

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setBackgroundColor(BG);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(20), dp(18), dp(20), dp(28));
        root.setBackgroundColor(BG);
        scroll.addView(root, new ScrollView.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        TextView brand = text("ROUTEPILOT", 12, BLUE, Typeface.BOLD);
        root.addView(brand);

        TextView title = text("Rustiger rijden. Slimmer aankomen.", 27, TEXT, Typeface.BOLD);
        title.setPadding(0, dp(5), 0, dp(2));
        root.addView(title);

        TextView subtitle = text("Prototype v0.1 • offline testbuild", 13, MUTED, Typeface.NORMAL);
        root.addView(subtitle);

        root.addView(space(18));

        LinearLayout statusCard = card(CARD);
        TextView statusLabel = text("RITSTATUS", 11, MUTED, Typeface.BOLD);
        statusCard.addView(statusLabel);

        tripStatus = text("Klaar voor een testrit", 21, TEXT, Typeface.BOLD);
        tripStatus.setPadding(0, dp(5), 0, dp(3));
        statusCard.addView(tripStatus);

        TextView statusSub = text("Live routering is in deze APK nog uitgeschakeld.", 13, MUTED, Typeface.NORMAL);
        statusCard.addView(statusSub);

        startButton = primaryButton("Start gesimuleerde testrit");
        startButton.setOnClickListener(v -> toggleTestRide());
        LinearLayout.LayoutParams startLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(52));
        startLp.topMargin = dp(14);
        statusCard.addView(startButton, startLp);

        root.addView(statusCard);
        root.addView(space(12));

        LinearLayout vehicleCard = card(CARD);
        vehicleCard.addView(text("VOERTUIGPROFIEL", 11, MUTED, Typeface.BOLD));
        vehicleCard.addView(text("Rolstoelbus", 20, TEXT, Typeface.BOLD));
        vehicleCard.addView(space(9));

        LinearLayout specs = new LinearLayout(this);
        specs.setOrientation(LinearLayout.HORIZONTAL);
        specs.setWeightSum(4f);
        specs.addView(spec("5,93 m", "lengte"), weighted());
        specs.addView(spec("2,76 m", "hoogte"), weighted());
        specs.addView(spec("2,34 m", "breedte"), weighted());
        specs.addView(spec("3.500 kg", "max."), weighted());
        vehicleCard.addView(specs);

        TextView lift = text("✓ Achterlift ingesteld", 13, GREEN, Typeface.BOLD);
        lift.setPadding(0, dp(13), 0, 0);
        vehicleCard.addView(lift);

        root.addView(vehicleCard);
        root.addView(space(12));

        root.addView(sectionTitle("Routewaarschuwingen"));

        LinearLayout warning1 = warningCard(
                "BUSSLUIS",
                "Niet toegankelijk",
                "RoutePilot zou deze doorgang vermijden voor jouw voertuig.",
                RED
        );
        root.addView(warning1);
        root.addView(space(9));

        LinearLayout warning2 = warningCard(
                "BUSBAAN",
                "Toegestaan met vrijstelling",
                "Gemarkeerd als toegestaan in het Tilburg-profiel.",
                GREEN
        );
        root.addView(warning2);
        root.addView(space(9));

        LinearLayout warning3 = warningCard(
                "HOOGTE",
                "Controlepunt: 2,80 m",
                "Je voertuig is 2,76 m hoog. In een echte route volgt hier een veiligheidsmarge.",
                ORANGE
        );
        root.addView(warning3);

        root.addView(space(18));
        root.addView(sectionTitle("Aankomstassistent"));

        LinearLayout arrival = card(CARD_ALT);
        arrival.addView(text("Bestemming simulatie", 18, TEXT, Typeface.BOLD));
        arrival.addView(text("Station Tilburg • achterliftmodus", 13, MUTED, Typeface.NORMAL));

        TextView advice = text(
                "Advies: zoek een vlakke stopplek met vrije ruimte achter de bus. Controleer fietspad, paaltjes en uitstapruimte vóór je de lift bedient.",
                14, TEXT, Typeface.NORMAL);
        advice.setPadding(0, dp(12), 0, dp(13));
        arrival.addView(advice);

        Button speak = secondaryButton("🔊 Test gesproken waarschuwing");
        speak.setOnClickListener(v -> speakWarning());
        arrival.addView(speak, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(48)));

        root.addView(arrival);
        root.addView(space(18));

        TextView footer = text(
                "Dit is een testprototype en geen navigatiesysteem. Verkeersborden en actuele verkeersregels blijven leidend.",
                12, MUTED, Typeface.NORMAL);
        footer.setGravity(Gravity.CENTER);
        root.addView(footer);

        setContentView(scroll);
    }

    private void toggleTestRide() {
        testRideRunning = !testRideRunning;
        if (testRideRunning) {
            tripStatus.setText("Testrit actief • 6,5 km");
            tripStatus.setTextColor(GREEN);
            startButton.setText("Stop testrit");
            Toast.makeText(this, "Simulatie gestart", Toast.LENGTH_SHORT).show();
            speak("Testrit gestart. RoutePilot bewaakt voertuigbeperkingen.");
        } else {
            tripStatus.setText("Klaar voor een testrit");
            tripStatus.setTextColor(TEXT);
            startButton.setText("Start gesimuleerde testrit");
            Toast.makeText(this, "Simulatie gestopt", Toast.LENGTH_SHORT).show();
        }
    }

    private void speakWarning() {
        speak("Let op. Mogelijke hoogtebeperking over 400 meter. Controleer de bebording.");
    }

    private void speak(String message) {
        if (tts != null) {
            tts.speak(message, TextToSpeech.QUEUE_FLUSH, null, "routepilot");
        }
    }

    @Override
    public void onInit(int status) {
        if (status == TextToSpeech.SUCCESS && tts != null) {
            tts.setLanguage(new Locale("nl", "NL"));
            tts.setSpeechRate(0.95f);
        }
    }

    @Override
    protected void onDestroy() {
        if (tts != null) {
            tts.stop();
            tts.shutdown();
        }
        super.onDestroy();
    }

    private LinearLayout warningCard(String tag, String title, String body, int accent) {
        LinearLayout box = card(CARD);
        TextView tagView = text(tag, 11, accent, Typeface.BOLD);
        box.addView(tagView);

        TextView titleView = text(title, 17, TEXT, Typeface.BOLD);
        titleView.setPadding(0, dp(4), 0, dp(3));
        box.addView(titleView);

        box.addView(text(body, 13, MUTED, Typeface.NORMAL));
        return box;
    }

    private LinearLayout spec(String value, String label) {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.addView(text(value, 15, TEXT, Typeface.BOLD));
        TextView l = text(label, 10, MUTED, Typeface.NORMAL);
        l.setGravity(Gravity.CENTER);
        box.addView(l);
        return box;
    }

    private LinearLayout.LayoutParams weighted() {
        return new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
    }

    private TextView sectionTitle(String s) {
        TextView v = text(s, 15, TEXT, Typeface.BOLD);
        v.setPadding(0, 0, 0, dp(9));
        return v;
    }

    private LinearLayout card(int color) {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(16), dp(16), dp(16), dp(16));
        box.setBackground(rounded(color, 18));
        return box;
    }

    private Button primaryButton(String label) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(14);
        b.setTextColor(Color.rgb(3, 18, 28));
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setAllCaps(false);
        b.setBackground(rounded(BLUE, 14));
        return b;
    }

    private Button secondaryButton(String label) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(14);
        b.setTextColor(TEXT);
        b.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        b.setAllCaps(false);
        b.setBackground(rounded(Color.rgb(38, 54, 78), 14));
        return b;
    }

    private TextView text(String value, int sp, int color, int style) {
        TextView v = new TextView(this);
        v.setText(value);
        v.setTextSize(sp);
        v.setTextColor(color);
        v.setTypeface(Typeface.DEFAULT, style);
        v.setLineSpacing(0, 1.12f);
        return v;
    }

    private Space space(int heightDp) {
        Space s = new Space(this);
        s.setLayoutParams(new LinearLayout.LayoutParams(1, dp(heightDp)));
        return s;
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
