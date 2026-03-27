<?php
/**
 * Admin interface for the Nieuws Distributie Systeem.
 * Handles the WordPress back-end: menus, meta-boxes, AJAX, dispatch dashboard.
 */
class SND_Admin {

	// ── Options / meta keys ───────────────────────────────────────────────────
	const OPTION_OUTLETS       = 'snd_media_outlets_v4';
	const META_PRESS_PHOTOS    = '_snd_press_photos';
	const META_PRESS_VIDEOS    = '_snd_press_videos';
	const META_DISPATCH_LOG    = '_snd_dispatch_log';
	const META_ACCESS_LOG      = '_snd_access_log';
	const META_EXPIRATION      = '_snd_expiration_date';
	const META_IS_LIVE         = '_snd_is_live_story';
	const META_LAT             = '_snd_lat';
	const META_LON             = '_snd_lon';
	const META_STREET1         = '_snd_street1';
	const META_STREET2         = '_snd_street2';
	const META_P2000_ID        = '_snd_p2000_id';
	const META_P2000_RAW       = '_snd_p2000_raw_message';
	const META_P2000_TIME      = '_snd_p2000_time';
	// Access control: per-post download blocks per outlet email
	const META_BLOCKED_OUTLETS = '_snd_blocked_outlets';
	// Update mail log
	const META_UPDATE_LOG      = '_snd_update_log';
	// Byline / naamsvermelding
	const META_BYLINE          = '_snd_byline';
	// Incident status
	const META_INCIDENT_STATUS = '_snd_incident_status';   // '', 'onderweg', 'ter_plaatse', 'afgerond'
	const META_STATUS_TIMES    = '_snd_status_times';      // array: ['onderweg'=>ts, 'ter_plaatse'=>ts, 'afgerond'=>ts]

	public function __construct() {
		// Admin pages & scripts
		add_action( 'admin_menu',            [ $this, 'register_pages' ] );
		add_action( 'admin_menu',            [ $this, 'add_p2000_chat_badge' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_notices',         [ $this, 'test_mode_notice' ] );
		add_action( 'admin_init',            [ $this, 'handle_form_submissions' ] );
		add_action( 'admin_footer',          [ $this, 'render_location_modal' ] );

		// Public styles + shortcodes
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_scripts' ] );

		// Meta boxes
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post',      [ $this, 'save_meta_boxes' ] );

		// AJAX – authenticated users only
		$ajax_actions = [
			'snd_get_post_details'      => 'ajax_get_post_details',
			'snd_get_press_photos'      => 'ajax_get_press_photos',
			'snd_save_press_photos'     => 'ajax_save_press_photos',
			'snd_get_press_videos'      => 'ajax_get_press_videos',
			'snd_save_press_videos'     => 'ajax_save_press_videos',
			'snd_get_dispatch_log'      => 'ajax_get_dispatch_log',
			'snd_send_dispatch_email'   => 'ajax_send_dispatch_email',
			'snd_quick_resend'          => 'ajax_quick_resend',
			'snd_send_update_email'     => 'ajax_send_update_email',
			'snd_get_tracking_status'   => 'ajax_get_tracking_status',
			'snd_reset_report_stats'    => 'ajax_reset_report_stats',
			'snd_geocode_address'       => 'ajax_geocode_address',
			'snd_save_location'         => 'ajax_save_location',
			'snd_reverse_geocode'       => 'ajax_reverse_geocode',
			'snd_toggle_outlet_block'   => 'ajax_toggle_outlet_block',
			'snd_get_access_control'    => 'ajax_get_access_control',
			'snd_update_outlet_role'    => 'ajax_update_outlet_role',
			'snd_edit_outlet'           => 'ajax_edit_outlet',
			'snd_send_access_link'      => 'ajax_send_access_link',
			'snd_get_dashboard_stats'   => 'ajax_get_dashboard_stats',
			'snd_get_live_viewers'      => 'ajax_get_live_viewers',
			'snd_set_post_eta'          => 'ajax_set_post_eta',
			'snd_pin_post'              => 'ajax_pin_post',
			'snd_record_post_view'      => 'ajax_record_post_view',
			'snd_save_dispatch_note'    => 'ajax_save_dispatch_note',
			'snd_tg_log_clear'          => 'ajax_tg_log_clear',
			'snd_get_map_data'          => 'ajax_get_map_data',
			'snd_link_p2000_to_post'    => 'ajax_link_p2000_to_post',
			'snd_update_post_location'  => 'ajax_update_post_location',
			'snd_get_live_p2000'        => 'ajax_get_live_p2000',
			'snd_p2000_test_feed'       => 'ajax_p2000_test_feed',
			'snd_create_incident'       => 'ajax_create_incident',
			'snd_set_p2000_status'      => 'ajax_set_p2000_status',
			'snd_telegram_test'              => 'ajax_telegram_test',
			'snd_telegram_register_commands' => 'ajax_telegram_register_commands',
			'snd_telegram_save_recipients'   => 'ajax_telegram_save_recipients',
			'snd_telegram_test_recipient'    => 'ajax_telegram_test_recipient',
			'snd_email_preview'              => 'ajax_email_preview',
			'snd_create_active_melding' => 'ajax_create_active_melding',
			'snd_delete_active_melding' => 'ajax_delete_active_melding',
			'snd_add_log_entry'         => 'ajax_add_log_entry',
			'snd_set_melding_priority'  => 'ajax_set_melding_priority',
			'snd_set_melding_eta'       => 'ajax_set_melding_eta',
			'snd_p2000_admin_chat_get'  => 'ajax_p2000_admin_chat_get',
			'snd_p2000_admin_chat_send' => 'ajax_p2000_admin_chat_send',
			'snd_p2000_ungroup'         => 'ajax_p2000_ungroup',
			'snd_p2000_regroup'         => 'ajax_p2000_regroup',
			'snd_p2000_add_manual'      => 'ajax_p2000_add_manual',
			'snd_p2000_remove_manual'   => 'ajax_p2000_remove_manual',
			'snd_test_p2000_feed'       => 'ajax_test_p2000_feed',
		];
		foreach ( $ajax_actions as $action => $method ) {
			add_action( "wp_ajax_{$action}", [ $this, $method ] );
		}

		// Public AJAX (shortcode live feed)
		add_action( 'wp_ajax_nopriv_snd_get_live_p2000', [ $this, 'ajax_get_live_p2000' ] );
		add_action( 'wp_ajax_nopriv_snd_shortcode_map',  [ $this, 'ajax_shortcode_map' ] );
		add_action( 'wp_ajax_snd_shortcode_map',          [ $this, 'ajax_shortcode_map' ] );

		// Shortcodes
		add_shortcode( 'incidenten_lijst', [ $this, 'shortcode_incident_list' ] );
		add_shortcode( 'incidenten_kaart', [ $this, 'shortcode_incident_map' ] );
	}

	// ── Public scripts ────────────────────────────────────────────────────────

	public function enqueue_public_scripts(): void {
		global $post;
		// Only enqueue when a shortcode is present
		if ( ! is_a( $post, 'WP_Post' ) ) return;
		if ( ! has_shortcode( $post->post_content, 'incidenten_lijst' ) && ! has_shortcode( $post->post_content, 'incidenten_kaart' ) ) return;

		wp_enqueue_style( 'snd-public', SND_PLUGIN_URL . 'css/public.css', [], SND_VERSION );

		if ( has_shortcode( $post->post_content, 'incidenten_kaart' ) ) {
			wp_enqueue_style(  'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',  [], '1.9.4', true );
		}

		wp_enqueue_script( 'snd-public', SND_PLUGIN_URL . 'js/public.js', [ 'jquery' ], SND_VERSION, true );
		wp_localize_script( 'snd-public', 'SND_Public', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'snd_public_nonce' ),
		] );
	}

	// ── Menu registration ──────────────────────────────────────────────────────

	public function add_p2000_chat_badge(): void {
		global $submenu;
		$active = get_option( 'snd_p2000_active_statuses', [] );
		$total  = 0;
		foreach ( $active as $id => $item ) {
			$msgs = get_option( 'snd_p2000_chat_' . md5( $id ), [] );
			if ( ! is_array( $msgs ) ) continue;
			foreach ( $msgs as $m ) {
				if ( ( $m['sender'] ?? '' ) !== 'admin' ) $total++;
			}
		}
		if ( $total > 0 && isset( $submenu['snd-dashboard'] ) ) {
			foreach ( $submenu['snd-dashboard'] as &$item ) {
				if ( ( $item[2] ?? '' ) === 'snd-p2000' ) {
					$item[0] .= ' <span class="awaiting-mod">' . $total . '</span>';
					break;
				}
			}
		}
	}

	public function register_pages(): void {
		add_menu_page(
			__( 'Distributie', 'nieuws-distributie-systeem' ),
			__( 'Distributie', 'nieuws-distributie-systeem' ),
			'publish_posts',
			'snd-dashboard',
			[ $this, 'page_dashboard' ],
			'dashicons-email-alt2',
			22
		);
		add_submenu_page( 'snd-dashboard', __( 'Verzend-Dashboard', 'nieuws-distributie-systeem' ), __( 'Dashboard', 'nieuws-distributie-systeem' ), 'publish_posts', 'snd-dashboard', [ $this, 'page_dashboard' ] );
		add_submenu_page( 'snd-dashboard', __( 'P2000 Feed', 'nieuws-distributie-systeem' ),         __( 'P2000 Feed', 'nieuws-distributie-systeem' ),   'publish_posts', 'snd-p2000',     [ $this, 'page_p2000' ] );
		add_submenu_page( 'snd-dashboard', __( '🗺 Kaartoverzicht', 'nieuws-distributie-systeem' ),   __( '🗺 Kaart', 'nieuws-distributie-systeem' ),       'publish_posts', 'snd-map',       [ $this, 'page_map' ] );
		add_submenu_page( 'snd-dashboard', __( 'Toegangsbeheer', 'nieuws-distributie-systeem' ),     __( 'Toegangsbeheer', 'nieuws-distributie-systeem' ), 'publish_posts', 'snd-access',  [ $this, 'page_access_control' ] );
		add_submenu_page( 'snd-dashboard', __( 'Rapportages', 'nieuws-distributie-systeem' ),        __( 'Rapportages', 'nieuws-distributie-systeem' ),  'publish_posts', 'snd-reports',   [ $this, 'page_reports' ] );
		add_submenu_page( 'snd-dashboard', __( 'Statistieken', 'nieuws-distributie-systeem' ),       __( 'Statistieken', 'nieuws-distributie-systeem' ), 'publish_posts', 'snd-analytics', [ $this, 'page_analytics' ] );
		add_submenu_page( 'snd-dashboard', __( 'Instellingen', 'nieuws-distributie-systeem' ),       __( 'Instellingen', 'nieuws-distributie-systeem' ), 'manage_options', 'snd-settings', [ $this, 'page_settings' ] );
		add_submenu_page( 'snd-dashboard', __( '📱 Telegram Log', 'nieuws-distributie-systeem' ),     __( '📱 Telegram Log', 'nieuws-distributie-systeem' ), 'publish_posts', 'snd-tg-log', [ $this, 'page_tg_log' ] );
	}

	// ── Scripts & styles ──────────────────────────────────────────────────────

	public function enqueue_scripts( string $hook ): void {
		$v    = SND_VERSION;
		$page = sanitize_text_field( $_GET['page'] ?? '' );

		wp_enqueue_style( 'snd-admin', SND_PLUGIN_URL . 'css/admin.css', [], $v );

		if ( in_array( $page, [ 'snd-reports', 'snd-analytics', 'snd-access' ], true ) ) {
			wp_enqueue_script( 'snd-reports', SND_PLUGIN_URL . 'js/admin-reports.js', [ 'jquery' ], $v, true );
			wp_localize_script( 'snd-reports', 'SND_Reports', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'snd_nonce' ),
			] );
		}

		if ( 'snd-map' === $page ) {
			wp_enqueue_style(  'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',  [], '1.9.4', true );
			wp_enqueue_script( 'snd-admin-map', SND_PLUGIN_URL . 'js/admin-map.js', [ 'jquery', 'leaflet' ], $v, true );
			wp_localize_script( 'snd-admin-map', 'SND_AdminMap', [
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'snd_nonce' ),
				'new_post_url'   => admin_url( 'post-new.php' ),
				'google_api_key' => get_option( 'snd_google_maps_api_key', '' ),
			] );
		}

		if ( 'snd-dashboard' === $page ) {
			wp_enqueue_media();
			// Leaflet needed for new incident modal map
			wp_enqueue_style(  'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',  [], '1.9.4', true );
			wp_enqueue_script( 'snd-dispatch', SND_PLUGIN_URL . 'js/admin-dispatch.js', [ 'jquery' ], $v, true );
			wp_localize_script( 'snd-dispatch', 'SND_Dispatch', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'snd_nonce' ),
				'outlets'  => get_option( self::OPTION_OUTLETS, [] ),
				'i18n'     => [
					'sending'       => __( 'Bezig met verzenden…', 'nieuws-distributie-systeem' ),
					'saving'        => __( 'Opslaan…', 'nieuws-distributie-systeem' ),
					'no_recipients' => __( 'Selecteer minimaal één ontvanger.', 'nieuws-distributie-systeem' ),
					'server_error'  => __( 'Serverfout.', 'nieuws-distributie-systeem' ),
					'confirm_send'  => __( 'Weet u zeker dat u wilt verzenden?', 'nieuws-distributie-systeem' ),
					'select_all'    => __( 'Alles selecteren', 'nieuws-distributie-systeem' ),
					'deselect_all'  => __( 'Alles deselecteren', 'nieuws-distributie-systeem' ),
					'update_subject_placeholder' => __( 'Onderwerpregel update-mail…', 'nieuws-distributie-systeem' ),
					'update_body_placeholder'    => __( 'Typ hier de update-tekst…', 'nieuws-distributie-systeem' ),
				],
			] );
		}

		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			wp_enqueue_style(  'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',  [], '1.9.4', true );
			wp_enqueue_script( 'snd-location-modal', SND_PLUGIN_URL . 'js/admin-location-modal.js', [ 'jquery', 'leaflet' ], $v, true );
			wp_localize_script( 'snd-location-modal', 'SND_Map', [
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'snd_location_nonce' ),
				'google_api_key' => get_option( 'snd_google_maps_api_key', '' ),
			] );
		}
	}

	// ── Admin notices ─────────────────────────────────────────────────────────

	public function test_mode_notice(): void {
		if ( $this->is_test_mode() && current_user_can( 'publish_posts' ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Nieuws Distributie Systeem:', 'nieuws-distributie-systeem' ),
				esc_html__( 'De testmodus is actief – statistieken worden niet gelogd.', 'nieuws-distributie-systeem' ),
				esc_url( admin_url( 'admin.php?page=snd-settings#tab-algemeen' ) ),
				esc_html__( 'Beheren', 'nieuws-distributie-systeem' )
			);
		}
	}

	// ── Form submissions (settings page) ──────────────────────────────────────

	public function handle_form_submissions(): void {
		if (
			! isset( $_POST['snd_nonce_field'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snd_nonce_field'] ) ), 'snd_settings_action' )
			|| ! current_user_can( 'manage_options' )
		) {
			return;
		}

		$action = sanitize_text_field( $_POST['snd_action'] ?? '' );

		if ( 'save_main_settings' === $action ) {
			$this->save_main_settings();
			wp_safe_redirect( add_query_arg( [ 'page' => 'snd-settings', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'add_outlet' === $action ) {
			$this->add_outlet();
			wp_safe_redirect( add_query_arg( [ 'page' => 'snd-settings', 'updated' => '1', 'tab' => 'outlets' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'delete_outlet' === $action && isset( $_POST['outlet_index'] ) ) {
			$this->delete_outlet( (int) $_POST['outlet_index'] );
			wp_safe_redirect( add_query_arg( [ 'page' => 'snd-settings', 'updated' => '1', 'tab' => 'outlets' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'regen_code' === $action && isset( $_POST['outlet_index'] ) ) {
			$this->regen_outlet_code( (int) $_POST['outlet_index'] );
			wp_safe_redirect( add_query_arg( [ 'page' => 'snd-settings', 'updated' => '1', 'tab' => 'outlets' ], admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	private function save_main_settings(): void {
		$fields = [
			'snd_p2000_feed_brandweer'     => 'sanitize_url',
			'snd_p2000_feed_politie'       => 'sanitize_url',
			'snd_p2000_feed_mmt'           => 'sanitize_url',
			'snd_p2000_cities'             => 'sanitize_text_field',
			'snd_email_from_name'          => 'sanitize_text_field',
			'snd_email_from_email'         => 'sanitize_email',
			'snd_email_subject_prefix'     => 'sanitize_text_field',
			'snd_portal_default_expiry'    => 'absint',
			'snd_admin_notification_email' => 'sanitize_email',
		];
		foreach ( $fields as $key => $cb ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, call_user_func( $cb, wp_unslash( $_POST[ $key ] ) ) );
			}
		}
		// Checkboxes
		update_option( 'snd_p2000_hide_standard_runs', isset( $_POST['snd_p2000_hide_standard_runs'] ) ? '1' : '0' );
		update_option( 'snd_notify_on_fail',           isset( $_POST['snd_notify_on_fail'] ) ? '1' : '0' );
		update_option( 'snd_test_mode_enabled',        isset( $_POST['snd_test_mode_enabled'] ) ? '1' : '0' );
		update_option( 'snd_p2000_filter_radius',      absint( $_POST['snd_p2000_filter_radius'] ?? 20 ) );
		if ( isset( $_POST['snd_google_maps_api_key'] ) ) {
			update_option( 'snd_google_maps_api_key', sanitize_text_field( wp_unslash( $_POST['snd_google_maps_api_key'] ) ) );
		}
		// Accent colour for emails
		if ( isset( $_POST['snd_email_accent_color'] ) ) {
			$col = sanitize_hex_color( wp_unslash( $_POST['snd_email_accent_color'] ) );
			if ( $col ) update_option( 'snd_email_accent_color', $col );
		}
		// Email settings
		if ( isset( $_POST['snd_telegram_bot_token'] ) ) {
			$new_token = sanitize_text_field( wp_unslash( $_POST['snd_telegram_bot_token'] ) );
			update_option( 'snd_telegram_bot_token', $new_token );
			// Register bot commands with Telegram when token is saved
			if ( ! empty( $new_token ) && function_exists( 'snd_tg_register_commands' ) ) {
				snd_tg_register_commands( $new_token );
			}
		}
		if ( isset( $_POST['snd_telegram_chat_id'] ) ) {
			update_option( 'snd_telegram_chat_id', sanitize_text_field( wp_unslash( $_POST['snd_telegram_chat_id'] ) ) );
		}
		update_option( 'snd_telegram_notify_onderweg',    isset( $_POST['snd_telegram_notify_onderweg'] )    ? '1' : '0' );
		update_option( 'snd_telegram_notify_ter_plaatse', isset( $_POST['snd_telegram_notify_ter_plaatse'] ) ? '1' : '0' );
		update_option( 'snd_telegram_notify_afgerond',    isset( $_POST['snd_telegram_notify_afgerond'] )    ? '1' : '0' );
		update_option( 'snd_telegram_notify_dispatch',    isset( $_POST['snd_telegram_notify_dispatch'] )    ? '1' : '0' );
		update_option( 'snd_telegram_notify_first_view',  isset( $_POST['snd_telegram_notify_first_view'] )  ? '1' : '0' );
		update_option( 'snd_telegram_notify_download',    isset( $_POST['snd_telegram_notify_download'] )    ? '1' : '0' );
		// Auto-archive: 0 = uitgeschakeld
		update_option( 'snd_melding_auto_archive_hours', absint( $_POST['snd_melding_auto_archive_hours'] ?? 0 ) );
	}

	private function add_outlet(): void {
		$name  = sanitize_text_field( wp_unslash( $_POST['outlet_name']  ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['outlet_email'] ?? '' ) );
		$group = sanitize_text_field( wp_unslash( $_POST['outlet_group'] ?? '' ) );
		$role  = sanitize_text_field( wp_unslash( $_POST['outlet_role']  ?? 'default' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['outlet_phone'] ?? '' ) );
		$notes = sanitize_textarea_field( wp_unslash( $_POST['outlet_notes'] ?? '' ) );

		if ( ! in_array( $role, [ 'default', 'partner', 'redacteur' ], true ) ) {
			$role = 'default';
		}
		if ( empty( $name ) || ! is_email( $email ) ) {
			return;
		}
		$outlets   = get_option( self::OPTION_OUTLETS, [] );
		$outlets[] = [
			'name'        => $name,
			'email'       => $email,
			'group'       => $group,
			'role'        => $role,
			'phone'       => $phone,
			'notes'       => $notes,
			'added'       => time(),
			'access_code' => wp_generate_password( 32, false ),
		];
		update_option( self::OPTION_OUTLETS, $outlets );
	}

	private function delete_outlet( int $index ): void {
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		if ( isset( $outlets[ $index ] ) ) {
			array_splice( $outlets, $index, 1 );
			update_option( self::OPTION_OUTLETS, array_values( $outlets ) );
		}
	}

	private function regen_outlet_code( int $index ): void {
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		if ( isset( $outlets[ $index ] ) ) {
			$outlets[ $index ]['access_code'] = wp_generate_password( 32, false );
			update_option( self::OPTION_OUTLETS, $outlets );
		}
	}

	// ── Meta boxes ────────────────────────────────────────────────────────────

	public function register_meta_boxes( string $post_type ): void {
		if ( 'post' !== $post_type ) {
			return;
		}
		add_meta_box( 'snd_location',         __( 'Locatie (P2000)', 'nieuws-distributie-systeem' ),          [ $this, 'render_location_meta_box' ],    'post', 'side', 'high' );
		add_meta_box( 'snd_incident_status',  __( 'Incidentstatus', 'nieuws-distributie-systeem' ),            [ $this, 'render_incident_status_meta_box' ], 'post', 'side', 'high' );
		add_meta_box( 'snd_byline',           __( 'Naamsvermelding', 'nieuws-distributie-systeem' ),           [ $this, 'render_byline_meta_box' ],      'post', 'side' );
		add_meta_box( 'snd_expiry',           __( 'Portaal vervaldatum', 'nieuws-distributie-systeem' ),       [ $this, 'render_expiry_meta_box' ],       'post', 'side' );
		add_meta_box( 'snd_live_story',       __( 'Live verhaal status', 'nieuws-distributie-systeem' ),       [ $this, 'render_live_meta_box' ],         'post', 'side' );
		add_meta_box( 'snd_p2000_meldingen',  __( 'P2000 Meldingen bij dit item', 'nieuws-distributie-systeem' ), [ $this, 'render_p2000_meldingen_meta_box' ], 'post', 'normal', 'low' );
	}

	public function render_location_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'snd_save_meta', 'snd_meta_nonce' );

		$lat     = get_post_meta( $post->ID, self::META_LAT, true );
		$lon     = get_post_meta( $post->ID, self::META_LON, true );
		$street1 = get_post_meta( $post->ID, self::META_STREET1, true );
		$street2 = get_post_meta( $post->ID, self::META_STREET2, true );
		$p2000t  = get_post_meta( $post->ID, self::META_P2000_TIME, true );
		$p2000r  = get_post_meta( $post->ID, self::META_P2000_RAW, true );
		$p2000id = get_post_meta( $post->ID, self::META_P2000_ID, true );

		// Pre-fill from P2000 feed URL params on new posts
		if ( 'auto-draft' === $post->post_status ) {
			$lat     = isset( $_GET['snd_lat'] )             ? sanitize_text_field( wp_unslash( $_GET['snd_lat'] ) )             : $lat;
			$lon     = isset( $_GET['snd_lon'] )             ? sanitize_text_field( wp_unslash( $_GET['snd_lon'] ) )             : $lon;
			$street1 = isset( $_GET['snd_street1'] )         ? sanitize_text_field( urldecode( wp_unslash( $_GET['snd_street1'] ) ) ) : $street1;
			$street2 = isset( $_GET['snd_street2'] )         ? sanitize_text_field( urldecode( wp_unslash( $_GET['snd_street2'] ) ) ) : $street2;
			$p2000id = isset( $_GET['snd_p2000_id'] )        ? sanitize_text_field( wp_unslash( $_GET['snd_p2000_id'] ) )        : $p2000id;
			$p2000r  = isset( $_GET['snd_p2000_raw_message'] ) ? sanitize_textarea_field( urldecode( wp_unslash( $_GET['snd_p2000_raw_message'] ) ) ) : $p2000r;

			// Pre-fill post content with log entries if coming from a melding
			if ( isset( $_GET['snd_log_content'] ) && '' === $post->post_content ) {
				$log_text = sanitize_textarea_field( urldecode( wp_unslash( $_GET['snd_log_content'] ) ) );
				if ( $log_text ) {
					// Inject via JS so the block editor / classic editor picks it up
					add_action( 'admin_footer', function() use ( $log_text ) {
						$safe = esc_js( nl2br( esc_html( $log_text ) ) );
						echo "<script>
						(function(){
							// Classic editor
							if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
								tinyMCE.activeEditor.setContent('<p><strong>Logboek:</strong></p><pre>" . esc_js( $log_text ) . "</pre>');
							}
							// Block editor — set initial content via wp.data
							if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
								var unsubscribe = wp.data.subscribe(function(){
									var editor = wp.data.select('core/editor');
									if (editor && editor.isCleanNewPost && editor.isCleanNewPost()) {
										wp.data.dispatch('core/editor').editPost({content: '<p><strong>Logboek:</strong></p><pre>{$safe}</pre>'});
										unsubscribe();
									}
								});
							}
						})();
						</script>";
					} );
				}
			}
		}
		?>
		<?php if ( ! empty( $p2000t ) ) : ?>
			<p><strong><?php esc_html_e( 'P2000 tijdstip:', 'nieuws-distributie-systeem' ); ?></strong><br>
			<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i:s', $p2000t ) ); ?></p>
			<hr>
		<?php endif; ?>

		<div id="snd-location-finder">
			<label for="snd-addr-search"><strong><?php esc_html_e( 'Zoek adres / kruispunt', 'nieuws-distributie-systeem' ); ?></strong></label>
			<div style="display:flex;gap:6px;margin-top:6px;">
				<input type="text" id="snd-addr-search" class="widefat" placeholder="<?php esc_attr_e( 'bijv. Korvelseweg, Tilburg', 'nieuws-distributie-systeem' ); ?>">
				<button type="button" id="snd-geocode-btn" class="button"><?php esc_html_e( 'Zoek', 'nieuws-distributie-systeem' ); ?></button>
				<span class="spinner" style="float:none;vertical-align:middle;"></span>
			</div>
		</div>

		<div id="snd-location-map" style="height:220px;width:100%;border-radius:4px;margin:12px 0;background:#eee;cursor:pointer;" title="<?php esc_attr_e( 'Klik op de kaart om de locatie te pinnen', 'nieuws-distributie-systeem' ); ?>"></div>

		<p><label for="snd_lat"><strong><?php esc_html_e( 'Latitude:', 'nieuws-distributie-systeem' ); ?></strong></label>
		<input type="text" id="snd_lat" name="snd_lat" value="<?php echo esc_attr( $lat ); ?>" class="widefat"></p>

		<p><label for="snd_lon"><strong><?php esc_html_e( 'Longitude:', 'nieuws-distributie-systeem' ); ?></strong></label>
		<input type="text" id="snd_lon" name="snd_lon" value="<?php echo esc_attr( $lon ); ?>" class="widefat"></p>

		<p><label for="snd_street1"><strong><?php esc_html_e( 'Straat:', 'nieuws-distributie-systeem' ); ?></strong></label>
		<input type="text" id="snd_street1" name="snd_street1" value="<?php echo esc_attr( $street1 ); ?>" class="widefat"></p>

		<p><label for="snd_street2"><strong><?php esc_html_e( 'Straat 2 (kruispunt):', 'nieuws-distributie-systeem' ); ?></strong></label>
		<input type="text" id="snd_street2" name="snd_street2" value="<?php echo esc_attr( $street2 ); ?>" class="widefat"></p>

		<input type="hidden" name="snd_p2000_id"          value="<?php echo esc_attr( $p2000id ); ?>">
		<input type="hidden" name="snd_p2000_raw_message" value="<?php echo esc_attr( $p2000r ); ?>">
		<input type="hidden" name="snd_p2000_time"        value="<?php echo esc_attr( $p2000t ); ?>">
		<?php
	}

	public function render_expiry_meta_box( \WP_Post $post ): void {
		$date = get_post_meta( $post->ID, self::META_EXPIRATION, true );
		echo '<label for="snd_expiration_date">' . esc_html__( 'Vervalt op:', 'nieuws-distributie-systeem' ) . '</label>';
		echo '<input type="date" id="snd_expiration_date" name="snd_expiration_date" value="' . esc_attr( $date ) . '" class="widefat" style="margin-top:6px;">';
		echo '<p class="description">' . esc_html__( 'Laat leeg om nooit te laten verlopen.', 'nieuws-distributie-systeem' ) . '</p>';
	}

	public function render_live_meta_box( \WP_Post $post ): void {
		$live = get_post_meta( $post->ID, self::META_IS_LIVE, true );
		echo '<label><input type="checkbox" name="snd_is_live_story" value="1" ' . checked( $live, '1', false ) . '> ' . esc_html__( 'Markeer als "Live Verhaal"', 'nieuws-distributie-systeem' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Toont een live-indicator in het persportaal.', 'nieuws-distributie-systeem' ) . '</p>';
	}

	public function render_byline_meta_box( \WP_Post $post ): void {
		$byline = get_post_meta( $post->ID, self::META_BYLINE, true );
		echo '<label for="snd_byline"><strong>' . esc_html__( 'Naamsvermelding:', 'nieuws-distributie-systeem' ) . '</strong></label>';
		echo '<input type="text" id="snd_byline" name="snd_byline" value="' . esc_attr( $byline ) . '" class="widefat" style="margin-top:6px;" placeholder="bijv. Foto: J. de Vries / Tilburg112">';
		echo '<p class="description">' . esc_html__( 'Wordt getoond bij foto\'s en tekst in het persportaal.', 'nieuws-distributie-systeem' ) . '</p>';
	}

	public function render_incident_status_meta_box( \WP_Post $post ): void {
		$status = get_post_meta( $post->ID, self::META_INCIDENT_STATUS, true ) ?: '';
		$times  = get_post_meta( $post->ID, self::META_STATUS_TIMES, true );
		$times  = is_array( $times ) ? $times : [];

		$statuses = [
			''           => [ 'label' => '— Geen status',  'color' => '#888',    'icon' => '○' ],
			'onderweg'   => [ 'label' => 'Onderweg',        'color' => '#f59e0b', 'icon' => '🚨' ],
			'ter_plaatse'=> [ 'label' => 'Ter plaatse',     'color' => '#3b82f6', 'icon' => '📍' ],
			'afgerond'   => [ 'label' => 'Afgerond',        'color' => '#10b981', 'icon' => '✓' ],
		];
		?>
		<div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
		<?php foreach ( $statuses as $val => $s ) :
			$ts = $times[ $val ] ?? null;
			$checked = $status === $val ? 'checked' : '';
		?>
			<label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;border:2px solid <?php echo $checked ? esc_attr( $s['color'] ) : '#e2e4e7'; ?>;background:<?php echo $checked ? 'rgba(' . ($val === '' ? '0,0,0,0.03' : '0,0,0,0.03') . ')' : '#fff'; ?>;cursor:pointer;">
				<input type="radio" name="snd_incident_status" value="<?php echo esc_attr( $val ); ?>" <?php echo $checked; ?> style="margin:0;">
				<span style="font-size:15px;"><?php echo $s['icon']; ?></span>
				<span style="font-weight:600;color:<?php echo esc_attr( $s['color'] ); ?>;"><?php echo esc_html( $s['label'] ); ?></span>
				<?php if ( $ts ) : ?>
					<span style="margin-left:auto;font-size:11px;color:#888;"><?php echo esc_html( wp_date( 'H:i', $ts ) ); ?></span>
				<?php endif; ?>
			</label>
		<?php endforeach; ?>
		</div>
		<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Tijdstempel wordt automatisch opgeslagen bij wijziging.', 'nieuws-distributie-systeem' ); ?></p>
		<?php
	}

	public function render_p2000_meldingen_meta_box( \WP_Post $post ): void {
		$p2000_id    = get_post_meta( $post->ID, self::META_P2000_ID, true );
		$p2000_raw   = get_post_meta( $post->ID, self::META_P2000_RAW, true );
		$manual_all  = get_option( 'snd_p2000_manual_links', [] );
		// Use p2000_id as key; fall back to "post_{ID}" for posts without feed link
		$link_key    = $p2000_id ?: 'post_' . $post->ID;
		$entries     = $manual_all[ $link_key ] ?? [];
		$nonce_field = wp_create_nonce( 'snd_nonce' );
		$type_colors = [ 'brandweer' => '#ef4444', 'politie' => '#f59e0b', 'mmt' => '#8b5cf6', 'overig' => '#6b7280' ];
		?>
		<div style="font-size:13px;">
			<?php if ( $p2000_raw ) : ?>
				<div style="background:#f8fafc;border-left:3px solid #3b82f6;padding:8px 12px;margin-bottom:12px;border-radius:0 4px 4px 0;">
					<span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#888;">Originele P2000 melding</span><br>
					<span style="font-family:monospace;font-size:12px;"><?php echo esc_html( $p2000_raw ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( empty( $entries ) ) : ?>
				<p style="color:#888;font-style:italic;margin-bottom:10px;">Nog geen handmatige meldingen toegevoegd.</p>
			<?php else : ?>
				<table class="wp-list-table widefat striped" style="margin-bottom:12px;">
					<thead><tr>
						<th style="width:90px;">Type</th>
						<th style="width:50px;">Tijd</th>
						<th>Meldingtekst</th>
						<th style="width:70px;">Actie</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $entries as $idx => $entry ) :
						$etype  = $entry['type'] ?? 'overig';
						$ecolor = $type_colors[ $etype ] ?? '#6b7280';
						$etijd  = ! empty( $entry['tijd'] ) ? wp_date( 'H:i', (int) $entry['tijd'] ) : '—';
					?>
						<tr>
							<td><span style="display:inline-block;background:<?php echo esc_attr($ecolor); ?>;color:#fff;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;"><?php echo esc_html( ucfirst($etype) ); ?></span></td>
							<td style="font-size:12px;color:#888;"><?php echo esc_html($etijd); ?></td>
							<td style="font-family:monospace;font-size:12px;"><?php echo esc_html($entry['tekst'] ?? ''); ?></td>
							<td>
								<button type="button" class="button button-small snd-meta-remove-manual"
									data-key="<?php echo esc_attr($link_key); ?>"
									data-idx="<?php echo esc_attr($idx); ?>"
									data-nonce="<?php echo esc_attr($nonce_field); ?>"
									style="font-size:11px;color:#b32d2e;">✕</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div style="border:1px solid #ddd;border-radius:6px;padding:12px;background:#f9f9f9;">
				<p style="font-weight:600;margin:0 0 8px;font-size:12px;">➕ Handmatige melding toevoegen</p>
				<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
					<div>
						<label style="font-size:11px;display:block;margin-bottom:3px;color:#666;">Type</label>
						<select id="snd-meta-manual-type" style="font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;">
							<?php foreach ( [ 'brandweer' => '🔥 Brandweer', 'politie' => '🚔 Politie', 'mmt' => '🚑 MMT', 'overig' => '📋 Overig' ] as $tv => $tl ) :
								echo '<option value="' . esc_attr($tv) . '">' . esc_html($tl) . '</option>';
							endforeach; ?>
						</select>
					</div>
					<div style="flex:1;min-width:200px;">
						<label style="font-size:11px;display:block;margin-bottom:3px;color:#666;">Meldingtekst (kopieer/plak uit P2000)</label>
						<input type="text" id="snd-meta-manual-tekst" class="widefat"
							placeholder="bijv. BZB-01 Prio 1 Woningbrand Korvelseweg Tilburg"
							style="font-size:12px;font-family:monospace;">
					</div>
					<div>
						<button type="button" id="snd-meta-add-manual-btn"
							class="button button-primary"
							data-key="<?php echo esc_attr($link_key); ?>"
							data-nonce="<?php echo esc_attr($nonce_field); ?>"
							style="font-size:12px;">Toevoegen</button>
					</div>
				</div>
				<p id="snd-meta-manual-msg" style="margin:6px 0 0;font-size:12px;"></p>
			</div>
		</div>

		<script>
		(function($){
			// Add manual entry
			$('#snd-meta-add-manual-btn').on('click', function(){
				var $btn   = $(this).prop('disabled',true).text('…');
				var tekst  = $('#snd-meta-manual-tekst').val().trim();
				var type   = $('#snd-meta-manual-type').val();
				var key    = $btn.data('key');
				var nonce  = $btn.data('nonce');
				if (!tekst) {
					$('#snd-meta-manual-msg').text('Vul een meldingtekst in.').css('color','#b32d2e');
					$btn.prop('disabled',false).text('Toevoegen');
					return;
				}
				$.post(ajaxurl, {
					action: 'snd_p2000_add_manual',
					nonce: nonce,
					primary: key,
					tekst: tekst,
					type: type,
				}).done(function(res){
					if (res.success) {
						$('#snd-meta-manual-msg').text('✓ Toegevoegd! Sla het bericht op om te vernieuwen.').css('color','#16a34a');
						$('#snd-meta-manual-tekst').val('');
					} else {
						$('#snd-meta-manual-msg').text(res.data && res.data.message ? res.data.message : 'Fout.').css('color','#b32d2e');
					}
				}).always(function(){ $btn.prop('disabled',false).text('Toevoegen'); });
			});

			// Remove manual entry
			$(document).on('click', '.snd-meta-remove-manual', function(){
				var $btn  = $(this).prop('disabled',true);
				var key   = $btn.data('key');
				var idx   = $btn.data('idx');
				var nonce = $btn.data('nonce');
				$.post(ajaxurl, {
					action: 'snd_p2000_remove_manual',
					nonce: nonce,
					primary: key,
					idx: idx,
				}).done(function(res){
					if (res.success) $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
					else $btn.prop('disabled',false);
				}).fail(function(){ $btn.prop('disabled',false); });
			});
		})(jQuery);
		</script>
		<?php
	}

	public function save_meta_boxes( int $post_id ): void {
		if (
			! isset( $_POST['snd_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snd_meta_nonce'] ) ), 'snd_save_meta' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$text_fields = [ 'snd_lat', 'snd_lon', 'snd_street1', 'snd_street2', 'snd_p2000_id', 'snd_p2000_raw_message', 'snd_p2000_time' ];
		foreach ( $text_fields as $field ) {
			$meta_key = '_' . $field;
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		if ( isset( $_POST['snd_expiration_date'] ) ) {
			update_post_meta( $post_id, self::META_EXPIRATION, sanitize_text_field( wp_unslash( $_POST['snd_expiration_date'] ) ) );
		}

		// Byline / naamsvermelding
		if ( isset( $_POST['snd_byline'] ) ) {
			update_post_meta( $post_id, self::META_BYLINE, sanitize_text_field( wp_unslash( $_POST['snd_byline'] ) ) );
		}

		// Incident status — record timestamp when status changes
		if ( isset( $_POST['snd_incident_status'] ) ) {
			$new_status = sanitize_text_field( wp_unslash( $_POST['snd_incident_status'] ) );
			$allowed    = [ '', 'onderweg', 'ter_plaatse', 'afgerond' ];
			if ( in_array( $new_status, $allowed, true ) ) {
				$old_status = get_post_meta( $post_id, self::META_INCIDENT_STATUS, true ) ?: '';
				update_post_meta( $post_id, self::META_INCIDENT_STATUS, $new_status );
				if ( $new_status !== '' && $new_status !== $old_status ) {
					$times = get_post_meta( $post_id, self::META_STATUS_TIMES, true );
					$times = is_array( $times ) ? $times : [];
					$times[ $new_status ] = time();
					update_post_meta( $post_id, self::META_STATUS_TIMES, $times );

					// Telegram notification for 'afgerond'
					if ( $new_status === 'afgerond' && get_option( 'snd_telegram_notify_afgerond', '1' ) === '1' ) {
						$post_obj = get_post( $post_id );
						$tg_title = $post_obj ? $post_obj->post_title : "Post #{$post_id}";
						$tg_url   = get_permalink( $post_id );
						$this->telegram_send_all( "✅ *Afgerond: {$tg_title}*\n🔗 {$tg_url}" );
					}
				}
			}
		}

		update_post_meta( $post_id, self::META_IS_LIVE, isset( $_POST['snd_is_live_story'] ) ? '1' : '0' );
	}

	// ── Location modal HTML ───────────────────────────────────────────────────

	public function render_location_modal(): void {
		global $hook_suffix;
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		?>
		<div id="snd-location-modal-backdrop" style="display:none;">
			<div id="snd-location-modal-content">
				<div class="snd-modal-header">
					<h2><?php esc_html_e( 'Kies een locatie op de kaart', 'nieuws-distributie-systeem' ); ?></h2>
					<button type="button" class="snd-modal-close" aria-label="<?php esc_attr_e( 'Sluiten', 'nieuws-distributie-systeem' ); ?>">&times;</button>
				</div>
				<div class="snd-modal-body">
					<div id="snd-modal-map" style="height:400px;width:100%;border-radius:4px;background:#eee;"></div>
				</div>
				<div class="snd-modal-footer">
					<div id="snd-modal-address-preview"><?php esc_html_e( 'Geselecteerd: …', 'nieuws-distributie-systeem' ); ?></div>
					<button type="button" id="snd-use-location-btn" class="button button-primary"><?php esc_html_e( 'Gebruik deze locatie', 'nieuws-distributie-systeem' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	private function verify_nonce(): void {
		if ( ! check_ajax_referer( 'snd_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Sessie verlopen. Herlaad de pagina.', 'nieuws-distributie-systeem' ) ], 403 );
		}
	}

	public function ajax_get_post_details(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( [ 'message' => __( 'Bericht niet gevonden.', 'nieuws-distributie-systeem' ) ] );
		}

		$image_ids = $this->get_all_image_ids( $post_id );
		$images    = [];
		foreach ( $image_ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) continue;
			$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
			$path  = get_attached_file( $id );
			if ( $thumb && $path && file_exists( $path ) ) {
				$images[] = [ 'id' => $id, 'thumb_url' => $thumb[0], 'filename' => wp_basename( $path ) ];
			}
		}
		wp_send_json_success( [ 'article_images' => $images, 'featured_image_id' => get_post_thumbnail_id( $post_id ) ] );
	}

	public function ajax_get_press_photos(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$ids     = $this->get_press_photo_ids( $post_id );
		$data    = [];
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) continue;
			$url = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( $url ) $data[] = [ 'id' => $id, 'url' => $url ];
		}
		wp_send_json_success( $data );
	}

	public function ajax_save_press_photos(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! current_user_can( 'publish_posts', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}
		$ids = sanitize_text_field( wp_unslash( $_POST['image_ids'] ?? '' ) );
		update_post_meta( $post_id, self::META_PRESS_PHOTOS, $ids );
		wp_send_json_success( __( 'Persfoto\'s opgeslagen!', 'nieuws-distributie-systeem' ) );
	}

	public function ajax_get_press_videos(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$json    = get_post_meta( $post_id, self::META_PRESS_VIDEOS, true );
		$videos  = ! empty( $json ) ? json_decode( $json, true ) : [];
		wp_send_json_success( is_array( $videos ) ? $videos : [] );
	}

	public function ajax_save_press_videos(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! current_user_can( 'publish_posts', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}
		// Expect JSON string from JS
		$raw    = wp_unslash( $_POST['videos_json'] ?? '[]' );
		$videos = json_decode( $raw, true );
		if ( ! is_array( $videos ) ) $videos = [];

		// Sanitize each entry
		$clean = [];
		foreach ( $videos as $v ) {
			$url   = esc_url_raw( $v['url']   ?? '' );
			$title = sanitize_text_field( $v['title'] ?? '' );
			$size  = sanitize_text_field( $v['size']  ?? '' );
			if ( $url ) $clean[] = [ 'url' => $url, 'title' => $title, 'size' => $size ];
		}
		update_post_meta( $post_id, self::META_PRESS_VIDEOS, wp_json_encode( $clean ) );
		wp_send_json_success( __( "Video's opgeslagen!", 'nieuws-distributie-systeem' ) );
	}

	public function ajax_get_dispatch_log(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		wp_send_json_success( get_post_meta( $post_id, self::META_DISPATCH_LOG, true ) ?: [] );
	}

	// ── AJAX: Snel opnieuw versturen aan dezelfde ontvangers ─────────────────

	public function ajax_quick_resend(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$log     = get_post_meta( $post_id, self::META_DISPATCH_LOG, true );
		if ( ! is_array( $log ) || empty( $log ) ) {
			wp_send_json_error( [ 'message' => 'Geen eerdere ontvangers gevonden.' ] );
		}
		$sent   = 0;
		$errors = [];
		foreach ( array_keys( $log ) as $email ) {
			$outlets = get_option( self::OPTION_OUTLETS, [] );
			$outlet  = null;
			foreach ( $outlets as $o ) {
				if ( strtolower( $o['email'] ) === strtolower( $email ) ) { $outlet = $o; break; }
			}
			if ( ! $outlet ) continue;
			if ( $this->send_email( $post_id, $outlet, [] ) ) {
				$sent++;
			} else {
				$errors[] = $email;
			}
		}
		if ( $sent > 0 ) {
			wp_send_json_success( [ 'message' => "{$sent} e-mail(s) opnieuw verstuurd." ] );
		} else {
			wp_send_json_error( [ 'message' => 'Verzenden mislukt: ' . implode( ', ', $errors ) ] );
		}
	}

	public function ajax_send_dispatch_email(): void {
		$this->verify_nonce();

		$post_id = absint( $_POST['post_id'] ?? 0 );

		// recipients can arrive as array (traditional:true) or comma-separated string
		$raw_recipients = $_POST['recipients'] ?? [];
		if ( is_string( $raw_recipients ) ) {
			$raw_recipients = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
		}
		$recipients = array_filter( array_map( 'sanitize_email', (array) wp_unslash( $raw_recipients ) ) );

		$raw_images = $_POST['selected_images'] ?? [];
		if ( is_string( $raw_images ) ) {
			$raw_images = array_filter( array_map( 'trim', explode( ',', $raw_images ) ) );
		}
		$sel_images = array_map( 'absint', (array) $raw_images );

		if ( isset( $_POST['press_photos'] ) ) {
			update_post_meta( $post_id, self::META_PRESS_PHOTOS, sanitize_text_field( wp_unslash( $_POST['press_photos'] ) ) );
		}

		if ( empty( $recipients ) ) {
			wp_send_json_error( [ 'message' => 'Geen ontvangers geselecteerd.' ] );
		}

		$outlets = get_option( self::OPTION_OUTLETS, [] );
		$sent    = 0;
		$errors  = [];

		foreach ( $recipients as $email ) {
			// Find matching outlet
			$outlet = null;
			foreach ( $outlets as $o ) {
				if ( strtolower( $o['email'] ) === strtolower( $email ) ) {
					$outlet = $o;
					break;
				}
			}

			if ( ! $outlet ) {
				$errors[] = "{$email}: outlet niet gevonden";
				continue;
			}
			if ( empty( $outlet['access_code'] ) ) {
				$errors[] = "{$email}: geen toegangscode";
				continue;
			}

			$ok = $this->send_email( $post_id, $outlet, $sel_images );
			if ( $ok ) {
				$sent++;
				if ( ! $this->is_test_mode() ) {
					$log = get_post_meta( $post_id, self::META_DISPATCH_LOG, true );
					if ( ! is_array( $log ) ) $log = [];
					$log[ $email ] = [ 'name' => $outlet['name'], 'email' => $outlet['email'], 'timestamp' => time() ];
					update_post_meta( $post_id, self::META_DISPATCH_LOG, $log );
				}
			} else {
				$errors[] = "{$email}: wp_mail mislukt";
			}
		}

		if ( $sent > 0 ) {
			if ( ! $this->is_test_mode() ) {
				delete_transient( 'snd_analytics_stats' );
				// Telegram: notify dispatch (if enabled)
				if ( get_option( 'snd_telegram_notify_dispatch', '1' ) === '1' ) {
					$post_obj = get_post( $post_id );
					$title    = $post_obj ? $post_obj->post_title : "Post #{$post_id}";
					$site     = get_bloginfo( 'name' );
					$edit_url = get_edit_post_link( $post_id );
					$this->telegram_send_all(
						"📨 *Persbericht verstuurd — {$site}*\n" .
						"_{$title}_\n\n" .
						"✉️ {$sent} outlet(s) ontvangen\n" .
						"✏️ {$edit_url}"
					);
				}
			}
			$msg = sprintf( _n( '%d e-mail verzonden!', '%d e-mails verzonden!', $sent, 'nieuws-distributie-systeem' ), $sent );
			if ( $this->is_test_mode() ) {
				$msg .= ' (Testmodus: niets gelogd)';
			}
			wp_send_json_success( [ 'message' => $msg ] );
		}

		// Return specific error so we can debug
		$detail = ! empty( $errors ) ? implode( '; ', $errors ) : 'Geen ontvangers verwerkt (recipients=' . implode( ',', $recipients ) . ')';
		wp_send_json_error( [ 'message' => 'Verzenden mislukt: ' . $detail ] );
	}

	public function ajax_get_tracking_status(): void {
		$this->verify_nonce();
		$post_id      = absint( $_POST['post_id'] ?? 0 );
		$dispatch_log = get_post_meta( $post_id, self::META_DISPATCH_LOG, true );
		$access_log   = get_post_meta( $post_id, self::META_ACCESS_LOG, true );
		$update_log   = get_post_meta( $post_id, self::META_UPDATE_LOG, true );
		$blocked      = get_post_meta( $post_id, self::META_BLOCKED_OUTLETS, true );
		if ( ! is_array( $blocked ) ) $blocked = [];
		if ( ! is_array( $access_log ) ) $access_log = [];

		ob_start();
		if ( empty( $dispatch_log ) ) {
			echo '<p class="snd-empty-state">' . esc_html__( 'Dit bericht is nog niet verstuurd, of testmodus is actief.', 'nieuws-distributie-systeem' ) . '</p>';
		} else {
			$total_sent = count( $dispatch_log );
			$total_open = 0;
			$total_dl   = 0;
			foreach ( $access_log as $log ) {
				if ( ! empty( $log['views'] ) )     $total_open++;
				if ( ! empty( $log['downloads'] ) ) $total_dl += count( $log['downloads'] );
			}
			$rate = $total_sent > 0 ? round( $total_open / $total_sent * 100 ) : 0;
			$num_updates = is_array( $update_log ) ? count( $update_log ) : 0;

			echo '<div class="snd-report-summary">';
			echo '<div class="snd-stat"><span class="snd-stat-val">' . $total_sent . '</span><span class="snd-stat-lbl">Verzonden</span></div>';
			echo '<div class="snd-stat"><span class="snd-stat-val">' . $total_open . '</span><span class="snd-stat-lbl">Geopend</span></div>';
			echo '<div class="snd-stat"><span class="snd-stat-val">' . $rate . '%</span><span class="snd-stat-lbl">Open rate</span></div>';
			echo '<div class="snd-stat"><span class="snd-stat-val">' . $total_dl . '</span><span class="snd-stat-lbl">Downloads</span></div>';
			echo '<div class="snd-stat"><span class="snd-stat-val">' . $num_updates . '</span><span class="snd-stat-lbl">Updates verzonden</span></div>';
			echo '</div>';

			// Updates log
			if ( $num_updates > 0 ) {
				echo '<h4 style="margin:16px 0 8px;">' . esc_html__( 'Verzonden updates', 'nieuws-distributie-systeem' ) . '</h4>';
				echo '<table class="wp-list-table widefat striped" style="margin-bottom:20px;"><thead><tr><th>' . esc_html__( 'Onderwerp', 'nieuws-distributie-systeem' ) . '</th><th>' . esc_html__( 'Ontvangers', 'nieuws-distributie-systeem' ) . '</th><th>' . esc_html__( 'Tijdstip', 'nieuws-distributie-systeem' ) . '</th></tr></thead><tbody>';
				foreach ( array_reverse( $update_log ) as $u ) {
					echo '<tr><td>' . esc_html( $u['subject'] ) . '</td><td>' . esc_html( implode( ', ', $u['recipients'] ?? [] ) ) . '</td><td>' . esc_html( wp_date( 'd-m-Y H:i', $u['time'] ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}

			// Per-outlet table
			echo '<h4 style="margin:16px 0 8px;">' . esc_html__( 'Per ontvanger', 'nieuws-distributie-systeem' ) . '</h4>';
			echo '<table class="wp-list-table widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Ontvanger', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th>' . esc_html__( 'Opens / Downloads', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th>' . esc_html__( 'Laatste activiteit', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th>' . esc_html__( 'Toegang', 'nieuws-distributie-systeem' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $dispatch_log as $entry ) {
				$em        = $entry['email'];
				$is_blocked = in_array( $em, $blocked, true );
				$log_item  = $access_log[ $em ] ?? null;
				$views     = $log_item ? count( $log_item['views'] ?? [] ) : 0;
				$dls       = $log_item ? count( $log_item['downloads'] ?? [] ) : 0;
				$last_ts   = $log_item && $views > 0 ? max( $log_item['views'] ) : null;

				$status_color = $log_item ? '#28a745' : '#777';
				$status_text  = $log_item ? __( 'Geopend', 'nieuws-distributie-systeem' ) : __( 'Verzonden', 'nieuws-distributie-systeem' );

				echo '<tr' . ( $is_blocked ? ' style="opacity:.55;"' : '' ) . '>';
				echo '<td><strong>' . esc_html( $entry['name'] ) . '</strong><br><small>' . esc_html( $em ) . '</small></td>';
				echo '<td><span style="color:' . $status_color . ';font-weight:600;">' . esc_html( $status_text ) . '</span></td>';
				echo '<td>' . (int) $views . '× / ' . (int) $dls . '×</td>';
				echo '<td>' . ( $last_ts ? esc_html( wp_date( 'd-m-Y H:i', $last_ts ) ) : '—' ) . '</td>';
				echo '<td>';
				$btn_label = $is_blocked
					? esc_html__( '🔓 Herstel toegang', 'nieuws-distributie-systeem' )
					: esc_html__( '🔒 Blokkeer', 'nieuws-distributie-systeem' );
				$btn_class = $is_blocked ? 'button button-small snd-unblock-outlet' : 'button button-small snd-block-outlet';
				echo '<button class="' . $btn_class . '" data-postid="' . (int) $post_id . '" data-email="' . esc_attr( $em ) . '">' . $btn_label . '</button>';
				echo '</td></tr>';

				// Download detail rows
				if ( $log_item && ! empty( $log_item['downloads'] ) ) {
					foreach ( $log_item['downloads'] as $dl ) {
						$fname = is_string( $dl['image_id'] ) && false !== strpos( $dl['image_id'], 'zip' ) ? 'ZIP' : ( wp_basename( get_attached_file( (int) $dl['image_id'] ) ) ?: 'ID: ' . $dl['image_id'] );
						echo '<tr style="background:#fafafa' . ( $is_blocked ? ';opacity:.55' : '' ) . '">';
						echo '<td></td><td><small style="color:#0073aa;">↳ Download</small></td>';
						echo '<td colspan="2"><small>' . esc_html( $fname ) . ' — ' . esc_html( wp_date( 'd-m-Y H:i', $dl['time'] ) ) . '</small></td>';
						echo '<td></td></tr>';
					}
				}
			}
			echo '</tbody></table>';
		}
		wp_send_json_success( [ 'html' => ob_get_clean() ] );
	}

	// ── AJAX: Toggle download block for an outlet on a specific post ──────────

	public function ajax_toggle_outlet_block(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$block   = (bool) ( $_POST['block'] ?? true );

		$blocked = get_post_meta( $post_id, self::META_BLOCKED_OUTLETS, true );
		if ( ! is_array( $blocked ) ) $blocked = [];

		if ( $block ) {
			$blocked = array_unique( array_merge( $blocked, [ $email ] ) );
		} else {
			$blocked = array_values( array_filter( $blocked, fn( $e ) => $e !== $email ) );
		}

		update_post_meta( $post_id, self::META_BLOCKED_OUTLETS, $blocked );
		wp_send_json_success( [
			'message' => $block
				? sprintf( __( 'Toegang geblokkeerd voor %s.', 'nieuws-distributie-systeem' ), $email )
				: sprintf( __( 'Toegang hersteld voor %s.', 'nieuws-distributie-systeem' ), $email ),
			'blocked' => $block,
		] );
	}

	// ── AJAX: Get access control overview (all posts × all outlets) ───────────

	public function ajax_get_access_control(): void {
		$this->verify_nonce();
		$outlets = get_option( self::OPTION_OUTLETS, [] );

		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'meta_query'     => [ [ 'key' => self::META_DISPATCH_LOG, 'compare' => 'EXISTS' ] ],
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$rows = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid     = get_the_ID();
				$blocked = get_post_meta( $pid, self::META_BLOCKED_OUTLETS, true );
				if ( ! is_array( $blocked ) ) $blocked = [];
				$dispatch = get_post_meta( $pid, self::META_DISPATCH_LOG, true );
				if ( ! is_array( $dispatch ) ) continue;

				$sent_to = array_keys( $dispatch );
				$rows[]  = [
					'post_id'   => $pid,
					'title'     => get_the_title(),
					'date'      => get_the_date( 'd-m-Y' ),
					'sent_to'   => $sent_to,
					'blocked'   => $blocked,
				];
			}
			wp_reset_postdata();
		}

		wp_send_json_success( [ 'rows' => $rows, 'outlets' => $outlets ] );
	}

	// ── AJAX: Send update e-mail to previously notified outlets ──────────────

	public function ajax_send_update_email(): void {
		$this->verify_nonce();

		$post_id    = absint( $_POST['post_id'] ?? 0 );
		// recipients can arrive as array or as repeated scalar (jQuery traditional:true)
		$raw_recipients = $_POST['recipients'] ?? [];
		if ( is_string( $raw_recipients ) ) {
			$raw_recipients = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
		}
		$recipients = array_values( array_filter( array_map( 'sanitize_email', (array) wp_unslash( $raw_recipients ) ) ) );
		$subject    = sanitize_text_field( wp_unslash( $_POST['update_subject'] ?? '' ) );
		$body_text  = wp_kses_post( wp_unslash( $_POST['update_body'] ?? '' ) );

		if ( empty( $recipients ) || empty( $subject ) || empty( $body_text ) ) {
			$debug = 'recipients=' . count($recipients) . ' subject=' . (empty($subject)?'leeg':'ok') . ' body=' . (empty($body_text)?'leeg':'ok');
			wp_send_json_error( [ 'message' => __( 'Vul onderwerp, tekst en ontvangers in.', 'nieuws-distributie-systeem' ) . ' (' . $debug . ')' ] );
		}

		$post    = get_post( $post_id );
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		$sent    = 0;

		if ( ! $post ) {
			wp_send_json_error( [ 'message' => __( 'Bericht niet gevonden.', 'nieuws-distributie-systeem' ) ] );
		}

		$from_name    = get_option( 'snd_email_from_name', get_bloginfo( 'name' ) );
		$from_email   = get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) );
		$site_name    = get_bloginfo( 'name' );
		$accent_color = get_option( 'snd_email_accent_color', '#3b82f6' );
		$post_title   = $post->post_title;
		$post_url     = get_permalink( $post_id );

		foreach ( $recipients as $email ) {
			$outlet = null;
			foreach ( $outlets as $o ) {
				if ( strtolower( $o['email'] ) === strtolower( $email ) ) {
					$outlet = $o;
					break;
				}
			}
			if ( ! $outlet ) continue;

			$portal_url  = home_url( '/persportaal/?access_code=' . rawurlencode( $outlet['access_code'] ) );
			$custom_note = $outlet['custom_email_note'] ?? '';

			ob_start();
			$tpl = SND_PLUGIN_PATH . 'templates/email-update-template.php';
			if ( file_exists( $tpl ) ) {
				include $tpl;
			}
			$html_body = ob_get_clean();

			if ( empty( trim( $html_body ) ) ) {
				continue; // template produced nothing, skip
			}

			$ok = wp_mail(
				$email,
				$subject,
				$html_body,
				[ 'Content-Type: text/html; charset=UTF-8', "From: {$from_name} <{$from_email}>" ]
			);
			if ( $ok ) $sent++;
		}

		if ( $sent > 0 && ! $this->is_test_mode() ) {
			$update_log = get_post_meta( $post_id, self::META_UPDATE_LOG, true );
			if ( ! is_array( $update_log ) ) $update_log = [];
			$update_log[] = [
				'subject'    => $subject,
				'recipients' => $recipients,
				'time'       => time(),
			];
			update_post_meta( $post_id, self::META_UPDATE_LOG, $update_log );
			delete_transient( 'snd_analytics_stats' );
		}

		$msg = sprintf( _n( '%d update-mail verzonden.', '%d update-mails verzonden.', $sent, 'nieuws-distributie-systeem' ), $sent );
		if ( $sent > 0 ) {
			wp_send_json_success( [ 'message' => $msg ] );
		} else {
			wp_send_json_error( [ 'message' => sprintf(
				__( 'Geen mails verzonden. Controleer of de ontvangers (%d geselecteerd) nog steeds in de perslijst staan en of de e-mailinstellingen correct zijn.', 'nieuws-distributie-systeem' ),
				count( $recipients )
			) ] );
		}
	}

	public function ajax_reset_report_stats(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		delete_post_meta( $post_id, self::META_DISPATCH_LOG );
		delete_post_meta( $post_id, self::META_ACCESS_LOG );
		delete_transient( 'snd_analytics_stats' );
		wp_send_json_success( [ 'message' => sprintf( __( 'Statistieken voor bericht %d gereset.', 'nieuws-distributie-systeem' ), $post_id ) ] );
	}

	/**
	 * Accepts either snd_nonce (map page) or snd_location_nonce (meta-box).
	 */
	private function verify_any_nonce(): void {
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '' ) );
		if ( wp_verify_nonce( $nonce, 'snd_nonce' ) || wp_verify_nonce( $nonce, 'snd_location_nonce' ) ) {
			return;
		}
		wp_send_json_error( [ 'message' => __( 'Sessie verlopen.', 'nieuws-distributie-systeem' ) ], 403 );
	}

	public function ajax_geocode_address(): void {
		$this->verify_any_nonce();
		$address = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );
		if ( empty( $address ) ) {
			wp_send_json_error();
		}
		$result = ( new SND_P2000() )->geocode( $address );
		if ( $result ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( [ 'message' => __( 'Locatie niet gevonden.', 'nieuws-distributie-systeem' ) ] );
		}
	}

	public function ajax_save_location(): void {
		check_ajax_referer( 'snd_location_nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}
		$fields = [ 'lat' => self::META_LAT, 'lon' => self::META_LON, 'street1' => self::META_STREET1, 'street2' => self::META_STREET2 ];
		foreach ( $fields as $input => $meta ) {
			if ( isset( $_POST[ $input ] ) ) {
				update_post_meta( $post_id, $meta, sanitize_text_field( wp_unslash( $_POST[ $input ] ) ) );
			}
		}
		wp_send_json_success( [ 'message' => __( 'Locatie opgeslagen!', 'nieuws-distributie-systeem' ) ] );
	}

	public function ajax_reverse_geocode(): void {
		$this->verify_any_nonce();
		$lat = sanitize_text_field( wp_unslash( $_POST['lat'] ?? '' ) );
		$lon = sanitize_text_field( wp_unslash( $_POST['lon'] ?? '' ) );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			wp_send_json_error();
		}
		$geo = ( new SND_P2000() )->reverse_geocode( $lat, $lon );
		if ( ! empty( $geo['straat'] ) ) {
			wp_send_json_success( $geo );
		} else {
			wp_send_json_error( [ 'message' => __( 'Geen adres gevonden.', 'nieuws-distributie-systeem' ) ] );
		}
	}

	// ── Page: Dashboard ───────────────────────────────────────────────────────

	public function page_dashboard(): void {
		$posts = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		?>
		<div class="wrap snd-admin-wrap">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
				<div>
					<h1 style="margin:0;"><?php esc_html_e( 'Verzend-Dashboard', 'nieuws-distributie-systeem' ); ?></h1>
					<p style="margin:4px 0 0;color:#666;"><?php esc_html_e( 'Selecteer een bericht om te versturen, of maak direct een nieuw incident aan.', 'nieuws-distributie-systeem' ); ?></p>
				</div>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<div id="snd-live-viewers" style="display:none;">
						<span class="live-pulse"></span>
						<span id="snd-live-viewers-count">0</span> nu actief
					</div>
					<span style="font-size:12px;color:#888;">Filter:</span>
					<button class="button button-small snd-dash-filter active" data-filter="all">Alles</button>
					<button class="button button-small snd-dash-filter" data-filter="unsent" style="color:#b32d2e;">⭕ Nog niet verstuurd</button>
					<button class="button button-small snd-dash-filter" data-filter="sent">✓ Verstuurd</button>
					<button id="snd-new-incident-btn" class="button button-primary" style="height:36px;font-size:14px;margin-left:8px;">
						+ <?php esc_html_e( 'Nieuw incident aanmaken', 'nieuws-distributie-systeem' ); ?>
					</button>
				</div>
			</div>

			<!-- Quick stats row -->
			<div id="snd-dash-stats" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
				<?php
				$stat_cards = [
					[ 'id' => 'stat-dispatched-today', 'label' => 'Vandaag verzonden',   'icon' => '📨', 'color' => '#3b82f6' ],
					[ 'id' => 'stat-opens-today',      'label' => 'Opens vandaag',        'icon' => '👁',  'color' => '#10b981' ],
					[ 'id' => 'stat-dl-today',         'label' => 'Downloads vandaag',    'icon' => '📥', 'color' => '#f59e0b' ],
					[ 'id' => 'stat-active',           'label' => 'Actieve meldingen',    'icon' => '🚨', 'color' => '#ef4444' ],
					[ 'id' => 'stat-p2000',            'label' => 'P2000 in feed',        'icon' => '📡', 'color' => '#8b5cf6' ],
					[ 'id' => 'stat-outlets',          'label' => 'Outlets',              'icon' => '📰', 'color' => '#64748b' ],
				];
				foreach ( $stat_cards as $sc ) :
				?>
				<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;min-width:130px;flex:1;border-top:3px solid <?php echo esc_attr($sc['color']); ?>;">
					<div style="font-size:20px;font-weight:800;color:<?php echo esc_attr($sc['color']); ?>;" id="<?php echo esc_attr($sc['id']); ?>">—</div>
					<div style="font-size:11px;color:#888;margin-top:3px;"><?php echo esc_html($sc['icon']); ?> <?php echo esc_html($sc['label']); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<script>
			// Dashboard post search
			function applyDashFilters() {
				var q = jQuery('#snd-dash-search').val().toLowerCase().trim();
				var filter = jQuery('.snd-dash-filter.active').data('filter') || 'all';
				jQuery('.wp-list-table.posts tbody tr[data-title]').each(function(){
					var $r = jQuery(this);
					var matchSearch = !q || $r.data('title').includes(q);
					var matchFilter = filter === 'all'
						|| (filter === 'sent'   && $r.data('dispatched') == 1)
						|| (filter === 'unsent' && $r.data('dispatched') == 0);
					$r.toggle(!!(matchSearch && matchFilter));
				});
			}
			jQuery('#snd-dash-search').on('input', applyDashFilters);
			jQuery('.snd-dash-filter').on('click', function(){
				jQuery('.snd-dash-filter').removeClass('active');
				jQuery(this).addClass('active');
				applyDashFilters();
			});
			jQuery.post(ajaxurl, {
				action: 'snd_get_dashboard_stats',
				nonce: <?php echo wp_json_encode( wp_create_nonce('snd_nonce') ); ?>
			}).done(function(r){
				if (!r.success) return;
				var d = r.data;
				jQuery('#stat-dispatched-today').text(d.dispatched_today);
				jQuery('#stat-opens-today').text(d.opens_today);
				jQuery('#stat-dl-today').text(d.downloads_today);
				jQuery('#stat-active').text(d.active_meldingen);
				jQuery('#stat-p2000').text(d.p2000_in_feed);
				jQuery('#stat-outlets').text(d.outlets);
				jQuery('#stat-dispatched-today').attr('title','Deze week: '+d.dispatched_week);
				jQuery('#stat-opens-today').attr('title','Deze week: '+d.opens_week);
				jQuery('#stat-dl-today').attr('title','Deze week: '+d.downloads_week);
			});

			// ── Live viewers: poll access logs for recent opens (last 5 min) ──
			(function pollLiveViewers() {
				jQuery.post(ajaxurl, {
					action: 'snd_get_live_viewers',
					nonce: <?php echo wp_json_encode( wp_create_nonce('snd_nonce') ); ?>
				}).done(function(r) {
					if (r.success) {
						var count = r.data.count || 0;
						jQuery('#snd-live-viewers-count').text(count);
						jQuery('#snd-live-viewers').toggle(count > 0);
					}
				});
				setTimeout(pollLiveViewers, 30000); // poll every 30s
			})();

			// ── Ctrl+N = nieuw incident aanmaken ──────────────────────────────
			jQuery(document).on('keydown', function(e) {
				if ((e.ctrlKey || e.metaKey) && e.key === 'n' && !jQuery(e.target).is('input,textarea,select')) {
					e.preventDefault();
					jQuery('#snd-new-incident-btn').trigger('click');
				}
			});

			// ── Pin post ───────────────────────────────────────────────────────
			jQuery(document).on('click', '.snd-pin-post', function() {
				var $btn = jQuery(this).prop('disabled', true);
				var postId = $btn.data('postid');
				var nonce = <?php echo wp_json_encode( wp_create_nonce('snd_nonce') ); ?>;
				jQuery.post(ajaxurl, { action: 'snd_pin_post', nonce: nonce, post_id: postId })
				.done(function(r) {
					if (r.success) {
						var pinned = r.data.pinned;
						$btn.text(pinned ? '📌 Los' : '📌')
							.css({'color': pinned ? '#f59e0b' : '', 'border-color': pinned ? '#f59e0b' : ''})
							.attr('title', pinned ? 'Losmaken' : 'Vastzetten');
						var $row = $btn.closest('tr');
						$row.attr('data-pinned', pinned ? '1' : '0')
							.css('border-left', pinned ? '3px solid #f59e0b' : '');
						// Move pinned rows to top
						if (pinned) {
							$row.prependTo($row.closest('tbody'));
						}
					}
				}).always(function() { $btn.prop('disabled', false); });
			});

			// ── ETA instellen ──────────────────────────────────────────────────
			jQuery(document).on('change blur', '.snd-post-eta-input', function() {
				var $inp   = jQuery(this);
				var postId = $inp.data('postid');
				var mins   = parseInt($inp.val(), 10) || 0;
				var nonce  = <?php echo wp_json_encode( wp_create_nonce('snd_nonce') ); ?>;
				jQuery.post(ajaxurl, { action: 'snd_set_post_eta', nonce: nonce, post_id: postId, minutes: mins });
			});

			// ── ETA countdown op dashboard ─────────────────────────────────────
			(function() {
				function tickETA() {
					jQuery('.snd-eta-badge').each(function() {
						var remain = parseInt(jQuery(this).data('remain'), 10);
						if (remain <= 0) { jQuery(this).remove(); return; }
						remain--;
						jQuery(this).data('remain', remain);
						jQuery(this).find('.snd-eta-val').text(remain);
						if (remain === 0) jQuery(this).remove();
					});
				}
				setInterval(tickETA, 60000); // tick every minute
			})();
			jQuery(document).on('click', '.snd-quick-resend', function() {
				var $btn    = jQuery(this).prop('disabled', true).text('Versturen…');
				var postId  = $btn.data('postid');
				var title   = $btn.data('posttitle');
				if (!confirm('Bericht "' + title + '" opnieuw versturen aan alle eerdere ontvangers?')) {
					$btn.prop('disabled', false).text('↩ Opnieuw'); return;
				}
				var nonce = <?php echo wp_json_encode( wp_create_nonce('snd_nonce') ); ?>;
				jQuery.post(ajaxurl, { action: 'snd_quick_resend', nonce: nonce, post_id: postId })
				.done(function(r) {
					if (r.success) {
						$btn.text('✓ ' + r.data.message).css('color','#16a34a');
						setTimeout(function(){ $btn.prop('disabled',false).text('↩ Opnieuw').css('color',''); }, 3000);
					} else {
						alert('Fout: ' + (r.data && r.data.message || 'Onbekende fout'));
						$btn.prop('disabled', false).text('↩ Opnieuw');
					}
				}).fail(function(){ $btn.prop('disabled', false).text('↩ Opnieuw'); });
			});
			</script>

			<table class="wp-list-table widefat fixed striped posts">
				<thead>
					<tr>
						<th>
							<?php esc_html_e( 'Titel', 'nieuws-distributie-systeem' ); ?>
							<input type="search" id="snd-dash-search"
								placeholder="Filter berichten…"
								style="margin-left:12px;width:220px;padding:3px 8px;font-size:12px;border:1px solid #ddd;border-radius:4px;vertical-align:middle;">
						</th>
						<th style="width:130px;"><?php esc_html_e( 'Gepubliceerd', 'nieuws-distributie-systeem' ); ?></th>
						<th style="width:130px;"><?php esc_html_e( 'Status', 'nieuws-distributie-systeem' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Acties', 'nieuws-distributie-systeem' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$status_labels = [
					'onderweg'    => [ 'label' => '🚨 Onderweg',    'color' => '#f59e0b' ],
					'ter_plaatse' => [ 'label' => '📍 Ter plaatse', 'color' => '#3b82f6' ],
					'afgerond'    => [ 'label' => '✓ Afgerond',     'color' => '#10b981' ],
				];
				if ( $posts->have_posts() ) :
					while ( $posts->have_posts() ) :
						$posts->the_post();
						$dispatched  = ! empty( get_post_meta( get_the_ID(), self::META_DISPATCH_LOG, true ) );
						$inc_status  = get_post_meta( get_the_ID(), self::META_INCIDENT_STATUS, true );
						$is_pinned   = ! empty( get_post_meta( get_the_ID(), '_snd_pinned', true ) );
						$eta_min     = (int) get_post_meta( get_the_ID(), '_snd_eta_minutes', true );
						$eta_set_at  = (int) get_post_meta( get_the_ID(), '_snd_eta_set_at', true );
						// Remaining minutes
						$eta_remain  = $eta_min > 0 && $eta_set_at > 0
							? max( 0, $eta_min - (int) floor( ( time() - $eta_set_at ) / 60 ) )
							: 0;
						?>
						<tr data-title="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>"
						data-dispatched="<?php echo $dispatched ? '1' : '0'; ?>"
						data-pinned="<?php echo $is_pinned ? '1' : '0'; ?>"
						style="<?php echo $is_pinned ? 'border-left:3px solid #f59e0b;' : ''; ?>">
							<td>
								<strong><a href="<?php echo esc_url( get_edit_post_link() ); ?>" target="_blank"><?php the_title(); ?></a></strong>
								<?php if ( $dispatched ) : ?>
									<span class="snd-badge snd-badge-sent"><?php esc_html_e( 'Verzonden', 'nieuws-distributie-systeem' ); ?></span>
								<?php endif; ?>
								<?php if ( $is_pinned ) : ?>
									<span title="Vastgezet" style="margin-left:4px;color:#f59e0b;">📌</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( get_the_date() ); ?></td>
							<td>
								<?php if ( $inc_status && isset( $status_labels[ $inc_status ] ) ) :
									$sl = $status_labels[ $inc_status ]; ?>
									<span style="font-size:12px;font-weight:600;color:<?php echo esc_attr( $sl['color'] ); ?>;"><?php echo esc_html( $sl['label'] ); ?></span>
								<?php else : ?>
									<span style="color:#aaa;font-size:12px;">—</span>
								<?php endif; ?>
								<?php if ( $eta_remain > 0 ) : ?>
									<br><span class="snd-eta-badge" data-remain="<?php echo $eta_remain; ?>" style="font-size:11px;background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 6px;margin-top:2px;display:inline-block;">⏱ <span class="snd-eta-val"><?php echo $eta_remain; ?></span> min</span>
								<?php endif; ?>
							</td>
							<td>
								<div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;">
									<button class="button button-primary snd-open-modal"
										data-postid="<?php echo get_the_ID(); ?>"
										data-posttitle="<?php echo esc_attr( get_the_title() ); ?>">
										<?php esc_html_e( 'Verstuur / Foto\'s', 'nieuws-distributie-systeem' ); ?>
									</button>
									<button class="button snd-pin-post" data-postid="<?php echo get_the_ID(); ?>"
										title="<?php echo $is_pinned ? 'Losmaken' : 'Vastzetten'; ?>"
										style="font-size:11px;<?php echo $is_pinned ? 'color:#f59e0b;border-color:#f59e0b;' : ''; ?>">
										<?php echo $is_pinned ? '📌 Los' : '📌'; ?>
									</button>
									<?php if ( $dispatched ) : ?>
									<button class="button snd-quick-resend"
										data-postid="<?php echo get_the_ID(); ?>"
										data-posttitle="<?php echo esc_attr( get_the_title() ); ?>"
										title="Opnieuw versturen" style="font-size:11px;">↩ Opnieuw
									</button>
									<?php endif; ?>
									<span style="display:inline-flex;align-items:center;gap:3px;font-size:11px;color:#888;">
										⏱<input type="number" class="snd-post-eta-input" data-postid="<?php echo get_the_ID(); ?>"
											value="<?php echo esc_attr( $eta_remain ?: '' ); ?>"
											min="0" max="999" placeholder="ETA"
											style="width:46px;padding:2px 4px;font-size:11px;border:1px solid #ddd;border-radius:4px;"
											title="ETA in minuten instellen">min
									</span>
								</div>
							</td>
						</tr>
						<?php
					endwhile;
					wp_reset_postdata();
				else :
					echo '<tr><td colspan="4">' . esc_html__( 'Geen gepubliceerde berichten.', 'nieuws-distributie-systeem' ) . '</td></tr>';
				endif;
				?>
				</tbody>
			</table>
		</div>

		<!-- New Incident Modal -->
		<!-- New Incident Modal — creates active melding (NOT a WP post) -->
		<div id="snd-new-incident-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:10px;width:90%;max-width:620px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.35);">
				<div style="padding:18px 24px;border-bottom:1px solid #e2e4e7;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
					<h2 style="margin:0;font-size:1.05rem;">+ Eigen melding toevoegen</h2>
					<button id="snd-ni-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#888;line-height:1;padding:0 4px;">&times;</button>
				</div>
				<div style="padding:20px 24px;">
					<p style="font-size:13px;color:#555;background:#fff8e1;border-left:3px solid #f59e0b;padding:10px 14px;border-radius:4px;margin:0 0 18px;">
						Dit maakt een <strong>eigen melding</strong> aan die direct zichtbaar is in de P2000-tabel en het persportaal — <em>zonder</em> een persbericht aan te maken. Vanuit de melding kun je later eventueel een item starten.
					</p>

					<table class="form-table" style="margin:0;">
						<tr>
							<th style="width:120px;padding:10px 0;vertical-align:top;padding-top:14px;"><label for="snd-ni-title">Omschrijving *</label></th>
							<td style="padding:8px 0;"><input type="text" id="snd-ni-title" class="widefat" placeholder="bijv. Woningbrand Korvelseweg Tilburg" style="font-size:14px;"></td>
						</tr>
						<tr>
							<th style="padding:10px 0;vertical-align:top;padding-top:14px;">Type</th>
							<td style="padding:8px 0;">
								<div style="display:flex;gap:6px;flex-wrap:wrap;">
									<?php foreach(['brandweer'=>'🔥 Brandweer','politie'=>'🚔 Politie','mmt'=>'🚑 MMT','overig'=>'📋 Overig'] as $v=>$l): ?>
										<label style="display:flex;align-items:center;gap:5px;padding:5px 11px;border-radius:6px;border:1px solid #ddd;cursor:pointer;font-size:12px;font-weight:600;">
											<input type="radio" name="snd-ni-type" value="<?php echo esc_attr($v); ?>" <?php echo $v==='brandweer'?'checked':''; ?> style="margin:0;"> <?php echo esc_html($l); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th style="padding:10px 0;vertical-align:top;padding-top:14px;">Status</th>
							<td style="padding:8px 0;">
								<div style="display:flex;gap:6px;flex-wrap:wrap;">
									<?php foreach([''=> '○ Geen','onderweg'=>'🚨 Onderweg','ter_plaatse'=>'📍 Ter plaatse'] as $v=>$l): ?>
										<label style="display:flex;align-items:center;gap:5px;padding:5px 11px;border-radius:6px;border:1px solid #ddd;cursor:pointer;font-size:12px;font-weight:600;">
											<input type="radio" name="snd-ni-status" value="<?php echo esc_attr($v); ?>" <?php echo $v===''?'checked':''; ?> style="margin:0;"> <?php echo esc_html($l); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th style="padding:10px 0;vertical-align:top;padding-top:14px;"><label>Locatie</label></th>
							<td style="padding:8px 0;">
								<div style="display:flex;gap:6px;margin-bottom:8px;">
									<input type="text" id="snd-ni-addr" class="widefat" placeholder="Zoek adres…" style="flex:1;">
									<button type="button" id="snd-ni-geocode" class="button">Zoek</button>
								</div>
								<div id="snd-ni-map" style="height:180px;border-radius:6px;background:#eee;border:1px solid #ddd;"></div>
								<div style="display:flex;gap:8px;margin-top:8px;">
									<input type="text" id="snd-ni-lat" class="widefat" placeholder="Latitude" style="font-family:monospace;font-size:12px;">
									<input type="text" id="snd-ni-lon" class="widefat" placeholder="Longitude" style="font-family:monospace;font-size:12px;">
								</div>
								<div style="display:flex;gap:8px;margin-top:6px;">
									<input type="text" id="snd-ni-street" class="widefat" placeholder="Straat">
									<input type="text" id="snd-ni-stad" class="widefat" placeholder="Stad">
								</div>
							</td>
						</tr>
						<tr>
							<th style="padding:10px 0;vertical-align:top;padding-top:14px;"><label for="snd-ni-notes">Notities</label></th>
							<td style="padding:8px 0;">
								<textarea id="snd-ni-notes" class="widefat" rows="3" placeholder="Extra info, interne notities…"></textarea>
							</td>
						</tr>
					</table>

					<hr style="margin:18px 0;">

					<div style="background:#f8fafc;border:1px solid #e2e4e7;border-radius:6px;padding:14px 16px;">
						<p style="margin:0 0 10px;font-size:13px;"><strong>E-mail aan media</strong><br>
						<span style="color:#666;">Optioneel: stuur direct een "fotograaf onderweg/ter plaatse" mail aan outlets.</span></p>
						<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;font-weight:600;">
							<input type="checkbox" id="snd-ni-send-email" style="margin:0;">
							Stuur e-mailnotificatie naar outlets
						</label>
					</div>
				</div>

				<div style="padding:14px 24px;border-top:1px solid #e2e4e7;display:flex;justify-content:space-between;align-items:center;position:sticky;bottom:0;background:#fff;">
					<span id="snd-ni-msg" style="font-size:13px;"></span>
					<div style="display:flex;gap:8px;">
						<button id="snd-ni-submit" class="button button-primary">Melding toevoegen</button>
						<button id="snd-ni-cancel" class="button">Annuleren</button>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function($){
			var niMap = null, niMarker = null;
			var niNonce = <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>;
			var googleKey = <?php echo wp_json_encode( get_option( 'snd_google_maps_api_key', '' ) ); ?>;

			function openNiModal() {
				$('#snd-new-incident-modal').css('display','flex');
				setTimeout(initNiMap, 150);
			}
			function closeNiModal() {
				$('#snd-new-incident-modal').css('display','none');
				// Reset form
				$('#snd-ni-title,#snd-ni-addr,#snd-ni-lat,#snd-ni-lon,#snd-ni-street,#snd-ni-stad,#snd-ni-notes').val('');
				$('input[name="snd-ni-status"][value=""]').prop('checked',true);
				$('input[name="snd-ni-type"][value="brandweer"]').prop('checked',true);
				$('#snd-ni-send-email').prop('checked',false);
				$('#snd-ni-msg').text('');
			}

			$('#snd-new-incident-btn').on('click', openNiModal);
			$('#snd-ni-close, #snd-ni-cancel').on('click', closeNiModal);
			$('#snd-new-incident-modal').on('click', function(e){
				if($(e.target).is('#snd-new-incident-modal')) closeNiModal();
			});

			// Map init
			function initNiMap() {
				if (niMap) { niMap.invalidateSize(); return; }
				if (typeof L === 'undefined') return;
				niMap = L.map('snd-ni-map').setView([51.56, 5.09], 12);
				if (googleKey) {
					L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
						subdomains:['mt0','mt1','mt2','mt3'], maxZoom:21
					}).addTo(niMap);
				} else {
					L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(niMap);
				}
				niMarker = L.marker([51.56, 5.09], {draggable:true, opacity:0}).addTo(niMap);
				niMap.on('click', function(e){ setNiPoint(e.latlng.lat, e.latlng.lng); });
				niMarker.on('dragend', function(e){ var ll=e.target.getLatLng(); setNiPoint(ll.lat, ll.lng); });
				setTimeout(function(){ niMap.invalidateSize(); }, 200);
			}

			function setNiPoint(lat, lon) {
				niMarker.setLatLng([lat, lon]).setOpacity(1);
				$('#snd-ni-lat').val(lat.toFixed(6));
				$('#snd-ni-lon').val(lon.toFixed(6));
				$.post(ajaxurl, {action:'snd_reverse_geocode', nonce:niNonce, lat:lat, lon:lon})
					.done(function(r){
						if(r.success){
							if(r.data.straat) $('#snd-ni-street').val(r.data.straat);
							if(r.data.stad)   $('#snd-ni-stad').val(r.data.stad);
						}
					});
			}

			$('#snd-ni-geocode').on('click', doGeocode);
			$('#snd-ni-addr').on('keypress', function(e){ if(e.which===13){e.preventDefault();doGeocode();} });
			function doGeocode() {
				var q = $('#snd-ni-addr').val().trim();
				if(!q) return;
				$('#snd-ni-geocode').prop('disabled',true).text('…');
				$.post(ajaxurl, {action:'snd_geocode_address', nonce:niNonce, address:q})
					.done(function(r){
						if(r.success && r.data.lat) {
							var lat=parseFloat(r.data.lat), lon=parseFloat(r.data.lon);
							initNiMap();
							niMap.setView([lat,lon],16);
							setNiPoint(lat,lon);
						} else { alert('Locatie niet gevonden.'); }
					})
					.always(function(){ $('#snd-ni-geocode').prop('disabled',false).text('Zoek'); });
			}

			// Auto-geocode when user fills in street + city and leaves the field
			function tryAutoGeocode() {
				var street = $('#snd-ni-street').val().trim();
				var stad   = $('#snd-ni-stad').val().trim();
				var lat    = $('#snd-ni-lat').val().trim();
				// Only auto-geocode if we don't already have coords
				if ( lat ) return;
				var q = '';
				if ( street && stad ) q = street + ', ' + stad;
				else if ( stad ) q = stad;
				if ( !q ) return;
				$('#snd-ni-geocode').prop('disabled',true).text('…');
				$.post(ajaxurl, {action:'snd_geocode_address', nonce:niNonce, address:q})
					.done(function(r){
						if(r.success && r.data.lat) {
							var lat=parseFloat(r.data.lat), lon=parseFloat(r.data.lon);
							initNiMap();
							niMap.setView([lat,lon], street ? 16 : 13);
							setNiPoint(lat,lon);
						}
					})
					.always(function(){ $('#snd-ni-geocode').prop('disabled',false).text('Zoek'); });
			}
			$('#snd-ni-street').on('blur', tryAutoGeocode);
			$('#snd-ni-stad').on('blur', tryAutoGeocode);

			// Clear coords when address fields are manually changed
			$('#snd-ni-street, #snd-ni-stad').on('input', function(){
				$('#snd-ni-lat, #snd-ni-lon').val('');
				if (niMarker) niMarker.setOpacity(0);
			});

			// Submit — creates an active melding in snd_p2000_active_statuses, NOT a WP post
			$('#snd-ni-submit').on('click', function(){
				var title = $.trim($('#snd-ni-title').val());
				if(!title){ alert('Vul een omschrijving in.'); $('#snd-ni-title').focus(); return; }

				$(this).prop('disabled',true).text('Toevoegen…');
				$('#snd-ni-msg').text('').css('color','');

				$.post(ajaxurl, {
					action:      'snd_create_active_melding',
					nonce:       niNonce,
					tekst:       title,
					type:        $('input[name="snd-ni-type"]:checked').val() || 'overig',
					status:      $('input[name="snd-ni-status"]:checked').val() || '',
					lat:         $('#snd-ni-lat').val(),
					lon:         $('#snd-ni-lon').val(),
					straat:      $('#snd-ni-street').val(),
					stad:        $('#snd-ni-stad').val(),
					notes:       $('#snd-ni-notes').val(),
					send_email:  $('#snd-ni-send-email').is(':checked') ? '1' : '0',
				}).done(function(r){
					if(r.success) {
						$('#snd-ni-msg').text('✓ Melding toegevoegd!').css('color','#16a34a');
						setTimeout(function(){
							closeNiModal();
							// Redirect to P2000 page to see the new melding
							window.location.href = <?php echo wp_json_encode( admin_url('admin.php?page=snd-p2000') ); ?>;
						}, 700);
					} else {
						$('#snd-ni-msg').text(r.data && r.data.message ? r.data.message : 'Fout.').css('color','#b32d2e');
						$('#snd-ni-submit').prop('disabled',false).text('Melding toevoegen');
					}
				}).fail(function(){
					$('#snd-ni-msg').text('Serverfout.').css('color','#b32d2e');
					$('#snd-ni-submit').prop('disabled',false).text('Melding toevoegen');
				});
			});
		})(jQuery);
		</script>
		<?php $this->render_dispatch_modal(); ?>
		<?php
	}

	private function render_dispatch_modal(): void {
		?>
		<div id="snd-send-modal" class="snd-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="snd-modal-title">
			<div class="snd-modal-box wide" role="document">
				<div class="snd-modal-header">
					<h2 id="snd-modal-title"></h2>
					<button type="button" class="snd-modal-close" aria-label="<?php esc_attr_e( 'Sluiten', 'nieuws-distributie-systeem' ); ?>">&times;</button>
				</div>
				<div class="snd-modal-body">
					<div class="nav-tab-wrapper">
						<a href="#snd-tab-recipients"  class="nav-tab nav-tab-active"><?php esc_html_e( 'Ontvangers', 'nieuws-distributie-systeem' ); ?></a>
						<a href="#snd-tab-update"       class="nav-tab"><?php esc_html_e( '📢 Update mailen', 'nieuws-distributie-systeem' ); ?></a>
						<a href="#snd-tab-history"      class="nav-tab" style="display:none;"><?php esc_html_e( 'Geschiedenis', 'nieuws-distributie-systeem' ); ?></a>
						<a href="#snd-tab-preview-imgs" class="nav-tab"><?php esc_html_e( 'Preview foto\'s', 'nieuws-distributie-systeem' ); ?></a>
						<a href="#snd-tab-press-photos" class="nav-tab"><?php esc_html_e( 'Persfoto\'s', 'nieuws-distributie-systeem' ); ?></a>
					<a href="#snd-tab-press-videos" class="nav-tab"><?php esc_html_e( "📹 Video's", 'nieuws-distributie-systeem' ); ?></a>
						<a href="#snd-tab-email-preview" class="nav-tab"><?php esc_html_e( '✉ E-mail preview', 'nieuws-distributie-systeem' ); ?></a>
					</div>

					<div id="snd-tab-recipients" class="snd-tab active">
						<div class="snd-tab-toolbar">
							<button id="snd-toggle-all" class="button button-small"><?php esc_html_e( 'Alles selecteren', 'nieuws-distributie-systeem' ); ?></button>
							<!-- Group filter buttons rendered by JS -->
							<span id="snd-group-filters" style="display:inline-flex;gap:4px;flex-wrap:wrap;margin-left:8px;"></span>
						</div>
						<div id="snd-outlets-list"></div>
					</div>

					<div id="snd-tab-update" class="snd-tab" style="display:none;">
						<p style="color:#666;font-size:13px;margin-bottom:14px;"><?php esc_html_e( 'Stuur een update-mail naar iedereen die dit bericht al heeft ontvangen. Ze krijgen een korte tekst + een directe link naar hun portaal.', 'nieuws-distributie-systeem' ); ?></p>
						<div class="snd-tab-toolbar">
							<button id="snd-toggle-all-update" class="button button-small"><?php esc_html_e( 'Alles selecteren', 'nieuws-distributie-systeem' ); ?></button>
						</div>
						<div id="snd-update-recipients-list" style="margin-bottom:14px;max-height:160px;overflow-y:auto;"></div>
						<p><label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Onderwerp:', 'nieuws-distributie-systeem' ); ?></label>
						<input type="text" id="snd-update-subject" class="widefat" placeholder="<?php esc_attr_e( 'bijv. Update: verdachte aangehouden', 'nieuws-distributie-systeem' ); ?>"></p>
						<p><label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Update-tekst:', 'nieuws-distributie-systeem' ); ?></label>
						<textarea id="snd-update-body" class="widefat" rows="5" placeholder="<?php esc_attr_e( 'Schrijf hier de update…', 'nieuws-distributie-systeem' ); ?>"></textarea></p>
					</div>

					<div id="snd-tab-history" class="snd-tab" style="display:none;">
						<div id="snd-history-list"></div>
					</div>
					<div id="snd-tab-preview-imgs" class="snd-tab" style="display:none;">
						<div id="snd-preview-images-list"></div>
					</div>
					<div id="snd-tab-press-photos" class="snd-tab" style="display:none;">
						<h4><?php esc_html_e( 'Watermerkvrije persfoto\'s voor portaal', 'nieuws-distributie-systeem' ); ?></h4>
						<div id="snd-press-photos-gallery" class="snd-photos-grid"></div>
						<button type="button" class="button" id="snd-add-press-photos"><?php esc_html_e( 'Afbeeldingen toevoegen', 'nieuws-distributie-systeem' ); ?></button>
					</div>

					<div id="snd-tab-press-videos" class="snd-tab" style="display:none;">
						<h4><?php esc_html_e( "Persvideo's voor portaal", 'nieuws-distributie-systeem' ); ?></h4>
						<p style="font-size:12px;color:#666;margin:0 0 12px;"><?php esc_html_e( "Plak een publieke deellink van Mega.io, Google Drive, Dropbox, WeTransfer of een directe video-URL. Media kunnen de video direct downloaden via die link.", 'nieuws-distributie-systeem' ); ?></p>

						<div id="snd-press-videos-list" style="margin-bottom:12px;"></div>

						<!-- Nieuw video toevoegen formulier -->
						<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;">
							<p style="font-size:12px;font-weight:600;margin:0 0 8px;color:#334155;">+ Link toevoegen</p>
							<div style="display:flex;flex-direction:column;gap:7px;">
								<div style="display:flex;gap:6px;align-items:center;">
									<input type="text" id="snd-video-url" placeholder="https://mega.nz/file/… of andere deellink"
										style="flex:1;font-size:12px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;">
								</div>
								<div style="display:flex;gap:6px;">
									<input type="text" id="snd-video-title" placeholder="Titel (bijv. Beelden brand Tilburg)"
										style="flex:1;font-size:12px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;">
									<input type="text" id="snd-video-size" placeholder="Grootte (bijv. 240 MB)"
										style="width:110px;font-size:12px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;">
									<button type="button" class="button button-primary" id="snd-add-video-link" style="font-size:12px;">Toevoegen</button>
								</div>
							</div>
							<p style="font-size:11px;color:#888;margin:6px 0 0;">Zorg dat de link publiek deelbaar is en geen inloggen vereist.</p>
						</div>
					</div>
				</div>
				<div id="snd-tab-email-preview" class="snd-tab" style="display:none;">
					<p style="font-size:13px;color:#555;margin:0 0 12px;"><?php esc_html_e( 'Zo ziet de e-mail eruit voor de ontvanger. De knop, accentkleur en inhoud zijn live.', 'nieuws-distributie-systeem' ); ?></p>
					<button type="button" class="button button-small" id="snd-load-preview"><?php esc_html_e( '🔄 Laad preview', 'nieuws-distributie-systeem' ); ?></button>
					<span id="snd-preview-loading" style="display:none;margin-left:10px;font-size:12px;color:#888;"><?php esc_html_e( 'Laden…', 'nieuws-distributie-systeem' ); ?></span>
					<div id="snd-email-preview-frame" style="margin-top:14px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;background:#f1f5f9;min-height:200px;"></div>
				</div>
				<div class="snd-modal-footer">
						<div class="snd-modal-status"><span id="snd-modal-status"></span></div>
						<div class="snd-modal-actions">
							<button type="button" class="button" id="snd-save-press-videos" style="display:none;"><?php esc_html_e( "Video's opslaan", 'nieuws-distributie-systeem' ); ?></button>
							<button type="button" class="button" id="snd-save-press-photos" style="display:none;"><?php esc_html_e( "Foto's opslaan", 'nieuws-distributie-systeem' ); ?></button>
							<button type="button" class="button button-primary" id="snd-send-email"><?php esc_html_e( 'Verstuur e-mail', 'nieuws-distributie-systeem' ); ?></button>
							<button type="button" class="button button-primary" style="display:none;" id="snd-send-update"><?php esc_html_e( '📢 Verstuur update', 'nieuws-distributie-systeem' ); ?></button>
							<button type="button" class="button snd-modal-close"><?php esc_html_e( 'Annuleren', 'nieuws-distributie-systeem' ); ?></button>
							<span class="spinner"></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Page: P2000 Feed ──────────────────────────────────────────────────────

	public function page_p2000(): void {
		// Handle manual actions
		if ( isset( $_GET['snd_action'] ) ) {
			$action = sanitize_text_field( $_GET['snd_action'] );

			if ( 'fetch' === $action && check_admin_referer( 'snd_manual_fetch' ) ) {
				( new SND_P2000() )->fetch();
				wp_schedule_single_event( time(), 'snd_geocode_batch_hook' );
				add_action( 'admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>&#10003; Feed opgehaald. Geocoding loopt op de achtergrond (zie Diagnose-tabblad).</p></div>';
				} );
			}

			if ( 'reset' === $action && check_admin_referer( 'snd_reset_feed' ) ) {
				( new SND_P2000() )->reset();
				add_action( 'admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>Feeds gereset.</p></div>';
				} );
			}

			if ( 'geocode' === $action && check_admin_referer( 'snd_manual_geocode' ) ) {
				$done = ( new SND_P2000() )->geocode_batch();
				$msg  = "{$done} meldingen geocodeerd. Zie Diagnose-tabblad.";
				add_action( 'admin_notices', function() use ( $msg ) {
					echo "<div class='notice notice-success is-dismissible'><p>" . esc_html( $msg ) . "</p></div>";
				} );
			}
		}

		$feeds    = get_option( 'snd_p2000_feeds_structured', [] );
		$all_msgs = array_merge( $feeds['brandweer'] ?? [], $feeds['politie'] ?? [], $feeds['mmt'] ?? [] );
		usort( $all_msgs, fn( $a, $b ) => ( $b['tijd'] ?? 0 ) <=> ( $a['tijd'] ?? 0 ) );

		// Build linked post lookup
		$linked_query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_P2000_ID, 'compare' => 'EXISTS' ] ],
		] );
		$linked_p2000 = [];
		foreach ( $linked_query->posts as $pid ) {
			$p2id = get_post_meta( $pid, self::META_P2000_ID, true );
			if ( $p2id ) $linked_p2000[ $p2id ] = [ 'id' => $pid, 'title' => get_the_title( $pid ), 'edit' => get_edit_post_link( $pid ) ];
		}

		// All published posts for the link dropdown
		$pub_query = new \WP_Query( [ 'post_type' => 'post', 'posts_per_page' => 100, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ] );
		$pub_posts = [];
		if ( $pub_query->have_posts() ) {
			while ( $pub_query->have_posts() ) { $pub_query->the_post(); $pub_posts[] = [ 'id' => get_the_ID(), 'title' => get_the_title() ]; }
			wp_reset_postdata();
		}

		$fetch_url = wp_nonce_url( admin_url( 'admin.php?page=snd-p2000&snd_action=fetch' ), 'snd_manual_fetch' );
		$reset_url = wp_nonce_url( admin_url( 'admin.php?page=snd-p2000&snd_action=reset' ), 'snd_reset_feed' );

		$type_colors = [ 'brandweer' => '#ef4444', 'politie' => '#f59e0b', 'mmt' => '#8b5cf6', 'overig' => '#6b7280' ];

		// ── Groepeer meldingen per incident ──────────────────────────────────────
		// Berichten op zelfde locatie binnen 6 uur worden gekoppeld.
		// Exacte duplicaten (zelfde tekst zonder voertuigcode) worden samengevoegd.
		$p2000_ungrouped_pairs = get_option( 'snd_p2000_ungrouped_pairs', [] ); // manual uncouples
		$time_window = 6 * 3600;

		$snd_strip_unit = function( string $tekst ): string {
			// Strip leading unit codes: BZB-01, A 1, P 2, DIA 1, OVD-B, etc.
			$tekst = preg_replace( '/^[A-Z]{1,4}[-\s]?\d+\s*/i', '', $tekst );
			// Strip Prio X
			$tekst = preg_replace( '/\bPrio\s*\d+\s*/i', '', $tekst );
			// Strip capcode-style identifiers like "bzb-01"
			$tekst = preg_replace( '/\bbzb-\d+\s*/i', '', $tekst );
			return trim( $tekst );
		};

		$snd_same_location = function( array $a, array $b ): bool {
			$a_straat = strtolower( trim( $a['straat'] ?? '' ) );
			$b_straat = strtolower( trim( $b['straat'] ?? '' ) );
			$a_stad   = strtolower( trim( $a['stad']   ?? '' ) );
			$b_stad   = strtolower( trim( $b['stad']   ?? '' ) );

			// Both need at least a city
			if ( empty( $a_stad ) || empty( $b_stad ) ) return false;
			if ( $a_stad !== $b_stad ) return false;

			// If both have street: streets must match
			if ( $a_straat !== '' && $b_straat !== '' ) {
				return $a_straat === $b_straat;
			}

			// If one has no street: check coordinates distance if available
			if ( is_numeric( $a['lat'] ?? '' ) && is_numeric( $b['lat'] ?? '' ) ) {
				$dlat = abs( (float) $a['lat'] - (float) $b['lat'] );
				$dlon = abs( (float) $a['lon'] - (float) $b['lon'] );
				// ~0.005 degrees ≈ 500m
				return $dlat < 0.005 && $dlon < 0.007;
			}

			return false;
		};

		$snd_group_messages = function( array $messages ) use ( $p2000_ungrouped_pairs, $time_window, $snd_strip_unit, $snd_same_location ): array {
			$groups   = [];
			$assigned = []; // id => group_index

			foreach ( $messages as $i => $msg ) {
				$mid = $msg['id'] ?? $i;
				if ( isset( $assigned[ $mid ] ) ) continue;

				$group_idx = count( $groups );
				$groups[]  = [ 'primary' => $msg, 'related' => [] ];
				$assigned[ $mid ] = $group_idx;

				$t_a    = (int) ( $msg['tijd'] ?? 0 );
				$core_a = $snd_strip_unit( $msg['tekst'] ?? '' );

				foreach ( $messages as $j => $other ) {
					$oid = $other['id'] ?? $j;
					if ( $oid === $mid || isset( $assigned[ $oid ] ) ) continue;

					// Manual uncouple check
					$pair = $mid < $oid ? $mid . '|' . $oid : $oid . '|' . $mid;
					if ( isset( $p2000_ungrouped_pairs[ $pair ] ) ) continue;

					// Time window
					$t_b = (int) ( $other['tijd'] ?? 0 );
					if ( abs( $t_a - $t_b ) > $time_window ) continue;

					// Location check
					if ( ! $snd_same_location( $msg, $other ) ) continue;

					$groups[ $group_idx ]['related'][] = $other;
					$assigned[ $oid ] = $group_idx;
				}
			}

			// Within each group: deduplicate entries with identical core text
			foreach ( $groups as &$group ) {
				if ( empty( $group['related'] ) ) continue;
				$seen_cores = [ $snd_strip_unit( $group['primary']['tekst'] ?? '' ) => true ];
				$unique = [];
				foreach ( $group['related'] as $rel ) {
					$core = $snd_strip_unit( $rel['tekst'] ?? '' );
					if ( ! isset( $seen_cores[ $core ] ) ) {
						$seen_cores[ $core ] = true;
						$unique[] = $rel;
					}
					// else: exact duplicate — silently dropped
				}
				$group['related'] = $unique;
			}
			unset( $group );

			return $groups;
		};

		// Load manual links: primary_id => [ {tekst, type, tijd} ]
		$p2000_manual_links = get_option( 'snd_p2000_manual_links', [] );

		$render_table = function ( array $messages ) use ( $linked_p2000, $type_colors, $active_statuses, $snd_group_messages, $p2000_manual_links ) {
			if ( empty( $messages ) ) {
				echo '<p class="snd-empty-state" style="padding:16px;">' . esc_html__( 'Geen meldingen.', 'nieuws-distributie-systeem' ) . '</p>';
				return;
			}

			$groups = $snd_group_messages( $messages );

			echo '<table class="wp-list-table widefat fixed striped snd-p2000-table"><thead><tr>';
			echo '<th style="width:70px;">' . esc_html__( 'Type', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th style="width:58px;">' . esc_html__( 'Tijd', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th>' . esc_html__( 'Melding', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th style="width:150px;">' . esc_html__( 'Locatie', 'nieuws-distributie-systeem' ) . '</th>';
			echo '<th style="width:220px;">' . esc_html__( 'Status / Actie', 'nieuws-distributie-systeem' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $groups as $group ) {
				$msg            = $group['primary'];
				$related        = $group['related'];
				$has_related    = ! empty( $related );
				$manual_entries = $p2000_manual_links[ $msg_id ] ?? [];
				$has_manual     = ! empty( $manual_entries );
				$total_related  = count( $related ) + count( $manual_entries );
				$type           = $msg['type'] ?? 'overig';
				$color          = $type_colors[ $type ] ?? '#6b7280';
				$linked         = $linked_p2000[ $msg['id'] ] ?? null;
				$msg_id         = $msg['id'] ?? '';
				$current_status = $active_statuses[ $msg_id ]['status'] ?? '';
				$new_url        = add_query_arg( [
					'snd_lat'               => $msg['lat'] ?? '',
					'snd_lon'               => $msg['lon'] ?? '',
					'snd_street1'           => rawurlencode( $msg['straat'] ?? '' ),
					'snd_p2000_id'          => rawurlencode( $msg_id ),
					'snd_p2000_raw_message' => rawurlencode( $msg['tekst'] ?? '' ),
				], admin_url( 'post-new.php' ) );

				$row_style = '';
				if ( $current_status === 'onderweg' )    $row_style = 'background:rgba(245,158,11,.07);';
				if ( $current_status === 'ter_plaatse' ) $row_style = 'background:rgba(59,130,246,.07);';

				$group_id = 'grp-' . esc_attr( substr( md5( $msg_id ), 0, 8 ) );

				echo '<tr class="snd-p2000-row snd-p2000-primary-row" style="' . $row_style . '" data-p2000id="' . esc_attr( $msg_id ) . '" data-lat="' . esc_attr( $msg['lat'] ?? '' ) . '" data-lon="' . esc_attr( $msg['lon'] ?? '' ) . '" data-tekst="' . esc_attr( $msg['tekst'] ?? '' ) . '">';

				// Type badge
				echo '<td><span class="snd-p2000-badge" style="background:' . esc_attr( $color ) . ';">' . esc_html( ucfirst( $type ) ) . '</span>';
				// Show expand toggle if there are related or manual entries
				if ( $has_related || $has_manual ) {
					$toggle_label = $total_related . ( $has_manual ? ' (+✏️)' : '' );
					echo '<div style="margin-top:4px;">'
						. '<button class="button-link snd-p2000-group-toggle" data-group="' . $group_id . '" '
						. 'style="font-size:10px;color:#3b82f6;white-space:nowrap;">'
						. '▶ +' . $toggle_label . ' opsch.</button></div>';
				}
				// Always show "add" button
				echo '<div style="margin-top:3px;">'
					. '<button class="button-link snd-p2000-show-add-row" data-group="' . $group_id . '" '
					. 'style="font-size:10px;color:#888;white-space:nowrap;" title="Voeg handmatige melding toe aan dit incident">'
					. '➕ Voeg toe</button></div>';
				echo '</td>';

				// Time
				echo '<td style="font-variant-numeric:tabular-nums;font-size:12px;">' . esc_html( isset( $msg['tijd'] ) ? wp_date( 'H:i', $msg['tijd'] ) : '—' ) . '</td>';

				// Message text with copy button
				$tekst = $msg['tekst'] ?? '';
				echo '<td><div style="display:flex;align-items:baseline;gap:6px;">'
					. '<span>' . esc_html( $tekst ) . '</span>'
					. '<button type="button" class="button-link snd-p2000-copy-btn" '
					. 'data-tekst="' . esc_attr( $tekst ) . '" '
					. 'style="font-size:10px;color:#aaa;white-space:nowrap;flex-shrink:0;" '
					. 'title="Kopieer meldingtekst">📋</button>'
					. '</div></td>';

				// Location
				$has_coords = is_numeric( $msg['lat'] ?? '' ) && is_numeric( $msg['lon'] ?? '' );
				echo '<td>';
				if ( ! empty( $msg['stad'] ) ) echo '<strong>' . esc_html( $msg['stad'] ) . '</strong><br>';
				if ( ! empty( $msg['straat'] ) ) echo '<small>' . esc_html( $msg['straat'] ) . '</small>';
				if ( $has_coords ) echo '<br><button class="button-link snd-p2000-mappin" data-lat="' . esc_attr( $msg['lat'] ) . '" data-lon="' . esc_attr( $msg['lon'] ) . '" data-tekst="' . esc_attr( $msg['tekst'] ?? '' ) . '">📍 Kaart</button>';
				echo '</td>';

				// Status & Actions
				echo '<td>';
				echo '<div class="snd-p2000-status-wrap" data-id="' . esc_attr( $msg_id ) . '" style="margin-bottom:5px;display:flex;gap:4px;flex-wrap:wrap;">';
				$statuses = [
					'onderweg'    => [ 'label' => '🚨 OW',    'color' => '#f59e0b' ],
					'ter_plaatse' => [ 'label' => '📍 TP',    'color' => '#3b82f6' ],
					'geen'        => [ 'label' => '✕',        'color' => '#888'    ],
				];
				foreach ( $statuses as $sv => $sc ) {
					$is_active = $current_status === $sv;
					$style     = $is_active ? 'background:' . $sc['color'] . ';color:#fff;border-color:' . $sc['color'] . ';' : '';
					echo '<button class="button button-small snd-p2000-status-btn" '
						. 'data-id="' . esc_attr( $msg_id ) . '" data-status="' . esc_attr( $sv ) . '" '
						. 'data-tekst="'  . esc_attr( $msg['tekst']  ?? '' ) . '" '
						. 'data-stad="'   . esc_attr( $msg['stad']   ?? '' ) . '" '
						. 'data-straat="' . esc_attr( $msg['straat'] ?? '' ) . '" '
						. 'data-type="'   . esc_attr( $msg['type']   ?? 'overig' ) . '" '
						. 'data-lat="'    . esc_attr( $msg['lat']    ?? '' ) . '" '
						. 'data-lon="'    . esc_attr( $msg['lon']    ?? '' ) . '" '
						. 'data-source="feed" style="font-size:11px;' . $style . '">'
						. esc_html( $sc['label'] ) . '</button>';
				}
				echo '</div>';
				if ( $linked ) {
					echo '<span style="color:#16a34a;font-size:11px;font-weight:600;">✓ Gekoppeld</span><br>';
					echo '<a href="' . esc_url( $linked['edit'] ) . '" class="button-link" style="font-size:11px;" target="_blank">' . esc_html( wp_trim_words( $linked['title'], 4 ) ) . '</a>';
				} else {
					echo '<a href="' . esc_url( $new_url ) . '" class="button button-primary button-small" target="_blank" style="font-size:11px;">+ Item</a> ';
					echo '<button class="button button-small snd-p2000-link-btn" data-p2000id="' . esc_attr( $msg_id ) . '" data-tekst="' . esc_attr( $msg['tekst'] ?? '' ) . '" data-newurl="' . esc_attr( $new_url ) . '" style="font-size:11px;">Koppel</button>';
				}
				echo '</td>';
				echo '</tr>';

				// ── Related (opschalings) rows — collapsed by default ──────────────
				if ( $has_related ) {
					foreach ( $related as $rel ) {
						$rid     = $rel['id'] ?? '';
						$rtype   = $rel['type'] ?? 'overig';
						$rcolor  = $type_colors[ $rtype ] ?? '#6b7280';
						$pair_id = $msg_id < $rid ? $msg_id . '|' . $rid : $rid . '|' . $msg_id;
						echo '<tr class="snd-p2000-related-row" data-group="' . $group_id . '" '
							. 'style="display:none;background:#f0f7ff;border-left:3px solid #3b82f6;" '
							. 'data-lat="' . esc_attr( $rel['lat'] ?? '' ) . '" data-lon="' . esc_attr( $rel['lon'] ?? '' ) . '" data-tekst="' . esc_attr( $rel['tekst'] ?? '' ) . '">';
						echo '<td style="padding-left:24px;"><span class="snd-p2000-badge" style="background:' . esc_attr( $rcolor ) . ';opacity:.8;">' . esc_html( ucfirst( $rtype ) ) . '</span></td>';
						echo '<td style="font-size:11px;color:#888;">' . esc_html( isset( $rel['tijd'] ) ? wp_date( 'H:i', $rel['tijd'] ) : '—' ) . '</td>';
						echo '<td style="font-size:12px;color:#555;padding-left:8px;">' . esc_html( $rel['tekst'] ?? '' ) . '</td>';
						echo '<td></td>';
						echo '<td>';
						echo '<button class="button button-small snd-p2000-ungroup-btn" '
							. 'data-pair="' . esc_attr( $pair_id ) . '" '
							. 'data-primary="' . esc_attr( $msg_id ) . '" '
							. 'data-related="' . esc_attr( $rid ) . '" '
							. 'style="font-size:10px;color:#888;" title="Los deze melding los van het incident">'
							. '⛓ Loskoppelen</button>';
						echo '</td>';
						echo '</tr>';
					}
				}

				// ── Handmatig toegevoegde meldingen — altijd renderen ─────────
				// Check feed msg_id key AND the linked WP post's key
				$manual_entries = $p2000_manual_links[ $msg_id ] ?? [];
				if ( $linked ) {
					$post_key    = 'post_' . ( $linked['id'] ?? '' );
					$post_manual = $p2000_manual_links[ $post_key ] ?? [];
					// Merge without duplicates (by tekst)
					$existing_teksten = array_column( $manual_entries, 'tekst' );
					foreach ( $post_manual as $pm ) {
						if ( ! in_array( $pm['tekst'], $existing_teksten, true ) ) {
							$manual_entries[] = $pm;
						}
					}
				}
				foreach ( $manual_entries as $midx => $mentry ) {
					$mtype   = $mentry['type'] ?? 'overig';
					$mcolor  = $type_colors[ $mtype ] ?? '#6b7280';
					$mtijd   = ! empty( $mentry['tijd'] ) ? wp_date( 'H:i', (int) $mentry['tijd'] ) : '—';
					echo '<tr class="snd-p2000-related-row snd-p2000-manual-row" data-group="' . $group_id . '" '
						. 'style="display:none;background:#fff8f0;border-left:3px solid #f59e0b;">';
					echo '<td style="padding-left:24px;"><span class="snd-p2000-badge" style="background:' . esc_attr( $mcolor ) . ';opacity:.8;">' . esc_html( ucfirst( $mtype ) ) . '</span><br><small style="color:#f59e0b;font-size:9px;">✏️ handmatig</small></td>';
					echo '<td style="font-size:11px;color:#888;">' . esc_html( $mtijd ) . '</td>';
					echo '<td style="font-size:12px;color:#555;padding-left:8px;">' . esc_html( $mentry['tekst'] ?? '' ) . '</td>';
					echo '<td></td>';
					echo '<td>';
					echo '<button class="button button-small snd-p2000-remove-manual-btn" '
						. 'data-primary="' . esc_attr( $msg_id ) . '" '
						. 'data-idx="' . esc_attr( $midx ) . '" '
						. 'style="font-size:10px;color:#b32d2e;" title="Verwijder handmatige melding">'
						. '✕ Verwijder</button>';
					echo '</td>';
					echo '</tr>';
				}

				// ── Inline "voeg melding toe" rij — altijd aanwezig ──────────
				echo '<tr class="snd-p2000-related-row snd-p2000-add-row" data-group="' . $group_id . '" '
					. 'style="display:none;background:#f8f8f8;border-left:3px solid #d1d5db;">';
				echo '<td colspan="5" style="padding:8px 12px 8px 28px;">';
				echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
				echo '<select class="snd-manual-type" style="font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;">';
				foreach ( [ 'brandweer' => '🔥 Brandweer', 'politie' => '🚔 Politie', 'mmt' => '🚑 MMT', 'overig' => '📋 Overig' ] as $tv => $tl ) {
					echo '<option value="' . esc_attr( $tv ) . '">' . esc_html( $tl ) . '</option>';
				}
				echo '</select>';
				echo '<input type="text" class="snd-manual-tekst" placeholder="Plak of typ de P2000 meldingtekst hier…" '
					. 'style="font-size:12px;padding:4px 8px;border:1px solid #ddd;border-radius:4px;flex:1;min-width:200px;">';
				echo '<button class="button button-primary button-small snd-p2000-add-manual-btn" '
					. 'data-primary="' . esc_attr( $msg_id ) . '" '
					. 'style="font-size:11px;">➕ Toevoegen</button>';
				echo '<button class="button button-small snd-p2000-add-cancel-btn" style="font-size:11px;">Annuleer</button>';
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		};

		// Load active statuses before passing to render_table closure
		$active_statuses = get_option( 'snd_p2000_active_statuses', [] );

		?>
		<div class="wrap snd-admin-wrap">
			<h1><?php esc_html_e( 'P2000 Meldingen', 'nieuws-distributie-systeem' ); ?></h1>

			<?php
			$geocode_url   = wp_nonce_url( admin_url( 'admin.php?page=snd-p2000&snd_action=geocode' ), 'snd_manual_geocode' );
			$queue_size    = ( new SND_P2000() )->queue_size();
			?>
			<div class="snd-toolbar" style="flex-wrap:wrap;gap:8px;">
				<a href="<?php echo esc_url( $fetch_url ); ?>" class="button button-primary">↺ Haal nieuwe meldingen op</a>
				<?php if ( $queue_size > 0 ) : ?>
					<a href="<?php echo esc_url( $geocode_url ); ?>" class="button">
						📍 Geocodeer volgende batch (<?php echo $queue_size; ?> wachten)
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $reset_url ); ?>" class="button" onclick="return confirm('Alle P2000-data verwijderen?')">Reset feeds</a>
				<span style="font-size:12px;color:#888;align-self:center;">
					<?php if ( $queue_size > 0 ) : ?>
						⏳ <?php echo $queue_size; ?> meldingen wachten op geocoding (automatisch elke 2 min)
					<?php else : ?>
						✓ Alle meldingen zijn geocodeerd
					<?php endif; ?>
				</span>
			</div>
				<label style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:13px;">
					<input type="checkbox" id="snd-p2000-autorefresh"> <?php esc_html_e( 'Auto-refresh elke 60s', 'nieuws-distributie-systeem' ); ?>
				</label>
				<span id="snd-p2000-refresh-countdown" style="font-size:12px;color:#888;display:none;"></span>
			</div>

			<div class="snd-p2000-layout">
				<!-- Feed table -->
				<div class="snd-p2000-table-wrap">
					<!-- P2000 search bar -->
					<div style="padding:8px 0;margin-bottom:4px;display:flex;align-items:center;gap:8px;">
						<input type="search" id="snd-p2000-search"
							placeholder="🔍 Zoek in meldingen… (tekst, straat, stad)"
							style="width:320px;padding:6px 10px;font-size:13px;border:1px solid #ddd;border-radius:6px;">
						<span id="snd-p2000-search-count" style="font-size:12px;color:#888;"></span>
						<button id="snd-p2000-search-clear" style="display:none;font-size:11px;" class="button button-small">✕ Wis</button>
					</div>
					<div class="nav-tab-wrapper snd-p2000-tabs">
						<a href="#p2000-all"       class="nav-tab nav-tab-active"><?php esc_html_e( 'Alles', 'nieuws-distributie-systeem' ); ?> <span class="snd-count"><?php echo count( $all_msgs ); ?></span></a>
						<a href="#p2000-brandweer" class="nav-tab">🔥 <?php esc_html_e( 'Brandweer', 'nieuws-distributie-systeem' ); ?> <span class="snd-count"><?php echo count( $feeds['brandweer'] ?? [] ); ?></span></a>
						<a href="#p2000-politie"   class="nav-tab">🚔 <?php esc_html_e( 'Politie', 'nieuws-distributie-systeem' ); ?> <span class="snd-count"><?php echo count( $feeds['politie'] ?? [] ); ?></span></a>
						<a href="#p2000-mmt"       class="nav-tab">🚑 <?php esc_html_e( 'MMT', 'nieuws-distributie-systeem' ); ?> <span class="snd-count"><?php echo count( $feeds['mmt'] ?? [] ); ?></span></a>
						<?php
						$active_statuses  = get_option( 'snd_p2000_active_statuses', [] );
						$handmatig_items  = array_filter( $active_statuses, function($i){ return ($i['source'] ?? '') === 'handmatig'; } );
						?>
						<a href="#p2000-handmatig" class="nav-tab">📋 Eigen meldingen <span class="snd-count"><?php echo count( $handmatig_items ); ?></span></a>
						<a href="#p2000-diagnose"  class="nav-tab">🔧 Diagnose</a>
					</div>

					<div id="p2000-all"       class="snd-tab-content active"><?php $render_table( $all_msgs ); ?></div>
					<div id="p2000-brandweer" class="snd-tab-content"><?php $render_table( $feeds['brandweer'] ?? [] ); ?></div>
					<div id="p2000-politie"   class="snd-tab-content"><?php $render_table( $feeds['politie'] ?? [] ); ?></div>
					<div id="p2000-mmt"       class="snd-tab-content"><?php $render_table( $feeds['mmt'] ?? [] ); ?></div>

					<!-- Handmatige / eigen meldingen tab -->
					<div id="p2000-handmatig" class="snd-tab-content">
						<?php
						$all_outlets_h = get_option( self::OPTION_OUTLETS, [] );
						$active_outlets_h = array_values( array_filter( $all_outlets_h, function( $o ) {
							return ! empty( $o['email'] ) && ! empty( $o['access_code'] );
						} ) );
						$priority_cfg = [
							'urgent' => [ 'icon' => '🔴', 'label' => 'Urgent',  'color' => '#ef4444' ],
							'normaal'=> [ 'icon' => '🟡', 'label' => 'Normaal', 'color' => '#f59e0b' ],
							'laag'   => [ 'icon' => '🟢', 'label' => 'Laag',    'color' => '#10b981' ],
						];
						$auto_archive_h = (int) get_option( 'snd_melding_auto_archive_hours', 0 );
						?>
						<?php if ( $auto_archive_h > 0 ) : ?>
							<p style="margin:8px 16px;font-size:12px;color:#666;">♻ Meldingen ouder dan <?php echo $auto_archive_h; ?> uur worden automatisch gearchiveerd.</p>
						<?php endif; ?>
						<?php if ( empty( $handmatig_items ) ) : ?>
							<p style="padding:20px;color:#888;">Geen eigen meldingen. Klik op "+ Eigen melding toevoegen" om er een aan te maken.</p>
						<?php else : ?>
							<?php foreach ( $handmatig_items as $hid => $hitem ) :
								$htype     = $hitem['type']     ?? 'overig';
								$hstatus   = $hitem['status']   ?? '';
								$hpriority = $hitem['priority'] ?? 'normaal';
								$heta      = $hitem['eta']      ?? '';
								$hlog      = is_array( $hitem['log'] ?? null ) ? $hitem['log'] : [];
								$hlocatie  = trim( ( $hitem['straat'] ?? '' ) ? ( $hitem['straat'] . ', ' . ($hitem['stad']??'') ) : ( $hitem['stad'] ?? '' ) );
								$htime     = isset( $hitem['time'] ) ? wp_date( 'H:i d-m', $hitem['time'] ) : '—';
								$type_colors_h = [ 'brandweer'=>'#ef4444','politie'=>'#f59e0b','mmt'=>'#8b5cf6','overig'=>'#6b7280' ];
								$hcolor    = $type_colors_h[ $htype ] ?? '#6b7280';
								$pcfg      = $priority_cfg[ $hpriority ] ?? $priority_cfg['normaal'];
								$log_content = '';
								foreach ( $hlog as $le ) {
									$log_content .= wp_date( 'H:i', $le['time'] ) . ' [' . esc_html( $le['user'] ) . '] ' . esc_html( $le['text'] ) . "\n";
								}
								$new_post_url = add_query_arg( [
									'snd_lat'               => $hitem['lat'] ?? '',
									'snd_lon'               => $hitem['lon'] ?? '',
									'snd_street1'           => rawurlencode( $hitem['straat'] ?? '' ),
									'snd_p2000_raw_message' => rawurlencode( $hitem['tekst'] ?? '' ),
									'snd_log_content'       => rawurlencode( $log_content ),
								], admin_url( 'post-new.php' ) );
							?>
							<div class="snd-melding-card" style="border:1px solid #ddd;border-radius:8px;margin:12px 16px;overflow:hidden;background:#fff;border-left:4px solid <?php echo esc_attr($hcolor); ?>;">

								<!-- Header -->
								<div style="padding:12px 16px;background:#f8fafc;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
									<span class="snd-p2000-badge" style="background:<?php echo esc_attr($hcolor); ?>;"><?php echo esc_html( ucfirst($htype) ); ?></span>
									<strong style="font-size:14px;flex:1;"><?php echo esc_html( $hitem['tekst'] ?? '' ); ?></strong>

									<!-- Priority selector -->
									<div style="display:flex;gap:3px;">
										<?php foreach ( $priority_cfg as $pv => $pc ) : ?>
											<button class="button button-small snd-priority-btn"
												data-id="<?php echo esc_attr($hid); ?>"
												data-priority="<?php echo esc_attr($pv); ?>"
												style="font-size:11px;padding:2px 8px;<?php echo $hpriority === $pv ? 'background:'.esc_attr($pc['color']).';color:#fff;border-color:'.esc_attr($pc['color']).';' : ''; ?>"
												title="Prioriteit: <?php echo esc_attr($pc['label']); ?>">
												<?php echo $pc['icon']; ?>
											</button>
										<?php endforeach; ?>
									</div>

									<!-- Status -->
									<div class="snd-p2000-status-wrap" data-id="<?php echo esc_attr($hid); ?>" style="display:flex;gap:3px;">
										<?php foreach(['onderweg'=>['🚨','#f59e0b'],'ter_plaatse'=>['📍','#3b82f6'],'geen'=>['✕','#888']] as $sv=>[$si,$sc]):
											$is_active = $hstatus === $sv;
											$style = $is_active ? "background:{$sc};color:#fff;border-color:{$sc};" : '';
										?>
										<button class="button button-small snd-p2000-status-btn"
											data-id="<?php echo esc_attr($hid); ?>"
											data-status="<?php echo esc_attr($sv); ?>"
											data-tekst="<?php echo esc_attr($hitem['tekst']??''); ?>"
											data-stad="<?php echo esc_attr($hitem['stad']??''); ?>"
											data-straat="<?php echo esc_attr($hitem['straat']??''); ?>"
											data-type="<?php echo esc_attr($htype); ?>"
											data-lat="<?php echo esc_attr($hitem['lat']??''); ?>"
											data-lon="<?php echo esc_attr($hitem['lon']??''); ?>"
											data-source="handmatig"
											style="font-size:11px;padding:2px 8px;<?php echo $style; ?>"
											title="<?php echo $sv === 'onderweg' ? 'Onderweg' : ($sv === 'ter_plaatse' ? 'Ter plaatse' : 'Wis status'); ?>">
											<?php echo esc_html($si); ?>
										</button>
										<?php endforeach; ?>
									</div>

									<span style="font-size:11px;color:#888;"><?php echo esc_html($htime); ?></span>
								</div>

								<!-- Meta row: locatie, ETA, mail knoppen -->
								<div style="padding:8px 16px;border-bottom:1px solid #eee;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
									<?php if ( $hlocatie ) : ?>
										<span style="font-size:12px;color:#555;">📍 <?php echo esc_html($hlocatie); ?></span>
									<?php endif; ?>

									<!-- ETA -->
									<div style="display:flex;align-items:center;gap:6px;">
										<span style="font-size:11px;color:#888;">ETA:</span>
										<input type="text" class="snd-eta-input" data-id="<?php echo esc_attr($hid); ?>"
											value="<?php echo esc_attr($heta); ?>"
											placeholder="bijv. 5 min"
											style="width:90px;font-size:12px;padding:2px 6px;border:1px solid #ddd;border-radius:4px;">
									</div>

									<!-- Mail knoppen -->
									<div style="margin-left:auto;display:flex;flex-wrap:wrap;gap:4px;">
										<?php foreach ( $active_outlets_h as $ho ) : ?>
											<button class="button button-small snd-handmatig-mail-btn"
												data-id="<?php echo esc_attr($hid); ?>"
												data-email="<?php echo esc_attr($ho['email']); ?>"
												data-name="<?php echo esc_attr($ho['name']); ?>"
												data-tekst="<?php echo esc_attr($hitem['tekst']??''); ?>"
												data-stad="<?php echo esc_attr($hitem['stad']??''); ?>"
												data-straat="<?php echo esc_attr($hitem['straat']??''); ?>"
												data-status="<?php echo esc_attr($hstatus); ?>"
												style="font-size:11px;" title="Mail aan <?php echo esc_attr($ho['name']); ?>">
												✉ <?php echo esc_html($ho['name']); ?>
											</button>
										<?php endforeach; ?>
										<?php if ( ! empty($active_outlets_h) ) : ?>
											<button class="button button-small snd-handmatig-mail-all-btn"
												data-id="<?php echo esc_attr($hid); ?>"
												data-tekst="<?php echo esc_attr($hitem['tekst']??''); ?>"
												data-stad="<?php echo esc_attr($hitem['stad']??''); ?>"
												data-straat="<?php echo esc_attr($hitem['straat']??''); ?>"
												data-status="<?php echo esc_attr($hstatus); ?>"
												style="font-size:11px;font-weight:600;background:#2271b1;color:#fff;border-color:#2271b1;">
												✉ Allen
											</button>
										<?php endif; ?>
										<a href="<?php echo esc_url($new_post_url); ?>" class="button button-primary button-small" target="_blank" style="font-size:11px;">📝 Maak item</a>
										<button class="button button-small snd-delete-melding-btn" data-id="<?php echo esc_attr($hid); ?>" style="font-size:11px;color:#b32d2e;" title="Verwijder">✕</button>
									</div>
								</div>

								<!-- Logboek -->
								<div style="padding:10px 16px;">
									<div style="font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
										📋 Logboek (<?php echo count($hlog); ?> entries)
									</div>
									<div class="snd-log-entries" data-id="<?php echo esc_attr($hid); ?>" style="max-height:140px;overflow-y:auto;margin-bottom:8px;">
										<?php if ( empty($hlog) ) : ?>
											<p style="color:#aaa;font-size:12px;font-style:italic;margin:0;">Nog geen log-entries.</p>
										<?php else : ?>
											<?php foreach ( array_reverse($hlog) as $le ) : ?>
												<div style="padding:4px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:8px;align-items:baseline;">
													<span style="font-size:10px;color:#aaa;white-space:nowrap;min-width:34px;"><?php echo esc_html( wp_date('H:i', $le['time']) ); ?></span>
													<span style="font-size:10px;color:#888;white-space:nowrap;"><?php echo esc_html($le['user']); ?></span>
													<span style="font-size:12px;color:#333;"><?php echo esc_html($le['text']); ?></span>
												</div>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
									<!-- Quick log input -->
									<div style="display:flex;gap:6px;">
										<input type="text" class="snd-log-input widefat" data-id="<?php echo esc_attr($hid); ?>"
											placeholder="Log-entry toevoegen… (Enter = opslaan)"
											style="font-size:12px;flex:1;">
										<button class="button button-small snd-log-save-btn" data-id="<?php echo esc_attr($hid); ?>"
											style="font-size:11px;">+ Log</button>
									</div>
								</div>

								<!-- Portaal-chat: berichten van media outlets -->
								<div style="padding:10px 16px;border-top:1px solid #eee;background:#fafafa;">
									<div style="font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
										💬 Portaalberichten
										<span class="snd-p2000-chat-count" data-id="<?php echo esc_attr($hid); ?>" style="background:#e5e7eb;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;">…</span>
										<button class="button button-small snd-p2000-chat-load-btn" data-id="<?php echo esc_attr($hid); ?>" style="font-size:10px;margin-left:auto;">↺ Laden</button>
									</div>
									<div class="snd-p2000-admin-chat-msgs" data-id="<?php echo esc_attr($hid); ?>"
										style="max-height:160px;overflow-y:auto;margin-bottom:8px;display:none;">
									</div>
									<div style="display:flex;gap:6px;">
										<input type="text" class="snd-p2000-admin-chat-input widefat" data-id="<?php echo esc_attr($hid); ?>"
											placeholder="Reageer naar alle outlets die een vraag stelden…"
											style="font-size:12px;flex:1;">
										<button class="button button-small snd-p2000-admin-chat-send" data-id="<?php echo esc_attr($hid); ?>"
											style="font-size:11px;">Stuur</button>
									</div>
								</div>

							</div>
							<?php endforeach; ?>
						<?php endif; ?>

					</div><!-- #p2000-handmatig -->

					<div id="p2000-diagnose" class="snd-tab-content">
						<?php
						$debug_log  = get_option( 'snd_p2000_debug_log', [] );
						$feed_bw    = get_option( 'snd_p2000_feed_brandweer', '' );
						$feed_pol   = get_option( 'snd_p2000_feed_politie', '' );
						$feed_mmt   = get_option( 'snd_p2000_feed_mmt', '' );
						$cities     = get_option( 'snd_p2000_cities', '' );

						$with_coords = 0; $without_coords = 0;
						foreach ( array_merge( $feeds['brandweer'] ?? [], $feeds['politie'] ?? [], $feeds['mmt'] ?? [] ) as $m ) {
							if ( is_numeric( $m['lat'] ?? '' ) && is_numeric( $m['lon'] ?? '' ) ) $with_coords++; else $without_coords++;
						}
						$regeocode_url = wp_nonce_url( admin_url( 'admin.php?page=snd-p2000&snd_action=regeocode' ), 'snd_regeocode' );
						?>
						<div style="padding:16px;max-width:900px;">

							<h3 style="margin:0 0 12px;">📋 Instellingen check</h3>
							<table class="widefat" style="margin-bottom:20px;">
								<tr><td style="width:220px;font-weight:600;">Brandweer feed URL</td><td><?php echo $feed_bw ? '<code style="color:#16a34a;">✓ ' . esc_html( $feed_bw ) . '</code>' : '<span style="color:#b32d2e;">✗ Niet ingesteld — ga naar Instellingen → P2000</span>'; ?></td></tr>
								<tr><td style="font-weight:600;">Politie feed URL</td><td><?php echo $feed_pol ? '<code style="color:#16a34a;">✓ ' . esc_html( $feed_pol ) . '</code>' : '<span style="color:#b32d2e;">✗ Niet ingesteld</span>'; ?></td></tr>
								<tr><td style="font-weight:600;">MMT feed URL</td><td><?php echo $feed_mmt ? '<code style="color:#16a34a;">✓ ' . esc_html( $feed_mmt ) . '</code>' : '<span style="color:#b32d2e;">✗ Niet ingesteld</span>'; ?></td></tr>
								<tr><td style="font-weight:600;">Steden filter</td><td><?php echo $cities ? esc_html( $cities ) : '<em style="color:#888;">Leeg = alle meldingen worden verwerkt</em>'; ?></td></tr>
								<tr>
									<td style="font-weight:600;">Opgeslagen meldingen</td>
									<td>
										<strong><?php echo count( $all_msgs ); ?></strong> totaal &nbsp;|&nbsp;
										<span style="color:#16a34a;font-weight:600;"><?php echo $with_coords; ?> ✓ met coördinaten</span> &nbsp;|&nbsp;
										<span style="color:<?php echo $without_coords > 0 ? '#b32d2e' : '#16a34a'; ?>;font-weight:600;"><?php echo $without_coords; ?> zonder coördinaten</span>
										<?php if ( $without_coords > 0 ) : ?>
											&nbsp;<a href="<?php echo esc_url( $regeocode_url ); ?>" class="button button-small">↺ Geocodeer <?php echo $without_coords; ?> missende locaties nu</a>
										<?php endif; ?>
									</td>
								</tr>
							</table>

							<?php if ( ! $feed_bw && ! $feed_pol && ! $feed_mmt ) : ?>
								<div class="notice notice-error inline" style="margin:0 0 16px;"><p><strong>Geen feed-URLs ingesteld!</strong> Ga naar <a href="<?php echo admin_url('admin.php?page=snd-settings&tab=p2000'); ?>">Instellingen → P2000 &amp; Feeds</a> en vul de RSS-feed URLs in. Gebruik URLs van <code>p2000online.net</code>, <code>alarmeringen.nl</code> of vergelijkbaar.</p></div>
							<?php else : ?>

							<h3 style="margin:0 0 10px;">🔬 Live feed test</h3>
							<p style="margin-bottom:10px;color:#666;font-size:13px;">Haalt direct de eerste 5 items op uit elke ingestelde feed — zonder caching. Laat zien of de URL bereikbaar is en of er GPS-coördinaten in zitten.</p>
							<button id="snd-test-feed-btn" class="button button-primary">▶ Test feeds nu</button>
							<span class="spinner" id="snd-test-spinner" style="float:none;vertical-align:middle;"></span>
							<div id="snd-test-feed-result" style="margin-top:14px;"></div>

							<?php endif; ?>

							<?php if ( ! empty( $debug_log ) ) : ?>
							<h3 style="margin:20px 0 10px;">🪵 Laatste fetch-log</h3>
							<p style="font-size:12px;color:#888;margin-bottom:8px;">Dit log wordt bijgewerkt elke keer dat je op "Haal meldingen op" klikt.</p>
							<div style="background:#1e293b;color:#e2e8f0;padding:14px 16px;border-radius:6px;font-family:monospace;font-size:12px;line-height:1.7;max-height:400px;overflow-y:auto;">
								<?php foreach ( array_reverse( $debug_log ) as $line ) : ?>
									<div style="<?php
									$style = '';
									if ( false !== strpos( $line, 'FOUT' ) ) {
										$style = 'color:#fca5a5;';
									} elseif ( false !== strpos( $line, 'geocoded' ) || false !== strpos( $line, '✓' ) ) {
										$style = 'color:#86efac;';
									}
									echo $style;
								?>"><?php echo esc_html( $line ); ?></div>
								<?php endforeach; ?>
							</div>
							<?php else : ?>
							<p style="color:#888;font-size:13px;margin-top:16px;"><em>Nog geen log beschikbaar. Klik op "↺ Haal nieuwe meldingen op" om te starten.</em></p>
							<?php endif; ?>
						</div>

						<script>
						(function($){
							$('#snd-test-feed-btn').on('click', function(){
								$(this).prop('disabled', true).text('Bezig…');
								$('#snd-test-spinner').addClass('is-active');
								$('#snd-test-feed-result').html('');

								$.post(ajaxurl, {
									action: 'snd_p2000_test_feed',
									nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>
								}).done(function(res){
									if (!res.success) {
										$('#snd-test-feed-result').html('<p style="color:#b32d2e;">Fout bij testen.</p>');
										return;
									}
									var html = '';
									$.each(res.data, function(type, r){
										var icon = {brandweer:'🔥',politie:'🚔',mmt:'🚑'}[type]||'📡';
										var statusColor = r.status === 'ok' ? '#16a34a' : (r.status === 'skip' ? '#888' : '#b32d2e');
										html += '<div style="margin-bottom:16px;border:1px solid #e2e4e7;border-radius:6px;overflow:hidden;">';
										html += '<div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e4e7;display:flex;justify-content:space-between;align-items:center;">';
										html += '<strong>' + icon + ' ' + type.charAt(0).toUpperCase() + type.slice(1) + '</strong>';
										html += '<span style="font-size:12px;color:' + statusColor + ';font-weight:600;">' + r.msg + '</span>';
										html += '</div>';
										if (r.items && r.items.length) {
											html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
											html += '<thead><tr style="background:#f1f5f9;"><th style="padding:6px 10px;text-align:left;">Titel</th><th style="padding:6px 10px;text-align:left;">Datum</th><th style="padding:6px 10px;text-align:center;">GPS in feed?</th></tr></thead><tbody>';
											$.each(r.items, function(i, item){
												var hasGps = item.lat && item.lon;
												html += '<tr style="border-top:1px solid #e2e4e7;">';
												html += '<td style="padding:6px 10px;">' + $('<div>').text(item.title).html() + '</td>';
												html += '<td style="padding:6px 10px;">' + item.date + '</td>';
												html += '<td style="padding:6px 10px;text-align:center;">';
												html += hasGps
													? '<span style="color:#16a34a;font-weight:600;">✓ ' + item.lat + ', ' + item.lon + '</span>'
													: '<span style="color:#888;">✗ geen GPS → wordt gegeocodeerd</span>';
												html += '</td></tr>';
											});
											html += '</tbody></table>';
										} else if (r.status === 'ok') {
											html += '<p style="padding:10px 14px;color:#888;font-size:12px;">Geen items gevonden. Controleer of de steden-filter klopt.</p>';
										}
										html += '</div>';
									});
									$('#snd-test-feed-result').html(html);
								}).fail(function(){
									$('#snd-test-feed-result').html('<p style="color:#b32d2e;">Serverfout.</p>');
								}).always(function(){
									$('#snd-test-feed-btn').prop('disabled', false).text('▶ Test feeds nu');
									$('#snd-test-spinner').removeClass('is-active');
								});
							});
						})(jQuery);
						</script>
					</div>
				</div>

				<!-- Side mini map -->
				<div class="snd-p2000-minimap-wrap">
					<div class="snd-p2000-minimap-header">
						<strong><?php esc_html_e( 'Locatie preview', 'nieuws-distributie-systeem' ); ?></strong>
						<span id="snd-p2000-map-label" style="font-size:12px;color:#888;"></span>
					</div>
					<div id="snd-p2000-minimap" style="height:300px;border-radius:6px;background:#f0f0f1;"></div>
					<p id="snd-p2000-map-hint" style="font-size:12px;color:#888;margin:8px 0 0;text-align:center;"><?php esc_html_e( 'Klik op "📍 Bekijk op kaart" om een locatie te zien.', 'nieuws-distributie-systeem' ); ?></p>
				</div>
			</div>
		</div>

		<!-- Link to post modal -->
		<div id="snd-p2000-link-modal" class="snd-modal-backdrop" style="display:none;" role="dialog">
			<div class="snd-modal-box" style="max-width:460px;">
				<div class="snd-modal-header">
					<h2><?php esc_html_e( 'Koppel aan bestaand bericht', 'nieuws-distributie-systeem' ); ?></h2>
					<button class="snd-modal-close">&times;</button>
				</div>
				<div class="snd-modal-body">
					<p class="snd-p2000-link-tekst" style="background:#f8fafc;border:1px solid #e2e4e7;padding:10px;border-radius:6px;font-size:13px;font-style:italic;margin-bottom:16px;"></p>
					<label style="font-weight:600;display:block;margin-bottom:6px;"><?php esc_html_e( 'Selecteer bericht:', 'nieuws-distributie-systeem' ); ?></label>
					<select id="snd-p2000-link-post" class="widefat" style="margin-bottom:10px;">
						<option value=""><?php esc_html_e( '— Kies een bericht —', 'nieuws-distributie-systeem' ); ?></option>
						<?php foreach ( $pub_posts as $pp ) : ?>
							<option value="<?php echo $pp['id']; ?>"><?php echo esc_html( $pp['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p style="text-align:center;color:#888;margin:8px 0;"><?php esc_html_e( '— of —', 'nieuws-distributie-systeem' ); ?></p>
					<a id="snd-p2000-link-new" href="#" class="button widefat" style="text-align:center;" target="_blank"><?php esc_html_e( '+ Maak nieuw bericht aan', 'nieuws-distributie-systeem' ); ?></a>
				</div>
				<div class="snd-modal-footer">
					<div class="snd-modal-status"><span id="snd-p2000-link-status"></span></div>
					<div class="snd-modal-actions">
						<button id="snd-p2000-link-confirm" class="button button-primary"><?php esc_html_e( 'Koppelen', 'nieuws-distributie-systeem' ); ?></button>
						<button class="button snd-modal-close"><?php esc_html_e( 'Annuleren', 'nieuws-distributie-systeem' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<?php wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' ); ?>
		<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
		<script>
		(function($){
			// ── Tabs ────────────────────────────────────────────────────────
			$('.snd-p2000-tabs .nav-tab').on('click', function(e){
				e.preventDefault();
				$('.snd-p2000-tabs .nav-tab').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.snd-tab-content').removeClass('active').hide();
				$($(this).attr('href')).addClass('active').show();
			});
			var hash = window.location.hash || '#p2000-all';
			$('.snd-p2000-tabs a[href="'+hash+'"]').trigger('click');

			// ── Live feed test ───────────────────────────────────────────────
			$('#snd-test-feed-btn').on('click', function(){
				var btn = $(this).prop('disabled',true).text('Testen…');
				var nonce = <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>;
				$.post(ajaxurl, { action: 'snd_test_p2000_feed', nonce: nonce })
				.done(function(res){
					if (!res.success){ $('#snd-test-feed-result').html('<p style="color:#b32d2e;">Fout bij testen.</p>').show(); return; }
					var html = '';
					$.each(res.data, function(type, r){
						var icon = r.status==='ok'?'✅':r.status==='error'?'❌':'⚪';
						html += '<div style="margin-bottom:16px;"><strong>'+icon+' '+type+': </strong>'+escHtml(r.msg)+'</div>';
						if (r.items && r.items.length) {
							html += '<table class="wp-list-table widefat striped" style="margin-bottom:12px;"><thead><tr><th>Titel</th><th>Datum</th><th>GPS in feed?</th></tr></thead><tbody>';
							$.each(r.items, function(i,item){
								var gps = (item.lat && item.lon)
									? '<span style="color:#16a34a;">✓ '+item.lat+', '+item.lon+'</span>'
									: '<span style="color:#b32d2e;">✗ Geen GPS — wordt gegeocodeerd</span>';
								html += '<tr><td>'+escHtml(item.title)+'</td><td>'+escHtml(item.date)+'</td><td>'+gps+'</td></tr>';
							});
							html += '</tbody></table>';
						}
					});
					$('#snd-test-feed-result').html(html).show();
				})
				.always(function(){ btn.prop('disabled',false).text('▶ Test feeds live'); });
			});

			function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

			// ── Mini map ────────────────────────────────────────────────────
			var miniMap = null;
			var miniMarker = null;

			function initMiniMap(lat, lon, tekst) {
				$('#snd-p2000-map-hint').hide();
				$('#snd-p2000-map-label').text(tekst.substring(0, 60));
				if (!miniMap) {
					miniMap = L.map('snd-p2000-minimap').setView([lat, lon], 15);
					L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(miniMap);
					miniMarker = L.marker([lat, lon]).addTo(miniMap);
				} else {
					miniMap.setView([lat, lon], 15);
					miniMarker.setLatLng([lat, lon]);
				}
				setTimeout(() => miniMap.invalidateSize(), 50);
			}

			$(document).on('click', '.snd-p2000-mappin', function(){
				var lat = parseFloat($(this).data('lat'));
				var lon = parseFloat($(this).data('lon'));
				var tekst = $(this).data('tekst');
				if (!isNaN(lat) && !isNaN(lon)) initMiniMap(lat, lon, tekst);
			});

			// Also show on row hover (desktop)
			$(document).on('mouseenter', '.snd-p2000-row', function(){
				var lat = parseFloat($(this).data('lat'));
				var lon = parseFloat($(this).data('lon'));
				var tekst = $(this).data('tekst') || '';
				if (!isNaN(lat) && lat !== 0) initMiniMap(lat, lon, tekst);
			});

			// ── Link modal ──────────────────────────────────────────────────
			var activeP2000Id = '', activeNewUrl = '';

			$(document).on('click', '.snd-p2000-link-btn', function(){
				activeP2000Id = $(this).data('p2000id');
				activeNewUrl  = $(this).data('newurl');
				$('.snd-p2000-link-tekst').text($(this).data('tekst'));
				$('#snd-p2000-link-new').attr('href', activeNewUrl);
				$('#snd-p2000-link-post').val('');
				$('#snd-p2000-link-status').text('');
				$('#snd-p2000-link-modal').fadeIn(200);
			});

			$('#snd-p2000-link-confirm').on('click', function(){
				var postId = parseInt($('#snd-p2000-link-post').val(), 10);
				if (!postId) { $('#snd-p2000-link-status').text('Selecteer eerst een bericht.').css('color','#b32d2e'); return; }
				$(this).prop('disabled', true).text('…');
				$.post(ajaxurl, {
					action: 'snd_link_p2000_to_post',
					nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>,
					post_id: postId,
					p2000_id: activeP2000Id
				}).done(function(res){
					if (res.success) {
						$('#snd-p2000-link-status').text('✓ ' + res.data.message).css('color','#16a34a');
						setTimeout(function(){ $('#snd-p2000-link-modal').fadeOut(200); location.reload(); }, 1500);
					} else {
						$('#snd-p2000-link-status').text(res.data.message || 'Fout.').css('color','#b32d2e');
						$('#snd-p2000-link-confirm').prop('disabled', false).text('Koppelen');
					}
				});
			});

			$(document).on('click', '.snd-modal-close', function(){ $(this).closest('.snd-modal-backdrop').fadeOut(200); });
			$(document).on('click', '.snd-modal-backdrop', function(e){ if($(e.target).is('.snd-modal-backdrop')) $(this).fadeOut(200); });

			// ── P2000 status buttons ─────────────────────────────────────────
			var p2000StatusNonce = <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>;
			var pendingStatus    = {};

			function getStatusModal() { return $('#snd-p2000-outlet-modal'); }

			// Open outlet picker when clicking onderweg/ter_plaatse
			$(document).on('click', '.snd-p2000-status-btn', function(){
				var $btn   = $(this);
				var status = $btn.data('status');
				var id     = $btn.data('id');
				var $wrap  = $btn.closest('.snd-p2000-status-wrap');

				// 'geen' = no email needed, just clear/remove status
				if (status === 'geen') {
					$wrap.find('.snd-p2000-status-btn').prop('disabled', true);
					$.post(ajaxurl, {
						action:    'snd_set_p2000_status',
						nonce:     p2000StatusNonce,
						p2000_id:  id,
						status:    'geen',
						source:    $btn.data('source') || 'feed',
					}).done(function(res){
						if (res.success) {
							$btn.closest('tr').css('background','');
							$wrap.find('.snd-p2000-status-btn').css({'background':'','color':'','border-color':''});
						} else {
							alert(res.data && res.data.message ? res.data.message : 'Fout.');
						}
					}).always(function(){ $wrap.find('.snd-p2000-status-btn').prop('disabled', false); });
					return;
				}

				// For onderweg/ter_plaatse: open outlet picker
				pendingStatus = {
					id:     id,
					status: status,
					tekst:  $btn.data('tekst')  || '',
					stad:   $btn.data('stad')   || '',
					straat: $btn.data('straat') || '',
					type:   $btn.data('type')   || 'overig',
					lat:    $btn.data('lat')    || '',
					lon:    $btn.data('lon')    || '',
					source: $btn.data('source') || 'feed',
					$wrap:  $wrap,
					$btn:   $btn,
				};

				var icon  = status === 'onderweg' ? '🚨' : '📍';
				var label = status === 'onderweg' ? 'Fotograaf onderweg' : 'Fotograaf ter plaatse';
				var loc   = (pendingStatus.straat ? pendingStatus.straat + ', ' : '') + pendingStatus.stad;

				$('#snd-outlet-modal-title').text(icon + ' ' + label + (loc.trim() ? ': ' + loc : ''));
				$('#snd-outlet-modal-list input[type="checkbox"]').prop('checked', true);
				$('#snd-outlet-modal-send-email').prop('checked', true);
				$('#snd-outlet-modal-outlet-wrap').show();
				getStatusModal().css('display','flex');
			});

			// Toggle outlet list (delegated — modal rendered after script)
			$(document).on('change', '#snd-outlet-modal-send-email', function(){
				$('#snd-outlet-modal-outlet-wrap').toggle(this.checked);
			});

			// Select all / none (delegated)
			$(document).on('click', '#snd-outlet-modal-all', function(){
				$('#snd-outlet-modal-list input').prop('checked', true);
				updateConfirmCount();
			});
			$(document).on('click', '#snd-outlet-modal-none', function(){
				$('#snd-outlet-modal-list input').prop('checked', false);
				updateConfirmCount();
			});
			$(document).on('change', '.snd-outlet-modal-cb', updateConfirmCount);
			function updateConfirmCount(){
				var n = $('#snd-outlet-modal-list input:checked').length;
				$('#snd-outlet-modal-confirm').text('Status instellen (' + n + ')');
			}

			// Confirm: set status + optionally email (delegated)
			$(document).on('click', '#snd-outlet-modal-confirm', function(){
				var $btn2 = $(this).prop('disabled',true).text('…');
				var sendEmail = $('#snd-outlet-modal-send-email').is(':checked');
				var emails = [];
				if (sendEmail) {
					$('#snd-outlet-modal-list input:checked').each(function(){
						emails.push($(this).val());
					});
				}
				$.post(ajaxurl, {
					action:        'snd_set_p2000_status',
					nonce:         p2000StatusNonce,
					p2000_id:      pendingStatus.id,
					status:        pendingStatus.status,
					tekst:         pendingStatus.tekst,
					stad:          pendingStatus.stad,
					straat:        pendingStatus.straat,
					type:          pendingStatus.type   || 'overig',
					lat:           pendingStatus.lat    || '',
					lon:           pendingStatus.lon    || '',
					source:        pendingStatus.source || 'feed',
					send_email:    sendEmail ? '1' : '0',
					outlet_emails: emails.join(','),
				}).done(function(res){
					getStatusModal().css('display','none');
					if (res.success) {
						var $row  = pendingStatus.$wrap.closest('tr');
						var $wrap = pendingStatus.$wrap;
						$row.css('background','');
						$wrap.find('.snd-p2000-status-btn').css({'background':'','color':'','border-color':''});
						if (pendingStatus.status === 'onderweg') {
							$row.css('background','rgba(245,158,11,.07)');
							pendingStatus.$btn.css({'background':'#f59e0b','color':'#fff','border-color':'#f59e0b'});
						} else if (pendingStatus.status === 'ter_plaatse') {
							$row.css('background','rgba(59,130,246,.07)');
							pendingStatus.$btn.css({'background':'#3b82f6','color':'#fff','border-color':'#3b82f6'});
						}
						var msg = res.data && res.data.message ? res.data.message : '✓';
						var $m = $('<span style="font-size:11px;color:#16a34a;display:block;margin-top:3px;">'+msg+'</span>');
						$wrap.append($m);
						setTimeout(function(){ $m.fadeOut(function(){ $m.remove(); }); }, 3000);
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Fout bij opslaan.');
					}
				}).always(function(){ $btn2.prop('disabled',false).text('Status instellen'); });
			});

			// Close modal
			// Close modal (delegated)
			$(document).on('click', '#snd-outlet-modal-cancel, #snd-outlet-modal-close', function(){
				getStatusModal().css('display','none');
			});
			$(document).on('click', '#snd-p2000-outlet-modal', function(e){
				if (e.target.id === 'snd-p2000-outlet-modal') getStatusModal().css('display','none');
			});

			// ── Delete handmatige melding ────────────────────────────────────────
			$(document).on('click', '.snd-delete-melding-btn', function(){
				var id = $(this).data('id');
				if (!confirm('Wil je deze melding verwijderen?')) return;
				var $btn = $(this).prop('disabled',true);
				$.post(ajaxurl, {
					action:   'snd_delete_active_melding',
					nonce:    p2000StatusNonce,
					p2000_id: id,
				}).done(function(r){
					if (r.success) {
						$btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
					}
				}).fail(function(){ $btn.prop('disabled',false); });
			});

			// ── Mail individual outlet from eigen meldingen tab ──────────────
			$(document).on('click', '.snd-handmatig-mail-btn', function(){
				var $btn   = $(this).prop('disabled',true);
				var id     = $btn.data('id');
				var email  = $btn.data('email');
				var name   = $btn.data('name');
				var tekst  = $btn.data('tekst') || '';
				var stad   = $btn.data('stad')  || '';
				var straat = $btn.data('straat') || '';
				var status = $btn.data('status') || 'onderweg';

				// Use status for email type; default to onderweg if empty
				var emailStatus = (status === 'ter_plaatse') ? 'ter_plaatse' : 'onderweg';

				$.post(ajaxurl, {
					action:        'snd_set_p2000_status',
					nonce:         p2000StatusNonce,
					p2000_id:      id,
					status:        emailStatus,
					tekst:         tekst,
					stad:          stad,
					straat:        straat,
					send_email:    '1',
					outlet_emails: email,
				}).done(function(res){
					if (res.success) {
						$btn.css({'background':'#16a34a','color':'#fff','border-color':'#16a34a'}).text('✓ Verstuurd');
						setTimeout(function(){
							$btn.css({'background':'','color':'','border-color':''}).text('✉ ' + name).prop('disabled', false);
						}, 3000);
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Fout bij versturen.');
						$btn.prop('disabled', false);
					}
				}).fail(function(){
					alert('Serverfout.');
					$btn.prop('disabled', false);
				});
			});

			// ── Mail ALL outlets from eigen meldingen tab ────────────────────
			$(document).on('click', '.snd-handmatig-mail-all-btn', function(){
				var $btn   = $(this).prop('disabled',true).text('…');
				var id     = $btn.data('id');
				var tekst  = $btn.data('tekst') || '';
				var stad   = $btn.data('stad')  || '';
				var straat = $btn.data('straat') || '';
				var status = $btn.data('status') || 'onderweg';
				var emailStatus = (status === 'ter_plaatse') ? 'ter_plaatse' : 'onderweg';

				$.post(ajaxurl, {
					action:        'snd_set_p2000_status',
					nonce:         p2000StatusNonce,
					p2000_id:      id,
					status:        emailStatus,
					tekst:         tekst,
					stad:          stad,
					straat:        straat,
					send_email:    '1',
					outlet_emails: '', // empty = all outlets
				}).done(function(res){
					if (res.success) {
						var msg = res.data && res.data.message ? res.data.message : '✓ Verstuurd';
						$btn.css({'background':'#16a34a','color':'#fff','border-color':'#16a34a'}).text('✓ ' + msg);
						setTimeout(function(){
							$btn.css({'background':'#2271b1','color':'#fff','border-color':'#2271b1'}).text('✉ Allen').prop('disabled', false);
						}, 3000);
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Fout bij versturen.');
						$btn.prop('disabled', false).text('✉ Allen');
					}
				}).fail(function(){
					alert('Serverfout.');
					$btn.prop('disabled', false).text('✉ Allen');
				});
			});

			// ── Prioriteit buttons ───────────────────────────────────────────
			$(document).on('click', '.snd-priority-btn', function(){
				var $btn     = $(this);
				var id       = $btn.data('id');
				var priority = $btn.data('priority');
				var colors   = {urgent:'#ef4444', normaal:'#f59e0b', laag:'#10b981'};
				var color    = colors[priority] || '#888';
				$.post(ajaxurl, {
					action:    'snd_set_melding_priority',
					nonce:     p2000StatusNonce,
					p2000_id:  id,
					priority:  priority,
				}).done(function(res){
					if (res.success) {
						// Reset all priority buttons in this card
						$btn.closest('.snd-melding-card').find('.snd-priority-btn')
							.css({'background':'','color':'','border-color':''});
						$btn.css({'background':color,'color':'#fff','border-color':color});
					}
				});
			});

			// ── ETA opslaan bij verlaten veld of Enter ───────────────────────
			$(document).on('blur change', '.snd-eta-input', function(){
				var $input = $(this);
				var id  = $input.data('id');
				var eta = $input.val().trim();
				$.post(ajaxurl, {
					action:   'snd_set_melding_eta',
					nonce:    p2000StatusNonce,
					p2000_id: id,
					eta:      eta,
				}).done(function(res){
					if (res.success) {
						$input.css('border-color','#16a34a');
						setTimeout(function(){ $input.css('border-color',''); }, 1500);
					}
				});
			});
			$(document).on('keydown', '.snd-eta-input', function(e){
				if (e.which === 13) { e.preventDefault(); $(this).trigger('blur'); }
			});

			// ── Log entry opslaan ────────────────────────────────────────────
			function saveLogEntry(id) {
				var $input = $('.snd-log-input[data-id="'+id+'"]');
				var text   = $input.val().trim();
				if (!text) return;
				var $btn = $('.snd-log-save-btn[data-id="'+id+'"]').prop('disabled',true);
				$.post(ajaxurl, {
					action:   'snd_add_log_entry',
					nonce:    p2000StatusNonce,
					p2000_id: id,
					text:     text,
				}).done(function(res){
					if (res.success) {
						$input.val('');
						var e = res.data.entry;
						var t = res.data.time_str || '—';
						var $entries = $('.snd-log-entries[data-id="'+id+'"]');
						// Remove empty state
						$entries.find('p').remove();
						// Prepend new entry
						$entries.prepend(
							'<div style="padding:4px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:8px;align-items:baseline;">' +
							'<span style="font-size:10px;color:#aaa;white-space:nowrap;min-width:34px;">' + t + '</span>' +
							'<span style="font-size:10px;color:#888;white-space:nowrap;">' + $('<span>').text(e.user).html() + '</span>' +
							'<span style="font-size:12px;color:#333;">' + $('<span>').text(e.text).html() + '</span>' +
							'</div>'
						);
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Fout bij opslaan log.');
					}
				}).always(function(){ $btn.prop('disabled',false); });
			}
			$(document).on('click', '.snd-log-save-btn', function(){
				saveLogEntry($(this).data('id'));
			});
			$(document).on('keydown', '.snd-log-input', function(e){
				if (e.which === 13) { e.preventDefault(); saveLogEntry($(this).data('id')); }
			});

			// ── P2000 portaalberichten (admin leest + beantwoordt) ───────────
			function loadP2000AdminChat(id) {
				var $msgs  = $('.snd-p2000-admin-chat-msgs[data-id="'+id+'"]');
				var $count = $('.snd-p2000-chat-count[data-id="'+id+'"]');
				$.post(ajaxurl, {
					action:   'snd_p2000_admin_chat_get',
					nonce:    p2000StatusNonce,
					p2000_id: id,
				}).done(function(res){
					if (!res.success) return;
					var msgs = res.data.messages || [];
					$count.text(msgs.length);
					if (msgs.length === 0) {
						$msgs.html('<p style="color:#aaa;font-size:12px;font-style:italic;margin:0;">Nog geen berichten van outlets.</p>').show();
						return;
					}
					var html = '';
					msgs.forEach(function(m){
						var isAdmin = m.sender === 'admin';
						var bg  = isAdmin ? '#e0f2fe' : '#f3f4f6';
						var name = isAdmin ? '🔵 ' + escH(m.name||'Redactie') : '💬 ' + escH(m.name||'Outlet');
						html += '<div style="margin-bottom:6px;padding:6px 10px;border-radius:6px;background:'+bg+';">'
							+ '<div style="font-size:10px;color:#888;margin-bottom:2px;">'
							+ name + ' <span style="color:#bbb;">'+escH(m.time||'')+'</span></div>'
							+ '<div style="font-size:13px;color:#222;">'+escH(m.body)+'</div>'
							+ '</div>';
					});
					$msgs.html(html).show();
					$msgs[0] && ($msgs[0].scrollTop = $msgs[0].scrollHeight);
				});
			}

			function escH(s) {
				return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}

			// Load chat when "↺ Laden" is clicked
			$(document).on('click', '.snd-p2000-chat-load-btn', function(){
				loadP2000AdminChat($(this).data('id'));
			});

			// Auto-load counts when handmatig tab is opened
			$(document).on('click', 'a[href="#p2000-handmatig"]', function(){
				setTimeout(function(){
					$('.snd-p2000-chat-count').each(function(){
						var id = $(this).data('id');
						$.post(ajaxurl, {
							action: 'snd_p2000_admin_chat_get',
							nonce: p2000StatusNonce, p2000_id: id,
						}).done(function(res){
							if (res.success) {
								$('.snd-p2000-chat-count[data-id="'+id+'"]').text((res.data.messages||[]).length);
							}
						});
					});
				}, 100);
			});

			// Send admin reply
			function sendAdminChatMsg(id) {
				var $input = $('.snd-p2000-admin-chat-input[data-id="'+id+'"]');
				var body   = $input.val().trim();
				if (!body) return;
				var $btn = $('.snd-p2000-admin-chat-send[data-id="'+id+'"]').prop('disabled',true);
				$.post(ajaxurl, {
					action:   'snd_p2000_admin_chat_send',
					nonce:    p2000StatusNonce,
					p2000_id: id,
					body:     body,
				}).done(function(res){
					if (res.success) {
						$input.val('');
						loadP2000AdminChat(id);
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Fout bij versturen.');
					}
				}).always(function(){ $btn.prop('disabled', false); });
			}
			$(document).on('click', '.snd-p2000-admin-chat-send', function(){
				sendAdminChatMsg($(this).data('id'));
			});
			$(document).on('keydown', '.snd-p2000-admin-chat-input', function(e){
				if (e.which === 13) { e.preventDefault(); sendAdminChatMsg($(this).data('id')); }
			});

			// ── P2000 incident groepering: uitklappen ────────────────────────
			$(document).on('click', '.snd-p2000-group-toggle', function(){
				var $btn  = $(this);
				var group = $btn.data('group');
				var $rows = $('[data-group="' + group + '"]');
				var open  = $rows.first().is(':visible');
				$rows.toggle(!open);
				var n = $rows.length;
				$btn.html(open ? '&#x25B6; +' + n + ' opsch.' : '&#x25BC; ' + n + ' opsch.');
			});

			// ── Handmatig melding toevoegen aan incident ──────────────────────
			$(document).on('click', '.snd-p2000-show-add-row', function(){
				var group   = $(this).data('group');
				var $addRow = $('tr.snd-p2000-add-row[data-group="' + group + '"]');
				$('tr.snd-p2000-related-row[data-group="' + group + '"]').show();
				$addRow.show().find('.snd-manual-tekst').focus();
			});

			$(document).on('click', '.snd-p2000-add-cancel-btn', function(){
				var $row = $(this).closest('tr');
				$row.find('.snd-manual-tekst').val('');
				$row.hide();
			});

			function doAddManual($btn) {
				var $row    = $btn.closest('tr');
				var primary = $btn.data('primary');
				var group   = $row.data('group');
				var tekst   = $row.find('.snd-manual-tekst').val().trim();
				var type    = $row.find('.snd-manual-type').val();
				if (!tekst) { $row.find('.snd-manual-tekst').focus().css('border-color','#b32d2e'); return; }
				$row.find('.snd-manual-tekst').css('border-color','');
				$btn.prop('disabled',true).text('...');
				$.post(ajaxurl, {
					action: 'snd_p2000_add_manual', nonce: p2000StatusNonce,
					primary: primary, tekst: tekst, type: type,
				}).done(function(res){
					if (res.success) {
						var e = res.data.entry;
						var colors = {brandweer:'#ef4444',politie:'#f59e0b',mmt:'#8b5cf6',overig:'#6b7280'};
						var col = colors[e.type] || '#6b7280';
						var tijdStr = new Date(e.tijd*1000).toLocaleTimeString('nl-NL',{hour:'2-digit',minute:'2-digit',timeZone:'Europe/Amsterdam'});
						var newRow = '<tr class="snd-p2000-related-row snd-p2000-manual-row" data-group="' + group + '" '
							+ 'style="background:#fff8f0;border-left:3px solid #f59e0b;">'
							+ '<td style="padding-left:24px;"><span class="snd-p2000-badge" style="background:' + col + ';opacity:.8;">'
							+ e.type.charAt(0).toUpperCase()+e.type.slice(1) + '</span>'
							+ '<br><small style="color:#f59e0b;font-size:9px;">&#x270F; handmatig</small></td>'
							+ '<td style="font-size:11px;color:#888;">' + tijdStr + '</td>'
							+ '<td style="font-size:12px;color:#555;padding-left:8px;">' + $('<span>').text(e.tekst).html() + '</td>'
							+ '<td></td>'
							+ '<td><button class="button button-small snd-p2000-remove-manual-btn" '
							+ 'data-primary="' + primary + '" data-idx="' + res.data.idx + '" '
							+ 'style="font-size:10px;color:#b32d2e;">&#x2715; Verwijder</button></td></tr>';
						$row.before(newRow);
						$row.find('.snd-manual-tekst').val('').hide();
						$row.hide();
					} else { alert(res.data && res.data.message ? res.data.message : 'Fout.'); }
				}).always(function(){ $btn.prop('disabled',false).text('Toevoegen'); });
			}
			$(document).on('click', '.snd-p2000-add-manual-btn', function(){ doAddManual($(this)); });
			$(document).on('keydown', '.snd-manual-tekst', function(e){
				if (e.which===13){ e.preventDefault(); $(this).closest('tr').find('.snd-p2000-add-manual-btn').trigger('click'); }
			});
			$(document).on('click', '.snd-p2000-remove-manual-btn', function(){
				var $btn = $(this).prop('disabled',true);
				if (!confirm('Handmatige melding verwijderen?')) { $btn.prop('disabled',false); return; }
				$.post(ajaxurl, {
					action: 'snd_p2000_remove_manual', nonce: p2000StatusNonce,
					primary: $btn.data('primary'), idx: $btn.data('idx'),
				}).done(function(res){
					if (res.success) $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
					else $btn.prop('disabled',false);
				}).fail(function(){ $btn.prop('disabled',false); });
			});

			// ── P2000 loskoppelen ────────────────────────────────────────────
			$(document).on('click', '.snd-p2000-ungroup-btn', function(){
				var $btn = $(this).prop('disabled', true).text('…');
				var pair = $btn.data('pair');
				$.post(ajaxurl, {
					action: 'snd_p2000_ungroup',
					nonce:  p2000StatusNonce,
					pair:   pair,
				}).done(function(res){
					if (res.success) {
						$btn.text('🔗 Hergroeperen')
							.removeClass('snd-p2000-ungroup-btn')
							.addClass('snd-p2000-regroup-btn')
							.prop('disabled', false)
							.css('color', '#16a34a');
						$btn.closest('tr').css({'background':'#fffff0','border-left':'2px solid #16a34a'});
					} else {
						$btn.text('⛓ Loskoppelen').prop('disabled', false);
					}
				}).fail(function(){ $btn.text('⛓ Loskoppelen').prop('disabled', false); });
			});

			// ── Hergroeperen ─────────────────────────────────────────────────
			$(document).on('click', '.snd-p2000-regroup-btn', function(){
				var $btn = $(this).prop('disabled', true).text('…');
				var pair = $btn.data('pair');
				$.post(ajaxurl, {
					action: 'snd_p2000_regroup',
					nonce:  p2000StatusNonce,
					pair:   pair,
				}).done(function(res){
					if (res.success) { window.location.reload(); }
					else { $btn.text('🔗 Hergroeperen').prop('disabled', false); }
				}).fail(function(){ $btn.text('🔗 Hergroeperen').prop('disabled', false); });
			});

			// ── P2000 meldingtekst kopiëren ──────────────────────────────────────────
			$(document).on('click', '.snd-p2000-copy-btn', function(e) {
				e.stopPropagation();
				var tekst = $(this).data('tekst');
				var $btn  = $(this);
				if (navigator.clipboard) {
					navigator.clipboard.writeText(tekst).then(function(){
						$btn.text('✓').css('color','#16a34a');
						setTimeout(function(){ $btn.text('📋').css('color',''); }, 1800);
					});
				}
			});

			// ── P2000 feed zoekfunctie ───────────────────────────────────────────
			(function(){
				var $input = $('#snd-p2000-search');
				var $clear = $('#snd-p2000-search-clear');
				var $count = $('#snd-p2000-search-count');

				$input.on('input', function(){
					var q = $(this).val().toLowerCase().trim();
					if (!q) {
						$('.snd-p2000-table tbody tr').show();
						$clear.hide(); $count.text('');
						return;
					}
					$clear.show();
					var visible = 0, total = 0;
					$('.snd-p2000-table tbody tr.snd-p2000-row, .snd-p2000-table tbody tr.snd-p2000-primary-row').each(function(){
						total++;
						var tekst = ($(this).data('tekst') || '').toLowerCase();
						var lat   = ($(this).attr('data-lat') || '');
						// Also search in TD text
						var tds   = $(this).find('td').text().toLowerCase();
						if (tekst.includes(q) || tds.includes(q)) {
							$(this).show();
							// Also show related rows
							var group = $(this).find('.snd-p2000-group-toggle').data('group') || $(this).find('.snd-p2000-show-add-row').data('group');
							if (group) $('[data-group="'+group+'"]').show();
							visible++;
						} else {
							$(this).hide();
						}
					});
					$count.text(visible + ' van ' + total + ' meldingen');
				});

				$clear.on('click', function(){
					$input.val('').trigger('input');
				});
			})();

			$('#snd-p2000-autorefresh').on('change', function(){
				if ($(this).is(':checked')) {
					$('#snd-p2000-refresh-countdown').show();
					startCountdown();
				} else {
					clearInterval(refreshTimer);
					$('#snd-p2000-refresh-countdown').hide();
				}
			});

			function startCountdown() {
				countdown = 60;
				$('#snd-p2000-refresh-countdown').text('Ververs over ' + countdown + 's');
				clearInterval(refreshTimer);
				refreshTimer = setInterval(function(){
					countdown--;
					$('#snd-p2000-refresh-countdown').text('Ververs over ' + countdown + 's');
					if (countdown <= 0) {
						window.location.href = <?php echo wp_json_encode( esc_url( $fetch_url ) ); ?>;
					}
				}, 1000);
			}
		})(jQuery);
		</script>

		<!-- Outlet picker modal for P2000 status emails -->
		<div id="snd-p2000-outlet-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:10px;width:90%;max-width:480px;box-shadow:0 16px 48px rgba(0,0,0,.3);overflow:hidden;">
				<div style="padding:16px 20px;border-bottom:1px solid #e2e4e7;display:flex;justify-content:space-between;align-items:center;">
					<strong id="snd-outlet-modal-title" style="font-size:14px;"></strong>
					<button id="snd-outlet-modal-close" style="background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1;padding:0 4px;">&times;</button>
				</div>
				<div style="padding:16px 20px;">
					<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:14px;font-weight:600;">
						<input type="checkbox" id="snd-outlet-modal-send-email" checked style="margin:0;">
						E-mailnotificatie sturen aan outlets
					</label>
					<div id="snd-outlet-modal-outlet-wrap" style="border:1px solid #e2e4e7;border-radius:6px;overflow:hidden;">
						<div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e4e7;display:flex;gap:8px;align-items:center;">
							<span style="font-size:12px;color:#666;flex:1;">Selecteer ontvangers:</span>
							<button type="button" id="snd-outlet-modal-all" class="button button-small">✅ Alles</button>
							<button type="button" id="snd-outlet-modal-none" class="button button-small">☐ Niets</button>
						</div>
						<div id="snd-outlet-modal-list" style="max-height:240px;overflow-y:auto;padding:8px 12px;">
							<?php
							$outlets_for_modal = get_option( self::OPTION_OUTLETS, [] );
							foreach ( $outlets_for_modal as $mo ) :
								if ( empty( $mo['email'] ) ) continue;
							?>
							<label style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;cursor:pointer;border-bottom:1px solid #f0f0f0;">
								<input type="checkbox" class="snd-outlet-modal-cb" value="<?php echo esc_attr( $mo['email'] ); ?>" checked style="margin:0;">
								<span style="flex:1;"><?php echo esc_html( $mo['name'] ); ?></span>
								<span style="color:#aaa;font-size:11px;"><?php echo esc_html( $mo['email'] ); ?></span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div style="padding:12px 20px;border-top:1px solid #e2e4e7;display:flex;justify-content:flex-end;gap:8px;">
					<button id="snd-outlet-modal-confirm" class="button button-primary">Status instellen</button>
					<button id="snd-outlet-modal-cancel" class="button">Annuleren</button>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_get_live_p2000(): void {
		check_ajax_referer( 'snd_public_nonce', 'nonce' );
		$feeds = get_option( 'snd_p2000_feeds_structured', [] );
		$all   = array_merge( $feeds['brandweer'] ?? [], $feeds['politie'] ?? [], $feeds['mmt'] ?? [] );
		usort( $all, fn( $a, $b ) => ( $b['tijd'] ?? 0 ) <=> ( $a['tijd'] ?? 0 ) );

		$linked_query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_P2000_ID, 'compare' => 'EXISTS' ] ],
		] );
		$linked = [];
		foreach ( $linked_query->posts as $pid ) {
			$p2id = get_post_meta( $pid, self::META_P2000_ID, true );
			if ( $p2id ) $linked[ $p2id ] = get_permalink( $pid );
		}

		$out = [];
		foreach ( array_slice( $all, 0, 40 ) as $msg ) {
			$out[] = [
				'id'     => $msg['id'],
				'type'   => $msg['type'] ?? 'overig',
				'tekst'  => $msg['tekst'] ?? '',
				'stad'   => $msg['stad'] ?? '',
				'straat' => $msg['straat'] ?? '',
				'tijd'   => isset( $msg['tijd'] ) ? wp_date( 'H:i', $msg['tijd'] ) : '',
				'ts'     => $msg['tijd'] ?? 0,
				'lat'    => $msg['lat'] ?? null,
				'lon'    => $msg['lon'] ?? null,
				'url'    => $linked[ $msg['id'] ] ?? null,
			];
		}
		wp_send_json_success( $out );
	}

	// ── AJAX: Create incident from dashboard ──────────────────────────────────

	public function ajax_create_incident(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Geen permissie.' ], 403 );
		}

		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( empty( $title ) ) {
			wp_send_json_error( [ 'message' => 'Titel is verplicht.' ] );
		}

		// Create the post
		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ),
			'post_status'  => 'publish',
			'post_type'    => 'post',
		] );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		// Save meta fields
		$inc_status = sanitize_text_field( wp_unslash( $_POST['inc_status'] ?? '' ) );
		$allowed    = [ '', 'onderweg', 'ter_plaatse', 'afgerond' ];
		if ( in_array( $inc_status, $allowed, true ) ) {
			update_post_meta( $post_id, self::META_INCIDENT_STATUS, $inc_status );
			if ( $inc_status !== '' ) {
				update_post_meta( $post_id, self::META_STATUS_TIMES, [ $inc_status => time() ] );
			}
		}

		$byline = sanitize_text_field( wp_unslash( $_POST['byline'] ?? '' ) );
		if ( $byline ) update_post_meta( $post_id, self::META_BYLINE, $byline );

		$lat    = sanitize_text_field( wp_unslash( $_POST['lat'] ?? '' ) );
		$lon    = sanitize_text_field( wp_unslash( $_POST['lon'] ?? '' ) );
		$street = sanitize_text_field( wp_unslash( $_POST['street'] ?? '' ) );
		if ( is_numeric( $lat ) ) update_post_meta( $post_id, self::META_LAT, $lat );
		if ( is_numeric( $lon ) ) update_post_meta( $post_id, self::META_LON, $lon );
		if ( $street )            update_post_meta( $post_id, self::META_STREET1, $street );

		$is_live = sanitize_text_field( wp_unslash( $_POST['is_live'] ?? '0' ) );
		update_post_meta( $post_id, self::META_IS_LIVE, $is_live === '1' ? '1' : '0' );

		// Optional email dispatch
		$send_emails = sanitize_text_field( wp_unslash( $_POST['send_emails'] ?? '' ) );
		$sent_count  = 0;
		if ( ! empty( $send_emails ) ) {
			$email_list = array_filter( array_map( 'sanitize_email', explode( ',', $send_emails ) ) );
			$outlets    = get_option( self::OPTION_OUTLETS, [] );
			$dispatch_log = [];

			foreach ( $outlets as $outlet ) {
				if ( ! in_array( $outlet['email'], $email_list, true ) ) continue;
				if ( empty( $outlet['access_code'] ) ) continue;
				if ( $this->send_email( $post_id, $outlet, [] ) ) {
					$dispatch_log[ $outlet['email'] ] = [
						'name'      => $outlet['name'],
						'email'     => $outlet['email'],
						'timestamp' => time(),
					];
					$sent_count++;
				}
			}

			if ( ! empty( $dispatch_log ) ) {
				update_post_meta( $post_id, self::META_DISPATCH_LOG, $dispatch_log );
			}
		}

		wp_send_json_success( [
			'post_id'    => $post_id,
			'edit_url'   => get_edit_post_link( $post_id ),
			'sent_count' => $sent_count,
			'message'    => sprintf(
				'Incident aangemaakt%s.',
				$sent_count > 0 ? " en e-mail verstuurd naar {$sent_count} outlet(s)" : ''
			),
		] );
	}

	// ── AJAX: Shortcode map markers ───────────────────────────────────────────

	// ── AJAX: Create manual active melding (NOT a WP post) ───────────────────

	public function ajax_create_active_melding(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Geen permissie.' ], 403 );
		}

		$tekst  = sanitize_text_field( wp_unslash( $_POST['tekst']  ?? '' ) );
		$type   = sanitize_text_field( wp_unslash( $_POST['type']   ?? 'overig' ) );
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
		$lat    = sanitize_text_field( wp_unslash( $_POST['lat']    ?? '' ) );
		$lon    = sanitize_text_field( wp_unslash( $_POST['lon']    ?? '' ) );
		$straat = sanitize_text_field( wp_unslash( $_POST['straat'] ?? '' ) );
		$stad   = sanitize_text_field( wp_unslash( $_POST['stad']   ?? '' ) );
		$notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		$send_email = ( $_POST['send_email'] ?? '0' ) === '1';

		if ( empty( $tekst ) ) {
			wp_send_json_error( [ 'message' => 'Omschrijving is verplicht.' ] );
		}

		$allowed_status = [ '', 'onderweg', 'ter_plaatse' ];
		if ( ! in_array( $status, $allowed_status, true ) ) $status = '';

		$allowed_types = [ 'brandweer', 'politie', 'mmt', 'overig' ];
		if ( ! in_array( $type, $allowed_types, true ) ) $type = 'overig';

		// Generate unique ID for this manual melding
		$msg_id = 'handmatig_' . time() . '_' . wp_rand( 1000, 9999 );
		$priority = sanitize_text_field( wp_unslash( $_POST['priority'] ?? 'normaal' ) );
		if ( ! in_array( $priority, [ 'urgent', 'normaal', 'laag' ], true ) ) $priority = 'normaal';

		// First log entry = the description itself
		$first_log = [];
		if ( $notes !== '' ) {
			$first_log[] = [
				'time' => time(),
				'user' => wp_get_current_user()->display_name ?: 'Admin',
				'text' => $notes,
			];
		}

		$active = get_option( 'snd_p2000_active_statuses', [] );
		$active[ $msg_id ] = [
			'status'   => $status,
			'tekst'    => $tekst,
			'type'     => $type,
			'stad'     => $stad,
			'straat'   => $straat,
			'lat'      => is_numeric( $lat ) ? $lat : null,
			'lon'      => is_numeric( $lon ) ? $lon : null,
			'notes'    => $notes,
			'priority' => $priority,
			'eta'      => sanitize_text_field( wp_unslash( $_POST['eta'] ?? '' ) ),
			'log'      => $first_log,
			'time'     => time(),
			'source'   => 'handmatig',
		];
		update_option( 'snd_p2000_active_statuses', $active );

		// Optional email
		$sent = 0;
		if ( $send_email && $status !== '' ) {
			$sent = $this->send_p2000_status_email( $status, $tekst, $stad, $straat );
		}

		// Telegram
		if ( true ) {
			$locatie  = trim( ( $straat ? $straat . ', ' : '' ) . $stad );
			$icon     = $status === 'onderweg' ? '🚨' : ( $status === 'ter_plaatse' ? '📍' : '📋' );
			$status_l = $status === 'onderweg' ? 'Onderweg' : ( $status === 'ter_plaatse' ? 'Ter plaatse' : 'Nieuw incident' );
			$tg_msg   = "{$icon} *Handmatige melding: {$status_l}*\n_{$tekst}_"
				. ( $locatie ? "\n📍 {$locatie}" : '' )
				. ( $notes   ? "\n\n📝 {$notes}" : '' )
				. "\n\n💡 Reageer via /status voor overzicht";
			$this->telegram_send_all( $tg_msg );
		}

		wp_send_json_success( [
			'id'      => $msg_id,
			'message' => 'Melding toegevoegd' . ( $sent > 0 ? ", {$sent} e-mail(s) verstuurd" : '' ) . '.',
		] );
	}

	public function ajax_delete_active_melding(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [], 403 );
		}
		$msg_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$active = get_option( 'snd_p2000_active_statuses', [] );
		unset( $active[ $msg_id ] );
		update_option( 'snd_p2000_active_statuses', $active );
		wp_send_json_success();
	}

	public function ajax_add_log_entry(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$msg_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$text   = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		if ( empty( $msg_id ) || empty( $text ) ) {
			wp_send_json_error( [ 'message' => 'Melding-ID en tekst zijn verplicht.' ] );
		}

		$active = get_option( 'snd_p2000_active_statuses', [] );
		if ( ! isset( $active[ $msg_id ] ) ) {
			wp_send_json_error( [ 'message' => 'Melding niet gevonden.' ] );
		}

		$user  = wp_get_current_user();
		$entry = [
			'time' => time(),
			'user' => $user->display_name ?: 'Admin',
			'text' => $text,
		];

		if ( ! is_array( $active[ $msg_id ]['log'] ?? null ) ) {
			$active[ $msg_id ]['log'] = [];
		}
		$active[ $msg_id ]['log'][] = $entry;
		update_option( 'snd_p2000_active_statuses', $active );

		// Telegram notification for log entry
		$tekst = $active[ $msg_id ]['tekst'] ?? '';
		$short = substr( $msg_id, -6 );
		$this->telegram_send_all(
			"📋 *Log #{$short}* — {$entry['user']}\n_{$text}_"
			. ( $tekst ? "\n\n↩ {$tekst}" : '' )
		);

		wp_send_json_success( [
			'entry'   => $entry,
			'time_str' => wp_date( 'H:i', $entry['time'] ),
		] );
	}

	public function ajax_set_melding_priority(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$msg_id   = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$priority = sanitize_text_field( wp_unslash( $_POST['priority'] ?? 'normaal' ) );
		if ( ! in_array( $priority, [ 'urgent', 'normaal', 'laag' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Ongeldige prioriteit.' ] );
		}

		$active = get_option( 'snd_p2000_active_statuses', [] );
		if ( ! isset( $active[ $msg_id ] ) ) wp_send_json_error( [ 'message' => 'Niet gevonden.' ] );

		$active[ $msg_id ]['priority'] = $priority;
		update_option( 'snd_p2000_active_statuses', $active );
		wp_send_json_success();
	}

	public function ajax_set_melding_eta(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$msg_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$eta    = sanitize_text_field( wp_unslash( $_POST['eta'] ?? '' ) );

		$active = get_option( 'snd_p2000_active_statuses', [] );
		if ( ! isset( $active[ $msg_id ] ) ) wp_send_json_error( [ 'message' => 'Niet gevonden.' ] );

		$active[ $msg_id ]['eta'] = $eta;
		update_option( 'snd_p2000_active_statuses', $active );
		wp_send_json_success();
	}

	// ── AJAX: P2000 incident groepering ──────────────────────────────────────

	public function ajax_p2000_ungroup(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$pair = sanitize_text_field( wp_unslash( $_POST['pair'] ?? '' ) );
		if ( empty( $pair ) || strpos( $pair, '|' ) === false ) {
			wp_send_json_error( [ 'message' => 'Ongeldig pair.' ] );
		}

		$pairs = get_option( 'snd_p2000_ungrouped_pairs', [] );
		$pairs[ $pair ] = true;
		update_option( 'snd_p2000_ungrouped_pairs', $pairs );
		wp_send_json_success();
	}

	public function ajax_p2000_regroup(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$pair = sanitize_text_field( wp_unslash( $_POST['pair'] ?? '' ) );
		$pairs = get_option( 'snd_p2000_ungrouped_pairs', [] );
		unset( $pairs[ $pair ] );
		update_option( 'snd_p2000_ungrouped_pairs', $pairs );
		wp_send_json_success();
	}

	public function ajax_p2000_add_manual(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$primary = sanitize_text_field( wp_unslash( $_POST['primary'] ?? '' ) );
		$tekst   = sanitize_text_field( wp_unslash( $_POST['tekst']   ?? '' ) );
		$type    = sanitize_text_field( wp_unslash( $_POST['type']    ?? 'overig' ) );

		if ( empty( $primary ) || empty( $tekst ) ) {
			wp_send_json_error( [ 'message' => 'Primaire ID en tekst zijn verplicht.' ] );
		}

		$allowed_types = [ 'brandweer', 'politie', 'mmt', 'overig' ];
		if ( ! in_array( $type, $allowed_types, true ) ) $type = 'overig';

		$links = get_option( 'snd_p2000_manual_links', [] );
		if ( ! isset( $links[ $primary ] ) || ! is_array( $links[ $primary ] ) ) {
			$links[ $primary ] = [];
		}

		$entry = [
			'tekst' => $tekst,
			'type'  => $type,
			'tijd'  => time(),
		];
		$links[ $primary ][] = $entry;
		update_option( 'snd_p2000_manual_links', $links );

		$new_idx = count( $links[ $primary ] ) - 1;
		wp_send_json_success( [
			'idx'   => $new_idx,
			'entry' => $entry,
			'message' => 'Melding toegevoegd aan incident.',
		] );
	}

	public function ajax_p2000_remove_manual(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$primary = sanitize_text_field( wp_unslash( $_POST['primary'] ?? '' ) );
		$idx     = absint( $_POST['idx'] ?? -1 );

		$links = get_option( 'snd_p2000_manual_links', [] );
		if ( ! isset( $links[ $primary ] ) ) wp_send_json_error( [ 'message' => 'Niet gevonden.' ] );

		array_splice( $links[ $primary ], $idx, 1 );
		if ( empty( $links[ $primary ] ) ) unset( $links[ $primary ] );
		update_option( 'snd_p2000_manual_links', $links );

		wp_send_json_success();
	}

	// ── AJAX: P2000 portaal-chat lezen + beantwoorden (admin) ────────────────

	public function ajax_p2000_admin_chat_get(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$p2000_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		if ( empty( $p2000_id ) ) wp_send_json_error( [ 'message' => 'Geen ID.' ] );

		$key      = 'snd_p2000_chat_' . md5( $p2000_id );
		$messages = get_option( $key, [] );

		wp_send_json_success( [ 'messages' => is_array( $messages ) ? $messages : [] ] );
	}

	public function ajax_p2000_admin_chat_send(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$p2000_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$body     = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

		if ( empty( $p2000_id ) || empty( $body ) ) {
			wp_send_json_error( [ 'message' => 'ID en bericht zijn verplicht.' ] );
		}

		$key      = 'snd_p2000_chat_' . md5( $p2000_id );
		$messages = get_option( $key, [] );
		if ( ! is_array( $messages ) ) $messages = [];

		$user  = wp_get_current_user();
		$entry = [
			'sender' => 'admin',
			'name'   => $user->display_name ?: 'Redactie',
			'email'  => $user->user_email,
			'body'   => $body,
			'time'   => wp_date( 'H:i' ),
			'ts'     => time(),
		];
		$messages[] = $entry;
		update_option( $key, $messages );

		wp_send_json_success( [ 'message' => 'Verstuurd.' ] );
	}

	// ── AJAX: P2000 status (onderweg / ter plaatse) ───────────────────────────

	public function ajax_set_p2000_status(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Geen permissie.' ], 403 );
		}

		$p2000_id   = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );
		$status     = sanitize_text_field( wp_unslash( $_POST['status']   ?? '' ) );
		$tekst      = sanitize_text_field( wp_unslash( $_POST['tekst']    ?? '' ) );
		$stad       = sanitize_text_field( wp_unslash( $_POST['stad']     ?? '' ) );
		$straat     = sanitize_text_field( wp_unslash( $_POST['straat']   ?? '' ) );
		$type       = sanitize_text_field( wp_unslash( $_POST['type']     ?? 'overig' ) );
		$lat        = sanitize_text_field( wp_unslash( $_POST['lat']      ?? '' ) );
		$lon        = sanitize_text_field( wp_unslash( $_POST['lon']      ?? '' ) );
		$source     = sanitize_text_field( wp_unslash( $_POST['source']   ?? 'feed' ) );
		$send_email = ( $_POST['send_email'] ?? '0' ) === '1';

		// outlet_emails: comma-separated; empty = all outlets
		$outlet_emails_raw = sanitize_text_field( wp_unslash( $_POST['outlet_emails'] ?? '' ) );
		$outlet_emails = array_filter( array_map( 'sanitize_email', explode( ',', $outlet_emails_raw ) ) );

		if ( empty( $p2000_id ) ) {
			wp_send_json_error( [ 'message' => 'Geen melding-ID.' ] );
		}

		$allowed = [ 'onderweg', 'ter_plaatse', 'geen' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			wp_send_json_error( [ 'message' => 'Ongeldige status: ' . $status ] );
		}

		$active = get_option( 'snd_p2000_active_statuses', [] );

		if ( $status === 'geen' ) {
			// For handmatig items: just clear the status, keep the entry
			// For feed items: remove from active list entirely
			if ( isset( $active[ $p2000_id ] ) && ( $active[ $p2000_id ]['source'] ?? 'feed' ) === 'handmatig' ) {
				$active[ $p2000_id ]['status'] = '';
			} else {
				unset( $active[ $p2000_id ] );
			}
		} else {
			// Merge with existing data so we don't lose lat/lon/type/notes/source
			$existing = $active[ $p2000_id ] ?? [];
			$active[ $p2000_id ] = array_merge( $existing, [
				'status' => $status,
				'time'   => time(),
				// Only update these if they're non-empty (don't blank out existing data)
				'tekst'  => $tekst  !== '' ? $tekst  : ( $existing['tekst']  ?? '' ),
				'stad'   => $stad   !== '' ? $stad   : ( $existing['stad']   ?? '' ),
				'straat' => $straat !== '' ? $straat : ( $existing['straat'] ?? '' ),
				'type'   => $type   !== 'overig' ? $type : ( $existing['type'] ?? $type ),
				'source' => $source !== '' ? $source : ( $existing['source'] ?? 'feed' ),
				'lat'    => $lat    !== '' ? $lat    : ( $existing['lat']    ?? null ),
				'lon'    => $lon    !== '' ? $lon    : ( $existing['lon']    ?? null ),
			] );
		}

		update_option( 'snd_p2000_active_statuses', $active );

		$sent_emails = 0;
		if ( $send_email && $status !== 'geen' ) {
			$item_tekst  = $active[ $p2000_id ]['tekst']  ?? $tekst;
			$item_stad   = $active[ $p2000_id ]['stad']   ?? $stad;
			$item_straat = $active[ $p2000_id ]['straat'] ?? $straat;
			$sent_emails = $this->send_p2000_status_email( $status, $item_tekst, $item_stad, $item_straat, $outlet_emails );
		}

		if ( $status !== 'geen' ) {
			$item_tekst  = $active[ $p2000_id ]['tekst']  ?? $tekst;
			$item_stad   = $active[ $p2000_id ]['stad']   ?? $stad;
			$item_straat = $active[ $p2000_id ]['straat'] ?? $straat;
			$locatie     = trim( ( $item_straat ? $item_straat . ', ' : '' ) . $item_stad );
			$icon        = $status === 'onderweg' ? '🚨' : '📍';
			$label       = $status === 'onderweg' ? 'Fotograaf onderweg' : 'Fotograaf ter plaatse';
			$tg_msg      = "{$icon} *{$label}*" . ( $locatie ? "\n📍 {$locatie}" : '' ) . "\n\n_{$item_tekst}_";
			$this->telegram_send_all( $tg_msg );
		}

		$status_label = $status === 'onderweg' ? '🚨 Onderweg' : ( $status === 'geen' ? 'Gewist' : '📍 Ter plaatse' );
		wp_send_json_success( [
			'message' => $status === 'geen'
				? 'Status gewist.'
				: "{$status_label} opgeslagen" . ( $sent_emails > 0 ? ", {$sent_emails} e-mail(s) verstuurd" : '' ) . '.',
		] );
	}

	private function send_p2000_status_email( string $status, string $tekst, string $stad, string $straat, array $outlet_emails = [] ): int {
		$outlets    = get_option( self::OPTION_OUTLETS, [] );
		$from_name  = get_option( 'snd_email_from_name',  get_bloginfo( 'name' ) );
		$from_email = get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) );
		$site_name  = get_bloginfo( 'name' );
		$locatie    = trim( ( $straat ? $straat . ', ' : '' ) . $stad );

		$is_onderweg = $status === 'onderweg';
		$icon        = $is_onderweg ? '🚨' : '📍';
		$label       = $is_onderweg ? 'Fotograaf onderweg' : 'Fotograaf ter plaatse';
		$subject     = $icon . ' ' . $label . ( $locatie ? ': ' . $locatie : '' );

		$portal_base = home_url( '/persportaal/' );

		$html = '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center" style="padding:24px 16px;">'
			. '<table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;">'

			// Header
			. '<tr><td align="center" bgcolor="' . ( $is_onderweg ? '#92400e' : '#1e3a8a' ) . '" style="padding:24px 30px;border-radius:8px 8px 0 0;">'
			. '<p style="margin:0 0 8px;font-size:32px;line-height:1;">' . $icon . '</p>'
			. '<h1 style="margin:0;font-size:24px;font-weight:700;color:#fff;line-height:1.3;">' . esc_html( $label ) . '</h1>'
			. ( $locatie ? '<p style="margin:8px 0 0;font-size:16px;color:rgba(255,255,255,.8);">📍 ' . esc_html( $locatie ) . '</p>' : '' )
			. '</td></tr>'

			// Body
			. '<tr><td bgcolor="#ffffff" style="padding:32px 30px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
			. '<p style="margin:0 0 16px;font-size:15px;color:#334155;">'
			. ( $is_onderweg
				? 'Een <strong>' . esc_html( $site_name ) . '</strong> fotograaf/verslaggever is onderweg naar dit incident. Meer details volgen.'
				: 'Een <strong>' . esc_html( $site_name ) . '</strong> fotograaf/verslaggever is ter plaatse bij dit incident. Beelden en informatie volgen binnenkort.' )
			. '</p>'
			. '<div style="background:#f8fafc;border-left:4px solid ' . ( $is_onderweg ? '#f59e0b' : '#3b82f6' ) . ';border-radius:4px;padding:16px 20px;margin:20px 0;">'
			. '<p style="margin:0;font-family:\'Courier New\',monospace;font-size:13px;color:#475569;">' . esc_html( $tekst ) . '</p>'
			. '</div>'
			. '<p style="margin:16px 0 0;font-size:13px;color:#94a3b8;">Volg de berichtgeving via uw persoonlijk portaal.</p>'
			. '</td></tr>'

			// Footer
			. '<tr><td align="center" bgcolor="#f1f5f9" style="padding:16px 30px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;">'
			. '<p style="margin:0;font-size:12px;color:#94a3b8;">© ' . date( 'Y' ) . ' ' . esc_html( $site_name ) . '</p>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		$sent = 0;
		foreach ( $outlets as $outlet ) {
			if ( empty( $outlet['email'] ) || empty( $outlet['access_code'] ) ) continue;
			// If a filter list is given, only send to those outlets
			if ( ! empty( $outlet_emails ) && ! in_array( $outlet['email'], $outlet_emails, true ) ) continue;

			// Add personal portal link to body
			$personal_url  = $portal_base . '?access_code=' . rawurlencode( $outlet['access_code'] );
			$personal_html = str_replace(
				'Volg de berichtgeving via uw persoonlijk portaal.',
				'Volg de berichtgeving via <a href="' . esc_url( $personal_url ) . '" style="color:#3b82f6;">uw persoonlijk portaal</a>.',
				$html
			);

			add_filter( 'wp_mail_content_type', function() { return 'text/html'; } );
			$ok = wp_mail(
				$outlet['email'],
				$subject,
				$personal_html,
				[ "From: {$from_name} <{$from_email}>" ]
			);
			remove_all_filters( 'wp_mail_content_type' );

			if ( $ok ) $sent++;
		}
		return $sent;
	}

	private function telegram_send( string $token, string $chat_id, string $text ): bool {
		$response = wp_remote_post(
			'https://api.telegram.org/bot' . $token . '/sendMessage',
			[
				'timeout' => 10,
				'body'    => [
					'chat_id'    => $chat_id,
					'text'       => $text,
					'parse_mode' => 'Markdown',
				],
			]
		);
		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Broadcast a message to ALL configured Telegram recipients.
	 * Falls back to the legacy single chat_id if no recipients list is configured.
	 */
	private function telegram_send_all( string $text ): void {
		$token      = get_option( 'snd_telegram_bot_token', '' );
		if ( empty( $token ) ) return;

		$recipients = get_option( 'snd_telegram_recipients', [] );
		if ( ! is_array( $recipients ) || empty( $recipients ) ) {
			// Legacy fallback
			$chat_id = get_option( 'snd_telegram_chat_id', '' );
			if ( $chat_id ) $this->telegram_send( $token, $chat_id, $text );
			return;
		}
		foreach ( $recipients as $r ) {
			$cid = $r['chat_id'] ?? '';
			if ( $cid && ! empty( $r['enabled'] ) ) {
				$this->telegram_send( $token, $cid, $text );
			}
		}
	}

	public function ajax_telegram_test(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

		$token   = sanitize_text_field( wp_unslash( $_POST['token']   ?? '' ) );
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) );

		if ( empty( $token ) || empty( $chat_id ) ) {
			wp_send_json_error( [ 'message' => 'Token en chat ID zijn verplicht.' ] );
		}

		$site = get_bloginfo( 'name' );
		$ok   = $this->telegram_send( $token, $chat_id, "✅ *Testbericht van {$site}*\n\nDe Telegram-integratie werkt correct." );

		if ( $ok ) {
			wp_send_json_success( [ 'message' => 'Testbericht verstuurd.' ] );
		} else {
			wp_send_json_error( [ 'message' => 'Mislukt. Controleer token en chat ID.' ] );
		}
	}

	public function ajax_telegram_register_commands(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( empty( $token ) ) wp_send_json_error( [ 'message' => 'Token is verplicht.' ] );

		if ( ! function_exists( 'snd_tg_register_commands' ) ) {
			wp_send_json_error( [ 'message' => 'Functie niet beschikbaar.' ] );
		}

		$ok = snd_tg_register_commands( $token );
		if ( $ok ) {
			wp_send_json_success( [ 'message' => 'Bot-commando\'s geregistreerd.' ] );
		} else {
			wp_send_json_error( [ 'message' => 'Mislukt. Controleer het token.' ] );
		}
	}

	// ── AJAX: Save Telegram recipients list ──────────────────────────────────

	public function ajax_telegram_save_recipients(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

		$raw  = wp_unslash( $_POST['recipients_json'] ?? '[]' );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) wp_send_json_error( [ 'message' => 'Ongeldige data.' ] );

		$clean = [];
		foreach ( $data as $r ) {
			$name    = sanitize_text_field( $r['name']    ?? '' );
			$chat_id = sanitize_text_field( $r['chat_id'] ?? '' );
			$enabled = ! empty( $r['enabled'] );
			$role    = in_array( $r['role'] ?? '', [ 'admin', 'redacteur', 'fotograaf', 'lezer' ], true )
				? $r['role'] : 'lezer';
			$needs_approval  = ! empty( $r['needs_approval'] );
			$allowed_outlets = isset( $r['allowed_outlets'] ) && is_array( $r['allowed_outlets'] )
				? array_map( 'sanitize_email', $r['allowed_outlets'] )
				: 'all';
			if ( $chat_id ) $clean[] = [
				'name'            => $name,
				'chat_id'         => $chat_id,
				'enabled'         => $enabled,
				'role'            => $role,
				'needs_approval'  => $needs_approval,
				'allowed_outlets' => $allowed_outlets,
			];
		}
		update_option( 'snd_telegram_recipients', $clean );
		wp_send_json_success( [ 'message' => count( $clean ) . ' ontvanger(s) opgeslagen.', 'recipients' => $clean ] );
	}

	// ── AJAX: Test a single Telegram recipient ────────────────────────────────

	public function ajax_telegram_test_recipient(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

		$token   = get_option( 'snd_telegram_bot_token', '' );
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) );
		$name    = sanitize_text_field( wp_unslash( $_POST['name']    ?? 'Ontvanger' ) );

		if ( ! $token || ! $chat_id ) wp_send_json_error( [ 'message' => 'Token of chat ID ontbreekt.' ] );

		$site = get_bloginfo( 'name' );
		$ok   = $this->telegram_send( $token, $chat_id, "✅ *Testbericht van {$site}*\n\nDe verbinding met *{$name}* werkt correct." );
		if ( $ok ) {
			wp_send_json_success( [ 'message' => "Testbericht verstuurd naar {$name}." ] );
		} else {
			wp_send_json_error( [ 'message' => 'Versturen mislukt. Controleer het chat ID.' ] );
		}
	}

	// ── AJAX: Email preview ───────────────────────────────────────────────────

	public function ajax_email_preview(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) wp_send_json_error( [ 'message' => 'Post niet gevonden.' ] );

		// Build a dummy outlet for preview
		$outlet = [
			'name'        => 'Voorbeeldredactie',
			'email'       => get_bloginfo( 'admin_email' ),
			'access_code' => 'preview',
		];
		$preview_images   = $this->get_press_photo_ids( $post_id );
		$press_photo_count = count( $preview_images );

		ob_start();
		$tpl = SND_PLUGIN_PATH . 'templates/email-template.php';
		include $tpl;
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	public function ajax_p2000_test_feed(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		include_once ABSPATH . WPINC . '/feed.php';

		$feed_urls = [
			'brandweer' => get_option( 'snd_p2000_feed_brandweer', '' ),
			'politie'   => get_option( 'snd_p2000_feed_politie', '' ),
			'mmt'       => get_option( 'snd_p2000_feed_mmt', '' ),
		];

		$results = [];

		foreach ( $feed_urls as $type => $url ) {
			if ( empty( $url ) ) {
				$results[ $type ] = [ 'status' => 'skip', 'msg' => 'Geen URL ingesteld.', 'items' => [] ];
				continue;
			}

			// Bust cache
			$bust = add_query_arg( '_t', time(), $url );
			$feed = fetch_feed( $bust );

			if ( is_wp_error( $feed ) ) {
				$results[ $type ] = [ 'status' => 'error', 'msg' => $feed->get_error_message(), 'items' => [] ];
				continue;
			}

			$items = [];
			foreach ( array_slice( $feed->get_items(), 0, 5 ) as $item ) {
				$items[] = [
					'title'   => (string) $item->get_title(),
					'date'    => (string) $item->get_date( 'd-m-Y H:i:s' ),
					'lat'     => $item->get_latitude(),
					'lon'     => $item->get_longitude(),
					'id'      => $item->get_id( true ),
				];
			}

			$results[ $type ] = [
				'status' => 'ok',
				'msg'    => $feed->get_item_quantity() . ' items in feed.',
				'items'  => $items,
			];

			$feed->__destruct();
		}

		wp_send_json_success( $results );
	}

	public function ajax_shortcode_map(): void {
		check_ajax_referer( 'snd_public_nonce', 'nonce' );
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'meta_query'     => [
				[ 'key' => self::META_LAT, 'compare' => 'EXISTS' ],
				[ 'key' => self::META_LON, 'compare' => 'EXISTS' ],
			],
		] );
		$markers = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid = get_the_ID();
				$lat = (float) get_post_meta( $pid, self::META_LAT, true );
				$lon = (float) get_post_meta( $pid, self::META_LON, true );
				if ( ! $lat || ! $lon ) continue;
				$markers[] = [
					'id'      => $pid,
					'title'   => get_the_title(),
					'lat'     => $lat,
					'lon'     => $lon,
					'url'     => get_permalink(),
					'street'  => get_post_meta( $pid, self::META_STREET1, true ),
					'is_live' => get_post_meta( $pid, self::META_IS_LIVE, true ) === '1',
					'thumb'   => has_post_thumbnail() ? get_the_post_thumbnail_url( $pid, 'thumbnail' ) : '',
					'date'    => get_the_date( 'd-m-Y' ),
				];
			}
			wp_reset_postdata();
		}
		wp_send_json_success( $markers );
	}

	// ── Page: Reports ─────────────────────────────────────────────────────────

	public function page_reports(): void {
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 200,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [ [ 'key' => self::META_DISPATCH_LOG, 'compare' => 'EXISTS' ] ],
		] );

		// Build summary data for all posts upfront (used for search/filter in JS)
		$report_data = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid          = get_the_ID();
				$dispatch_log = get_post_meta( $pid, self::META_DISPATCH_LOG, true );
				$access_log   = get_post_meta( $pid, self::META_ACCESS_LOG, true );
				$update_log   = get_post_meta( $pid, self::META_UPDATE_LOG, true );
				$blocked      = get_post_meta( $pid, self::META_BLOCKED_OUTLETS, true );

				if ( ! is_array( $dispatch_log ) ) continue;

				$first_entry  = reset( $dispatch_log );
				$sent_ts      = $first_entry['timestamp'] ?? get_post_timestamp( $pid );
				$total_sent   = count( $dispatch_log );
				$total_open   = 0;
				$total_dl     = 0;
				$num_updates  = is_array( $update_log ) ? count( $update_log ) : 0;
				$num_blocked  = is_array( $blocked ) ? count( $blocked ) : 0;

				if ( is_array( $access_log ) ) {
					foreach ( $access_log as $l ) {
						if ( ! empty( $l['views'] ) )     $total_open++;
						if ( ! empty( $l['downloads'] ) ) $total_dl += count( $l['downloads'] );
					}
				}

				$open_rate = $total_sent > 0 ? round( $total_open / $total_sent * 100 ) : 0;
				$not_opened = $total_sent - $total_open;
				$days_since = $sent_ts ? round( ( time() - $sent_ts ) / DAY_IN_SECONDS ) : null;

				$report_data[] = [
					'id'          => $pid,
					'title'       => get_the_title(),
					'date'        => wp_date( 'd-m-Y', $sent_ts ),
					'ts'          => $sent_ts,
					'sent'        => $total_sent,
					'opened'      => $total_open,
					'not_opened'  => $not_opened,
					'downloads'   => $total_dl,
					'open_rate'   => $open_rate,
					'updates'     => $num_updates,
					'blocked'     => $num_blocked,
					'days_since'  => $days_since,
					'edit_url'    => get_edit_post_link( $pid ),
					'post_url'    => get_permalink( $pid ),
				];
			}
			wp_reset_postdata();
		}
		?>
		<div class="wrap snd-admin-wrap">
			<h1><?php esc_html_e( 'Rapportages', 'nieuws-distributie-systeem' ); ?></h1>

			<?php if ( empty( $report_data ) ) : ?>
				<p><?php esc_html_e( 'Nog geen berichten verstuurd (in live-modus).', 'nieuws-distributie-systeem' ); ?></p>
			<?php else : ?>

			<!-- Toolbar -->
			<div class="snd-reports-toolbar">
				<div class="snd-reports-search-wrap">
					<span class="dashicons dashicons-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#999;pointer-events:none;"></span>
					<input type="search" id="snd-report-search" placeholder="<?php esc_attr_e( 'Zoek op titel…', 'nieuws-distributie-systeem' ); ?>" class="regular-text" style="padding-left:32px;">
				</div>
				<div class="snd-reports-filters">
					<button class="button snd-filter-btn active" data-filter="all"><?php esc_html_e( 'Alle', 'nieuws-distributie-systeem' ); ?> <span class="snd-count"><?php echo count( $report_data ); ?></span></button>
					<button class="button snd-filter-btn" data-filter="opened"><?php esc_html_e( '✓ Geopend', 'nieuws-distributie-systeem' ); ?></button>
					<button class="button snd-filter-btn" data-filter="unopened"><?php esc_html_e( '○ Niet geopend', 'nieuws-distributie-systeem' ); ?></button>
					<button class="button snd-filter-btn" data-filter="has_updates"><?php esc_html_e( '📢 Met updates', 'nieuws-distributie-systeem' ); ?></button>
					<button class="button snd-filter-btn" data-filter="has_blocked"><?php esc_html_e( '🔒 Geblokkeerd', 'nieuws-distributie-systeem' ); ?></button>
				</div>
				<div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
					<span id="snd-report-count" style="font-size:13px;color:#666;"></span>
					<button class="button" id="snd-export-csv"><?php esc_html_e( '⬇ Export CSV', 'nieuws-distributie-systeem' ); ?></button>
					<button class="button" id="snd-expand-all"><?php esc_html_e( 'Alles uitklappen', 'nieuws-distributie-systeem' ); ?></button>
				</div>
			</div>

			<!-- Reports table (summary rows, expandable) -->
			<div id="snd-reports-table-wrap">
				<table class="wp-list-table widefat fixed striped" id="snd-reports-table">
					<thead>
						<tr>
							<th class="snd-col-toggle" style="width:28px;"></th>
							<th class="snd-sortable" data-sort="title"><?php esc_html_e( 'Bericht', 'nieuws-distributie-systeem' ); ?> <span class="snd-sort-icon">↕</span></th>
							<th class="snd-sortable" data-sort="date" style="width:100px;"><?php esc_html_e( 'Verzonden', 'nieuws-distributie-systeem' ); ?> <span class="snd-sort-icon">↓</span></th>
							<th class="snd-sortable" data-sort="sent" style="width:70px;"><?php esc_html_e( 'Verz.', 'nieuws-distributie-systeem' ); ?> <span class="snd-sort-icon">↕</span></th>
							<th style="width:200px;"><?php esc_html_e( 'Open rate', 'nieuws-distributie-systeem' ); ?></th>
							<th class="snd-sortable" data-sort="downloads" style="width:80px;"><?php esc_html_e( 'Downloads', 'nieuws-distributie-systeem' ); ?> <span class="snd-sort-icon">↕</span></th>
							<th style="width:90px;"><?php esc_html_e( 'Status', 'nieuws-distributie-systeem' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Acties', 'nieuws-distributie-systeem' ); ?></th>
						</tr>
					</thead>
					<tbody id="snd-reports-tbody">
					<?php foreach ( $report_data as $r ) :
						$badge_html = '';
						if ( $r['updates'] > 0 )
							$badge_html .= '<span class="snd-pill snd-pill-blue" title="' . esc_attr( $r['updates'] ) . ' update(s)">' . $r['updates'] . ' update</span> ';
						if ( $r['blocked'] > 0 )
							$badge_html .= '<span class="snd-pill snd-pill-red" title="' . esc_attr( $r['blocked'] ) . ' geblokkeerd">' . $r['blocked'] . ' 🔒</span>';
					?>
					<tr class="snd-report-row"
						data-postid="<?php echo $r['id']; ?>"
						data-title="<?php echo esc_attr( strtolower( $r['title'] ) ); ?>"
						data-ts="<?php echo $r['ts']; ?>"
						data-sent="<?php echo $r['sent']; ?>"
						data-opened="<?php echo $r['opened']; ?>"
						data-downloads="<?php echo $r['downloads']; ?>"
						data-updates="<?php echo $r['updates']; ?>"
						data-blocked="<?php echo $r['blocked']; ?>">
						<td class="snd-col-toggle"><button class="snd-row-toggle button-link" aria-expanded="false" title="Details"><span class="dashicons dashicons-arrow-right-alt2"></span></button></td>
						<td>
							<strong><a href="<?php echo esc_url( $r['edit_url'] ); ?>" target="_blank"><?php echo esc_html( $r['title'] ); ?></a></strong>
							<?php if ( $badge_html ) echo '<br><span style="margin-top:3px;display:inline-block;">' . $badge_html . '</span>'; ?>
						</td>
						<td><?php echo esc_html( $r['date'] ); ?></td>
						<td style="text-align:center;"><?php echo $r['sent']; ?></td>
						<td>
							<div class="snd-openrate-wrap">
								<div class="snd-openrate-bar">
									<div class="snd-openrate-fill" style="width:<?php echo $r['open_rate']; ?>%;background:<?php echo $r['open_rate'] >= 60 ? '#16a34a' : ( $r['open_rate'] >= 30 ? '#ca8a04' : '#dc2626' ); ?>;"></div>
								</div>
								<span class="snd-openrate-pct"><?php echo $r['open_rate']; ?>%</span>
								<small style="color:#888;">(<?php echo $r['opened']; ?>/<?php echo $r['sent']; ?>)</small>
							</div>
						</td>
						<td style="text-align:center;"><?php echo $r['downloads']; ?></td>
						<td>
							<?php if ( $r['opened'] > 0 ) : ?>
								<span class="snd-status-dot snd-dot-green"></span><?php esc_html_e( 'Actief', 'nieuws-distributie-systeem' ); ?>
							<?php elseif ( $r['sent'] > 0 ) : ?>
								<span class="snd-status-dot snd-dot-grey"></span><?php esc_html_e( 'Wacht', 'nieuws-distributie-systeem' ); ?>
							<?php endif; ?>
							<?php if ( $r['not_opened'] > 0 ) : ?>
								<br><small style="color:#b32d2e;font-size:10px;">
									<?php echo esc_html( $r['not_opened'] ); ?> niet geopend
									<?php if ( $r['days_since'] !== null && $r['days_since'] > 1 ) : ?>
										(<?php echo esc_html( $r['days_since'] ); ?>d)
									<?php endif; ?>
								</small>
							<?php endif; ?>
						</td>
						<td>
							<button class="button button-small snd-reset-stats" data-postid="<?php echo $r['id']; ?>" title="Reset stats">↺</button>
						</td>
					</tr>
					<tr class="snd-detail-row" id="detail-<?php echo $r['id']; ?>" style="display:none;">
						<td colspan="8" style="padding:0;">
							<div class="snd-detail-inner" id="detail-inner-<?php echo $r['id']; ?>">
								<div class="snd-detail-loading"><span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Laden…', 'nieuws-distributie-systeem' ); ?></div>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p id="snd-no-results" style="display:none;padding:16px;color:#888;"><?php esc_html_e( 'Geen berichten gevonden.', 'nieuws-distributie-systeem' ); ?></p>
			</div>

			<?php endif; ?>
		</div>

		<script>
		(function($) {
			var reportData = <?php echo wp_json_encode( $report_data ); ?>;
			var currentSort = { key: 'ts', dir: -1 };
			var currentFilter = 'all';
			var currentSearch = '';

			// ── Sort ────────────────────────────────────────────────────────
			$('.snd-sortable').on('click', function() {
				var key = $(this).data('sort');
				if (currentSort.key === key) {
					currentSort.dir *= -1;
				} else {
					currentSort.key = key;
					currentSort.dir = -1;
				}
				$('.snd-sort-icon').text('↕');
				$(this).find('.snd-sort-icon').text(currentSort.dir === 1 ? '↑' : '↓');
				renderTable();
			});

			// ── Filter ──────────────────────────────────────────────────────
			$('.snd-filter-btn').on('click', function() {
				$('.snd-filter-btn').removeClass('active');
				$(this).addClass('active');
				currentFilter = $(this).data('filter');
				renderTable();
			});

			// ── Search ──────────────────────────────────────────────────────
			$('#snd-report-search').on('input', function() {
				currentSearch = this.value.toLowerCase().trim();
				renderTable();
			});

			// ── Expand/collapse row ──────────────────────────────────────────
			$('#snd-reports-tbody').on('click', '.snd-row-toggle', function(e) {
				e.stopPropagation();
				var pid  = $(this).closest('.snd-report-row').data('postid');
				toggleDetail(pid, $(this));
			});
			$('#snd-reports-tbody').on('click', '.snd-report-row', function(e) {
				if ($(e.target).closest('a,button').length) return;
				var pid = $(this).data('postid');
				toggleDetail(pid, $(this).find('.snd-row-toggle'));
			});

			function toggleDetail(pid, toggleBtn) {
				var detailRow  = $('#detail-' + pid);
				var innerDiv   = $('#detail-inner-' + pid);
				var isOpen     = detailRow.is(':visible');

				if (isOpen) {
					detailRow.hide();
					toggleBtn.attr('aria-expanded', 'false').find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
					return;
				}

				detailRow.show();
				toggleBtn.attr('aria-expanded', 'true').find('.dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');

				if (innerDiv.find('.snd-detail-loading').length) {
					loadDetail(pid, innerDiv);
				}
			}

			function loadDetail(pid, container) {
				$.post(ajaxurl, { action: 'snd_get_tracking_status', nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>, post_id: pid })
				.done(function(res) {
					if (res.success) container.html(res.data.html);
					else container.html('<p style="padding:16px;color:#b32d2e;">Kon data niet laden.</p>');
				})
				.fail(function() {
					container.html('<p style="padding:16px;color:#b32d2e;">Serverfout.</p>');
				});
			}

			// ── Expand all ───────────────────────────────────────────────────
			var allExpanded = false;
			$('#snd-expand-all').on('click', function() {
				allExpanded = !allExpanded;
				$(this).text(allExpanded ? 'Alles inklappen' : 'Alles uitklappen');
				$('.snd-report-row:visible').each(function() {
					var pid      = $(this).data('postid');
					var detailRow = $('#detail-' + pid);
					var innerDiv  = $('#detail-inner-' + pid);
					var toggleBtn = $(this).find('.snd-row-toggle');
					if (allExpanded) {
						detailRow.show();
						toggleBtn.attr('aria-expanded','true').find('.dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
						if (innerDiv.find('.snd-detail-loading').length) loadDetail(pid, innerDiv);
					} else {
						detailRow.hide();
						toggleBtn.attr('aria-expanded','false').find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
					}
				});
			});

			// ── Reset stats ──────────────────────────────────────────────────
			$(document).on('click', '.snd-reset-stats', function(e) {
				e.stopPropagation();
				if (!confirm('Statistieken resetten voor dit bericht?')) return;
				var pid = $(this).data('postid');
				$.post(ajaxurl, { action: 'snd_reset_report_stats', nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>, post_id: pid })
				.done(function(res) {
					if (res.success) location.reload();
				});
			});

			// ── Block/unblock from inside detail row ─────────────────────────
			$(document).on('click', '.snd-block-outlet, .snd-unblock-outlet', function(e) {
				e.stopPropagation();
				var btn     = $(this);
				var pid     = btn.data('postid');
				var email   = btn.data('email');
				var doBlock = btn.hasClass('snd-block-outlet');
				btn.prop('disabled', true).text('…');
				$.post(ajaxurl, { action: 'snd_toggle_outlet_block', nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>, post_id: pid, email: email, block: doBlock ? 1 : 0 })
				.done(function(res) {
					if (res.success) {
						var inner = $('#detail-inner-' + pid);
						inner.html('<div class="snd-detail-loading"><span class="spinner is-active" style="float:none;"></span> Laden…</div>');
						loadDetail(pid, inner);
					}
				});
			});

			// ── Export CSV ───────────────────────────────────────────────────
			$('#snd-export-csv').on('click', function() {
				var visible = getVisible();
				var BOM = '\uFEFF'; // UTF-8 BOM for Excel
				var rows = [['ID','Titel','Verzenddatum','Ontvangers','Geopend','Niet geopend','Open rate %','Downloads','Updates','Geblokkeerd','Dagen geleden']];
				visible.forEach(function(r) {
					rows.push([
						r.id,
						'"' + (r.title||'').replace(/"/g,'""') + '"',
						r.date,
						r.sent,
						r.opened,
						(r.sent||0) - (r.opened||0),
						r.open_rate,
						r.downloads,
						r.updates,
						r.blocked,
						r.days_since !== null && r.days_since !== undefined ? r.days_since : '',
					]);
				});
				var csv = BOM + rows.map(function(r){ return r.join(';'); }).join('\n');
				var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
				var url  = URL.createObjectURL(blob);
				var a    = document.createElement('a');
				a.href = url; a.download = 'rapportages-' + new Date().toISOString().slice(0,10) + '.csv';
				document.body.appendChild(a); a.click(); document.body.removeChild(a);
				URL.revokeObjectURL(url);
			});

			// ── Render ───────────────────────────────────────────────────────
			function getVisible() {
				return reportData
					.filter(function(r) {
						if (currentSearch && r.title.toLowerCase().indexOf(currentSearch) === -1) return false;
						if (currentFilter === 'opened')      return r.opened > 0;
						if (currentFilter === 'unopened')    return r.opened === 0;
						if (currentFilter === 'has_updates') return r.updates > 0;
						if (currentFilter === 'has_blocked') return r.blocked > 0;
						return true;
					})
					.sort(function(a, b) {
						var av = a[currentSort.key], bv = b[currentSort.key];
						if (typeof av === 'string') return av.localeCompare(bv) * currentSort.dir;
						return (av - bv) * currentSort.dir;
					});
			}

			function renderTable() {
				var visible = getVisible();
				$('#snd-reports-tbody .snd-report-row, #snd-reports-tbody .snd-detail-row').hide();
				visible.forEach(function(r) {
					$('.snd-report-row[data-postid="' + r.id + '"]').show();
				});
				var count = visible.length;
				$('#snd-report-count').text(count + ' van ' + reportData.length + ' berichten');
				$('#snd-no-results').toggle(count === 0);
			}

			// Initial render
			renderTable();

		})(jQuery);
		</script>
		<?php
	}

	// ── Page: Analytics ───────────────────────────────────────────────────────

	public function page_analytics(): void {
		$stats = $this->get_analytics_stats();
		// Totals across all time
		$total_dispatched = 0;
		$total_opens      = 0;
		$total_dls        = 0;
		foreach ( $stats as $s ) {
			$total_opens += $s['opens'];
			$total_dls   += $s['downloads'];
		}
		$query = new \WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'meta_query' => [ [ 'key' => self::META_DISPATCH_LOG, 'compare' => 'EXISTS' ] ], 'fields' => 'ids' ] );
		$total_dispatched = $query->found_posts;

		// Activity per day last 14 days
		$daily = [];
		for ( $i = 13; $i >= 0; $i-- ) {
			$daily[ wp_date( 'd-m', strtotime( "-{$i} days" ) ) ] = [ 'opens' => 0, 'downloads' => 0 ];
		}
		$all_posts = new \WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'meta_key' => self::META_ACCESS_LOG, 'fields' => 'ids' ] );
		foreach ( $all_posts->posts as $pid ) {
			$log = get_post_meta( $pid, self::META_ACCESS_LOG, true );
			if ( ! is_array( $log ) ) continue;
			foreach ( $log as $item ) {
				foreach ( $item['views'] ?? [] as $ts ) {
					$d = wp_date( 'd-m', $ts );
					if ( isset( $daily[ $d ] ) ) $daily[ $d ]['opens']++;
				}
				foreach ( $item['downloads'] ?? [] as $dl ) {
					$d = wp_date( 'd-m', $dl['time'] );
					if ( isset( $daily[ $d ] ) ) $daily[ $d ]['downloads']++;
				}
			}
		}

		// Per-outlet aggregate: total opens, downloads, articles received
		$outlet_stats = [];
		$all_access   = new \WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [ [ 'key' => self::META_ACCESS_LOG, 'compare' => 'EXISTS' ] ] ] );
		foreach ( $all_access->posts as $pid ) {
			$access_log   = get_post_meta( $pid, self::META_ACCESS_LOG, true );
			$dispatch_log = get_post_meta( $pid, self::META_DISPATCH_LOG, true );
			if ( ! is_array( $access_log ) ) continue;
			foreach ( $access_log as $email => $l ) {
				$name = $l['name'] ?? $email;
				if ( ! isset( $outlet_stats[ $email ] ) ) {
					$outlet_stats[ $email ] = [ 'name' => $name, 'opens' => 0, 'downloads' => 0, 'received' => 0 ];
				}
				$outlet_stats[ $email ]['opens']     += count( $l['views']     ?? [] );
				$outlet_stats[ $email ]['downloads']  += count( $l['downloads'] ?? [] );
			}
			// Count dispatches per outlet
			if ( is_array( $dispatch_log ) ) {
				foreach ( $dispatch_log as $email => $d ) {
					if ( ! isset( $outlet_stats[ $email ] ) ) {
						$outlet_stats[ $email ] = [ 'name' => $d['name'] ?? $email, 'opens' => 0, 'downloads' => 0, 'received' => 0 ];
					}
					$outlet_stats[ $email ]['received']++;
				}
			}
		}
		// Sort by total opens desc
		uasort( $outlet_stats, fn( $a, $b ) => $b['opens'] - $a['opens'] );
		?>
		<div class="wrap snd-admin-wrap">
			<h1><?php esc_html_e( 'Statistieken & Inzichten', 'nieuws-distributie-systeem' ); ?></h1>

			<!-- KPI cards -->
			<div class="snd-kpi-row">
				<div class="snd-kpi-card">
					<div class="snd-kpi-val"><?php echo (int) $total_dispatched; ?></div>
					<div class="snd-kpi-lbl"><?php esc_html_e( 'Verzonden berichten', 'nieuws-distributie-systeem' ); ?></div>
				</div>
				<div class="snd-kpi-card">
					<div class="snd-kpi-val"><?php echo (int) $total_opens; ?></div>
					<div class="snd-kpi-lbl"><?php esc_html_e( 'Totaal opens (all-time)', 'nieuws-distributie-systeem' ); ?></div>
				</div>
				<div class="snd-kpi-card">
					<div class="snd-kpi-val"><?php echo (int) $total_dls; ?></div>
					<div class="snd-kpi-lbl"><?php esc_html_e( 'Totaal downloads (all-time)', 'nieuws-distributie-systeem' ); ?></div>
				</div>
				<div class="snd-kpi-card">
					<div class="snd-kpi-val"><?php echo count( get_option( self::OPTION_OUTLETS, [] ) ); ?></div>
					<div class="snd-kpi-lbl"><?php esc_html_e( 'Actieve outlets', 'nieuws-distributie-systeem' ); ?></div>
				</div>
			</div>

			<!-- Activity chart -->
			<div class="snd-card" style="margin-bottom:24px;">
				<h3 style="margin:0 0 16px;"><?php esc_html_e( 'Activiteit afgelopen 14 dagen', 'nieuws-distributie-systeem' ); ?></h3>
				<canvas id="snd-activity-chart" height="80"></canvas>
			</div>

			<!-- Leaderboard -->
			<h3><?php esc_html_e( 'Meest actieve media outlets', 'nieuws-distributie-systeem' ); ?></h3>
			<?php if ( empty( $stats ) ) : ?>
				<p><?php esc_html_e( 'Nog geen activiteit gelogd.', 'nieuws-distributie-systeem' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:36px;">#</th>
							<th><?php esc_html_e( 'Media Outlet', 'nieuws-distributie-systeem' ); ?></th>
							<th><?php esc_html_e( 'E-mail', 'nieuws-distributie-systeem' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Opens', 'nieuws-distributie-systeem' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Downloads', 'nieuws-distributie-systeem' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Betrokkenheid', 'nieuws-distributie-systeem' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$max_score = max( array_column( $stats, 'score' ) ) ?: 1;
					$rank = 1;
					foreach ( $stats as $email => $data ) :
						$pct = round( $data['score'] / $max_score * 100 );
						?>
						<tr>
							<td><strong><?php echo $rank++; ?></strong></td>
							<td><strong><?php echo esc_html( $data['name'] ); ?></strong></td>
							<td><?php echo esc_html( $email ); ?></td>
							<td><?php echo (int) $data['opens']; ?></td>
							<td><?php echo (int) $data['downloads']; ?></td>
							<td>
								<div style="background:#e5e7eb;border-radius:4px;height:10px;overflow:hidden;">
									<div style="background:#3b82f6;height:100%;width:<?php echo $pct; ?>%;border-radius:4px;transition:width .4s;"></div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
		<script>
		(function() {
			var labels  = <?php echo wp_json_encode( array_keys( $daily ) ); ?>;
			var opens   = <?php echo wp_json_encode( array_column( $daily, 'opens' ) ); ?>;
			var dls     = <?php echo wp_json_encode( array_column( $daily, 'downloads' ) ); ?>;
			var ctx     = document.getElementById('snd-activity-chart');
			if (!ctx) return;
			new Chart(ctx, {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [
						{ label: 'Opens', data: opens, backgroundColor: 'rgba(59,130,246,.7)', borderRadius: 4 },
						{ label: 'Downloads', data: dls, backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 4 },
					]
				},
				options: {
					responsive: true,
					plugins: { legend: { position: 'bottom' } },
					scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } }
				}
			});
		})();
		</script>

		<!-- Per-outlet activiteiten tabel -->
		<?php if ( ! empty( $outlet_stats ) ) : ?>
		<h2 style="margin-top:40px;margin-bottom:12px;"><?php esc_html_e( 'Activiteit per outlet', 'nieuws-distributie-systeem' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th style="width:30px;">#</th>
				<th><?php esc_html_e( 'Outlet', 'nieuws-distributie-systeem' ); ?></th>
				<th style="width:100px;text-align:center;"><?php esc_html_e( 'Ontvangen', 'nieuws-distributie-systeem' ); ?></th>
				<th style="width:100px;text-align:center;"><?php esc_html_e( 'Opens', 'nieuws-distributie-systeem' ); ?></th>
				<th style="width:100px;text-align:center;"><?php esc_html_e( 'Downloads', 'nieuws-distributie-systeem' ); ?></th>
				<th style="width:160px;"><?php esc_html_e( 'Open-rate', 'nieuws-distributie-systeem' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			$rank = 1;
			$max_opens = max( array_column( $outlet_stats, 'opens' ) ) ?: 1;
			foreach ( $outlet_stats as $email => $os ) :
				$rate = $os['received'] > 0 ? round( $os['opens'] / $os['received'] * 100 ) : 0;
				$bar_w = round( $os['opens'] / $max_opens * 100 );
			?>
			<tr>
				<td><strong><?php echo $rank++; ?></strong></td>
				<td>
					<strong><?php echo esc_html( $os['name'] ); ?></strong>
					<br><small style="color:#888;"><?php echo esc_html( $email ); ?></small>
				</td>
				<td style="text-align:center;"><?php echo (int) $os['received']; ?></td>
				<td style="text-align:center;font-weight:600;color:#3b82f6;"><?php echo (int) $os['opens']; ?></td>
				<td style="text-align:center;"><?php echo (int) $os['downloads']; ?></td>
				<td>
					<div style="display:flex;align-items:center;gap:8px;">
						<div style="flex:1;background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">
							<div style="background:<?php echo $rate >= 70 ? '#10b981' : ($rate >= 40 ? '#f59e0b' : '#ef4444'); ?>;height:100%;width:<?php echo $bar_w; ?>%;border-radius:4px;"></div>
						</div>
						<span style="font-size:12px;color:#888;min-width:36px;"><?php echo $rate; ?>%</span>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php
	}

	// ── AJAX: Update outlet role inline ──────────────────────────────────────

	public function ajax_update_outlet_role(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}
		$index = absint( $_POST['index'] ?? -1 );
		$role  = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'default' ) );
		if ( ! in_array( $role, [ 'default', 'partner', 'redacteur' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Ongeldige rol.', 'nieuws-distributie-systeem' ) ] );
		}
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		if ( ! isset( $outlets[ $index ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Outlet niet gevonden.', 'nieuws-distributie-systeem' ) ] );
		}
		$outlets[ $index ]['role'] = $role;
		update_option( self::OPTION_OUTLETS, $outlets );
		wp_send_json_success( [
			'message' => sprintf( __( 'Rol van %s gewijzigd naar %s.', 'nieuws-distributie-systeem' ), $outlets[ $index ]['name'], $role ),
			'role'    => $role,
		] );
	}

	// ── AJAX: Bewerk outlet ───────────────────────────────────────────────────
	public function ajax_edit_outlet(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

		$index = absint( $_POST['index'] ?? -1 );
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		if ( ! isset( $outlets[ $index ] ) ) {
			wp_send_json_error( [ 'message' => 'Outlet niet gevonden.' ] );
		}

		// On GET: return current data
		if ( ( $_POST['mode'] ?? '' ) === 'get' ) {
			wp_send_json_success( $outlets[ $index ] );
		}

		// On SAVE: update all fields
		$name    = sanitize_text_field( wp_unslash( $_POST['name']              ?? '' ) );
		$email   = sanitize_email(       wp_unslash( $_POST['email']             ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['phone']             ?? '' ) );
		$group   = sanitize_text_field( wp_unslash( $_POST['group']             ?? '' ) );
		$role    = sanitize_text_field( wp_unslash( $_POST['role']              ?? 'default' ) );
		$notes   = sanitize_textarea_field( wp_unslash( $_POST['notes']         ?? '' ) );
		$cn      = sanitize_textarea_field( wp_unslash( $_POST['custom_email_note'] ?? '' ) );

		if ( empty( $name ) || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'Naam en e-mail zijn verplicht.' ] );
		}
		if ( ! in_array( $role, [ 'default', 'partner', 'redacteur' ], true ) ) $role = 'default';

		// Check email uniqueness (allow same email for same index)
		foreach ( $outlets as $i => $o ) {
			if ( $i !== $index && strtolower( $o['email'] ) === strtolower( $email ) ) {
				wp_send_json_error( [ 'message' => 'Dit e-mailadres is al in gebruik.' ] );
			}
		}

		$outlets[ $index ] = array_merge( $outlets[ $index ], [
			'name'              => $name,
			'email'             => $email,
			'phone'             => $phone,
			'group'             => $group,
			'role'              => $role,
			'notes'             => $notes,
			'custom_email_note' => $cn,
		] );
		update_option( self::OPTION_OUTLETS, $outlets );
		wp_send_json_success( [ 'message' => "Outlet '{$name}' opgeslagen.", 'outlet' => $outlets[ $index ] ] );
	}

	// ── AJAX: Stuur toegangslink per e-mail ───────────────────────────────────
	public function ajax_send_access_link(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );

		$index   = absint( $_POST['index'] ?? -1 );
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		if ( ! isset( $outlets[ $index ] ) ) {
			wp_send_json_error( [ 'message' => 'Outlet niet gevonden.' ] );
		}

		$outlet     = $outlets[ $index ];
		$portal_url = home_url( '/persportaal/?access_code=' . rawurlencode( $outlet['access_code'] ) );
		$from_name  = get_option( 'snd_email_from_name',  get_bloginfo( 'name' ) );
		$from_email = get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) );
		$site_name  = get_bloginfo( 'name' );
		$site_url   = home_url();

		$html = '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Toegang ' . esc_html( $site_name ) . ' Persportaal</title></head>'
			. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center" style="padding:28px 16px;">'
			. '<table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;">'

			// Header
			. '<tr><td bgcolor="#1e293b" style="padding:28px 32px;border-radius:10px 10px 0 0;">'
			. '<p style="margin:0 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748b;">Persportaal toegang</p>'
			. '<h1 style="margin:0;font-size:24px;font-weight:700;color:#fff;line-height:1.3;">' . esc_html( $site_name ) . '</h1>'
			. '</td></tr>'

			// Welcome
			. '<tr><td bgcolor="#fff" style="padding:32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">'
			. '<p style="margin:0 0 20px;font-size:16px;color:#334155;">Beste redactie van <strong>' . esc_html( $outlet['name'] ) . '</strong>,</p>'
			. '<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#475569;">Welkom bij het persportaal van ' . esc_html( $site_name ) . '. Via dit portaal ontvangt u al onze persberichten inclusief hoge-resolutie persfoto\'s, direct in uw eigen beveiligde omgeving.</p>'

			// CTA button
			. '<table border="0" cellpadding="0" cellspacing="0" style="margin-bottom:32px;">'
			. '<tr><td bgcolor="#3b82f6" style="border-radius:8px;">'
			. '<a href="' . esc_url( $portal_url ) . '" target="_blank" style="display:inline-block;padding:15px 32px;font-size:16px;font-weight:700;color:#fff;text-decoration:none;">Naar uw persportaal →</a>'
			. '</td></tr></table>'

			// How it works section
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">'
			. '<tr><td style="padding:20px 24px;">'
			. '<p style="margin:0 0 16px;font-size:14px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:.6px;">Hoe werkt het portaal?</p>'
			// Step 1
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">'
			. '<tr>'
			. '<td width="36" valign="top" style="padding-top:1px;"><div style="width:28px;height:28px;background:#3b82f6;border-radius:50%;text-align:center;line-height:28px;font-size:13px;font-weight:700;color:#fff;">1</div></td>'
			. '<td style="padding-left:12px;"><p style="margin:0 0 3px;font-size:14px;font-weight:600;color:#1e293b;">Persoonlijke link</p><p style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">Uw portaal is toegankelijk via de bovenstaande knop. De link is uniek voor uw redactie — deel deze niet met anderen.</p></td>'
			. '</tr></table>'
			// Step 2
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">'
			. '<tr>'
			. '<td width="36" valign="top" style="padding-top:1px;"><div style="width:28px;height:28px;background:#10b981;border-radius:50%;text-align:center;line-height:28px;font-size:13px;font-weight:700;color:#fff;">2</div></td>'
			. '<td style="padding-left:12px;"><p style="margin:0 0 3px;font-size:14px;font-weight:600;color:#1e293b;">Persberichten ontvangen</p><p style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">Zodra wij een persbericht versturen ontvangt u een e-mail. Klik op de link in de mail om het volledige artikel te lezen, inclusief achtergrondinfo en context.</p></td>'
			. '</tr></table>'
			// Step 3
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">'
			. '<tr>'
			. '<td width="36" valign="top" style="padding-top:1px;"><div style="width:28px;height:28px;background:#f59e0b;border-radius:50%;text-align:center;line-height:28px;font-size:13px;font-weight:700;color:#fff;">3</div></td>'
			. '<td style="padding-left:12px;"><p style="margin:0 0 3px;font-size:14px;font-weight:600;color:#1e293b;">Persfoto\'s downloaden</p><p style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">Bij elk bericht staan watermerkvrije persfoto\'s in hoge resolutie. U kunt foto\'s afzonderlijk downloaden of als ZIP-archief. Vermelding: <em>Foto: ' . esc_html( $site_name ) . '</em></p></td>'
			. '</tr></table>'
			// Step 4
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%">'
			. '<tr>'
			. '<td width="36" valign="top" style="padding-top:1px;"><div style="width:28px;height:28px;background:#8b5cf6;border-radius:50%;text-align:center;line-height:28px;font-size:13px;font-weight:700;color:#fff;">4</div></td>'
			. '<td style="padding-left:12px;"><p style="margin:0 0 3px;font-size:14px;font-weight:600;color:#1e293b;">Live updates & vragen stellen</p><p style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">Bij actieve incidenten ziet u real-time de status (fotograaf onderweg / ter plaatse). Via de chat-functie kunt u direct vragen stellen aan onze redactie.</p></td>'
			. '</tr></table>'
			. '</td></tr></table>'

			// Tips section
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;">'
			. '<tr><td style="padding:16px 20px;">'
			. '<p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#92400e;">💡 Tips voor gebruik</p>'
			. '<ul style="margin:0;padding-left:18px;font-size:13px;color:#78350f;line-height:1.8;">'
			. '<li>Sla de portaallink op als bladwijzer in uw browser</li>'
			. '<li>Het portaal werkt op desktop, tablet en smartphone</li>'
			. '<li>Nieuwe berichten verschijnen automatisch — u hoeft de pagina niet te verversen</li>'
			. '<li>Foto\'s zijn vrij te gebruiken met naamsvermelding</li>'
			. '<li>Heeft u vragen? Gebruik de chat in het portaal of mail naar <a href="mailto:' . esc_attr( $from_email ) . '" style="color:#92400e;">' . esc_html( $from_email ) . '</a></li>'
			. '</ul>'
			. '</td></tr></table>'

			// Security notice
			. '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:0;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;">'
			. '<tr><td style="padding:14px 18px;">'
			. '<p style="margin:0;font-size:12px;color:#991b1b;line-height:1.6;">🔒 <strong>Beveiligingsmelding:</strong> Deze link geeft directe toegang tot het portaal zonder wachtwoord. Bewaar hem veilig en deel hem niet met derden. Vermoed u misbruik? Neem dan contact op zodat wij een nieuwe link voor u aanmaken.</p>'
			. '</td></tr></table>'
			. '</td></tr>'

			// Footer
			. '<tr><td bgcolor="#f1f5f9" style="padding:20px 32px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;text-align:center;">'
			. '<p style="margin:0 0 6px;font-size:13px;color:#64748b;">' . esc_html( $site_name ) . ' &mdash; Persportaal</p>'
			. '<p style="margin:0;font-size:12px;color:#94a3b8;">&copy; ' . date('Y') . ' ' . esc_html( $site_name ) . ' &mdash; <a href="' . esc_url( $site_url ) . '" style="color:#94a3b8;">' . esc_html( $site_url ) . '</a></p>'
			. '</td></tr>'

			. '</table></td></tr></table></body></html>';

		add_filter( 'wp_mail_content_type', function(){ return 'text/html'; } );
		$ok = wp_mail(
			$outlet['email'],
			'Welkom bij het ' . $site_name . ' persportaal — uw toegangslink',
			$html,
			[ "From: {$from_name} <{$from_email}>" ]
		);
		remove_all_filters( 'wp_mail_content_type' );

		if ( $ok ) {
			wp_send_json_success( [ 'message' => 'Welkomstmail verstuurd naar ' . $outlet['email'] ] );
		} else {
			wp_send_json_error( [ 'message' => 'Verzenden mislukt.' ] );
		}
	}

	// ── AJAX: Dashboard snelstatistieken ──────────────────────────────────────
	public function ajax_get_dashboard_stats(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$today_start = strtotime( 'today midnight' );
		$week_start  = strtotime( 'monday this week midnight' );

		$dispatched_today = 0;
		$dispatched_week  = 0;
		$opens_today      = 0;
		$opens_week       = 0;
		$downloads_today  = 0;
		$downloads_week   = 0;

		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_DISPATCH_LOG, 'compare' => 'EXISTS' ] ],
		] );

		foreach ( $query->posts as $pid ) {
			$dlog = get_post_meta( $pid, self::META_DISPATCH_LOG, true );
			$alog = get_post_meta( $pid, self::META_ACCESS_LOG, true );
			if ( ! is_array( $dlog ) ) continue;

			// Dispatch time = first entry
			$first = reset( $dlog );
			$ts    = $first['timestamp'] ?? get_post_timestamp( $pid );
			if ( $ts >= $today_start ) $dispatched_today++;
			if ( $ts >= $week_start )  $dispatched_week++;

			// Views + downloads
			if ( is_array( $alog ) ) {
				foreach ( $alog as $l ) {
					foreach ( $l['views'] ?? [] as $vts ) {
						if ( $vts >= $today_start ) $opens_today++;
						if ( $vts >= $week_start )  $opens_week++;
					}
					foreach ( $l['downloads'] ?? [] as $dl ) {
						$dts = $dl['time'] ?? 0;
						if ( $dts >= $today_start ) $downloads_today++;
						if ( $dts >= $week_start )  $downloads_week++;
					}
				}
			}
		}

		$active_count   = count( get_option( 'snd_p2000_active_statuses', [] ) );
		$outlet_count   = count( get_option( self::OPTION_OUTLETS, [] ) );
		$p2000_feeds    = get_option( 'snd_p2000_feeds_structured', [] );
		$p2000_total    = 0;
		foreach ( $p2000_feeds as $msgs ) $p2000_total += count( $msgs );

		wp_send_json_success( [
			'dispatched_today' => $dispatched_today,
			'dispatched_week'  => $dispatched_week,
			'opens_today'      => $opens_today,
			'opens_week'       => $opens_week,
			'downloads_today'  => $downloads_today,
			'downloads_week'   => $downloads_week,
			'active_meldingen' => $active_count,
			'outlets'          => $outlet_count,
			'p2000_in_feed'    => $p2000_total,
		] );
	}

	// ── AJAX: ETA instellen op WP post (minuten + timestamp) ─────────────────

	public function ajax_set_post_eta(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$minutes = absint( $_POST['minutes'] ?? 0 );
		if ( $minutes > 0 ) {
			update_post_meta( $post_id, '_snd_eta_minutes', $minutes );
			update_post_meta( $post_id, '_snd_eta_set_at', time() );
		} else {
			delete_post_meta( $post_id, '_snd_eta_minutes' );
			delete_post_meta( $post_id, '_snd_eta_set_at' );
		}
		wp_send_json_success( [ 'minutes' => $minutes, 'set_at' => time() ] );
	}

	// ── AJAX: Incident vastzetten / losmaken ──────────────────────────────────

	public function ajax_pin_post(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$pinned  = ! empty( get_post_meta( $post_id, '_snd_pinned', true ) );
		if ( $pinned ) {
			delete_post_meta( $post_id, '_snd_pinned' );
		} else {
			update_post_meta( $post_id, '_snd_pinned', '1' );
		}
		wp_send_json_success( [ 'pinned' => ! $pinned ] );
	}

	// ── AJAX: Live viewers op post bijhouden ──────────────────────────────────

	public function ajax_record_post_view(): void {
		// No nonce required — just a heartbeat, called from portal
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error();
		// Store last 10 view timestamps
		$views = get_post_meta( $post_id, '_snd_recent_views', true );
		if ( ! is_array( $views ) ) $views = [];
		$cutoff = time() - 300; // 5 minutes
		$views  = array_filter( $views, fn( $t ) => $t > $cutoff );
		$views[] = time();
		update_post_meta( $post_id, '_snd_recent_views', array_values( $views ) );
		$count = count( $views );
		update_post_meta( $post_id, '_snd_live_viewers', $count );
		wp_send_json_success( [ 'count' => $count ] );
	}

	// ── AJAX: Dispatch notitie opslaan ────────────────────────────────────────

	public function ajax_save_dispatch_note(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$note    = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		update_post_meta( $post_id, '_snd_dispatch_note', $note );
		wp_send_json_success();
	}

	public function ajax_get_live_viewers(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'publish_posts' ) ) wp_send_json_error( [], 403 );

		$cutoff  = time() - 300; // last 5 minutes
		$viewers = [];

		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_ACCESS_LOG, 'compare' => 'EXISTS' ] ],
		] );

		foreach ( $query->posts as $pid ) {
			$log = get_post_meta( $pid, self::META_ACCESS_LOG, true );
			if ( ! is_array( $log ) ) continue;
			foreach ( $log as $email => $data ) {
				foreach ( $data['views'] ?? [] as $ts ) {
					if ( $ts >= $cutoff ) {
						$viewers[ $email ] = $data['name'] ?? $email;
						break;
					}
				}
			}
		}

		wp_send_json_success( [ 'count' => count( $viewers ), 'names' => array_values( $viewers ) ] );
	}

	public function ajax_get_map_data(): void {
		$this->verify_nonce();

		// Published posts with lat/lon
		$posts_query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 200,
			'post_status'    => 'publish',
			'meta_query'     => [
				[ 'key' => self::META_LAT, 'compare' => 'EXISTS' ],
				[ 'key' => self::META_LON, 'compare' => 'EXISTS' ],
			],
		] );

		$incidents = [];
		if ( $posts_query->have_posts() ) {
			while ( $posts_query->have_posts() ) {
				$posts_query->the_post();
				$pid = get_the_ID();
				$lat = (float) get_post_meta( $pid, self::META_LAT, true );
				$lon = (float) get_post_meta( $pid, self::META_LON, true );
				if ( ! $lat || ! $lon ) continue;

				$p2000_id   = get_post_meta( $pid, self::META_P2000_ID, true );
				$dispatched = ! empty( get_post_meta( $pid, self::META_DISPATCH_LOG, true ) );

				$incidents[] = [
					'id'         => $pid,
					'title'      => get_the_title(),
					'lat'        => $lat,
					'lon'        => $lon,
					'street'     => get_post_meta( $pid, self::META_STREET1, true ),
					'date'       => get_the_date( 'd-m-Y H:i' ),
					'edit_url'   => get_edit_post_link( $pid ),
					'post_url'   => get_permalink( $pid ),
					'p2000_id'   => $p2000_id,
					'dispatched' => $dispatched,
					'is_live'    => get_post_meta( $pid, self::META_IS_LIVE, true ) === '1',
				];
			}
			wp_reset_postdata();
		}

		// P2000 feed markers (unlinked only)
		$feeds    = get_option( 'snd_p2000_feeds_structured', [] );
		$all_msgs = array_merge( $feeds['brandweer'] ?? [], $feeds['politie'] ?? [], $feeds['mmt'] ?? [] );
		// Collect all P2000 IDs already linked to posts
		$linked_ids = array_filter( array_column( $incidents, 'p2000_id' ) );

		$p2000_markers = [];
		foreach ( $all_msgs as $msg ) {
			if ( ! is_numeric( $msg["lat"] ?? null ) || ! is_numeric( $msg["lon"] ?? null ) ) continue;
			$already_linked = in_array( $msg['id'], $linked_ids, true );
			$p2000_markers[] = [
				'id'      => $msg['id'],
				'lat'     => (float) $msg['lat'],
				'lon'     => (float) $msg['lon'],
				'tekst'   => $msg['tekst'] ?? '',
				'stad'    => $msg['stad'] ?? '',
				'straat'  => $msg['straat'] ?? '',
				'type'    => $msg['type'] ?? 'overig',
				'tijd'    => isset( $msg['tijd'] ) ? wp_date( 'H:i', $msg['tijd'] ) : '',
				'linked'  => $already_linked,
				'new_post_url' => add_query_arg( [
					'snd_lat'               => $msg['lat'],
					'snd_lon'               => $msg['lon'],
					'snd_street1'           => rawurlencode( $msg['straat'] ?? '' ),
					'snd_p2000_id'          => rawurlencode( $msg['id'] ),
					'snd_p2000_raw_message' => rawurlencode( $msg['tekst'] ?? '' ),
				], admin_url( 'post-new.php' ) ),
			];
		}

		wp_send_json_success( [
			'incidents'     => $incidents,
			'p2000_markers' => $p2000_markers,
		] );
	}

	// ── AJAX: Link P2000 melding to existing post ─────────────────────────────

	public function ajax_link_p2000_to_post(): void {
		$this->verify_nonce();
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$p2000_id = sanitize_text_field( wp_unslash( $_POST['p2000_id'] ?? '' ) );

		if ( ! $post_id || ! $p2000_id ) {
			wp_send_json_error( [ 'message' => __( 'Ongeldige parameters.', 'nieuws-distributie-systeem' ) ] );
		}

		// Find the P2000 message
		$feeds = get_option( 'snd_p2000_feeds_structured', [] );
		$msg   = null;
		foreach ( $feeds as $type => $messages ) {
			if ( isset( $messages[ $p2000_id ] ) ) {
				$msg = $messages[ $p2000_id ];
				break;
			}
		}
		if ( ! $msg ) {
			wp_send_json_error( [ 'message' => __( 'P2000 melding niet gevonden.', 'nieuws-distributie-systeem' ) ] );
		}

		// Update post meta
		update_post_meta( $post_id, self::META_P2000_ID,  $p2000_id );
		update_post_meta( $post_id, self::META_P2000_RAW, $msg['tekst'] ?? '' );
		if ( is_numeric( $msg['lat'] ?? '' ) ) update_post_meta( $post_id, self::META_LAT, $msg['lat'] );
		if ( is_numeric( $msg['lon'] ?? '' ) ) update_post_meta( $post_id, self::META_LON, $msg['lon'] );
		if ( ! empty( $msg['straat'] ) ) update_post_meta( $post_id, self::META_STREET1, $msg['straat'] );

		wp_send_json_success( [
			'message' => sprintf( __( 'P2000 melding "%s" gekoppeld aan dit bericht.', 'nieuws-distributie-systeem' ), $msg['tekst'] ?? $p2000_id ),
			'lat'     => $msg['lat'] ?? '',
			'lon'     => $msg['lon'] ?? '',
			'street'  => $msg['straat'] ?? '',
		] );
	}

	// ── AJAX: Update post location from map ───────────────────────────────────

	public function ajax_update_post_location(): void {
		$this->verify_nonce();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$lat     = sanitize_text_field( wp_unslash( $_POST['lat'] ?? '' ) );
		$lon     = sanitize_text_field( wp_unslash( $_POST['lon'] ?? '' ) );
		$street  = sanitize_text_field( wp_unslash( $_POST['street'] ?? '' ) );

		if ( ! $post_id || ! $lat || ! $lon ) {
			wp_send_json_error( [ 'message' => __( 'Ongeldige parameters.', 'nieuws-distributie-systeem' ) ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen permissie.', 'nieuws-distributie-systeem' ) ], 403 );
		}

		update_post_meta( $post_id, self::META_LAT,     $lat );
		update_post_meta( $post_id, self::META_LON,     $lon );
		update_post_meta( $post_id, self::META_STREET1, $street );

		wp_send_json_success( [ 'message' => __( 'Locatie bijgewerkt.', 'nieuws-distributie-systeem' ) ] );
	}

	// ── Page: Map overview ────────────────────────────────────────────────────

	public function page_map(): void {
		// Get all published posts for the link-to-post dropdown
		$posts_query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		$posts_list = [];
		if ( $posts_query->have_posts() ) {
			while ( $posts_query->have_posts() ) {
				$posts_query->the_post();
				$posts_list[] = [ 'id' => get_the_ID(), 'title' => get_the_title() ];
			}
			wp_reset_postdata();
		}
		?>
		<div class="wrap snd-admin-wrap" style="margin-right:0;">
			<h1 style="margin-bottom:8px;"><?php esc_html_e( 'Kaartoverzicht', 'nieuws-distributie-systeem' ); ?></h1>
		</div>

		<div class="snd-map-wrap" id="snd-map-fullwrap">
			<!-- Controls sidebar -->
			<div class="snd-map-sidebar">

				<div class="snd-map-legend">
					<h3><?php esc_html_e( 'Legenda', 'nieuws-distributie-systeem' ); ?></h3>
					<div class="snd-legend-item"><span class="snd-legend-dot" style="background:#3b82f6;border-radius:50%;"></span><?php esc_html_e( 'Incident (ongepubliceerd)', 'nieuws-distributie-systeem' ); ?></div>
					<div class="snd-legend-item"><span class="snd-legend-dot" style="background:#10b981;border-radius:50%;"></span><?php esc_html_e( 'Incident (verzonden)', 'nieuws-distributie-systeem' ); ?></div>
					<div class="snd-legend-item"><span class="snd-legend-dot" style="background:#ef4444;border-radius:2px;transform:rotate(45deg);"></span><?php esc_html_e( 'P2000 Brandweer', 'nieuws-distributie-systeem' ); ?></div>
					<div class="snd-legend-item"><span class="snd-legend-dot" style="background:#f59e0b;border-radius:2px;transform:rotate(45deg);"></span><?php esc_html_e( 'P2000 Politie', 'nieuws-distributie-systeem' ); ?></div>
					<div class="snd-legend-item"><span class="snd-legend-dot" style="background:#8b5cf6;border-radius:2px;transform:rotate(45deg);"></span><?php esc_html_e( 'P2000 MMT', 'nieuws-distributie-systeem' ); ?></div>
					<div class="snd-legend-item"><span class="snd-legend-dot" style="background:#10b981;border-radius:2px;"></span><?php esc_html_e( 'P2000 (al gekoppeld)', 'nieuws-distributie-systeem' ); ?></div>
				</div>

				<div class="snd-map-filters">
					<h3><?php esc_html_e( 'Filters', 'nieuws-distributie-systeem' ); ?></h3>
					<label class="snd-map-filter-label"><input type="checkbox" id="filter-incidents" checked> <?php esc_html_e( 'Incidenten tonen', 'nieuws-distributie-systeem' ); ?></label>
					<label class="snd-map-filter-label"><input type="checkbox" id="filter-p2000" checked> <?php esc_html_e( 'P2000 meldingen tonen', 'nieuws-distributie-systeem' ); ?></label>
					<label class="snd-map-filter-label"><input type="checkbox" id="filter-linked"> <?php esc_html_e( 'Verberg gekoppelde P2000', 'nieuws-distributie-systeem' ); ?></label>
				</div>

				<button class="button button-primary" id="snd-map-refresh" style="width:100%;margin-top:4px;">
					↺ <?php esc_html_e( 'Ververs kaart', 'nieuws-distributie-systeem' ); ?>
				</button>

				<div id="snd-map-loading-indicator" style="text-align:center;padding:12px 0;display:none;">
					<span class="spinner is-active" style="float:none;"></span>
					<span style="font-size:12px;color:#888;display:block;margin-top:4px;"><?php esc_html_e( 'Laden…', 'nieuws-distributie-systeem' ); ?></span>
				</div>

				<div id="snd-map-selected-info" class="snd-map-selected-panel" style="display:none;">
					<h4 id="snd-map-sel-title" style="word-break:break-word;"></h4>
					<div id="snd-map-sel-body"></div>
				</div>

				<div style="margin-top:auto;padding-top:12px;border-top:1px solid #e2e4e7;">
					<p style="font-size:11px;color:#888;margin:0;" id="snd-map-stats"></p>
				</div>
			</div>

			<!-- Map canvas -->
			<div id="snd-admin-map"></div>
		</div>

		<!-- Link P2000 to post modal -->
		<div id="snd-link-modal" class="snd-modal-backdrop" style="display:none;" role="dialog">
			<div class="snd-modal-box" style="max-width:500px;">
				<div class="snd-modal-header">
					<h2><?php esc_html_e( 'P2000 melding koppelen aan bericht', 'nieuws-distributie-systeem' ); ?></h2>
					<button class="snd-modal-close" aria-label="Sluiten">&times;</button>
				</div>
				<div class="snd-modal-body">
					<p class="snd-link-p2000-tekst" style="background:#f8fafc;border-left:3px solid #3b82f6;padding:10px 14px;border-radius:4px;font-size:13px;margin-bottom:16px;font-style:italic;color:#475569;"></p>

					<label style="font-weight:600;display:block;margin-bottom:6px;"><?php esc_html_e( 'Koppel aan bestaand bericht:', 'nieuws-distributie-systeem' ); ?></label>
					<select id="snd-link-post-select" class="widefat" style="margin-bottom:16px;">
						<option value=""><?php esc_html_e( '— Selecteer bericht —', 'nieuws-distributie-systeem' ); ?></option>
						<?php foreach ( $posts_list as $p ) : ?>
							<option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html( $p['title'] ); ?></option>
						<?php endforeach; ?>
					</select>

					<p style="text-align:center;color:#aaa;font-size:12px;margin:0 0 12px;">— of —</p>
					<a id="snd-link-new-post" href="#" class="button widefat" style="text-align:center;display:block;" target="_blank">
						<?php esc_html_e( '+ Maak nieuw bericht op basis van melding', 'nieuws-distributie-systeem' ); ?>
					</a>
				</div>
				<div class="snd-modal-footer">
					<div class="snd-modal-status"><span id="snd-link-status"></span></div>
					<div class="snd-modal-actions">
						<button class="button button-primary" id="snd-link-confirm"><?php esc_html_e( 'Koppelen', 'nieuws-distributie-systeem' ); ?></button>
						<button class="button snd-modal-close"><?php esc_html_e( 'Annuleren', 'nieuws-distributie-systeem' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Edit location modal -->
		<div id="snd-editloc-modal" class="snd-modal-backdrop" style="display:none;" role="dialog">
			<div class="snd-modal-box wide" style="max-width:720px;">
				<div class="snd-modal-header">
					<h2><?php esc_html_e( 'Locatie aanpassen', 'nieuws-distributie-systeem' ); ?></h2>
					<button class="snd-modal-close">&times;</button>
				</div>
				<div class="snd-modal-body">
					<div style="display:flex;gap:8px;margin-bottom:10px;">
						<input type="text" id="snd-editloc-search" class="regular-text" placeholder="<?php esc_attr_e( 'Zoek adres of kruispunt…', 'nieuws-distributie-systeem' ); ?>" style="flex:1;">
						<button class="button" id="snd-editloc-search-btn"><?php esc_html_e( 'Zoek', 'nieuws-distributie-systeem' ); ?></button>
						<span class="spinner" id="snd-editloc-search-spinner" style="float:none;vertical-align:middle;"></span>
					</div>
					<div id="snd-editloc-map" style="height:380px;border-radius:6px;overflow:hidden;border:1px solid #e2e4e7;"></div>
					<div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:12px;margin-top:12px;">
						<label><strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Latitude', 'nieuws-distributie-systeem' ); ?></strong><input type="text" id="snd-editloc-lat" class="widefat" readonly style="font-family:monospace;font-size:12px;"></label>
						<label><strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Longitude', 'nieuws-distributie-systeem' ); ?></strong><input type="text" id="snd-editloc-lon" class="widefat" readonly style="font-family:monospace;font-size:12px;"></label>
						<label><strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Straat (wordt automatisch ingevuld)', 'nieuws-distributie-systeem' ); ?></strong><input type="text" id="snd-editloc-street" class="widefat"></label>
					</div>
					<p style="font-size:12px;color:#888;margin:8px 0 0;">💡 <?php esc_html_e( 'Klik op de kaart of sleep de marker om de locatie te verplaatsen.', 'nieuws-distributie-systeem' ); ?></p>
				</div>
				<div class="snd-modal-footer">
					<div class="snd-modal-status"><span id="snd-editloc-status"></span></div>
					<div class="snd-modal-actions">
						<button class="button button-primary" id="snd-editloc-save"><?php esc_html_e( 'Locatie opslaan', 'nieuws-distributie-systeem' ); ?></button>
						<button class="button snd-modal-close"><?php esc_html_e( 'Annuleren', 'nieuws-distributie-systeem' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<style>
		/* Override WP admin constraints specifically for map page */
		#wpbody-content { padding-bottom: 0 !important; }
		.snd-map-wrap {
			position: fixed !important;
			top: 0; left: 0; right: 0; bottom: 0;
			margin: 0 !important;
			border-radius: 0 !important;
			border: none !important;
			z-index: 1;
		}
		/* Shift down to clear WP toolbar + page title */
		body.wp-toolbar .snd-map-wrap { top: 32px; }
		#wpadminbar ~ #wpwrap .snd-map-wrap { top: 32px; }
		.snd-map-wrap { top: 76px; } /* toolbar (32) + page h1 row (44) */
		</style>
		<?php
	}

	public function page_access_control(): void {
		?>
		<div class="wrap snd-admin-wrap">
			<h1><?php esc_html_e( 'Toegangsbeheer', 'nieuws-distributie-systeem' ); ?></h1>
			<p><?php esc_html_e( 'Beheer per bericht wie er toegang heeft tot het persportaal en foto\'s. Blokkeren werkt direct — de outlet kan het bericht niet meer openen, ook niet met hun link.', 'nieuws-distributie-systeem' ); ?></p>

			<div id="snd-access-loading" style="text-align:center;padding:40px;">
				<span class="spinner is-active" style="float:none;"></span>
			</div>
			<div id="snd-access-content" style="display:none;"></div>
		</div>

		<script>
		(function($) {
			$.post(ajaxurl, { action: 'snd_get_access_control', nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?> })
			.done(function(res) {
				$('#snd-access-loading').hide();
				if (!res.success || !res.data.rows.length) {
					$('#snd-access-content').html('<p><?php echo esc_js( __( 'Geen verzonden berichten gevonden.', 'nieuws-distributie-systeem' ) ); ?></p>').show();
					return;
				}
				var html = '';
				res.data.rows.forEach(function(row) {
					html += '<div class="snd-accordion-item" style="margin-bottom:10px;">';
					html += '<div class="snd-accordion-header snd-static-header">';
					html += '<span><strong>' + escHtml(row.title) + '</strong> &mdash; <small>' + row.date + '</small></span>';
					html += '<span><small>' + row.sent_to.length + ' ontvangers</small></span>';
					html += '</div><div class="snd-accordion-body" style="display:block;padding:0;">';
					html += '<table class="wp-list-table widefat striped" style="margin:0;"><tbody>';
					row.sent_to.forEach(function(email) {
						var outlet = res.data.outlets.find(o => o.email === email) || { name: email };
						var blocked = row.blocked.includes(email);
						html += '<tr style="' + (blocked ? 'opacity:.55;' : '') + '">';
						html += '<td style="padding:8px 12px;"><strong>' + escHtml(outlet.name) + '</strong><br><small>' + escHtml(email) + '</small></td>';
						html += '<td style="padding:8px 12px;">';
						if (blocked) {
							html += '<span style="color:#b32d2e;font-weight:600;">🔒 Geblokkeerd</span>';
						} else {
							html += '<span style="color:#1a7239;font-weight:600;">✓ Toegang OK</span>';
						}
						html += '</td><td style="padding:8px 12px;">';
						if (blocked) {
							html += '<button class="button button-small snd-access-toggle" data-postid="' + row.post_id + '" data-email="' + escHtml(email) + '" data-block="0">🔓 Herstel toegang</button>';
						} else {
							html += '<button class="button button-small snd-access-toggle" data-postid="' + row.post_id + '" data-email="' + escHtml(email) + '" data-block="1">🔒 Blokkeer download</button>';
						}
						html += '</td></tr>';
					});
					html += '</tbody></table></div></div>';
				});
				$('#snd-access-content').html(html).show();
			});

			$(document).on('click', '.snd-access-toggle', function() {
				var btn    = $(this);
				var postId = btn.data('postid');
				var email  = btn.data('email');
				var block  = parseInt(btn.data('block'), 10);
				btn.prop('disabled', true).text('…');
				$.post(ajaxurl, {
					action: 'snd_toggle_outlet_block',
					nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>,
					post_id: postId,
					email: email,
					block: block
				}).done(function(res) {
					if (res.success) {
						// Refresh the whole block
						location.reload();
					} else {
						alert(res.data.message || 'Fout.');
						btn.prop('disabled', false);
					}
				});
			});

			function escHtml(str) {
				return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}
		})(jQuery);
		</script>
		<?php
	}

	// ── Page: Settings ────────────────────────────────────────────────────────

	public function page_settings(): void {
		$outlets   = get_option( self::OPTION_OUTLETS, [] );
		$active    = sanitize_text_field( $_GET['tab'] ?? 'p2000' );
		$updated   = isset( $_GET['updated'] );
		?>
		<div class="wrap snd-admin-wrap">
			<h1><?php esc_html_e( 'Media Outlets & Instellingen', 'nieuws-distributie-systeem' ); ?></h1>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Instellingen opgeslagen.', 'nieuws-distributie-systeem' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php
				$tabs = [
					'outlets'  => __( 'Media Outlets', 'nieuws-distributie-systeem' ),
					'p2000'    => __( 'P2000 & Feeds', 'nieuws-distributie-systeem' ),
					'email'    => __( 'E-mail & Portaal', 'nieuws-distributie-systeem' ),
					'algemeen' => __( 'Algemeen', 'nieuws-distributie-systeem' ),
				];
				foreach ( $tabs as $slug => $label ) :
					$class = $active === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
					echo '<a href="' . esc_url( admin_url( 'admin.php?page=snd-settings&tab=' . $slug ) ) . '" class="' . $class . '">' . esc_html( $label ) . '</a>';
				endforeach;
				?>
			</nav>

			<?php if ( 'outlets' === $active ) : ?>
				<div class="snd-settings-columns">
					<div class="snd-col-main">
						<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
							<h2 style="margin:0;"><?php esc_html_e( 'Huidige perslijst', 'nieuws-distributie-systeem' ); ?></h2>
							<button type="button" id="snd-outlets-export-csv" class="button button-small" style="font-size:12px;">&#x2B07; Export CSV</button>
						</div>
						<?php if ( empty( $outlets ) ) : ?>
							<p><?php esc_html_e( 'Nog geen outlets.', 'nieuws-distributie-systeem' ); ?></p>
						<?php else : ?>
													<table class="wp-list-table widefat fixed striped" id="snd-outlets-table">
							<thead><tr>
								<th><?php esc_html_e( 'Naam', 'nieuws-distributie-systeem' ); ?></th>
								<th><?php esc_html_e( 'E-mail / Telefoon', 'nieuws-distributie-systeem' ); ?></th>
								<th><?php esc_html_e( 'Groep', 'nieuws-distributie-systeem' ); ?></th>
								<th style="width:145px;"><?php esc_html_e( 'Rol', 'nieuws-distributie-systeem' ); ?></th>
								<th><?php esc_html_e( 'Portaallink', 'nieuws-distributie-systeem' ); ?></th>
								<th style="width:100px;"><?php esc_html_e( 'Acties', 'nieuws-distributie-systeem' ); ?></th>
							</tr></thead>
							<tbody>
							<?php
							$send_link_nonce = wp_create_nonce( 'snd_nonce' );
							foreach ( $outlets as $i => $outlet ) :
								$is_partner = ( $outlet['role'] ?? 'default' ) === 'partner';
								$portal_url = home_url( '/persportaal/?access_code=' . rawurlencode( $outlet['access_code'] ?? '' ) );
								$added_str  = ! empty( $outlet['added'] ) ? wp_date( 'd-m-Y', $outlet['added'] ) : '';
							?>
								<tr id="outlet-row-<?php echo $i; ?>">
									<td>
										<strong><?php echo esc_html( $outlet['name'] ); ?></strong>
										<?php if ( ! empty( $outlet['notes'] ) ) : ?>
											<br><small style="color:#888;font-style:italic;"><?php echo esc_html( $outlet['notes'] ); ?></small>
										<?php endif; ?>
										<?php if ( $added_str ) : ?>
											<br><small style="color:#bbb;"><?php echo esc_html( $added_str ); ?></small>
										<?php endif; ?>
									</td>
									<td>
										<?php echo esc_html( $outlet['email'] ); ?>
										<?php if ( ! empty( $outlet['phone'] ) ) : ?>
											<br><small><?php echo esc_html( $outlet['phone'] ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $outlet['group'] ?: '\u2014' ); ?></td>
									<td>
										<select class="snd-role-select" data-index="<?php echo $i; ?>" style="width:100%;">
											<option value="default"   <?php selected( $is_partner ? 'partner' : ( $outlet['role'] ?? 'default' ), 'default' ); ?>><?php esc_html_e( 'Standaard', 'nieuws-distributie-systeem' ); ?></option>
											<option value="partner"   <?php selected( $outlet['role'] ?? '', 'partner' ); ?>><?php esc_html_e( 'Partner', 'nieuws-distributie-systeem' ); ?></option>
											<option value="redacteur" <?php selected( $outlet['role'] ?? '', 'redacteur' ); ?>><?php esc_html_e( 'Redacteur', 'nieuws-distributie-systeem' ); ?></option>
										</select>
										<span class="snd-role-saving" data-index="<?php echo $i; ?>" style="display:none;font-size:11px;color:#888;"><?php esc_html_e( 'Opslaan\u2026', 'nieuws-distributie-systeem' ); ?></span>
									</td>
									<td>
										<div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;">
											<button type="button" class="button-link snd-copy-portal-link"
												data-url="<?php echo esc_attr( $portal_url ); ?>"
												style="font-size:11px;" title="Kopieer portaallink">&#x1F4CB; Kopieer</button>
											<button type="button" class="button button-small snd-send-access-link"
												data-index="<?php echo $i; ?>"
												data-nonce="<?php echo esc_attr( $send_link_nonce ); ?>"
												style="font-size:11px;">&#x2709; Stuur link</button>
										</div>
									</td>
									<td class="snd-action-btns">
							<button type="button" class="button button-small snd-edit-outlet-btn" data-index="<?php echo $i; ?>" style="font-size:11px;">&#x270F; Bewerk</button>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'snd_settings_action', 'snd_nonce_field' ); ?>
											<input type="hidden" name="snd_action" value="regen_code">
											<input type="hidden" name="outlet_index" value="<?php echo $i; ?>">
											<button type="submit" class="button-link" title="Nieuwe code genereren"><span class="dashicons dashicons-update"></span></button>
										</form>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'snd_settings_action', 'snd_nonce_field' ); ?>
											<input type="hidden" name="snd_action" value="delete_outlet">
											<input type="hidden" name="outlet_index" value="<?php echo $i; ?>">
											<button type="submit" class="button-link-delete" onclick="return confirm('Verwijderen?')" title="Verwijderen"><span class="dashicons dashicons-trash"></span></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
							</table>
							<script>
							(function($){
								$('.snd-role-select').on('change', function() {
									var idx  = $(this).data('index');
									var role = $(this).val();
									var nonce = <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>;
									$('.snd-role-saving[data-index="' + idx + '"]').show();
									$.post(ajaxurl, { action: 'snd_update_outlet_role', nonce: nonce, index: idx, role: role })
									.done(function(res) {
										var $saving = $('.snd-role-saving[data-index="' + idx + '"]');
										if (res.success) {
											$saving.text('✓ Opgeslagen').css('color','#1a7239');
										} else {
											$saving.text('Fout').css('color','#b32d2e');
										}
										setTimeout(function() { $saving.hide().text('Opslaan…').css('color','#888'); }, 2000);
									})
									.fail(function() {
										$('.snd-role-saving[data-index="' + idx + '"]').hide();
										alert('Serverfout.');
									});
								});

								// ── Export outlets als CSV ───────────────────────────────────
							$('#snd-outlets-export-csv').on('click', function() {
								var BOM = '\uFEFF';
								var rows = [['Naam','E-mail','Telefoon','Groep','Rol','Toegevoegd']];
								$('#snd-outlets-table tbody tr').each(function() {
									var $td = $(this).find('td');
									var name  = $td.eq(0).find('strong').text().trim();
									var email = $td.eq(1).text().trim().split('\n')[0].trim();
									var phone = $td.eq(1).find('small').text().trim() || '';
									var group = $td.eq(2).text().trim();
									var role  = $td.eq(3).find('select').val() || $td.eq(3).text().trim();
									var added = $td.eq(0).find('small:last').text().trim() || '';
									rows.push([
										'"'+name.replace(/"/g,'""')+'"',
										email, phone,
										'"'+group.replace(/"/g,'""')+'"',
										role, added
									]);
								});
								var csv = BOM + rows.map(function(r){ return r.join(';'); }).join('\n');
								var a = document.createElement('a');
								a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
								a.download = 'perslijst-' + new Date().toISOString().slice(0,10) + '.csv';
								document.body.appendChild(a); a.click(); document.body.removeChild(a);
							});
								$(document).on('click', '.snd-copy-portal-link', function(){
									var url = $(this).data('url');
									var $btn = $(this);
									if (navigator.clipboard) {
										navigator.clipboard.writeText(url).then(function(){
											$btn.text('✓ Gekopieerd!').css('color','#16a34a');
											setTimeout(function(){ $btn.text('📋 Kopieer').css('color',''); }, 2000);
										});
									} else {
										// Fallback
										var $tmp = $('<input>').val(url).appendTo('body').select();
										document.execCommand('copy');
										$tmp.remove();
										$btn.text('✓ Gekopieerd!').css('color','#16a34a');
										setTimeout(function(){ $btn.text('📋 Kopieer').css('color',''); }, 2000);
									}
								});

								// ── Stuur toegangslink per e-mail ────────────────────────────────
								$(document).on('click', '.snd-send-access-link', function(){
									var $btn  = $(this).prop('disabled', true).text('Versturen…');
									var idx   = $btn.data('index');
									var nonce = $btn.data('nonce');
									$.post(ajaxurl, {
										action: 'snd_send_access_link',
										nonce:  nonce,
										index:  idx,
									}).done(function(res){
										if (res.success) {
											$btn.text('✓ Verstuurd!').css({'background':'#16a34a','border-color':'#16a34a','color':'#fff'});
											setTimeout(function(){ $btn.text('✉ Stuur link').css({'background':'','border-color':'','color':''}).prop('disabled',false); }, 3000);
										} else {
											alert(res.data && res.data.message ? res.data.message : 'Fout bij versturen.');
											$btn.text('✉ Stuur link').prop('disabled',false);
										}
									}).fail(function(){
										alert('Serverfout.');
										$btn.text('✉ Stuur link').prop('disabled',false);
									});
								});
							})(jQuery);
							</script>
						<?php endif; // end if(empty($outlets)) ?>

							<!-- ── Outlet bewerk-modal ──────────────────────────────────────── -->
							<div id="snd-edit-outlet-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
								<div style="background:#fff;border-radius:10px;width:520px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.25);overflow:hidden;">
									<div style="background:#1e293b;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;">
										<span style="font-size:15px;font-weight:700;color:#fff;">✏️ Outlet bewerken</span>
										<button type="button" id="snd-edit-outlet-close" style="background:none;border:none;color:rgba(255,255,255,.7);font-size:22px;cursor:pointer;line-height:1;">&times;</button>
									</div>
									<div style="padding:24px;max-height:70vh;overflow-y:auto;">
										<input type="hidden" id="snd-edit-idx">
										<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
											<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;">
												Naam medium *
												<input type="text" id="snd-edit-name" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
											</label>
											<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;">
												E-mailadres *
												<input type="email" id="snd-edit-email" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
											</label>
											<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;">
												Telefoonnummer
												<input type="text" id="snd-edit-phone" placeholder="+31 6 …" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
											</label>
											<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;">
												Groep
												<input type="text" id="snd-edit-group" placeholder="bijv. Lokaal, Sport" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
											</label>
										</div>
										<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;margin-bottom:14px;">
											Rol
											<select id="snd-edit-role" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
												<option value="default">Standaard</option>
												<option value="partner">Partner</option>
												<option value="redacteur">Redacteur</option>
											</select>
										</label>
										<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;margin-bottom:14px;">
											Interne notitie (niet zichtbaar voor outlet)
											<input type="text" id="snd-edit-notes" placeholder="bijv. Contactpersoon: Jan de Vries" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
										</label>
										<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">
											Aangepaste e-mailnoot voor deze outlet
											<span style="font-size:11px;font-weight:400;color:#6b7280;">Wordt onderaan elke e-mail toegevoegd, alleen voor dit medium zichtbaar. Handig voor contractuele vermeldingen.</span>
											<textarea id="snd-edit-custom-note" rows="3" placeholder="bijv. Dit persbericht is verstuurd namens Bedrijf X onder contract #2024-001." style="padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;resize:vertical;font-family:sans-serif;margin-top:4px;"></textarea>
										</label>
										<div id="snd-edit-outlet-msg" style="font-size:12px;min-height:18px;"></div>
									</div>
									<div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;background:#f9fafb;">
										<button type="button" id="snd-edit-outlet-close2" class="button"><?php esc_html_e( 'Annuleren', 'nieuws-distributie-systeem' ); ?></button>
										<button type="button" id="snd-edit-outlet-save" class="button button-primary"><?php esc_html_e( 'Opslaan', 'nieuws-distributie-systeem' ); ?></button>
									</div>
								</div>
							</div>
							<script>
							(function($){
								var nonce = <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>;
								var $modal = $('#snd-edit-outlet-modal');

								function openModal(idx) {
									$.post(ajaxurl, { action: 'snd_edit_outlet', nonce: nonce, index: idx, mode: 'get' })
									.done(function(r) {
										if (!r.success) { alert('Kon outlet niet laden.'); return; }
										var o = r.data;
										$('#snd-edit-idx').val(idx);
										$('#snd-edit-name').val(o.name || '');
										$('#snd-edit-email').val(o.email || '');
										$('#snd-edit-phone').val(o.phone || '');
										$('#snd-edit-group').val(o.group || '');
										$('#snd-edit-role').val(o.role || 'default');
										$('#snd-edit-notes').val(o.notes || '');
										$('#snd-edit-custom-note').val(o.custom_email_note || '');
										$('#snd-edit-outlet-msg').text('');
										$modal.css('display', 'flex');
									});
								}

								function closeModal() { $modal.hide(); }

								$(document).on('click', '.snd-edit-outlet-btn', function() { openModal($(this).data('index')); });
								$('#snd-edit-outlet-close, #snd-edit-outlet-close2').on('click', closeModal);
								$modal.on('click', function(e) { if ($(e.target).is($modal)) closeModal(); });

								$('#snd-edit-outlet-save').on('click', function() {
									var $btn = $(this).prop('disabled', true).text('Opslaan…');
									var $msg = $('#snd-edit-outlet-msg');
									$.post(ajaxurl, {
										action: 'snd_edit_outlet',
										nonce:  nonce,
										mode:   'save',
										index:  $('#snd-edit-idx').val(),
										name:   $('#snd-edit-name').val(),
										email:  $('#snd-edit-email').val(),
										phone:  $('#snd-edit-phone').val(),
										group:  $('#snd-edit-group').val(),
										role:   $('#snd-edit-role').val(),
										notes:  $('#snd-edit-notes').val(),
										custom_email_note: $('#snd-edit-custom-note').val(),
									}).done(function(r) {
										if (r.success) {
											$msg.text('✓ ' + r.data.message).css('color', '#16a34a');
											setTimeout(function() { window.location.reload(); }, 1200);
										} else {
											$msg.text('✗ ' + (r.data && r.data.message || 'Fout')).css('color', '#b32d2e');
											$btn.prop('disabled', false).text('Opslaan');
										}
									}).fail(function() {
										$msg.text('Serverfout.').css('color', '#b32d2e');
										$btn.prop('disabled', false).text('Opslaan');
									});
								});

								// Close with Escape
								$(document).on('keydown', function(e) {
									if (e.key === 'Escape') closeModal();
								});
							})(jQuery);
							</script>
					</div>
					<div class="snd-col-side">
						<div class="snd-card">
							<h2><?php esc_html_e( 'Nieuwe outlet toevoegen', 'nieuws-distributie-systeem' ); ?></h2>
							<form method="post">
								<?php wp_nonce_field( 'snd_settings_action', 'snd_nonce_field' ); ?>
								<input type="hidden" name="snd_action" value="add_outlet">
								<p><label><?php esc_html_e( 'Naam medium', 'nieuws-distributie-systeem' ); ?><input type="text" name="outlet_name" class="widefat" required></label></p>
								<p><label><?php esc_html_e( 'E-mailadres', 'nieuws-distributie-systeem' ); ?><input type="email" name="outlet_email" class="widefat" required></label></p>
								<p><label><?php esc_html_e( 'Telefoonnummer (optioneel)', 'nieuws-distributie-systeem' ); ?><input type="text" name="outlet_phone" class="widefat" placeholder="+31 6 12 34 56 78"></label></p>
								<p><label><?php esc_html_e( 'Groep (optioneel)', 'nieuws-distributie-systeem' ); ?><input type="text" name="outlet_group" class="widefat" placeholder="<?php esc_attr_e( 'bijv. Lokaal, Sport', 'nieuws-distributie-systeem' ); ?>"></label></p>
								<p><label><?php esc_html_e( 'Interne notitie (optioneel)', 'nieuws-distributie-systeem' ); ?><input type="text" name="outlet_notes" class="widefat" placeholder="bijv. Contactpersoon: Jan de Vries"></label></p>
								<p><label><?php esc_html_e( 'Rol', 'nieuws-distributie-systeem' ); ?>
									<select name="outlet_role" class="widefat">
										<option value="default"><?php esc_html_e( 'Standaard', 'nieuws-distributie-systeem' ); ?></option>
										<option value="partner"><?php esc_html_e( 'Partner (chat + portaal)', 'nieuws-distributie-systeem' ); ?></option>
										<option value="redacteur"><?php esc_html_e( 'Redacteur (front-end beheer)', 'nieuws-distributie-systeem' ); ?></option>
									</select></label>
								</p>
								<?php submit_button( __( 'Outlet toevoegen', 'nieuws-distributie-systeem' ), 'primary', 'submit', false ); ?>
							</form>
						</div>
					</div>
				</div>

			<?php elseif ( 'p2000' === $active ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'snd_settings_action', 'snd_nonce_field' ); ?>
					<input type="hidden" name="snd_action" value="save_main_settings">
					<h2><?php esc_html_e( 'P2000 Feed instellingen', 'nieuws-distributie-systeem' ); ?></h2>
					<table class="form-table">
						<tr><th><label for="snd_p2000_feed_brandweer"><?php esc_html_e( 'RSS Feed Brandweer', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="url" id="snd_p2000_feed_brandweer" name="snd_p2000_feed_brandweer" value="<?php echo esc_attr( get_option( 'snd_p2000_feed_brandweer' ) ); ?>" class="widefat"></td></tr>
						<tr><th><label for="snd_p2000_feed_politie"><?php esc_html_e( 'RSS Feed Politie', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="url" id="snd_p2000_feed_politie" name="snd_p2000_feed_politie" value="<?php echo esc_attr( get_option( 'snd_p2000_feed_politie' ) ); ?>" class="widefat"></td></tr>
						<tr><th><label for="snd_p2000_feed_mmt"><?php esc_html_e( 'RSS Feed MMT', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="url" id="snd_p2000_feed_mmt" name="snd_p2000_feed_mmt" value="<?php echo esc_attr( get_option( 'snd_p2000_feed_mmt' ) ); ?>" class="widefat"></td></tr>
						<tr><th><label for="snd_p2000_cities"><?php esc_html_e( 'Te monitoren steden', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="text" id="snd_p2000_cities" name="snd_p2000_cities" value="<?php echo esc_attr( get_option( 'snd_p2000_cities', 'Tilburg' ) ); ?>" class="widefat"><p class="description"><?php esc_html_e( 'Kommagescheiden. Eerste stad is centrum voor MMT-radius.', 'nieuws-distributie-systeem' ); ?></p></td></tr>
						<tr><th><label for="snd_p2000_filter_radius"><?php esc_html_e( 'MMT Filter Radius (km)', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="number" id="snd_p2000_filter_radius" name="snd_p2000_filter_radius" value="<?php echo esc_attr( get_option( 'snd_p2000_filter_radius', 20 ) ); ?>" class="small-text" min="0"><p class="description"><?php esc_html_e( '0 = alle MMT-meldingen tonen.', 'nieuws-distributie-systeem' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'Basisritten filter', 'nieuws-distributie-systeem' ); ?></th><td><label><input type="checkbox" name="snd_p2000_hide_standard_runs" value="1" <?php checked( get_option( 'snd_p2000_hide_standard_runs', '1' ), '1' ); ?>> <?php esc_html_e( 'Verberg standaard A0/A1/A2 en B1/B2 ritten', 'nieuws-distributie-systeem' ); ?></label></td></tr>
					</table>

					<h2 style="margin-top:24px;">🗺 Google Maps API</h2>
					<p style="color:#555;margin-bottom:12px;">
						Met een Google API-sleutel worden straatnamen veel nauwkeuriger gevonden én worden de kaarten weergegeven via Google Maps.
						Haal een sleutel op via <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> en schakel
						<strong>Geocoding API</strong> en <strong>Maps JavaScript API</strong> in.
					</p>
					<table class="form-table">
						<tr>
							<th><label for="snd_google_maps_api_key">Google Maps API-sleutel</label></th>
							<td>
								<input type="text" id="snd_google_maps_api_key" name="snd_google_maps_api_key"
									value="<?php echo esc_attr( get_option( 'snd_google_maps_api_key', '' ) ); ?>"
									class="regular-text" placeholder="AIza…" autocomplete="off">
								<p class="description">
									<?php
									$api_key = get_option( 'snd_google_maps_api_key', '' );
									if ( $api_key ) {
										echo '<span style="color:#16a34a;font-weight:600;">✓ Ingesteld — geocoding gebruikt Google.</span>';
									} else {
										echo '<span style="color:#f59e0b;">Niet ingesteld — valt terug op Photon + Nominatim (minder nauwkeurig).</span>';
									}
									?>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Instellingen opslaan', 'nieuws-distributie-systeem' ) ); ?>
				</form>

			<?php elseif ( 'email' === $active ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'snd_settings_action', 'snd_nonce_field' ); ?>
					<input type="hidden" name="snd_action" value="save_main_settings">
					<h2><?php esc_html_e( 'E-mail & Persportaal', 'nieuws-distributie-systeem' ); ?></h2>
					<table class="form-table">
						<tr><th><label for="snd_email_from_name"><?php esc_html_e( 'Afzendernaam', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="text" id="snd_email_from_name" name="snd_email_from_name" value="<?php echo esc_attr( get_option( 'snd_email_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text"></td></tr>
						<tr><th><label for="snd_email_from_email"><?php esc_html_e( 'Afzender e-mailadres', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="email" id="snd_email_from_email" name="snd_email_from_email" value="<?php echo esc_attr( get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) ) ); ?>" class="regular-text"></td></tr>
						<tr><th><label for="snd_email_subject_prefix"><?php esc_html_e( 'Onderwerp-voorvoegsel', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="text" id="snd_email_subject_prefix" name="snd_email_subject_prefix" value="<?php echo esc_attr( get_option( 'snd_email_subject_prefix', 'Persbericht:' ) ); ?>" class="regular-text"></td></tr>
						<tr>
							<th><label for="snd_email_accent_color"><?php esc_html_e( 'Accentkleur e-mail', 'nieuws-distributie-systeem' ); ?></label></th>
							<td>
								<input type="color" id="snd_email_accent_color" name="snd_email_accent_color"
									value="<?php echo esc_attr( get_option( 'snd_email_accent_color', '#3b82f6' ) ); ?>"
									style="width:48px;height:32px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
								<span style="margin-left:8px;font-size:13px;color:#555;"><?php esc_html_e( 'Kleur van de knop en accentlijn in uitgaande e-mails.', 'nieuws-distributie-systeem' ); ?></span>
							</td>
						</tr>
						<tr><th><label for="snd_portal_default_expiry"><?php esc_html_e( 'Standaard vervaltermijn', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="number" id="snd_portal_default_expiry" name="snd_portal_default_expiry" value="<?php echo esc_attr( get_option( 'snd_portal_default_expiry', 30 ) ); ?>" class="small-text"> <?php esc_html_e( 'dagen (0 = nooit)', 'nieuws-distributie-systeem' ); ?></td></tr>
					</table>
					<?php submit_button( __( 'Instellingen opslaan', 'nieuws-distributie-systeem' ) ); ?>
				</form>

			<?php elseif ( 'algemeen' === $active ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'snd_settings_action', 'snd_nonce_field' ); ?>
					<input type="hidden" name="snd_action" value="save_main_settings">
					<h2><?php esc_html_e( 'Algemene instellingen', 'nieuws-distributie-systeem' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Notificatie-e-mailadres', 'nieuws-distributie-systeem' ); ?></label></th><td><input type="email" id="snd_admin_notification_email" name="snd_admin_notification_email" value="<?php echo esc_attr( get_option( 'snd_admin_notification_email', get_bloginfo( 'admin_email' ) ) ); ?>" class="regular-text"></td></tr>
						<tr><th><?php esc_html_e( 'Meldingen', 'nieuws-distributie-systeem' ); ?></th><td><label><input type="checkbox" name="snd_notify_on_fail" value="1" <?php checked( get_option( 'snd_notify_on_fail' ), '1' ); ?>> <?php esc_html_e( 'Stuur e-mail als een feed ophalen mislukt.', 'nieuws-distributie-systeem' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Testmodus', 'nieuws-distributie-systeem' ); ?></th><td><label><input type="checkbox" name="snd_test_mode_enabled" value="1" <?php checked( get_option( 'snd_test_mode_enabled', '0' ), '1' ); ?>> <?php esc_html_e( 'Testmodus inschakelen (geen logging)', 'nieuws-distributie-systeem' ); ?></label></td></tr>
						<tr>
							<th><label for="snd_melding_auto_archive_hours">Auto-archivering eigen meldingen</label></th>
							<td>
								<input type="number" id="snd_melding_auto_archive_hours" name="snd_melding_auto_archive_hours"
									value="<?php echo esc_attr( get_option( 'snd_melding_auto_archive_hours', 0 ) ); ?>"
									min="0" max="168" step="1" style="width:80px;"> uur
								<p class="description">Eigen meldingen worden na dit aantal uur automatisch verwijderd. Stel 0 in om archivering uit te schakelen.</p>
							</td>
						</tr>
					</table>

					<h2 style="margin-top:28px;">📱 Telegram integratie</h2>
					<p style="color:#555;margin-bottom:12px;">
						Koppel een Telegram bot voor meldingen bij statuswijzigingen en om info via Telegram in te sturen.
						Maak een bot aan via <a href="https://t.me/BotFather" target="_blank">@BotFather</a>,
						voeg de bot toe aan je kanaal/groep, en haal het chat-ID op.
					</p>
					<table class="form-table">
						<tr>
							<th><label for="snd_telegram_bot_token">Bot Token</label></th>
							<td>
								<input type="text" id="snd_telegram_bot_token" name="snd_telegram_bot_token"
									value="<?php echo esc_attr( get_option( 'snd_telegram_bot_token', '' ) ); ?>"
									class="regular-text" placeholder="123456789:ABCdef…" autocomplete="off">
								<p class="description">Van @BotFather → token van jouw bot.</p>
							</td>
						</tr>
						<tr>
							<th>Ontvangers / Groepen</th>
							<td>
								<?php
								$recipients = get_option( 'snd_telegram_recipients', [] );
								// Migrate legacy single chat_id if no recipients yet
								$legacy_chat_id = get_option( 'snd_telegram_chat_id', '' );
								if ( empty( $recipients ) && $legacy_chat_id ) {
									$recipients = [ [ 'name' => 'Hoofdkanaal', 'chat_id' => $legacy_chat_id, 'enabled' => true ] ];
								}
								?>
								<div style="margin-bottom:10px;">
									<table id="snd-tg-recipients-table" style="border-collapse:collapse;width:100%;">
										<thead>
											<tr style="font-size:12px;font-weight:600;color:#666;border-bottom:2px solid #ddd;">
												<th style="padding:5px 8px;text-align:left;min-width:110px;">Naam</th>
												<th style="padding:5px 8px;text-align:left;min-width:130px;">Chat ID</th>
												<th style="padding:5px 8px;min-width:95px;">Rol</th>
												<th style="padding:5px 8px;min-width:130px;" title="Laat leeg voor toegang tot alle outlets">Toegestane outlets</th>
												<th style="padding:5px 8px;text-align:center;width:75px;" title="Versturen vereist goedkeuring van eigenaar">Goedkeuring vereist</th>
												<th style="padding:5px 8px;text-align:center;width:50px;">Actief</th>
												<th style="padding:5px 8px;width:70px;"></th>
											</tr>
										</thead>
										<tbody id="snd-tg-recipients-body">
										<?php
										$_snd_all_outlets = get_option( self::OPTION_OUTLETS, [] );
										foreach ( $recipients as $i => $r ) :
											$r_role     = $r['role']            ?? 'lezer';
											$r_ao       = $r['allowed_outlets'] ?? 'all';
											$r_ao_arr   = is_array( $r_ao ) ? $r_ao : [];
											$r_approval = ! empty( $r['needs_approval'] );
										?>
										<tr data-idx="<?php echo $i; ?>" style="border-bottom:1px solid #f0f0f0;vertical-align:top;">
											<td style="padding:5px 8px;"><input type="text" class="snd-tg-name" value="<?php echo esc_attr( $r['name'] ?? '' ); ?>" placeholder="bijv. Sophie" style="width:100px;font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;"></td>
											<td style="padding:5px 8px;"><input type="text" class="snd-tg-chatid" value="<?php echo esc_attr( $r['chat_id'] ?? '' ); ?>" placeholder="-100123…" style="width:120px;font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-family:monospace;"></td>
											<td style="padding:5px 8px;">
												<select class="snd-tg-role" style="font-size:12px;padding:3px;border:1px solid #ddd;border-radius:4px;">
													<?php foreach ( [ 'admin' => 'Admin', 'redacteur' => 'Redacteur', 'fotograaf' => 'Fotograaf', 'lezer' => 'Lezer' ] as $rv => $rl ) : ?>
														<option value="<?php echo $rv; ?>" <?php selected( $r_role, $rv ); ?>><?php echo $rl; ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td style="padding:5px 8px;">
												<select class="snd-tg-outlets" multiple style="font-size:11px;width:130px;height:60px;border:1px solid #ddd;border-radius:4px;" title="Niets = alle outlets toegestaan">
													<?php foreach ( $_snd_all_outlets as $ao ) : ?>
														<option value="<?php echo esc_attr( $ao['email'] ?? '' ); ?>" <?php echo in_array( $ao['email'] ?? '', $r_ao_arr, true ) ? 'selected' : ''; ?>><?php echo esc_html( $ao['name'] ?? $ao['email'] ?? '?' ); ?></option>
													<?php endforeach; ?>
												</select>
												<div style="font-size:10px;color:#999;margin-top:2px;">Ctrl+klik = meerdere</div>
											</td>
											<td style="padding:5px 8px;text-align:center;"><input type="checkbox" class="snd-tg-approval" title="Versturen vereist goedkeuring" <?php checked( $r_approval ); ?>></td>
											<td style="padding:5px 8px;text-align:center;"><input type="checkbox" class="snd-tg-enabled" <?php checked( ! empty( $r['enabled'] ) ); ?>></td>
											<td style="padding:5px 8px;">
												<button type="button" class="button button-small snd-tg-test-row" style="font-size:11px;">📱 Test</button>
												<button type="button" class="button-link-delete snd-tg-remove-row" style="font-size:18px;margin-left:4px;vertical-align:middle;">&times;</button>
											</td>
										</tr>
										<?php endforeach; ?>
										</tbody>
									<button type="button" id="snd-tg-add-row" class="button button-small" style="margin-top:8px;font-size:12px;">+ Ontvanger toevoegen</button>
									<button type="button" id="snd-tg-save-recipients" class="button button-primary button-small" style="margin-top:8px;margin-left:6px;font-size:12px;">Opslaan</button>
									<span id="snd-tg-save-result" style="margin-left:8px;font-size:12px;"></span>
								</div>
								<p class="description">Voeg kanalen, groepen of gebruikers toe. Iedereen in de lijst kan commando's sturen en ontvangt alle notificaties. Haal het chat ID op door <code>@userinfobot</code> te sturen in Telegram.</p>
								<script>
								(function($){
									var nonce = <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>;

									function rowHtml(idx) {
										return '<tr data-idx="' + idx + '" style="border-bottom:1px solid #f0f0f0;vertical-align:top;">'
											+ '<td style="padding:5px 8px;"><input type="text" class="snd-tg-name" value="" placeholder="Naam" style="width:100px;font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;"></td>'
											+ '<td style="padding:5px 8px;"><input type="text" class="snd-tg-chatid" value="" placeholder="-100123…" style="width:120px;font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-family:monospace;"></td>'
											+ '<td style="padding:5px 8px;"><select class="snd-tg-role" style="font-size:12px;padding:3px;border:1px solid #ddd;border-radius:4px;"><option value="lezer">Lezer</option><option value="fotograaf">Fotograaf</option><option value="redacteur">Redacteur</option><option value="admin">Admin</option></select></td>'
											+ '<td style="padding:5px 8px;"><select class="snd-tg-outlets" multiple style="font-size:11px;width:130px;height:60px;border:1px solid #ddd;border-radius:4px;">' + outletOptions('') + '</select><div style="font-size:10px;color:#999;">Leeg = alles</div></td>'
											+ '<td style="padding:5px 8px;text-align:center;"><input type="checkbox" class="snd-tg-approval"></td>'
											+ '<td style="padding:5px 8px;text-align:center;"><input type="checkbox" class="snd-tg-enabled" checked></td>'
											+ '<td style="padding:5px 8px;"><button type="button" class="button button-small snd-tg-test-row" style="font-size:11px;">📱 Test</button>'
											+ '<button type="button" class="button-link-delete snd-tg-remove-row" style="font-size:18px;margin-left:4px;vertical-align:middle;">&times;</button></td>'
											+ '</tr>';
									}

									// Outlets voor de multi-select (vanuit PHP doorgegeven)
									var sndAllOutlets = <?php
										$_outs = get_option( self::OPTION_OUTLETS, [] );
										echo wp_json_encode( array_map( function($o){ return ['email'=>$o['email']??'','name'=>$o['name']??$o['email']??'?']; }, $_outs ) );
									?>;

									function outletOptions(selected) {
										var sel = Array.isArray(selected) ? selected : [];
										return sndAllOutlets.map(function(o){
											var s = sel.indexOf(o.email) > -1 ? ' selected' : '';
											return '<option value="' + o.email + '"' + s + '>' + o.name + '</option>';
										}).join('');
									}

									$('#snd-tg-add-row').on('click', function(){
										var idx = $('#snd-tg-recipients-body tr').length;
										$('#snd-tg-recipients-body').append(rowHtml(idx));
									});

									$(document).on('click', '.snd-tg-remove-row', function(){
										$(this).closest('tr').remove();
									});

									$(document).on('click', '.snd-tg-test-row', function(){
										var $btn = $(this).prop('disabled', true).text('\u2026');
										var $row = $btn.closest('tr');
										var name    = $row.find('.snd-tg-name').val().trim() || 'Ontvanger';
										var chat_id = $row.find('.snd-tg-chatid').val().trim();
										if (!chat_id) { alert('Vul een chat ID in.'); $btn.prop('disabled', false).text('\xf0\x9f\x93\xb1 Test'); return; }
										$.post(ajaxurl, { action: 'snd_telegram_test_recipient', nonce: nonce, name: name, chat_id: chat_id })
										.done(function(r){ alert(r.success ? '\u2713 ' + r.data.message : '\u2717 ' + (r.data && r.data.message || 'Fout')); })
										.always(function(){ $btn.prop('disabled', false).text('\xf0\x9f\x93\xb1 Test'); });
									});

									$('#snd-tg-save-recipients').on('click', function(){
										var $btn = $(this).prop('disabled', true).text('Opslaan\u2026');
										var data = [];
										$('#snd-tg-recipients-body tr').each(function(){
											var name    = $(this).find('.snd-tg-name').val().trim();
											var chat_id = $(this).find('.snd-tg-chatid').val().trim();
											var enabled  = $(this).find('.snd-tg-enabled').is(':checked');
											var role     = $(this).find('.snd-tg-role').val() || 'lezer';
											var approval = $(this).find('.snd-tg-approval').is(':checked');
											var outlets  = $(this).find('.snd-tg-outlets').val() || [];
											if (chat_id) data.push({ name, chat_id, enabled, role, needs_approval: approval, allowed_outlets: outlets });
										});
										$.post(ajaxurl, { action: 'snd_telegram_save_recipients', nonce: nonce, recipients_json: JSON.stringify(data) })
										.done(function(r){
											var $res = $('#snd-tg-save-result');
											$res.text(r.success ? '\u2713 ' + r.data.message : '\u2717 ' + (r.data && r.data.message || 'Fout'))
												.css('color', r.success ? '#16a34a' : '#b32d2e');
											setTimeout(function(){ $res.text(''); }, 3000);
										}).always(function(){ $btn.prop('disabled', false).text('Opslaan'); });
									});
								})(jQuery);
								</script>
							</td>
						</tr>
						<tr>
							<th>Webhook URL</th>
							<td>
								<?php $webhook_url = rest_url( 'snd/v1/telegram' ); ?>
								<code style="background:#f0f0f0;padding:5px 10px;border-radius:4px;font-size:12px;display:inline-block;margin-bottom:8px;user-select:all;"><?php echo esc_html( $webhook_url ); ?></code>
								<p class="description">
									Registreer dit als webhook bij Telegram (eenmalig na instellen token):<br>
									<a href="https://api.telegram.org/bot<?php echo esc_attr( get_option('snd_telegram_bot_token','TOKEN') ); ?>/setWebhook?url=<?php echo urlencode( $webhook_url ); ?>" target="_blank" class="button button-small" style="margin-top:6px;">🔗 Webhook nu registreren</a>
								</p>
								<p class="description" style="margin-top:8px;"><strong>Commando's via Telegram:</strong>
									<code>/help</code> · <code>/status</code> · <code>/melding tekst @ straat, stad</code> · <code>/onderweg [id]</code> · <code>/terplaatse [id]</code> · <code>/wis [id]</code> · <code>/info [id] notitie</code> · <code>/item [id]</code> · <code>/nieuws</code> · <code>/samenvatting</code>
								</p>
							</td>
						</tr>
						<tr>
							<th>Test &amp; commando's</th>
							<td>
								<button type="button" id="snd-tg-cmds" class="button">📋 Registreer bot-commando's</button>
								<span id="snd-tg-cmds-result" style="margin-left:10px;font-size:13px;"></span>
								<script>
								jQuery('#snd-tg-cmds').on('click', function(){
									var token = jQuery('#snd_telegram_bot_token').val();
									if (!token) { jQuery('#snd-tg-cmds-result').text('Vul eerst het token in.').css('color','#b32d2e'); return; }
									jQuery(this).prop('disabled',true).text('Bezig…');
									jQuery.post(ajaxurl, {
										action: 'snd_telegram_register_commands',
										nonce: <?php echo wp_json_encode( wp_create_nonce( 'snd_nonce' ) ); ?>,
										token: token
									}).done(function(r){
										jQuery('#snd-tg-cmds-result').text(r.success ? '✓ Commando\'s geregistreerd!' : '✗ ' + (r.data && r.data.message || 'Fout')).css('color', r.success ? '#16a34a' : '#b32d2e');
									}).always(function(){ jQuery('#snd-tg-cmds').prop('disabled',false).text('📋 Registreer bot-commando\'s'); });
								});
								</script>
							</td>
						</tr>
						<tr>
							<th>Notificaties</th>
							<td>
								<label><input type="checkbox" name="snd_telegram_notify_onderweg" value="1" <?php checked( get_option( 'snd_telegram_notify_onderweg', '1' ), '1' ); ?>>
								Bericht bij <strong>Fotograaf onderweg</strong></label><br>
								<label><input type="checkbox" name="snd_telegram_notify_ter_plaatse" value="1" <?php checked( get_option( 'snd_telegram_notify_ter_plaatse', '1' ), '1' ); ?>>
								Bericht bij <strong>Fotograaf ter plaatse</strong></label><br>
								<label><input type="checkbox" name="snd_telegram_notify_afgerond" value="1" <?php checked( get_option( 'snd_telegram_notify_afgerond', '1' ), '1' ); ?>>
								Bericht bij <strong>Afgerond</strong> (WordPress incident)</label><br>
								<label><input type="checkbox" name="snd_telegram_notify_dispatch" value="1" <?php checked( get_option( 'snd_telegram_notify_dispatch', '1' ), '1' ); ?>>
								Bericht bij <strong>Persbericht verstuurd</strong> 📨</label><br>
								<label><input type="checkbox" name="snd_telegram_notify_first_view" value="1" <?php checked( get_option( 'snd_telegram_notify_first_view', '1' ), '1' ); ?>>
								Bericht als medium <strong>persbericht voor het eerst opent</strong> 👁</label><br>
								<label><input type="checkbox" name="snd_telegram_notify_download" value="1" <?php checked( get_option( 'snd_telegram_notify_download', '1' ), '1' ); ?>>
								Bericht als medium <strong>foto's downloadt</strong> 📥</label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Instellingen opslaan', 'nieuws-distributie-systeem' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Shortcode: incident list ──────────────────────────────────────────────

	public function shortcode_incident_list( array $atts = [] ): string {
		$atts = shortcode_atts( [
			'aantal'     => 20,
			'type'       => '',       // brandweer, politie, mmt, or empty for all
			'live'       => '1',      // auto-refresh
			'refresh'    => '60',     // seconds
			'toon_stad'  => '1',
		], $atts, 'incidenten_lijst' );

		$id = 'snd-lijst-' . wp_unique_id();

		ob_start();
		?>
		<div class="snd-incident-widget" id="<?php echo esc_attr( $id ); ?>"
			data-type="<?php echo esc_attr( $atts['type'] ); ?>"
			data-aantal="<?php echo (int) $atts['aantal']; ?>"
			data-live="<?php echo esc_attr( $atts['live'] ); ?>"
			data-refresh="<?php echo (int) $atts['refresh']; ?>"
			data-toon-stad="<?php echo esc_attr( $atts['toon_stad'] ); ?>">
			<ul class="snd-incident-list snd-incident-list-output">
				<li class="snd-incident-loading"><?php esc_html_e( 'Laden…', 'nieuws-distributie-systeem' ); ?></li>
			</ul>
			<?php if ( $atts['live'] === '1' ) : ?>
			<div class="snd-incident-footer">
				<span class="snd-live-indicator">● LIVE</span>
				<span class="snd-last-updated"></span>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function shortcode_incident_map( array $atts = [] ): string {
		$atts = shortcode_atts( [
			'hoogte' => '450',
			'zoom'   => '11',
			'lat'    => '51.56',
			'lon'    => '5.09',
		], $atts, 'incidenten_kaart' );

		$id = 'snd-kaart-' . wp_unique_id();

		return sprintf(
			'<div class="snd-incident-map-widget"><div id="%s" style="height:%spx;border-radius:8px;overflow:hidden;" data-zoom="%s" data-lat="%s" data-lon="%s"></div></div>',
			esc_attr( $id ),
			(int) $atts['hoogte'],
			(int) $atts['zoom'],
			esc_attr( $atts['lat'] ),
			esc_attr( $atts['lon'] )
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function is_test_mode(): bool {
		return get_option( 'snd_test_mode_enabled' ) === '1';
	}

	private function get_press_photo_ids( int $post_id ): array {
		$str = get_post_meta( $post_id, self::META_PRESS_PHOTOS, true );
		return ! empty( $str ) ? array_filter( explode( ',', $str ) ) : [];
	}

	private function get_press_video_ids( int $post_id ): array {
		$str = get_post_meta( $post_id, self::META_PRESS_VIDEOS, true );
		return ! empty( $str ) ? array_filter( explode( ',', $str ) ) : [];
	}

	private function get_all_image_ids( int $post_id ): array {
		$ids = [];
		if ( has_post_thumbnail( $post_id ) ) {
			$ids[] = get_post_thumbnail_id( $post_id );
		}
		foreach ( get_attached_media( 'image', $post_id ) as $img ) {
			$ids[] = $img->ID;
		}
		$post = get_post( $post_id );
		if ( $post && preg_match_all( '/wp-image-(\d+)/i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = (int) $id;
			}
		}
		return array_unique( $ids );
	}


	private function send_email( int $post_id, array $outlet, array $selected_images ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) return false;

		// Template expects $preview_images and $press_photo_count
		$press_photo_ids  = $this->get_press_photo_ids( $post_id );
		$preview_images   = ! empty( $selected_images ) ? $selected_images : $press_photo_ids;
		$press_photo_count = count( $press_photo_ids ); // actual count of full-res press photos

		ob_start();
		$tpl = SND_PLUGIN_PATH . 'templates/email-template.php';
		if ( ! file_exists( $tpl ) ) {
			ob_end_clean();
			return false;
		}
		include $tpl;
		$body = ob_get_clean();

		if ( empty( trim( $body ) ) ) {
			return false; // Template produced no output
		}

		$from_name  = get_option( 'snd_email_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) );
		$prefix     = get_option( 'snd_email_subject_prefix', 'Persbericht:' );
		$subject    = trim( $prefix . ' ' . $post->post_title );

		add_filter( 'wp_mail_content_type', function() { return 'text/html'; } );

		$result = wp_mail(
			$outlet['email'],
			$subject,
			$body,
			[ "From: {$from_name} <{$from_email}>" ]
		);

		remove_all_filters( 'wp_mail_content_type' );

		return $result;
	}

	private function get_analytics_stats(): array {
		$cached = get_transient( 'snd_analytics_stats' );
		if ( false !== $cached ) return $cached;

		$data  = [];
		$query = new \WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'meta_key' => self::META_ACCESS_LOG, 'fields' => 'ids' ] );
		foreach ( $query->posts as $pid ) {
			$log = get_post_meta( $pid, self::META_ACCESS_LOG, true );
			if ( ! is_array( $log ) ) continue;
			foreach ( $log as $email => $item ) {
				if ( ! isset( $data[ $email ] ) ) {
					$data[ $email ] = [ 'name' => $item['name'] ?? 'Onbekend', 'opens' => 0, 'downloads' => 0, 'score' => 0 ];
				}
				$data[ $email ]['opens']     += count( $item['views'] ?? [] );
				$data[ $email ]['downloads'] += count( $item['downloads'] ?? [] );
			}
		}
		foreach ( $data as &$d ) {
			$d['score'] = ( $d['downloads'] * 3 ) + $d['opens'];
		}
		uasort( $data, fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$stats = array_slice( $data, 0, 20, true );
		set_transient( 'snd_analytics_stats', $stats, HOUR_IN_SECONDS );
		return $stats;
	}

	// ── Page: Telegram Log ────────────────────────────────────────────────────

	public function page_tg_log(): void {
		$log = array_reverse( get_option( 'snd_tg_action_log', [] ) ); // nieuwste eerst
		?>
		<div class="wrap snd-admin-wrap">
			<h1>📱 Telegram Activiteitenlog</h1>
			<p style="color:#666;">Elke actie die via de Telegram bot wordt uitgevoerd wordt hier vastgelegd.</p>

			<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;">
				<span style="color:#888;font-size:13px;"><?php echo count( $log ); ?> regels (max 500)</span>
				<button id="snd-tg-log-clear" class="button button-small" style="color:#b32d2e;">🗑 Log wissen</button>
			</div>

			<?php if ( empty( $log ) ) : ?>
				<p style="color:#999;">Nog geen activiteit gelogd.</p>
			<?php else : ?>
			<table class="widefat striped" style="font-size:12px;">
				<thead>
					<tr>
						<th style="width:140px;">Tijdstip</th>
						<th style="width:160px;">Gebruiker</th>
						<th style="width:120px;">Chat ID</th>
						<th style="width:160px;">Actie</th>
						<th>Detail</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log as $entry ) :
					$ts     = isset( $entry['ts'] ) ? wp_date( 'd-m-Y H:i:s', $entry['ts'] ) : '—';
					$action = esc_html( $entry['action'] ?? '—' );
					$name   = esc_html( $entry['name']   ?? '?' );
					$cid    = esc_html( $entry['chat_id'] ?? '?' );
					$detail = esc_html( $entry['detail']  ?? '' );
					$color  = match( $entry['action'] ?? '' ) {
						'verstuurd'                => '#dcfce7',
						'geblokkeerd_dispatch'     => '#fee2e2',
						'goedkeuring_aangevraagd'  => '#fef3c7',
						'goedkeuring_verleend'     => '#dcfce7',
						'goedkeuring_afgewezen'    => '#fee2e2',
						default                    => '',
					};
					?>
					<tr<?php echo $color ? ' style="background:' . $color . ';"' : ''; ?>>
						<td><?php echo $ts; ?></td>
						<td><strong><?php echo $name; ?></strong></td>
						<td><code><?php echo $cid; ?></code></td>
						<td><span style="background:#e2e8f0;padding:2px 6px;border-radius:4px;"><?php echo $action; ?></span></td>
						<td><?php echo $detail; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<script>
		document.getElementById('snd-tg-log-clear').addEventListener('click', function() {
			if (!confirm('Weet je zeker dat je het volledige log wilt wissen?')) return;
			var btn = this;
			btn.disabled = true;
			btn.textContent = 'Wissen…';
			jQuery.post(ajaxurl, {
				action: 'snd_tg_log_clear',
				nonce: <?php echo wp_json_encode( wp_create_nonce('snd_nonce') ); ?>
			}).done(function() {
				location.reload();
			}).fail(function() {
				btn.disabled = false;
				btn.textContent = '🗑 Log wissen';
				alert('Wissen mislukt.');
			});
		});
		</script>
		<?php
	}

	// ── AJAX: Telegram log wissen ─────────────────────────────────────────────

	public function ajax_tg_log_clear(): void {
		$this->verify_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
		delete_option( 'snd_tg_action_log' );
		wp_send_json_success();
	}
}
