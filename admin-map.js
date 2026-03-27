<?php
/**
 * Press portal template.
 * Loaded directly by SND_Portal::intercept_portal_request().
 * Available: $GLOBALS['snd_outlet'] (the authenticated outlet array)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$outlet     = $GLOBALS['snd_outlet'] ?? null;
if ( ! $outlet ) wp_die( 'Geen toegang.' );

$site_name  = get_bloginfo( 'name' );
$ajax_url   = admin_url( 'admin-ajax.php' );
$nonce      = wp_create_nonce( 'snd_portal_nonce' );
$access_code = esc_js( $outlet['access_code'] );

// Build list of posts sent to this outlet
$posts_query = new WP_Query( [
	'post_type'      => 'post',
	'posts_per_page' => 100,
	'post_status'    => 'publish',
	'orderby'        => 'date',
	'order'          => 'DESC',
	'meta_query'     => [ [
		'key'     => '_snd_dispatch_log',
		'value'   => '"' . esc_sql( $outlet['email'] ) . '"',
		'compare' => 'LIKE',
	] ],
] );

// Active P2000 meldingen (onderweg / ter plaatse) — visible to ALL outlets
$active_p2000 = get_option( 'snd_p2000_active_statuses', [] );
// Sort: ter_plaatse first, then onderweg, newest first
uasort( $active_p2000, function( $a, $b ) {
	$order = [ 'ter_plaatse' => 0, 'onderweg' => 1 ];
	$oa = $order[ $a['status'] ] ?? 2;
	$ob = $order[ $b['status'] ] ?? 2;
	if ( $oa !== $ob ) return $oa - $ob;
	return ( $b['time'] ?? 0 ) - ( $a['time'] ?? 0 );
} );
?>
<!DOCTYPE html>
<html lang="nl-NL">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( $site_name ); ?> – Persportaal</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
	<style>
		/* ── Reset & tokens ───────────────────────────────────────────── */
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		:root {
			--bg-0:   #0f0f0f;
			--bg-1:   #171717;
			--bg-2:   #222;
			--bg-3:   #2d2d2d;
			--border: #303030;
			--text-1: #f2f2f2;
			--text-2: #a3a3a3;
			--text-3: #6b6b6b;
			--accent: #3b82f6;
			--accent-h: #60a5fa;
			--live:   #ef4444;
			--font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
			--radius: 8px;
		}
		/* ── Light mode ────────────────────────────────────────────────── */
		body.light-mode {
			--bg-0:   #f8fafc;
			--bg-1:   #ffffff;
			--bg-2:   #f1f5f9;
			--bg-3:   #e2e8f0;
			--border: #e2e8f0;
			--text-1: #0f172a;
			--text-2: #475569;
			--text-3: #94a3b8;
			color-scheme: light;
		}
		html { scroll-behavior: smooth; }
		body { font-family: var(--font); background: var(--bg-0); color: var(--text-1); font-size: 16px; line-height: 1.6; color-scheme: dark; transition: background .2s, color .2s; }
		body.light-mode { color-scheme: light; }

		/* ── Search bar ─────────────────────────────────────────────── */
		.sidebar-search {
			padding: 10px 12px;
			border-bottom: 1px solid var(--border);
			flex-shrink: 0;
		}
		.sidebar-search input {
			width: 100%;
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: 6px;
			color: var(--text-1);
			padding: 7px 12px 7px 32px;
			font-size: 13px;
			font-family: var(--font);
			outline: none;
			transition: border-color .15s;
		}
		.sidebar-search input:focus { border-color: var(--accent); }
		.sidebar-search { position: relative; }
		.sidebar-search svg { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: var(--text-3); pointer-events: none; width: 14px; height: 14px; }
		.posts-list li.hidden { display: none; }

		/* ── Sort/filter bar ─────────────────────────────────────────── */
		.sidebar-filter {
			padding: 6px 12px;
			border-bottom: 1px solid var(--border);
			display: flex;
			gap: 6px;
			flex-shrink: 0;
			align-items: center;
		}
		.filter-btn {
			background: none;
			border: 1px solid var(--border);
			border-radius: 5px;
			color: var(--text-2);
			font-size: 11px;
			padding: 3px 9px;
			cursor: pointer;
			font-family: var(--font);
			transition: all .15s;
		}
		.filter-btn:hover, .filter-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
		.filter-count { margin-left: auto; font-size: 11px; color: var(--text-3); }

		/* ── New post indicator ───────────────────────────────────────── */
		.post-unread-dot {
			display: inline-block;
			width: 8px; height: 8px;
			border-radius: 50%;
			background: var(--accent);
			flex-shrink: 0;
			margin-top: 4px;
		}
		.posts-list li a.active .post-unread-dot { background: rgba(255,255,255,.9); }

		/* ── Chat badge ───────────────────────────────────────────────── */
		.chat-msg-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 16px; height: 16px;
			background: #ef4444;
			color: #fff;
			border-radius: 8px;
			font-size: 10px;
			font-weight: 700;
			padding: 0 4px;
			margin-left: 5px;
			vertical-align: middle;
		}

		/* ── Content search highlight ─────────────────────────────────── */
		.search-hit { background: rgba(59,130,246,.25); border-radius: 2px; }
		.post-new-badge {
			display: inline-block;
			background: var(--accent);
			color: #fff;
			font-size: 9px;
			font-weight: 800;
			padding: 1px 5px;
			border-radius: 4px;
			text-transform: uppercase;
			letter-spacing: .5px;
			vertical-align: middle;
			margin-left: 4px;
		}

		/* ── Reading time ────────────────────────────────────────────── */
		.reading-time { font-size: 11px; color: var(--text-3); margin-top: 2px; }

		/* ── Dark/light toggle ───────────────────────────────────────── */
		.theme-toggle {
			background: none;
			border: 1px solid var(--border);
			border-radius: 6px;
			color: var(--text-2);
			padding: 5px 8px;
			cursor: pointer;
			font-size: 15px;
			line-height: 1;
			transition: all .15s;
		}
		.theme-toggle:hover { border-color: var(--accent); color: var(--accent); }

		/* ── Auto-refresh indicator ──────────────────────────────────── */
		.refresh-indicator {
			display: flex;
			align-items: center;
			gap: 5px;
			font-size: 11px;
			color: var(--text-3);
			padding: 0 12px 6px;
		}
		.refresh-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text-3); flex-shrink: 0; }
		.refresh-dot.active { background: #10b981; animation: pulse 1.5s infinite; }

		/* ── New post notification banner ────────────────────────────── */
		#new-post-banner {
			display: none;
			background: var(--accent);
			color: #fff;
			padding: 10px 16px;
			font-size: 13px;
			font-weight: 600;
			cursor: pointer;
			text-align: center;
			flex-shrink: 0;
			animation: slideDown .3s ease;
		}
		@keyframes slideDown { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

		/* ── Layout ───────────────────────────────────────────────────── */
		.portal-wrap { display: flex; height: 100dvh; overflow: hidden; }

		/* Sidebar */
		#sidebar {
			width: 340px;
			flex-shrink: 0;
			background: var(--bg-1);
			border-right: 1px solid var(--border);
			display: flex;
			flex-direction: column;
			overflow: hidden;
		}
		.sidebar-header {
			padding: 24px 20px 16px;
			border-bottom: 1px solid var(--border);
			flex-shrink: 0;
		}
		.sidebar-header .site-name {
			font-size: 13px;
			font-weight: 600;
			color: var(--text-3);
			text-transform: uppercase;
			letter-spacing: .7px;
			margin-bottom: 4px;
		}
		.sidebar-header h1 { font-size: 1.1rem; font-weight: 700; }
		.posts-list {
			flex: 1;
			overflow-y: auto;
			padding: 10px;
			list-style: none;
		}
		.posts-list li a {
			display: flex;
			gap: 12px;
			padding: 10px;
			border-radius: var(--radius);
			text-decoration: none;
			color: var(--text-1);
			transition: background .15s;
			align-items: flex-start;
			margin-bottom: 4px;
			border: 1px solid transparent;
		}
		.posts-list li a:hover { background: var(--bg-2); }
		.posts-list li a.active { background: var(--accent); color: #fff; border-color: var(--accent-h); box-shadow: 0 2px 8px rgba(59,130,246,.35); }
		.posts-list li a.active .post-date { color: rgba(255,255,255,.7); }
		.posts-list li a.active .reading-time { color: rgba(255,255,255,.65); }

		/* ── Reading progress bar ─────────────────────────────── */
		#reading-progress {
			position: fixed;
			top: 0; left: 0;
			height: 3px;
			background: var(--accent);
			width: 0%;
			z-index: 9999;
			transition: width .1s linear;
			border-radius: 0 2px 2px 0;
			box-shadow: 0 0 8px rgba(59,130,246,.5);
		}

		/* ── Copy-paragraph hover button ─────────────────────── */
		.article-body p,
		.article-body li { position: relative; }
		.copy-para-btn {
			position: absolute;
			right: -36px;
			top: 0;
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: 6px;
			width: 28px; height: 28px;
			display: flex; align-items: center; justify-content: center;
			cursor: pointer;
			opacity: 0;
			transition: opacity .18s;
			font-size: 13px;
			color: var(--text-2);
			z-index: 10;
		}
		.copy-para-btn.show { opacity: 1; }
		.copy-para-btn:hover,
		.copy-para-btn.copied { background: var(--accent); color: #fff; border-color: var(--accent); opacity: 1; }
		/* ── ETA countdown ────────────────────────────────────────────── */
		#eta-block {
			display: none;
			background: linear-gradient(135deg, #fef3c7, #fde68a);
			border: 1px solid #fcd34d;
			border-radius: var(--radius);
			padding: 10px 16px;
			margin-bottom: 16px;
			font-size: 13px;
			font-weight: 600;
			color: #92400e;
			align-items: center;
			gap: 10px;
		}
		#eta-block .eta-time {
			font-size: 1.8rem;
			font-weight: 800;
			min-width: 2ch;
			font-feature-settings: 'tnum';
		}
		#reading-time-badge {
			display: none;
			font-size: 12px;
			color: var(--text-3);
			margin-bottom: 6px;
		}
		/* ── Bookmark button ──────────────────────────────────────── */
		#btn-bookmark {
			background: none;
			border: 1px solid var(--border);
			border-radius: 6px;
			padding: 6px 10px;
			cursor: pointer;
			font-size: 16px;
			color: var(--text-3);
			transition: all .15s;
			line-height: 1;
		}
		#btn-bookmark:hover { border-color: #f59e0b; color: #f59e0b; }
		#btn-bookmark.bookmarked { color: #f59e0b; border-color: #f59e0b; background: rgba(245,158,11,.08); }

		/* ── Search highlight ─────────────────────────────────────── */
		mark.snd-hl {
			background: rgba(250,204,21,.4);
			color: inherit;
			border-radius: 2px;
			padding: 0 1px;
		}

		/* ── Sound toggle ─────────────────────────────────────────── */
		#btn-sound {
			background: none;
			border: 1px solid var(--border);
			border-radius: 6px;
			padding: 6px 10px;
			cursor: pointer;
			font-size: 14px;
			color: var(--text-3);
			transition: all .15s;
			line-height: 1;
		}
		#btn-sound:hover { border-color: var(--accent); color: var(--accent); }
		#btn-sound.on { color: var(--accent); border-color: var(--accent); }
			width: 72px;
			height: 50px;
			border-radius: 4px;
			object-fit: cover;
			flex-shrink: 0;
			background: var(--bg-3);
		}
		.post-meta { flex: 1; min-width: 0; }
		.post-title { font-size: .875rem; font-weight: 600; line-height: 1.4; }
		.post-date  { font-size: .75rem; color: var(--text-2); margin-top: 3px; }
		.live-dot {
			display: inline-block;
			width: 8px; height: 8px;
			border-radius: 50%;
			background: var(--live);
			margin-right: 5px;
			animation: pulse 2s infinite;
			vertical-align: middle;
		}
		@keyframes pulse {
			0%   { box-shadow: 0 0 0 0 rgba(239,68,68,.6); }
			70%  { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
			100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
		}

		/* Main column */
		#main {
			flex: 1;
			display: flex;
			flex-direction: column;
			overflow: hidden;
		}
		.main-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 14px 32px;
			border-bottom: 1px solid var(--border);
			flex-shrink: 0;
		}
		.user-chip {
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: 20px;
			padding: 6px 14px;
			font-size: 13px;
			font-weight: 500;
		}
		#mobile-menu-btn {
			display: none;
			background: var(--bg-2);
			border: 1px solid var(--border);
			color: var(--text-1);
			border-radius: 6px;
			padding: 7px 14px;
			cursor: pointer;
			font-size: 14px;
		}

		.main-scroll { flex: 1; overflow-y: auto; }
		.main-inner  { max-width: 800px; margin: 0 auto; padding: 40px 32px; }

		/* Initial placeholder */
		#initial-msg { text-align: center; padding: 80px 20px; color: var(--text-2); }
		#initial-msg svg { width: 48px; height: 48px; opacity: .3; margin-bottom: 16px; }

		/* Loader */
		#loader { display: none; text-align: center; padding: 60px; }
		.snd-spinner {
			width: 36px; height: 36px;
			border: 3px solid var(--bg-3);
			border-top-color: var(--accent);
			border-radius: 50%;
			animation: spin .7s linear infinite;
			margin: 0 auto;
		}
		@keyframes spin { to { transform: rotate(360deg); } }

		/* Content */
		#content-area { display: none; }
		.article-title { font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 700; line-height: 1.25; margin-bottom: 8px; }
		.article-date  { color: var(--text-2); font-size: .875rem; margin-bottom: 28px; }

		.live-notice {
			background: rgba(239,68,68,.1);
			border-left: 4px solid var(--live);
			padding: 14px 18px;
			border-radius: 4px;
			margin-bottom: 28px;
			color: #fca5a5;
			font-size: .9rem;
		}

		/* ── Active P2000 section ───────────────────────────────── */
		.active-p2000-section {
			margin-bottom: 28px;
		}
		.active-p2000-card {
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: 8px;
			padding: 16px 18px;
			margin-bottom: 10px;
			position: relative;
			overflow: hidden;
		}
		.active-p2000-card.status-onderweg    { border-left: 4px solid #f59e0b; }
		.active-p2000-card.status-ter_plaatse { border-left: 4px solid #3b82f6; }
		.active-p2000-card-header {
			display: flex;
			align-items: center;
			gap: 10px;
			margin-bottom: 8px;
		}
		.active-p2000-badge {
			padding: 3px 10px;
			border-radius: 20px;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: .4px;
		}
		.badge-onderweg    { background: rgba(245,158,11,.2); color: #f59e0b; }
		.badge-ter_plaatse { background: rgba(59,130,246,.2); color: #60a5fa; }
		.active-p2000-tekst {
			font-family: 'Courier New', monospace;
			font-size: 12px;
			color: var(--text-2);
			margin-bottom: 10px;
			padding: 8px 12px;
			background: var(--bg-3);
			border-radius: 4px;
		}
		.active-p2000-meta {
			font-size: 12px;
			color: var(--text-3);
			display: flex;
			gap: 14px;
			flex-wrap: wrap;
		}
		.p2000-chat-toggle {
			margin-top: 10px;
			background: none;
			border: 1px solid var(--border);
			color: var(--text-2);
			padding: 6px 14px;
			border-radius: 6px;
			font-size: 12px;
			cursor: pointer;
			transition: all .15s;
		}
		.p2000-chat-toggle:hover { border-color: var(--accent); color: var(--accent); }
		.p2000-chat-area {
			margin-top: 12px;
			border-top: 1px solid var(--border);
			padding-top: 12px;
			display: none;
		}
		.p2000-chat-area textarea {
			width: 100%;
			background: var(--bg-3);
			border: 1px solid var(--border);
			border-radius: 6px;
			color: var(--text-1);
			padding: 10px 12px;
			font-size: 13px;
			resize: vertical;
			min-height: 70px;
			font-family: inherit;
		}
		.p2000-chat-area textarea:focus { outline: none; border-color: var(--accent); }
		.p2000-chat-send {
			margin-top: 8px;
			background: var(--accent);
			color: #fff;
			border: none;
			padding: 8px 18px;
			border-radius: 6px;
			font-size: 13px;
			cursor: pointer;
			font-weight: 600;
		}
		.p2000-chat-send:disabled { opacity: .5; }
		.p2000-chat-msgs {
			margin-bottom: 10px;
			max-height: 160px;
			overflow-y: auto;
		}
		.p2000-chat-msg {
			padding: 6px 0;
			border-bottom: 1px solid var(--bg-3);
			font-size: 13px;
		}
		.p2000-chat-msg-name { font-weight: 600; color: var(--accent); font-size: 11px; margin-bottom: 2px; }
		.p2000-chat-msg-text { color: var(--text-1); }

		.status-pill {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 4px 12px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: .3px;
		}
		.status-pill[data-s="onderweg"]    { background: rgba(245,158,11,.2); color: #f59e0b; border: 1px solid rgba(245,158,11,.4); }
		.status-pill[data-s="ter_plaatse"] { background: rgba(59,130,246,.2); color: #3b82f6; border: 1px solid rgba(59,130,246,.4); }
		.status-pill[data-s="afgerond"]    { background: rgba(16,185,129,.2); color: #10b981; border: 1px solid rgba(16,185,129,.4); }

		.p2000-block {
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			padding: 14px 18px;
			margin-bottom: 28px;
			font-family: 'Courier New', monospace;
			font-size: 13px;
			color: var(--text-2);
			white-space: pre-wrap;
			word-break: break-word;
		}

		/* P2000 meldingen uitklap */
		.p2000-melding-row {
			display: flex;
			gap: 10px;
			align-items: baseline;
			padding: 9px 14px;
			border-bottom: 1px solid var(--border);
			font-family: 'Courier New', monospace;
			font-size: 12px;
			color: var(--text-1);
		}
		.p2000-melding-row:last-child { border-bottom: none; }
		.p2000-melding-row.feed-row { background: rgba(59,130,246,.06); }
		.p2000-melding-row.manual-row { background: rgba(245,158,11,.06); }
		.p2000-type-pill {
			font-size: 9px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: .4px;
			padding: 2px 6px;
			border-radius: 4px;
			white-space: nowrap;
			flex-shrink: 0;
		}
		.p2000-time-col {
			font-size: 11px;
			color: var(--text-3);
			white-space: nowrap;
			flex-shrink: 0;
			min-width: 32px;
		}
		.p2000-source-tag {
			font-size: 9px;
			color: var(--text-3);
			white-space: nowrap;
			flex-shrink: 0;
		}

		.section-label {
			font-size: .75rem;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: .8px;
			color: var(--text-3);
			margin: 40px 0 16px;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

		.article-body { color: #d1d5db; font-size: 1.05rem; line-height: 1.75; }
		.article-body h2, .article-body h3 { color: var(--text-1); margin: 1.5em 0 .5em; }
		.article-body p  { margin-bottom: 1em; }
		.article-body img { max-width: 100%; border-radius: var(--radius); margin: 1em 0; }
		.article-body a  { color: var(--accent-h); }

		/* Map */
		#map-section { margin: 40px 0; }
		#portal-map  { height: 320px; border-radius: var(--radius); overflow: hidden; }

		/* Gallery */
		.gallery-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 16px;
			flex-wrap: wrap;
			gap: 10px;
		}
		.photo-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
			gap: 14px;
		}
		.photo-card {
			position: relative;
			background: var(--bg-2);
			border-radius: var(--radius);
			overflow: hidden;
			border: 2px solid transparent;
			transition: border-color .15s;
		}
		.photo-card.selected { border-color: var(--accent); }
		.photo-card img { width: 100%; height: 180px; object-fit: cover; display: block; cursor: pointer; transition: opacity .15s; }
		.photo-card img:hover { opacity: .88; }
		.photo-card-body { padding: 10px 12px; }
		.photo-filename { font-size: 12px; color: var(--text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.photo-caption  { font-size: 11px; color: var(--text-3); margin-top: 3px; }
		.photo-check {
			position: absolute;
			top: 8px; right: 8px;
			width: 22px; height: 22px;
			background: rgba(0,0,0,.5);
			border: 2px solid rgba(255,255,255,.7);
			border-radius: 4px;
			cursor: pointer;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.photo-check input { display: none; }
		.photo-check .tick { display: none; color: #fff; font-size: 14px; font-weight: 700; }
		.photo-card.selected .photo-check { background: var(--accent); border-color: var(--accent); }
		.photo-card.selected .tick { display: block; }


		/* ── Video lijst ─────────────────────────────────────────────── */
		.video-item {
			display: flex;
			align-items: center;
			gap: 14px;
			padding: 14px 16px;
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			margin-bottom: 10px;
			transition: border-color .15s;
		}
		.video-item:hover { border-color: var(--accent); }
		.video-type-badge {
			background: #1e293b;
			color: #fff;
			border-radius: 5px;
			padding: 3px 8px;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: .5px;
			flex-shrink: 0;
		}
		.video-info { flex: 1; min-width: 0; }
		.video-title {
			font-size: 13px;
			font-weight: 600;
			color: var(--text-1);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.video-meta { font-size: 11px; color: var(--text-3); margin-top: 2px; }
		.video-dl-btn {
			background: var(--accent);
			color: #fff;
			border: none;
			border-radius: 6px;
			padding: 8px 16px;
			font-size: 12px;
			font-weight: 600;
			cursor: pointer;
			white-space: nowrap;
			flex-shrink: 0;
			text-decoration: none;
			display: inline-block;
			transition: opacity .15s;
		}
		.video-dl-btn:hover { opacity: .85; }
		.btn {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 9px 18px;
			border-radius: 6px;
			border: none;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			text-decoration: none;
			transition: background .15s, opacity .15s;
		}
		.btn-primary { background: var(--accent); color: #fff; }
		.btn-primary:hover { background: var(--accent-h); color: #fff; }
		.btn-secondary { background: var(--bg-3); color: var(--text-1); border: 1px solid var(--border); }
		.btn-secondary:hover { background: var(--bg-2); }
		.btn:disabled { opacity: .5; cursor: default; }

		/* Mobile overlay */
		#sidebar-overlay {
			display: none;
			position: fixed;
			inset: 0;
			background: rgba(0,0,0,.6);
			z-index: 200;
		}

		/* ── Chat ─────────────────────────────────────────────────── */
		#chat-section {
			margin-top: 48px;
			border-top: 1px solid var(--border);
			padding-top: 32px;
		}
		.chat-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 16px;
		}
		.chat-messages {
			display: flex;
			flex-direction: column;
			gap: 6px;
			margin-bottom: 16px;
			max-height: 500px;
			overflow-y: auto;
			padding-right: 4px;
		}
		.chat-empty {
			text-align: center;
			padding: 32px 0;
			color: var(--text-3);
			font-size: 13px;
		}
		.chat-date-divider {
			text-align: center;
			font-size: 11px;
			color: var(--text-3);
			margin: 8px 0;
			position: relative;
		}
		.chat-date-divider::before {
			content: '';
			position: absolute;
			left: 0; right: 0; top: 50%;
			height: 1px;
			background: var(--border);
		}
		.chat-date-divider span {
			background: var(--bg-0);
			position: relative;
			padding: 0 10px;
			z-index: 1;
		}
		.chat-bubble {
			max-width: 72%;
			padding: 10px 14px;
			border-radius: 12px;
			font-size: 13px;
			line-height: 1.6;
			word-break: break-word;
		}
		.chat-bubble-admin {
			align-self: flex-start;
			background: var(--bg-2);
			color: var(--text-1);
			border: 1px solid var(--border);
			border-bottom-left-radius: 4px;
		}
		.chat-bubble-partner {
			align-self: flex-end;
			background: var(--accent);
			color: #fff;
			border-bottom-right-radius: 4px;
		}
		.chat-bubble-meta {
			font-size: 11px;
			opacity: .65;
			margin-bottom: 4px;
		}
		.chat-composer {
			display: flex;
			flex-direction: column;
			gap: 8px;
		}
		.chat-composer textarea {
			background: var(--bg-2);
			border: 1px solid var(--border);
			border-radius: 8px;
			color: var(--text-1);
			padding: 12px 14px;
			font-size: 14px;
			font-family: var(--font);
			resize: vertical;
			min-height: 80px;
			width: 100%;
			transition: border-color .15s;
		}
		.chat-composer textarea:focus {
			outline: none;
			border-color: var(--accent);
		}
		.chat-composer-footer {
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		.chat-composer-hint { font-size: 11px; color: var(--text-3); }
		.chat-loading { display: flex; align-items: center; justify-content: center; padding: 24px; color: var(--text-3); gap: 10px; font-size: 13px; }

		@media (max-width: 960px) {
			#sidebar {
				position: fixed;
				top: 0; left: 0; bottom: 0;
				z-index: 300;
				transform: translateX(-100%);
				transition: transform .25s ease;
				width: 300px;
			}
			body.sidebar-open #sidebar { transform: translateX(0); }
			body.sidebar-open #sidebar-overlay { display: block; }
			.main-header { padding: 12px 16px; }
			.main-inner  { padding: 20px 16px; }
			#mobile-menu-btn { display: block; }
		}
		@media print {
			#sidebar, .main-header, #share-btns, #gallery-section,
			#chat-section, .active-p2000-section, #incident-status-bar,
			.p2000-melding-row .p2000-source-tag { display: none !important; }
			#main { overflow: visible !important; }
			.main-inner { padding: 0 !important; max-width: 100% !important; }
			body { background: #fff !important; }
			.article-title { font-size: 22px !important; color: #000 !important; }
			.article-body { color: #000 !important; }
			#p2000-meldingen-list { display: block !important; }
			a { color: #000 !important; text-decoration: underline; }
		}
	</style>
</head>
<body>
<div id="reading-progress"></div>
<div class="portal-wrap">
	<!-- Sidebar -->
	<aside id="sidebar">
		<div class="sidebar-header">
			<div style="display:flex;justify-content:space-between;align-items:flex-start;">
				<div>
					<div class="site-name"><?php echo esc_html( $site_name ); ?></div>
					<h1>Persportaal</h1>
				</div>
				<button class="theme-toggle" id="theme-toggle" title="Licht/donker">🌙</button>
				<button id="btn-sound" title="Geluidssignaal bij nieuw bericht aan/uit">&#x1F514;</button>
			</div>
		</div>
		<div class="sidebar-search">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
			<input type="search" id="sidebar-search" placeholder="Zoek berichten..." autocomplete="off">
		</div>
		<div class="sidebar-filter">
			<button class="filter-btn active" data-filter="all">Alles</button>
			<button class="filter-btn" data-filter="live">&#x25CF; Live</button>
			<button class="filter-btn" data-filter="onderweg">&#x1F6A8; OW</button>
			<button class="filter-btn" data-filter="ter_plaatse">&#x1F4CD; TP</button>
			<button class="filter-btn" data-filter="bookmarked" title="Opgeslagen berichten">&#x2605;</button>
			<span class="filter-count" id="filter-count"><?php echo $posts_query->post_count; ?> berichten</span>
		</div>
		<div id="new-post-banner">&#x2191; Nieuw bericht beschikbaar &mdash; klik om te vernieuwen</div>
		<div class="refresh-indicator">
			<span class="refresh-dot active" id="refresh-dot"></span>
			<span id="refresh-status">Live</span>
		</div>
		<ul class="posts-list" id="posts-list">
			<?php
			if ( $posts_query->have_posts() ) :
				while ( $posts_query->have_posts() ) :
					$posts_query->the_post();

					// Skip expired posts
					$exp = get_post_meta( get_the_ID(), '_snd_expiration_date', true );
					if ( ! empty( $exp ) && current_time( 'Y-m-d' ) > $exp ) continue;

					$is_live    = get_post_meta( get_the_ID(), '_snd_is_live_story', true ) === '1';
					$inc_status = get_post_meta( get_the_ID(), '_snd_incident_status', true );
					// Reading time estimate
					$word_count   = str_word_count( wp_strip_all_tags( get_the_content() ) );
					$reading_mins = max( 1, round( $word_count / 200 ) );
					$photo_count  = count( array_filter( explode( ',', get_post_meta( get_the_ID(), '_snd_press_photos', true ) ) ) );
					// Video count
					$vid_json   = get_post_meta( get_the_ID(), '_snd_press_videos', true );
					$vid_data   = ! empty( $vid_json ) ? json_decode( $vid_json, true ) : [];
					$video_count = is_array( $vid_data ) ? count( $vid_data ) : 0;
					?>
					<li data-title="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>"
						data-live="<?php echo $is_live ? '1' : '0'; ?>"
						data-status="<?php echo esc_attr( $inc_status ?: '' ); ?>"
						data-date="<?php echo esc_attr( get_the_date( 'U' ) ); ?>"
						data-postid="<?php echo get_the_ID(); ?>"
						data-content="<?php echo esc_attr( strtolower( wp_strip_all_tags( get_the_content() ) ) ); ?>">
						<a href="#" class="snd-post-link" data-postid="<?php echo get_the_ID(); ?>">
							<?php if ( has_post_thumbnail() ) : ?>
								<img src="<?php echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' ) ); ?>" class="post-thumb" loading="lazy" alt="">
							<?php else : ?>
								<div class="post-thumb"></div>
							<?php endif; ?>
							<div class="post-meta">
								<div class="post-title">
									<?php if ( $is_live ) : ?><span class="live-dot" title="Live"></span><?php endif; ?>
									<?php the_title(); ?>
									<span class="post-unread-dot" id="unread-<?php echo get_the_ID(); ?>" style="display:none;"></span>
								</div>
								<div class="post-date"><?php echo esc_html( get_the_date() ); ?></div>
								<div class="reading-time">
									<?php echo $reading_mins; ?> min lezen
									<?php if ( $photo_count > 0 ) echo ' · 📷 ' . $photo_count . ' foto' . ( $photo_count > 1 ? "'s" : '' ); ?>
									<?php if ( $video_count > 0 ) echo " · 🎬 Video's beschikbaar"; ?>
								</div>
								<?php
										// Incident status badge — alleen bij live+actief incident
										if ( $is_live && $inc_status && in_array( $inc_status, ['onderweg', 'ter_plaatse'], true ) ) {
											$status_cfg = [
												'onderweg'    => [ 'label' => '🚨 Onderweg',    'bg' => 'rgba(245,158,11,.25)', 'color' => '#f59e0b' ],
												'ter_plaatse' => [ 'label' => '📍 Ter plaatse', 'bg' => 'rgba(59,130,246,.25)',  'color' => '#60a5fa' ],
											];
											$sc = $status_cfg[ $inc_status ];
											echo '<span style="display:inline-block;background:' . esc_attr( $sc['bg'] ) . ';color:' . esc_attr( $sc['color'] ) . ';font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;margin-top:3px;">' . esc_html( $sc['label'] ) . '</span>';
										}
								// Show unread count for partners
								if ( ( $outlet['role'] ?? 'default' ) === 'partner' ) {
									$msgs   = get_post_meta( get_the_ID(), '_snd_chat_messages', true );
									$unread = 0;
									if ( is_array( $msgs ) ) {
										foreach ( $msgs as $m ) {
											if ( $m['sender'] === 'admin' && ! $m['read_partner'] ) $unread++;
										}
									}
									if ( $unread > 0 ) {
										echo '<span style="display:inline-block;background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;margin-top:3px;">💬 ' . $unread . ' nieuw</span>';
									}
								}
								?>
							</div>
						</a>
					</li>
				<?php
				endwhile;
				wp_reset_postdata();
			else :
				echo '<li style="padding:16px;color:var(--text-2);font-size:.875rem;">' . esc_html__( 'Geen persberichten beschikbaar.', 'nieuws-distributie-systeem' ) . '</li>';
			endif;
			?>
		</ul>
	</aside>

	<!-- Main -->
	<div id="main">
		<header class="main-header">
			<button id="mobile-menu-btn" aria-label="Menu">☰ Berichten</button>
			<div style="display:flex;align-items:center;gap:10px;">
				<a href="<?php echo esc_url( home_url( '/persportaal/kaart/?access_code=' . rawurlencode( $outlet['access_code'] ) ) ); ?>" style="font-size:13px;color:var(--text-2);text-decoration:none;padding:6px 12px;border:1px solid var(--border);border-radius:6px;transition:background .15s;" onmouseover="this.style.background='var(--bg-2)'" onmouseout="this.style.background=''">🗺 Kaartoverzicht</a>
				<div class="user-chip">Ingelogd als: <strong><?php echo esc_html( $outlet['name'] ); ?></strong></div>
			</div>
		</header>

		<div class="main-scroll">
			<div class="main-inner">

				<?php if ( ! empty( $active_p2000 ) ) : ?>
				<!-- Active P2000 meldingen: visible to all outlets -->
				<div class="active-p2000-section">
					<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
						<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-3);">Live meldingen</span>
						<span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;animation:pulse 1.5s infinite;"></span>
					</div>
					<?php foreach ( $active_p2000 as $p2000_id => $item ) :
						$is_onderweg = $item['status'] === 'onderweg';
						$badge_class = $is_onderweg ? 'badge-onderweg' : 'badge-ter_plaatse';
						$card_class  = 'status-' . $item['status'];
						$locatie     = trim( ( $item['straat'] ?? '' ) ? ( $item['straat'] . ', ' . $item['stad'] ) : $item['stad'] );
						$tijd_str    = isset( $item['time'] ) ? wp_date( 'H:i', $item['time'] ) : '';
					?>
					<div class="active-p2000-card <?php echo esc_attr( $card_class ); ?>" data-p2000id="<?php echo esc_attr( $p2000_id ); ?>">
						<div class="active-p2000-card-header">
							<span class="active-p2000-badge <?php echo esc_attr( $badge_class ); ?>">
								<?php echo $is_onderweg ? '🚨 Fotograaf onderweg' : '📍 Fotograaf ter plaatse'; ?>
							</span>
							<?php if ( $tijd_str ) : ?>
								<span style="font-size:11px;color:var(--text-3);">sinds <?php echo esc_html( $tijd_str ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $locatie ) : ?>
							<div style="font-size:13px;font-weight:600;color:var(--text-1);margin-bottom:6px;">📍 <?php echo esc_html( $locatie ); ?></div>
						<?php endif; ?>
						<div class="active-p2000-tekst"><?php echo esc_html( $item['tekst'] ?? '' ); ?></div>
						<div class="active-p2000-meta">
							<span style="color:var(--text-3);">Meer informatie volgt via een persbericht.</span>
						</div>
						<!-- Chat: send questions/messages about this P2000 item -->
						<button class="p2000-chat-toggle" data-p2000id="<?php echo esc_attr( $p2000_id ); ?>">
							💬 Stel een vraag of stuur informatie
						</button>
						<div class="p2000-chat-area" id="p2000chat-<?php echo esc_attr( $p2000_id ); ?>">
							<div class="p2000-chat-msgs" id="p2000msgs-<?php echo esc_attr( $p2000_id ); ?>"></div>
							<textarea class="p2000-chat-input" data-p2000id="<?php echo esc_attr( $p2000_id ); ?>"
								placeholder="Stel een vraag, stuur info of geef een tip over deze melding…"></textarea>
							<button class="p2000-chat-send" data-p2000id="<?php echo esc_attr( $p2000_id ); ?>">Verstuur</button>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div id="initial-msg">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 01-.923 1.785A5.969 5.969 0 006 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337z"/></svg>
					<p>Selecteer een bericht uit de lijst.</p>
				</div>

				<div id="loader"><div class="snd-spinner"></div></div>

				<div id="content-area">
					<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:4px;">
						<h2 class="article-title" id="article-title"></h2>
						<div style="display:flex;gap:6px;align-items:center;flex-shrink:0;margin-top:6px;">
							<button id="btn-bookmark" title="Sla op als favoriet">☆</button>
							<div id="share-btns" style="display:none;flex-shrink:0;display:flex;gap:6px;">
								<button id="share-copy-btn" title="Kopieer link naar artikel"
									style="background:var(--bg-2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;cursor:pointer;font-size:12px;color:var(--text-2);white-space:nowrap;">
									📋 Link
								</button>
								<a id="share-email-btn" href="#"
									style="background:var(--bg-2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;cursor:pointer;font-size:12px;color:var(--text-2);text-decoration:none;white-space:nowrap;">
									✉ Mail
								</a>
								<a id="share-wa-btn" href="#" target="_blank"
									style="background:#25d366;border:1px solid #25d366;border-radius:6px;padding:6px 10px;font-size:12px;color:#fff;text-decoration:none;white-space:nowrap;">
									WhatsApp
								</a>
								<button onclick="window.print()" title="Artikel afdrukken / opslaan als PDF"
									style="background:var(--bg-2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;cursor:pointer;font-size:12px;color:var(--text-2);white-space:nowrap;">
									&#x1F5A8; Print
								</button>
							</div>
						</div>
					</div>
					<p class="article-date" id="article-date"></p>
					<div id="reading-time-badge">&#x1F4D6; <span id="reading-time-val"></span></div>
					<div id="eta-block" style="display:none;align-items:center;gap:10px;">
						&#x23F1; Fotograaf arriveert over <span class="eta-time" id="eta-minutes">0</span> min
					</div>

					<div id="live-notice" class="live-notice" style="display:none;">
						<strong>Let op:</strong> Dit is een live verslag en kan nog aangevuld worden.
					</div>

					<!-- Incident status bar — alleen bij live/actief incident -->
					<div id="incident-status-bar" style="display:none;margin-bottom:16px;">
						<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
							<span style="font-size:12px;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;">Status:</span>
							<span id="status-onderweg"    class="status-pill" data-s="onderweg"    style="display:none;">🚨 Onderweg</span>
							<span id="status-ter_plaatse" class="status-pill" data-s="ter_plaatse" style="display:none;">📍 Ter plaatse</span>
						</div>
					</div>


				<!-- P2000 meldingen uitklap sectie -->
				<div id="p2000-meldingen-section" style="display:none;margin-bottom:24px;">
					<button id="p2000-toggle" style="display:flex;align-items:center;gap:8px;width:100%;background:var(--bg-2);border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;cursor:pointer;font-family:var(--font);font-size:13px;font-weight:600;color:var(--text-1);text-align:left;transition:background .15s;">
						<span id="p2000-toggle-icon" style="font-size:11px;transition:transform .2s;">▶</span>
						<span>P2000 Meldingen</span>
						<span id="p2000-count" style="margin-left:auto;background:var(--bg-3);border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;color:var(--text-2);"></span>
					</button>
					<div id="p2000-meldingen-list" style="display:none;border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);overflow:hidden;"></div>
				</div>

					<div class="section-label">Persbericht</div>
					<div class="article-body" id="article-body"></div>

					<!-- Byline / naamsvermelding -->
					<div id="byline-block" style="display:none;margin-top:12px;font-size:12px;color:var(--text-2);font-style:italic;border-top:1px solid rgba(255,255,255,.1);padding-top:10px;"></div>

					<div id="map-section" style="display:none;">
						<div class="section-label">Locatie op kaart</div>
						<div id="portal-map"></div>
					</div>

					<div id="gallery-section" style="display:none; margin-top:40px;">
						<div class="gallery-header">
							<div class="section-label" style="margin:0;">Beschikbare media</div>
							<div style="display:flex;gap:8px;flex-wrap:wrap;">
								<button id="btn-dl-selection" class="btn btn-secondary" style="display:none;">
									Download selectie (<span id="sel-count">0</span>)
								</button>
								<button id="btn-dl-all" class="btn btn-primary">Download alles als ZIP</button>
							</div>
						</div>
						<div class="photo-grid" id="photo-grid"></div>
					</div>

					<!-- Video sectie -->
					<div id="video-section" style="display:none;margin-top:40px;">
						<div class="section-label">📹 Beschikbare video's</div>
						<div id="video-list"></div>
					</div>

					<div id="download-blocked-notice" style="display:none;margin-top:32px;background:rgba(239,68,68,.1);border-left:4px solid #ef4444;padding:16px 20px;border-radius:4px;color:#fca5a5;">
						<strong>Downloadtoegang ingetrokken.</strong> U kunt de foto's voor dit bericht momenteel niet downloaden. Neem contact op met de redactie.
					</div>

					<p id="no-photos-msg" style="display:none;color:var(--text-2);margin-top:32px;">Geen persfoto's beschikbaar voor dit bericht.</p>

					<?php if ( ( $outlet['role'] ?? 'default' ) === 'partner' ) : ?>
					<!-- Partner chat – only visible to partners -->
					<div id="chat-section" style="display:none;">
						<div class="chat-header">
							<div class="section-label" style="margin:0;">💬 Gesprek met redactie</div>
						</div>
						<div class="chat-messages" id="chat-messages">
							<div class="chat-loading"><div class="snd-spinner" style="width:24px;height:24px;border-width:2px;"></div> Laden…</div>
						</div>
						<div class="chat-composer" id="chat-composer">
							<textarea id="chat-input" placeholder="Stel een vraag of stuur een bericht…"></textarea>
							<div class="chat-composer-footer">
								<span class="chat-composer-hint">Ctrl+Enter om te versturen</span>
								<button id="chat-send" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">Verstuur</button>
							</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<div id="sidebar-overlay"></div>

<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Global bookmark helpers (accessible from all script blocks) ──────────────
(function() {
	var BM_KEY   = 'snd_bookmarks_<?php echo esc_js( $outlet['access_code'] ); ?>';
	var SND_KEY  = 'snd_sound_<?php echo esc_js( $outlet['access_code'] ); ?>';
	window.SND_getBookmarks = function() {
		try { return JSON.parse(localStorage.getItem(BM_KEY) || '[]'); } catch(e) { return []; }
	};
	window.SND_saveBookmarks = function(arr) {
		try { localStorage.setItem(BM_KEY, JSON.stringify(arr)); } catch(e) {}
	};
	window.SND_getSoundEnabled = function() {
		return localStorage.getItem(SND_KEY) !== 'off';
	};
	window.SND_setSoundEnabled = function(val) {
		localStorage.setItem(SND_KEY, val ? 'on' : 'off');
	};
})();
</script>

<script>
(function ($) {
	'use strict';

	const AJAX   = <?php echo wp_json_encode( $ajax_url ); ?>;
	const NONCE  = <?php echo wp_json_encode( $nonce ); ?>;
	const CODE   = <?php echo wp_json_encode( $outlet['access_code'] ); ?>;

	// ── Ongelezen badges initialiseren ───────────────────────────────────────
	(function initUnread() {
		try {
			const readKey = 'snd_read_' + CODE;
			const read = JSON.parse(localStorage.getItem(readKey) || '[]');
			document.querySelectorAll('#posts-list li[data-postid]').forEach(function(li) {
				const pid = parseInt(li.dataset.postid, 10);
				if (!read.includes(pid)) {
					const dot = document.getElementById('unread-' + pid);
					if (dot) dot.style.display = 'inline-block';
				}
			});
		} catch(e) {}
	})();

	let currentPostId = 0;
	let selectedIds   = [];
	let lbPhotos      = [];
	let mapInstance   = null;

	// ── Leesvoortgang-balk ────────────────────────────────────────────────────
	(function() {
		const bar = document.getElementById('reading-progress');
		if (!bar) return;
		window.addEventListener('scroll', function() {
			const art = document.getElementById('article-body');
			if (!art) { bar.style.width = '0%'; return; }
			const rect = art.getBoundingClientRect();
			const total = art.offsetHeight - window.innerHeight;
			if (total <= 0) { bar.style.width = '100%'; return; }
			const pct = Math.min(100, Math.max(0, (-rect.top / total) * 100));
			bar.style.width = pct + '%';
		}, { passive: true });
	})();

	// ── Paragraaf kopiëren op hover (met delay zodat knop klikbaar blijft) ───────
	let copyHideTimer = null;
	$(document).on('mouseenter', '#article-body p, #article-body li', function() {
		clearTimeout(copyHideTimer);
		if (!$(this).find('.copy-para-btn').length) {
			$(this).append('<button class="copy-para-btn" title="Kopieer alinea">📋</button>');
		}
		$(this).find('.copy-para-btn').addClass('show');
	}).on('mouseleave', '#article-body p, #article-body li', function() {
		const $btn = $(this).find('.copy-para-btn');
		copyHideTimer = setTimeout(function() {
			$btn.removeClass('show');
		}, 300);
	});
	$(document).on('mouseenter', '.copy-para-btn', function() {
		clearTimeout(copyHideTimer);
		$(this).addClass('show');
	}).on('mouseleave', '.copy-para-btn', function() {
		const $btn = $(this);
		copyHideTimer = setTimeout(function() {
			$btn.removeClass('show');
		}, 200);
	});
	$(document).on('click', '.copy-para-btn', function(e) {
		e.stopPropagation();
		const $btn = $(this);
		const text = $btn.parent().clone().children('.copy-para-btn').remove().end().text().trim();
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function() {
				$btn.text('✓').addClass('copied');
				setTimeout(function() { $btn.text('📋').removeClass('copied'); }, 1800);
			});
		}
	});

	// Mobile sidebar
	$('#mobile-menu-btn').on('click', () => $('body').toggleClass('sidebar-open'));

	// ── Feature: Bookmarks ─────────────────────────────────────────────────────
	function updateBookmarkBtn(postId) {
		const saved = window.SND_getBookmarks().includes(postId);
		$('#btn-bookmark').text(saved ? '★' : '☆').toggleClass('bookmarked', saved)
			.attr('title', saved ? 'Verwijder uit favorieten' : 'Sla op als favoriet');
	}
	$('#btn-bookmark').on('click', function() {
		if (!currentPostId) return;
		let bm = window.SND_getBookmarks();
		const idx = bm.indexOf(currentPostId);
		if (idx > -1) bm.splice(idx, 1);
		else bm.push(currentPostId);
		window.SND_saveBookmarks(bm);
		updateBookmarkBtn(currentPostId);
	});

	// ── Feature: Geluidssignaal bij nieuw bericht ─────────────────────────────
	function updateSoundBtn() {
		const on = window.SND_getSoundEnabled();
		$('#btn-sound').text(on ? '🔔' : '🔕')
			.toggleClass('on', on)
			.attr('title', on ? 'Geluid uit' : 'Geluid aan');
	}
	updateSoundBtn();
	$('#btn-sound').on('click', function() {
		window.SND_setSoundEnabled(!window.SND_getSoundEnabled());
		updateSoundBtn();
	});
	function playNewPostSound() {
		if (!window.SND_getSoundEnabled()) return;
		try {
			const ctx = new (window.AudioContext || window.webkitAudioContext)();
			const osc = ctx.createOscillator();
			const gain = ctx.createGain();
			osc.connect(gain); gain.connect(ctx.destination);
			osc.frequency.setValueAtTime(880, ctx.currentTime);
			osc.frequency.setValueAtTime(1100, ctx.currentTime + 0.08);
			gain.gain.setValueAtTime(0.15, ctx.currentTime);
			gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
			osc.start(); osc.stop(ctx.currentTime + 0.35);
		} catch(e) {}
	}

	// ── P2000 meldingen uitklaptablatje ───────────────────────────────────────
	$(document).on('click', '#p2000-toggle', function() {
		const $list = $('#p2000-meldingen-list');
		const $icon = $('#p2000-toggle-icon');
		const isOpen = $list.is(':visible');
		$list.slideToggle(180);
		$icon.css('transform', isOpen ? 'rotate(0deg)' : 'rotate(90deg)');
	});
	$('#sidebar-overlay').on('click', () => $('body').removeClass('sidebar-open'));

	// Select a post
	$('.snd-post-link').on('click', async function (e) {
		e.preventDefault();
		$('.snd-post-link').removeClass('active');
		$(this).addClass('active');
		$('body').removeClass('sidebar-open');

		currentPostId = parseInt(this.dataset.postid, 10);
		selectedIds   = [];

		// Mark as read
		try {
			const readKey = 'snd_read_' + CODE;
			const read = JSON.parse(localStorage.getItem(readKey) || '[]');
			if (!read.includes(currentPostId)) read.push(currentPostId);
			localStorage.setItem(readKey, JSON.stringify(read));
			$('#unread-' + currentPostId).hide();
		} catch(e) {}

		// Update bookmark state
		updateBookmarkBtn(currentPostId);
		$('#initial-msg, #content-area').hide();
		$('#loader').show();

		try {
			const res = await post('snd_portal_get_post', { post_id: currentPostId, access_code: CODE });
			if (res.success) {
				renderContent(this, res.data);
			} else {
				alert('Fout: ' + (res.data?.message || 'Onbekende fout.'));
			}
		} catch {
			alert('Serverfout.');
		} finally {
			$('#loader').hide();
		}
	});

	// Download all ZIP
	$('#btn-dl-all').on('click', () => triggerZip([]));

	// Download selection ZIP
	$('#btn-dl-selection').on('click', () => triggerZip(selectedIds));

	$(document).on('click', '.photo-card', function (e) {
		if ($(e.target).closest('a').length) return;
		togglePhoto($(this));
	});

	// Klik op foto = direct downloaden + loggen
	$(document).on('click', '.photo-dl-link', function (e) {
		e.preventDefault();
		const card    = $(this).closest('.photo-card');
		const imageId = parseInt(card.data('id'), 10);
		logDownload(imageId);
		const a = document.createElement('a');
		a.href = this.href;
		a.download = this.dataset.filename || 'foto';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
	});


		// After rendering content, show chat if partner
	const IS_PARTNER = <?php echo wp_json_encode( ( $outlet['role'] ?? 'default' ) === 'partner' ); ?>;

	// ── Chat ─────────────────────────────────────────────────────────────────
	let chatPostId     = 0;
	let chatPollTimer  = null;
	let chatLastTs     = 0;
	let chatSending    = false;

	function initChat(postId) {
		if (!IS_PARTNER) return;
		chatPostId  = postId;
		chatLastTs  = 0;
		clearInterval(chatPollTimer);

		$('#chat-section').show();
		$('#chat-messages').html('<div class="chat-loading"><div class="snd-spinner" style="width:24px;height:24px;border-width:2px;"></div> Laden…</div>');

		loadChatMessages(true);
		chatPollTimer = setInterval(() => loadChatMessages(false), 12000);
	}

	async function loadChatMessages(fullReload) {
		try {
			const res = await post('snd_chat_portal_get', { post_id: chatPostId, access_code: CODE });
			if (!res.success) return;
			const msgs = res.data.messages || [];

			if (fullReload) {
				renderChatMessages(msgs);
			} else {
				const newMsgs = msgs.filter(m => m.ts > chatLastTs);
				if (newMsgs.length) {
					newMsgs.forEach(m => appendChatBubble(m));
					scrollChat();
				}
			}
			if (msgs.length) chatLastTs = Math.max(...msgs.map(m => m.ts));
		} catch {}
	}

	function renderChatMessages(msgs) {
		const $c = $('#chat-messages').empty();
		if (!msgs.length) {
			$c.html('<div class="chat-empty">Nog geen berichten. Stel gerust een vraag!</div>');
			return;
		}
		let lastDate = '';
		msgs.forEach(m => {
			const d = chatFormatDate(m.ts);
			if (d !== lastDate) {
				$c.append(`<div class="chat-date-divider"><span>${escHtml(d)}</span></div>`);
				lastDate = d;
			}
			appendChatBubble(m, false);
		});
		scrollChat(false);
	}

	function appendChatBubble(msg, scroll = true) {
		const isMe = msg.sender === 'partner';
		const time = new Date(msg.ts * 1000).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Amsterdam' });
		const cls  = isMe ? 'chat-bubble-partner' : 'chat-bubble-admin';
		$('#chat-messages').append(`
			<div class="chat-bubble ${cls}">
				<div class="chat-bubble-meta">${escHtml(msg.name)} · ${time}</div>
				${escHtml(msg.body).replace(/\n/g, '<br>')}
			</div>
		`);
		if (scroll) scrollChat();
	}

	function scrollChat(smooth = true) {
		const el = document.getElementById('chat-messages');
		if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
	}

	function chatFormatDate(ts) {
		const d = new Date(ts * 1000);
		const t = new Date();
		const y = new Date(t); y.setDate(t.getDate() - 1);
		if (d.toDateString() === t.toDateString()) return 'Vandaag';
		if (d.toDateString() === y.toDateString()) return 'Gisteren';
		return d.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', timeZone: 'Europe/Amsterdam' });
	}

	$('#chat-send').on('click', sendChatMessage);
	$('#chat-input').on('keydown', function(e) {
		if (e.ctrlKey && e.key === 'Enter') sendChatMessage();
	});

	async function sendChatMessage() {
		const body = $('#chat-input').val().trim();
		if (!body || chatSending || !chatPostId) return;
		chatSending = true;
		$('#chat-send').prop('disabled', true).text('…');
		try {
			const res = await post('snd_chat_portal_send', { post_id: chatPostId, access_code: CODE, body });
			if (res.success) {
				$('#chat-input').val('');
				$('#chat-messages .chat-empty').remove();
				appendChatBubble(res.data.message);
				chatLastTs = Math.max(chatLastTs, res.data.message.ts);
			} else {
				alert(res.data?.message || 'Fout bij verzenden.');
			}
		} catch {
			alert('Serverfout.');
		} finally {
			chatSending = false;
			$('#chat-send').prop('disabled', false).text('Verstuur');
		}
	}

	// ── Render (extend existing renderContent) ───────────────────────────────
	function renderContent(linkEl, data) {
		const title = $(linkEl).find('.post-title').text().trim();
		const postId = $(linkEl).data('postid');
		$('#article-title').text(title);
		$('#article-date').text($(linkEl).find('.post-date').text().trim());
		$('#live-notice').toggle(!!data.is_live);

		// Share buttons — deelt het publieke artikel, niet de portaallink
		const shareUrl = data.post_url || '';
		if (shareUrl) {
			$('#share-btns').css('display','flex');
			$('#share-copy-btn').off('click').on('click', function(){
				const $btn = $(this);
				if (navigator.clipboard) {
					navigator.clipboard.writeText(shareUrl).then(function(){
						$btn.text('✓ Gekopieerd!').css('color','#16a34a');
						setTimeout(function(){ $btn.text('📋 Link').css('color',''); }, 2000);
					});
				}
			});
			$('#share-email-btn').attr('href',
				'mailto:?subject=' + encodeURIComponent(title) +
				'&body=' + encodeURIComponent(shareUrl)
			);
			$('#share-wa-btn').attr('href',
				'https://wa.me/?text=' + encodeURIComponent(title + '\n' + shareUrl)
			);
		} else {
			$('#share-btns').hide();
		}

		// Incident status — alleen tonen als live EN status is onderweg of ter_plaatse
		const activeStatus = data.incident_status || '';
		const statusTimes  = data.status_times || {};
		const statusBar    = $('#incident-status-bar');
		const liveStatuses = ['onderweg', 'ter_plaatse'];

		if ( data.is_live && liveStatuses.includes(activeStatus) ) {
			['onderweg', 'ter_plaatse'].forEach(s => {
				const $pill = $('#status-' + s);
				const isActive = s === activeStatus;
				if (isActive) {
					const ts = statusTimes[s];
					const timeStr = ts ? ' ' + new Date(ts * 1000).toLocaleTimeString('nl-NL', {hour:'2-digit',minute:'2-digit',timeZone:'Europe/Amsterdam'}) : '';
					const label = s === 'onderweg' ? '🚨 Onderweg' : '📍 Ter plaatse';
					$pill.text(label + timeStr).show();
				} else {
					$pill.hide();
				}
			});
			statusBar.show();
		} else {
			statusBar.hide();
			$('#status-onderweg, #status-ter_plaatse').hide();
		}

		// ── P2000 meldingen uitklapbaar blok ─────────────────────────────────
		const meldingen = data.p2000_meldingen || [];
		if (meldingen.length > 0) {
			const typeColors = {brandweer:'#ef4444',politie:'#f59e0b',mmt:'#8b5cf6',overig:'#9ca3af'};
			const typeBgs    = {brandweer:'rgba(239,68,68,.15)',politie:'rgba(245,158,11,.15)',mmt:'rgba(139,92,246,.15)',overig:'rgba(156,163,175,.15)'};
			$('#p2000-count').text(meldingen.length);
			const $list = $('#p2000-meldingen-list').empty();
			meldingen.forEach(function(m) {
				const col  = typeColors[m.type] || typeColors.overig;
				const bg   = typeBgs[m.type]    || typeBgs.overig;
				const isFeed = m.source === 'feed';
				const tijdStr = m.tijd ? new Date(m.tijd * 1000).toLocaleTimeString('nl-NL', {hour:'2-digit',minute:'2-digit',timeZone:'Europe/Amsterdam'}) : '';
				const typeLabel = (m.type||'overig').charAt(0).toUpperCase() + (m.type||'overig').slice(1);
				$list.append(
					'<div class="p2000-melding-row ' + (isFeed ? 'feed-row' : 'manual-row') + '">' +
					'<span class="p2000-type-pill" style="background:' + bg + ';color:' + col + ';">' + escHtml(typeLabel) + '</span>' +
					(tijdStr ? '<span class="p2000-time-col">' + tijdStr + '</span>' : '') +
					'<span style="flex:1;word-break:break-word;">' + escHtml(m.tekst) + '</span>' +
					(!isFeed ? '<span class="p2000-source-tag">✏ handmatig</span>' : '') +
					'</div>'
				);
			});
			$('#p2000-meldingen-section').show();
		} else {
			$('#p2000-meldingen-section').hide();
		}

		$('#article-body').html(data.post_content);

		// ── Leestijd berekenen ──────────────────────────────────────────────────
		const wordCount = ($('#article-body').text() || '').trim().split(/\s+/).length;
		const readMins  = Math.max(1, Math.round(wordCount / 200));
		$('#reading-time-val').text('~' + readMins + ' min lezen');
		$('#reading-time-badge').show();

		// ── ETA countdown ───────────────────────────────────────────────────────
		const etaMin   = parseInt(data.eta_minutes, 10) || 0;
		const etaSetAt = parseInt(data.eta_set_at, 10) || 0;
		window._etaTimer && clearInterval(window._etaTimer);
		if (etaMin > 0 && etaSetAt > 0) {
			const elapsed = Math.floor((Date.now() / 1000) - etaSetAt);
			let remain    = Math.max(0, etaMin * 60 - elapsed); // seconds
			if (remain > 0) {
				function fmtEta(s) {
					const m = Math.floor(s / 60);
					const sec = s % 60;
					return m + ':' + String(sec).padStart(2, '0');
				}
				$('#eta-minutes').text(fmtEta(remain));
				$('#eta-block').css('display', 'flex');
				window._etaTimer = setInterval(function() {
					remain--;
					if (remain <= 0) {
						clearInterval(window._etaTimer);
						$('#eta-block').fadeOut(600);
					} else {
						$('#eta-minutes').text(fmtEta(remain));
					}
				}, 1000);
			}
		} else {
			$('#eta-block').hide();
		}


		// Highlight zoektermen in artikeltekst
		const _q = window.SND_searchQuery || '';
		if (_q) {
			try {
				const esc = _q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				const re  = new RegExp('(' + esc + ')', 'gi');
				$('#article-body').html(function(_, html) {
					return html.replace(re, '<mark class="snd-hl">$1</mark>');
				});
			} catch(e) {}
		}

		// Byline
		if (data.byline) {
			$('#byline-block').text('📷 ' + data.byline).show();
		} else {
			$('#byline-block').hide();
		}

		// Map
		if (mapInstance) { mapInstance.remove(); mapInstance = null; }
		if (data.lat && data.lon) {
			$('#map-section').show();
			mapInstance = L.map('portal-map').setView([data.lat, data.lon], 16);
			L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
				attribution: '&copy; OpenStreetMap &copy; CartoDB'
			}).addTo(mapInstance);
			L.marker([data.lat, data.lon]).addTo(mapInstance);
			setTimeout(() => mapInstance?.invalidateSize(), 100);
		} else {
			$('#map-section').hide();
		}

		// Gallery
		const grid = $('#photo-grid').empty();
		$('#download-blocked-notice').hide();
		if (data.download_blocked) {
			lbPhotos = [];
			$('#no-photos-msg').hide();
			$('#download-blocked-notice').show();
		} else if (data.press_photos?.length) {
			lbPhotos = data.press_photos.map(img => ({
				id:       img.id,
				full_url: img.full_url,
				url:      img.url,
				filename: img.filename,
				caption:  img.caption || '',
			}));
			data.press_photos.forEach(img => {
				grid.append(`
					<div class="photo-card" data-id="${img.id}">
						<label class="photo-check"><input type="checkbox" value="${img.id}"><span class="tick">✓</span></label>
						<a class="photo-dl-link" href="${escHtml(img.full_url)}" data-filename="${escHtml(img.filename)}">
							<img src="${escHtml(img.url)}" loading="lazy" alt="${escHtml(img.filename)}">
						</a>
						<div class="photo-card-body">
							<div class="photo-filename">${escHtml(img.filename)}</div>
							${img.caption ? `<div class="photo-caption">${escHtml(img.caption)}</div>` : ''}
						</div>
					</div>
				`);
			});
			$('#gallery-section').show();
			$('#no-photos-msg').hide();
			// Photo count badge
			const n = data.press_photos.length;
			$('#gallery-section .gallery-header .section-label').html(
				'Beschikbare media <span style="background:var(--accent);color:#fff;font-size:11px;font-weight:700;padding:1px 8px;border-radius:10px;margin-left:6px;">'
				+ n + (n > 1 ? " foto's" : ' foto') + '</span>'
			);
		} else {
			$('#gallery-section').hide();
			$('#no-photos-msg').show();
		}

		updateSelectionBtn();

		// ── Video's ──────────────────────────────────────────────────────────────
		const videos = data.press_videos || [];
		if (videos.length && !data.download_blocked) {
			const $vlist = $('#video-list').empty();
			videos.forEach(v => {
				const isMega      = v.url.includes('mega.nz') || v.url.includes('mega.io');
				const isDropbox   = v.url.includes('dropbox.com');
				const isDrive     = v.url.includes('drive.google.com');
				const isWeTr      = v.url.includes('wetransfer.com');
				const hostIcon    = isMega ? '🔒' : isDropbox ? '📦' : isDrive ? '🗂' : isWeTr ? '📨' : '🎬';
				const hostLabel   = isMega ? 'Mega' : isDropbox ? 'Dropbox' : isDrive ? 'Google Drive' : isWeTr ? 'WeTransfer' : 'Video';
				$vlist.append(
					`<div class="video-item">
						<span class="video-type-badge" title="${escHtml(hostLabel)}">${hostIcon}</span>
						<div class="video-info">
							<div class="video-title">${escHtml(v.title || hostLabel)}</div>
							${v.size ? `<div class="video-meta">${escHtml(v.size)}</div>` : ''}
						</div>
						<a class="video-dl-btn"
							href="${escHtml(v.url)}"
							target="_blank"
							rel="noopener noreferrer">
							⬇ Downloaden
						</a>
					</div>`
				);
			});
			$('#video-section').show();
		} else {
			$('#video-section').hide();
		}

		$('#content-area').show();

		// Init chat for partners
		if (IS_PARTNER) initChat(currentPostId);
	}

	function togglePhoto($card) {
		const id = parseInt($card.data('id'), 10);
		const idx = selectedIds.indexOf(id);
		if (idx > -1) {
			selectedIds.splice(idx, 1);
			$card.removeClass('selected');
		} else {
			selectedIds.push(id);
			$card.addClass('selected');
		}
		updateSelectionBtn();
	}

	function updateSelectionBtn() {
		const n = selectedIds.length;
		$('#sel-count').text(n);
		$('#btn-dl-selection').toggle(n > 0);
	}


	async function triggerZip(ids) {
		const btn = ids.length ? $('#btn-dl-selection') : $('#btn-dl-all');
		const orig = btn.text();
		$('#btn-dl-all, #btn-dl-selection').prop('disabled', true);
		btn.text('Voorbereiden…');

		try {
			const res = await post('snd_portal_create_zip', {
				post_id: currentPostId, access_code: CODE,
				...(ids.length ? { selected_ids: ids } : {})
			});
			if (res.success && res.data.zip_url) {
				btn.text('Downloaden…');
				window.location.href = res.data.zip_url;
				setTimeout(() => { $('#btn-dl-all, #btn-dl-selection').prop('disabled', false); btn.text(orig); }, 4000);
			} else {
				alert('Fout: ' + (res.data?.message || 'Onbekend.'));
				$('#btn-dl-all, #btn-dl-selection').prop('disabled', false);
				btn.text(orig);
			}
		} catch {
			alert('Serverfout.');
			$('#btn-dl-all, #btn-dl-selection').prop('disabled', false);
			btn.text(orig);
		}
	}

	function logDownload(imageId) {
		post('snd_portal_log_dl', { post_id: currentPostId, access_code: CODE, image_id: imageId });
	}

	function post(action, data = {}) {
		return $.post(AJAX, { action, nonce: NONCE, ...data });
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// ── P2000 live melding chat ───────────────────────────────────────────────

	// Toggle chat area
	$(document).on('click', '.p2000-chat-toggle', function() {
		var id = $(this).data('p2000id');
		var $area = $('#p2000chat-' + id);
		$area.slideToggle(200);
		if ($area.is(':visible')) {
			loadP2000Chat(id);
		}
	});

	// Send chat message about a P2000 item
	$(document).on('click', '.p2000-chat-send', function() {
		var id   = $(this).data('p2000id');
		var $btn = $(this);
		var body = $.trim($('.p2000-chat-input[data-p2000id="' + id + '"]').val());
		if (!body) return;
		$btn.prop('disabled', true).text('…');
		post('snd_p2000_chat_send', {
			access_code: CODE,
			p2000_id:    id,
			body:        body,
		}).done(function(r) {
			if (r.success) {
				$('.p2000-chat-input[data-p2000id="' + id + '"]').val('');
				loadP2000Chat(id);
			} else {
				alert(r.data && r.data.message ? r.data.message : 'Fout bij versturen.');
			}
		}).always(function() { $btn.prop('disabled', false).text('Verstuur'); });
	});

	// Also send on Ctrl+Enter
	$(document).on('keydown', '.p2000-chat-input', function(e) {
		if (e.ctrlKey && e.which === 13) {
			$(this).closest('.p2000-chat-area').find('.p2000-chat-send').trigger('click');
		}
	});

	function loadP2000Chat(p2000_id) {
		post('snd_p2000_chat_get', { access_code: CODE, p2000_id: p2000_id })
			.done(function(r) {
				if (!r.success) return;
				var $msgs = $('#p2000msgs-' + p2000_id).empty();
				(r.data.messages || []).forEach(function(m) {
					var isAdmin = m.sender === 'admin';
					var name = isAdmin ? <?php echo wp_json_encode( get_bloginfo('name') ); ?> : escHtml(m.name || 'U');
					var nameColor = isAdmin ? '#60a5fa' : 'var(--accent)';
					var bg = isAdmin ? 'background:rgba(59,130,246,.08);border-left:2px solid #3b82f6;padding-left:8px;border-radius:0 4px 4px 0;' : '';
					$msgs.append(
						'<div class="p2000-chat-msg" style="' + bg + '">'
						+ '<div class="p2000-chat-msg-name" style="color:' + nameColor + '">'
						+ (isAdmin ? '🔵 ' : '') + escHtml(name)
						+ ' <span style="color:var(--text-3);font-weight:400;">' + escHtml(m.time || '') + '</span></div>'
						+ '<div class="p2000-chat-msg-text">' + escHtml(m.body) + '</div>'
						+ '</div>'
					);
				});
				$msgs.scrollTop($msgs[0] ? $msgs[0].scrollHeight : 0);
			});
	}

})(jQuery);

// ── Dark / light mode ────────────────────────────────────────────────────────
(function() {
	const btn  = document.getElementById('theme-toggle');
	const body = document.body;
	const saved = localStorage.getItem('snd_theme');
	if ( saved === 'light' ) { body.classList.add('light-mode'); btn.textContent = '☀️'; }

	btn.addEventListener('click', function() {
		const isLight = body.classList.toggle('light-mode');
		btn.textContent = isLight ? '☀️' : '🌙';
		localStorage.setItem('snd_theme', isLight ? 'light' : 'dark');
	});
})();

// ── Sidebar search & filter ───────────────────────────────────────────────────
(function() {
	const searchInput  = document.getElementById('sidebar-search');
	const filterBtns   = document.querySelectorAll('.filter-btn');
	const filterCount  = document.getElementById('filter-count');
	const items        = document.querySelectorAll('#posts-list > li[data-title]');

	let activeFilter = 'all';
	let searchQuery  = '';
	window.SND_searchQuery = '';

	function applyFilters() {
		let visible = 0;
		const bm = window.SND_getBookmarks ? window.SND_getBookmarks() : [];
		items.forEach(function(li) {
			const title   = li.dataset.title   || '';
			const content = li.dataset.content || '';
			const live    = li.dataset.live   === '1';
			const status  = li.dataset.status || '';
			const postId  = parseInt(li.dataset.postid || '0', 10);

			const q = searchQuery.toLowerCase();
			const matchSearch = !q || title.includes(q) || content.includes(q);
			let matchFilter   = true;
			if      ( activeFilter === 'live' )        matchFilter = live;
			else if ( activeFilter === 'onderweg' )    matchFilter = status === 'onderweg';
			else if ( activeFilter === 'ter_plaatse')  matchFilter = status === 'ter_plaatse';
			else if ( activeFilter === 'bookmarked' )  matchFilter = bm.includes(postId);

			const show = matchSearch && matchFilter;
			li.classList.toggle('hidden', !show);
			if (show) visible++;
		});
		if (filterCount) filterCount.textContent = visible + ' berichten';
	}

	if (searchInput) {
		searchInput.addEventListener('input', function() {
			searchQuery = this.value.trim();
			window.SND_searchQuery = searchQuery;
			applyFilters();
		});
	}

	filterBtns.forEach(function(btn) {
		btn.addEventListener('click', function() {
			filterBtns.forEach(function(b){ b.classList.remove('active'); });
			this.classList.add('active');
			activeFilter = this.dataset.filter;
			applyFilters();
		});
	});
})();

// ── Auto-refresh: poll for new posts every 90s ────────────────────────────────
(function() {
	const CODE       = <?php echo wp_json_encode( $outlet['access_code'] ); ?>;
	const AJAX       = <?php echo wp_json_encode( $ajax_url ); ?>;
	const NONCE      = <?php echo wp_json_encode( $nonce ); ?>;
	const banner     = document.getElementById('new-post-banner');
	const dot        = document.getElementById('refresh-dot');
	const statusEl   = document.getElementById('refresh-status');
	const knownIds   = new Set(<?php
		$ids = [];
		if ( $posts_query->have_posts() ) {
			while ( $posts_query->have_posts() ) { $posts_query->the_post(); $ids[] = get_the_ID(); }
			wp_reset_postdata();
		}
		echo wp_json_encode( $ids );
	?>);

	function timeStr() {
		var now = new Date();
		return new Date().toLocaleTimeString('nl-NL',{hour:'2-digit',minute:'2-digit',timeZone:'Europe/Amsterdam'});
	}

	// Set initial timestamp
	if (statusEl) statusEl.textContent = 'Bijgewerkt ' + timeStr();

	function poll() {
		if (dot) dot.classList.remove('active');
		if (statusEl) statusEl.textContent = 'Controleren…';

		jQuery.post(AJAX, {
				action:    'snd_portal_check_new',
				nonce:     NONCE,
				access_code: CODE,
				known_ids:   JSON.stringify(Array.from(knownIds)),
			})
			.done(function(r) {
				if (dot) dot.classList.add('active');
				if (statusEl) statusEl.textContent = 'Bijgewerkt ' + timeStr();
				if (r.success && r.data && r.data.new_count > 0) {
					if (banner) {
						banner.textContent = '\u2191 ' + r.data.new_count + ' nieuw bericht' + (r.data.new_count > 1 ? 'en' : '') + ' \u2014 klik om te vernieuwen';
						banner.style.display = 'block';
						banner.onclick = function() { window.location.reload(); };
					}
					// Geluid als enabled
					if (window.SND_getSoundEnabled && window.SND_getSoundEnabled()) { try { var ctx=new (window.AudioContext||window.webkitAudioContext)();var osc=ctx.createOscillator();var g=ctx.createGain();osc.connect(g);g.connect(ctx.destination);osc.frequency.setValueAtTime(880,ctx.currentTime);osc.frequency.setValueAtTime(1100,ctx.currentTime+0.08);g.gain.setValueAtTime(0.15,ctx.currentTime);g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.35);osc.start();osc.stop(ctx.currentTime+0.35); } catch(e){} }
				}
			})
			.fail(function() {
				if (dot) dot.classList.remove('active');
				if (statusEl) statusEl.textContent = 'Geen verbinding';
			});
	}

	// Poll every 90 seconds
	if (banner) setInterval(poll, 90000);
})();

// ── Swipe to close sidebar on mobile ─────────────────────────────────────────
(function() {
	const sidebar = document.getElementById('sidebar');
	if (!sidebar) return;
	let startX = 0, startY = 0;
	sidebar.addEventListener('touchstart', function(e) {
		startX = e.touches[0].clientX;
		startY = e.touches[0].clientY;
	}, { passive: true });
	sidebar.addEventListener('touchend', function(e) {
		const dx = e.changedTouches[0].clientX - startX;
		const dy = Math.abs(e.changedTouches[0].clientY - startY);
		// Swipe left more than 60px and mostly horizontal
		if (dx < -60 && dy < 40) {
			document.body.classList.remove('sidebar-open');
		}
	}, { passive: true });
	// Overlay tap to close
	const overlay = document.getElementById('sidebar-overlay');
	if (overlay) overlay.addEventListener('click', function() {
		document.body.classList.remove('sidebar-open');
	});
})();
</script>
</body>

</html>