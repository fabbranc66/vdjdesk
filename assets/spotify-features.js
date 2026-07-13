const krIcon={
  spotify:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M7 10c3-1 7-1 10 1"/><path d="M8 13c2.5-.7 5.5-.6 8 .8"/><path d="M9 16c2-.4 4-.3 6 .6"/></svg>',
  download:'<svg viewBox="0 0 24 24"><path d="M12 4v10"/><path d="m8 10 4 4 4-4"/><path d="M5 20h14"/></svg>',
  align:'<svg viewBox="0 0 24 24"><path d="M5 19 11 5h2l6 14"/><path d="M8 14h8"/></svg>',
  metrics:'<svg viewBox="0 0 24 24"><path d="M5 19V9"/><path d="M12 19V5"/><path d="M19 19v-7"/></svg>',
  move:'<svg viewBox="0 0 24 24"><path d="M4 7h7l2 3h7v9H4z"/><path d="m14 14 3 3 3-3"/></svg>',
  trash:'<svg viewBox="0 0 24 24"><path d="M5 7h14"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M8 7l1-3h6l1 3"/><path d="M7 7l1 13h8l1-13"/></svg>',
  tag:'<svg viewBox="0 0 24 24"><path d="M20 13 11 22 2 13V4h9l9 9Z"/><path d="M7 8h.01"/></svg>',
  sync:'<svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 1-15 6.7"/><path d="M3 12a9 9 0 0 1 15-6.7"/><path d="M21 5v7h-7"/><path d="M3 19v-7h7"/></svg>',
  export:'<svg viewBox="0 0 24 24"><path d="M5 20h14"/><path d="M12 4v12"/><path d="m7 11 5 5 5-5"/></svg>',
  force:'<svg viewBox="0 0 24 24"><path d="M4 4v6h6"/><path d="M20 20v-6h-6"/><path d="M20 9A8 8 0 0 0 6.3 5.3L4 10"/><path d="M4 15a8 8 0 0 0 13.7 3.7L20 14"/></svg>',
  folder:'<svg viewBox="0 0 24 24"><path d="M4 6h6l2 3h8v10H4z"/><path d="M12 13v4"/><path d="M10 15h4"/></svg>'
};
window.krIcon=krIcon;

function setGlobalActionIcon(button,icon,title,progress=''){
  if(!button)return;
  button.classList.add('global-icon-action');
  button.title=progress?`${title} - ${progress}`:title;
  button.setAttribute('aria-label',button.title);
  button.innerHTML=icon;
}
window.setGlobalActionIcon=setGlobalActionIcon;

function decorateGlobalActionButtons(){
  $$('.open-bulk-tags').forEach(button=>setGlobalActionIcon(button,krIcon.tag,'Tag globale sui brani visibili'));
  $$('.sync-vdj-years').forEach(button=>setGlobalActionIcon(button,krIcon.sync,'Aggiorna anno e genere da VirtualDJ a KR Desk'));
  setGlobalActionIcon($('#align-filtered-vdj-tags'),krIcon.align,'Allinea artista e titolo in VirtualDJ per la lista filtrata');
  setGlobalActionIcon($('#align-filtered-playlist-vdj-tags'),krIcon.align,'Allinea artista e titolo in VirtualDJ per la playlist filtrata');
  setGlobalActionIcon($('#send-library-to-spotify'),krIcon.export,'Porta in Spotify to VDJ solo i brani visibili con metriche Spotify');
  setGlobalActionIcon($('#playlist-send-to-spotify'),krIcon.export,'Porta in Spotify to VDJ solo i brani visibili con metriche Spotify');
  setGlobalActionIcon($('#playlist-force-spotify'),krIcon.force,'Forza ricerca Spotify ID sulla playlist visibile');
  setGlobalActionIcon($('#playlist-identify-spotify'),krIcon.spotify,'Trova Spotify ID per la playlist visibile');
  setGlobalActionIcon($('#playlist-bulk-spotify'),krIcon.metrics,'Recupera metriche Spotify per la playlist visibile');
  setGlobalActionIcon($('#expand-folder-tracks'),krIcon.folder,'Espandi tutta la cartella selezionata');
}
window.decorateGlobalActionButtons=decorateGlobalActionButtons;

const bulkSpotifyButton=document.createElement('button');
bulkSpotifyButton.id='bulk-spotify-features';
bulkSpotifyButton.className='button primary global-icon-action';
$('#send-library-to-spotify').before(bulkSpotifyButton);
const identifySpotifyButton=document.createElement('button');
identifySpotifyButton.id='identify-spotify-features';
identifySpotifyButton.className='button accent global-icon-action';
bulkSpotifyButton.before(identifySpotifyButton);
const forceSpotifyButton=document.createElement('button');
forceSpotifyButton.id='force-spotify-list';
forceSpotifyButton.className='button ghost global-icon-action';
identifySpotifyButton.before(forceSpotifyButton);
setGlobalActionIcon(forceSpotifyButton,krIcon.force,'Forza ricerca Spotify ID sui brani visibili');

const wait=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));
const pendingSpotifyTracks=()=>state.tracks.filter(track=>Number(track.id)>0&&track.spotify_id&&(['never','error'].includes(track.spotify_features_status)||(!track.spotify_features_status&&!track.spotify_features_updated_at)));
const unidentifiedSpotifyTracks=()=>state.tracks.filter(track=>Number(track.id)>0&&!track.spotify_id);
const canAlignVdjTrack=track=>{const extension=String(track.file_name||track.file_path||'').split('.').pop().toLowerCase();return Number(track.vdj_linked)&&['mp3','m4a','aac','ogg','opus','wma','flac','wav','aiff','aif','alac'].includes(extension)&&Number(track.bitrate)>=320};

const baseOpenEditor=openEditor;
openEditor=function(track){
  baseOpenEditor(track);
  const editor=$('#track-editor');
  editor.querySelector('.editor-head')?.insertAdjacentHTML('afterend',`<section class="spotify-link-editor"><label>LINK SPOTIFY TRACCIA<input type="url" value="${escapeHtml(track.spotify_url||'')}" placeholder="https://open.spotify.com/track/..."></label><button type="button" class="button accent save-spotify-link" data-id="${track.id}">Salva link</button></section>`);
  if(Array.isArray(track.auto_tags))track.auto_tags.forEach(tag=>{const button=[...editor.querySelectorAll('.tag-picker button')].find(item=>item.dataset.tag===tag);if(button){button.classList.add('auto-highlight');button.title='Tag attribuito automaticamente'}});
  const scoreSource=document.createElement('span');
  scoreSource.className=`badge ${Number(track.dj_scores_manual)?'amber':'blue'} dj-score-source`;
  scoreSource.textContent=Number(track.dj_scores_manual)?'Punteggi DJ':'Punteggi AUTO';
  editor.querySelector('.editor-head')?.append(scoreSource);
  const value=(number,digits=0)=>number===null||number===undefined?'--':Number(number).toFixed(digits);
  const percent=number=>number===null||number===undefined?'--':Math.round(Number(number)*100);
  const keyNames=['C','C#/Db','D','D#/Eb','E','F','F#/Gb','G','G#/Ab','A','A#/Bb','B'];
  const spotifyKey=track.spotify_key===null||track.spotify_key===undefined?'--':keyNames[Number(track.spotify_key)]||'--';
  const spotifyMode=track.spotify_mode===null||track.spotify_mode===undefined?'--':Number(track.spotify_mode)===1?'Maggiore':'Minore';
  const automaticCatalog=state.bootstrap?.automatic_tags||[];
  editor.insertAdjacentHTML('beforeend',`<section class="auto-tag-section"><span class="kicker">TAG AUTOMATICI - CLICCA PER FORZARE</span><div class="auto-tag-list">${automaticCatalog.map(tag=>`<button type="button" class="auto-tag-override ${track.auto_tags?.includes(tag)?'active':''}" data-track-id="${track.id}" data-tag="${escapeHtml(tag)}">${escapeHtml(tag)}</button>`).join('')}</div></section>`);
  editor.insertAdjacentHTML('beforeend',`<section class="spotify-editor-metrics kr-editor-metrics"><div class="spotify-editor-head"><span class="kicker">INDICATORI KR</span><span class="badge">0-100</span></div><div class="spotify-editor-grid"><div><span>Energia KR</span><b>${track.kr_energy??'--'}</b></div><div><span>Cantabilita KR</span><b>${track.kr_singability??'--'}</b></div><div><span>Potenza pista</span><b>${track.kr_floor_power??'--'}</b></div><div><span>Familiarita KR</span><b>${track.kr_familiarity??'--'}</b></div><div><span>Rischio KR</span><b>${track.kr_risk??'--'}</b></div><div><span>Impatto / Picco</span><b>${track.kr_peak??'--'}</b></div><div><span>Recupero pista</span><b>${track.kr_recovery??'--'}</b></div><div><span>Cambio genere</span><b>Contestuale</b></div></div></section>`);
  const featureLabels={complete:'Complete',partial:'Parziali',unavailable:'Non disponibili',error:'Errore',never:'Mai cercate'};
  editor.insertAdjacentHTML('beforeend',`<section class="spotify-editor-metrics"><div class="spotify-editor-head"><span class="kicker">METRICHE SPOTIFY</span><span class="badge blue">${featureLabels[track.spotify_features_status]||'Mai cercate'}</span></div><div class="spotify-editor-grid"><div><span>Energia</span><b>${percent(track.spotify_energy)}</b></div><div><span>Ballabilita</span><b>${percent(track.spotify_danceability)}</b></div><div><span>Valenza</span><b>${percent(track.spotify_valence)}</b></div><div><span>Popolarita</span><b>${track.popularity??'--'}</b></div><div><span>Genere Spotify</span><b>${escapeHtml(track.spotify_genre||'--')}</b></div><div><span>BPM Spotify</span><b>${value(track.spotify_tempo,1)}</b></div><div><span>Chiave</span><b>${spotifyKey}</b></div><div><span>Modalita</span><b>${spotifyMode}</b></div><div><span>Volume</span><b>${value(track.spotify_loudness,1)} dB</b></div><div><span>Acusticita</span><b>${percent(track.spotify_acousticness)}</b></div><div><span>Strumentale</span><b>${percent(track.spotify_instrumentalness)}</b></div><div><span>Parlato</span><b>${percent(track.spotify_speechiness)}</b></div><div><span>Presenza live</span><b>${percent(track.spotify_liveness)}</b></div></div></section>`);
};

document.addEventListener('click',async event=>{const button=event.target.closest('.auto-tag-override');if(!button)return;const enabled=!button.classList.contains('active');button.disabled=true;try{const result=await post('auto-tag-override',{id:Number(button.dataset.trackId),tag:button.dataset.tag,enabled});button.classList.toggle('active',enabled);const index=state.tracks.findIndex(track=>Number(track.id)===Number(button.dataset.trackId));if(index>=0)state.tracks[index]=result.track;toast(`${button.dataset.tag}: forzato ${enabled?'attivo':'spento'}`)}catch(error){toast(error.message)}finally{button.disabled=false}});
document.addEventListener('click',async event=>{const button=event.target.closest('.save-spotify-link');if(!button)return;const input=button.closest('.spotify-link-editor').querySelector('input');button.disabled=true;try{const result=await post('spotify-link-update',{id:Number(button.dataset.id),url:input.value.trim()});input.value=result.track.spotify_url;const index=state.tracks.findIndex(track=>Number(track.id)===Number(button.dataset.id));if(index>=0)state.tracks[index]=result.track;toast('Link Spotify salvato nel database')}catch(error){toast(error.message)}finally{button.disabled=false}});

let spotifyClipboardTimer=null;
document.addEventListener('click',async event=>{const link=event.target.closest('.spotify-search-link');if(!link)return;event.preventDefault();const url=link.href;const trackId=Number(link.dataset.trackId);const force=link.dataset.force==='1';delete link.dataset.force;const spotifyWindow=window.open('about:blank','vdjdesk_spotify');try{await post('spotify-clipboard-start',{id:trackId,force});toast(`${force?'ID e link ignorati - ':''}appunti azzerati - copia il link del brano da Spotify`);if(spotifyWindow){spotifyWindow.location.href=url;spotifyWindow.focus()}clearInterval(spotifyClipboardTimer);spotifyClipboardTimer=setInterval(async()=>{try{const result=await api(`spotify-clipboard-status&id=${trackId}`);if(!result.pending){clearInterval(spotifyClipboardTimer);spotifyClipboardTimer=null;if(result.saved){spotifyWindow?.close();window.focus();const index=state.tracks.findIndex(track=>Number(track.id)===Number(result.track.id));if(index>=0)state.tracks[index]=result.track;renderTracks();decorateSpotifyTracks();toast('Link Spotify acquisito e salvato nel database')}else toast('Acquisizione link Spotify scaduta')}}catch(error){}},1200)}catch(error){spotifyWindow?.close();toast(error.message)}});

let replacementWatchTimer=null;
async function handleReplacementWatchResult(result,spotmateWindow){
  if(result.requires_confirmation){
    clearInterval(replacementWatchTimer);replacementWatchTimer=null;
    const ok=window.confirm(`ATTENZIONE: ${result.warning}\n\nOriginale: ${result.old_path}\n\nDownload: ${result.download_path}\n\nConfermi la sostituzione tra media diversi?`);
    if(!ok){toast('Sostituzione media annullata: file lasciato in Da_classificare');return}
    await post('replacement-watch-confirm-media-change',{});
    result=await api('replacement-watch-status');
  }
  if(result.completed){
    clearInterval(replacementWatchTimer);replacementWatchTimer=null;spotmateWindow?.close();window.focus();
    const index=state.tracks.findIndex(track=>Number(track.id)===Number(result.track.id));
    if(index>=0)state.tracks[index]=result.track;
    renderTracks();decorateSpotifyTracks();
    toast(result.spotify_updated?'Nuovo media installato - metriche Spotify aggiornate':`Nuovo media installato - metriche non aggiornate: ${result.spotify_error||'errore Spotify'}`);
  }else if(!result.pending){
    clearInterval(replacementWatchTimer);replacementWatchTimer=null;
    if(result.expired)toast('Monitoraggio download scaduto');
  }
}
document.addEventListener('click',async event=>{const link=event.target.closest('.spotmate-link');if(!link)return;event.preventDefault();try{const watch=await post('replacement-watch-start',{id:Number(link.dataset.trackId)});await navigator.clipboard.writeText(link.dataset.spotifyUrl);toast(`SpotMate attivo - scarica in Downloads; poi sposto in ${watch.staging_folder||'Da_classificare'}`);const spotmateWindow=window.open(link.href,'vdjdesk_spotmate');spotmateWindow?.focus();clearInterval(replacementWatchTimer);replacementWatchTimer=setInterval(async()=>{try{await handleReplacementWatchResult(await api('replacement-watch-status'),spotmateWindow)}catch(error){clearInterval(replacementWatchTimer);replacementWatchTimer=null;toast(error.message)}},1500)}catch(error){toast(error.message)}});

function updateBulkSpotifyButton(){
  if(bulkSpotifyButton.disabled)return;
  const withId=state.tracks.filter(track=>track.spotify_id).length;
  setGlobalActionIcon(bulkSpotifyButton,krIcon.metrics,'Recupera metriche Spotify sui brani visibili',`${pendingSpotifyTracks().length}/${withId}`);
  if(!identifySpotifyButton.disabled)setGlobalActionIcon(identifySpotifyButton,krIcon.spotify,'Trova Spotify ID sui brani visibili',`${unidentifiedSpotifyTracks().length} mancanti`);
  decorateGlobalActionButtons();
}

function decorateSpotifyTracks(){
  $$('.track-row').forEach(row=>{
    const track=state.tracks.find(item=>item.id===Number(row.dataset.id));
    if(!track)return;
    row.classList.toggle('has-spotify-id',Boolean(track.spotify_id));
    if(!row.querySelector('.library-prelisten')){
      const prelisten=document.createElement('button');prelisten.type='button';prelisten.className='vdj-prelisten-title library-prelisten';prelisten.dataset.trackId=track.id;prelisten.title='Preascolta in cuffia con VirtualDJ da 60 secondi';prelisten.setAttribute('aria-label',prelisten.title);prelisten.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13v-2a8 8 0 0 1 16 0v2M6 12H4a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h2V12Zm12 0h2a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2V12Z"/></svg>';row.querySelector('.track-identity')?.prepend(prelisten);
    }
    if(!row.querySelector('.spotify-title-actions')){
      const actions=document.createElement('span');actions.className='spotify-title-actions';
      const query=[track.artist,track.title].filter(Boolean).join(' ');
      const spotify=document.createElement('a');spotify.className='spotify-title-action spotify-open spotify-direct-link spotify-search-link';spotify.href=`https://open.spotify.com/search/${encodeURIComponent(query)}`;spotify.target='vdjdesk_spotify';spotify.dataset.trackId=track.id;spotify.textContent='S';spotify.title='Cerca il brano su Spotify e acquisisci il link copiato';spotify.setAttribute('aria-label',spotify.title);actions.append(spotify);
      if(track.spotify_id){const spotmate=document.createElement('a');spotmate.className='spotify-title-action spotify-spotmate spotmate-link';spotmate.href='https://spotmate.online/premium';spotmate.target='vdjdesk_spotmate';spotmate.dataset.trackId=track.id;spotmate.dataset.spotifyUrl=track.spotify_url||`https://open.spotify.com/track/${track.spotify_id}`;spotmate.textContent='S';spotmate.title='Apri SpotMate, monitora il download e sostituisci il file';spotmate.setAttribute('aria-label',spotmate.title);actions.append(spotmate)}
      if(canAlignVdjTrack(track)){const align=document.createElement('button');align.type='button';align.className='spotify-title-action vdj-align-title';align.dataset.trackId=track.id;align.textContent='A';align.title='Allinea artista e titolo di KR Desk nel brano collegato in VirtualDJ';align.setAttribute('aria-label',align.title);actions.append(align)}
      row.querySelector('.track-identity strong')?.append(actions);
    }
    if(track.spotify_id&&!row.querySelector('.spotify-id-badge')){const badge=document.createElement('span');badge.className='badge blue spotify-id-badge';const labels={complete:'Spotify metriche',partial:'Spotify parziali',unavailable:'Spotify non disponibili',error:'Spotify da riprovare'};badge.textContent=labels[track.spotify_features_status]||'Spotify ID';row.querySelector('.track-tags')?.prepend(badge)}
    if(track.spotify_id&&!row.querySelector('.spotify-direct-link')){const link=document.createElement('a');link.className='badge blue spotify-direct-link';link.href=track.spotify_url||`https://open.spotify.com/track/${encodeURIComponent(track.spotify_id)}`;link.target='vdjdesk_spotify';link.textContent='Spotify';link.title='Apri il brano nella scheda Spotify Web controllata da KR Desk';row.querySelector('.track-tags')?.prepend(link)}
    if(track.spotify_id&&!row.querySelector('.spotmate-link')){const link=document.createElement('a');link.className='badge spotify-direct-link spotmate-link';link.href='https://spotmate.online/premium';link.target='_blank';link.rel='noopener';link.dataset.trackId=track.id;link.dataset.spotifyUrl=track.spotify_url||`https://open.spotify.com/track/${track.spotify_id}`;link.textContent='SpotMate';link.title='Copia il link, monitora il download e sostituisci automaticamente';row.querySelector('.track-tags')?.prepend(link)}
    if(!track.spotify_id&&!row.querySelector('.spotify-search-link')){const query=[track.artist,track.title].filter(Boolean).join(' ');const link=document.createElement('a');link.className='badge spotify-direct-link spotify-search-link';link.href=`https://open.spotify.com/search/${encodeURIComponent(query)}`;link.target='vdjdesk_spotify';link.dataset.trackId=track.id;link.textContent='Cerca Spotify';link.title='Apri la ricerca nella scheda Spotify Web controllata da KR Desk';row.querySelector('.track-tags')?.prepend(link)}
    if(track.spotify_features_updated_at&&!row.querySelector('.spotify-metrics')){const metrics=document.createElement('div');metrics.className='spotify-metrics';const percent=value=>value===null||value===undefined?'--':Math.round(Number(value)*100);metrics.innerHTML=`<span>E <b>${percent(track.spotify_energy)}</b></span><span>B <b>${percent(track.spotify_danceability)}</b></span><span>V <b>${percent(track.spotify_valence)}</b></span><span>POP <b>${track.popularity??'--'}</b></span><span>GEN <b>${escapeHtml(track.spotify_genre||'--')}</b></span>`;row.querySelector('.track-identity')?.append(metrics)}
    if(Array.isArray(track.auto_tags)&&track.auto_tags.length&&!row.querySelector('.auto-tag-badge')){const badge=document.createElement('span');badge.className='badge red auto-tag-badge';badge.textContent=track.auto_tags[0];row.querySelector('.track-tags')?.prepend(badge)}
    if(!row.querySelector('.track-quality')){const extension=String(track.file_name||track.file_path||'').split('.').pop().toUpperCase();const bitrate=Number(track.bitrate||0);const standard=extension==='MP3'&&bitrate>=320;const quality=document.createElement('div');quality.className='track-quality hide-mobile';quality.innerHTML=`<span class="cell-label">QUALITA FILE</span><span class="quality-badge ${standard?'quality-standard':'quality-review'}">${escapeHtml(extension||'-')}</span><strong>${bitrate?`${bitrate} kbps`:'bitrate -'}</strong>`;quality.title=standard?'Standard libreria: MP3 320 kbps':'Da valutare: formato o bitrate diverso dallo standard MP3 320 kbps';row.querySelector('.track-tags')?.before(quality)}
    const menu=row.querySelector('.action-menu');
    menu?.querySelector('[data-action="spotify"]')?.remove();
    menu?.querySelector('[data-action="suggest"]')?.remove();
    menu?.querySelector('[data-action="duplicates"]')?.remove();
    menu?.querySelector('[data-action="copy"]')?.remove();
    menu?.querySelector('[data-action="folder"]')?.remove();
    if(!menu||menu.querySelector('[data-action="spotify-features"]'))return;
    const button=document.createElement('button');button.dataset.action='spotify-features';button.textContent=['complete','partial','unavailable'].includes(track.spotify_features_status)?'Forza aggiornamento Spotify':'Recupera metriche Spotify';button.disabled=!track.spotify_id;menu.prepend(button);
    const moveButton=document.createElement('button');moveButton.dataset.action='library-move';moveButton.textContent='Sposta nella cartella genere';menu.append(moveButton);
    const deleteButton=document.createElement('button');deleteButton.dataset.action='library-move-to-delete';deleteButton.textContent='Sposta in Da_cancellare';menu.append(deleteButton);
  });
  updateBulkSpotifyButton();
}

const spotifyFeaturesMenuObserver=new MutationObserver(decorateSpotifyTracks);
spotifyFeaturesMenuObserver.observe($('#library-results'),{childList:true,subtree:true});

document.addEventListener('click',async event=>{const button=event.target.closest('[data-action="spotify-features"]');if(!button)return;const track=state.tracks.find(item=>item.id===Number(button.closest('.track-row').dataset.id));if(!track)return;try{toast('Recupero metriche Spotify...');const result=await post('spotify-audio-features',{id:track.id});Object.assign(track,result.track);renderTracks();toast(`Spotify: energia ${Math.round(result.features.energy*100)} - ballabilita ${Math.round(result.features.danceability*100)} - ${Math.round(result.features.tempo)} BPM`)}catch(error){toast(error.message)}});
document.addEventListener('click',async event=>{const button=event.target.closest('.vdj-align-title');if(!button)return;event.preventDefault();event.stopPropagation();button.disabled=true;try{const result=await post('vdj-align-artist-title',{id:Number(button.dataset.trackId)});toast(`VirtualDJ aggiornato - ${result.artist} - ${result.title}`)}catch(error){toast(error.message)}finally{button.disabled=false}});
document.addEventListener('click',async event=>{const button=event.target.closest('.vdj-prelisten-title');if(!button)return;event.preventDefault();event.stopPropagation();const stopping=button.classList.contains('is-playing');button.disabled=true;try{if(stopping){await post('vdj-prelisten-stop',{});button.classList.remove('is-playing');toast('Preascolto VDJ fermato')}else{$$('.vdj-prelisten-title,.builder-prelisten').forEach(item=>item.classList.remove('is-playing'));const result=await post('vdj-prelisten',{id:Number(button.dataset.trackId)});button.classList.add('is-playing');toast(`Preascolto VDJ da ${result.start_at}s - ${result.title}`)}}catch(error){toast(error.message)}finally{button.disabled=false}});

async function alignFilteredVdjTags(button){const tracks=state.tracks.filter(canAlignVdjTrack);if(!tracks.length){toast('Nessun audio collegato a VDJ da 320 kbps nella lista filtrata');return}if(!window.confirm(`Allineare artista e titolo in VirtualDJ per ${tracks.length} brani visibili?`))return;const original=button.innerHTML;button.disabled=true;krProgress.start('Allineamento nomi VDJ',tracks.length,'Lista filtrata');let completed=0,errors=0;try{for(let index=0;index<tracks.length;index++){krProgress.update('Allineamento nomi VDJ',index+1,tracks.length,`${tracks[index].artist||''} - ${tracks[index].title||''}`);if(window.setGlobalActionIcon)setGlobalActionIcon(button,krIcon.align,'Allineamento nomi VDJ',`${index+1}/${tracks.length}`);try{await post('vdj-align-artist-title',{id:tracks[index].id});completed++}catch(error){errors++}}krProgress.done('Allineamento VDJ completato',`${completed} ok${errors?` - ${errors} errori`:''}`);toast(`${completed} nomi allineati in VirtualDJ${errors?` - ${errors} errori`:''}`)}catch(error){krProgress.fail('Allineamento VDJ interrotto',error.message);toast(error.message)}finally{button.disabled=false;button.innerHTML=original;decorateGlobalActionButtons()}}
$('#align-filtered-vdj-tags').addEventListener('click',event=>alignFilteredVdjTags(event.currentTarget));
$('#align-filtered-playlist-vdj-tags').addEventListener('click',event=>alignFilteredVdjTags(event.currentTarget));

document.addEventListener('click',async event=>{const button=event.target.closest('[data-action="library-move"]');if(!button)return;const row=button.closest('.track-row');const track=state.tracks.find(item=>Number(item.id)===Number(row?.dataset.id));if(!track)return;button.disabled=true;toast(`Spostamento nella Libreria Definitiva in base al genere: ${track.genre||'non indicato'}`);try{const result=await post('track-move',{id:track.id});if(result.cancelled){toast('Spostamento annullato');return}const index=state.tracks.findIndex(item=>Number(item.id)===Number(track.id));if(index>=0)state.tracks[index]=result.track;renderTracks();decorateSpotifyTracks();toast('File spostato e percorso aggiornato')}catch(error){toast(error.message)}finally{button.disabled=false}});

bulkSpotifyButton.addEventListener('click',async()=>{const tracks=pendingSpotifyTracks();if(!tracks.length){toast('Nessun brano con Spotify ID da aggiornare nella lista');return}bulkSpotifyButton.disabled=true;krProgress.start('Recupero metriche Spotify',tracks.length,'Lista filtrata');let completed=0,errors=0;for(let index=0;index<tracks.length;index++){const track=tracks[index];setGlobalActionIcon(bulkSpotifyButton,krIcon.metrics,'Recupero metriche Spotify',`${index+1}/${tracks.length}`);krProgress.update('Recupero metriche Spotify',index+1,tracks.length,`${track.artist||''} - ${track.title||''}`);try{let result;try{result=await post('spotify-audio-features',{id:track.id})}catch(error){if(!error.message.includes('Limite Spotify'))throw error;setGlobalActionIcon(bulkSpotifyButton,krIcon.metrics,'Pausa Spotify','30 secondi');krProgress.update('Pausa limite Spotify',index+1,tracks.length,'Attendo 30 secondi');await wait(30000);result=await post('spotify-audio-features',{id:track.id})}Object.assign(track,result.track);completed++}catch(error){errors++}if(index<tracks.length-1)await wait(950)}bulkSpotifyButton.disabled=false;renderTracks();updateBulkSpotifyButton();krProgress.done('Metriche Spotify completate',`${completed} ok${errors?` - ${errors} errori`:''}`);toast(`${completed} metriche Spotify salvate${errors?` - ${errors} errori`:''}`)});

identifySpotifyButton.addEventListener('click',async()=>{const tracks=unidentifiedSpotifyTracks();if(!tracks.length){toast('Tutti i brani della lista hanno gia uno Spotify ID');return}identifySpotifyButton.disabled=true;bulkSpotifyButton.disabled=true;krProgress.start('Ricerca Spotify ID',tracks.length,'Lista filtrata');let completed=0,unmatched=0;const errors=[];for(let index=0;index<tracks.length;index++){const track=tracks[index];setGlobalActionIcon(identifySpotifyButton,krIcon.spotify,'Ricerca Spotify ID',`${index+1}/${tracks.length}`);krProgress.update('Ricerca Spotify ID',index+1,tracks.length,`${track.artist||''} - ${track.title||''}`);try{let result;try{result=await post('spotify-identify',{id:track.id})}catch(error){if(!error.message.includes('Limite Spotify'))throw error;setGlobalActionIcon(identifySpotifyButton,krIcon.spotify,'Pausa Spotify','30 secondi');krProgress.update('Pausa limite Spotify',index+1,tracks.length,'Attendo 30 secondi');await wait(30000);result=await post('spotify-identify',{id:track.id})}Object.assign(track,result.track);completed++}catch(error){unmatched++;if(errors.length<3)errors.push(`${track.artist} - ${track.title}: ${error.message}`)}if(index<tracks.length-1)await wait(950)}identifySpotifyButton.disabled=false;bulkSpotifyButton.disabled=false;renderTracks();updateBulkSpotifyButton();krProgress.done('Ricerca Spotify ID completata',`${completed} ok${unmatched?` - ${unmatched} non riconosciuti`:''}`);toast(`${completed} Spotify ID salvati${unmatched?` - ${unmatched} non riconosciuti`:''}${errors.length?` - ${errors.join(' | ')}`:''}`)});

forceSpotifyButton.addEventListener('click',async()=>{const tracks=state.tracks.filter(track=>Number(track.id)>0);if(!tracks.length)return;if(!window.confirm(`Rifare Spotify ID per ${tracks.length} brani visibili?`))return;forceSpotifyButton.disabled=true;identifySpotifyButton.disabled=true;bulkSpotifyButton.disabled=true;krProgress.start('Forza ricerca Spotify ID',tracks.length,'Lista filtrata');let completed=0,errors=0;for(let index=0;index<tracks.length;index++){setGlobalActionIcon(forceSpotifyButton,krIcon.force,'Forza ricerca Spotify ID',`${index+1}/${tracks.length}`);krProgress.update('Forza ricerca Spotify ID',index+1,tracks.length,`${tracks[index].artist||''} - ${tracks[index].title||''}`);try{let result;try{result=await post('spotify-identify',{id:tracks[index].id,force:true})}catch(error){if(!error.message.includes('Limite Spotify'))throw error;setGlobalActionIcon(forceSpotifyButton,krIcon.force,'Pausa Spotify','30 secondi');krProgress.update('Pausa limite Spotify',index+1,tracks.length,'Attendo 30 secondi');await wait(30000);result=await post('spotify-identify',{id:tracks[index].id,force:true})}Object.assign(tracks[index],result.track);completed++}catch(error){errors++}if(index<tracks.length-1)await wait(950)}forceSpotifyButton.disabled=false;identifySpotifyButton.disabled=false;bulkSpotifyButton.disabled=false;setGlobalActionIcon(forceSpotifyButton,krIcon.force,'Forza ricerca Spotify ID sui brani visibili');renderTracks();updateBulkSpotifyButton();krProgress.done('Forza Spotify ID completata',`${completed} ok${errors?` - ${errors} errori`:''}`);toast(`${completed} brani forzati${errors?` - ${errors} errori`:''}`)});

decorateGlobalActionButtons();
decorateSpotifyTracks();

document.addEventListener('click',async event=>{const button=event.target.closest('[data-action="library-move-to-delete"]');if(!button)return;event.preventDefault();event.stopPropagation();const row=button.closest('.track-row');const track=state.tracks.find(item=>Number(item.id)===Number(row?.dataset.id));if(!track)return;if(!window.confirm(`Spostare il file in Da_cancellare?\n\nIl file NON verra eliminato definitivamente.\n\n${track.artist} - ${track.title}\n${track.file_path}`))return;button.disabled=true;try{const result=await post('track-move-to-delete',{id:track.id});const index=state.tracks.findIndex(item=>Number(item.id)===Number(track.id));if(index>=0)state.tracks[index]=result.track;renderTracks();decorateSpotifyTracks();toast('File spostato in Da_cancellare')}catch(error){toast(error.message)}finally{button.disabled=false}});
