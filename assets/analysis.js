async function loadLibraryAnalysis(){
  const body=$('#vdj-genre-stats');
  if(!body)return;
  body.innerHTML='<tr><td colspan="2">Caricamento…</td></tr>';
  try{
    const data=await api('vdj-genre-stats');
    $('#vdj-genre-summary').textContent=`${data.total_genres} generi · ${Number(data.total_tracks).toLocaleString('it-IT')} brani classificati`;
    body.innerHTML=data.items.map(item=>`<tr><td>${escapeHtml(item.genre)}</td><td>${Number(item.total).toLocaleString('it-IT')}</td></tr>`).join('')||'<tr><td colspan="2">Nessun genere presente</td></tr>';
  }catch(error){
    body.innerHTML=`<tr><td colspan="2">${escapeHtml(error.message)}</td></tr>`;
  }
}

async function runStandardTest(){
  const out=$('#standard-test-output');
  if(!out)return;
  out.className='empty-state';
  out.textContent='Test in corso…';
  const params=new URLSearchParams({
    genres:$('#standard-test-genres')?.value||'',
    release_date:$('#standard-test-release')?.value||'',
    popularity:$('#standard-test-popularity')?.value||'',
    bpm:$('#standard-test-bpm')?.value||''
  });
  try{
    const data=await api(`library-standard-test&${params.toString()}`);
    const c=data.classification;
    out.className='standard-test-result';
    out.innerHTML=`<div><b>Macro-area</b><strong>${escapeHtml(c.macro_area)}</strong></div><div><b>Genere</b><strong>${escapeHtml(c.main_genre)}</strong></div><div><b>Regola</b><strong>${escapeHtml(c.mapping_rule||'nessuna')}</strong></div><div><b>Confidenza</b><strong>${escapeHtml(c.mapping_confidence)}</strong></div><div><b>Popularity</b><strong>${escapeHtml(c.popularity_class)}</strong></div><div><b>Epoca</b><strong>${escapeHtml(c.era)}</strong></div><div><b>BPM</b><strong>${escapeHtml(c.bpm_class)}</strong></div><p>${escapeHtml(c.notes||'')}</p>`;
  }catch(error){
    out.className='empty-state';
    out.textContent=error.message;
  }
}

document.addEventListener('click',event=>{
  if(event.target?.id==='standard-test-run')runStandardTest();
  if(!event.target.closest('[data-view="analysis"]'))return;
  $('#view-title').textContent='Analisi libreria';
  loadLibraryAnalysis();
});

if(location.hash==='#analysis'){
  setTimeout(loadLibraryAnalysis,500);
  setTimeout(()=>$('#view-title').textContent='Analisi libreria',100);
}
