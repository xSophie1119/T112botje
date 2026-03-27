<?php
/**
 * SND_Chat – Per-partner, per-incident chat threads.
 *
 * Storage key: _snd_chat_{md5(email)}  (one meta key per outlet per post)
 * Each message:
 *   [ id, sender ('admin'|'partner'), name, email, body, ts, read_admin, read_partner ]
 *
 * Partners only see their own thread. Admins/editors see all threads per incident.
 */
class SND_Chat {

	const OPTION_OUTLETS = 'snd_media_outlets_v4';
	const MAX_MESSAGES   = 200;

	/** Meta key for a specific partner's thread on a post */
	public static function thread_key( string $email ): string {
		return '_snd_chat_' . md5( strtolower( trim( $email ) ) );
	}

	public function __construct() {
		// Admin/editor AJAX
		add_action( 'wp_ajax_snd_chat_get',        [ $this, 'ajax_admin_get' ] );
		add_action( 'wp_ajax_snd_chat_send_admin',  [ $this, 'ajax_admin_send' ] );
		add_action( 'wp_ajax_snd_chat_delete_msg',  [ $this, 'ajax_admin_delete' ] );

		// Portal AJAX (nopriv = access-code auth)
		add_action( 'wp_ajax_snd_chat_portal_get',         [ $this, 'ajax_portal_get' ] );
		add_action( 'wp_ajax_snd_chat_portal_send',        [ $this, 'ajax_portal_send' ] );
		add_action( 'wp_ajax_nopriv_snd_chat_portal_get',  [ $this, 'ajax_portal_get' ] );
		add_action( 'wp_ajax_nopriv_snd_chat_portal_send', [ $this, 'ajax_portal_send' ] );

		// Unread badge
		add_action( 'admin_menu',            [ $this, 'add_unread_badge' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	// ── Submenu ───────────────────────────────────────────────────────────────

	public function register_submenu(): void {
		add_submenu_page( 'snd-dashboard',
			__( 'Partner Chat', 'nieuws-distributie-systeem' ),
			__( '💬 Partner Chat', 'nieuws-distributie-systeem' ),
			'publish_posts', 'snd-chat', [ $this, 'page_chat' ]
		);
	}

	public function add_unread_badge(): void {
		global $submenu;
		$count = $this->total_unread_admin();
		if ( $count > 0 && isset( $submenu['snd-dashboard'] ) ) {
			foreach ( $submenu['snd-dashboard'] as &$item ) {
				if ( ( $item[2] ?? '' ) === 'snd-chat' ) {
					$item[0] .= ' <span class="awaiting-mod">' . $count . '</span>';
					break;
				}
			}
		}
	}

	public function enqueue( string $hook ): void {
		if ( ( $_GET['page'] ?? '' ) !== 'snd-chat' ) return;
		wp_enqueue_style(  'snd-admin',      SND_PLUGIN_URL . 'css/admin.css',    [], SND_VERSION );
		wp_enqueue_script( 'snd-chat-admin', SND_PLUGIN_URL . 'js/admin-chat.js', [ 'jquery' ], SND_VERSION, true );
		wp_localize_script( 'snd-chat-admin', 'SND_Chat', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'snd_chat_nonce' ),
		] );
	}

	// ── Admin page ────────────────────────────────────────────────────────────

	public function page_chat(): void {
		$partner_emails = $this->get_emails_by_role( 'partner' );

		// Posts dispatched to ≥1 partner, or that already have chat threads
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [ [ 'key' => '_snd_dispatch_log', 'compare' => 'EXISTS' ] ],
		] );

		$eligible = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pid  = get_the_ID();
				$log  = get_post_meta( $pid, '_snd_dispatch_log', true );
				$sent_partners = is_array( $log )
					? array_filter( array_keys( $log ), fn( $e ) => in_array( $e, $partner_emails, true ) )
					: [];
				if ( empty( $sent_partners ) ) continue;

				// Count unread messages across all partner threads
				$total_unread = 0;
				foreach ( $sent_partners as $em ) {
					$total_unread += $this->count_unread_admin( $pid, $em );
				}

				$eligible[] = [
					'id'      => $pid,
					'title'   => get_the_title(),
					'date'    => get_the_date( 'd-m-Y' ),
					'unread'  => $total_unread,
					'partners'=> array_values( $sent_partners ),
				];
			}
			wp_reset_postdata();
		}
		?>
		<div class="wrap snd-admin-wrap" style="padding:0;">
			<div class="snd-chat-layout">

				<!-- Incidents sidebar -->
				<div class="snd-chat-sidebar">
					<div class="snd-chat-sidebar-header">
						<h2>💬 <?php esc_html_e( 'Partner Chat', 'nieuws-distributie-systeem' ); ?></h2>
						<p style="font-size:12px;color:#a0a0a0;margin:4px 0 0;"><?php esc_html_e( 'Per incident, per partner', 'nieuws-distributie-systeem' ); ?></p>
					</div>
					<div class="snd-chat-incident-list">
						<?php if ( empty( $eligible ) ) : ?>
							<p class="snd-chat-empty"><?php esc_html_e( 'Geen partner-gesprekken.', 'nieuws-distributie-systeem' ); ?></p>
						<?php else : ?>
							<?php foreach ( $eligible as $ep ) : ?>
								<button class="snd-chat-incident-item"
									data-postid="<?php echo esc_attr( $ep['id'] ); ?>"
									data-partners="<?php echo esc_attr( implode( ',', $ep['partners'] ) ); ?>">
									<span class="snd-chat-incident-title"><?php echo esc_html( $ep['title'] ); ?></span>
									<span class="snd-chat-incident-meta"><?php echo esc_html( $ep['date'] ); ?></span>
									<?php if ( $ep['unread'] > 0 ) : ?>
										<span class="snd-chat-unread-badge"><?php echo (int) $ep['unread']; ?></span>
									<?php endif; ?>
								</button>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- Thread area -->
				<div class="snd-chat-main">
					<div class="snd-chat-main-placeholder" id="snd-chat-placeholder">
						<div class="snd-chat-placeholder-icon">💬</div>
						<p><?php esc_html_e( 'Selecteer een incident.', 'nieuws-distributie-systeem' ); ?></p>
					</div>

					<div class="snd-chat-thread-wrap" id="snd-chat-thread-wrap" style="display:none;">

						<!-- Thread header with partner tabs -->
						<div class="snd-chat-thread-header">
							<div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;">
								<span class="snd-chat-thread-title" id="snd-chat-thread-title"></span>
								<div id="snd-chat-partner-tabs" class="snd-chat-partner-tabs"></div>
							</div>
							<a id="snd-chat-edit-link" href="#" target="_blank" class="button button-small">
								<?php esc_html_e( '✏ Bericht', 'nieuws-distributie-systeem' ); ?>
							</a>
						</div>

						<div class="snd-chat-messages" id="snd-chat-messages"></div>

						<div class="snd-chat-composer">
							<div class="snd-chat-composer-inner">
								<textarea id="snd-chat-input"
									placeholder="<?php esc_attr_e( 'Antwoord aan deze partner…', 'nieuws-distributie-systeem' ); ?>"
									rows="3"></textarea>
								<div class="snd-chat-composer-footer">
									<span class="snd-chat-composer-hint"><?php esc_html_e( 'Ctrl+Enter om te verzenden', 'nieuws-distributie-systeem' ); ?></span>
									<button id="snd-chat-send" class="button button-primary">
										<?php esc_html_e( 'Verstuur', 'nieuws-distributie-systeem' ); ?>
									</button>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>

		<style>
		.snd-chat-layout { height: calc(100vh - 78px); }
		.snd-chat-partner-tabs { display:flex; gap:4px; flex-wrap:wrap; }
		.snd-chat-partner-tab {
			padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
			border: 1px solid #ddd; background: transparent; cursor: pointer;
			transition: all .15s; color: #555;
		}
		.snd-chat-partner-tab.active { background: #2271b1; border-color: #2271b1; color: #fff; }
		.snd-chat-partner-tab .snd-tab-unread {
			display: inline-block; background: #d63638; color: #fff;
			border-radius: 8px; font-size: 10px; font-weight: 700;
			padding: 0 5px; margin-left: 4px; vertical-align: middle;
		}
		</style>
		<?php
	}

	// ── Admin AJAX ────────────────────────────────────────────────────────────

	private function verify_admin(): void {
		if ( ! check_ajax_referer( 'snd_chat_nonce', 'nonce', false ) || ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Geen permissie.' ], 403 );
		}
	}

	/** Load one partner's thread + mark admin as read */
	public function ajax_admin_get(): void {
		$this->verify_admin();
		$post_id      = absint( $_POST['post_id'] ?? 0 );
		$partner_email = sanitize_email( wp_unslash( $_POST['partner_email'] ?? '' ) );

		if ( ! $partner_email ) {
			wp_send_json_error( [ 'message' => 'Partner e-mail ontbreekt.' ] );
		}

		$messages = $this->get_thread( $post_id, $partner_email );

		// Mark partner messages as read by admin
		$changed = false;
		foreach ( $messages as &$msg ) {
			if ( ! $msg['read_admin'] ) { $msg['read_admin'] = true; $changed = true; }
		}
		unset( $msg );
		if ( $changed ) $this->save_thread( $post_id, $partner_email, $messages );

		// Also return unread counts per partner for the tabs
		$log            = get_post_meta( $post_id, '_snd_dispatch_log', true );
		$partner_emails = $this->get_emails_by_role( 'partner' );
		$outlets        = get_option( self::OPTION_OUTLETS, [] );
		$partners_data  = [];
		if ( is_array( $log ) ) {
			foreach ( array_keys( $log ) as $em ) {
				if ( ! in_array( $em, $partner_emails, true ) ) continue;
				$outlet_info = null;
				foreach ( $outlets as $o ) { if ( $o['email'] === $em ) { $outlet_info = $o; break; } }
				$partners_data[] = [
					'email'  => $em,
					'name'   => $outlet_info['name'] ?? $em,
					'unread' => $this->count_unread_admin( $post_id, $em ),
				];
			}
		}

		wp_send_json_success( [
			'messages' => $messages,
			'partners' => $partners_data,
			'edit_url' => get_edit_post_link( $post_id ),
		] );
	}

	/** Admin sends message to one specific partner thread */
	public function ajax_admin_send(): void {
		$this->verify_admin();
		$post_id       = absint( $_POST['post_id'] ?? 0 );
		$partner_email = sanitize_email( wp_unslash( $_POST['partner_email'] ?? '' ) );
		$body          = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

		if ( empty( $body ) || ! $partner_email ) {
			wp_send_json_error( [ 'message' => 'Tekst of partner ontbreekt.' ] );
		}

		$user     = wp_get_current_user();
		$msg      = $this->make_message( 'admin', $user->display_name, $user->user_email, $body );
		$messages = $this->get_thread( $post_id, $partner_email );
		$messages[] = $msg;
		$this->save_thread( $post_id, $partner_email, $messages );

		$this->notify_partner( $post_id, $body, $user->display_name, $partner_email );

		wp_send_json_success( [ 'message' => $msg ] );
	}

	public function ajax_admin_delete(): void {
		$this->verify_admin();
		$post_id       = absint( $_POST['post_id'] ?? 0 );
		$partner_email = sanitize_email( wp_unslash( $_POST['partner_email'] ?? '' ) );
		$msg_id        = sanitize_text_field( wp_unslash( $_POST['msg_id'] ?? '' ) );

		$messages = $this->get_thread( $post_id, $partner_email );
		$messages = array_values( array_filter( $messages, fn( $m ) => $m['id'] !== $msg_id ) );
		$this->save_thread( $post_id, $partner_email, $messages );
		wp_send_json_success();
	}

	// ── Portal AJAX ───────────────────────────────────────────────────────────

	private function auth_portal(): array {
		if ( ! check_ajax_referer( 'snd_portal_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Sessie verlopen.' ], 403 );
		}
		$code    = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		foreach ( $outlets as $outlet ) {
			if ( isset( $outlet['access_code'] ) && hash_equals( trim( $outlet['access_code'] ), $code ) ) {
				if ( ( $outlet['role'] ?? 'default' ) !== 'partner' ) {
					wp_send_json_error( [ 'message' => 'Alleen partners kunnen chatten.' ], 403 );
				}
				return $outlet;
			}
		}
		wp_send_json_error( [ 'message' => 'Ongeldige toegangscode.' ], 403 );
		return []; // unreachable
	}

	public function ajax_portal_get(): void {
		$outlet  = $this->auth_portal();
		$post_id = absint( $_POST['post_id'] ?? 0 );

		$log = get_post_meta( $post_id, '_snd_dispatch_log', true );
		if ( ! is_array( $log ) || ! isset( $log[ $outlet['email'] ] ) ) {
			wp_send_json_error( [ 'message' => 'Geen toegang.' ], 403 );
		}

		// Only this partner's thread
		$messages = $this->get_thread( $post_id, $outlet['email'] );

		// Mark admin messages as read by this partner
		$changed = false;
		foreach ( $messages as &$msg ) {
			if ( $msg['sender'] === 'admin' && ! $msg['read_partner'] ) {
				$msg['read_partner'] = true; $changed = true;
			}
		}
		unset( $msg );
		if ( $changed ) $this->save_thread( $post_id, $outlet['email'], $messages );

		wp_send_json_success( [ 'messages' => $messages ] );
	}

	public function ajax_portal_send(): void {
		$outlet  = $this->auth_portal();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$body    = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

		if ( empty( $body ) ) {
			wp_send_json_error( [ 'message' => 'Bericht is leeg.' ] );
		}

		$log = get_post_meta( $post_id, '_snd_dispatch_log', true );
		if ( ! is_array( $log ) || ! isset( $log[ $outlet['email'] ] ) ) {
			wp_send_json_error( [ 'message' => 'Geen toegang.' ], 403 );
		}

		$msg      = $this->make_message( 'partner', $outlet['name'], $outlet['email'], $body );
		$messages = $this->get_thread( $post_id, $outlet['email'] );
		$messages[] = $msg;
		$this->save_thread( $post_id, $outlet['email'], $messages );

		$this->notify_admin( $post_id, $body, $outlet['name'], $outlet['email'] );

		wp_send_json_success( [ 'message' => $msg ] );
	}

	// ── Storage ───────────────────────────────────────────────────────────────

	public function get_thread( int $post_id, string $email ): array {
		$raw = get_post_meta( $post_id, self::thread_key( $email ), true );
		return is_array( $raw ) ? $raw : [];
	}

	public function save_thread( int $post_id, string $email, array $messages ): void {
		if ( count( $messages ) > self::MAX_MESSAGES ) {
			$messages = array_slice( $messages, -self::MAX_MESSAGES );
		}
		update_post_meta( $post_id, self::thread_key( $email ), $messages );
		delete_transient( 'snd_chat_unread' );
	}

	private function make_message( string $sender, string $name, string $email, string $body ): array {
		return [
			'id'           => uniqid( 'msg_', true ),
			'sender'       => $sender,
			'name'         => $name,
			'email'        => $email,
			'body'         => $body,
			'ts'           => time(),
			'read_admin'   => $sender === 'admin',
			'read_partner' => $sender === 'partner',
		];
	}

	// ── Unread counts ─────────────────────────────────────────────────────────

	public function count_unread_admin( int $post_id, string $email ): int {
		return count( array_filter(
			$this->get_thread( $post_id, $email ),
			fn( $m ) => $m['sender'] === 'partner' && ! $m['read_admin']
		) );
	}

	private function total_unread_admin(): int {
		$cached = get_transient( 'snd_chat_unread' );
		if ( false !== $cached ) return (int) $cached;

		$partner_emails = $this->get_emails_by_role( 'partner' );
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'meta_query'     => [ [ 'key' => '_snd_dispatch_log', 'compare' => 'EXISTS' ] ],
			'fields'         => 'ids',
		] );
		$total = 0;
		foreach ( $query->posts as $pid ) {
			$log = get_post_meta( $pid, '_snd_dispatch_log', true );
			if ( ! is_array( $log ) ) continue;
			foreach ( array_keys( $log ) as $em ) {
				if ( in_array( $em, $partner_emails, true ) ) {
					$total += $this->count_unread_admin( $pid, $em );
				}
			}
		}
		set_transient( 'snd_chat_unread', $total, 60 );
		return $total;
	}

	// ── Notifications ─────────────────────────────────────────────────────────

	private function notify_admin( int $post_id, string $body, string $partner_name, string $partner_email ): void {
		$to         = get_option( 'snd_admin_notification_email', get_bloginfo( 'admin_email' ) );
		$site       = get_bloginfo( 'name' );
		$title      = get_the_title( $post_id );
		$from_name  = get_option( 'snd_email_from_name',  $site );
		$from_email = get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) );
		$url        = admin_url( 'admin.php?page=snd-chat&post_id=' . $post_id );

		wp_mail( $to,
			"[{$site}] Nieuw chatbericht van {$partner_name} bij: {$title}",
			$this->email_tpl( "Nieuw bericht van {$partner_name}", $title, $partner_name, $body, $url, 'Bekijk gesprek →', $site ),
			[ 'Content-Type: text/html; charset=UTF-8', "From: {$from_name} <{$from_email}>" ]
		);
	}

	private function notify_partner( int $post_id, string $body, string $admin_name, string $partner_email ): void {
		$outlets    = get_option( self::OPTION_OUTLETS, [] );
		$outlet     = null;
		foreach ( $outlets as $o ) { if ( $o['email'] === $partner_email ) { $outlet = $o; break; } }
		if ( ! $outlet ) return;

		$site       = get_bloginfo( 'name' );
		$title      = get_the_title( $post_id );
		$from_name  = get_option( 'snd_email_from_name',  $site );
		$from_email = get_option( 'snd_email_from_email', get_bloginfo( 'admin_email' ) );
		$url        = home_url( '/persportaal/?access_code=' . rawurlencode( $outlet['access_code'] ) );

		wp_mail( $partner_email,
			"[{$site}] Reactie van redactie bij: {$title}",
			$this->email_tpl( "Reactie van de redactie", $title, $admin_name, $body, $url, 'Bekijk en reageer in portaal →', $site ),
			[ 'Content-Type: text/html; charset=UTF-8', "From: {$from_name} <{$from_email}>" ]
		);
	}

	private function email_tpl( string $h, string $t, string $from, string $body, string $url, string $cta, string $site ): string {
		$b = nl2br( esc_html( $body ) );
		return "<html><body style='margin:0;padding:0;background:#f1f5f9;font-family:sans-serif;'>"
			. "<table width='100%'><tr><td align='center' style='padding:24px;'>"
			. "<table width='580' style='max-width:580px;'>"
			. "<tr><td bgcolor='#1e293b' style='padding:20px 28px;border-radius:8px 8px 0 0;'>"
			. "<p style='margin:0;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;'>{$site}</p>"
			. "<h1 style='margin:6px 0 0;font-size:17px;font-weight:700;color:#fff;'>{$h}</h1>"
			. "<p style='margin:4px 0 0;font-size:12px;color:#94a3b8;'>Bij: {$t}</p>"
			. "</td></tr>"
			. "<tr><td bgcolor='#fff' style='padding:24px 28px;border:1px solid #e2e8f0;border-top:none;'>"
			. "<div style='background:#f8fafc;border-left:4px solid #3b82f6;padding:14px 18px;border-radius:4px;margin-bottom:20px;'>"
			. "<p style='margin:0 0 6px;font-size:11px;font-weight:600;text-transform:uppercase;color:#64748b;'>{$from}</p>"
			. "<p style='margin:0;font-size:15px;line-height:1.7;color:#1e293b;'>{$b}</p></div>"
			. "<a href='{$url}' style='display:inline-block;padding:11px 22px;background:#3b82f6;color:#fff;border-radius:6px;font-size:14px;font-weight:600;text-decoration:none;'>{$cta}</a>"
			. "</td></tr>"
			. "<tr><td align='center' bgcolor='#f1f5f9' style='padding:14px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;'>"
			. "<p style='margin:0;font-size:11px;color:#94a3b8;'>© {$site}</p></td></tr>"
			. "</table></td></tr></table></body></html>";
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public function get_emails_by_role( string $role ): array {
		$outlets = get_option( self::OPTION_OUTLETS, [] );
		return array_values( array_map(
			fn( $o ) => $o['email'],
			array_filter( $outlets, fn( $o ) => ( $o['role'] ?? 'default' ) === $role )
		) );
	}
}
