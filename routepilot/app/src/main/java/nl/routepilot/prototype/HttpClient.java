package nl.routepilot.prototype;

import java.util.concurrent.TimeUnit;

import okhttp3.ConnectionPool;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

public final class HttpClient {
    private static final OkHttpClient CLIENT=new OkHttpClient.Builder()
            .connectTimeout(6,TimeUnit.SECONDS)
            .readTimeout(14,TimeUnit.SECONDS)
            .writeTimeout(10,TimeUnit.SECONDS)
            .callTimeout(20,TimeUnit.SECONDS)
            .connectionPool(new ConnectionPool(8,5,TimeUnit.MINUTES))
            .retryOnConnectionFailure(true)
            .build();

    private HttpClient(){}

    public static String get(String url,int timeoutMs)throws Exception{
        OkHttpClient c=CLIENT.newBuilder()
                .callTimeout(Math.max(1000,timeoutMs),TimeUnit.MILLISECONDS).build();
        Request r=new Request.Builder().url(url)
                .header("User-Agent",OnlineServices.USER_AGENT)
                .header("Accept-Encoding","gzip")
                .build();
        try(Response resp=c.newCall(r).execute()){
            if(!resp.isSuccessful())throw new IllegalStateException("HTTP "+resp.code());
            if(resp.body()==null)return "";
            return resp.body().string();
        }
    }

    public static String postJson(String url,String json,int timeoutMs)throws Exception{
        OkHttpClient c=CLIENT.newBuilder()
                .callTimeout(Math.max(1000,timeoutMs),TimeUnit.MILLISECONDS).build();
        RequestBody body=RequestBody.create(json,MediaType.get("application/json; charset=utf-8"));
        Request r=new Request.Builder().url(url).post(body)
                .header("User-Agent",OnlineServices.USER_AGENT)
                .header("Accept","application/geo+json, application/json")
                .header("Accept-Encoding","gzip")
                .build();
        try(Response resp=c.newCall(r).execute()){
            String text=resp.body()==null?"":resp.body().string();
            if(!resp.isSuccessful())throw new IllegalStateException("HTTP "+resp.code()+": "+trim(text,240));
            return text;
        }
    }

    private static String trim(String s,int max){
        if(s==null)return "";
        return s.length()<=max?s:s.substring(0,max);
    }
}
