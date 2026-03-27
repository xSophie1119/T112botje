<?php
/**
 * Portal map template – /persportaal/kaart?access_code=…
 * Shows incidents (dispatched to this outlet) AND live P2000 markers.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$outlet     = $GLOBALS['snd_outlet'] ?? null;
if ( ! $outlet ) wp_die( 'Geen toegang.' );

$site_name   = get_bloginfo( 'name' );
$ajax_url    = admin_url( 'admin-ajax.php' );
$nonce       = wp_create_nonce( 'snd_portal_nonce' );
$access_code = $outlet['access_code'];
$portal_url  = home_url( '/persportaal/?access_code=' . rawurlencode( $access_code ) );
$is_partner  = ( $outlet['role'] ?? 'default' ) === 'partner';
?>
<!DOCTYPE html>
<html lang="nl-NL">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( $site_name ); ?> – Incidentenkaart</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		:root {
			--bg-0: #0f0f0f; --bg-1: #171717; --bg-2: #222; --bg-3: #2d2d2d;
			--border: #2a2a2a; --text-1: #f2f2f2; --text-2: #a3a3a3; --text-3: #6b6b6b;
			--accent: #3b82f6; --accent-h: #60a5fa;
			--live: #ef4444;
			--brandweer: #ef4444; --politie: #f59e0b; --mmt: #8b5cf6;
			--font: 'Inter', -apple-system, sans-serif;
		}
		html, body { height: 100%; font-family: var(--font); background: var(--bg-0); color: var(--text-1); overflow: hidden; }
		body { display: flex; flex-direction: column; color-scheme: dark; }

		/* ── Header ──────────────────────────────────────────────── */
		.map-header {
			padding: 12px 20px;
			background: var(--bg-1);
			border-bottom: 1px solid var(--border);
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-shrink: 0;
			gap: 12px;
			z-index: 500;
		}
		.map-brand { display: flex; flex-direction: column; }
		.map-brand-site  { font-size: 11px; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .7px; }
		.map-brand-title { font-size: 1rem; font-weight: 700; }
		.map-nav { display: flex; gap: 6px; }
		.map-nav a {
			padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 500;
			text-decoration: none; border: 1px solid var(--border); color: var(--text-2);
			transition: background .15s;
		}
		.map-nav a:hover  { background: var(--bg-2); color: var(--text-1); }
		.map-nav a.active { background: var(--accent); border-color: var(--accent); color: #fff; }

		/* ── Layout ──────────────────────────────────────────────── */
		.map-layout { flex: 1; display: flex; overflow: hidden; min-height: 0; }

		/* ── Sidebar ─────────────────────────────────────────────── */
		.map-sidebar {
			width: 300px; flex-shrink: 0;
			background: var(--bg-1);
			border-right: 1px solid var(--border);
			display: flex; flex-direction: column;
			overflow: hidden;
		}
		.map-sidebar-tabs {
			display: flex;
			border-bottom: 1px solid var(--border);
			flex-shrink: 0;
		}
		.map-sidebar-tab {
			flex: 1; padding: 10px 6px;
			font-size: 12px; font-weight: 600;
			color: var(--text-3);
			cursor: pointer;
			border: none; background: transparent;
			border-bottom: 2px solid transparent;
			transition: color .15s, border-color .15s;
			text-align: center;
		}
		.map-sidebar-tab.active { color: var(--text-1); border-bottom-color: var(--accent); }
		.map-sidebar-panel { flex: 1; overflow-y: auto; display: none; }
		.map-sidebar-panel.active { display: block; }

		/* Filters bar */
		.map-filter-bar {
			padding: 10px 14px;
			border-bottom: 1px solid var(--border);
			display: flex; gap: 6px; flex-wrap: wrap;
			flex-shrink: 0;
		}
		.map-filter-btn {
			padding: 4px 10px; border-radius: 10px;
			font-size: 11px; font-weight: 600;
			border: 1px solid var(--border);
			background: transparent; color: var(--text-2);
			cursor: pointer; transition: all .15s;
		}
		.map-filter-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }

		/* Incident items */
		.map-item {
			padding: 11px 14px;
			border-bottom: 1px solid var(--border);
			cursor: pointer;
			display: flex; gap: 10px; align-items: flex-start;
			transition: background .12s;
		}
		.map-item:hover  { background: var(--bg-2); }
		.map-item.active { background: rgba(59,130,246,.12); border-left: 3px solid var(--accent); padding-left: 11px; }

		.map-item-thumb {
			width: 44px; height: 32px;
			border-radius: 3px; object-fit: cover;
			flex-shrink: 0; background: var(--bg-3);
		}
		.map-item-info   { flex: 1; min-width: 0; }
		.map-item-title  { font-size: 12px; font-weight: 600; line-height: 1.4; }
		.map-item-meta   { font-size: 11px; color: var(--text-3); margin-top: 2px; }
		.map-item-type   {
			display: inline-block;
			width: 8px; height: 8px;
			border-radius: 50%;
			margin-right: 4px;
			flex-shrink: 0;
			margin-top: 3px;
		}
		.type-brandweer { background: var(--brandweer); }
		.type-politie   { background: var(--politie); }
		.type-mmt       { background: var(--mmt); }
		.type-overig    { background: var(--text-3); }
		.type-incident  { background: var(--accent); border-radius: 50%; }
		.type-incident-live { background: var(--live); border-radius: 50%; animation: blink 1.5s infinite; }
		@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.4} }

		.map-empty { padding: 24px 14px; text-align: center; color: var(--text-3); font-size: 13px; }
		.map-loading { padding: 24px; text-align: center; color: var(--text-3); font-size: 13px; }

		/* Legend */
		.map-legend { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
		.map-legend h4 { font-size: 10px; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3); margin: 0 0 8px; }
		.legend-row { display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--text-2); margin-bottom: 5px; }
		.legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
		.legend-diamond { width: 10px; height: 10px; transform: rotate(45deg); flex-shrink: 0; border-radius: 2px; }

		/* Map */
		#portal-incident-map { flex: 1; }

		/* Leaflet popup */
		.pm-popup { font-family: var(--font); min-width: 200px; }
		.pm-popup-type   { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 5px; }
		.pm-popup-title  { font-size: 14px; font-weight: 600; margin-bottom: 6px; color: #1e293b; }
		.pm-popup-meta   { font-size: 12px; color: #64748b; margin-bottom: 3px; }
		.pm-popup-p2000  { font-size: 11px; font-family: monospace; background: #f1f5f9; padding: 6px 8px; border-radius: 4px; margin: 8px 0; color: #475569; line-height: 1.5; }
		.pm-popup-badge  { display: inline-block; padding: 2px 7px; border-radius: 8px; font-size: 11px; font-weight: 600; margin: 4px 0; }
		.pm-popup-badge-green { background: #d1fae5; color: #065f46; }
		.pm-popup-badge-blue  { background: #dbeafe; color: #1e40af; }
		.pm-popup-link   {
			display: inline-block; margin-top: 10px;
			padding: 6px 14px; background: #3b82f6; color: #fff;
			border-radius: 5px; font-size: 12px; font-weight: 600;
			text-decoration: none;
		}
		.pm-popup-link:hover { background: #2563eb; }

		/* Mobile */
		@media (max-width: 768px) {
			.map-sidebar { position: fixed; top:0;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .25s; }
			body.sidebar-open .map-sidebar { transform:translateX(0); }
			.map-mobile-btn { display: block; }
		}
		.map-mobile-btn { display: none; background: var(--bg-2); border: 1px solid var(--border); color: var(--text-1); padding: 7px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; }
		#map-overlay { display: none; position: fixed; inset:0; background: rgba(0,0,0,.5); z-index: 299; }
		body.sidebar-open #map-overlay { display: block; }
	</style>
</head>
<body>
<header class="map-header">
	<div class="map-brand">
		<span class="map-brand-site"><?php echo esc_html( $site_name ); ?></span>
		<span class="map-brand-title">🗺 Incidentenkaart</span>
	</div>
	<nav class="map-nav">
		<button class="map-mobile-btn" id="map-sidebar-btn">☰ Lijst</button>
		<a href="<?php echo esc_url( $portal_url ); ?>">📰 Persberichten</a>
		<a href="#" class="active">🗺 Kaart</a>
	</nav>
</header>

<div class="map-layout">
	<aside class="map-sidebar" id="map-sidebar">

		<!-- Tabs: incidents / P2000 -->
		<div class="map-sidebar-tabs">
			<button class="map-sidebar-tab active" data-panel="panel-incidents">
				📰 Incidenten (<span id="cnt-incidents">0</span>)
			</button>
			<button class="map-sidebar-tab" data-panel="panel-p2000">
				🚨 P2000 (<span id="cnt-p2000">0</span>)
			</button>
		</div>

		<!-- Incidents panel -->
		<div class="map-sidebar-panel active" id="panel-incidents">
			<div class="map-loading" id="incidents-loading">Laden…</div>
			<div id="incidents-list"></div>
		</div>

		<!-- P2000 panel -->
		<div class="map-sidebar-panel" id="panel-p2000">
			<div class="map-filter-bar" id="p2000-filters">
				<button class="map-filter-btn active" data-type="all">Alles</button>
				<button class="map-filter-btn" data-type="brandweer">🔥 Brand</button>
				<button class="map-filter-btn" data-type="politie">🚔 Politie</button>
				<button class="map-filter-btn" data-type="mmt">🚑 MMT</button>
			</div>
			<div class="map-loading" id="p2000-loading">Laden…</div>
			<div id="p2000-list"></div>
		</div>

		<!-- Legend -->
		<div class="map-legend">
			<h4>Legenda</h4>
			<div class="legend-row"><span class="legend-dot" style="background:var(--accent);"></span> Uw incident</div>
			<div class="legend-row"><span class="legend-dot" style="background:var(--live);"></span> Live incident</div>
			<div class="legend-row"><span class="legend-diamond" style="background:var(--brandweer);"></span> P2000 Brandweer</div>
			<div class="legend-row"><span class="legend-diamond" style="background:var(--politie);"></span> P2000 Politie</div>
			<div class="legend-row"><span class="legend-diamond" style="background:var(--mmt);"></span> P2000 MMT</div>
			<div class="legend-row"><span class="legend-diamond" style="background:#10b981;"></span> P2000 (gekoppeld aan bericht)</div>
		</div>
	</aside>

	<div id="portal-incident-map"></div>
</div>

<div id="map-overlay"></div>

<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function ($) {
	'use strict';

	const AJAX       = <?php echo wp_json_encode( $ajax_url ); ?>;
	const NONCE      = <?php echo wp_json_encode( $nonce ); ?>;
	const CODE       = <?php echo wp_json_encode( $access_code ); ?>;
	const PORTAL_URL = <?php echo wp_json_encode( $portal_url ); ?>;
	const GOOGLE_KEY = <?php echo wp_json_encode( get_option( 'snd_google_maps_api_key', '' ) ); ?>;

	let map, incidentLayer, p2000Layer;
	let allIncidents = [], allP2000 = [];
	let p2000Filter  = 'all';
	let incidentMarkers = {}, p2000Markers = {};

	// ── Map init ──────────────────────────────────────────────────────────────
	map = L.map('portal-incident-map').setView([51.5, 5.1], 11);
	L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
		attribution: '&copy; OpenStreetMap contributors &copy; CartoDB', maxZoom: 19
	}).addTo(map);
	if ( GOOGLE_KEY ) {
		map.eachLayer(l => map.removeLayer(l));
		L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
			subdomains: ['mt0','mt1','mt2','mt3'],
			attribution: '© Google Maps', maxZoom: 21
		}).addTo(map);
	}

	incidentLayer = L.layerGroup().addTo(map);
	p2000Layer    = L.layerGroup().addTo(map);

	// ── Load data ─────────────────────────────────────────────────────────────
	async function loadData() {
		const res = await $.post(AJAX, { action: 'snd_portal_get_map', nonce: NONCE, access_code: CODE });
		if (!res.success) {
			$('#incidents-loading, #p2000-loading').text('Kon data niet laden.');
			return;
		}
		allIncidents = res.data.markers       || [];
		allP2000     = res.data.p2000_markers || [];

		$('#cnt-incidents').text(allIncidents.length);
		$('#cnt-p2000').text(allP2000.filter(m => validCoord(m.lat) && validCoord(m.lon)).length);
		$('#incidents-loading, #p2000-loading').hide();

		renderIncidents();
		renderP2000();
		fitAll();
	}

	// ── Render incidents ──────────────────────────────────────────────────────
	function renderIncidents() {
		incidentLayer.clearLayers();
		incidentMarkers = {};
		const $list = $('#incidents-list').empty();

		if (!allIncidents.length) {
			$list.html('<div class="map-empty">Geen incidenten met locatie voor uw account.</div>');
			return;
		}

		allIncidents.forEach(inc => {
			if (!validCoord(inc.lat) || !validCoord(inc.lon)) return;
			// Sidebar
			const thumb = inc.thumb ? `<img src="${e(inc.thumb)}" class="map-item-thumb" alt="">` : '<div class="map-item-thumb"></div>';
			const dot   = `<span class="map-item-type ${inc.is_live ? 'type-incident-live' : 'type-incident'}"></span>`;
			const $item = $(`
				<div class="map-item" data-id="${inc.id}">
					${thumb}
					<div class="map-item-info">
						<div class="map-item-title">${dot}${e(inc.title)}</div>
						<div class="map-item-meta">📍 ${e(inc.street || '—')}</div>
						<div class="map-item-meta">🗓 ${e(inc.date)}</div>
					</div>
				</div>
			`);
			$item.on('click', () => focusIncident(inc));
			$list.append($item);

			// Marker
			const color = inc.is_live ? '#ef4444' : '#3b82f6';
			const icon  = circleIcon(color, inc.is_live ? 18 : 14);
			const m     = L.marker([inc.lat, inc.lon], { icon })
				.bindPopup(incidentPopup(inc), { maxWidth: 280 })
				.addTo(incidentLayer);
			m.on('click', () => { activateIncidentItem(inc.id); activateTab('panel-incidents'); });
			incidentMarkers[inc.id] = m;
		});
	}

	function incidentPopup(inc) {
		const p2000 = inc.p2000 ? `<div class="pm-popup-p2000">${e(inc.p2000)}</div>` : '';
		const liveTag = inc.is_live ? '<span class="pm-popup-badge pm-popup-badge-live" style="background:#fee2e2;color:#991b1b;">● LIVE</span><br>' : '';
		const postUrl = `${PORTAL_URL.split('?')[0]}?access_code=${encodeURIComponent(CODE)}`;
		return `<div class="pm-popup">
			<div class="pm-popup-type" style="color:#3b82f6;">📰 Uw bericht</div>
			${liveTag}
			<div class="pm-popup-title">${e(inc.title)}</div>
			<div class="pm-popup-meta">📍 ${e(inc.street || '—')}</div>
			<div class="pm-popup-meta">🗓 ${e(inc.date)}</div>
			${p2000}
			<a href="${e(postUrl)}" class="pm-popup-link">📰 Bekijk bericht →</a>
		</div>`;
	}

	function focusIncident(inc) {
		map.setView([inc.lat, inc.lon], 16, { animate: true });
		incidentMarkers[inc.id]?.openPopup();
		activateIncidentItem(inc.id);
	}

	function activateIncidentItem(id) {
		$('#incidents-list .map-item').removeClass('active');
		$(`#incidents-list .map-item[data-id="${id}"]`).addClass('active');
		scrollInList('#incidents-list', id);
	}

	// ── Render P2000 ──────────────────────────────────────────────────────────
	function renderP2000() {
		p2000Layer.clearLayers();
		p2000Markers = {};
		const $list    = $('#p2000-list').empty();
		const withCoords = allP2000.filter(m => validCoord(m.lat) && validCoord(m.lon));
		const filtered = p2000Filter === 'all' ? withCoords : withCoords.filter(m => m.subtype === p2000Filter);

		if (!filtered.length) {
			$list.html('<div class="map-empty">Geen P2000 meldingen met coördinaten gevonden.</div>');
			return;
		}

		filtered.forEach(msg => {
			// All items in filtered already have valid coords (see withCoords filter above)
			const dotClass = `type-${msg.subtype || 'overig'}`;
			const linked   = msg.linked ? '<span class="pm-popup-badge pm-popup-badge-green" style="font-size:10px;background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:6px;display:inline-block;margin-top:2px;">Gekoppeld aan bericht</span>' : '';
			const $item = $(`
				<div class="map-item p2000-item" data-id="${e(msg.id)}" data-subtype="${e(msg.subtype)}">
					<span class="map-item-type ${dotClass}" style="border-radius:2px;"></span>
					<div class="map-item-info">
						<div class="map-item-title">${e(msg.tekst)}</div>
						<div class="map-item-meta">🕐 ${e(msg.tijd)} &nbsp; 📍 ${e(msg.stad)} ${e(msg.straat)}</div>
						${linked}
					</div>
				</div>
			`);
			$item.on('click', () => focusP2000(msg));
			$list.append($item);

			const color = msg.linked ? '#10b981' : typeColor(msg.subtype);
			const icon  = diamondIcon(color);
			const m     = L.marker([msg.lat, msg.lon], { icon })
				.bindPopup(p2000Popup(msg), { maxWidth: 280 })
				.addTo(p2000Layer);
			m.on('click', () => { activateP2000Item(msg.id); activateTab('panel-p2000'); });
			p2000Markers[msg.id] = m;
		});
	}

	function p2000Popup(msg) {
		const typeLabel = { brandweer:'🔥 Brandweer', politie:'🚔 Politie', mmt:'🚑 MMT' }[msg.subtype] || '🚨 Melding';
		const color     = typeColor(msg.subtype);
		const badge     = msg.linked
			? '<span class="pm-popup-badge pm-popup-badge-green">✓ Gekoppeld aan bericht</span>'
			: '<span class="pm-popup-badge pm-popup-badge-blue">Ongekoppeld</span>';
		return `<div class="pm-popup">
			<div class="pm-popup-type" style="color:${color};">${typeLabel}</div>
			<div class="pm-popup-title">${e(msg.tekst)}</div>
			<div class="pm-popup-meta">🕐 ${e(msg.tijd)}</div>
			<div class="pm-popup-meta">📍 ${e(msg.stad)} ${e(msg.straat)}</div>
			${badge}
		</div>`;
	}

	function focusP2000(msg) {
		if (!validCoord(msg.lat) || !validCoord(msg.lon)) return;
		map.setView([msg.lat, msg.lon], 16, { animate: true });
		p2000Markers[msg.id]?.openPopup();
		activateP2000Item(msg.id);
	}

	function activateP2000Item(id) {
		$('#p2000-list .map-item').removeClass('active');
		$(`#p2000-list .map-item[data-id="${id}"]`).addClass('active');
		scrollInList('#p2000-list', id);
	}

	// ── P2000 type filter ─────────────────────────────────────────────────────
	$('#p2000-filters').on('click', '.map-filter-btn', function () {
		$('#p2000-filters .map-filter-btn').removeClass('active');
		$(this).addClass('active');
		p2000Filter = $(this).data('type');
		renderP2000();
	});

	// ── Tab switching ─────────────────────────────────────────────────────────
	$('.map-sidebar-tab').on('click', function () {
		activateTab($(this).data('panel'));
	});

	function activateTab(panelId) {
		$('.map-sidebar-tab').removeClass('active');
		$(`.map-sidebar-tab[data-panel="${panelId}"]`).addClass('active');
		$('.map-sidebar-panel').removeClass('active');
		$('#' + panelId).addClass('active');
	}

	// ── Mobile sidebar ────────────────────────────────────────────────────────
	$('#map-sidebar-btn').on('click', () => $('body').toggleClass('sidebar-open'));
	$('#map-overlay').on('click', () => $('body').removeClass('sidebar-open'));

	// ── Fit bounds ────────────────────────────────────────────────────────────
	function fitAll() {
		const all = [...incidentLayer.getLayers(), ...p2000Layer.getLayers()];
		if (all.length > 1) {
			try { map.fitBounds(L.featureGroup(all).getBounds().pad(0.1)); } catch(e) {}
		} else if (all.length === 1) {
			const ll = all[0].getLatLng();
			map.setView(ll, 13);
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────
	function circleIcon(color, size) {
		return L.divIcon({
			className: '',
			html: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${color};border:3px solid rgba(255,255,255,.9);box-shadow:0 2px 8px rgba(0,0,0,.4);"></div>`,
			iconSize: [size, size], iconAnchor: [size/2, size/2],
		});
	}

	function diamondIcon(color) {
		return L.divIcon({
			className: '',
			html: `<div style="width:12px;height:12px;background:${color};border:2px solid rgba(255,255,255,.9);box-shadow:0 2px 6px rgba(0,0,0,.4);transform:rotate(45deg);border-radius:2px;"></div>`,
			iconSize: [12,12], iconAnchor: [6,6],
		});
	}

	function typeColor(type) {
		return { brandweer: '#ef4444', politie: '#f59e0b', mmt: '#8b5cf6' }[type] || '#9ca3af';
	}

	function scrollInList(selector, id) {
		const $l = $(selector), $i = $(`${selector} .map-item[data-id="${id}"]`);
		if ($i.length) $l.animate({ scrollTop: $l.scrollTop() + $i.position().top - 60 }, 200);
	}

	function e(str) {
		return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function validCoord(v) {
		return v !== null && v !== undefined && v !== '' && !isNaN(parseFloat(v));
	}

	// ── Boot ──────────────────────────────────────────────────────────────────
	loadData();

})(jQuery);
</script>
</body>
</html>
