/* ============================================================
   Nieuws Distributie Systeem – Admin Stylesheet v2
   Modern redesign with CSS variables + smooth interactions
   ============================================================ */

/* ── Design tokens ───────────────────────────────────────────── */
:root {
	--snd-bg:         #f0f2f5;
	--snd-surface:    #ffffff;
	--snd-border:     #e1e5eb;
	--snd-border-2:   #cbd2da;
	--snd-text-1:     #111827;
	--snd-text-2:     #374151;
	--snd-text-3:     #6b7280;
	--snd-text-4:     #9ca3af;
	--snd-blue:       #2563eb;
	--snd-blue-light: #eff6ff;
	--snd-blue-mid:   #bfdbfe;
	--snd-green:      #16a34a;
	--snd-green-lt:   #dcfce7;
	--snd-red:        #dc2626;
	--snd-red-lt:     #fee2e2;
	--snd-amber:      #d97706;
	--snd-amber-lt:   #fef3c7;
	--snd-radius-sm:  5px;
	--snd-radius:     8px;
	--snd-radius-lg:  12px;
	--snd-shadow-sm:  0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
	--snd-shadow:     0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
	--snd-shadow-lg:  0 12px 40px rgba(0,0,0,.14), 0 4px 12px rgba(0,0,0,.06);
	--snd-transition: all .18s cubic-bezier(.4,0,.2,1);
}

/* ── Base wrap ───────────────────────────────────────────────── */
.snd-admin-wrap {
	margin-right: 20px;
}
.snd-admin-wrap h1 {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--snd-text-1);
	margin-bottom: 1.5rem;
	letter-spacing: -.3px;
}

/* ── Utilities ───────────────────────────────────────────────── */
.snd-toolbar {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.snd-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	font-weight: 600;
	padding: 2px 9px;
	border-radius: 20px;
	vertical-align: middle;
	margin-left: 5px;
	text-transform: uppercase;
	letter-spacing: .5px;
	line-height: 1.6;
}
.snd-badge-sent {
	background: var(--snd-green-lt);
	color: var(--snd-green);
}
.snd-count {
	background: #e9ecef;
	color: var(--snd-text-2);
	border-radius: 10px;
	padding: 1px 8px;
	font-size: 11px;
	font-weight: 700;
	margin-left: 4px;
}

/* ── Settings layout ─────────────────────────────────────────── */
.snd-settings-columns {
	display: grid;
	grid-template-columns: 1fr 340px;
	gap: 24px;
	margin-top: 20px;
	align-items: start;
}
.snd-card {
	background: var(--snd-surface);
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius-lg);
	padding: 24px;
	box-shadow: var(--snd-shadow-sm);
	transition: var(--snd-transition);
}
.snd-card:hover {
	box-shadow: var(--snd-shadow);
}
.snd-card h2 {
	font-size: 1.05rem;
	font-weight: 700;
	color: var(--snd-text-1);
	margin-top: 0;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--snd-border);
}
.snd-col-side .snd-card .form-field,
.snd-col-side .snd-card p { margin-bottom: 12px; }
.snd-col-side .snd-card label {
	display: block;
	font-weight: 600;
	font-size: 12px;
	color: var(--snd-text-2);
	margin-bottom: 4px;
	text-transform: uppercase;
	letter-spacing: .4px;
}
.snd-action-btns {
	display: flex;
	gap: 4px;
	align-items: center;
}

/* ── Tab content ─────────────────────────────────────────────── */
.snd-tab-content { display: none; margin-top: 12px; }
.snd-tab-content.active { display: block; }

/* ── KPI cards ───────────────────────────────────────────────── */
.snd-kpi-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 14px;
	margin-bottom: 24px;
}
.snd-kpi-card {
	background: var(--snd-surface);
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius-lg);
	padding: 20px 16px;
	text-align: center;
	box-shadow: var(--snd-shadow-sm);
	transition: var(--snd-transition);
	position: relative;
	overflow: hidden;
}
.snd-kpi-card::before {
	content: '';
	position: absolute;
	top: 0; left: 0; right: 0;
	height: 3px;
	background: linear-gradient(90deg, var(--snd-blue), #60a5fa);
	border-radius: var(--snd-radius-lg) var(--snd-radius-lg) 0 0;
}
.snd-kpi-card:hover {
	transform: translateY(-2px);
	box-shadow: var(--snd-shadow);
}
.snd-kpi-icon {
	font-size: 1.5rem;
	margin-bottom: 6px;
}
.snd-kpi-val {
	font-size: 1.9rem;
	font-weight: 800;
	color: var(--snd-text-1);
	line-height: 1;
	margin-bottom: 5px;
	font-feature-settings: 'tnum';
}
.snd-kpi-lbl {
	font-size: 11px;
	color: var(--snd-text-3);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: .6px;
}

/* ── Dashboard stat bar ──────────────────────────────────────── */
.snd-report-summary {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	background: var(--snd-blue-light);
	border: 1px solid var(--snd-blue-mid);
	padding: 14px 20px;
	border-radius: var(--snd-radius);
	margin-bottom: 18px;
}
.snd-stat { display: flex; flex-direction: column; align-items: center; min-width: 60px; }
.snd-stat-val {
	font-size: 1.35rem;
	font-weight: 800;
	color: var(--snd-blue);
	line-height: 1;
	font-feature-settings: 'tnum';
}
.snd-stat-lbl { font-size: 11px; color: var(--snd-text-3); margin-top: 3px; text-align: center; }
.snd-empty-state { color: var(--snd-text-4); font-style: italic; padding: 16px 0; }

/* ── Dispatch modal ──────────────────────────────────────────── */
.snd-modal-backdrop {
	position: fixed;
	inset: 0;
	background: rgba(0,0,0,.45);
	z-index: 100000;
	display: flex;
	align-items: center;
	justify-content: center;
	backdrop-filter: blur(3px);
	-webkit-backdrop-filter: blur(3px);
}
.snd-modal-box {
	background: var(--snd-surface);
	border-radius: var(--snd-radius-lg);
	width: 90%;
	max-width: 540px;
	max-height: 90vh;
	display: flex;
	flex-direction: column;
	box-shadow: var(--snd-shadow-lg);
	overflow: hidden;
	animation: sndModalIn .2s cubic-bezier(.34,1.4,.64,1);
}
.snd-modal-box.wide { max-width: 860px; }
@keyframes sndModalIn {
	from { opacity: 0; transform: scale(.95) translateY(8px); }
	to   { opacity: 1; transform: scale(1)  translateY(0); }
}
.snd-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 18px 22px;
	border-bottom: 1px solid var(--snd-border);
	flex-shrink: 0;
	background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
}
.snd-modal-header h2 {
	margin: 0;
	font-size: 1rem;
	font-weight: 700;
	color: #fff;
	letter-spacing: -.2px;
}
.snd-modal-close {
	background: rgba(255,255,255,.1);
	border: 1px solid rgba(255,255,255,.15);
	border-radius: 6px;
	font-size: 18px;
	cursor: pointer;
	color: rgba(255,255,255,.8);
	line-height: 1;
	padding: 4px 8px;
	transition: var(--snd-transition);
}
.snd-modal-close:hover {
	background: rgba(255,255,255,.2);
	color: #fff;
}
.snd-modal-body {
	flex: 1;
	overflow-y: auto;
	padding: 18px 22px;
}
.snd-modal-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 14px 22px;
	border-top: 1px solid var(--snd-border);
	gap: 8px;
	flex-shrink: 0;
	background: #f8fafc;
}
.snd-modal-actions { display: flex; align-items: center; gap: 8px; }
.snd-modal-status span { font-size: 13px; font-weight: 500; }
.snd-modal-status span.error   { color: var(--snd-red); }
.snd-modal-status span.success { color: var(--snd-green); }

/* ── Modal tabs ──────────────────────────────────────────────── */
.snd-modal-body .nav-tab-wrapper {
	margin: -18px -22px 16px;
	padding: 0 22px;
	background: #f8fafc;
	border-bottom: 1px solid var(--snd-border);
}
.snd-modal-body .nav-tab-wrapper .nav-tab {
	font-size: 12px;
	font-weight: 600;
}
.snd-tab { display: none; }
.snd-tab.active { display: block; }
.snd-tab-toolbar { margin-bottom: 12px; }

/* ── Outlet list in modal ────────────────────────────────────── */
#snd-outlets-list .outlet-group {
	margin-bottom: 16px;
}
#snd-outlets-list .outlet-group h4 {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .8px;
	color: var(--snd-text-3);
	margin: 0 0 8px;
	padding-bottom: 5px;
	border-bottom: 1px solid var(--snd-border);
}
#snd-outlets-list label {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	cursor: pointer;
	border-radius: var(--snd-radius-sm);
	transition: background .12s;
}
#snd-outlets-list label:hover { background: var(--snd-blue-light); }
#snd-history-list .history-item {
	display: block;
	padding: 8px 10px;
	border-radius: var(--snd-radius-sm);
	border-bottom: 1px solid var(--snd-border);
	font-size: 13px;
}

/* ── Press photos grid ───────────────────────────────────────── */
.snd-photos-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.snd-photos-grid .photo-thumb {
	position: relative;
	width: 86px;
	height: 86px;
	border-radius: var(--snd-radius);
	overflow: hidden;
	border: 2px solid var(--snd-border);
	transition: var(--snd-transition);
}
.snd-photos-grid .photo-thumb:hover {
	border-color: var(--snd-blue);
	transform: scale(1.04);
}
.snd-photos-grid .photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.snd-photos-grid .remove-photo {
	position: absolute;
	top: 4px; right: 4px;
	width: 22px; height: 22px;
	background: rgba(0,0,0,.65);
	color: #fff;
	border: none;
	border-radius: 50%;
	cursor: pointer;
	font-size: 14px;
	line-height: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	opacity: 0;
	transition: opacity .15s;
}
.snd-photos-grid .photo-thumb:hover .remove-photo { opacity: 1; }

/* ── Preview images ──────────────────────────────────────────── */
#snd-preview-images-list label {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 7px 10px;
	cursor: pointer;
	border-radius: var(--snd-radius-sm);
	transition: background .12s;
}
#snd-preview-images-list label:hover { background: var(--snd-blue-light); }
#snd-preview-images-list .preview-thumb {
	width: 64px;
	height: 42px;
	object-fit: cover;
	border-radius: 5px;
	border: 1px solid var(--snd-border);
}

/* ── Location modal ──────────────────────────────────────────── */
#snd-location-modal-backdrop {
	position: fixed; inset: 0;
	background: rgba(0,0,0,.45);
	z-index: 200000;
	display: flex;
	align-items: center;
	justify-content: center;
	backdrop-filter: blur(3px);
}
#snd-location-modal-content {
	background: var(--snd-surface);
	border-radius: var(--snd-radius-lg);
	width: 90%;
	max-width: 680px;
	overflow: hidden;
	box-shadow: var(--snd-shadow-lg);
	animation: sndModalIn .2s cubic-bezier(.34,1.4,.64,1);
}
#snd-location-modal-content .snd-modal-header {
	background: linear-gradient(135deg, #1e293b, #0f172a);
	border-bottom: none;
}
#snd-location-modal-content .snd-modal-header h2,
#snd-location-modal-content .snd-modal-close { color: #fff; }
#snd-location-modal-content .snd-modal-footer {
	display: flex; justify-content: space-between; align-items: center; gap: 12px;
}
#snd-modal-address-preview { font-size: 13px; color: var(--snd-text-3); }

/* ── Accordion (Reports) ─────────────────────────────────────── */
.snd-accordion { margin-top: 16px; }
.snd-accordion-item {
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius);
	margin-bottom: 8px;
	background: var(--snd-surface);
	overflow: hidden;
	box-shadow: var(--snd-shadow-sm);
	transition: var(--snd-transition);
}
.snd-accordion-item:hover { box-shadow: var(--snd-shadow); }
.snd-accordion-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 14px 18px;
	cursor: pointer;
	user-select: none;
	gap: 12px;
	transition: background .12s;
}
.snd-accordion-header:hover { background: #f8fafc; }
.snd-accordion-body {
	border-top: 1px solid var(--snd-border);
	padding: 18px;
}

/* ── Reports page ────────────────────────────────────────────── */
.snd-reports-toolbar {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
	margin-bottom: 16px;
	padding: 14px 18px;
	background: var(--snd-surface);
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius-lg);
	box-shadow: var(--snd-shadow-sm);
}
.snd-reports-search-wrap { position: relative; flex-shrink: 0; }
.snd-reports-filters { display: flex; gap: 6px; flex-wrap: wrap; }
.snd-filter-btn {
	font-size: 12px !important;
	padding: 4px 11px !important;
	height: auto !important;
	line-height: 1.5 !important;
	border-radius: 20px !important;
	font-weight: 600 !important;
	transition: var(--snd-transition) !important;
}
.snd-filter-btn.active {
	background: var(--snd-text-1) !important;
	border-color: var(--snd-text-1) !important;
	color: #fff !important;
}
.snd-report-row { cursor: pointer; transition: background .12s; }
.snd-report-row:hover td { background: #f8fafc !important; }
.snd-col-toggle { text-align: center !important; padding: 8px 4px !important; }
.snd-row-toggle {
	width: 24px; height: 24px;
	display: inline-flex; align-items: center; justify-content: center;
	border-radius: 5px;
	color: var(--snd-text-3);
	transition: var(--snd-transition);
}
.snd-row-toggle:hover { background: #e9ecef; color: var(--snd-text-1); }
.snd-openrate-wrap { display: flex; align-items: center; gap: 8px; }
.snd-openrate-bar {
	flex: 1;
	height: 7px;
	background: #e9ecef;
	border-radius: 4px;
	overflow: hidden;
}
.snd-openrate-fill { height: 100%; border-radius: 4px; transition: width .5s cubic-bezier(.4,0,.2,1); }
.snd-openrate-pct { font-weight: 700; font-size: 13px; width: 38px; flex-shrink: 0; text-align: right; color: var(--snd-text-1); }
.snd-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
.snd-dot-green { background: var(--snd-green); box-shadow: 0 0 0 3px rgba(22,163,74,.15); }
.snd-dot-grey  { background: var(--snd-text-4); }
.snd-pill {
	display: inline-block;
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
	margin-right: 3px;
}
.snd-pill-blue { background: var(--snd-blue-light); color: #1e40af; }
.snd-pill-red  { background: var(--snd-red-lt); color: #991b1b; }
.snd-detail-row td { padding: 0 !important; background: #f8fafc !important; }
.snd-detail-inner {
	padding: 16px 20px 20px 36px;
	border-top: 3px solid var(--snd-blue);
	border-left: none;
}
.snd-detail-loading { padding: 16px; display: flex; align-items: center; gap: 10px; color: var(--snd-text-3); font-size: 13px; }
.snd-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.snd-sortable:hover { background: #f8fafc !important; }
.snd-sort-icon { opacity: .4; font-size: 11px; transition: opacity .12s; }
.snd-sortable:hover .snd-sort-icon { opacity: 1; }
.snd-dl-row td { background: #fafafa; }

/* ── Static header (access control page) ────────────────────── */
.snd-static-header { cursor: default !important; }
.snd-static-header:hover { background: inherit !important; }

/* ── Update mail tab ─────────────────────────────────────────── */
#snd-update-recipients-list label { display: block; padding: 5px 0; }
#snd-update-body { resize: vertical; }

/* ── Incident list ───────────────────────────────────────────── */
.snd-incident-list { list-style: none; margin: 0; padding: 0; }
.snd-incident-link,
.snd-incident-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 9px 10px;
	border-radius: var(--snd-radius-sm);
	border-bottom: 1px solid var(--snd-border);
	text-decoration: none;
	color: inherit;
	transition: background .12s;
}
.snd-incident-link:hover { background: var(--snd-blue-light); color: var(--snd-blue); }
.snd-incident-type { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.snd-type-brandweer { background: #ef4444; }
.snd-type-politie   { background: #3b82f6; }
.snd-type-mmt       { background: #10b981; }
.snd-type-overig    { background: #9ca3af; }
.snd-incident-time  { font-size: 12px; color: var(--snd-text-3); flex-shrink: 0; width: 40px; }

/* ── Chat layout ─────────────────────────────────────────────── */
.snd-chat-layout {
	display: flex;
	height: calc(100vh - 80px);
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius-lg);
	overflow: hidden;
	background: var(--snd-surface);
	margin-top: 16px;
	box-shadow: var(--snd-shadow);
}
.snd-chat-sidebar {
	width: 290px;
	flex-shrink: 0;
	border-right: 1px solid var(--snd-border);
	display: flex;
	flex-direction: column;
	background: #f8fafc;
}
.snd-chat-sidebar-header {
	padding: 18px 16px 14px;
	border-bottom: 1px solid var(--snd-border);
	flex-shrink: 0;
	background: linear-gradient(135deg, #1e293b, #0f172a);
}
.snd-chat-sidebar-header h2 { margin: 0; font-size: .95rem; color: #fff; }
.snd-chat-incident-list { flex: 1; overflow-y: auto; padding: 8px; }
.snd-chat-incident-item {
	display: block;
	width: 100%;
	text-align: left;
	padding: 10px 12px;
	border: none;
	background: transparent;
	border-radius: var(--snd-radius);
	cursor: pointer;
	position: relative;
	margin-bottom: 3px;
	transition: var(--snd-transition);
}
.snd-chat-incident-item:hover { background: #eff6ff; }
.snd-chat-incident-item.active { background: var(--snd-blue); color: #fff; box-shadow: var(--snd-shadow-sm); }
.snd-chat-incident-item.active .snd-chat-incident-meta { color: rgba(255,255,255,.7); }
.snd-chat-incident-title { display: block; font-size: 13px; font-weight: 600; line-height: 1.4; margin-bottom: 2px; }
.snd-chat-incident-meta { display: block; font-size: 11px; color: var(--snd-text-3); }
.snd-chat-unread-badge {
	position: absolute; top: 10px; right: 10px;
	background: var(--snd-red);
	color: #fff; font-size: 10px; font-weight: 700;
	min-width: 18px; height: 18px;
	border-radius: 9px; display: flex; align-items: center; justify-content: center; padding: 0 5px;
}
.snd-chat-empty { padding: 16px; font-size: 13px; color: var(--snd-text-4); font-style: italic; }
.snd-chat-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.snd-chat-main-placeholder {
	flex: 1; display: flex; flex-direction: column;
	align-items: center; justify-content: center;
	color: var(--snd-text-4); gap: 12px;
}
.snd-chat-placeholder-icon { font-size: 3rem; }
.snd-chat-thread-wrap { display: flex; flex-direction: column; height: 100%; }
.snd-chat-thread-header {
	padding: 14px 20px;
	border-bottom: 1px solid var(--snd-border);
	display: flex; justify-content: space-between; align-items: center;
	flex-shrink: 0; background: var(--snd-surface); gap: 12px;
}
.snd-chat-thread-title { font-weight: 700; font-size: 14px; }
.snd-chat-partner-tags { display: inline-flex; gap: 6px; flex-wrap: wrap; margin-left: 10px; }
.snd-chat-partner-tag {
	background: var(--snd-blue-light);
	color: var(--snd-blue);
	font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;
}
.snd-chat-messages {
	flex: 1; overflow-y: auto;
	padding: 20px; display: flex; flex-direction: column; gap: 4px;
	background: #f8fafc;
}
.snd-chat-loading, .snd-chat-no-messages {
	display: flex; align-items: center; justify-content: center;
	gap: 10px; color: var(--snd-text-4); font-size: 13px; flex: 1;
}
.snd-chat-date-divider { text-align: center; margin: 12px 0 8px; position: relative; }
.snd-chat-date-divider span {
	background: #f8fafc; padding: 0 10px;
	font-size: 11px; color: var(--snd-text-4); position: relative; z-index: 1;
}
.snd-chat-date-divider::before {
	content: ''; position: absolute; left: 0; right: 0; top: 50%;
	height: 1px; background: var(--snd-border);
}
.snd-chat-msg {
	max-width: 70%; padding: 10px 14px; border-radius: 14px;
	font-size: 13px; line-height: 1.55; margin-bottom: 6px; position: relative;
	animation: sndMsgIn .15s ease;
}
@keyframes sndMsgIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
.snd-msg-admin {
	align-self: flex-end;
	background: var(--snd-blue);
	color: #fff;
	border-bottom-right-radius: 4px;
}
.snd-msg-partner {
	align-self: flex-start;
	background: var(--snd-surface);
	color: var(--snd-text-1);
	border: 1px solid var(--snd-border);
	border-bottom-left-radius: 4px;
}
.snd-msg-unread .snd-chat-msg-meta::after { content: '●'; color: var(--snd-red); font-size: 8px; margin-left: 4px; vertical-align: middle; }
.snd-chat-msg-meta { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-size: 11px; opacity: .75; }
.snd-msg-admin .snd-chat-msg-meta { opacity: .85; }
.snd-chat-msg-name { font-weight: 700; }
.snd-chat-msg-time { flex: 1; }
.snd-chat-msg-body { word-break: break-word; }
.snd-chat-delete { opacity: 0; transition: opacity .15s; font-size: 11px; color: rgba(255,255,255,.7) !important; padding: 0 2px; }
.snd-chat-msg:hover .snd-chat-delete { opacity: 1; }
.snd-chat-composer {
	padding: 14px 16px; border-top: 1px solid var(--snd-border);
	background: var(--snd-surface); flex-shrink: 0;
}
.snd-chat-composer-inner { display: flex; flex-direction: column; gap: 8px; }
.snd-chat-composer textarea {
	width: 100%; resize: vertical; min-height: 72px;
	border: 1px solid var(--snd-border-2);
	border-radius: var(--snd-radius); padding: 10px 12px; font-size: 13px; font-family: inherit;
	transition: border-color .15s, box-shadow .15s;
}
.snd-chat-composer textarea:focus {
	border-color: var(--snd-blue); outline: none;
	box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}
.snd-chat-composer-footer { display: flex; justify-content: space-between; align-items: center; }
.snd-chat-composer-hint { font-size: 11px; color: var(--snd-text-4); }

/* ── Map pages ───────────────────────────────────────────────── */
.snd-map-wrap {
	display: flex;
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius-lg);
	overflow: hidden;
	margin-top: 0;
	box-shadow: var(--snd-shadow);
}
#snd-admin-map { flex: 1; min-height: 400px; }
.snd-map-sidebar {
	width: 250px; flex-shrink: 0;
	background: #f8fafc; border-right: 1px solid var(--snd-border);
	padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px;
}
.snd-map-sidebar h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--snd-text-3); margin: 0 0 8px; }
.snd-legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 6px; }
.snd-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.snd-map-filter-label { display: block; font-size: 13px; margin-bottom: 6px; cursor: pointer; }
.snd-map-selected-panel { background: var(--snd-surface); border: 1px solid var(--snd-border); border-radius: var(--snd-radius); padding: 12px; }
.snd-map-selected-panel h4 { font-size: 13px; margin: 0 0 8px; }
.snd-map-popup { font-family: -apple-system, sans-serif; }
.snd-map-popup-title { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
.snd-map-popup-meta { font-size: 12px; color: var(--snd-text-3); margin-bottom: 3px; }
.snd-map-popup-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.snd-map-btn {
	display: inline-block; padding: 5px 10px; font-size: 12px; font-weight: 500;
	border-radius: 5px; cursor: pointer; border: 1px solid var(--snd-border);
	background: var(--snd-surface); color: var(--snd-text-1); text-decoration: none;
	transition: var(--snd-transition);
}
.snd-map-btn:hover { background: var(--snd-blue-light); border-color: var(--snd-blue-mid); color: var(--snd-blue); }

/* ── P2000 page ──────────────────────────────────────────────── */
.snd-p2000-tabs { margin-top: 16px; }
.snd-p2000-layout {
	display: grid;
	grid-template-columns: 1fr 290px;
	gap: 18px;
	align-items: start;
	margin-top: 8px;
}
.snd-p2000-table-wrap { min-width: 0; }
.snd-p2000-minimap-wrap {
	background: var(--snd-surface);
	border: 1px solid var(--snd-border);
	border-radius: var(--snd-radius-lg);
	overflow: hidden;
	position: sticky;
	top: 32px;
	box-shadow: var(--snd-shadow-sm);
}
.snd-p2000-minimap-header {
	padding: 11px 14px; border-bottom: 1px solid var(--snd-border);
	display: flex; justify-content: space-between; align-items: center; font-size: 13px;
	background: #f8fafc;
}
.snd-p2000-badge {
	display: inline-block; color: #fff;
	font-size: 11px; font-weight: 700;
	padding: 2px 9px; border-radius: 10px; text-transform: capitalize;
}
.snd-p2000-table td { vertical-align: top; }
.snd-p2000-row { cursor: default; transition: background .1s; }
.snd-p2000-row:hover td { background: #f8fafc !important; }
.snd-p2000-mappin {
	font-size: 11px; color: var(--snd-blue); cursor: pointer;
	text-decoration: none; border: none; background: none; padding: 0;
	transition: color .12s;
}
.snd-p2000-mappin:hover { text-decoration: underline; }

/* ── Dashboard filter buttons ────────────────────────────────── */
.snd-dash-filter {
	border-radius: 20px !important;
	font-size: 12px !important;
	font-weight: 600 !important;
	padding: 4px 12px !important;
	height: auto !important;
	line-height: 1.5 !important;
	transition: var(--snd-transition) !important;
}
.snd-dash-filter.active {
	background: var(--snd-text-1) !important;
	border-color: var(--snd-text-1) !important;
	color: #fff !important;
}

/* ── Live activity indicator ─────────────────────────────────── */
#snd-live-viewers {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: var(--snd-green-lt);
	border: 1px solid #86efac;
	color: #166534;
	font-size: 12px;
	font-weight: 600;
	padding: 4px 12px;
	border-radius: 20px;
}
#snd-live-viewers .live-pulse {
	width: 8px; height: 8px;
	border-radius: 50%;
	background: var(--snd-green);
	animation: sndPulse 2s infinite;
}
@keyframes sndPulse {
	0%,100% { opacity:1; transform:scale(1); }
	50%      { opacity:.5; transform:scale(1.3); }
}

/* ── Spinner ─────────────────────────────────────────────────── */
.snd-spinner {
	display: inline-block;
	width: 18px; height: 18px;
	border: 2px solid var(--snd-border-2);
	border-top-color: var(--snd-blue);
	border-radius: 50%;
	animation: sndSpin .7s linear infinite;
}
@keyframes sndSpin { to { transform: rotate(360deg); } }

/* ── Role select ─────────────────────────────────────────────── */
.snd-role-select { font-size: 12px !important; border-radius: var(--snd-radius-sm) !important; }

/* ── Dashboard table dispatch badges ─────────────────────────── */
tr[data-dispatched="1"] td:first-child::before {
	content: '';
	display: inline-block;
	width: 5px; height: 5px;
	border-radius: 50%;
	background: var(--snd-green);
	margin-right: 6px;
	vertical-align: middle;
}
tr[data-dispatched="0"] td:first-child::before {
	content: '';
	display: inline-block;
	width: 5px; height: 5px;
	border-radius: 50%;
	background: #f97316;
	margin-right: 6px;
	vertical-align: middle;
}
