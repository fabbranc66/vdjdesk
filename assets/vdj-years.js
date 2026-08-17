$$('.sync-vdj-years').forEach(button=>window.setGlobalActionIcon?setGlobalActionIcon(button,krIcon.sync,'Aggiorna anno e genere da VirtualDJ a KR Desk'):button.textContent='Anno + genere VDJ -> KR');

document.addEventListener('click',async event=>{
  const button=event.target.closest('.sync-vdj-years');if(!button)return;
  const inLibrary=$('#view-library')?.classList.contains('active');
  const targetTracks=inLibrary?await collectCurrentLibrary():state.tracks;
  const ids=targetTracks.map(track=>Number(track.id)).filter(id=>id>0);if(!ids.length){toast('Nessun brano nella lista filtrata');return}
  if(!window.confirm(`Importare da VirtualDJ anno e microgenere di ${ids.length} brani filtrati?`))return;
  const original=button.innerHTML;button.disabled=true;
  if(window.setGlobalActionIcon)setGlobalActionIcon(button,krIcon.sync,'Lettura tag VirtualDJ','in corso');else button.textContent='Lettura tag VDJ...';
  krProgress.start('Aggiorna anno e genere da VDJ',ids.length,'Lettura tag VirtualDJ');
  try{
    const result=await post('bulk-vdj-metadata',{ids});const metadata=new Map(result.items.map(item=>[Number(item.id),item]));
    let scanned=0;
    for(const track of targetTracks){if(!ids.includes(Number(track.id)))continue;scanned++;krProgress.update('Aggiorna anno e genere da VDJ',scanned,ids.length,`${track.artist||''} - ${track.title||''}`);const item=metadata.get(Number(track.id));if(!item)continue;if(item.year)track.year=Number(item.year);if(item.genre)track.genre=item.genre}
    if(inLibrary){await loadTaxonomyOptions();await loadTracks(true,false)}else renderTracks();
    krProgress.done('Aggiornamento VDJ completato',`${result.updated} aggiornati${result.missing?` - ${result.missing} senza anno/genere`:''}`);toast(`${result.updated} brani aggiornati da VDJ${result.missing?` - ${result.missing} senza anno/genere`:''}`);
  }catch(error){krProgress.fail('Aggiornamento VDJ fallito',error.message);toast(error.message)}finally{button.disabled=false;if(window.setGlobalActionIcon)setGlobalActionIcon(button,krIcon.sync,'Aggiorna anno e genere da VirtualDJ a KR Desk');else button.innerHTML=original}
});
