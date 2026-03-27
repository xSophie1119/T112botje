<?php
class SND_Activator {

	public static function activate(): void {
		// Schedule fast RSS fetch every 15 min
		if ( ! wp_next_scheduled( 'snd_fetch_p2000_hook' ) ) {
			wp_schedule_event( time(), 'snd_fifteen_minutes', 'snd_fetch_p2000_hook' );
		}
		// Schedule geocoding batch every 2 min
		if ( ! wp_next_scheduled( 'snd_geocode_batch_hook' ) ) {
			wp_schedule_event( time() + 30, 'snd_two_minutes', 'snd_geocode_batch_hook' );
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'snd_fetch_p2000_hook' );
		wp_clear_scheduled_hook( 'snd_geocode_batch_hook' );
		flush_rewrite_rules();
	}
}
