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
        double totalKm=0.0;
        int totalReroutes=0;
        int totalWarnings=0;
        for(RoutePilotStore.Trip t:trips){
            totalKm+=t.actualDistanceM/1000.0;
            totalReroutes+=t.reroutes;
            totalWarnings+=t.warnings;
        }

        StringBuilder h=new StringBuilder();
        h.append("<html><head><meta name='viewport' content='width=device-width,initial-scale=1,viewport-fit=cover'>")
         .append("<style>*{box-sizing:border-box}body{margin:0;background:#060b13;color:#f8fafc;font-family:system-ui,-apple-system,sans-serif;padding:18px;line-height:1.35}")
         .append(".hero{background:linear-gradient(135deg,#0e1725,#11283a);border:1px solid #27415a;border-radius:22px;padding:20px;margin-bottom:14px;box-shadow:0 8px 24px #0006}")
         .append(".brand{font-size:12px;letter-spacing:2px;color:#38bdf8;font-weight:800}.hero h1{font-size:28px;margin:5px 0 3px}.sub{color:#94a3b8;font-size:12px}")
         .append(".grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:12px 0 16px}")
         .append(".kpi{background:#0e1725;border:1px solid #263c54;border-radius:18px;padding:14px}.kpi b{display:block;font-size:24px;color:#f8fafc}.kpi span{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.7px}")
         .append(".card{background:linear-gradient(145deg,#0e1725,#121e30);border:1px solid #263c54;border-radius:18px;padding:15px;margin:11px 0}")
         .append("h2{font-size:16px;margin:0 0 10px;color:#e2e8f0}small{color:#94a3b8}.chip{display:inline-block;background:#13283a;border:1px solid #24506d;color:#bae6fd;border-radius:999px;padding:5px 9px;font-size:11px;margin:3px 4px 3px 0}")
         .append("table{width:100%;border-collapse:collapse;font-size:12px}td{padding:9px 6px;border-bottom:1px solid #213247;vertical-align:top}tr:last-child td{border-bottom:0}")
         .append(".ok{color:#4ade80}.warn{color:#fb923c}.bad{color:#f87171}</style></head><body>");
        h.append("<div class='hero'><div class='brand'>ROUTEPILOT V3.1</div><h1>WMO Dashboard</h1><div class='sub'>Ritten, leerdata en operationele statistieken lokaal op dit toestel</div></div>")
         .append("<div class='grid'>")
         .append("<div class='kpi'><b>").append(trips.size()).append("</b><span>ritten</span></div>")
         .append("<div class='kpi'><b>").append(String.format(NL,"%.1f",totalKm)).append(" km</b><span>gereden</span></div>")
         .append("<div class='kpi'><b>").append(totalReroutes).append("</b><span>herrouteringen</span></div>")
         .append("<div class='kpi'><b>").append(totalWarnings).append("</b><span>waarschuwingen</span></div>")
         .append("</div>");

        List<WmoSessionManager.HistoryItem> wmo = WmoSessionManager.history(this);
        h.append("<div class='card'><h2>WMO-overzicht</h2>")
         .append("<span class='chip'>").append(wmo.size()).append(" WMO-statussen</span>")
         .append("<span class='chip'>gem. wachten ")
         .append(String.format(NL,"%.0f sec",WmoSessionManager.averageWaitSeconds(this))).append("</span>")
         .append("<span class='chip'>gem. uitstappen ")
         .append(String.format(NL,"%.0f sec",WmoSessionManager.averageDropoffSeconds(this))).append("</span>")
         .append("<span class='chip'>").append(WmoSessionManager.noShowCount(this)).append(" loos/no-show</span>")
         .append("<span class='chip'>").append(RoutePilotStore.pendingLearningCount(this)).append(" leerpunten open</span>")
         .append("</div>");

        h.append("<div class='card'><h2>Recente WMO-ritten</h2><table>");
        int wshown=0;
        for(WmoSessionManager.HistoryItem wh:wmo){
            if(wshown++>=12)break;
            h.append("<tr><td>").append(wh.startedAt>0?df.format(new Date(wh.startedAt)):"—").append("</td><td>")
             .append(esc(wh.pickupLabel)).append("</td><td>")
             .append(esc(wh.dropoffLabel)).append("</td><td>")
             .append("<span class='").append("NO_SHOW".equals(wh.result)?"bad":"ok").append("'>")
 .append(esc(wh.result)).append("</span></td><td>")
             .append(String.format(NL,"%.0f sec wachten",wh.waitMs/1000.0)).append("</td></tr>");
        }
        if(wmo.isEmpty())h.append("<tr><td>Nog geen WMO-ritten opgeslagen.</td></tr>");
        h.append("</table></div>");

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
             .append(p.confirmed?"✓ ":"? ").append(esc(p.reason)).append("</td><td><small>")
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
