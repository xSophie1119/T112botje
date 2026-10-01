package nl.routepilot.prototype;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

public final class PortalCorrectionService {
    public interface Callback {
        void done(boolean ok, String message, int count);
    }

    private PortalCorrectionService() {}

    public static void syncAsync(Context context, Callback callback) {
        final Context app = context.getApplicationContext();
        new Thread(() -> {
            boolean ok = false;
            String message;
            int count = PortalCorrectionStore.count(app);
            try {
                sync(app);
                count = PortalCorrectionStore.count(app);
                ok = true;
                message = count + " portalcorrectie(s) gesynchroniseerd";
            } catch (Exception e) {
                message = e.getMessage() == null ? "Synchronisatie mislukt" : e.getMessage();
            }
            if (callback != null) {
                boolean finalOk = ok;
                int finalCount = count;
                String finalMessage = message;
                new Handler(Looper.getMainLooper()).post(
                        () -> callback.done(finalOk, finalMessage, finalCount));
            }
        }).start();
    }

    public static void syncIfStaleAsync(Context context) {
        String url = PortalCorrectionStore.portalUrl(context);
        if (url == null || url.trim().isEmpty()) return;
        if (System.currentTimeMillis() - PortalCorrectionStore.lastSync(context) < 10 * 60_000L) return;
        syncAsync(context, null);
    }

    public static void sync(Context context) throws Exception {
        String base = PortalCorrectionStore.portalUrl(context);
        if (base == null || base.trim().isEmpty())
            throw new IllegalStateException("Geen simulator-portal URL ingesteld.");
        base = base.trim();
        while (base.endsWith("/")) base = base.substring(0, base.length() - 1);

        HttpURLConnection con = (HttpURLConnection)
                new URL(base + "/api/corrections/export").openConnection();
        con.setConnectTimeout(5000);
        con.setReadTimeout(8000);
        con.setRequestProperty("User-Agent", OnlineServices.USER_AGENT);
        con.setRequestProperty("Accept", "application/json");
        String token = PortalCorrectionStore.portalToken(context);
        if (token != null && !token.trim().isEmpty())
            con.setRequestProperty("X-RoutePilot-Token", token.trim());

        int code = con.getResponseCode();
        BufferedReader br = new BufferedReader(new InputStreamReader(
                code >= 200 && code < 300 ? con.getInputStream() : con.getErrorStream(),
                StandardCharsets.UTF_8));
        StringBuilder body = new StringBuilder();
        String line;
        while ((line = br.readLine()) != null) body.append(line);
        br.close();
        con.disconnect();

        if (code < 200 || code >= 300)
            throw new IllegalStateException("Portal HTTP " + code);
        PortalCorrectionStore.replaceFromJson(context, new JSONObject(body.toString()));
    }
}
