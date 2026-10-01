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
    if(fit)map.fitBounds(state.routeLayer.getBounds(),{padding:[45,45]});
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

el('simulateBtn').onclick=async()=>{
  const b=el('simulateBtn');b.disabled=true;b.textContent='Simuleren…';
  el('simStatus').textContent='Routes worden berekend en tegen jouw correctielaag getest…';
  try{
    const x=await api('/api/simulate',{method:'POST',body:JSON.stringify({count:Number(el('count').value),seed:Number(el('seed').value)})});
    el('simStatus').textContent='Batch '+x.batch_id+' klaar.';
    await refresh();
  }catch(e){
    toast(e.message,true);el('simStatus').textContent=e.message;
  }finally{
    b.disabled=false;b.textContent='▶ Simuleer WMO-ritten';
  }
};
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
