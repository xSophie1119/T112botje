package nl.routepilot.prototype;

import android.app.Activity;
import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class DashboardActivity extends Activity {
    private static final Locale NL=new Locale("nl","NL");

    @Override protected void onCreate(Bundle b){
        super.onCreate(b);
        WebView web=new WebView(this);
        WebSettings s=web.getSettings();
        s.setJavaScriptEnabled(false);
        web.setBackgroundColor(0xff09101b);
        web.loadDataWithBaseURL(null,html(),"text/html","UTF-8",null);
        setContentView(web);
    }

    private String html(){
        List<RoutePilotStore.Trip> trips=RoutePilotStore.trips(this);
        List<RoutePilotStore.Report> reports=RoutePilotStore.reports(this);
        List<RoutePilotStore.LearnedPoint> learned=RoutePilotStore.learned(this);
        SimpleDateFormat df=new SimpleDateFormat("dd-MM HH:mm",NL);

        StringBuilder h=new StringBuilder();
        h.append("<html><head><meta name='viewport' content='width=device-width,initial-scale=1'>")
         .append("<style>body{background:#09101b;color:#f1f5f9;font-family:sans-serif;padding:18px}")
         .append(".card{background:#111b2c;border-radius:14px;padding:14px;margin:10px 0}")
         .append("h1{color:#38bdf8}h2{font-size:17px}small{color:#94a3b8}")
         .append("table{width:100%;border-collapse:collapse;font-size:12px}td{padding:7px;border-bottom:1px solid #26374f}")
         .append("</style></head><body>");
        h.append("<h1>RoutePilot Dashboard</h1><div class='card'><b>")
         .append(esc(RoutePilotStore.summary(this))).append("</b><br><small>Lokaal dashboard • debug</small></div>");

        h.append("<div class='card'><h2>Ritten & replay-data</h2><table>");
        int shown=0;
        for(RoutePilotStore.Trip t:trips){
            if(shown++>=15)break;
            h.append("<tr><td>").append(df.format(new Date(t.startedAt))).append("</td><td>")
             .append(esc(t.destinationLabel)).append("</td><td>")
             .append(String.format(NL,"%.1f km",t.actualDistanceM/1000.0)).append("</td><td>")
             .append(t.reroutes).append(" reroutes</td><td>").append(t.points.length()).append(" punten</td></tr>");
        }
        if(trips.isEmpty())h.append("<tr><td>Nog geen afgeronde ritten.</td></tr>");
        h.append("</table></div>");

        h.append("<div class='card'><h2>Chauffeursmeldingen</h2><table>");
        shown=0;
        for(int i=reports.size()-1;i>=0 && shown<20;i--,shown++){
            RoutePilotStore.Report r=reports.get(i);
            h.append("<tr><td>").append(df.format(new Date(r.time))).append("</td><td>")
             .append(esc(r.type)).append("</td><td>").append(esc(r.note)).append("</td></tr>");
        }
        if(reports.isEmpty())h.append("<tr><td>Nog geen meldingen.</td></tr>");
        h.append("</table></div>");

        h.append("<div class='card'><h2>Geleerde vermijdpunten</h2><table>");
        for(RoutePilotStore.LearnedPoint p:learned){
            h.append("<tr><td>").append(p.count).append("×</td><td>")
             .append(esc(p.reason)).append("</td><td><small>")
             .append(String.format(Locale.US,"%.5f, %.5f",p.lat,p.lon)).append("</small></td></tr>");
        }
        if(learned.isEmpty())h.append("<tr><td>RoutePilot heeft nog niets hoeven leren.</td></tr>");
        h.append("</table></div></body></html>");
        return h.toString();
    }

    private static String esc(String s){
        if(s==null)return "";
        return s.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;").replace("\"","&quot;");
    }
}
