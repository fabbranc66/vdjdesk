let playlistAllTracks=[];
let playlistOriginalTracks=[];
let playlistSort={field:'artist',direction:1};
let playlistDraggedPath='';
const playlistBaseRenderTracks=renderTracks;

async function loadPlaylists(){
  const select=$('#playlist-select');
  select.innerHTML='<option value="">Lettura playlist...</option>';
  try{
    const data=await api('playlists');
    $('#playlist-root').textContent=data.root;
    select.innerHTML=data.items.length?data.items.map(item=>`<option value="${escapeHtml(item.relative)}">${escapeHtml(item.name)} - ${item.tracks} brani</option>`).join(''):'<option value="">Nessuna playlist disponibile</option>';
    if(data.items.length)await openPlaylist(data.items[0].relative);else $('#playlist-results').innerHTML='<div class="empty-state panel">Nessuna playlist disponibile.</div>';
  }catch(error){$('#playlist-results').innerHTML=`<div class="empty-state panel">${escapeHtml(error.message)}</div>`}
}

async function openPlaylist(relative){
  $('#playlist-results').innerHTML='<div class="empty-state">Lettura brani...</div>';
  const data=await api(`playlist-detail&file=${encodeURIComponent(relative)}`);
  playlistAllTracks=[...data.items];
  playlistOriginalTracks=[...data.items];
  $('#playlist-title').textContent=data.file;
  applyPlaylistFilters();
}

function playlistLibraryQuery(track){return [track.artist,track.title].filter(Boolean).join(' ')||String(track.file_name||track.file_path||'').replace(/\.[^.]+$/,'')}
function playlistLibrarySearchQuery(track){
  return playlistLibraryQuery(track).replace(/['’`]/g,' ').replace(/[^A-Za-z0-9À-ÿ]+/g,' ').replace(/\s+/g,' ').trim();
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
  const query=$('#playlist-search').value.trim().toLocaleLowerCase('it'),macro=$('#playlist-macro-genre')?.value||'',folderGenre=$('#playlist-folder-genre')?.value||'',bpm=Number($('#playlist-bpm').value||0),key=$('#playlist-key').value.trim().toLocaleLowerCase('it'),genre=$('#playlist-genre').value.trim().toLocaleLowerCase('it'),year=Number($('#playlist-year').value||0),quality=$('#playlist-quality').value;
  state.tracks=playlistAllTracks.filter(track=>{
    const text=`${track.artist||''} ${track.title||''} ${track.file_name||''}`.toLocaleLowerCase('it');
    const extension=String(track.file_name||track.file_path||'').split('.').pop().toLowerCase(),bitrate=Number(track.bitrate||0);
    const standard=(extension==='mp3'&&bitrate>=320)||['flac','wav','aiff','aif','alac'].includes(extension);
    const video=['mp4','mkv','avi','mov','webm','m4v','wmv','mpeg','mpg'].includes(extension);
    return(!query||text.includes(query))&&(!macro||String(track.macro_genre||'')===macro)&&(!folderGenre||String(track.folder_genre||'')===folderGenre)&&(!bpm||Math.abs(Number(track.bpm||0)-bpm)<=8)&&(!key||String(track.camelot||track.musical_key||'').toLocaleLowerCase('it').includes(key))&&(!genre||String(track.genre||'').toLocaleLowerCase('it').includes(genre))&&(!year||Number(track.year)===year)&&(!quality||(quality==='standard'?standard:quality==='below'?!standard&&!video:video));
  });
  renderTracks();
}

function updatePlaylistSpotifyActions(){
  const visible=state.tracks.filter(track=>Number(track.id)>0),withId=visible.filter(track=>track.spotify_id),pending=withId.filter(track=>['never','error'].includes(track.spotify_features_status)||(!track.spotify_features_status&&!track.spotify_features_updated_at)),unidentified=visible.filter(track=>!track.spotify_id);
  const bulk=$('#playlist-bulk-spotify'),identify=$('#playlist-identify-spotify'),force=$('#playlist-force-spotify'),send=$('#playlist-send-to-spotify');
  if(window.setGlobalActionIcon){
    if(bulk&&!bulk.disabled)setGlobalActionIcon(bulk,krIcon.metrics,'Recupera metriche Spotify per la playlist visibile',`${pending.length}/${withId.length}`);
    if(identify&&!identify.disabled)setGlobalActionIcon(identify,krIcon.spotify,'Trova Spotify ID per la playlist visibile',`${unidentified.length} mancanti`);
    setGlobalActionIcon(force,krIcon.force,'Forza ricerca Spotify ID sulla playlist visibile');
    setGlobalActionIcon(send,krIcon.export,'Porta in Spotify to VDJ solo i brani visibili con metriche Spotify');
  }else{
    if(bulk&&!bulk.disabled)bulk.textContent=`Metriche Spotify ${pending.length}/${withId.length}`;
    if(identify&&!identify.disabled)identify.textContent=`Trova Spotify ID ${unidentified.length}`;
  }
}


async function replacePlaylistMissingFromLibrary(track){
  const file=$('#playlist-select').value;
  if(!file)return;
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
  if(!candidates.length){toast(`Nessun candidato in E per: ${queries[0]?.label||track.file_path}`);return}
  let chosen=candidates[0];
  if(candidates.length>1){
    const message=candidates.slice(0,5).map((item,index)=>`${index+1}) ${item.artist||''} - ${item.title||''} | ${item.file_path}`).join('\n');
    const answer=window.prompt(`Scegli candidato da usare:\n${message}`, '1');
    if(answer===null)return;
    const index=Math.max(1,Math.min(5,Number(answer)||1))-1;
    chosen=candidates[index]||candidates[0];
  }
  if(!window.confirm(`Sostituire nella playlist?\n\nDA: ${track.file_path}\n\nA: ${chosen.file_path}`))return;
  const result=await post('playlist-replace-track',{file,old_path:track.file_path,new_path:chosen.file_path});
  toast(`${result.replaced} riferimento playlist sostituito`);
  await openPlaylist(file);
}

function renderPlaylistTable(){
  const target=$('#playlist-results');
  $('#playlist-count').textContent=`${state.tracks.length} di ${playlistAllTracks.length}`;
  target.innerHTML=state.tracks.length?state.tracks.map((track,index)=>`<article class="track-row ${track._playlist_exists?'':'playlist-missing'}" draggable="true" data-id="${track.id}" data-playlist-index="${index}" data-playlist-path="${escapeHtml(track.file_path)}"><div class="track-identity"><strong>${escapeHtml(track.artist||'Artista sconosciuto')} - ${escapeHtml(track.title)} <button type="button" class="playlist-library-dot" data-query="${escapeHtml(playlistLibrarySearchQuery(track))}" data-path="${escapeHtml(track.file_path)}" title="Cerca questo brano nella libreria completa E" aria-label="Cerca in libreria E">E</button></strong><small title="${escapeHtml(track.file_path)}">${escapeHtml(track.file_path)}</small></div><div><span class="cell-label">BPM</span><span class="cell-value">${track.bpm??'-'}</span></div><div><span class="cell-label">KEY / SCALA</span><span class="cell-value">${escapeHtml(track.camelot||track.musical_key||'-')} ${scaleMode(track)}</span></div><div class="hide-mobile"><span class="cell-label">DURATA</span><span class="cell-value">${formatDuration(track.duration)}</span></div><div class="hide-tablet hide-mobile"><span class="cell-label">GENERE / ANNO</span><span class="cell-value">${escapeHtml(track.folder_genre||track.genre||'-')} / ${escapeHtml(track.genre||'-')} - ${track.year||'-'}</span></div><div class="track-tags hide-mobile">${track.version?`<span class="badge blue">${escapeHtml(track.version)}</span>`:''}${(track.tags||[]).slice(0,2).map(tag=>`<span class="badge">${escapeHtml(tag)}</span>`).join('')}</div><div class="track-actions">${track.id?'<button class="more-button">...</button><div class="action-menu"><button data-action="edit">Tag e punteggi</button><button data-action="played">Segna come suonato</button><button data-action="queue">Aggiungi alla coda</button><button type="button" class="playlist-remove-row">Rimuovi da playlist</button></div>':`<button type="button" class="button ghost playlist-replace-missing" data-path="${escapeHtml(track.file_path)}">Sostituisci da E</button><button type="button" class="button ghost playlist-remove-row">Rimuovi</button>`}</div></article>`).join(''):'<div class="empty-state panel">Nessun brano corrisponde ai filtri.</div>';
  if(typeof decorateSpotifyTracks==='function')decorateSpotifyTracks();
  updatePlaylistSpotifyActions();
}

function reorderPlaylist(compare){playlistAllTracks.sort(compare);applyPlaylistFilters()}
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
document.addEventListener('click',async event=>{if(event.target.closest('[data-view="playlists"]')){$('#view-title').textContent='Playlist';loadPlaylists()}if(event.target.closest('[data-view="library"]'))loadTracks(true,false);const sort=event.target.closest('#playlist-sort-header [data-sort]');if(sort){const field=sort.dataset.sort;playlistSort=field===playlistSort.field?{field,direction:playlistSort.direction*-1}:{field,direction:1};const {direction}=playlistSort;reorderPlaylist((a,b)=>{const left=playlistSortValue(a,field),right=playlistSortValue(b,field);return(typeof left==='string'?left.localeCompare(right,'it',{numeric:true,sensitivity:'base'}):left-right)*direction})}if(event.target.closest('#playlist-original')){playlistAllTracks=[...playlistOriginalTracks];$('#playlist-camelot-debug').innerHTML='';applyPlaylistFilters()}if(event.target.closest('#playlist-bpm-up'))reorderPlaylist((a,b)=>Number(a.bpm||999)-Number(b.bpm||999));if(event.target.closest('#playlist-bpm-down'))reorderPlaylist((a,b)=>Number(b.bpm||0)-Number(a.bpm||0));if(event.target.closest('#playlist-camelot-strict'))buildCamelotOrder('strict');if(event.target.closest('#playlist-camelot-soft'))buildCamelotOrder('soft');if(event.target.closest('#playlist-genre-bpm'))reorderPlaylist((a,b)=>playlistSortValue(a,'genre').localeCompare(playlistSortValue(b,'genre'),'it')||Number(a.bpm||0)-Number(b.bpm||0));if(event.target.closest('#playlist-save')){const file=$('#playlist-select').value;if(!file)return;const result=await post('playlist-save-order',{file,paths:playlistAllTracks.map(track=>track.file_path)});playlistOriginalTracks=[...playlistAllTracks];toast(`${result.tracks} brani salvati - playlist VDJ aggiornata`)}});
$('#playlist-results').addEventListener('dragstart',event=>{const row=event.target.closest('[data-playlist-path]');if(!row)return;playlistDraggedPath=row.dataset.playlistPath;row.classList.add('dragging')});$('#playlist-results').addEventListener('dragend',event=>{event.target.closest('.track-row')?.classList.remove('dragging');playlistDraggedPath=''});$('#playlist-results').addEventListener('dragover',event=>{if(!playlistDraggedPath)return;event.preventDefault()});$('#playlist-results').addEventListener('drop',event=>{const target=event.target.closest('[data-playlist-path]');if(!target||!playlistDraggedPath||target.dataset.playlistPath===playlistDraggedPath)return;if(state.tracks.length!==playlistAllTracks.length){toast('Azzera i filtri prima del riordino manuale');return}const from=playlistAllTracks.findIndex(track=>track.file_path===playlistDraggedPath),to=playlistAllTracks.findIndex(track=>track.file_path===target.dataset.playlistPath);if(from<0||to<0)return;const [moved]=playlistAllTracks.splice(from,1);playlistAllTracks.splice(to,0,moved);applyPlaylistFilters()});
$('#playlist-results').addEventListener('click',async event=>{const button=event.target.closest('.playlist-remove-row');if(!button)return;event.preventDefault();event.stopPropagation();const row=button.closest('[data-playlist-path]'),file=$('#playlist-select').value;if(!row||!file)return;const index=Number(row.dataset.playlistIndex),path=row.dataset.playlistPath;if(!window.confirm(`Rimuovere questa riga dalla playlist?\n\nIl file audio NON verra cancellato.\n\n${path}`))return;button.disabled=true;try{const result=await post('playlist-remove-track',{file,index,path});toast(`Riga rimossa dalla playlist - ${result.tracks} brani rimasti`);await openPlaylist(file)}catch(error){toast(error.message)}finally{button.disabled=false}});
$('#playlist-select').addEventListener('change',event=>{if(event.target.value)openPlaylist(event.target.value)});$('#playlist-search-button').addEventListener('click',applyPlaylistFilters);$('#playlist-macro-genre')?.addEventListener('change',()=>{if(typeof updateFolderGenreOptions==='function')updateFolderGenreOptions('#playlist-macro-genre','#playlist-folder-genre');applyPlaylistFilters()});$('#playlist-folder-genre')?.addEventListener('change',applyPlaylistFilters);$('#playlist-search-library').addEventListener('click',()=>searchPlaylistQueryInLibrary($('#playlist-search').value));document.addEventListener('click',event=>{if(!event.target.closest('.playlist-search-library-action'))return;searchPlaylistQueryInLibrary($('#playlist-search').value)});document.addEventListener('click',event=>{const button=event.target.closest('.playlist-library-search,.playlist-library-dot');if(!button)return;event.preventDefault();event.stopPropagation();const track=playlistAllTracks.find(item=>String(item.file_path)===String(button.dataset.path));searchPlaylistQueryInLibrary(button.dataset.query,track||null)});document.addEventListener('click',async event=>{const button=event.target.closest('.playlist-replace-missing');if(!button)return;event.preventDefault();event.stopPropagation();const track=playlistAllTracks.find(item=>String(item.file_path)===String(button.dataset.path));if(track)try{await replacePlaylistMissingFromLibrary(track)}catch(error){toast(error.message)}});$('#playlist-search').addEventListener('input',applyPlaylistFilters);$$('#playlist-filters input, #playlist-filters select').forEach(input=>input.addEventListener('change',applyPlaylistFilters));$('#playlist-clear').addEventListener('click',()=>{$$('#playlist-filters input').forEach(input=>input.value='');$('#playlist-macro-genre').value='';if(typeof updateFolderGenreOptions==='function')updateFolderGenreOptions('#playlist-macro-genre','#playlist-folder-genre');$('#playlist-folder-genre').value='';$('#playlist-quality').value='';applyPlaylistFilters()});

function runPlaylistLibraryAction(sourceSelector,playlistButton){const source=$(sourceSelector);if(!source){toast('Azione Libreria non disponibile');return}playlistButton.disabled=true;source.click();const timer=setInterval(()=>{playlistButton.title=source.title;playlistButton.setAttribute('aria-label',source.getAttribute('aria-label')||source.title||'Azione playlist');playlistButton.disabled=source.disabled;if(source.innerHTML&&window.setGlobalActionIcon)playlistButton.innerHTML=source.innerHTML;else playlistButton.textContent=source.textContent;if(!source.disabled){clearInterval(timer);updatePlaylistSpotifyActions()}},200)}
$('#playlist-force-spotify').addEventListener('click',event=>runPlaylistLibraryAction('#force-spotify-list',event.currentTarget));
$('#playlist-identify-spotify').addEventListener('click',event=>runPlaylistLibraryAction('#identify-spotify-features',event.currentTarget));
$('#playlist-bulk-spotify').addEventListener('click',event=>runPlaylistLibraryAction('#bulk-spotify-features',event.currentTarget));
$('#playlist-send-to-spotify').addEventListener('click',()=>$('#send-library-to-spotify').click());
if(location.hash==='#playlists'){setTimeout(loadPlaylists,500);setTimeout(()=>$('#view-title').textContent='Playlist',100)}
