package nl.routepilot.prototype;

import org.junit.Test;
import org.osmdroid.util.GeoPoint;

import java.util.List;

import static org.junit.Assert.*;

public class RoutingCoreV33Test {

    @Test
    public void flexiblePolylineMatchesOfficialHereExample() {
        List<GeoPoint> pts=FlexiblePolylineDecoder.decode("BFoz5xJ67i1B1B7PzIhaxL7Y");
        assertEquals(4,pts.size());
        assertEquals(50.10228,pts.get(0).getLatitude(),0.00001);
        assertEquals(8.69821,pts.get(0).getLongitude(),0.00001);
        assertEquals(50.09878,pts.get(3).getLatitude(),0.00001);
        assertEquals(8.68752,pts.get(3).getLongitude(),0.00001);
    }

    @Test
    public void arrivalRequiresStableThreeSecondsAndLowSpeed() {
        ArrivalDetector.State s=new ArrivalDetector.State();
        assertFalse(ArrivalDetector.update(s,1000,30,70,10,3,8));
        assertFalse(ArrivalDetector.update(s,3999,30,70,10,3,8));
        assertTrue(ArrivalDetector.update(s,4000,30,70,10,3,8));

        s.reset();
        assertFalse(ArrivalDetector.update(s,5000,30,70,10,14,8));
        assertFalse(ArrivalDetector.update(s,9000,30,70,10,14,8));
    }

    @Test
    public void arrivalRejectsPassingBehindDestination() {
        ArrivalDetector.State s=new ArrivalDetector.State();
        assertFalse(ArrivalDetector.update(s,1000,25,260,18,2,10));
        assertFalse(ArrivalDetector.update(s,5000,25,260,18,2,10));
    }

    @Test
    public void arrivalRejectsPoorGpsOrOffRoute() {
        ArrivalDetector.State s=new ArrivalDetector.State();
        assertFalse(ArrivalDetector.update(s,1000,20,40,80,1,10));
        assertFalse(ArrivalDetector.update(s,5000,20,40,10,1,80));
    }
}
