let bulkTagTrackIds=[];

function openBulkTagDialog(){
  bulkTagTrackIds=state.tracks.map(track=>Number(track.id)).filter(id=>id>0);
  if(!bulkTagTrackIds.length){toast('Nessun brano nella lista filtrata visibile');return}
  $('#bulk-tag-count').textContent=`${bulkTagTrackIds.length} brani selezionati`;
  $('#bulk-tag-picker').innerHTML=(state.bootstrap?.tags||[]).map(tag=>`<button type="button" data-tag="${escapeHtml(tag)}">${escapeHtml(tag)}</button>`).join('');
  $('#bulk-tag-dialog').showModal();
}

document.addEventListener('click',event=>{const open=event.target.closest('.open-bulk-tags');if(open){openBulkTagDialog();return}const tag=event.target.closest('#bulk-tag-picker [data-tag]');if(!tag)return;if(!tag.classList.contains('tag-add')&&!tag.classList.contains('tag-remove'))tag.classList.add('tag-add');else if(tag.classList.contains('tag-add')){tag.classList.remove('tag-add');tag.classList.add('tag-remove')}else tag.classList.remove('tag-remove')});

$('#apply-bulk-tags').addEventListener('click',async event=>{
  const addTags=$$('#bulk-tag-picker .tag-add').map(button=>button.dataset.tag),removeTags=$$('#bulk-tag-picker .tag-remove').map(button=>button.dataset.tag);if(!addTags.length&&!removeTags.length){toast('Seleziona almeno un tag da aggiungere o rimuovere');return}
  const button=event.currentTarget,summary=[addTags.length?`aggiungi ${addTags.join(', ')}`:'',removeTags.length?`rimuovi ${removeTags.join(', ')}`:''].filter(Boolean).join(' · ');
  if(!window.confirm(`Confermi su ${bulkTagTrackIds.length} brani visibili?\n\n${summary}`))return;
  button.disabled=true;button.textContent='Aggiornamento tag…';
  try{let updated=0;if(addTags.length){krProgress.update('Tag globale',0,bulkTagTrackIds.length,'Aggiunta tag');const result=await post('bulk-track-tags',{ids:bulkTagTrackIds,tags:addTags,mode:'add'});updated=Math.max(updated,Number(result.updated))}if(removeTags.length){krProgress.update('Tag globale',Math.floor(bulkTagTrackIds.length/2),bulkTagTrackIds.length,'Rimozione tag');const result=await post('bulk-track-tags',{ids:bulkTagTrackIds,tags:removeTags,mode:'remove'});updated=Math.max(updated,Number(result.updated))}let scanned=0;for(const track of state.tracks){if(!bulkTagTrackIds.includes(Number(track.id)))continue;scanned++;krProgress.update('Tag globale',scanned,bulkTagTrackIds.length,`${track.artist||''} - ${track.title||''}`);const current=Array.isArray(track.tags)?track.tags:[];track.tags=[...new Set([...current,...addTags])].filter(tag=>!removeTags.includes(tag))}$('#bulk-tag-dialog').close();renderTracks();krProgress.done('Tag globale completato',`${updated} brani aggiornati`);toast(`${updated} brani aggiornati`)}catch(error){krProgress.fail('Tag globale fallito',error.message);toast(error.message)}finally{button.disabled=false;button.textContent='Applica ai brani visibili'}
});
