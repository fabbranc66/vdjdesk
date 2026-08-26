let playlistAllTracks=[];
let playlistOriginalTracks=[];
let playlistSort={field:'artist',direction:1};
let playlistDraggedPath='';
let playlistLoadRequest=0;
let playlistOnlyWork=false;
let playlistAudioStatusRequest=0;
const playlistAudioAnalysisIds=new Set();
const playlistBaseRenderTracks=renderTracks;
const playlistScrollStorageKey='krdesk-playlist-scroll';
let playlistSavedScroll=Number(sessionStorage.getItem(playlistScrollStorageKey)||0);
let playlistScrollRestorePending=false;

function rememberPlaylistScroll(){
  if(!$('#view-playlists')?.classList.contains('active'))return;
  playlistSavedScroll=window.scrollY;
  sessionStorage.setItem(playlistScrollStorageKey,String(playlistSavedScroll));
}

function restorePlaylistScroll(position=playlistSavedScroll){
  playlistSavedScroll=Math.max(0,Number(position)||0);
  sessionStorage.setItem(playlistScrollStorageKey,String(playlistSavedScroll));
  const apply=()=>{
    if($('#view-playlists')?.classList.contains('active'))window.scrollTo({top:playlistSavedScroll,left:0,behavior:'instant'});
  };
  requestAnimationFrame(()=>requestAnimationFrame(apply));
  [80,180,350].forEach(delay=>setTimeout(apply,delay));
  setTimeout(()=>{playlistScrollRestorePending=false},400);
}
function preparePlaylistScrollRestore(){rememberPlaylistScroll();playlistScrollRestorePending=true}
window.preparePlaylistScrollRestore=preparePlaylistScrollRestore;

async function loadPlaylists(){
  const select=$('#playlist-select');
  const request=++playlistLoadRequest;
  const selected=select.dataset.selected||select.value;
  select.innerHTML='<option value="">Lettura playlist...</option>';
  try{
    const data=await api('playlists');
    if(request!==playlistLoadRequest)return;
    $('#playlist-root').textContent=data.root;
    select.innerHTML=data.items.length?data.items.map(item=>`<option value="${escapeHtml(item.relative)}">${escapeHtml(item.name)} - ${item.tracks} brani</option>`).join(''):'<option value="">Nessuna playlist disponibile</option>';
    if(data.items.length){const relative=data.items.some(item=>item.relative===selected)?selected:data.items[0].relative;select.value=relative;await openPlaylist(relative)}else $('#playlist-results').innerHTML='<div class="empty-state panel">Nessuna playlist disponibile.</div>';
  }catch(error){$('#playlist-results').innerHTML=`<div class="empty-state panel">${escapeHtml(error.message)}</div>`}
}

async function openPlaylist(relative){
  const scrollPosition=playlistScrollRestorePending?playlistSavedScroll:window.scrollY;
  playlistSavedScroll=scrollPosition;
  playlistScrollRestorePending=true;
  $('#playlist-select').dataset.selected=relative;
  $('#playlist-results').innerHTML='<div class="empty-state">Lettura brani...</div>';
  const data=await api(`playlist-detail&file=${encodeURIComponent(relative)}`);
  playlistAllTracks=[...data.items];
  playlistOriginalTracks=[...data.items];
  $('#playlist-title').textContent=data.file;
  applyPlaylistFilters();
  restorePlaylistScroll(scrollPosition);
}

function playlistLibraryQuery(track){return [track.artist,track.title].filter(Boolean).join(' ')||String(track.file_name||track.file_path||'').replace(/\.[^.]+$/,'')}
function playlistCrc32(value){
  let crc=0xFFFFFFFF;
  for(const byte of new TextEncoder().encode(String(value||'').trim().toLocaleLowerCase('it'))){
    crc^=byte;
    for(let bit=0;bit<8;bit++)crc=(crc>>>1)^((crc&1)?0xEDB88320:0);
  }
  return (crc^0xFFFFFFFF)>>>0;
}
function playlistVdjColor(track){
  const bases={Commerciale:[37,99,235],Italiana:[34,197,94],Latin:[249,115,22],Rock_PopRock:[239,68,68],Urban:[168,85,247]};
  const base=bases[String(track.macro_genre||'')];
  if(!base)return '';
  const genre=String(track.genre||'').trim(),levels=[-.28,-.14,0,.14,.28],level=genre?levels[playlistCrc32(genre)%levels.length]:0;
  const rgb=base.map(component=>Math.max(0,Math.min(255,Math.round(level<0?component*(1+level):component+(255-component)*level))));
  return `#${rgb.map(component=>component.toString(16).padStart(2,'0')).join('').toUpperCase()}`;
}
function playlistLibrarySearchQuery(track){
  return playlistLibraryQuery(track).replace(/['’`]/g,' ').replace(/[^A-Za-z0-9À-ÿ]+/g,' ').replace(/\s+/g,' ').trim();
}
function openPlaylistSuggestions(track){
  const file=$('#playlist-select').value,afterIndex=playlistAllTracks.indexOf(track),trackId=Number(track?.id||0);
  if(!file||afterIndex<0||trackId<1){toast('Brano playlist non disponibile per i suggerimenti');return}
  rememberPlaylistScroll();
  window.playlistSuggestionContext={file,afterIndex,afterPath:track.file_path,scroll:playlistSavedScroll};
  const select=$('#current-track-select');
  if(!select.querySelector(`option[value="${trackId}"]`))select.insertAdjacentHTML('afterbegin',`<option value="${trackId}">${escapeHtml(track.artist)} - ${escapeHtml(track.title)}</option>`);
  select.value=String(trackId);state.currentMode='same';showView('suggestions');
}
function playlistCandidateQueries(track){
  const artist=String(track.artist||'').trim(),title=String(track.title||'').trim();
  const cleanArtist=artist.replace(/['’`]/g,'');
  const cleanTitle=title.replace(/['’`]/g,'');
  return [
    {artist,title,label:[artist,title].filter(Boolean).join(' - ')},
    {artist:cleanArtist,title:cleanTitle,label:[cleanArtist,cleanTitle].filter(Boolean).join(' - ')},
    {title,label:title},
    {title:cleanTitle,label:cleanTitle}
  ].filter(item=>String(item.title||item.artist||'').trim().length>=3);
}
async function searchPlaylistQueryInLibrary(query,track=null){
  query=String(query||'').trim();
  if(!query){toast('Inserisci artista o titolo da cercare nella libreria E');return}
  preparePlaylistScrollRestore();
  if(track){
    window.playlistReplacementContext={
      file:$('#playlist-select').value,
      old_path:track.file_path,
      label:[track.artist,track.title].filter(Boolean).join(' - ')||track.file_path
    };
  }else{
    window.playlistReplacementContext=null;
  }
  $('#library-search').value=query;
  $('#filter-folder').value='';
  state.libraryExtraFilters={};
  showView('library');
  await loadTracks(true,false);
  toast(`Ricerca in libreria E: ${query}`);
}

function playlistSortValue(track,field){
  if(field==='artist')return `${track.artist||''} ${track.title||''}`.toLocaleLowerCase('it');
  if(field==='key')return String(track.camelot||track.musical_key||'').toLocaleLowerCase('it');
  if(field==='genre')return String(track.genre||'').toLocaleLowerCase('it');
  if(field==='format')return String(track.file_name||track.file_path||'').split('.').pop().toLocaleLowerCase('it');
  if(field==='tags')return (track.tags||[]).join(' ').toLocaleLowerCase('it');
  return Number(track[field]??0);
}

function applyPlaylistFilters(){
  const query=$('#playlist-search').value.trim().toLocaleLowerCase('it'),filters=advancedFilterSelection('playlist'),macros=filters.macroGenres,folderGenres=filters.folderGenres,bpm=Number(filters.bpm||0),keys=filters.keys.map(value=>value.toLocaleLowerCase('it')),genres=filters.genres.map(value=>value.toLocaleLowerCase('it')),year=Number(filters.year||0);
  state.tracks=playlistAllTracks.filter(track=>{
    const complete=Boolean(track._playlist_exists)&&/^[A-Za-z0-9]{22}$/.test(String(track.spotify_id||''))&&['complete','partial'].includes(track.spotify_features_status);
    if(playlistOnlyWork&&complete)return false;
    const text=`${track.artist||''} ${track.title||''} ${track.file_name||''}`.toLocaleLowerCase('it'),trackKey=String(track.camelot||track.musical_key||'').toLocaleLowerCase('it'),trackGenre=String(track.genre||'').toLocaleLowerCase('it');
    return(!query||text.includes(query))&&(!macros.length||macros.includes(String(track.macro_genre||'')))&&(!folderGenres.length||folderGenres.includes(String(track.folder_genre||'')))&&(!bpm||Math.abs(Number(track.bpm||0)-bpm)<=8)&&(!keys.length||keys.includes(trackKey))&&(!genres.length||genres.includes(trackGenre))&&(!year||Number(track.year)===year);
  });
  renderTracks();
}

function updatePlaylistSpotifyActions(){
  const validSpotifyId=track=>/^[A-Za-z0-9]{22}$/.test(String(track.spotify_id||''));
  const visible=state.tracks.filter(track=>Number(track.id)>0),withId=visible.filter(validSpotifyId),pending=withId.filter(track=>['never','error'].includes(track.spotify_features_status)||(!track.spotify_features_status&&!track.spotify_features_updated_at)),unidentified=visible.filter(track=>!validSpotifyId(track));
  const bulk=$('#playlist-bulk-spotify'),identify=$('#playlist-identify-spotify'),force=$('#playlist-force-spotify'),send=$('#playlist-send-to-spotify'),replaceMissing=$('#playlist-bulk-replace-missing');
  if(window.setGlobalActionIcon){
    if(bulk&&!bulk.disabled)setGlobalActionIcon(bulk,krIcon.metrics,'Recupera metriche Spotify per la playlist visibile',`${pending.length}/${withId.length} da ricercare`);
    if(identify&&!identify.disabled)setGlobalActionIcon(identify,krIcon.spotify,'Trova Spotify ID per la playlist visibile',`${unidentified.length} mancanti`);
    setGlobalActionIcon(force,krIcon.force,'Forza ricerca Spotify ID sulla playlist visibile');
    setGlobalActionIcon(send,krIcon.export,'Porta in Spotify to VDJ solo i brani visibili con metriche Spotify');
    setGlobalActionIcon(replaceMissing,krIcon.folder,'Sostituisci in E i brani mancanti con Spotify ID identico');
  }else{
    if(bulk&&!bulk.disabled)bulk.textContent=`Metriche Spotify ${pending.length}/${withId.length}`;
    if(identify&&!identify.disabled)identify.textContent=`Trova Spotify ID ${unidentified.length}`;
  }
}


async function replacePlaylistMissingFromLibrary(track){
  const file=$('#playlist-select').value;
  if(!file)return 'skipped';
  const queries=playlistCandidateQueries(track);
  const candidates=[],seen=new Set();
  for(const query of queries){
    const params=new URLSearchParams({limit:'20',offset:'0'});
    if(query.artist)params.set('artist',query.artist);
    if(query.title)params.set('title',query.title);
    const data=await api(`tracks&${params}`);
    for(const item of data.items||[]){
      const path=String(item.file_path||'');
      if(!path.toUpperCase().startsWith('E:')||!Number(item.id)||seen.has(path.toUpperCase()))continue;
      seen.add(path.toUpperCase());
      candidates.push(item);
    }
    if(candidates.length)break;
  }
  if(!candidates.length){return 'not_found'}
  let chosen=candidates[0];
  if(candidates.length>1){
    const message=candidates.slice(0,5).map((item,index)=>`${index+1}) ${item.artist||''} - ${item.title||''} | ${item.file_path}`).join('\n');
    const answer=window.prompt(`Scegli candidato da usare:\n${message}`, '1');
    if(answer===null)return 'skipped';
    const index=Math.max(1,Math.min(5,Number(answer)||1))-1;
    chosen=candidates[index]||candidates[0];
  }
  if(!window.confirm(`Sostituire nella playlist?\n\nDA: ${track.artist||'Artista sconosciuto'} - ${track.title||'Titolo sconosciuto'}\n\nA: ${chosen.file_path}`))return 'skipped';
  const scrollPosition=window.scrollY;
  const result=await post('playlist-replace-track',{file,old_path:track.file_path,new_path:chosen.file_path});
  toast(`${result.replaced} riferimento playlist sostituito`);
  await openPlaylist(file);
  requestAnimationFrame(()=>window.scrollTo({top:scrollPosition,left:0,behavior:'instant'}));
  return 'replaced';
}

async function replaceAllPlaylistMissingFromLibrary(button){
  const file=$('#playlist-select').value;if(!file)return;
  const missing=playlistAllTracks.filter(track=>!track._playlist_exists).length;
  if(!missing){toast('Nessun brano mancante nella playlist');return}
  const scrollPosition=window.scrollY;button.disabled=true;
  try{
    const result=await post('playlist-replace-all-missing',{file});await openPlaylist(file);
    const remaining=[...playlistAllTracks.filter(track=>!track._playlist_exists)];let manual=0,notFound=0,skipped=0;
    toast(`${result.replaced} sicuri sostituiti · controllo manuale di ${remaining.length} brani`);
    for(const track of remaining){const outcome=await replacePlaylistMissingFromLibrary(track);if(outcome==='replaced')manual++;else if(outcome==='not_found')notFound++;else skipped++;}
    await openPlaylist(file);requestAnimationFrame(()=>window.scrollTo({top:scrollPosition,left:0,behavior:'instant'}));
    toast(`${result.replaced} sicuri · ${manual} manuali · ${notFound} senza candidati${skipped?` · ${skipped} saltati`:''}`);
  }
  catch(error){toast(error.message)}finally{button.disabled=false;updatePlaylistSpotifyActions()}
}

function renderPlaylistTable(){
  const target=$('#playlist-results');
  $('#playlist-count').textContent=`${state.tracks.length} di ${playlistAllTracks.length}`;
  target.innerHTML=state.tracks.length?state.tracks.map((track,index)=>{const vdjColor=playlistVdjColor(track);return `<article class="track-row ${track._playlist_exists?'':'playlist-missing'}${vdjColor?' playlist-vdj-colored':''}"${vdjColor?` style="--playlist-vdj-color:${vdjColor}"`:''} draggable="true" data-id="${track.id}" data-playlist-index="${index}" data-playlist-path="${escapeHtml(track.file_path)}"><div class="track-identity"><strong>${escapeHtml(track.artist||'Artista sconosciuto')} - ${escapeHtml(track.title)}</strong><div class="playlist-title-actions">${!track._playlist_exists?`<a class="playlist-library-dot spotify" target="vdjdesk_spotify" href="https://open.spotify.com/search/${encodeURIComponent(playlistLibraryQuery(track))}" title="Cerca questo brano su Spotify">S</a>`:''}<button type="button" class="playlist-library-dot" data-query="${escapeHtml(playlistLibrarySearchQuery(track))}" data-path="${escapeHtml(track.file_path)}" title="Cerca questo brano nella libreria completa E" aria-label="Cerca in libreria E">E</button>${Number(track.id)>0?`<button type="button" class="playlist-audio-analysis${playlistAudioAnalysisIds.has(Number(track.id))?' analyzed':''}" data-track-id="${track.id}" title="Apri in Analisi Audio" aria-label="Apri in Analisi Audio">∿</button><button type="button" class="playlist-suggest-next" title="Suggerisci il brano successivo" aria-label="Suggerisci il brano successivo">✦</button>`:''}</div><small title="${escapeHtml(track.file_path)}">${escapeHtml(track.file_path)}</small></div><div><span class="cell-label">BPM</span><span class="cell-value">${track.bpm??'-'}</span></div><div><span class="cell-label">KEY / SCALA</span><span class="cell-value">${escapeHtml(track.camelot||track.musical_key||'-')} ${scaleMode(track)}</span></div><div class="hide-mobile"><span class="cell-label">DURATA</span><span class="cell-value">${formatDuration(track.duration)}</span></div><div class="hide-tablet hide-mobile"><span class="cell-label">GENERE / ANNO</span><span class="cell-value">${escapeHtml(track.folder_genre||track.genre||'-')} / ${escapeHtml(track.genre||'-')} - ${track.year||'-'}</span></div><div class="track-tags hide-mobile">${track.version?`<span class="badge blue">${escapeHtml(track.version)}</span>`:''}${(track.tags||[]).slice(0,2).map(tag=>`<span class="badge">${escapeHtml(tag)}</span>`).join('')}</div><div class="track-actions">${track.id?'<button class="more-button">...</button><div class="action-menu"><button data-action="edit">Tag e punteggi</button><button data-action="played">Segna come suonato</button><button data-action="queue">Aggiungi alla coda</button><button type="button" class="playlist-remove-row">Rimuovi da playlist</button></div>':`<button type="button" class="button ghost playlist-replace-missing" data-path="${escapeHtml(track.file_path)}">Inserisci da E</button><button type="button" class="button ghost playlist-remove-row">Rimuovi</button>`}</div></article>`}).join(''):'<div class="empty-state panel">Nessun brano corrisponde ai filtri.</div>';
  $$('#playlist-results .track-identity>small').forEach(item=>item.remove());
  if(typeof decorateSpotifyTracks==='function')decorateSpotifyTracks();
  updatePlaylistSpotifyActions();
  refreshPlaylistAudioAnalysisStatuses().catch(()=>{});
}

async function refreshPlaylistAudioAnalysisStatuses(){
  const ids=[...new Set(state.tracks.map(track=>Number(track.id)).filter(id=>id>0))];
  if(!ids.length)return;
  const request=++playlistAudioStatusRequest;
  const data=await post('audio-analysis-statuses',{ids});
  if(request!==playlistAudioStatusRequest)return;
  ids.forEach(id=>{
    if(data.items?.[String(id)]?.exists)playlistAudioAnalysisIds.add(id);
    else playlistAudioAnalysisIds.delete(id);
  });
  $$('#playlist-results .playlist-audio-analysis').forEach(button=>button.classList.toggle('analyzed',playlistAudioAnalysisIds.has(Number(button.dataset.trackId))));
}

document.addEventListener('click',async event=>{
  const suggestionButton=event.target.closest('.playlist-suggest-next');
  if(suggestionButton){
    event.preventDefault();event.stopPropagation();
    const visibleIndex=Number(suggestionButton.closest('[data-playlist-index]')?.dataset.playlistIndex??-1);
    openPlaylistSuggestions(state.tracks[visibleIndex]||null);return;
  }
  const button=event.target.closest('.playlist-audio-analysis');
  if(!button)return;
  event.preventDefault();
  event.stopPropagation();
  const visibleIndex=Number(button.closest('[data-playlist-index]')?.dataset.playlistIndex??-1);
  const track=state.tracks[visibleIndex]||null;
  if(!track){toast('Record libreria non disponibile per l’analisi audio');return}
  const playlistIndex=playlistAllTracks.indexOf(track);
  const nextTrack=playlistIndex>=0?playlistAllTracks[playlistIndex+1]||null:null;
  try{await window.openAudioAnalysisTrack(track,{source:'playlist',nextTrack})}catch(error){toast(error.message)}
});

document.addEventListener('audio-analysis-completed',event=>{
  const trackId=Number(event.detail?.trackId||0);
  if(trackId<1)return;
  playlistAudioAnalysisIds.add(trackId);
  $$(`#playlist-results .playlist-audio-analysis[data-track-id="${trackId}"]`).forEach(button=>button.classList.add('analyzed'));
});

function reorderPlaylist(compare){playlistAllTracks.sort(compare);applyPlaylistFilters()}
async function saveVisiblePlaylist(){
  const file=$('#playlist-select').value;
  if(!file)return;
  const visibleTracks=[...state.tracks];
  if(!visibleTracks.length){toast('Nessun brano visualizzato da salvare');return}
  const payload={file,paths:visibleTracks.map(track=>track.file_path)};
  let result=await post('playlist-save-order',payload);
  if(result.name_conflict){
    const proposed=window.prompt('Esiste già una playlist con le stesse percentuali. Conferma o modifica il nuovo nome:',result.suggested_name||'');
    if(proposed===null){toast(`${result.tracks} brani salvati con il nome attuale`);return}
    if(!proposed.trim()){toast('Nome playlist non valido');return}
    result=await post('playlist-save-order',{...payload,name:proposed.trim()});
  }
  $('#playlist-select').dataset.selected=result.relative||file;
  await loadPlaylists();
  playlistOriginalTracks=[...playlistAllTracks];
  toast(`${result.tracks} brani visualizzati salvati - ${result.file||'playlist VDJ aggiornata'}`);
}
function playlistCamelotBpmCompare(left,right){
  const leftCamelot=camelotParts(left),rightCamelot=camelotParts(right);
  const leftCamelotOrder=leftCamelot?(leftCamelot.letter==='A'?0:12)+leftCamelot.number:99;
  const rightCamelotOrder=rightCamelot?(rightCamelot.letter==='A'?0:12)+rightCamelot.number:99;
  return leftCamelotOrder-rightCamelotOrder
    ||Number(left.bpm||0)-Number(right.bpm||0);
}
function playlistCamelotDistance(left,right){
  const a=camelotParts(left),b=camelotParts(right);
  if(!a||!b)return Number.POSITIVE_INFINITY;
  const numeric=Math.min(Math.abs(a.number-b.number),12-Math.abs(a.number-b.number));
  return numeric+(a.letter===b.letter?0:1);
}
function playlistCamelotRecommended(left,right){
  if(!left)return true;
  const distance=playlistCamelotDistance(left,right);
  return distance<=2;
}
function playlistMacroBpmDifference(left,right){
  const leftBpm=Number(left?.bpm||0),rightBpm=Number(right?.bpm||0);
  return leftBpm>0&&rightBpm>0?Math.abs(leftBpm-rightBpm):Number.POSITIVE_INFINITY;
}
function playlistMacroBpmCompatible(left,right){return !left||playlistMacroBpmDifference(left,right)<=15}
function takePlaylistCamelotBlock(queue,size,previousTrack,macro){
  const block=[];let current=previousTrack,fallbacks=0;
  while(block.length<size&&queue.length){
    const ranked=queue.map((track,index)=>({track,index,distance:current?playlistCamelotDistance(current,track):0,bpm:current?playlistMacroBpmDifference(current,track):Number(track.bpm||0)}))
      .sort((left,right)=>left.distance-right.distance||left.bpm-right.bpm||playlistCamelotBpmCompare(left.track,right.track));
    const genreChange=Boolean(current&&String(current.macro_genre||'')!==macro);
    const bpmCompatible=ranked.filter(item=>playlistMacroBpmCompatible(current,item.track));
    let choice=bpmCompatible.find(item=>playlistCamelotRecommended(current,item.track,genreChange))||bpmCompatible[0];
    if(!choice){
      if(block.length)break;
      choice=ranked[0];fallbacks++;
    }
    const [track]=queue.splice(choice.index,1);block.push(track);current=track;
  }
  return{block,fallbacks};
}
function buildKrGenreBlocks(){
  const groups=new Map();
  for(const track of playlistAllTracks){
    const macro=String(track.macro_genre||'Senza macrogenere').trim()||'Senza macrogenere';
    if(!groups.has(macro))groups.set(macro,[]);
    groups.get(macro).push(track);
  }
  groups.forEach(tracks=>tracks.sort(playlistCamelotBpmCompare));
  const configured=(state.taxonomyOptions?.macros||[]).map(item=>String(item.name||'')).filter(name=>groups.has(name));
  const extras=[...groups.keys()].filter(name=>!configured.includes(name)).sort((a,b)=>a.localeCompare(b,'it',{numeric:true,sensitivity:'base'}));
  const macroOrder=[...configured,...extras],totals=new Map(macroOrder.map(name=>[name,groups.get(name).length])),placed=new Map(macroOrder.map(name=>[name,0])),ordered=[];
  const total=playlistAllTracks.length;
  let remaining=playlistAllTracks.length;
  let previousMacro='';
  let previousTrack=null;
  let fallbacks=0;
  while(remaining>0){
    const candidates=macroOrder.filter(macro=>(groups.get(macro)?.length||0)>0);
    const bpmCompatible=previousTrack?candidates.filter(macro=>groups.get(macro).some(track=>playlistMacroBpmCompatible(previousTrack,track))):candidates;
    const compatible=previousTrack?bpmCompatible.filter(macro=>groups.get(macro).some(track=>playlistMacroBpmCompatible(previousTrack,track)&&playlistCamelotRecommended(previousTrack,track,String(previousTrack.macro_genre||'')!==macro))):[];
    let selectable=compatible.length?compatible:bpmCompatible.length?bpmCompatible:candidates;
    if(selectable.length>1){
      const alternatives=selectable.filter(macro=>macro!==previousMacro);
      if(alternatives.length)selectable=alternatives;
    }
    if(!selectable.length)selectable=candidates;
    const current=total-remaining;
    selectable.sort((left,right)=>{
      const leftDeficit=(current+1)*(totals.get(left)/total)-(placed.get(left)||0);
      const rightDeficit=(current+1)*(totals.get(right)/total)-(placed.get(right)||0);
      return rightDeficit-leftDeficit||macroOrder.indexOf(left)-macroOrder.indexOf(right);
    });
    const macro=selectable[0],tracks=groups.get(macro),share=totals.get(macro)/total;
    const needed=Math.round((current+4)*share-(placed.get(macro)||0));
    const blockSize=Math.min(4,Math.max(1,needed),tracks.length);
    const selection=takePlaylistCamelotBlock(tracks,blockSize,previousTrack,macro),block=selection.block;
    ordered.push(...block);
    placed.set(macro,(placed.get(macro)||0)+block.length);
    remaining-=block.length;
    previousMacro=macro;
    previousTrack=block[block.length-1]||previousTrack;
    fallbacks+=selection.fallbacks;
  }
  playlistAllTracks=ordered;
  $('#playlist-camelot-debug').innerHTML='';
  applyPlaylistFilters();
  toast(`Macro - blocchi 1-4 - massimo ±15 BPM - Camelot ±2${fallbacks?` - ${fallbacks} salti inevitabili`:''}`);
}
function camelotParts(track){const match=String(track.camelot||'').trim().toUpperCase().match(/^([1-9]|1[0-2])([AB])$/);return match?{number:Number(match[1]),letter:match[2],key:`${Number(match[1])}${match[2]}`}:null}
function camelotTransition(left,right){const a=camelotParts(left),b=camelotParts(right);if(!a||!b)return{compatible:false,type:'key non valida'};if(a.key===b.key)return{compatible:true,type:'stessa key'};if(a.number===b.number&&a.letter!==b.letter)return{compatible:true,type:'relativa'};const next=a.number===12?1:a.number+1,previous=a.number===1?12:a.number-1;if(a.letter===b.letter&&(b.number===next||b.number===previous))return{compatible:true,type:'adiacente'};return{compatible:false,type:'fallback'}}
function playlistQualityPenalty(track){const extension=String(track.file_name||track.file_path||'').split('.').pop().toLowerCase(),bitrate=Number(track.bitrate||0);return(extension==='mp3'&&bitrate>=320)||['flac','wav','aiff','aif','alac'].includes(extension)?0:15}
function playlistBpmLimit(){return 10}
function mixableBpmDifference(left,right){const a=Number(left.bpm||0),b=Number(right.bpm||0);if(!a||!b)return 99;return Math.min(Math.abs(a-b),Math.abs(a*2-b),Math.abs(a-b*2))}
function camelotSecondaryScore(current,candidate){const difference=mixableBpmDifference(current,candidate),limit=playlistBpmLimit(),overflow=Math.max(0,difference-limit),bpm=difference*3+overflow*overflow*18,energy=Math.abs(Number(current.energy||3)-Number(candidate.energy||3))*8,genre=String(current.genre||'').trim().toLowerCase()===String(candidate.genre||'').trim().toLowerCase()?0:25,popularity=(100-Number(candidate.popularity??40))*.08,year=Math.abs(Number(current.year||0)-Number(candidate.year||0))*.03,quality=playlistQualityPenalty(candidate);return{score:bpm+energy+genre+popularity+year+quality,bpmDifference:difference,bpmOutside:overflow>0,reason:`Delta BPM mixabile ${difference.toFixed(1)} / limite ${limit} - energia ${energy/8} - ${genre?'genere diverso':'stesso genere'} - qualita ${quality?'penalizzata':'ok'}`}}
function buildCamelotCandidate(start,tracks,mode){const ordered=[start],remaining=tracks.filter(track=>track!==start),debug=[];let fallbackCount=0,totalScore=0;while(remaining.length){const current=ordered[ordered.length-1],compatible=remaining.filter(track=>camelotTransition(current,track).compatible),compatibleBpm=compatible.filter(track=>mixableBpmDifference(current,track)<=playlistBpmLimit()),pool=mode==='strict'?(compatibleBpm.length?compatibleBpm:compatible.length?compatible:remaining):remaining;const ranked=pool.map(track=>{const transition=camelotTransition(current,track),secondary=camelotSecondaryScore(current,track),onward=remaining.filter(other=>other!==track&&camelotTransition(track,other).compatible&&mixableBpmDifference(track,other)<=playlistBpmLimit()).length,harmonic=transition.compatible?(transition.type==='stessa key'?0:transition.type==='adiacente'?6:8):(mode==='strict'?10000:800);return{track,transition,secondary,onward,score:harmonic+secondary.score+onward*2}}).sort((a,b)=>a.score-b.score);const chosen=ranked[0];if(!chosen.transition.compatible)fallbackCount++;totalScore+=chosen.score;debug.push({current,chosen:chosen.track,transition:chosen.transition.type+(chosen.secondary.bpmOutside?' - BPM fuori range':''),compatible:chosen.transition.compatible&&!chosen.secondary.bpmOutside,reason:chosen.secondary.reason,penalty:(chosen.transition.compatible?0:(mode==='strict'?10000:800))+(chosen.secondary.bpmOutside?Math.round(Math.pow(chosen.secondary.bpmDifference-playlistBpmLimit(),2)*18):0)});ordered.push(chosen.track);remaining.splice(remaining.indexOf(chosen.track),1)}return{ordered,debug,fallbackCount,totalScore}}
function buildCamelotOrder(mode){const valid=playlistAllTracks.filter(camelotParts),invalid=playlistAllTracks.filter(track=>!camelotParts(track));if(!valid.length){toast('Nessuna chiave Camelot valida da 1A a 12B');return}const starts=valid.length<=80?valid:[...valid].sort((a,b)=>Number(a.bpm||999)-Number(b.bpm||999)).slice(0,40);let best=null;for(const start of starts){const candidate=buildCamelotCandidate(start,valid,mode);const objective=candidate.fallbackCount*100000+candidate.totalScore;if(!best||objective<best.objective)best={...candidate,objective}}if(invalid.length){for(const track of invalid){const current=best.ordered[best.ordered.length-1];best.debug.push({current,chosen:track,transition:'key non valida',compatible:false,reason:'Brano escluso dalla catena Camelot sicura e accodato in fondo',penalty:10000});best.ordered.push(track)}}playlistAllTracks=best.ordered;renderCamelotDebug(best.debug,mode);applyPlaylistFilters();toast(`Camelot ${mode==='strict'?'Strict':'Soft'} - ${best.fallbackCount} fallback - ${invalid.length} key non valide`)}
function renderCamelotDebug(items,mode){const compatible=items.filter(item=>item.compatible).length,fallback=items.length-compatible;$('#playlist-camelot-debug').innerHTML=`<details class="camelot-debug-details"><summary class="camelot-debug-head"><strong>Camelot ${mode==='strict'?'Strict':'Soft'}</strong><span>${compatible} compatibili - ${fallback} fallback - apri debug</span></summary>${items.map((item,index)=>`<div class="camelot-debug-row ${item.compatible?'ok':'fallback'}"><span>${index+1}</span><b>${escapeHtml(item.current.artist||'')} - ${escapeHtml(item.current.title||'')} <i>${escapeHtml(item.current.camelot||'-')}</i></b><span>-></span><b>${escapeHtml(item.chosen.artist||'')} - ${escapeHtml(item.chosen.title||'')} <i>${escapeHtml(item.chosen.camelot||'-')}</i></b><em>${escapeHtml(item.transition)}</em><small>${escapeHtml(item.reason)}${item.penalty?` - penalita ${item.penalty}`:''}</small></div>`).join('')}</details>`}

renderTracks=function(){if($('#view-playlists').classList.contains('active'))renderPlaylistTable();else playlistBaseRenderTracks()};
document.addEventListener('click',async event=>{if(event.target.closest('[data-view="library"]'))loadTracks(true,false);const sort=event.target.closest('#playlist-sort-header [data-sort]');if(sort){const field=sort.dataset.sort;playlistSort=field===playlistSort.field?{field,direction:playlistSort.direction*-1}:{field,direction:1};const {direction}=playlistSort;reorderPlaylist((a,b)=>{const left=playlistSortValue(a,field),right=playlistSortValue(b,field);return(typeof left==='string'?left.localeCompare(right,'it',{numeric:true,sensitivity:'base'}):left-right)*direction})}if(event.target.closest('#playlist-original')){playlistAllTracks=[...playlistOriginalTracks];$('#playlist-camelot-debug').innerHTML='';applyPlaylistFilters()}if(event.target.closest('#playlist-bpm-up'))reorderPlaylist((a,b)=>Number(a.bpm||999)-Number(b.bpm||999));if(event.target.closest('#playlist-bpm-down'))reorderPlaylist((a,b)=>Number(b.bpm||0)-Number(a.bpm||0));if(event.target.closest('#playlist-camelot-strict'))buildCamelotOrder('strict');if(event.target.closest('#playlist-camelot-soft'))buildCamelotOrder('soft');if(event.target.closest('#playlist-genre-bpm'))buildKrGenreBlocks();if(event.target.closest('#playlist-save'))await saveVisiblePlaylist()});
$('#playlist-results').addEventListener('dragstart',event=>{const row=event.target.closest('[data-playlist-path]');if(!row)return;playlistDraggedPath=row.dataset.playlistPath;row.classList.add('dragging')});$('#playlist-results').addEventListener('dragend',event=>{event.target.closest('.track-row')?.classList.remove('dragging');playlistDraggedPath=''});$('#playlist-results').addEventListener('dragover',event=>{if(!playlistDraggedPath)return;event.preventDefault()});$('#playlist-results').addEventListener('drop',event=>{const target=event.target.closest('[data-playlist-path]');if(!target||!playlistDraggedPath||target.dataset.playlistPath===playlistDraggedPath)return;if(state.tracks.length!==playlistAllTracks.length){toast('Azzera i filtri prima del riordino manuale');return}const from=playlistAllTracks.findIndex(track=>track.file_path===playlistDraggedPath),to=playlistAllTracks.findIndex(track=>track.file_path===target.dataset.playlistPath);if(from<0||to<0)return;const [moved]=playlistAllTracks.splice(from,1);playlistAllTracks.splice(to,0,moved);applyPlaylistFilters()});
$('#playlist-results').addEventListener('click',async event=>{const button=event.target.closest('.playlist-remove-row');if(!button)return;event.preventDefault();event.stopPropagation();const row=button.closest('[data-playlist-path]'),file=$('#playlist-select').value;if(!row||!file)return;const index=Number(row.dataset.playlistIndex),path=row.dataset.playlistPath;if(!window.confirm(`Rimuovere questa riga dalla playlist?\n\nIl file audio NON verra cancellato.\n\n${path}`))return;button.disabled=true;try{const result=await post('playlist-remove-track',{file,index,path});toast(`Riga rimossa dalla playlist - ${result.tracks} brani rimasti`);await openPlaylist(file)}catch(error){toast(error.message)}finally{button.disabled=false}});
$('#playlist-select').addEventListener('change',event=>{if(event.target.value)openPlaylist(event.target.value)});$('#playlist-search-button').addEventListener('click',applyPlaylistFilters);$('#playlist-macro-genre')?.addEventListener('change',()=>{if(typeof updateFolderGenreOptions==='function')updateFolderGenreOptions('#playlist-macro-genre','#playlist-folder-genre');applyPlaylistFilters()});$('#playlist-folder-genre')?.addEventListener('change',applyPlaylistFilters);$('#playlist-search-library').addEventListener('click',()=>searchPlaylistQueryInLibrary($('#playlist-search').value));document.addEventListener('click',event=>{if(!event.target.closest('.playlist-search-library-action'))return;searchPlaylistQueryInLibrary($('#playlist-search').value)});document.addEventListener('click',event=>{const button=event.target.closest('.playlist-library-search,.playlist-library-dot');if(!button||button.classList.contains('spotify'))return;event.preventDefault();event.stopPropagation();const track=playlistAllTracks.find(item=>String(item.file_path)===String(button.dataset.path));searchPlaylistQueryInLibrary(button.dataset.query,track||null)});document.addEventListener('click',async event=>{const button=event.target.closest('.playlist-replace-missing');if(!button)return;event.preventDefault();event.stopPropagation();const track=playlistAllTracks.find(item=>String(item.file_path)===String(button.dataset.path));if(track)try{await replacePlaylistMissingFromLibrary(track)}catch(error){toast(error.message)}});$('#playlist-search').addEventListener('input',applyPlaylistFilters);$$('#playlist-filters input, #playlist-filters select').forEach(input=>input.addEventListener('change',applyPlaylistFilters));$('#playlist-clear').addEventListener('click',()=>{$$('#playlist-filters input').forEach(input=>input.value='');$('#playlist-macro-genre').value='';if(typeof updateFolderGenreOptions==='function')updateFolderGenreOptions('#playlist-macro-genre','#playlist-folder-genre');$('#playlist-folder-genre').value='';$('#playlist-key').value='';$('#playlist-genre').value='';if(window.refreshCompactMultiSelects)refreshCompactMultiSelects();applyPlaylistFilters()});

function runPlaylistLibraryAction(sourceSelector,playlistButton){const source=$(sourceSelector);if(!source){toast('Azione Libreria non disponibile');return}playlistButton.disabled=true;source.click();const timer=setInterval(()=>{playlistButton.title=source.title;playlistButton.setAttribute('aria-label',source.getAttribute('aria-label')||source.title||'Azione playlist');playlistButton.disabled=source.disabled;if(source.innerHTML&&window.setGlobalActionIcon)playlistButton.innerHTML=source.innerHTML;else playlistButton.textContent=source.textContent;if(!source.disabled){clearInterval(timer);updatePlaylistSpotifyActions()}},200)}
const playlistOnlyWorkButton=document.createElement('button');playlistOnlyWorkButton.type='button';playlistOnlyWorkButton.className='button ghost';playlistOnlyWorkButton.textContent='Solo da lavorare';$('#playlist-complete')?.after(playlistOnlyWorkButton);playlistOnlyWorkButton.addEventListener('click',()=>{playlistOnlyWork=!playlistOnlyWork;playlistOnlyWorkButton.textContent=playlistOnlyWork?'Mostra tutti i brani':'Solo da lavorare';playlistOnlyWorkButton.classList.toggle('accent',playlistOnlyWork);applyPlaylistFilters()});
$('#playlist-force-spotify').addEventListener('click',event=>runPlaylistLibraryAction('#force-spotify-list',event.currentTarget));
$('#playlist-identify-spotify').addEventListener('click',event=>runPlaylistLibraryAction('#identify-spotify-features',event.currentTarget));
$('#playlist-bulk-spotify').addEventListener('click',event=>runPlaylistLibraryAction('#bulk-spotify-features',event.currentTarget));
$('#playlist-send-to-spotify').addEventListener('click',()=>$('#send-library-to-spotify').click());
$('#playlist-bulk-replace-missing').addEventListener('click',event=>replaceAllPlaylistMissingFromLibrary(event.currentTarget));
$('#playlist-color-vdj')?.addEventListener('click',async event=>{
  const button=event.currentTarget,tracks=playlistAllTracks.filter(track=>Number(track.id)>0&&track._playlist_exists);
  if(!tracks.length){toast('Nessun brano fisico della playlist da colorare');return}
  try{
    const status=await api('vdj-control-status');
    if(!status.online){toast('VirtualDJ Network Control non disponibile');return}
    if(!window.confirm(`Applicare i colori KR Desk a ${tracks.length} brani della playlist?`))return;
    button.disabled=true;krProgress.start('Colori KR Desk in VirtualDJ',tracks.length,'Verifica percorso e applicazione colore');
    let completed=0,errors=0;
    for(let index=0;index<tracks.length;index++){
      const track=tracks[index];krProgress.update('Colori KR Desk in VirtualDJ',index+1,tracks.length,`${track.artist||''} - ${track.title||''}`);
      try{await post('vdj-kr-color',{id:Number(track.id)});completed++}catch(error){errors++}
    }
    krProgress.done('Colorazione VDJ completata',`${completed} colorati${errors?` - ${errors} errori`:''}`);
    toast(`${completed} brani colorati in VirtualDJ${errors?` - ${errors} errori`:''}`);
  }catch(error){krProgress.fail('Colorazione VDJ fallita',error.message);toast(error.message)}
  finally{button.disabled=false}
});
document.addEventListener('click',event=>{const link=event.target.closest('.playlist-library-dot.spotify');if(!link)return;event.preventDefault();event.stopImmediatePropagation();const row=link.closest('.track-row');const track=playlistAllTracks.find(item=>String(item.file_path)===String(row?.dataset.playlistPath));if(!track)return;preparePlaylistScrollRestore();const spotifyWindow=window.open('about:blank','vdjdesk_playlist_spotify');post('spotify-clipboard-lookup-start',{}).then(()=>{if(spotifyWindow){spotifyWindow.location.href=link.href;spotifyWindow.focus()}const timer=setInterval(async()=>{try{const result=await api('playlist-spotify-clipboard-status');if(result.pending)return;clearInterval(timer);spotifyWindow?.close();if(result.expired){toast('Verifica Spotify scaduta');restorePlaylistScroll();return}await post('playlist-spotify-attach',{artist:track.artist||'',title:track.title||'',spotify_id:result.spotify_id,spotify_url:result.spotify_url});toast('Spotify ID aggiornato nella riga playlist');playlistScrollRestorePending=true;await openPlaylist($('#playlist-select').value)}catch(error){clearInterval(timer);spotifyWindow?.close();restorePlaylistScroll();toast(error.message)}},1200)}).catch(error=>{spotifyWindow?.close();restorePlaylistScroll();toast(error.message)})},true);
$('#playlist-results').addEventListener('click',async event=>{const link=event.target.closest('.track-row .spotmate-link');if(!link)return;event.preventDefault();event.stopImmediatePropagation();const row=link.closest('.track-row');const track=playlistAllTracks.find(item=>String(item.file_path)===String(row?.dataset.playlistPath));if(!track||!Number(track.id)){toast('Record Playlist non disponibile');return}preparePlaylistScrollRestore();try{const spotifyUrl=track.spotify_url||`https://open.spotify.com/track/${track.spotify_id}`;await navigator.clipboard.writeText(spotifyUrl);await post('playlist-spotmate-start',{file:$('#playlist-select').value,id:Number(track.id),old_path:track.file_path});const spotmateWindow=window.open(link.href,'vdjdesk_playlist_spotmate');spotmateWindow?.focus();toast('Link Spotify copiato: incollalo in SpotMate. Download monitorato');const timer=setInterval(async()=>{try{const result=await api('playlist-spotmate-status');if(result.pending)return;clearInterval(timer);spotmateWindow?.close();toast(result.replaced?'Download associato al record Playlist':'Download non rilevato');playlistScrollRestorePending=true;await openPlaylist($('#playlist-select').value)}catch(error){clearInterval(timer);spotmateWindow?.close();restorePlaylistScroll();toast(error.message)}},1500)}catch(error){restorePlaylistScroll();toast(error.message)}},true);
if(location.hash==='#playlists'){playlistScrollRestorePending=true;setTimeout(loadPlaylists,500);setTimeout(()=>$('#view-title').textContent='Playlist',100)}
document.addEventListener('pointerdown',event=>{if(event.target.closest('#view-playlists'))rememberPlaylistScroll()},true);
window.addEventListener('scroll',()=>{if(location.hash==='#playlists'&&!playlistScrollRestorePending)rememberPlaylistScroll()},{passive:true});
window.addEventListener('focus',()=>{if(location.hash==='#playlists')restorePlaylistScroll()});

async function startPlaylistSpotifyAttach(link){const row=link.closest('.track-row');const track=playlistAllTracks.find(item=>String(item.file_path)===String(row?.dataset.playlistPath));if(!track)return;preparePlaylistScrollRestore();const spotifyWindow=window.open('about:blank','vdjdesk_playlist_spotify');try{await post('spotify-clipboard-lookup-start',{});if(spotifyWindow){spotifyWindow.location.href=link.href;spotifyWindow.focus()}const timer=setInterval(async()=>{const result=await api('spotify-clipboard-lookup-status');if(result.pending)return;clearInterval(timer);spotifyWindow?.close();if(result.expired){toast('Verifica Spotify scaduta');restorePlaylistScroll();return}await post('playlist-spotify-attach',{artist:track.artist||'',title:track.title||'',spotify_id:result.spotify_id,spotify_url:result.spotify_url});toast('Spotify ID aggiornato nel database');playlistScrollRestorePending=true;await openPlaylist($('#playlist-select').value)},1200)}catch(error){spotifyWindow?.close();restorePlaylistScroll();toast(error.message)}}
