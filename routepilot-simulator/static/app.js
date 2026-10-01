async function openScenario(id){
  try{
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
  document.querySelectorAll('[data-alt]').forEach(n=>n.onclick=()=>{state.altIndex=Number(n.dataset.alt);renderAlternatives();drawSelectedAlternative(state.altIndex,true)});
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
    state.routeLayer.on('click',ev=>{if(state.correctionMode)pickLocation(ev.latlng)});

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
map.on('click',e=>{if(state.correctionMode)pickLocation(e.latlng)});

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
el('cancelCorrection').onclick=()=>toggleCorrectionMode(false);
el('showCorrectionsBtn').onclick=()=>{
  state.showCorrections=!state.showCorrections;
  el('showCorrectionsBtn').classList.toggle('active',state.showCorrections);
  drawCorrections();
};
el('closeDetail').onclick=()=>el('detailPanel').classList.add('hidden');

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
