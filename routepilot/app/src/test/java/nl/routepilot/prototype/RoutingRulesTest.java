package nl.routepilot.prototype;

import org.junit.Test;

import static org.junit.Assert.*;

public class RoutingRulesTest {

    @Test
    public void tilburgExemptionNeverLeaksOutsideMunicipality() {
        assertTrue(RoutingRules.busLaneExemptionAllowed(true, true));
        assertFalse(RoutingRules.busLaneExemptionAllowed(true, false));
        assertFalse(RoutingRules.busLaneExemptionAllowed(false, true));
    }

    @Test
    public void rightDoorPreferenceNeverBeatsAccessConstraints() {
        assertFalse(RoutingRules.doorPreferenceAllowed(true, false, false, 2));
        assertFalse(RoutingRules.doorPreferenceAllowed(false, true, false, 2));
        assertFalse(RoutingRules.doorPreferenceAllowed(false, false, true, 0));
        assertTrue(RoutingRules.doorPreferenceAllowed(false, false, true, 1));
        assertTrue(RoutingRules.doorPreferenceAllowed(false, false, false, 0));
    }

    @Test
    public void wmoNoShowThresholdIsExactlyThreeMinutes() {
        assertEquals(180000L, WmoSessionManager.WAIT_LIMIT_MS);
        assertFalse(RoutingRules.mayMarkNoShow(179999L));
        assertTrue(RoutingRules.mayMarkNoShow(180000L));
        assertEquals("3:00", WmoSessionManager.formatWait(180000L));
        assertEquals("0:00", WmoSessionManager.formatWait(0L));
    }
}
