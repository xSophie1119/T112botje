<?php
/**
 * Handles the public-facing press portal (persportaal).
 * Intercepts requests to /persportaal/ and validates access codes.
 */
class SND_Portal {

	const OPTION_OUTLETS = 'snd_media_outlets_v4';
	const META_ACCESS_LOG = '_snd_access_log';
	const META_PRESS_PHOTOS = '_snd_press_photos';
	const META_PRESS_VIDEOS = '_snd_press_videos';
	const META_IS_LIVE = '_snd_is_live_story';
	const META_EXPIRATION = '_snd_expiration_date';
	const META_DISPATCH_LOG = '_snd_dispatch_log';
	const PORTAL_SLUG = 'persportaal';

	public function __construct() {
		add_action( 'init', [ $this, 'intercept_portal_request' ], 1 );

		// AJAX – works for both logged-in and anonymous users
		$actions = [
			'snd_portal_get_post'       => 'ajax_get_post',
			'snd_portal_log_view'       => 'ajax_log_view',
			'snd_portal_log_dl'         => 'ajax_log_download',
			'snd_portal_create_zip'     => 'ajax_create_zip',
			'snd_portal_get_map'        => 'ajax_get_portal_map',
			// Editor portal actions
			'snd_editor_get_map'          => 'ajax_editor_get_map',
			'snd_editor_link_p2000'       => 'ajax_editor_link_p2000',
			'snd_editor_update_location'  => 'ajax_editor_update_location',
			'snd_editor_get_posts'        => 'ajax_editor_get_posts',
			'snd_editor_get_incident'     => 'ajax_editor_get_incident',
			'snd_editor_add_log'          => 'ajax_editor_add_log',
			'snd_editor_set_status'       => 'ajax_editor_set_status',
			'snd_editor_add_manual_p2000' => 'ajax_editor_add_manual_p2000',
			'snd_editor_update_p2000_raw' => 'ajax_editor_update_p2000_raw',
			'snd_editor_save_note'        => 'ajax_editor_save_note',
			// P2000 live chat
			'snd_p2000_chat_send'       => 'ajax_p2000_chat_send',
			'snd_p2000_chat_get'        => 'ajax_p2000_chat_get',
			'snd_portal_check_new'      => 'ajax_portal_check_new',
			'snd_portal_record_view'    => 'ajax_portal_record_view',
		];
		foreach ( $actions as $action => $method ) {
			add_action( "wp_ajax_{$action}",        [ $this, $method ] );
			add_action( "wp_ajax_nopriv_{$action}", [ $this, $method ] );
		}
	}

	// ── Request interception ──────────────────────────────────────────────────

	public function intercept_portal_request(): void {
		$uri = strtok( $_SERVER['REQUEST_URI'], '?' );
		if ( false === strpos( $uri, '/' . self::PORTAL_SLUG ) ) {
			return;
		}

		$code   = isset( $_GET['access_code'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['access_code'] ) ) ) : null;
		$outlet = $this->validate_code( $code );

		if ( ! $outlet ) {
			wp_die(
				esc_html__( 'Toegang geweigerd. Gebruik de persoonlijke link uit uw e-mail.', 'nieuws-distributie-systeem' ),
				esc_html__( 'Toegang Geweigerd', 'nieuws-distributie-systeem' ),
				[ 'response' => 403 ]
			);
		}

		// Route: /persportaal/redactie → editor portal (role = redacteur only)
		if ( preg_match( '#/' . self::PORTAL_SLUG . '/redactie#', $uri ) ) {
			if ( ( $outlet['role'] ?? 'default' ) !== 'redacteur' ) {
				wp_die( esc_html__( 'Geen toegang. Dit portaal is alleen voor redacteuren.', 'nieuws-distributie-systeem' ), '', [ 'response' => 403 ] );
			}
			$GLOBALS['snd_outlet'] = $outlet;
			$tpl = SND_PLUGIN_PATH . 'templates/editor-portal.php';
			if ( file_exists( $tpl ) ) { include $tpl; exit; }
			wp_die( esc_html__( 'Redactieportaal-template niet gevonden.', 'nieuws-distributie-systeem' ) );
		}

		// Route: /persportaal/kaart → map page
		if ( preg_match( '#/' . self::PORTAL_SLUG . '/kaart#', $uri ) ) {
			$GLOBALS['snd_outlet'] = $outlet;
			$tpl = SND_PLUGIN_PATH . 'templates/portal-map.php';
			if ( file_exists( $tpl ) ) { include $tpl; exit; }
			wp_die( esc_html__( 'Kaarttemplate niet gevonden.', 'nieuws-distributie-systeem' ) );
		}

		// Default portal
		$tpl = SND_PLUGIN_PATH . 'templates/press-portal.php';
		if ( ! file_exists( $tpl ) ) {
			wp_die( esc_html__( 'Portaal-template niet gevonden.', 'nieuws-distributie-systeem' ) );
		}

		$GLOBALS['snd_outlet'] = $outlet;
		include $tpl;
		exit;
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	private function verify_portal_nonce(): void {
		if ( ! check_ajax_referer( 'snd_portal_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Sessie verlopen.', 'nieuws-distributie-systeem' ) ], 403 );
		}
	}

	public function ajax_get_post(): void {
		$this->verify_portal_nonce();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet  = $this->validate_code( $code );

		if ( ! $outlet ) {
			wp_send_json_error( [ 'message' => __( 'Authenticatie mislukt.', 'nieuws-distributie-systeem' ) ], 403 );
		}

		// Check that this outlet was actually sent this post
		$dispatch_log = get_post_meta( $post_id, self::META_DISPATCH_LOG, true );
		if ( empty( $dispatch_log ) || ! isset( $dispatch_log[ $outlet['email'] ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang tot dit bericht.', 'nieuws-distributie-systeem' ) ], 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( [ 'message' => __( 'Bericht niet gevonden.', 'nieuws-distributie-systeem' ) ] );
		}

		// Check if outlet is blocked from downloads for this post
		$blocked_outlets = get_post_meta( $post_id, '_snd_blocked_outlets', true );
		$is_download_blocked = is_array( $blocked_outlets ) && in_array( $outlet['email'], $blocked_outlets, true );

		// Log the view
		$this->log_view( $post_id, $outlet );

		$photos = [];
		if ( ! $is_download_blocked ) {
			$post_slug = sanitize_title( $post->post_title );
			$counter   = 1;
			foreach ( $this->get_photo_ids( $post_id ) as $id ) {
				if ( 'attachment' !== get_post_type( $id ) ) continue;
				$full  = wp_get_attachment_url( $id );
				$thumb = wp_get_attachment_image_url( $id, 'medium_large' );
				$att   = get_post( $id );
				if ( $full && $thumb ) {
					// Bestandsnaam: artikel-titel-dd-mm-yyyy-N.ext
					$ext          = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
					$upload_date  = $att ? get_the_date( 'd-m-Y', $att->ID ) : wp_date( 'd-m-Y' );
					$clean_name   = $post_slug . '-' . $upload_date . ( $counter > 1 ? '-' . $counter : '' ) . '.' . $ext;
					$photos[] = [
						'id'       => $id,
						'url'      => $thumb,
						'full_url' => $full,
						'filename' => $clean_name,
						'caption'  => $att ? $att->post_excerpt : '',
					];
					$counter++;
				}
			}
		}

		// Videos — externe links (Mega, Dropbox, etc.)
		$videos = [];
		if ( ! $is_download_blocked ) {
			$vid_json = get_post_meta( $post_id, '_snd_press_videos', true );
			$vid_data = ! empty( $vid_json ) ? json_decode( $vid_json, true ) : [];
			if ( is_array( $vid_data ) ) {
				foreach ( $vid_data as $v ) {
					$url = esc_url( $v['url'] ?? '' );
					if ( $url ) {
						$videos[] = [
							'url'   => $url,
							'title' => $v['title'] ?? '',
							'size'  => $v['size']  ?? '',
						];
					}
				}
			}
		}

		wp_send_json_success( [
			'post_content'        => apply_filters( 'the_content', $post->post_content ),
			'post_url'            => get_permalink( $post_id ),
			'is_live'             => get_post_meta( $post_id, self::META_IS_LIVE, true ) === '1',
			'lat'                 => get_post_meta( $post_id, '_snd_lat', true ),
			'lon'                 => get_post_meta( $post_id, '_snd_lon', true ),
			'p2000_message'       => get_post_meta( $post_id, '_snd_p2000_raw_message', true ),
			'p2000_meldingen'     => $this->get_all_p2000_meldingen( $post_id ),
			'press_photos'        => $photos,
			'press_videos'        => $videos,
			'download_blocked'    => $is_download_blocked,
			'byline'              => get_post_meta( $post_id, '_snd_byline', true ),
			'incident_status'     => get_post_meta( $post_id, '_snd_incident_status', true ) ?: '',
			'status_times'        => get_post_meta( $post_id, '_snd_status_times', true ) ?: [],
			'eta_minutes'         => (int) get_post_meta( $post_id, '_snd_eta_minutes', true ),
			'eta_set_at'          => (int) get_post_meta( $post_id, '_snd_eta_set_at', true ),
			'live_viewers'        => (int) get_post_meta( $post_id, '_snd_live_viewers', true ),
		] );
	}

	public function ajax_log_view(): void {
		$this->verify_portal_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet  = $this->validate_code( $code );
		if ( $outlet ) {
			$this->log_view( $post_id, $outlet );
		}
		wp_send_json_success();
	}

	public function ajax_log_download(): void {
		$this->verify_portal_nonce();
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$code     = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$image_id = sanitize_text_field( wp_unslash( $_POST['image_id'] ?? '' ) );
		$outlet   = $this->validate_code( $code );
		if ( $outlet ) {
			$this->log_download_action( $post_id, $image_id, $outlet );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function ajax_create_zip(): void {
		$this->verify_portal_nonce();

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( [ 'message' => __( 'ZipArchive niet beschikbaar op deze server.', 'nieuws-distributie-systeem' ) ] );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet  = $this->validate_code( $code );

		if ( ! $outlet ) {
			wp_send_json_error( [ 'message' => __( 'Authenticatie mislukt.', 'nieuws-distributie-systeem' ) ], 403 );
		}

		// Respect download block
		$blocked_outlets = get_post_meta( $post_id, '_snd_blocked_outlets', true );
		if ( is_array( $blocked_outlets ) && in_array( $outlet['email'], $blocked_outlets, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Uw downloadtoegang voor dit bericht is ingetrokken.', 'nieuws-distributie-systeem' ) ], 403 );
		}

		$selected_ids = isset( $_POST['selected_ids'] ) && is_array( $_POST['selected_ids'] )
			? array_map( 'absint', $_POST['selected_ids'] )
			: [];

		$image_ids  = ! empty( $selected_ids ) ? $selected_ids : $this->get_photo_ids( $post_id );
		$log_type   = ! empty( $selected_ids ) ? 'selective_zip' : 'all_zip';

		if ( empty( $image_ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen foto\'s om te zippen.', 'nieuws-distributie-systeem' ) ] );
		}

		$post      = get_post( $post_id );
		$post_slug = $post ? sanitize_title( $post->post_title ) : 'persfoto';
		$upload    = wp_upload_dir();
		$zip_dir   = $upload['basedir'] . '/pers-zips/';
		$zip_name  = 'persfotos-' . $post_slug . '-' . wp_date( 'd-m-Y' ) . '.zip';
		$zip_path  = $zip_dir . $zip_name;

		if ( ! is_dir( $zip_dir ) && ! wp_mkdir_p( $zip_dir ) ) {
			wp_send_json_error( [ 'message' => __( 'Kon de ZIP-map niet aanmaken.', 'nieuws-distributie-systeem' ) ] );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_send_json_error( [ 'message' => __( 'Kon ZIP niet aanmaken.', 'nieuws-distributie-systeem' ) ] );
		}

		$added   = 0;
		$counter = 1;
		foreach ( $image_ids as $id ) {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				$att         = get_post( $id );
				$ext         = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
				$upload_date = $att ? get_the_date( 'd-m-Y', $att->ID ) : wp_date( 'd-m-Y' );
				$zip_entry   = $post_slug . '-' . $upload_date . ( $counter > 1 ? '-' . $counter : '' ) . '.' . $ext;
				$zip->addFile( $file, $zip_entry );
				$added++;
				$counter++;
			}
		}
		$zip->close();

		if ( 0 === $added ) {
			@unlink( $zip_path );
			wp_send_json_error( [ 'message' => __( 'Geen geldige bestanden gevonden.', 'nieuws-distributie-systeem' ) ] );
		}

		$this->log_download_action( $post_id, $log_type, $outlet );

		wp_send_json_success( [ 'zip_url' => $upload['baseurl'] . '/pers-zips/' . $zip_name ] );
	}

	// ── AJAX: Portal map data ─────────────────────────────────────────────────

	public function ajax_get_portal_map(): void {
		$this->verify_portal_nonce();
		$code   = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet = $this->validate_code( $code );
		if ( ! $outlet ) {
			wp_send_json_error( [ 'message' => 'Authenticatie mislukt.' ], 403 );
		}

		// ── Incidents dispatched to this outlet that have lat/lon ─────────────
		$dispatch_meta_value = '"' . esc_sql( $outlet['email'] ) . '"';
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 200,
			'post_status'    => 'publish',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_snd_dispatch_log', 'value' => $dispatch_meta_value, 'compare' => 'LIKE' ],
				[ 'key' => '_snd_lat',          'compare' => 'EXISTS' ],
			],
		] );

		$markers    = [];
		$linked_ids = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid = get_the_ID();
				$lat = (float) get_post_meta( $pid, '_snd_lat', true );
				$lon = (float) get_post_meta( $pid, '_snd_lon', true );
				if ( ! $lat || ! $lon ) continue;

				$exp = get_post_meta( $pid, self::META_EXPIRATION, true );
				if ( ! empty( $exp ) && current_time( 'Y-m-d' ) > $exp ) continue;

				$p2000_id = get_post_meta( $pid, '_snd_p2000_id', true );
				if ( $p2000_id ) $linked_ids[] = $p2000_id;

				$markers[] = [
					'id'              => $pid,
					'type'            => 'incident',
					'title'           => get_the_title(),
					'lat'             => $lat,
					'lon'             => $lon,
					'street'          => get_post_meta( $pid, '_snd_street1', true ),
					'date'            => get_the_date( 'd-m-Y H:i' ),
					'is_live'         => get_post_meta( $pid, self::META_IS_LIVE, true ) === '1',
					'thumb'           => has_post_thumbnail( $pid ) ? get_the_post_thumbnail_url( $pid, 'thumbnail' ) : '',
					'p2000'           => get_post_meta( $pid, '_snd_p2000_raw_message', true ),
					'incident_status' => get_post_meta( $pid, '_snd_incident_status', true ) ?: '',
					'byline'          => get_post_meta( $pid, '_snd_byline', true ),
				];
			}
			wp_reset_postdata();
		}

		// ── P2000 feed markers ────────────────────────────────────────────────
		$feeds      = get_option( 'snd_p2000_feeds_structured', [] );
		$all_p2000  = array_merge( $feeds['brandweer'] ?? [], $feeds['politie'] ?? [], $feeds['mmt'] ?? [] );
		$p2000_markers = [];

		foreach ( $all_p2000 as $msg ) {
			if ( ! is_numeric( $msg['lat'] ?? '' ) || ! is_numeric( $msg['lon'] ?? '' ) ) continue;

			$is_linked  = in_array( $msg['id'], $linked_ids, true );
			$p2000_markers[] = [
				'id'      => $msg['id'],
				'type'    => 'p2000',
				'subtype' => $msg['type'] ?? 'overig',
				'tekst'   => $msg['tekst'] ?? '',
				'stad'    => $msg['stad'] ?? '',
				'straat'  => $msg['straat'] ?? '',
				'tijd'    => isset( $msg['tijd'] ) ? wp_date( 'H:i', $msg['tijd'] ) : '',
				'lat'     => (float) $msg['lat'],
				'lon'     => (float) $msg['lon'],
				'linked'  => $is_linked,
			];
		}

		wp_send_json_success( [
			'markers'       => $markers,
			'p2000_markers' => $p2000_markers,
		] );
	}

	// ── Editor portal AJAX (role = redacteur) ─────────────────────────────────

	private function auth_editor(): array {
		if ( ! check_ajax_referer( 'snd_editor_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Sessie verlopen.' ], 403 );
		}
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		foreach ( $outlets as $outlet ) {
			if ( isset( $outlet['access_code'] ) && hash_equals( trim( $outlet['access_code'] ), $code ) ) {
				if ( ( $outlet['role'] ?? 'default' ) !== 'redacteur' ) {
					wp_send_json_error( [ 'message' => 'Geen redacteurstoegang.' ], 403 );
				}
				return $outlet;
			}
		}
		wp_send_json_error( [ 'message' => 'Ongeldige code.' ], 403 );
		return [];
	}

	public function ajax_editor_get_map(): void {
		$this->auth_editor();

		$query = new \WP_Query( [
			'post_type' => 'post', 'posts_per_page' => 200, 'post_status' => 'publish',
			'meta_query' => [ [ 'key' => '_snd_lat', 'compare' => 'EXISTS' ] ],
		] );
		$incidents = []; $linked_ids = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid = get_the_ID();
				$lat = (float) get_post_meta( $pid, '_snd_lat', true );
				$lon = (float) get_post_meta( $pid, '_snd_lon', true );
				if ( ! $lat || ! $lon ) continue;
				$p2k = get_post_meta( $pid, '_snd_p2000_id', true );
				if ( $p2k ) $linked_ids[] = $p2k;
				$incidents[] = [
					'id' => $pid, 'title' => get_the_title(), 'lat' => $lat, 'lon' => $lon,
					'street' => get_post_meta( $pid, '_snd_street1', true ),
					'date' => get_the_date( 'd-m-Y H:i' ), 'p2000_id' => $p2k,
					'dispatched' => ! empty( get_post_meta( $pid, '_snd_dispatch_log', true ) ),
					'is_live' => get_post_meta( $pid, '_snd_is_live_story', true ) === '1',
					'status' => get_post_meta( $pid, '_snd_incident_status', true ) ?: '',
					'photo_count' => count( array_filter( explode( ',', get_post_meta( $pid, '_snd_press_photos', true ) ) ) ),
					'dispatch_count' => count( (array) get_post_meta( $pid, '_snd_dispatch_log', true ) ),
				];
			}
			wp_reset_postdata();
		}

		$feeds = get_option( 'snd_p2000_feeds_structured', [] );
		$all   = array_merge( $feeds['brandweer'] ?? [], $feeds['politie'] ?? [], $feeds['mmt'] ?? [] );
		$p2000 = [];
		foreach ( $all as $msg ) {
			if ( ! is_numeric( $msg['lat'] ?? '' ) || ! is_numeric( $msg['lon'] ?? '' ) ) continue;
			$p2000[] = [
				'id' => $msg['id'], 'lat' => (float)$msg['lat'], 'lon' => (float)$msg['lon'],
				'tekst' => $msg['tekst'] ?? '', 'stad' => $msg['stad'] ?? '',
				'straat' => $msg['straat'] ?? '', 'type' => $msg['type'] ?? 'overig',
				'tijd' => isset($msg['tijd']) ? wp_date('H:i',$msg['tijd']) : '',
				'linked' => in_array( $msg['id'], $linked_ids, true ),
			];
		}
		wp_send_json_success( [ 'incidents' => $incidents, 'p2000' => $p2000 ] );
	}

	public function ajax_editor_link_p2000(): void {
		$this->auth_editor();
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$p2000_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$feeds = get_option( 'snd_p2000_feeds_structured', [] );
		$msg = null;
		foreach ( $feeds as $messages ) {
			if ( isset( $messages[$p2000_id] ) ) { $msg = $messages[$p2000_id]; break; }
		}
		if ( ! $msg ) { wp_send_json_error( [ 'message' => 'Melding niet gevonden.' ] ); }
		update_post_meta( $post_id, '_snd_p2000_id',          $p2000_id );
		update_post_meta( $post_id, '_snd_p2000_raw_message',  $msg['tekst'] ?? '' );
		if ( is_numeric( $msg['lat'] ?? '' ) ) update_post_meta( $post_id, '_snd_lat', $msg['lat'] );
		if ( is_numeric( $msg['lon'] ?? '' ) ) update_post_meta( $post_id, '_snd_lon', $msg['lon'] );
		if ( ! empty($msg['straat']) ) update_post_meta( $post_id, '_snd_street1', $msg['straat'] );
		wp_send_json_success( [ 'message' => 'Gekoppeld.', 'lat' => $msg['lat']??'', 'lon' => $msg['lon']??'' ] );
	}

	public function ajax_editor_update_location(): void {
		$this->auth_editor();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$lat     = sanitize_text_field( wp_unslash( $_POST['lat'] ?? '' ) );
		$lon     = sanitize_text_field( wp_unslash( $_POST['lon'] ?? '' ) );
		$street  = sanitize_text_field( wp_unslash( $_POST['street'] ?? '' ) );
		if ( ! $post_id || ! $lat || ! $lon ) {
			wp_send_json_error( [ 'message' => 'Ongeldige parameters.' ] );
		}
		update_post_meta( $post_id, '_snd_lat', $lat );
		update_post_meta( $post_id, '_snd_lon', $lon );
		update_post_meta( $post_id, '_snd_street1', $street );
		wp_send_json_success( [ 'message' => 'Locatie opgeslagen.' ] );
	}

	public function ajax_editor_get_posts(): void {
		$this->auth_editor();
		$q = new \WP_Query( [ 'post_type'=>'post','posts_per_page'=>100,'post_status'=>'publish','orderby'=>'date','order'=>'DESC' ] );
		$posts = [];
		if ( $q->have_posts() ) { while ($q->have_posts()) { $q->the_post(); $posts[] = ['id'=>get_the_ID(),'title'=>get_the_title()]; } wp_reset_postdata(); }
		wp_send_json_success( $posts );
	}

	public function ajax_editor_get_incident(): void {
		$this->auth_editor();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) wp_send_json_error( [ 'message' => 'Niet gevonden.' ] );

		$log      = get_post_meta( $post_id, '_snd_dispatch_log', true );
		$acc_log  = get_post_meta( $post_id, '_snd_access_log', true );
		$status   = get_post_meta( $post_id, '_snd_incident_status', true ) ?: '';
		$p2000_id = get_post_meta( $post_id, '_snd_p2000_id', true ) ?: '';
		$p2000_raw= get_post_meta( $post_id, '_snd_p2000_raw_message', true ) ?: '';
		$lat      = get_post_meta( $post_id, '_snd_lat', true );
		$lon      = get_post_meta( $post_id, '_snd_lon', true );
		$street   = get_post_meta( $post_id, '_snd_street1', true );
		$is_live  = get_post_meta( $post_id, '_snd_is_live_story', true ) === '1';
		$photos   = array_filter( explode( ',', get_post_meta( $post_id, '_snd_press_photos', true ) ) );

		// Manual P2000 meldingen linked to this post
		$manual_all    = get_option( 'snd_p2000_manual_links', [] );
		$manual_feed   = $p2000_id ? ( $manual_all[ $p2000_id ]     ?? [] ) : [];
		$manual_post   = $manual_all[ 'post_' . $post_id ] ?? [];
		$manual_merged = array_values( $manual_feed + $manual_post );

		// Outlets who viewed / downloaded
		$views = [];
		if ( is_array( $acc_log ) ) {
			foreach ( $acc_log as $email => $data ) {
				$views[] = [
					'name'      => $data['name']      ?? $email,
					'views'     => count( $data['views']     ?? [] ),
					'downloads' => count( $data['downloads'] ?? [] ),
					'last_view' => ! empty( $data['views'] ) ? max( $data['views'] ) : null,
				];
			}
		}

		wp_send_json_success( [
			'id'         => $post_id,
			'title'      => $post->post_title,
			'content'    => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ),
			'date'       => get_the_date( 'd-m-Y H:i', $post ),
			'edit_url'   => get_edit_post_link( $post_id ),
			'status'     => $status,
			'is_live'    => $is_live,
			'lat'        => $lat,
			'lon'        => $lon,
			'street'     => $street,
			'p2000_id'   => $p2000_id,
			'p2000_raw'  => $p2000_raw,
			'photo_count'  => count( $photos ),
			'dispatched'   => is_array( $log ) ? count( $log ) : 0,
			'manual_p2000' => $manual_merged,
			'views'        => $views,
			'editor_log'   => array_values( get_post_meta( $post_id, '_snd_editor_log', true ) ?: [] ),
			'internal_note'=> get_post_meta( $post_id, '_snd_internal_note', true ) ?: '',
		] );
	}

	public function ajax_editor_add_log(): void {
		$this->auth_editor();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$text    = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		if ( ! $post_id || empty( $text ) ) wp_send_json_error( [ 'message' => 'Verplichte velden ontbreken.' ] );

		// Reuse admin log entry logic via direct option update
		$active = get_option( 'snd_p2000_active_statuses', [] );
		// Find matching active melding by post p2000_id
		$p2000_id = get_post_meta( $post_id, '_snd_p2000_id', true );
		$found    = null;
		if ( $p2000_id ) {
			foreach ( $active as $mid => $item ) {
				if ( ( $item['source'] ?? '' ) !== 'handmatig' && $mid === $p2000_id ) { $found = $mid; break; }
			}
		}

		$entry = [
			'time' => time(),
			'user' => $this->get_editor_name(),
			'text' => $text,
		];

		if ( $found ) {
			if ( ! is_array( $active[ $found ]['log'] ?? null ) ) $active[ $found ]['log'] = [];
			$active[ $found ]['log'][] = $entry;
			update_option( 'snd_p2000_active_statuses', $active );
		}

		// Also store log entry directly on the post meta for persistence
		$post_log   = get_post_meta( $post_id, '_snd_editor_log', true );
		$post_log   = is_array( $post_log ) ? $post_log : [];
		$post_log[] = $entry;
		update_post_meta( $post_id, '_snd_editor_log', $post_log );

		wp_send_json_success( [ 'entry' => $entry, 'time_str' => wp_date( 'H:i', $entry['time'] ) ] );
	}

	public function ajax_editor_set_status(): void {
		$this->auth_editor();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$status  = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
		if ( ! $post_id ) wp_send_json_error( [ 'message' => 'Geen post ID.' ] );

		$allowed = [ '', 'onderweg', 'ter_plaatse', 'afgerond' ];
		if ( ! in_array( $status, $allowed, true ) ) wp_send_json_error( [ 'message' => 'Ongeldige status.' ] );

		// Update WP post meta
		update_post_meta( $post_id, '_snd_incident_status', $status );
		$times         = get_post_meta( $post_id, '_snd_status_times', true );
		$times         = is_array( $times ) ? $times : [];
		$times[$status]= time();
		update_post_meta( $post_id, '_snd_status_times', $times );

		// Also sync to active_statuses if there's a linked P2000 melding
		$p2000_id = get_post_meta( $post_id, '_snd_p2000_id', true );
		if ( $p2000_id ) {
			$active = get_option( 'snd_p2000_active_statuses', [] );
			if ( isset( $active[ $p2000_id ] ) ) {
				$active[ $p2000_id ]['status'] = $status;
				update_option( 'snd_p2000_active_statuses', $active );
			}
		}

		wp_send_json_success( [ 'message' => 'Status opgeslagen.' ] );
	}

	public function ajax_editor_add_manual_p2000(): void {
		$this->auth_editor();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$tekst   = sanitize_text_field( wp_unslash( $_POST['tekst'] ?? '' ) );
		$type    = sanitize_text_field( wp_unslash( $_POST['type']  ?? 'overig' ) );
		if ( ! $post_id || empty( $tekst ) ) wp_send_json_error( [ 'message' => 'Verplicht.' ] );

		$allowed_types = [ 'brandweer', 'politie', 'mmt', 'overig' ];
		if ( ! in_array( $type, $allowed_types, true ) ) $type = 'overig';

		$key   = 'post_' . $post_id;
		$links = get_option( 'snd_p2000_manual_links', [] );
		if ( ! is_array( $links[ $key ] ?? null ) ) $links[ $key ] = [];
		$entry = [ 'tekst' => $tekst, 'type' => $type, 'tijd' => time() ];
		$links[ $key ][] = $entry;
		update_option( 'snd_p2000_manual_links', $links );

		wp_send_json_success( [ 'entry' => $entry, 'idx' => count( $links[$key] ) - 1 ] );
	}

	public function ajax_editor_update_p2000_raw(): void {
		$this->auth_editor();
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$raw_text = sanitize_textarea_field( wp_unslash( $_POST['raw_text'] ?? '' ) );
		if ( ! $post_id ) wp_send_json_error( [ 'message' => 'Geen post ID.' ] );

		update_post_meta( $post_id, '_snd_p2000_raw_message', $raw_text );
		if ( empty( $raw_text ) ) {
			delete_post_meta( $post_id, '_snd_p2000_id' );
		}
		wp_send_json_success( [ 'message' => 'Meldingtekst opgeslagen.' ] );
	}

	public function ajax_editor_save_note(): void {
		$this->auth_editor();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$note    = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		if ( ! $post_id ) wp_send_json_error( [ 'message' => 'Geen post ID.' ] );

		update_post_meta( $post_id, '_snd_internal_note', $note );
		wp_send_json_success( [ 'message' => 'Notitie opgeslagen.' ] );
	}

	private function get_editor_name(): string {
		// Editor portals don't have WP users — use outlet name from session
		$outlet = $GLOBALS['snd_outlet'] ?? null;
		return $outlet['name'] ?? 'Redacteur';
	}

	private function validate_code( ?string $code ): ?array {
		if ( empty( $code ) ) return null;

		// Brute-force protection: max 20 failed attempts per IP per hour
		$ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$fail_key = 'snd_portal_fail_' . md5( $ip );
		$fails    = (int) get_transient( $fail_key );
		if ( $fails >= 20 ) return null; // silently block

		$outlets = get_option( self::OPTION_OUTLETS, [] );
		foreach ( $outlets as $outlet ) {
			if ( isset( $outlet['access_code'] ) && hash_equals( trim( $outlet['access_code'] ), $code ) ) {
				// Success — clear fail counter
				delete_transient( $fail_key );
				return $outlet;
			}
		}

		// Failed attempt — increment counter (expire after 1 hour)
		set_transient( $fail_key, $fails + 1, HOUR_IN_SECONDS );
		return null;
	}

	private function get_photo_ids( int $post_id ): array {
		$str = get_post_meta( $post_id, self::META_PRESS_PHOTOS, true );
		return ! empty( $str ) ? array_filter( explode( ',', $str ) ) : [];
	}

	private function get_press_video_ids( int $post_id ): array {
		$str = get_post_meta( $post_id, self::META_PRESS_VIDEOS, true );
		return ! empty( $str ) ? array_filter( explode( ',', $str ) ) : [];
	}

	/**
	 * Returns all P2000 meldingen for a post: the original feed entry + all manual ones.
	 * Keyed by type: 'feed' | 'manual'. Ordered chronologically (oldest first).
	 */
	private function get_all_p2000_meldingen( int $post_id ): array {
		$result   = [];
		$p2000_id = get_post_meta( $post_id, '_snd_p2000_id', true ) ?: '';
		$raw      = get_post_meta( $post_id, '_snd_p2000_raw_message', true ) ?: '';

		// Original feed melding
		if ( $raw ) {
			// Try to find the time from the feed data
			$feeds = get_option( 'snd_p2000_feeds_structured', [] );
			$tijd  = null;
			foreach ( $feeds as $msgs ) {
				if ( isset( $msgs[ $p2000_id ]['tijd'] ) ) {
					$tijd = $msgs[ $p2000_id ]['tijd'];
					break;
				}
			}
			$result[] = [
				'source' => 'feed',
				'type'   => 'overig',
				'tekst'  => $raw,
				'tijd'   => $tijd,
			];
		}

		// Manual entries linked via feed ID
		$manual_links = get_option( 'snd_p2000_manual_links', [] );
		if ( $p2000_id && ! empty( $manual_links[ $p2000_id ] ) ) {
			foreach ( $manual_links[ $p2000_id ] as $entry ) {
				$result[] = [
					'source' => 'manual',
					'type'   => $entry['type'] ?? 'overig',
					'tekst'  => $entry['tekst'] ?? '',
					'tijd'   => $entry['tijd'] ?? null,
				];
			}
		}

		// Manual entries linked via post ID
		$post_key = 'post_' . $post_id;
		if ( ! empty( $manual_links[ $post_key ] ) ) {
			foreach ( $manual_links[ $post_key ] as $entry ) {
				$result[] = [
					'source' => 'manual',
					'type'   => $entry['type'] ?? 'overig',
					'tekst'  => $entry['tekst'] ?? '',
					'tijd'   => $entry['tijd'] ?? null,
				];
			}
		}

		// Sort by time (oldest first, nulls last)
		usort( $result, function( $a, $b ) {
			if ( $a['tijd'] === null && $b['tijd'] === null ) return 0;
			if ( $a['tijd'] === null ) return 1;
			if ( $b['tijd'] === null ) return -1;
			return $a['tijd'] - $b['tijd'];
		} );

		return $result;
	}

	private function log_view( int $post_id, array $outlet ): void {
		if ( get_option( 'snd_test_mode_enabled' ) === '1' || empty( $outlet['email'] ) ) return;
		$log   = get_post_meta( $post_id, self::META_ACCESS_LOG, true );
		if ( ! is_array( $log ) ) $log = [];
		$email = $outlet['email'];

		$is_first_view = ! isset( $log[ $email ] );
		if ( $is_first_view ) {
			$log[ $email ] = [ 'name' => $outlet['name'], 'views' => [], 'downloads' => [] ];
		}
		$log[ $email ]['views'][] = time();
		update_post_meta( $post_id, self::META_ACCESS_LOG, $log );

		// Telegram: notify on first view only (not every page load)
		if ( $is_first_view && get_option( 'snd_telegram_notify_first_view', '1' ) === '1' ) {
			$tg_token   = get_option( 'snd_telegram_bot_token', '' );
			$tg_chat_id = get_option( 'snd_telegram_chat_id', '' );
			if ( $tg_token && $tg_chat_id ) {
				$post  = get_post( $post_id );
				$title = $post ? $post->post_title : "Post #{$post_id}";
				wp_remote_post(
					'https://api.telegram.org/bot' . $tg_token . '/sendMessage',
					[ 'timeout' => 5, 'body' => [
						'chat_id'    => $tg_chat_id,
						'text'       => "👁 *{$outlet['name']}* heeft het persbericht voor het eerst geopend\n📰 _{$title}_",
						'parse_mode' => 'Markdown',
					] ]
				);
			}
		}
	}

	private function log_download_action( int $post_id, $image_id, array $outlet ): void {
		if ( get_option( 'snd_test_mode_enabled' ) === '1' || empty( $outlet['email'] ) ) return;

		$log   = get_post_meta( $post_id, self::META_ACCESS_LOG, true );
		if ( ! is_array( $log ) ) $log = [];
		$email = $outlet['email'];
		if ( ! isset( $log[ $email ] ) ) {
			$log[ $email ] = [ 'name' => $outlet['name'], 'views' => [], 'downloads' => [] ];
		}
		$log[ $email ]['downloads'][] = [ 'image_id' => $image_id, 'time' => time() ];
		update_post_meta( $post_id, self::META_ACCESS_LOG, $log );

		// Telegram notification on download
		$tg_token   = get_option( 'snd_telegram_bot_token', '' );
		$tg_chat_id = get_option( 'snd_telegram_chat_id', '' );
		if ( ! $tg_token || ! $tg_chat_id ) return;
		if ( get_option( 'snd_telegram_notify_download', '1' ) !== '1' ) return;

		$post  = get_post( $post_id );
		$title = $post ? $post->post_title : "Post #{$post_id}";

		if ( $image_id === 'all_zip' || $image_id === 'selective_zip' ) {
			$type  = $image_id === 'all_zip' ? 'alle foto\'s (ZIP)' : 'selectie foto\'s (ZIP)';
			$msg   = "📥 *{$outlet['name']}* heeft {$type} gedownload\n📰 _{$title}_";
		} else {
			$filename = is_numeric( $image_id ) ? basename( get_attached_file( (int) $image_id ) ?: "foto #{$image_id}" ) : $image_id;
			$msg      = "📥 *{$outlet['name']}* heeft een foto gedownload\n🖼 _{$filename}_\n📰 _{$title}_";
		}

		wp_remote_post(
			'https://api.telegram.org/bot' . $tg_token . '/sendMessage',
			[ 'timeout' => 6, 'body' => [ 'chat_id' => $tg_chat_id, 'text' => $msg, 'parse_mode' => 'Markdown' ] ]
		);
	}

	// ── P2000 live chat ───────────────────────────────────────────────────────
	// Any outlet can send a message/question about an active P2000 item.
	// Messages are stored in a WP option per P2000 ID and shown in admin.

	public function ajax_portal_check_new(): void {
		$this->verify_portal_nonce();
		$code   = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet = $this->validate_code( $code );
		if ( ! $outlet ) wp_send_json_error( [], 403 );

		// Client sends known_ids as JSON array
		$known_raw = sanitize_text_field( wp_unslash( $_POST['known_ids'] ?? '[]' ) );
		$known_ids = json_decode( $known_raw, true );
		if ( ! is_array( $known_ids ) ) $known_ids = [];
		$known_ids = array_map( 'absint', $known_ids );

		$query = new WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'     => '_snd_dispatch_log',
				'value'   => '"' . esc_sql( $outlet['email'] ) . '"',
				'compare' => 'LIKE',
			] ],
		] );

		$current_ids = array_map( 'absint', $query->posts );
		$new_ids     = array_values( array_diff( $current_ids, $known_ids ) );

		wp_send_json_success( [
			'post_ids'  => $current_ids,
			'new_ids'   => $new_ids,
			'new_count' => count( $new_ids ),
		] );
	}

	public function ajax_p2000_chat_send(): void {
		$this->verify_portal_nonce();
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet  = $this->validate_code( $code );
		if ( ! $outlet ) {
			wp_send_json_error( [ 'message' => 'Authenticatie mislukt.' ], 403 );
		}

		$p2000_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$body     = sanitize_textarea_field( wp_unslash( $_POST['body']    ?? '' ) );

		if ( empty( $p2000_id ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => 'Bericht is leeg.' ] );
		}

		$key      = 'snd_p2000_chat_' . md5( $p2000_id );
		$messages = get_option( $key, [] );
		if ( ! is_array( $messages ) ) $messages = [];

		$messages[] = [
			'sender' => 'outlet',
			'name'   => $outlet['name'],
			'email'  => $outlet['email'],
			'body'   => $body,
			'time'   => wp_date( 'H:i' ),
			'ts'     => time(),
		];
		update_option( $key, $messages );

		// Telegram notification
		$tg_token   = get_option( 'snd_telegram_bot_token', '' );
		$tg_chat_id = get_option( 'snd_telegram_chat_id', '' );
		if ( $tg_token && $tg_chat_id ) {
			$active = get_option( 'snd_p2000_active_statuses', [] );
			$item   = $active[ $p2000_id ] ?? [];
			$locatie = trim( ( $item['straat'] ?? '' ) ? ( $item['straat'] . ', ' . $item['stad'] ) : ( $item['stad'] ?? '' ) );
			$tg_text = "💬 *Reactie van {$outlet['name']}*"
				. ( $locatie ? "\n📍 {$locatie}" : '' )
				. "\n\n_{$body}_";
			wp_remote_post(
				'https://api.telegram.org/bot' . $tg_token . '/sendMessage',
				[ 'timeout' => 8, 'body' => [ 'chat_id' => $tg_chat_id, 'text' => $tg_text, 'parse_mode' => 'Markdown' ] ]
			);
		}

		wp_send_json_success( [ 'message' => 'Bericht verstuurd.' ] );
	}

	public function ajax_p2000_chat_get(): void {
		$this->verify_portal_nonce();
		$code   = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlet = $this->validate_code( $code );
		if ( ! $outlet ) {
			wp_send_json_error( [ 'message' => 'Authenticatie mislukt.' ], 403 );
		}

		$p2000_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$key      = 'snd_p2000_chat_' . md5( $p2000_id );
		$messages = get_option( $key, [] );

		wp_send_json_success( [ 'messages' => is_array( $messages ) ? $messages : [] ] );
	}
	// ── AJAX: Live viewers teller bijhouden vanuit portaal ────────────────────

	public function ajax_portal_record_view(): void {
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		if ( ! $post_id || ! $code ) wp_send_json_error();

		// Store recent view timestamps per post (last 5 min window)
		$key    = '_snd_recent_views';
		$views  = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $views ) ) $views = [];
		$cutoff = time() - 300;
		// Remove stale entries, add this one
		$views  = array_filter( $views, fn( $t ) => $t > $cutoff );
		$views[] = time();
		update_post_meta( $post_id, $key, array_values( $views ) );
		update_post_meta( $post_id, '_snd_live_viewers', count( $views ) );
		wp_send_json_success( [ 'count' => count( $views ) ] );
	}
}
