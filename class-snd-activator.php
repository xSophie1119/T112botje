/* ============================================================
   Nieuws Distributie Systeem – Public Stylesheet
   Used by [incidenten_lijst] and [incidenten_kaart] shortcodes
   ============================================================ */

/* ── Widget container ────────────────────────────────────────── */
.snd-incident-widget {
	margin: 1.5em 0;
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* ── List ────────────────────────────────────────────────────── */
.snd-incident-list {
	list-style: none;
	margin: 0;
	padding: 0;
	border: 1px solid #e5e7eb;
	border-radius: 8px;
	overflow: hidden;
}
.snd-incident-li {
	border-bottom: 1px solid #f3f4f6;
	transition: background .12s;
}
.snd-incident-li:last-child { border-bottom: none; }
.snd-incident-li:hover { background: #f9fafb; }
.snd-incident-fresh  { background: #fffbeb !important; }
.snd-incident-recent { background: #f0fdf4 !important; }

.snd-incident-link,
.snd-incident-item {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 14px;
	text-decoration: none;
	color: inherit;
	width: 100%;
	box-sizing: border-box;
}
.snd-incident-link:hover { color: #1d4ed8; }

.snd-incident-type {
	flex-shrink: 0;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-top: 4px;
}
.snd-type-brandweer { background: #dc2626; }
.snd-type-politie   { background: #2563eb; }
.snd-type-mmt       { background: #7c3aed; }
.snd-type-ambulance { background: #16a34a; }
.snd-type-overig    { background: #9ca3af; }

.snd-incident-time {
	flex-shrink: 0;
	font-size: 12px;
	font-variant-numeric: tabular-nums;
	color: #6b7280;
	width: 38px;
	padding-top: 1px;
}
.snd-incident-text { flex: 1; font-size: 14px; line-height: 1.5; }
.snd-incident-loc  { display: block; font-size: 11px; color: #6b7280; margin-top: 2px; }

.snd-incident-badge {
	flex-shrink: 0;
	align-self: center;
	font-size: 10px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: .5px;
	background: #dbeafe;
	color: #1e40af;
	padding: 2px 7px;
	border-radius: 10px;
}
.snd-incident-loading { padding: 16px; color: #9ca3af; font-size: 13px; }

.snd-incident-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 14px;
	font-size: 11px;
	color: #9ca3af;
	border: 1px solid #e5e7eb;
	border-top: none;
	border-radius: 0 0 8px 8px;
	background: #f9fafb;
}
.snd-live-indicator {
	color: #ef4444;
	font-weight: 700;
	font-size: 10px;
	letter-spacing: .5px;
	animation: snd-blink 2s step-start infinite;
}
@keyframes snd-blink { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Map widget ──────────────────────────────────────────────── */
.snd-incident-map-widget { margin: 1.5em 0; }
.snd-incident-map-widget > div { border: 1px solid #e5e7eb; }
