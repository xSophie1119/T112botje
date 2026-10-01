/*
 * Decoder derived from HERE flexible-polyline reference implementation.
 * Copyright (C) 2019 HERE Europe B.V. - MIT License.
 * https://github.com/heremaps/flexible-polyline
 */
package nl.routepilot.prototype;

import org.osmdroid.util.GeoPoint;

import java.text.CharacterIterator;
import java.text.StringCharacterIterator;
import java.util.ArrayList;
import java.util.List;

public final class FlexiblePolylineDecoder {
    private static final int FORMAT_VERSION=1;
    private static final int[] DECODING_TABLE={
            62,-1,-1,52,53,54,55,56,57,58,59,60,61,-1,-1,-1,-1,-1,-1,-1,
            0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,
            22,23,24,25,-1,-1,-1,-1,63,-1,26,27,28,29,30,31,32,33,34,35,
            36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51
    };

    private FlexiblePolylineDecoder(){}

    public static List<GeoPoint> decode(String encoded){
        if(encoded==null||encoded.trim().isEmpty())throw new IllegalArgumentException("Lege HERE-polyline");
        CharacterIterator it=new StringCharacterIterator(encoded);
        long version=decodeUnsignedVarint(it);
        if(version!=FORMAT_VERSION)throw new IllegalArgumentException("Onbekende HERE-polylineversie");
        int header=(int)decodeUnsignedVarint(it);
        int precision=header&0x0f;
        int thirdDim=(header>>4)&0x07;
        int thirdPrecision=(header>>7)&0x0f;
        Converter lat=new Converter(precision),lon=new Converter(precision),z=new Converter(thirdPrecision);
        List<GeoPoint> out=new ArrayList<>();
        while(it.current()!=CharacterIterator.DONE){
            double la=lat.decode(it),lo=lon.decode(it);
            if(thirdDim!=0)z.decode(it);
            out.add(new GeoPoint(la,lo));
        }
        return out;
    }

    private static int decodeChar(char c){
        int pos=c-45;
        if(pos<0||pos>77)return -1;
        return DECODING_TABLE[pos];
    }

    private static long decodeUnsignedVarint(CharacterIterator it){
        short shift=0; long result=0;
        while(it.current()!=CharacterIterator.DONE){
            char c=it.current();it.next();
            long value=decodeChar(c);
            if(value<0)throw new IllegalArgumentException("Ongeldige HERE-polyline");
            result|=(value&0x1f)<<shift;
            if((value&0x20)==0)return result;
            shift+=5;
        }
        throw new IllegalArgumentException("Onverwacht einde HERE-polyline");
    }

    private static final class Converter{
        final long multiplier; long last=0;
        Converter(int precision){multiplier=(long)Math.pow(10,precision);}
        double decode(CharacterIterator it){
            long v=decodeUnsignedVarint(it);
            if((v&1)!=0)v=~v;
            v>>=1;
            last+=v;
            return (double)last/multiplier;
        }
    }
}
