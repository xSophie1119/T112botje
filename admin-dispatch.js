/**
 * Nieuws Distributie Systeem – Admin Map JS
 * Interactive Leaflet map showing incidents and P2000 markers.
 * Allows linking P2000 to existing posts and editing locations.
 */
(function ($) {
	'use strict';

	if (typeof SND_AdminMap === 'undefined' || typeof L === 'undefined') return;

	const { ajax_url, nonce } = SND_AdminMap;

	let map, incidentLayer, p2000Layer;
	let allIncidents = [], allP2000 = [];
	let editLocMap, editLocMarker;
	let editingPostId = 0;
	let linkingP2000Id = '', linkingNewPostUrl = '';

	// ── Init map ──────────────────────────────────────────────────────────────
	map = L.map('snd-admin-map', { zoomControl: true }).setView([51.5, 5.1], 10);

	const googleKey = SND_AdminMap.google_api_key || '';
	if ( googleKey ) {
		L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}&key=' + googleKey, {
			subdomains: ['mt0','mt1','mt2','mt3'],
			attribution: '© Google Maps',
			maxZoom: 21,
		}).addTo(map);
	} else {
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
			maxZoom: 19,
		}).addTo(map);
	}

	incidentLayer = L.layerGroup().addTo(map);
	p2000Layer    = L.layerGroup().addTo(map);

	// ── Load data ─────────────────────────────────────────────────────────────
	async function loadMapData() {
		$('#snd-map-refresh').prop('disabled', true).text('Laden…');
		$('#snd-map-loading-indicator').show();
		$('#snd-map-selected-info').hide();
		try {
			const res = await post('snd_get_map_data');
			if (!res.success) { alert('Kon kaartdata niet laden.'); return; }
			allIncidents = res.data.incidents;
			allP2000     = res.data.p2000_markers;
			renderMarkers();

			// Stats
			const linked = allP2000.filter(m => m.linked).length;
			const unlinked = allP2000.filter(m => !m.linked).length;
			$('#snd-map-stats').html(
				`${allIncidents.length} incidenten &nbsp;|&nbsp; ${allP2000.filter(m=>validCoord(m.lat)&&validCoord(m.lon)).length} P2000 op kaart (${linked} gekoppeld, ${unlinked} open)`
			);
		} catch (e) {
			alert('Kon kaartdata niet laden: ' + e);
		} finally {
			$('#snd-map-refresh').prop('disabled', false).text('↺ Ververs kaart');
			$('#snd-map-loading-indicator').hide();
		}
	}

	function renderMarkers() {
		incidentLayer.clearLayers();
		p2000Layer.clearLayers();

		const showIncidents = $('#filter-incidents').is(':checked');
		const showP2000     = $('#filter-p2000').is(':checked');
		const hideLinked    = $('#filter-linked').is(':checked');

		if (showIncidents) {
			allIncidents.forEach(inc => {
				if ( !validCoord(inc.lat) || !validCoord(inc.lon) ) return;
				const color  = inc.dispatched ? '#10b981' : '#3b82f6';
				const icon   = makeCircleIcon(color, inc.is_live ? '●' : '');
				const marker = L.marker([inc.lat, inc.lon], { icon }).addTo(incidentLayer);

				marker.bindPopup(buildIncidentPopup(inc), { maxWidth: 320 });
				marker.on('click', () => showSelectedInfo('incident', inc));
			});
		}

		if (showP2000) {
			allP2000.forEach(msg => {
				if ( !validCoord(msg.lat) || !validCoord(msg.lon) ) return;
				if (hideLinked && msg.linked) return;
				const color  = p2000Color(msg.type);
				const icon   = makeSquareIcon(color);
				const marker = L.marker([msg.lat, msg.lon], { icon }).addTo(p2000Layer);

				marker.bindPopup(buildP2000Popup(msg), { maxWidth: 300 });
				marker.on('click', () => showSelectedInfo('p2000', msg));
			});
		}

		// Auto-fit bounds if we have markers
		const all = [...incidentLayer.getLayers(), ...p2000Layer.getLayers()];
		if (all.length > 1) {
			const group = L.featureGroup(all);
			map.fitBounds(group.getBounds().pad(0.1));
		}
	}

	// ── Popup builders ────────────────────────────────────────────────────────
	function buildIncidentPopup(inc) {
		return `
			<div class="snd-map-popup">
				<div class="snd-map-popup-title">${escHtml(inc.title)}</div>
				<div class="snd-map-popup-meta">${escHtml(inc.date)}</div>
				${inc.street ? `<div class="snd-map-popup-meta">📍 ${escHtml(inc.street)}</div>` : ''}
				<div class="snd-map-popup-actions">
					<a href="${escHtml(inc.edit_url)}" target="_blank" class="snd-map-btn">✏ Bewerken</a>
					<button class="snd-map-btn snd-editloc-btn" data-postid="${inc.id}" data-lat="${inc.lat}" data-lon="${inc.lon}" data-street="${escHtml(inc.street || '')}">📍 Locatie aanpassen</button>
				</div>
			</div>`;
	}

	function buildP2000Popup(msg) {
		const linkedBadge = msg.linked ? '<span style="color:#10b981;font-weight:600;">✓ Gekoppeld</span>' : '';
		return `
			<div class="snd-map-popup">
				<div class="snd-map-popup-title">${escHtml(msg.tekst)}</div>
				<div class="snd-map-popup-meta">🕐 ${escHtml(msg.tijd)} &nbsp;|&nbsp; ${escHtml(msg.stad)} ${escHtml(msg.straat)}</div>
				<div class="snd-map-popup-meta">${linkedBadge}</div>
				${!msg.linked ? `
				<div class="snd-map-popup-actions">
					<button class="snd-map-btn snd-link-p2000-btn" data-p2000id="${escHtml(msg.id)}" data-tekst="${escHtml(msg.tekst)}" data-newurl="${escHtml(msg.new_post_url)}">🔗 Koppelen aan bericht</button>
				</div>` : ''}
			</div>`;
	}

	// ── Popup delegation ──────────────────────────────────────────────────────
	$(document).on('click', '.snd-link-p2000-btn', function () {
		linkingP2000Id    = this.dataset.p2000id;
		linkingNewPostUrl = this.dataset.newurl;
		$('.snd-link-p2000-tekst').text(this.dataset.tekst);
		$('#snd-link-new-post').attr('href', linkingNewPostUrl);
		$('#snd-link-post-select').val('');
		$('#snd-link-status').text('');
		$('#snd-link-modal').fadeIn(200);
	});

	$(document).on('click', '.snd-editloc-btn', function () {
		editingPostId = parseInt(this.dataset.postid, 10);
		const lat     = parseFloat(this.dataset.lat);
		const lon     = parseFloat(this.dataset.lon);
		const street  = this.dataset.street || '';

		$('#snd-editloc-lat').val(lat.toFixed(6));
		$('#snd-editloc-lon').val(lon.toFixed(6));
		$('#snd-editloc-street').val(street);
		$('#snd-editloc-status').text('');
		$('#snd-editloc-modal').fadeIn(200);

		setTimeout(() => initEditLocMap(lat, lon), 100);
	});

	// ── Link P2000 confirm ────────────────────────────────────────────────────
	$('#snd-link-confirm').on('click', async function () {
		const postId = parseInt($('#snd-link-post-select').val(), 10);
		if (!postId) {
			$('#snd-link-status').text('Selecteer eerst een bericht.').css('color', '#b32d2e');
			return;
		}
		$(this).prop('disabled', true).text('Koppelen…');
		try {
			const res = await post('snd_link_p2000_to_post', { post_id: postId, p2000_id: linkingP2000Id });
			if (res.success) {
				$('#snd-link-status').text('✓ ' + res.data.message).css('color', '#1a7239');
				setTimeout(() => { $('#snd-link-modal').fadeOut(200); loadMapData(); }, 1500);
			} else {
				$('#snd-link-status').text(res.data.message).css('color', '#b32d2e');
			}
		} catch {
			$('#snd-link-status').text('Serverfout.').css('color', '#b32d2e');
		} finally {
			$(this).prop('disabled', false).text('Koppelen');
		}
	});

	// ── Edit location map ─────────────────────────────────────────────────────
	function initEditLocMap(lat, lon) {
		if (editLocMap) {
			editLocMap.remove();
			editLocMap = null;
			editLocMarker = null;
		}
		editLocMap = L.map('snd-editloc-map').setView([lat, lon], 16);
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '© OpenStreetMap', maxZoom: 19
		}).addTo(editLocMap);

		editLocMarker = L.marker([lat, lon], { draggable: true }).addTo(editLocMap);

		editLocMap.on('click', e => setEditLocPoint(e.latlng.lat, e.latlng.lng));
		editLocMarker.on('dragend', e => setEditLocPoint(e.target.getLatLng().lat, e.target.getLatLng().lng));

		setTimeout(() => editLocMap.invalidateSize(), 100);
	}

	async function setEditLocPoint(lat, lon) {
		editLocMarker.setLatLng([lat, lon]);
		$('#snd-editloc-lat').val(lat.toFixed(6));
		$('#snd-editloc-lon').val(lon.toFixed(6));
		$('#snd-editloc-street').val('Zoeken…');

		try {
			const res = await post('snd_reverse_geocode', { lat, lon });
			$('#snd-editloc-street').val(res.success && res.data.straat ? res.data.straat : '');
		} catch {
			$('#snd-editloc-street').val('');
		}
	}

	// Address search inside edit modal
	$('#snd-editloc-search-btn').on('click', searchEditLocAddress);
	$('#snd-editloc-search').on('keypress', e => { if (e.which === 13) { e.preventDefault(); searchEditLocAddress(); } });

	async function searchEditLocAddress() {
		const q = $('#snd-editloc-search').val().trim();
		if (!q) return;
		$('#snd-editloc-search-btn').prop('disabled', true).text('…');
		try {
			const res = await post('snd_geocode_address', { address: q });
			if (res.success && res.data.lat) {
				const lat = parseFloat(res.data.lat), lon = parseFloat(res.data.lon);
				editLocMap.setView([lat, lon], 17);
				setEditLocPoint(lat, lon);
			} else {
				alert('Locatie niet gevonden.');
			}
		} catch {
			alert('Serverfout.');
		} finally {
			$('#snd-editloc-search-btn').prop('disabled', false).text('Zoek');
		}
	}

	$('#snd-editloc-save').on('click', async function () {
		const lat    = $('#snd-editloc-lat').val();
		const lon    = $('#snd-editloc-lon').val();
		const street = $('#snd-editloc-street').val();
		$(this).prop('disabled', true).text('Opslaan…');

		try {
			const res = await post('snd_update_post_location', { post_id: editingPostId, lat, lon, street });
			if (res.success) {
				$('#snd-editloc-status').text('✓ Opgeslagen').css('color', '#1a7239');
				setTimeout(() => { $('#snd-editloc-modal').fadeOut(200); loadMapData(); }, 1200);
			} else {
				$('#snd-editloc-status').text(res.data.message).css('color', '#b32d2e');
			}
		} catch {
			$('#snd-editloc-status').text('Serverfout.').css('color', '#b32d2e');
		} finally {
			$(this).prop('disabled', false).text('Locatie opslaan');
		}
	});

	// ── Selected info panel ───────────────────────────────────────────────────
	function showSelectedInfo(type, data) {
		const $panel = $('#snd-map-selected-info');
		$panel.show();
		if (type === 'incident') {
			$('#snd-map-sel-title').text(data.title);
			$('#snd-map-sel-body').html(`
				<p style="font-size:12px;color:#666;">${escHtml(data.date)}</p>
				${data.street ? `<p>📍 ${escHtml(data.street)}</p>` : ''}
				<p>${data.dispatched ? '✅ Verzonden' : '⏳ Nog niet verzonden'}</p>
				${data.is_live ? '<p>🔴 Live verhaal</p>' : ''}
				<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
					<a href="${escHtml(data.edit_url)}" target="_blank" class="button button-small">✏ Bewerken</a>
					<button class="button button-small snd-editloc-btn" data-postid="${data.id}" data-lat="${data.lat}" data-lon="${data.lon}" data-street="${escHtml(data.street||'')}">📍 Locatie</button>
				</div>
			`);
		} else {
			$('#snd-map-sel-title').text(data.tekst);
			$('#snd-map-sel-body').html(`
				<p style="font-size:12px;color:#666;">🕐 ${escHtml(data.tijd)} — ${escHtml(data.stad)} ${escHtml(data.straat)}</p>
				<p>Type: <strong>${escHtml(data.type)}</strong></p>
				${data.linked ? '<p style="color:#10b981;font-weight:600;">✓ Al gekoppeld aan een bericht</p>' : `
				<button class="button button-small snd-link-p2000-btn" data-p2000id="${escHtml(data.id)}" data-tekst="${escHtml(data.tekst)}" data-newurl="${escHtml(data.new_post_url)}">🔗 Koppelen aan bericht</button>`}
			`);
		}
	}

	// ── Filters ───────────────────────────────────────────────────────────────
	$('#filter-incidents, #filter-p2000, #filter-linked').on('change', renderMarkers);
	$('#snd-map-refresh').on('click', loadMapData);

	// ── Modal close ───────────────────────────────────────────────────────────
	$(document).on('click', '.snd-modal-close', function () {
		$(this).closest('.snd-modal-backdrop').fadeOut(200);
	});
	$(document).on('click', '.snd-modal-backdrop', function (e) {
		if ($(e.target).is('.snd-modal-backdrop')) $(this).fadeOut(200);
	});

	// ── Icon factories ────────────────────────────────────────────────────────
	function makeCircleIcon(color, label) {
		return L.divIcon({
			className: '',
			html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;font-size:8px;color:#fff;">${label}</div>`,
			iconSize: [14, 14],
			iconAnchor: [7, 7],
		});
	}

	function makeSquareIcon(color) {
		return L.divIcon({
			className: '',
			html: `<div style="width:12px;height:12px;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);transform:rotate(45deg);"></div>`,
			iconSize: [12, 12],
			iconAnchor: [6, 6],
		});
	}

	function p2000Color(type) {
		return { brandweer: '#ef4444', politie: '#f59e0b', mmt: '#8b5cf6' }[type] || '#6b7280';
	}

	function validCoord(v) {
		return v !== null && v !== undefined && v !== '' && !isNaN(parseFloat(v));
	}

	// ── Helpers ───────────────────────────────────────────────────────────────
	function post(action, data = {}) {
		return $.post(ajax_url, { action, nonce, ...data });
	}

	function escHtml(str) {
		return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// ── Boot ──────────────────────────────────────────────────────────────────
	loadMapData();

})(jQuery);
