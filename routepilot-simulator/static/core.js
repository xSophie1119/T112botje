const state={scenarios:[],corrections:[],driverTrips:[],selected:null,selectedDriver:null,altIndex:0,routeLayer:null,altLayers:[],correctionLayer:null,picked:null,correctionMode:false,showCorrections:true,token:localStorage.getItem('routepilot_token')||''};
const el=id=>document.getElementById(id);
const map=L.map('map',{zoomControl:true}).setView([51.5555,5.0913],12.5);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);

function headers(json=true){
  const h={}; if(json)h['Content-Type']='application/json';
  if(state.token)h['X-RoutePilot-Token']=state.token;
  return h;
}
async function api(url,opts={}){
  opts.headers={...headers(opts.body!==undefined),...(opts.headers||{})};
  const r=await fetch(url,opts);
  if(r.status===401)throw new Error('Token vereist of onjuist. Klik bovenaan op Token.');
  if(!r.ok){const x=await r.json().catch(()=>({}));throw new Error(x.error||('HTTP '+r.status));}
  return r.json();
}
function km(m){return(m/1000).toFixed(1)+' km'}
function mins(s){const m=Math.round(s/60);return m<60?m+' min':Math.floor(m/60)+'u '+(m%60)+'m'}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function correctionLabel(t){const m={avoid:'Vermijden',prefer:'Prefereren',road_closed:'Afgesloten',too_narrow:'Te smal',bus_trap:'Bussluis',height_block:'Hoogtebeperking',entrance:'Ingang',good_stop:'Goed stoppunt',bad_stop:'Slecht stoppunt',ignore_door_side:'Deurzijde negeren',bus_lane_allowed:'Busbaan toegestaan',bus_lane_forbidden:'Busbaan verboden',turning_ok:'Keerruimte OK',lift_ok:'Lift OK',lift_bad:'Lift slecht'};return m[t]||t}
function correctionColor(t){
  if(['prefer','good_stop','entrance','bus_lane_allowed','turning_ok','lift_ok'].includes(t))return'#4ade80';
  if(['avoid','road_closed','too_narrow','bus_trap','height_block','bad_stop','bus_lane_forbidden','lift_bad'].includes(t))return'#fb7185';
  return'#38bdf8';
}
function toast(msg,bad=false){
  const n=document.createElement('div');n.textContent=msg;
  n.style.cssText='position:fixed;right:18px;bottom:18px;z-index:5000;padding:11px 14px;border-radius:12px;background:'+(bad?'#7f1d1d':'#0c4a6e')+';color:white;box-shadow:0 15px 40px #0008;font-weight:700;font-size:12px';
  document.body.appendChild(n);setTimeout(()=>n.remove(),4000);
}
async function refresh(){
  try{
    const [s,c,d,stats]=await Promise.all([api('/api/scenarios?limit=300'),api('/api/corrections'),api('/api/driver-trips?limit=60'),api('/api/stats')]);
    state.scenarios=s.scenarios;state.corrections=c.corrections;state.driverTrips=d.driver_trips||[];
    renderScenarios();renderDriverTrips();renderCorrections();renderStats(stats);drawCorrections();
  }catch(e){toast(e.message,true)}
}
function renderStats(x){
  el('statTotal').textContent=x.total;el('statAccepted').textContent=x.accepted;
  el('statRejected').textContent=x.rejected;el('statCorrections').textContent=x.corrections;
  el('statDriverTrips').textContent=x.driver_trips||0;
}
function renderDriverTrips(){
  const rows=state.driverTrips||[];
  el('driverTripList').innerHTML=rows.slice(0,20).map(x=>{
    const delta=(Number(x.actual_distance_m||0)-Number(x.planned_distance_m||0));
    return '<div class="scenario '+(state.selectedDriver&&state.selectedDriver.id===x.id?'selected':'')+'" data-driver="'+x.id+'">'
      +'<div class="scenario-top"><span>'+esc(x.destination||'WMO-rit')+'</span><span>ECHT</span></div>'
      +'<div class="scenario-meta"><span>'+km(x.actual_distance_m||0)+'</span><span>'+(delta>=0?'+':'')+km(delta)+'</span><span>'+Number(x.reroutes||0)+' reroutes</span></div></div>';
  }).join('')||'<div class="hint">Nog geen echte ritten ontvangen.</div>';
  document.querySelectorAll('[data-driver]').forEach(n=>n.onclick=()=>openDriverTrip(Number(n.dataset.driver)));
}
function openDriverTrip(id){
  const x=(state.driverTrips||[]).find(t=>Number(t.id)===Number(id));if(!x)return;
  state.selectedDriver=x;state.selected=null;renderDriverTrips();renderScenarios();
  clearRouteLayers();
  const planned=(x.planned_points||[]).map(p=>[p[0],p[1]]);
  const actual=(x.actual_points||[]).map(p=>[p[0],p[1]]);
  if(planned.length){
    const l=L.polyline(planned,{color:'#38bdf8',weight:6,opacity:.9,dashArray:'10 7'}).addTo(map);
    state.altLayers.push(l);
  }
  if(actual.length){
    state.routeLayer=L.polyline(actual,{color:'#fb923c',weight:7,opacity:.95,lineCap:'round'}).addTo(map);
    state.routeLayer.on('click',ev=>{if(state.correctionMode)pickLocation(ev.latlng)});
  }
  const layers=[...state.altLayers,...(state.routeLayer?[state.routeLayer]:[])];
  if(layers.length){
    const group=L.featureGroup(layers);map.fitBounds(group.getBounds(),{padding:[45,45]});
  }
  el('emptyState').classList.add('hidden');
  el('detailPanel').classList.add('hidden');
  toast('Blauw = gepland • oranje = werkelijk gereden');
}
function renderScenarios(){
  const f=el('filter').value;
  const list=state.scenarios.filter(x=>f==='all'||x.status===f);
  el('scenarioList').innerHTML=list.map(x=>'<div class="scenario '+(state.selected&&state.selected.id===x.id?'selected':'')+'" data-id="'+x.id+'"><div class="scenario-top"><span>'+esc(x.origin_name)+' → '+esc(x.destination_name)+'</span><span class="status-dot '+esc(x.status)+'"></span></div><div class="scenario-meta"><span>'+km(x.distance_m)+'</span><span>'+mins(x.duration_s)+'</span><span>'+x.correction_hits+' correcties</span></div>'+(x.error?'<div class="scenario-meta">'+esc(x.error)+'</div>':'')+'</div>').join('')||'<div class="hint">Nog geen simulaties.</div>';
  document.querySelectorAll('.scenario').forEach(n=>n.onclick=()=>openScenario(Number(n.dataset.id)));
}
function renderCorrections(){
  el('correctionList').innerHTML=state.corrections.map(c=>'<div class="correction"><div class="correction-head"><span style="color:'+correctionColor(c.type)+'">'+esc(correctionLabel(c.type))+'</span><button class="tiny-danger" data-delete="'+c.id+'">verwijder</button></div><p>'+esc(c.note||'Geen notitie')+' • radius '+Math.round(c.radius_m)+' m • sterkte '+c.strength+'</p></div>').join('')||'<div class="hint">Nog geen correcties.</div>';
  document.querySelectorAll('[data-delete]').forEach(b=>b.onclick=async()=>{if(!confirm('Correctie uitschakelen?'))return;await api('/api/corrections/'+b.dataset.delete,{method:'DELETE'});await refresh()});
}
function drawCorrections(){
  if(state.correctionLayer)state.correctionLayer.remove();state.correctionLayer=L.layerGroup();
  if(state.showCorrections){
    state.corrections.forEach(c=>{
      const color=correctionColor(c.type);
      L.circle([c.lat,c.lon],{radius:c.radius_m,color,weight:2,fillColor:color,fillOpacity:.12}).bindPopup('<b>'+esc(correctionLabel(c.type))+'</b><br>'+esc(c.note||'')).addTo(state.correctionLayer);
      L.circleMarker([c.lat,c.lon],{radius:5,color,fillColor:color,fillOpacity:1,weight:2}).addTo(state.correctionLayer);
    });
    state.correctionLayer.addTo(map);
  }
}
