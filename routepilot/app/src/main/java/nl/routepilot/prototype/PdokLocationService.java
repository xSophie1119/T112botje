package nl.routepilot.prototype;

import org.json.JSONArray;
import org.json.JSONObject;

import java.net.URLEncoder;
import java.util.ArrayList;
import java.util.List;

public final class PdokLocationService {
    private static final String BASE="https://api.pdok.nl/kadaster/location-api/v1/search";
    private PdokLocationService(){}

    public static OnlineServices.SearchResult searchBest(String query) throws Exception{
        List<OnlineServices.SearchResult> all=search(query,5);
        if(all.isEmpty())throw new IllegalArgumentException("Bestemming niet gevonden in PDOK.");
        return all.get(0);
    }

    public static List<OnlineServices.SearchResult> search(String query,int limit)throws Exception{
        String q=query==null?"":query.trim();
        if(q.length()<2)throw new IllegalArgumentException("Voer minimaal 2 tekens in.");
        String url=BASE+"?f=json&adres%5Bversion%5D=1&limit="+Math.max(1,Math.min(10,limit))
                +"&q="+URLEncoder.encode(q,"UTF-8");
        JSONObject root=new JSONObject(HttpClient.get(url,7000));
        JSONArray features=root.optJSONArray("features");
        List<OnlineServices.SearchResult> out=new ArrayList<>();
        if(features==null)return out;
        for(int i=0;i<features.length();i++){
            JSONObject f=features.optJSONObject(i); if(f==null)continue;
            JSONObject g=f.optJSONObject("geometry");
            JSONObject p=f.optJSONObject("properties");
            if(g==null||p==null)continue;
            JSONArray c=g.optJSONArray("coordinates");
            if(c==null||c.length()<2)continue;
            String label=p.optString("display_name",p.optString("name",q));
            out.add(new OnlineServices.SearchResult(c.optDouble(1),c.optDouble(0),label));
        }
        return out;
    }
}
