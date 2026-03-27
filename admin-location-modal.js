<?php
/**
 * Front-end editorial portal – /persportaal/redactie?access_code=…
 * For outlets with role = 'redacteur'.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$outlet = $GLOBALS['snd_outlet'] ?? null;
if ( ! $outlet || ( $outlet['role'] ?? '' ) !== 'redacteur' ) wp_die( 'Geen toegang.' );

$site_name = get_bloginfo( 'name' );
$ajax_url  = admin_url( 'admin-ajax.php' );
$nonce     = wp_create_nonce( 'snd_editor_nonce' );
$code      = $outlet['access_code'];
$google_key = get_option( 'snd_google_maps_api_key', '' );
?>
<!DOCTYPE html>
<html lang="nl-NL">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($site_name); ?> – Redactieportaal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--sidebar:#fff;--detail:#fff;--border:#e1e5eb;
  --text:#111827;--muted:#6b7280;--accent:#2563eb;--accent-h:#1d4ed8;
  --bw:#ef4444;--po:#f59e0b;--mmt:#8b5cf6;--green:#16a34a;
  --font:'Inter',-apple-system,sans-serif;--r:8px;
  --shadow-sm:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
  --shadow:0 4px 12px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.04);
}
html,body{height:100%;font-family:var(--font);background:var(--bg);color:var(--text);}
body{display:flex;flex-direction:column;overflow:hidden;}

/* ── Header ── */
.ep-header{padding:11px 18px;background:linear-gradient(135deg,#1e293b,#0f172a);color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.2);}
.ep-brand{font-size:11px;font-weight:700;opacity:.55;text-transform:uppercase;letter-spacing:.8px;}
.ep-title{font-size:15px;font-weight:700;margin-top:1px;}
.ep-user{font-size:12px;background:rgba(255,255,255,.12);padding:5px 12px;border-radius:20px;}

/* ── 3-column layout ── */
.ep-layout{flex:1;display:flex;overflow:hidden;min-height:0;}
.ep-sidebar{width:290px;flex-shrink:0;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
#ep-map{flex:1;min-width:0;z-index:1;}
.ep-detail{width:340px;flex-shrink:0;background:var(--detail);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;transition:width .2s;}
.ep-detail.hidden{width:0;overflow:hidden;}

/* ── Sidebar tabs ── */
.ep-tabs{display:flex;border-bottom:1px solid var(--border);flex-shrink:0;}
.ep-tab{flex:1;padding:10px 4px;font-size:11px;font-weight:600;color:var(--muted);cursor:pointer;border:none;background:transparent;border-bottom:2px solid transparent;transition:all .15s;text-align:center;}
.ep-tab.active{color:var(--accent);border-bottom-color:var(--accent);}

/* ── Search ── */
.ep-search-wrap{padding:8px 10px;border-bottom:1px solid var(--border);flex-shrink:0;position:relative;}
.ep-search-wrap svg{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:var(--muted);width:13px;height:13px;pointer-events:none;}
.ep-search{width:100%;padding:6px 8px 6px 28px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:var(--font);background:var(--bg);color:var(--text);outline:none;}
.ep-search:focus{border-color:var(--accent);}

/* ── Filter bar ── */
.ep-filter-bar{padding:6px 10px;border-bottom:1px solid var(--border);display:flex;gap:4px;flex-wrap:wrap;flex-shrink:0;}
.ep-filter{padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;transition:all .15s;}
.ep-filter.active{background:var(--accent);border-color:var(--accent);color:#fff;}

/* ── Panel & items ── */
.ep-panel{flex:1;overflow-y:auto;display:none;}
.ep-panel.active{display:block;}
.ep-item{padding:10px 12px;border-bottom:1px solid var(--border);cursor:pointer;display:flex;gap:9px;align-items:flex-start;transition:background .12s;}
.ep-item:hover{background:#f8fafc;}
.ep-item.active{background:#eff6ff;border-left:3px solid var(--accent);padding-left:9px;}
.ep-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:4px;}
.ep-diamond{width:9px;height:9px;transform:rotate(45deg);border-radius:2px;flex-shrink:0;margin-top:4px;}
.ep-item-title{font-size:12px;font-weight:600;line-height:1.4;}
.ep-item-meta{font-size:11px;color:var(--muted);margin-top:2px;}
.ep-badge{display:inline-block;font-size:9px;font-weight:700;padding:1px 5px;border-radius:6px;margin-top:2px;}
.ep-empty{padding:20px 12px;text-align:center;color:var(--muted);font-size:12px;}

/* ── Legend ── */
.ep-legend{padding:10px 12px;border-top:1px solid var(--border);flex-shrink:0;}
.ep-legend-row{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--muted);margin-bottom:4px;}

/* ── Detail panel ── */
.ep-detail-header{padding:14px 16px 10px;border-bottom:1px solid var(--border);flex-shrink:0;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.ep-detail-title{font-size:14px;font-weight:700;line-height:1.4;flex:1;}
.ep-detail-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);line-height:1;padding:0;}
.ep-detail-body{flex:1;overflow-y:auto;padding:12px 16px;}
.ep-section{margin-bottom:16px;}
.ep-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:6px;}
.ep-meta-row{display:flex;justify-content:space-between;align-items:baseline;font-size:12px;padding:3px 0;border-bottom:1px solid #f1f5f9;}
.ep-meta-row:last-child{border:none;}

/* Status buttons */
.ep-status-btns{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px;}
.ep-status-btn{padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:2px solid var(--border);background:#fff;color:var(--text);transition:all .15s;}
.ep-status-btn:hover{opacity:.8;}
.ep-status-btn.active-ow{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.ep-status-btn.active-tp{background:#3b82f6;border-color:#3b82f6;color:#fff;}
.ep-status-btn.active-af{background:#10b981;border-color:#10b981;color:#fff;}

/* Log entries */
.ep-log{max-height:160px;overflow-y:auto;margin-bottom:8px;}
.ep-log-entry{padding:4px 0;border-bottom:1px solid #f1f5f9;display:flex;gap:7px;align-items:baseline;font-size:12px;}
.ep-log-entry:last-child{border:none;}
.ep-log-time{font-size:10px;color:var(--muted);white-space:nowrap;min-width:30px;}
.ep-log-user{font-size:10px;color:#94a3b8;white-space:nowrap;}
.ep-log-add{display:flex;gap:6px;margin-top:4px;}
.ep-log-input{flex:1;padding:5px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:var(--font);}

/* P2000 meldingen list in detail */
.ep-p2k-entry{padding:5px 8px;background:#f8fafc;border-left:3px solid var(--accent);border-radius:0 4px 4px 0;margin-bottom:5px;font-size:12px;}
.ep-p2k-entry.manual{border-left-color:#f59e0b;background:#fffbf0;}

/* Views table */
.ep-views-table{width:100%;border-collapse:collapse;font-size:11px;}
.ep-views-table th{text-align:left;font-weight:600;color:var(--muted);padding:3px 0;border-bottom:1px solid var(--border);}
.ep-views-table td{padding:3px 0;border-bottom:1px solid #f1f5f9;}

/* Buttons */
.ep-btn{padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#f8fafc;color:var(--text);transition:all .15s;}
.ep-btn-primary{background:var(--accent);border-color:var(--accent-h);color:#fff;}
.ep-btn-sm{padding:4px 9px;font-size:11px;}
.ep-btn:hover{opacity:.85;}
.ep-btn:disabled{opacity:.5;cursor:not-allowed;}

/* Modals */
.ep-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;}
.ep-modal{background:#fff;border-radius:10px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;}
.ep-modal-wide{max-width:700px;}
.ep-modal-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.ep-modal-header h2{font-size:14px;font-weight:700;margin:0;}
.ep-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;}
.ep-modal-body{padding:16px 18px;}
.ep-modal-footer{padding:10px 18px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.ep-status-msg{font-size:12px;}
.ep-status-msg.ok{color:#16a34a;} .ep-status-msg.err{color:#b32d2e;}
label.ep-label{display:block;font-weight:600;font-size:12px;margin-bottom:4px;color:var(--text);}
.ep-input{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;margin-bottom:10px;font-family:var(--font);}
.ep-select{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;margin-bottom:10px;}
.ep-q-box{background:#f8fafc;border-left:3px solid var(--accent);padding:9px 12px;border-radius:0 6px 6px 0;font-size:12px;font-style:italic;margin-bottom:12px;color:#475569;font-family:monospace;}
.ep-map-modal{height:300px;border-radius:6px;overflow:hidden;border:1px solid var(--border);margin-bottom:10px;}
.ep-coord-row{display:grid;grid-template-columns:1fr 1fr 2fr;gap:8px;}
.ep-hint{font-size:11px;color:var(--muted);}

/* Popup */
.ep-popup{font-family:var(--font);min-width:180px;}
.ep-popup-type{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.ep-popup-title{font-size:13px;font-weight:700;margin-bottom:4px;line-height:1.3;}
.ep-popup-meta{font-size:11px;color:#64748b;margin-bottom:2px;}
.ep-popup-actions{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px;}
.ep-popup-btn{padding:4px 10px;font-size:11px;font-weight:600;border-radius:4px;cursor:pointer;border:1px solid #ddd;background:#f6f7f7;color:#1d2327;}
.ep-popup-btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}

@media(max-width:900px){
  .ep-sidebar{width:240px;}
  .ep-detail{width:300px;}
}
@media(max-width:600px){
  .ep-layout{flex-direction:column;}
  .ep-sidebar{width:100%;height:200px;}
  .ep-detail{width:100%;height:260px;border-left:none;border-top:1px solid var(--border);}
}
</style>
</head>
<body>

<header class="ep-header">
  <div>
    <div class="ep-brand"><?php echo esc_html($site_name); ?> &mdash; Redactieportaal</div>
    <div class="ep-title">&#x1F5FA; Kaart &amp; Incidentbeheer</div>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <button id="ep-export-csv" title="Exporteer incidentenoverzicht als CSV"
      style="font-size:11px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.9);border-radius:5px;padding:5px 10px;cursor:pointer;transition:background .15s;"
      onmouseover="this.style.background='rgba(255,255,255,.22)'" onmouseout="this.style.background='rgba(255,255,255,.12)'">
      &#x2B07; CSV export
    </button>
    <a href="<?php echo esc_url(home_url('/persportaal/?access_code='.rawurlencode($code))); ?>" style="font-size:11px;color:rgba(255,255,255,.7);text-decoration:none;">&#x2190; Persportaal</a>
    <div class="ep-user">&#x1F464; <?php echo esc_html($outlet['name']); ?></div>
  </div>
</header>

<div class="ep-layout">

  <!-- ── Sidebar ─────────────────────────────────────────────────────── -->
  <aside class="ep-sidebar">
    <div class="ep-tabs">
      <button class="ep-tab active" data-panel="ep-panel-incidents">&#x1F4F0; Items <span id="cnt-inc" style="font-weight:400;opacity:.7;"></span></button>
      <button class="ep-tab" data-panel="ep-panel-p2000">&#x1F6A8; P2000 <span id="cnt-p2k" style="font-weight:400;opacity:.7;"></span></button>
    </div>

    <!-- Incidents panel -->
    <div class="ep-panel active" id="ep-panel-incidents">
      <div class="ep-search-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="search" class="ep-search" id="inc-search" placeholder="Zoek incidenten…">
      </div>
      <div class="ep-filter-bar" style="justify-content:space-between;">
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
          <button class="ep-filter active" data-filter="all">Alles</button>
          <button class="ep-filter" data-filter="live">&#x25CF; Live</button>
          <button class="ep-filter" data-filter="dispatched">Verstuurd</button>
          <button class="ep-filter" data-filter="no_loc">Geen locatie</button>
          <button class="ep-filter" data-filter="has_photos">&#x1F4F7; Foto's</button>
          <button class="ep-filter" data-filter="no_dispatch">&#x26AA; Niet verstuurd</button>
        </div>
        <button id="ep-export-csv" title="Exporteer als CSV" style="background:none;border:1px solid var(--border);border-radius:6px;padding:3px 8px;font-size:11px;color:var(--muted);cursor:pointer;white-space:nowrap;">⬇ CSV</button>
      </div>
      <div class="ep-empty" id="inc-loading">Laden&#x2026;</div>
      <div id="inc-list"></div>
    </div>

    <!-- P2000 panel -->
    <div class="ep-panel" id="ep-panel-p2000">
      <div class="ep-search-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="search" class="ep-search" id="p2k-search" placeholder="Zoek meldingen…">
      </div>
      <!-- Bulk link toolbar -->
      <div id="ep-p2k-bulk-bar" style="display:none;padding:6px 10px;background:var(--accent);color:#fff;font-size:12px;display:none;align-items:center;gap:8px;flex-shrink:0;">
        <span id="ep-p2k-bulk-count">0</span> geselecteerd
        <select id="ep-p2k-bulk-post" style="flex:1;font-size:11px;padding:3px 6px;border:none;border-radius:4px;color:#1e293b;max-width:160px;">
          <option value="">— Koppel aan item —</option>
        </select>
        <button id="ep-p2k-bulk-link" class="ep-btn ep-btn-sm" style="background:#fff;color:var(--accent);border:none;font-size:11px;">Koppelen</button>
        <button id="ep-p2k-bulk-cancel" style="background:none;border:none;color:rgba(255,255,255,.8);cursor:pointer;font-size:14px;">✕</button>
      </div>
      <div class="ep-filter-bar" id="p2k-filters">
        <button class="ep-filter active" data-type="all">Alles</button>
        <button class="ep-filter" data-type="brandweer">&#x1F525;</button>
        <button class="ep-filter" data-type="politie">&#x1F694;</button>
        <button class="ep-filter" data-type="mmt">&#x1F691;</button>
        <button class="ep-filter" data-type="unlinked">&#x25CB; Los</button>
      </div>
      <div class="ep-empty" id="p2k-loading">Laden&#x2026;</div>
      <div id="p2k-list"></div>
    </div>

    <div class="ep-legend">
      <div class="ep-legend-row"><span class="ep-dot" style="background:var(--accent);"></span> Incident</div>
      <div class="ep-legend-row"><span class="ep-dot" style="background:var(--green);"></span> Verstuurd / gekoppeld</div>
      <div class="ep-legend-row"><span class="ep-dot" style="background:#ef4444;"></span> Live</div>
      <div class="ep-legend-row"><span class="ep-diamond" style="background:var(--bw);"></span> P2000 Brandweer</div>
      <div class="ep-legend-row"><span class="ep-diamond" style="background:var(--po);"></span> P2000 Politie</div>
      <div class="ep-legend-row"><span class="ep-diamond" style="background:var(--mmt);"></span> P2000 MMT</div>
    </div>
  </aside>

  <!-- ── Map ────────────────────────────────────────────────────────── -->
  <div id="ep-map"></div>

  <!-- ── Detail panel ──────────────────────────────────────────────── -->
  <div class="ep-detail hidden" id="ep-detail">
    <div class="ep-detail-header">
      <div class="ep-detail-title" id="detail-title">&#x2014;</div>
      <button class="ep-detail-close" id="detail-close">&times;</button>
    </div>
    <div class="ep-detail-body" id="detail-body">
      <div class="ep-empty">Selecteer een incident.</div>
    </div>
  </div>

</div><!-- .ep-layout -->

<!-- ── Link P2000 modal ─────────────────────────────────────────────── -->
<div class="ep-modal-backdrop" id="modal-link" style="display:none;">
  <div class="ep-modal">
    <div class="ep-modal-header">
      <h2>&#x1F517; P2000 koppelen aan bericht</h2>
      <button class="ep-modal-close" data-modal="modal-link">&times;</button>
    </div>
    <div class="ep-modal-body">
      <div class="ep-q-box" id="link-tekst"></div>
      <label class="ep-label">Koppel aan bestaand bericht:</label>
      <select class="ep-select" id="link-post-select">
        <option value="">&#x2014; Selecteer &#x2014;</option>
      </select>
      <p style="text-align:center;color:#aaa;font-size:11px;margin:-5px 0 10px;">&#x2014; of &#x2014;</p>
      <a id="link-new-post" href="#" target="_blank" class="ep-btn" style="display:block;text-align:center;text-decoration:none;">+ Nieuw bericht aanmaken</a>
    </div>
    <div class="ep-modal-footer">
      <span class="ep-status-msg" id="link-status"></span>
      <div style="display:flex;gap:7px;">
        <button class="ep-btn ep-btn-primary" id="link-confirm">Koppelen</button>
        <button class="ep-btn" data-modal="modal-link">Annuleren</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Location modal ───────────────────────────────────────────────── -->
<div class="ep-modal-backdrop" id="modal-loc" style="display:none;">
  <div class="ep-modal ep-modal-wide">
    <div class="ep-modal-header">
      <h2>&#x1F4CD; Locatie aanpassen</h2>
      <button class="ep-modal-close" data-modal="modal-loc">&times;</button>
    </div>
    <div class="ep-modal-body">
      <div style="display:flex;gap:7px;margin-bottom:8px;">
        <input type="text" id="loc-search" class="ep-input" style="flex:1;margin:0;" placeholder="Zoek adres&#x2026;">
        <button class="ep-btn" id="loc-search-btn">Zoek</button>
      </div>
      <div class="ep-map-modal" id="loc-map"></div>
      <div class="ep-coord-row">
        <label><span class="ep-label">Lat</span><input type="text" id="loc-lat" class="ep-input" style="font-size:11px;font-family:monospace;"></label>
        <label><span class="ep-label">Lon</span><input type="text" id="loc-lon" class="ep-input" style="font-size:11px;font-family:monospace;"></label>
        <label><span class="ep-label">Straat</span><input type="text" id="loc-street" class="ep-input" style="font-size:12px;"></label>
      </div>
      <p class="ep-hint">&#x1F4A1; Klik op de kaart of sleep de marker.</p>
    </div>
    <div class="ep-modal-footer">
      <span class="ep-status-msg" id="loc-status"></span>
      <div style="display:flex;gap:7px;">
        <button class="ep-btn ep-btn-primary" id="loc-save">Opslaan</button>
        <button class="ep-btn" data-modal="modal-loc">Annuleren</button>
      </div>
    </div>
  </div>
</div>

<script src="<?php echo esc_url(includes_url('js/jquery/jquery.min.js')); ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function($){
'use strict';

const AJAX  = <?php echo wp_json_encode($ajax_url); ?>;
const NONCE = <?php echo wp_json_encode($nonce); ?>;
const CODE  = <?php echo wp_json_encode($code); ?>;
const GKEY  = <?php echo wp_json_encode($google_key); ?>;
const EDIT_BASE = <?php echo wp_json_encode(admin_url('post.php')); ?>;
const NEW_BASE  = <?php echo wp_json_encode(admin_url('post-new.php')); ?>;

let map, incLayer, p2kLayer, locMap, locMarker;
let allInc = [], allP2k = [];
let activeIncId = null, linkP2kId = null, linkNewUrl = null, editPostId = null;
let incFilter = 'all', p2kFilter = 'all';
let incSearch = '', p2kSearch = '';

// ── Map init ─────────────────────────────────────────────────────────────────
function makeTile() {
  if (GKEY) return L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',{subdomains:['mt0','mt1','mt2','mt3'],maxZoom:21});
  return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19});
}
map = L.map('ep-map').setView([51.56,5.09],12);
makeTile().addTo(map);
incLayer = L.layerGroup().addTo(map);
p2kLayer = L.layerGroup().addTo(map);

function circleIcon(color,size=14){
  return L.divIcon({className:'',html:`<div style="width:${size}px;height:${size}px;border-radius:50%;background:${color};border:2.5px solid rgba(255,255,255,.9);box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>`,iconSize:[size,size],iconAnchor:[size/2,size/2]});
}
function diamondIcon(color){
  return L.divIcon({className:'',html:`<div style="width:11px;height:11px;background:${color};border:2px solid rgba(255,255,255,.9);box-shadow:0 2px 5px rgba(0,0,0,.3);transform:rotate(45deg);border-radius:2px;"></div>`,iconSize:[11,11],iconAnchor:[5.5,5.5]});
}
function typeColor(t){return{brandweer:'#ef4444',politie:'#f59e0b',mmt:'#8b5cf6'}[t]||'#9ca3af';}

// ── Load all data ─────────────────────────────────────────────────────────────
async function load(){
  try{
    const r = await post('snd_editor_get_map');
    if(!r.success) return;
    allInc = r.data.incidents||[];
    allP2k = r.data.p2000||[];
    // Populate post selector in link modal
    $('#link-post-select').html('<option value="">— Selecteer —</option>'+allInc.map(i=>`<option value="${i.id}">${e(i.title)}</option>`).join(''));
    $('#cnt-inc').text(allInc.length);
    $('#cnt-p2k').text(allP2k.length);
    $('#inc-loading').hide();
    $('#p2k-loading').hide();
    renderInc();
    renderP2k();
  } catch(err){ $('#inc-loading').text('Laadprobleem.'); }
}

// ── Render incidents ──────────────────────────────────────────────────────────
function renderInc(){
  incLayer.clearLayers();
  const q = incSearch.toLowerCase();
  let filtered = allInc.filter(i=>{
    if(q && !i.title.toLowerCase().includes(q)) return false;
    if(incFilter==='live')        return i.is_live;
    if(incFilter==='dispatched')  return i.dispatched;
    if(incFilter==='no_loc')      return !i.lat;
    if(incFilter==='has_photos')  return (i.photo_count||0) > 0;
    if(incFilter==='no_dispatch') return !i.dispatched;
    return true;
  });
  const $list = $('#inc-list').empty();
  if(!filtered.length){ $list.html('<div class="ep-empty">Geen incidenten.</div>'); return; }
  filtered.forEach(inc=>{
    const color = inc.is_live ? '#ef4444' : inc.dispatched ? '#10b981' : '#3b82f6';
    const status_label = {onderweg:'🚨 OW',ter_plaatse:'📍 TP',afgerond:'✓'}[inc.status||''] || '';
    $list.append(`<div class="ep-item${inc.id===activeIncId?' active':''}" data-id="${inc.id}">
      <span class="ep-dot" style="background:${color};"></span>
      <div>
        <div class="ep-item-title">${e(inc.title)}</div>
        <div class="ep-item-meta">${e(inc.date)}${inc.street?' · '+e(inc.street):''}</div>
        ${status_label?`<span class="ep-badge" style="background:#f1f5f9;color:#475569;">${status_label}</span>`:''}
        ${inc.is_live?'<span class="ep-badge" style="background:#fee2e2;color:#991b1b;">LIVE</span>':''}
      </div>
    </div>`);
    if(inc.lat && inc.lon){
      const m = L.marker([inc.lat,inc.lon],{icon:circleIcon(color)}).addTo(incLayer);
      m.bindPopup(incPopup(inc),{maxWidth:260});
      m.on('click',()=>{ activateInc(inc.id); });
    }
  });
}

function incPopup(inc){
  const ed = `${EDIT_BASE}?post=${inc.id}&action=edit`;
  return `<div class="ep-popup">
    <div class="ep-popup-type" style="color:#3b82f6;">Incident</div>
    <div class="ep-popup-title">${e(inc.title)}</div>
    <div class="ep-popup-meta">${e(inc.date)}</div>
    ${inc.street?`<div class="ep-popup-meta">📍 ${e(inc.street)}</div>`:''}
    <div class="ep-popup-actions">
      <button class="ep-popup-btn ep-popup-btn-primary ep-open-detail-btn" data-id="${inc.id}">Details</button>
      <button class="ep-popup-btn ep-editloc-btn" data-postid="${inc.id}" data-lat="${inc.lat||0}" data-lon="${inc.lon||0}" data-street="${e(inc.street||'')}">📍 Locatie</button>
      <a href="${ed}" target="_blank" class="ep-popup-btn">✏️ Bewerk</a>
    </div>
  </div>`;
}

function activateInc(id){
  activeIncId = id;
  $('.ep-item').removeClass('active');
  $(`.ep-item[data-id="${id}"]`).addClass('active');
  loadDetail(id);
  // Scroll sidebar item into view
  const $item = $(`#inc-list .ep-item[data-id="${id}"]`);
  if($item.length) $('#ep-panel-incidents').scrollTop($('#ep-panel-incidents').scrollTop()+$item.position().top-60);
}

// ── Detail panel ──────────────────────────────────────────────────────────────
async function loadDetail(postId){
  $('#ep-detail').removeClass('hidden');
  $('#detail-title').text('Laden…');
  $('#detail-body').html('<div class="ep-empty">Laden&#x2026;</div>');
  try{
    const r = await post('snd_editor_get_incident',{post_id:postId});
    if(!r.success){ $('#detail-body').html('<div class="ep-empty">Fout bij laden.</div>'); return; }
    renderDetail(r.data);
  } catch(e){ $('#detail-body').html('<div class="ep-empty">Serverfout.</div>'); }
}

function renderDetail(d){
  $('#detail-title').text(d.title);
  const statusLabels = {'':{l:'Geen',cls:''},'onderweg':{l:'🚨 Onderweg',cls:'active-ow'},'ter_plaatse':{l:'📍 Ter plaatse',cls:'active-tp'},'afgerond':{l:'✓ Afgerond',cls:'active-af'}};
  const curStatus = d.status||'';

  // Post meta
  let metaHtml = `
    <div class="ep-section">
      <div class="ep-section-label">Info</div>
      <div class="ep-meta-row"><span style="color:var(--muted);">Datum</span><span>${e(d.date)}</span></div>
      ${d.street?`<div class="ep-meta-row"><span style="color:var(--muted);">Locatie</span><span>${e(d.street)}</span></div>`:''}
      <div class="ep-meta-row"><span style="color:var(--muted);">Foto's</span><span>${d.photo_count}</span></div>
      <div class="ep-meta-row"><span style="color:var(--muted);">Verstuurd naar</span><span>${d.dispatched} outlet(s)</span></div>
      ${d.is_live?'<div class="ep-meta-row"><span style="color:#ef4444;font-weight:600;">🔴 LIVE verhaal</span></div>':''}
    </div>`;

  // Status
  metaHtml += `<div class="ep-section">
    <div class="ep-section-label">Incidentstatus</div>
    <div class="ep-status-btns">
      ${['onderweg','ter_plaatse','afgerond'].map(s=>`<button class="ep-status-btn ep-set-status${s===curStatus?' '+statusLabels[s].cls:''}" data-postid="${d.id}" data-status="${s}">${statusLabels[s].l}</button>`).join('')}
      ${curStatus?`<button class="ep-status-btn ep-set-status" data-postid="${d.id}" data-status="">✕ Wis</button>`:''}
    </div>
  </div>`;

  // P2000 meldingen
  metaHtml += `<div class="ep-section">
    <div class="ep-section-label" style="display:flex;justify-content:space-between;align-items:center;">
      P2000 Meldingen
      <button class="ep-btn ep-btn-sm" id="ep-add-p2k-btn" data-postid="${d.id}">+ Toevoegen</button>
    </div>`;

  // Original feed melding — now editable
  metaHtml += `<div style="margin-bottom:6px;">
    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#3b82f6;margin-bottom:3px;">Originele meldingtekst</div>
    <div style="display:flex;gap:5px;align-items:flex-start;">
      <textarea id="ep-p2k-raw-text" rows="2"
        style="flex:1;font-size:11px;font-family:monospace;padding:5px 7px;border:1px solid var(--border);border-radius:4px;resize:vertical;"
        placeholder="Geen originele meldingtekst">${e(d.p2000_raw||'')}</textarea>
      <button class="ep-btn ep-btn-sm ep-btn-primary" id="ep-p2k-raw-save" data-postid="${d.id}" style="white-space:nowrap;">Opslaan</button>
    </div>
    <div id="ep-p2k-raw-msg" style="font-size:11px;margin-top:3px;"></div>
  </div>`;

  if(d.manual_p2000 && d.manual_p2000.length){
    d.manual_p2000.forEach((m, idx)=>{
      const key = d.p2000_id ? d.p2000_id : 'post_' + d.id;
      metaHtml += `<div class="ep-p2k-entry manual" style="display:flex;justify-content:space-between;align-items:baseline;gap:6px;">
        <span><span style="font-size:9px;color:#f59e0b;font-weight:700;text-transform:uppercase;">✏ ${e(m.type)}</span> ${e(m.tekst)}</span>
        <button class="ep-btn ep-btn-sm ep-remove-manual-p2k" data-key="${e(key)}" data-idx="${idx}" data-postid="${d.id}" style="color:#b32d2e;font-size:10px;padding:2px 6px;flex-shrink:0;">✕</button>
      </div>`;
    });
  }

  if(!d.p2000_raw && (!d.manual_p2000||!d.manual_p2000.length)){
    metaHtml += `<div style="font-size:11px;color:var(--muted);font-style:italic;">Nog geen meldingen.</div>`;
  }
  // Add P2000 form (hidden)
  metaHtml += `<div id="ep-add-p2k-form" style="display:none;margin-top:8px;padding:8px;background:#f8fafc;border-radius:6px;border:1px solid var(--border);">
    <select id="ep-p2k-type" style="font-size:11px;padding:4px;border:1px solid var(--border);border-radius:4px;margin-bottom:6px;width:100%;">
      <option value="brandweer">🔥 Brandweer</option>
      <option value="politie">🚔 Politie</option>
      <option value="mmt">🚑 MMT</option>
      <option value="overig">📋 Overig</option>
    </select>
    <input type="text" id="ep-p2k-tekst" placeholder="P2000 meldingtekst…" style="width:100%;font-size:11px;padding:5px 7px;border:1px solid var(--border);border-radius:4px;margin-bottom:6px;font-family:monospace;">
    <div style="display:flex;gap:5px;">
      <button class="ep-btn ep-btn-primary ep-btn-sm" id="ep-p2k-save" data-postid="${d.id}">Opslaan</button>
      <button class="ep-btn ep-btn-sm" id="ep-p2k-cancel">Annuleer</button>
    </div>
    <div id="ep-p2k-msg" style="font-size:11px;margin-top:4px;"></div>
  </div>
  </div>`;

  // Logboek
  const postLog = d.editor_log || [];
  metaHtml += `<div class="ep-section">
    <div class="ep-section-label">Logboek</div>
    <div class="ep-log" id="detail-log">`;
  if(!postLog.length) metaHtml += `<div style="font-size:11px;color:var(--muted);font-style:italic;">Geen entries.</div>`;
  else postLog.slice().reverse().forEach(le=>{
    const t = le.time ? new Date(le.time*1000).toLocaleTimeString('nl-NL',{hour:'2-digit',minute:'2-digit',timeZone:'Europe/Amsterdam'}) : '—';
    metaHtml += `<div class="ep-log-entry"><span class="ep-log-time">${t}</span><span class="ep-log-user">${e(le.user)}</span><span>${e(le.text)}</span></div>`;
  });
  metaHtml += `</div>
    <div class="ep-log-add">
      <input type="text" class="ep-log-input" id="detail-log-input" placeholder="Log-entry toevoegen…" data-postid="${d.id}">
      <button class="ep-btn ep-btn-sm ep-btn-primary" id="detail-log-save" data-postid="${d.id}">+ Log</button>
    </div>
  </div>`;

  // Interne notities (alleen zichtbaar in redactieportaal)
  metaHtml += `<div class="ep-section">
    <div class="ep-section-label" style="display:flex;justify-content:space-between;align-items:center;">
      📝 Interne notitie
      <span style="font-size:10px;color:var(--muted);font-weight:400;">Niet zichtbaar voor media</span>
    </div>
    <textarea id="ep-internal-note" rows="3"
      style="width:100%;font-size:12px;padding:7px 9px;border:1px solid var(--border);border-radius:6px;resize:vertical;font-family:var(--font);color:var(--text);background:var(--bg);box-sizing:border-box;"
      placeholder="Noteer hier interne afspraken, context of aandachtspunten…">${e(d.internal_note||'')}</textarea>
    <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:5px;">
      <span id="ep-note-saved" style="font-size:11px;color:#16a34a;display:none;">✓ Opgeslagen</span>
      <button class="ep-btn ep-btn-sm ep-btn-primary" id="ep-save-note" data-postid="${d.id}">Opslaan</button>
    </div>
  </div>`;

  if(d.views && d.views.length){
    metaHtml += `<div class="ep-section">
      <div class="ep-section-label">Portaalactiviteit</div>
      <table class="ep-views-table">
        <tr><th>Outlet</th><th style="text-align:right;">Views</th><th style="text-align:right;">Downloads</th></tr>
        ${d.views.map(v=>`<tr><td>${e(v.name)}</td><td style="text-align:right;">${v.views}</td><td style="text-align:right;">${v.downloads}</td></tr>`).join('')}
      </table>
    </div>`;
  }

  // Action buttons
  metaHtml += `<div class="ep-section" style="display:flex;gap:6px;flex-wrap:wrap;">
    <a href="${EDIT_BASE}?post=${d.id}&action=edit" target="_blank" class="ep-btn ep-btn-sm">&#x270F;&#xFE0F; Bewerk item</a>
    <button class="ep-btn ep-btn-sm ep-editloc-btn" data-postid="${d.id}" data-lat="${d.lat||0}" data-lon="${d.lon||0}" data-street="${e(d.street||'')}">&#x1F4CD; Locatie</button>
  </div>`;

  $('#detail-body').html(metaHtml);
}

// ── Set incident status ───────────────────────────────────────────────────────
$(document).on('click', '.ep-set-status', function(){
  const $btn = $(this).prop('disabled',true);
  const postId = $btn.data('postid');
  const status = $btn.data('status');
  post('snd_editor_set_status',{post_id:postId,status:status}).done(r=>{
    if(r.success){
      // Refresh detail
      loadDetail(postId);
      // Update sidebar
      load();
    }
  }).always(()=>$btn.prop('disabled',false));
});

// ── Add P2000 melding ─────────────────────────────────────────────────────────
$(document).on('click','#ep-add-p2k-btn',()=>$('#ep-add-p2k-form').toggle());
$(document).on('click','#ep-p2k-cancel',()=>$('#ep-add-p2k-form').hide());

// ── Sla originele meldingtekst op ────────────────────────────────────────────
$(document).on('click','#ep-p2k-raw-save',function(){
  const $btn   = $(this).prop('disabled',true).text('…');
  const postId = $btn.data('postid');
  const raw    = $('#ep-p2k-raw-text').val().trim();
  post('snd_editor_update_p2000_raw',{post_id:postId,raw_text:raw}).done(r=>{
    const $msg = $('#ep-p2k-raw-msg');
    if(r.success){
      $msg.text('✓ Opgeslagen').css('color','#16a34a');
      setTimeout(()=>$msg.text(''),2500);
    } else {
      $msg.text(r.data&&r.data.message?r.data.message:'Fout.').css('color','#b32d2e');
    }
  }).always(()=>$btn.prop('disabled',false).text('Opslaan'));
});

// ── Interne notitie opslaan ───────────────────────────────────────────────────
$(document).on('click','#ep-save-note',function(){
  const $btn   = $(this).prop('disabled',true).text('…');
  const postId = $btn.data('postid');
  const note   = $('#ep-internal-note').val();
  post('snd_editor_save_note',{post_id:postId,note:note}).done(r=>{
    if(r.success){
      $('#ep-note-saved').show();
      setTimeout(()=>$('#ep-note-saved').hide(),2500);
    } else {
      alert(r.data&&r.data.message||'Fout bij opslaan.');
    }
  }).always(()=>$btn.prop('disabled',false).text('Opslaan'));
});

// ── Verwijder handmatige melding ──────────────────────────────────────────────
$(document).on('click','.ep-remove-manual-p2k',function(){
  const $btn   = $(this).prop('disabled',true);
  const key    = $btn.data('key');
  const idx    = $btn.data('idx');
  const postId = $btn.data('postid');
  $.post(AJAX,{action:'snd_p2000_remove_manual',nonce:NONCE,primary:key,idx:idx}).done(r=>{
    if(r.success){ loadDetail(postId); }
    else $btn.prop('disabled',false);
  }).fail(()=>$btn.prop('disabled',false));
});
$(document).on('click','#ep-p2k-save',function(){
  const $btn = $(this).prop('disabled',true);
  const postId = $btn.data('postid');
  const tekst = $('#ep-p2k-tekst').val().trim();
  const type  = $('#ep-p2k-type').val();
  if(!tekst){ $('#ep-p2k-msg').text('Vul een tekst in.').css('color','#b32d2e'); $btn.prop('disabled',false); return; }
  post('snd_editor_add_manual_p2000',{post_id:postId,tekst:tekst,type:type}).done(r=>{
    if(r.success){
      $('#ep-p2k-msg').text('✓ Opgeslagen.').css('color','#16a34a');
      $('#ep-p2k-tekst').val('');
      setTimeout(()=>{ $('#ep-add-p2k-form').hide(); loadDetail(postId); }, 800);
    } else { $('#ep-p2k-msg').text(r.data&&r.data.message?r.data.message:'Fout.').css('color','#b32d2e'); }
  }).always(()=>$btn.prop('disabled',false));
});

// ── Log entry ─────────────────────────────────────────────────────────────────
function saveLog(postId){
  const $input = $('#detail-log-input');
  const text = $input.val().trim();
  if(!text) return;
  const $btn = $('#detail-log-save').prop('disabled',true);
  post('snd_editor_add_log',{post_id:postId,text:text}).done(r=>{
    if(r.success){
      $input.val('');
      const le = r.data.entry;
      const t  = r.data.time_str||'—';
      const $log = $('#detail-log');
      $log.find('div[style*="italic"]').remove();
      $log.prepend(`<div class="ep-log-entry"><span class="ep-log-time">${t}</span><span class="ep-log-user">${$('<span>').text(le.user).html()}</span><span>${$('<span>').text(le.text).html()}</span></div>`);
    }
  }).always(()=>$btn.prop('disabled',false));
}
$(document).on('click','#detail-log-save',function(){ saveLog($(this).data('postid')); });
$(document).on('keydown','#detail-log-input',function(e){ if(e.which===13){ e.preventDefault(); saveLog($(this).data('postid')); } });

// ── Detail from popup ─────────────────────────────────────────────────────────
$(document).on('click','.ep-open-detail-btn',function(){
  activateInc(parseInt($(this).data('id'),10));
});
$('#detail-close').on('click',()=>$('#ep-detail').addClass('hidden'));

// ── Render P2000 ──────────────────────────────────────────────────────────────
function renderP2k(){
  p2kLayer.clearLayers();
  const q = p2kSearch.toLowerCase();
  let filtered = allP2k.filter(m=>{
    if(q && !(m.tekst+m.stad+m.straat).toLowerCase().includes(q)) return false;
    if(p2kFilter==='unlinked') return !m.linked;
    if(p2kFilter!=='all') return m.type===p2kFilter;
    return true;
  });
  const $list = $('#p2k-list').empty();
  if(!filtered.length){ $list.html('<div class="ep-empty">Geen meldingen.</div>'); return; }
  filtered.forEach(msg=>{
    const col = typeColor(msg.type);
    const loc = [msg.straat,msg.stad].filter(Boolean).join(', ');
    $list.append(`<div class="ep-item ep-p2k-item" data-p2kid="${e(msg.id)}" style="display:flex;align-items:flex-start;gap:8px;">
      <input type="checkbox" class="ep-p2k-cb" data-p2kid="${e(msg.id)}" style="margin-top:3px;flex-shrink:0;cursor:pointer;" title="Selecteer voor bulk koppelen">
      <span class="ep-diamond" style="background:${col};margin-top:4px;"></span>
      <div style="flex:1;min-width:0;">
        <div class="ep-item-title" style="font-size:11px;">${e(msg.tekst)}</div>
        <div class="ep-item-meta">${msg.tijd?msg.tijd+' · ':''}${e(loc)}</div>
        ${msg.linked?'<span class="ep-badge" style="background:#d1fae5;color:#065f46;">gekoppeld</span>':''}
      </div>
    </div>`);
    if(msg.lat && msg.lon){
      const mk = L.marker([msg.lat,msg.lon],{icon:diamondIcon(msg.linked?'#10b981':col)}).addTo(p2kLayer);
      mk.bindPopup(p2kPopup(msg),{maxWidth:260});
    }
  });
}

function p2kPopup(msg){
  const loc = [msg.straat,msg.stad].filter(Boolean).join(', ');
  const newUrl = `${NEW_BASE}?snd_lat=${encodeURIComponent(msg.lat)}&snd_lon=${encodeURIComponent(msg.lon)}&snd_street1=${encodeURIComponent(msg.straat||'')}&snd_p2000_id=${encodeURIComponent(msg.id)}&snd_p2000_raw_message=${encodeURIComponent(msg.tekst||'')}`;
  return `<div class="ep-popup">
    <div class="ep-popup-type" style="color:${typeColor(msg.type)};">${e(msg.type.charAt(0).toUpperCase()+msg.type.slice(1))}</div>
    <div class="ep-popup-title" style="font-size:12px;font-family:monospace;">${e(msg.tekst)}</div>
    <div class="ep-popup-meta">${msg.tijd||''}${loc?' · '+e(loc):''}</div>
    ${msg.linked?'<div class="ep-popup-meta" style="color:#10b981;">&#x2713; Gekoppeld aan bericht</div>':''}
    <div class="ep-popup-actions">
      <button class="ep-popup-btn ep-popup-btn-primary ep-link-p2k-btn" data-p2kid="${e(msg.id)}" data-tekst="${e(msg.tekst)}" data-newurl="${e(newUrl)}">Koppelen</button>
      <a href="${e(newUrl)}" target="_blank" class="ep-popup-btn">+ Nieuw item</a>
    </div>
  </div>`;
}

// ── Sidebar item click → focus on map ────────────────────────────────────────
$(document).on('click','#inc-list .ep-item',function(){
  const id = parseInt($(this).data('id'),10);
  activateInc(id);
  const inc = allInc.find(i=>i.id===id);
  if(inc&&inc.lat) map.setView([inc.lat,inc.lon],16);
});
$(document).on('click','#p2k-list .ep-item',function(){
  const id = $(this).data('p2kid');
  const msg = allP2k.find(m=>m.id===id);
  if(msg&&msg.lat) map.setView([msg.lat,msg.lon],17);
});

// ── Tabs ──────────────────────────────────────────────────────────────────────
$('.ep-tabs').on('click','.ep-tab',function(){
  $('.ep-tab').removeClass('active');
  $(this).addClass('active');
  const panel = $(this).data('panel');
  $('.ep-panel').removeClass('active');
  $('#'+panel).addClass('active');
});

// ── Search ────────────────────────────────────────────────────────────────────
$('#inc-search').on('input',function(){ incSearch=$(this).val().trim(); renderInc(); });
$('#p2k-search').on('input',function(){ p2kSearch=$(this).val().trim(); renderP2k(); });

// ── Filters ───────────────────────────────────────────────────────────────────
$('#ep-panel-incidents').on('click','.ep-filter',function(){
  $(this).siblings().removeClass('active'); $(this).addClass('active');
  incFilter=$(this).data('filter'); renderInc();
});
$('#p2k-filters').on('click','.ep-filter',function(){
  $(this).siblings().removeClass('active'); $(this).addClass('active');
  p2kFilter=$(this).data('type'); renderP2k();
});

// ── Bulk P2000 select & koppelen ──────────────────────────────────────────────
$(document).on('change','.ep-p2k-cb',function(){
  const selected = $('.ep-p2k-cb:checked');
  const count = selected.length;
  const $bar = $('#ep-p2k-bulk-bar');
  if(count > 0){
    // Populate post dropdown from allInc
    const $sel = $('#ep-p2k-bulk-post').empty().append('<option value="">— Koppel aan item —</option>');
    allInc.forEach(i => $sel.append(`<option value="${i.id}">${e(i.title)}</option>`));
    $('#ep-p2k-bulk-count').text(count);
    $bar.css('display','flex');
  } else {
    $bar.hide();
  }
});

$('#ep-p2k-bulk-cancel').on('click', function(){
  $('.ep-p2k-cb').prop('checked', false);
  $('#ep-p2k-bulk-bar').hide();
});

$('#ep-p2k-bulk-link').on('click', async function(){
  const postId = parseInt($('#ep-p2k-bulk-post').val(), 10);
  if(!postId){ alert('Selecteer een item om aan te koppelen.'); return; }
  const $btn = $(this).prop('disabled', true).text('Bezig…');
  const ids = $('.ep-p2k-cb:checked').map(function(){ return $(this).data('p2kid'); }).get();
  let linked = 0;
  for(const p2kId of ids){
    try{
      const r = await post('snd_editor_link_p2000', {post_id: postId, p2000_id: p2kId});
      if(r.success) linked++;
    } catch(e){}
  }
  $btn.prop('disabled', false).text('Koppelen');
  alert(`${linked} van ${ids.length} meldingen gekoppeld aan item.`);
  $('.ep-p2k-cb').prop('checked', false);
  $('#ep-p2k-bulk-bar').hide();
  await load(); // reload all data
});

// ── Link P2000 modal ──────────────────────────────────────────────────────────
$(document).on('click','.ep-link-p2k-btn',function(){
  linkP2kId  = this.dataset.p2kid;
  linkNewUrl = this.dataset.newurl;
  $('#link-tekst').text(this.dataset.tekst);
  $('#link-new-post').attr('href',linkNewUrl);
  $('#link-post-select').val('');
  $('#link-status').text('').attr('class','ep-status-msg');
  showModal('modal-link');
});
$('#link-confirm').on('click',async function(){
  const pid = parseInt($('#link-post-select').val(),10);
  if(!pid){ setStatus('link-status','Selecteer een bericht.','err'); return; }
  $(this).prop('disabled',true).text('…');
  try{
    const r=await post('snd_editor_link_p2000',{post_id:pid,p2000_id:linkP2kId});
    if(r.success){ setStatus('link-status','✓ Gekoppeld!','ok'); setTimeout(()=>{ closeModal('modal-link'); load(); },1000); }
    else setStatus('link-status',r.data?.message||'Fout.','err');
  }catch{ setStatus('link-status','Serverfout.','err'); }
  finally{ $(this).prop('disabled',false).text('Koppelen'); }
});

// ── Location modal ────────────────────────────────────────────────────────────
$(document).on('click','.ep-editloc-btn',function(){
  editPostId = parseInt(this.dataset.postid,10);
  const lat=parseFloat(this.dataset.lat)||51.56, lon=parseFloat(this.dataset.lon)||5.09;
  $('#loc-lat').val(lat.toFixed(6));
  $('#loc-lon').val(lon.toFixed(6));
  $('#loc-street').val(this.dataset.street||'');
  $('#loc-status').text('').attr('class','ep-status-msg');
  showModal('modal-loc');
  setTimeout(()=>initLocMap(lat,lon),150);
});
function initLocMap(lat,lon){
  if(locMap){ locMap.remove(); locMap=null; locMarker=null; }
  locMap = L.map('loc-map').setView([lat,lon],16);
  makeTile().addTo(locMap);
  locMarker = L.marker([lat,lon],{draggable:true}).addTo(locMap);
  locMap.on('click',ev=>setLocPoint(ev.latlng.lat,ev.latlng.lng));
  locMarker.on('dragend',ev=>{ const ll=ev.target.getLatLng(); setLocPoint(ll.lat,ll.lng); });
  setTimeout(()=>locMap.invalidateSize(),100);
}
async function setLocPoint(lat,lon){
  locMarker.setLatLng([lat,lon]);
  $('#loc-lat').val(lat.toFixed(6));
  $('#loc-lon').val(lon.toFixed(6));
  $('#loc-street').val('Zoeken…');
  try{
    const r=await $.post(AJAX,{action:'snd_reverse_geocode',nonce:<?php echo wp_json_encode(wp_create_nonce('snd_nonce')); ?>,lat,lon});
    $('#loc-street').val(r.success&&r.data.straat?r.data.straat:'');
  }catch{ $('#loc-street').val(''); }
}
$('#loc-search-btn').on('click',searchLocAddress);
$('#loc-search').on('keypress',ev=>{ if(ev.which===13){ev.preventDefault();searchLocAddress();} });
async function searchLocAddress(){
  const q=$('#loc-search').val().trim(); if(!q)return;
  $('#loc-search-btn').prop('disabled',true).text('…');
  try{
    const r=await $.post(AJAX,{action:'snd_geocode_address',nonce:<?php echo wp_json_encode(wp_create_nonce('snd_nonce')); ?>,address:q});
    if(r.success&&r.data.lat){ const lat=parseFloat(r.data.lat),lon=parseFloat(r.data.lon); locMap.setView([lat,lon],17); setLocPoint(lat,lon); }
    else alert('Niet gevonden.');
  }catch{ alert('Serverfout.'); }
  finally{ $('#loc-search-btn').prop('disabled',false).text('Zoek'); }
}
$('#loc-save').on('click',async function(){
  const lat=$('#loc-lat').val(),lon=$('#loc-lon').val(),street=$('#loc-street').val();
  $(this).prop('disabled',true).text('Opslaan…');
  try{
    const r=await post('snd_editor_update_location',{post_id:editPostId,lat,lon,street});
    if(r.success){ setStatus('loc-status','✓ Opgeslagen','ok'); setTimeout(()=>{ closeModal('modal-loc'); load(); if(activeIncId===editPostId) loadDetail(editPostId); },1000); }
    else setStatus('loc-status',r.data?.message||'Fout.','err');
  }catch{ setStatus('loc-status','Serverfout.','err'); }
  finally{ $(this).prop('disabled',false).text('Opslaan'); }
});

// ── Modal helpers ─────────────────────────────────────────────────────────────
function showModal(id){ $('#'+id).show(); }
function closeModal(id){ $('#'+id).hide(); }
$(document).on('click','.ep-modal-close,[data-modal]',function(){ closeModal($(this).data('modal')||$(this).closest('.ep-modal-backdrop').attr('id')); });
$(document).on('click','.ep-modal-backdrop',function(ev){ if($(ev.target).is('.ep-modal-backdrop')) closeModal($(ev.target).attr('id')); });

// ── CSV export incidenten ─────────────────────────────────────────────────────
$('#ep-export-csv').on('click', function() {
  if (!allInc.length) { alert('Geen incidenten geladen.'); return; }
  const BOM = '\uFEFF';
  const statusLabel = {onderweg:'Onderweg', ter_plaatse:'Ter plaatse', afgerond:'Afgerond'};
  const rows = [['ID','Titel','Datum','Locatie','Status','Live','Verstuurd naar','Foto\'s','P2000 ID']];
  allInc.forEach(function(i) {
    rows.push([
      i.id,
      '"' + (i.title||'').replace(/"/g,'""') + '"',
      i.date || '',
      '"' + (i.street||'').replace(/"/g,'""') + '"',
      statusLabel[i.status||''] || '',
      i.is_live ? 'Ja' : 'Nee',
      i.dispatch_count !== undefined ? i.dispatch_count : (i.dispatched ? '≥1' : '0'),
      i.photo_count !== undefined ? i.photo_count : '',
      i.p2000_id || '',
    ]);
  });
  const csv = BOM + rows.map(r => r.join(';')).join('\n');
  const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = 'incidenten-' + new Date().toISOString().slice(0,10) + '.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function setStatus(elId,msg,cls){ $('#'+elId).text(msg).attr('class','ep-status-msg '+(cls||'')); }
function e(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function post(action,data={}){ return $.post(AJAX,{action,nonce:NONCE,access_code:CODE,...data}); }

load();
})(jQuery);
</script>
</body>
</html>
