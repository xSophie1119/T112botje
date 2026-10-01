async function openScenario(id){
  try{
    if(state.routeEditMode)toggleRouteEdit(false);
    const x=await api('/api/scenarios/'+id);
    state.selected=x;state.altIndex=0;
    el('emptyState').classList.add('hidden');el('detailPanel').classList.remove('hidden');
    el('detailId').textContent='#'+x.id;
    el('detailTitle').textContent=x.origin_name+' → '+x.destination_name;
    el('detailDistance').textContent=km(x.distance_m);
    el('detailDuration').textContent=mins(x.duration_s);
    el('detailHits').textContent=x.correction_hits;
    el('detailScore').textContent=Math.round(x.score);
    el('detailCategory').textContent=x.trip_category==='hospital'
      ?'🏥 Ziekenhuis':(x.trip_category==='care'?'🏡 Zorginstelling':'📍 Overige bestemming');
    el('detailRing').textContent=(x.ring_rule||'—')
      +' · '+(x.origin_municipality||'?')+' → '+(x.destination_municipality||'?');
    el('detailTarget').textContent=x.destination_name||'—';
    el('detailStop').textContent=(x.destination_stop_name||'route-stoppunt')
      +' · '+Number(x.destination_lat||0).toFixed(6)+', '+Number(x.destination_lon||0).toFixed(6);
    el('detailStopGap').textContent=Math.round(Number(x.destination_target_to_stop_m||0))+' m';
    el('reviewNote').value=x.review_note||'';
    renderAlternatives();drawSelectedAlternative(0,true);renderScenarios();
  }catch(e){toast(e.message,true)}
}
function renderAlternatives(){
  const x=state.selected,alts=x?.alternatives||[];
  el('alternatives').innerHTML=alts.map((a,i)=>'<div class="alternative '+(i===state.altIndex?'active':'')+'" data-alt="'+i+'"><b>Route '+(i+1)+'</b> • '+km(a.distance_m)+' • '+mins(a.duration_s)+' • score '+Math.round(a.score)+(a.correction_hits?.length?'<br><span style="color:#fb923c">'+a.correction_hits.map(h=>esc(correctionLabel(h.type))).join(', ')+'</span>':'')+'</div>').join('');
  document.querySelectorAll('[data-alt]').forEach(n=>n.onclick=()=>{
    if(state.routeEditMode)toggleRouteEdit(false);
    state.altIndex=Number(n.dataset.alt);renderAlternatives();drawSelectedAlternative(state.altIndex,true)
  });
}
function clearRouteLayers(){
  state.altLayers.forEach(l=>l.remove());state.altLayers=[];
  state.endpointLayers.forEach(l=>l.remove());state.endpointLayers=[];
  if(state.routeLayer){state.routeLayer.remove();state.routeLayer=null}
}
function drawSelectedAlternative(index,fit=false){
  clearRouteLayers();
  const x=state.selected;if(!x)return;
  const alts=x.alternatives||[];
  alts.forEach((a,i)=>{
    if(i===index)return;
    const latlng=(a.geometry||[]).map(p=>[p[1],p[0]]);
    if(!latlng.length)return;
    const l=L.polyline(latlng,{color:'#64748b',weight:4,opacity:.35,dashArray:'8 8'}).addTo(map);
    state.altLayers.push(l);
  });
  const a=alts[index]||{geometry:x.geometry||[]};
  const latlng=(a.geometry||[]).map(p=>[p[1],p[0]]);
  if(latlng.length){
    state.routeLayer=L.polyline(latlng,{color:'#38bdf8',weight:7,opacity:.92,lineCap:'round'}).addTo(map);
    state.routeLayer.on('click',ev=>{
      if(state.routeEditMode)addRouteEditPoint(ev.latlng,true);
      else if(state.correctionMode)pickLocation(ev.latlng);
    });

    const targetLat=Number(x.destination_target_lat||0),targetLon=Number(x.destination_target_lon||0);
    const stopLat=Number(x.destination_lat||0),stopLon=Number(x.destination_lon||0);
    if(targetLat&&targetLon){
      const target=L.circleMarker([targetLat,targetLon],{
        radius:9,color:'#a855f7',fillColor:'#a855f7',fillOpacity:1,weight:3
      }).bindPopup('<b>Echte doellocatie</b><br>'+esc(x.destination_name||'')+
        '<br>'+targetLat.toFixed(6)+', '+targetLon.toFixed(6)).addTo(map);
      state.endpointLayers.push(target);
    }
    if(stopLat&&stopLon){
      const stop=L.circleMarker([stopLat,stopLon],{
        radius:9,color:'#22c55e',fillColor:'#22c55e',fillOpacity:1,weight:3
      }).bindPopup('<b>Werkelijk route-eindpunt</b><br>'+esc(x.destination_stop_name||'route-stoppunt')+
        '<br>'+Math.round(Number(x.destination_target_to_stop_m||0))+' m van doel').addTo(map);
      state.endpointLayers.push(stop);
    }
    if(targetLat&&targetLon&&stopLat&&stopLon){
      const connector=L.polyline([[targetLat,targetLon],[stopLat,stopLon]],{
        color:'#c084fc',weight:3,opacity:.85,dashArray:'4 7'
      }).addTo(map);
      state.endpointLayers.push(connector);
    }

    if(fit){
      const layers=[state.routeLayer,...state.endpointLayers];
      const group=L.featureGroup(layers);
      map.fitBounds(group.getBounds(),{padding:[45,45]});
    }
  }
}
function routeEditDivIcon(kind='via',moved=false){
  const cls=kind==='end'?'route-edit-end':'route-edit-dot';
  return L.divIcon({
    className:'routepilot-edit-icon',
    html:'<div class="'+cls+(moved?' moved':'')+'"></div>',
    iconSize:kind==='end'?[22,22]:[18,18],
    iconAnchor:kind==='end'?[11,11]:[9,9]
  });
}
function currentRouteEditGeometry(){
  const x=state.selected;if(!x)return[];
  const a=(x.alternatives||[])[state.altIndex]||{geometry:x.geometry||[]};
  return (a.geometry||[]).map(p=>L.latLng(p[1],p[0]));
}
function clearRouteEditorObjects(){
  const e=state.routeEdit;
  if(e.timer){clearTimeout(e.timer);e.timer=null}
  e.markers.forEach(m=>m.remove());e.markers=[];
  if(e.endpointMarker){e.endpointMarker.remove();e.endpointMarker=null}
  if(e.previewLayer){e.previewLayer.remove();e.previewLayer=null}
  if(e.originalLayer){e.originalLayer.remove();e.originalLayer=null}
  e.originalGeometry=[];e.previewGeometry=[];e.originalEndpoint=null;e.seq++;
}
function routeEditMovedVia(){
  return state.routeEdit.markers.filter(m=>{
    if(m._rpForceMoved)return true;
    const now=m.getLatLng(),orig=m._rpOriginal;
    return orig&&distanceM(now,orig)>12;
  });
}
function routeEditEndpointMoved(){
  const m=state.routeEdit.endpointMarker,orig=state.routeEdit.originalEndpoint;
  return !!(m&&orig&&distanceM(m.getLatLng(),orig)>5);
}
function updateRouteEditStateText(extra=''){
  const via=routeEditMovedVia().length;
  const end=routeEditEndpointMoved();
  const parts=[];
  if(via)parts.push(via+' versleept routepunt'+(via===1?'':'en'));
  if(end)parts.push('eindpunt verplaatst');
  if(!parts.length)parts.push('nog geen wijzigingen');
  el('routeEditStatus').textContent=parts.join(' • ')+(extra?' • '+extra:'');
  el('saveRouteEditBtn').disabled=!(via||end);
  el('resetRouteEditBtn').disabled=false;
}
function markerMovedStyle(marker,kind){
  const orig=kind==='end'?state.routeEdit.originalEndpoint:marker._rpOriginal;
  const threshold=kind==='end'?5:12;
  marker.setIcon(routeEditDivIcon(kind,orig&&distanceM(marker.getLatLng(),orig)>threshold));
}
function installRouteEditMarker(marker,kind='via'){
  marker.on('drag',()=>{
    markerMovedStyle(marker,kind);
    updateRouteEditStateText('route opnieuw berekenen…');
    scheduleRouteEditPreview();
  });
  marker.on('dragend',()=>{
    markerMovedStyle(marker,kind);
    updateRouteEditStateText('route opnieuw berekenen…');
    refreshRouteEditPreview();
  });
  if(kind==='via'){
    marker.on('contextmenu',()=>{
      if(!state.routeEditMode)return;
      const idx=state.routeEdit.markers.indexOf(marker);
      if(idx>=0){state.routeEdit.markers.splice(idx,1);marker.remove();refreshRouteEditPreview()}
    });
  }
}
function addRouteEditPoint(latlng,userAdded=false){
  if(!state.routeEditMode||state.routeEdit.markers.length>=8){
    if(state.routeEdit.markers.length>=8)toast('Maximaal 8 routepunten per bewerking.',true);
    return;
  }
  const marker=L.marker(latlng,{
    draggable:true,
    icon:routeEditDivIcon('via',userAdded),
    zIndexOffset:800
  }).addTo(map);
  marker._rpOriginal=userAdded
    ?L.latLng(latlng.lat+0.0005,latlng.lng+0.0005)
    :L.latLng(latlng.lat,latlng.lng);
  if(userAdded)marker._rpForceMoved=true;
  installRouteEditMarker(marker,'via');
  state.routeEdit.markers.push(marker);
  if(userAdded){
    updateRouteEditStateText('extra routepunt toegevoegd');
    refreshRouteEditPreview();
  }
}
function sampleRouteEditPoints(geometry){
  if(!geometry.length)return[];
  const count=geometry.length<20?2:4;
  const out=[];
  for(let i=1;i<=count;i++){
    const idx=Math.max(1,Math.min(geometry.length-2,Math.round(i*(geometry.length-1)/(count+1))));
    out.push(geometry[idx]);
  }
  return out;
}
function scheduleRouteEditPreview(){
  const e=state.routeEdit;
  if(e.timer)clearTimeout(e.timer);
  e.timer=setTimeout(()=>refreshRouteEditPreview(),320);
}
async function refreshRouteEditPreview(){
  if(!state.routeEditMode||!state.selected)return;
  const e=state.routeEdit,seq=++e.seq;
  if(e.timer){clearTimeout(e.timer);e.timer=null}
  const x=state.selected;
  const points=[
    [Number(x.origin_lat),Number(x.origin_lon)],
    ...e.markers.map(m=>{const p=m.getLatLng();return[p.lat,p.lng]}),
    (()=>{const p=e.endpointMarker.getLatLng();return[p.lat,p.lng]})()
  ];
  try{
    const r=await api('/api/route-edit/preview',{
      method:'POST',body:JSON.stringify({points})
    });
    if(!state.routeEditMode||seq!==e.seq)return;
    const latlng=(r.geometry||[]).map(p=>[p[1],p[0]]);
    if(e.previewLayer)e.previewLayer.remove();
    e.previewLayer=L.polyline(latlng,{
      color:'#a855f7',weight:8,opacity:.92,lineCap:'round'
    }).addTo(map);
    e.previewLayer.on('click',ev=>addRouteEditPoint(ev.latlng,true));
    e.previewGeometry=r.geometry||[];
    updateRouteEditStateText('preview '+km(r.distance_m)+' • '+mins(r.duration_s));
  }catch(err){
    if(!state.routeEditMode||seq!==e.seq)return;
    updateRouteEditStateText('previewfout: '+err.message);
  }
}
function toggleRouteEdit(on){
  const enable=on??!state.routeEditMode;
  if(enable){
    if(!state.selected){toast('Open eerst een trainingsrit.',true);return}
    if(state.correctionMode)toggleCorrectionMode(false);
    clearRouteEditorObjects();
    state.routeEditMode=true;
    el('routeEditBtn').classList.add('editing');
    const geometry=currentRouteEditGeometry();
    if(geometry.length<2){
      state.routeEditMode=false;el('routeEditBtn').classList.remove('editing');
      toast('Deze route heeft geen bruikbare geometrie.',true);return;
    }
    state.routeEdit.originalGeometry=geometry.map(p=>[p.lat,p.lng]);
    if(state.routeLayer)state.routeLayer.setStyle({opacity:.24,weight:5,dashArray:'9 8'});
    state.routeEdit.originalLayer=L.polyline(state.routeEdit.originalGeometry,{
      color:'#38bdf8',weight:4,opacity:.55,dashArray:'8 8'
    }).addTo(map);

    sampleRouteEditPoints(geometry).forEach(p=>addRouteEditPoint(p,false));

    const x=state.selected;
    const endpoint=L.latLng(Number(x.destination_lat),Number(x.destination_lon));
    state.routeEdit.originalEndpoint=L.latLng(endpoint.lat,endpoint.lng);
    const endMarker=L.marker(endpoint,{
      draggable:true,icon:routeEditDivIcon('end',false),zIndexOffset:1000
    }).addTo(map);
    endMarker.bindTooltip('Sleep mij naar het gewenste WMO-stoppunt',{permanent:false});
    installRouteEditMarker(endMarker,'end');
    state.routeEdit.endpointMarker=endMarker;

    el('routeEditStatus').textContent='Sleep witte punten, klik op de route voor extra punten, of sleep het groene eindpunt.';
    el('resetRouteEditBtn').disabled=false;
    el('saveRouteEditBtn').disabled=true;
    refreshRouteEditPreview();
  }else{
    state.routeEditMode=false;
    el('routeEditBtn').classList.remove('editing');
    clearRouteEditorObjects();
    el('routeEditStatus').textContent='Niet actief.';
    el('resetRouteEditBtn').disabled=true;
    el('saveRouteEditBtn').disabled=true;
    if(state.routeLayer)state.routeLayer.setStyle({color:'#38bdf8',weight:7,opacity:.92,dashArray:null});
  }
}
function resetRouteEdit(){
  if(!state.routeEditMode)return;
  const keep=[];
  state.routeEdit.markers.forEach(m=>{
    if(m._rpForceMoved){m.remove();return}
    m.setLatLng(m._rpOriginal);markerMovedStyle(m,'via');keep.push(m);
  });
  state.routeEdit.markers=keep;
  if(state.routeEdit.endpointMarker&&state.routeEdit.originalEndpoint){
    state.routeEdit.endpointMarker.setLatLng(state.routeEdit.originalEndpoint);
    markerMovedStyle(state.routeEdit.endpointMarker,'end');
  }
  updateRouteEditStateText();
  refreshRouteEditPreview();
}
async function saveRouteEdit(){
  if(!state.routeEditMode||!state.selected)return;
  const moved=routeEditMovedVia();
  const endMoved=routeEditEndpointMoved();
  if(!moved.length&&!endMoved){toast('Je hebt nog niets versleept.',true);return}
  const body={
    scenario_id:state.selected.id,
    moved_via:moved.map(m=>{const p=m.getLatLng();return{lat:p.lat,lon:p.lng}}),
    endpoint:endMoved?(()=>{
      const p=state.routeEdit.endpointMarker.getLatLng();
      return{lat:p.lat,lon:p.lng}
    })():null
  };
  try{
    const r=await api('/api/route-edit/save',{method:'POST',body:JSON.stringify(body)});
    const n=(r.created||[]).length;
    toggleRouteEdit(false);
    await refresh();
    toast(n+' route-editorcorrectie'+(n===1?'':'s')+' opgeslagen en klaar voor app-sync.');
  }catch(err){toast(err.message,true)}
}

function pickLocation(latlng){
  state.picked={lat:latlng.lat,lon:latlng.lng};
  el('pickedLocation').textContent=latlng.lat.toFixed(6)+', '+latlng.lng.toFixed(6);
  el('saveCorrection').disabled=false;
  el('correctionComposer').classList.remove('hidden');
}
function toggleCorrectionMode(on){
  state.correctionMode=on??!state.correctionMode;
  el('correctionModeBtn').classList.toggle('active',state.correctionMode);
  el('correctionComposer').classList.toggle('hidden',!state.correctionMode);
  if(!state.correctionMode){
    state.picked=null;el('saveCorrection').disabled=true;
    el('pickedLocation').textContent='Klik een plek op de kaart…';
  }
}
map.on('click',e=>{if(state.correctionMode&&!state.routeEditMode)pickLocation(e.latlng)});

const sleep=ms=>new Promise(r=>setTimeout(r,ms));
let activeBatchId=null;

function paintBatch(x){
  const requested=Math.max(1,Number(x.requested_count||1));
  const success=Number(x.success_count||0);
  const errors=Number(x.error_count||0);
  const pct=Math.max(0,Math.min(100,Number(x.progress_pct??Math.round(success/requested*100))));
  el('batchProgressWrap').classList.remove('hidden');
  el('batchStage').textContent=x.stage||x.status||'Bezig';
  el('batchPct').textContent=pct+'%';
  el('batchProgressBar').style.width=pct+'%';
  el('batchCounts').textContent=success+'/'+requested+' geldig • '+errors+' afgekeurd • '+Number(x.completed_count||0)+' pogingen';
  const mix=x.mix||{},rings=x.rings||{};
  const mixParts=[
    '🏥 '+Number(mix.hospital||0),
    '🏡 zorg '+Number(mix.care||0),
    '📍 overig '+Number(mix.general||0),
    'unieke start '+Number(x.unique_origins||0),
    'unieke eind '+Number(x.unique_destinations||0)
  ];
  const ringParts=[
    'B→B '+Number(rings['inside->inside']||0),
    'B→U '+Number(rings['inside->outside']||0),
    'U→B '+Number(rings['outside->inside']||0)
  ];
  el('batchMix').textContent=mixParts.join(' • ')+' | '+ringParts.join(' • ');
  el('simStatus').textContent=x.message||'Batch '+x.batch_id+' wordt uitgevoerd…';
}

async function pollBatch(batchId){
  activeBatchId=batchId;
  const b=el('simulateBtn');
  b.disabled=true;b.textContent='⏳ Simulator draait…';
  let lastSuccess=-1;
  try{
    while(true){
      const x=await api('/api/batches/'+encodeURIComponent(batchId));
      paintBatch(x);
      const success=Number(x.success_count||0);
      if(success!==lastSuccess && (success===1 || success%4===0)){
        lastSuccess=success;
        await refresh();
      }
      if(['completed','partial','failed'].includes(x.status)){
        await refresh();
        if(x.status==='completed')toast('Trainingsbatch klaar: '+success+' geldige ritten.');
        else toast(x.message||'Batch niet volledig afgerond.',x.status==='failed');
        return x;
      }
      await sleep(900);
    }
  }finally{
    activeBatchId=null;
    b.disabled=false;b.textContent='▶ Simuleer WMO-ritten';
  }
}

el('simulateBtn').onclick=async()=>{
  const b=el('simulateBtn');
  b.disabled=true;b.textContent='Batch starten…';
  el('batchProgressWrap').classList.remove('hidden');
  el('batchProgressBar').style.width='0%';
  el('batchPct').textContent='0%';
  el('batchStage').textContent='Starten';
  el('simStatus').textContent='De server maakt een batch aan…';
  try{
    const x=await api('/api/simulate',{
      method:'POST',
      body:JSON.stringify({
        count:Number(el('count').value),
        seed:el('seed').value.trim()==='' ? Date.now() : Number(el('seed').value)
      })
    });
    await pollBatch(x.batch_id);
  }catch(e){
    toast(e.message,true);
    el('simStatus').textContent=e.message;
    b.disabled=false;b.textContent='▶ Simuleer WMO-ritten';
  }
};

el('sourceCheckBtn').onclick=async()=>{
  const b=el('sourceCheckBtn');
  b.disabled=true;b.textContent='🩺 Controleren…';
  try{
    const x=await api('/api/source-status');
    const box=el('sourceStatus');
    box.classList.remove('hidden');
    box.innerHTML=[
      '<div class="source-pill '+(x.pdok?.ok?'ok':'bad')+'"><b>PDOK/BAG</b><br>'+esc(x.pdok?.detail||'Geen antwoord')+'</div>',
      '<div class="source-pill '+(x.osrm?.ok?'ok':'bad')+'"><b>OSRM</b><br>'+esc(x.osrm?.detail||'Geen antwoord')+'</div>',
      '<div class="source-pill ok"><b>Lokale cache</b><br>'+Number(x.cache?.locations||0)+' bestemmingen • '+Number(x.cache?.routes||0)+' routes</div>'
    ].join('');
  }catch(e){
    toast(e.message,true);
  }finally{
    b.disabled=false;b.textContent='🩺 Controleer databronnen';
  }
};

async function resumeLatestBatch(){
  if(activeBatchId)return;
  try{
    const x=await api('/api/batches');
    const latest=(x.batches||[])[0];
    if(latest && ['queued','running'].includes(latest.status)){
      paintBatch(latest);
      pollBatch(latest.batch_id);
    }else if(latest){
      paintBatch(latest);
    }
  }catch(e){}
}
el('refreshBtn').onclick=refresh;
el('filter').onchange=renderScenarios;
el('fitBtn').onclick=()=>{if(state.routeLayer)map.fitBounds(state.routeLayer.getBounds(),{padding:[45,45]})};
el('correctionModeBtn').onclick=()=>toggleCorrectionMode();
el('routeEditBtn').onclick=()=>toggleRouteEdit();
el('resetRouteEditBtn').onclick=resetRouteEdit;
el('saveRouteEditBtn').onclick=saveRouteEdit;
el('cancelCorrection').onclick=()=>toggleCorrectionMode(false);
el('showCorrectionsBtn').onclick=()=>{
  state.showCorrections=!state.showCorrections;
  el('showCorrectionsBtn').classList.toggle('active',state.showCorrections);
  drawCorrections();
};
el('closeDetail').onclick=()=>{
  if(state.routeEditMode)toggleRouteEdit(false);
  el('detailPanel').classList.add('hidden');
};

async function saveReview(status){
  if(!state.selected)return;
  await api('/api/scenarios/'+state.selected.id+'/review',{
    method:'POST',body:JSON.stringify({status,note:el('reviewNote').value})
  });
  state.selected.status=status;state.selected.review_note=el('reviewNote').value;
  await refresh();
}
el('acceptBtn').onclick=()=>saveReview('accepted');
el('rejectBtn').onclick=()=>saveReview('rejected');

el('saveCorrection').onclick=async()=>{
  if(!state.picked)return;
  try{
    await api('/api/corrections',{
      method:'POST',
      body:JSON.stringify({
        type:el('correctionType').value,
        lat:state.picked.lat,lon:state.picked.lon,
        radius_m:Number(el('radius').value),
        strength:Number(el('strength').value),
        note:el('correctionNote').value
      })
    });
    el('correctionNote').value='';
    toggleCorrectionMode(false);
    await refresh();
    toast('Correctie opgeslagen. Nieuwe simulaties gebruiken hem direct.');
  }catch(e){toast(e.message,true)}
};
el('tokenBtn').onclick=()=>{
  const t=prompt('RoutePilot portal token',state.token);
  if(t!==null){state.token=t.trim();localStorage.setItem('routepilot_token',state.token);refresh()}
};
el('exportBtn').onclick=async()=>{
  try{
    const x=await api('/api/corrections/export');
    const blob=new Blob([JSON.stringify(x,null,2)],{type:'application/json'});
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);a.download='routepilot-corrections.json';a.click();
    URL.revokeObjectURL(a.href);
  }catch(e){toast(e.message,true)}
};
refresh();
resumeLatestBatch();
