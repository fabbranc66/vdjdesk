let externalCompareResult=null;
let externalCompareFilter='missing';
let externalCompareSort={field:'position',direction:1};
let externalRawItems=[];
let externalFolderMatchResult=null;
let externalImportedName='playlist_importata';

function externalFlattenJson(data){
  if(Array.isArray(data))return data;
  if(data&&typeof data==='object'){
    for(const key of ['tracks','items','data','playlist','songs']){
      if(Array.isArray(data[key]))return data[key];
    }
  }
  return [];
}

function externalStats(result){
  $('#playlist-integrator-stats').innerHTML=`<span>Totale: <b>${result.total}</b></span><span class="ok">Già presenti: <b>${result.present}</b></span><span class="warn">Dubbi: <b>${result.doubtful}</b></span><span class="missing">Da scaricare: <b>${result.missing}</b></span>`;
  $('#playlist-integrator-actions').classList.remove('hidden');
}

function externalSortValue(item,field){
  if(field==='artist')return String(item.artist||'').toLocaleLowerCase('it');
  if(field==='title')return String(item.title||'').toLocaleLowerCase('it');
  if(field==='reason')return String(item.reason||'').toLocaleLowerCase('it');
  if(field==='duration')return Number(item.duration||0);
  return Number(item.position||0);
}

function externalSortedItems(items){
  const {field,direction}=externalCompareSort;
  return [...items].sort((a,b)=>{
    const left=externalSortValue(a,field),right=externalSortValue(b,field);
    return (typeof left==='string'?left.localeCompare(right,'it',{numeric:true,sensitivity:'base'}):left-right)*direction;
  });
}

function externalSortLabel(){
  const labels={position:'posizione JSON',artist:'artista',title:'titolo',duration:'durata',reason:'motivo'};
  return `${labels[externalCompareSort.field]||externalCompareSort.field} ${externalCompareSort.direction>0?'↑':'↓'}`;
}

function externalRender(filter='missing'){
  externalCompareFilter=filter;
  const target=$('#playlist-integrator-results');
  if(!externalCompareResult){target.innerHTML='Carica un JSON per iniziare.';return}
  const source=filter==='all'?['present','doubtful','missing'].flatMap(status=>externalCompareResult.items?.[status]||[]):externalCompareResult.items?.[filter]||[];
  const items=externalSortedItems(source);
  const labels={all:'Playlist completa',missing:'Da scaricare',present:'Già presenti',doubtful:'Dubbi'};
  target.classList.remove('empty-state');
  target.innerHTML=items.length
    ? `<div class="external-list-head"><div><strong>${labels[filter]} · ${items.length}</strong><small>${filter==='all'?'Tutti i brani importati, nello stesso ordine del JSON.':filter==='missing'?'JSON finale dei brani da scaricare.':filter==='doubtful'?'Controllo manuale: non li considero mancanti sicuri.':'Questi non vanno riscaricati.'}</small></div><small>Ordine: ${escapeHtml(externalSortLabel())}</small></div><div class="external-sortbar"><button type="button" data-external-sort="position">Pos. JSON</button><button type="button" data-external-sort="artist">Artista</button><button type="button" data-external-sort="title">Titolo</button><button type="button" data-external-sort="duration">Durata</button><button type="button" data-external-sort="reason">Motivo</button></div>${items.map(item=>externalRow(item,filter==='all'?(item.status||'missing'):filter)).join('')}`
    : `<div class="empty-state">Nessun brano in ${labels[filter].toLowerCase()}.</div>`;
}

function externalRow(item,filter){
  const spotify=externalSpotifyUrl(item);
  const match=(item.matches||[])[0];
  const matchHtml=match?`<small class="external-match">Match: ${escapeHtml(match.artist||'')} — ${escapeHtml(match.title||'')} · ${escapeHtml(match.file_path||'')}</small>`:'';
  const actions=filter==='missing'?`<div class="external-actions">${spotify?`<a class="button ghost" target="vdjdesk_spotify" href="${escapeHtml(spotify)}">Apri Spotify</a>`:''}</div>`:'';
  const titleDots=filter==='doubtful'?externalTitleDots(item):'';
  return `<article class="external-row ${filter}" data-spotify-id="${escapeHtml(item.spotify_id||'')}"><div class="external-main"><div class="external-title-line"><b>${escapeHtml(item.artist||'Artista sconosciuto')} — ${escapeHtml(item.title||'Titolo mancante')}</b>${titleDots}</div><small>${escapeHtml(item.reason||'')}</small>${matchHtml}<div class="external-meta"><span>#${escapeHtml(String(item.position||''))}</span>${item.spotify_id?`<span>ID ${escapeHtml(item.spotify_id)}</span>`:''}${item.isrc?`<span>ISRC ${escapeHtml(item.isrc)}</span>`:''}${item.duration?`<span>${formatDuration(item.duration)}</span>`:''}</div></div>${actions}</article>`;
}

function externalQuery(item){return [item.artist,item.title].filter(Boolean).join(' ').trim()}
function externalSpotifyUrl(item){return item.trackLink||item.spotify_url||(item.spotify_id?`https://open.spotify.com/track/${encodeURIComponent(item.spotify_id)}`:(externalQuery(item)?`https://open.spotify.com/search/${encodeURIComponent(externalQuery(item))}`:''))}
function externalTitleDots(item){
  const query=externalQuery(item);
  const spotify=externalSpotifyUrl(item);
  const match=(item.matches||[])[0]||{};
  const trackId=Number(match.id||0);
  const spotifyButton=spotify
    ? `<button type="button" class="external-title-dot spotify" data-external-spotify-acquire="${trackId}" data-external-spotify-url="${escapeHtml(spotify)}" title="Cerca su Spotify e verifica se esiste in KR Desk">S</button>`
    : (spotify?`<a class="external-title-dot spotify" target="vdjdesk_spotify" href="${escapeHtml(spotify)}" title="Cerca/apri su Spotify">S</a>`:'');
  const spotmatePayload=encodeURIComponent(JSON.stringify({track_id:trackId,query,spotify_url:item.trackLink||item.spotify_url||(item.spotify_id?`https://open.spotify.com/track/${item.spotify_id}`:''),artist:item.artist||'',title:item.title||''}));
  return `<span class="external-title-actions">${spotifyButton}<button type="button" class="external-title-dot spotmate" data-external-spotmate="${spotmatePayload}" title="Copia link/query e apri SpotMate">M</button><button type="button" class="external-title-dot kr" data-external-kr-search="${escapeHtml(query)}" title="Cerca in Libreria KR Desk">K</button></span>`;
}

let externalSpotifyClipboardTimer=null;
async function externalAcquireSpotifyId(button){
  const trackId=Number(button.dataset.externalSpotifyAcquire||0);
  const url=button.dataset.externalSpotifyUrl||'';
  if(!url){window.open('https://open.spotify.com/search','vdjdesk_spotify','noopener');return}
  const spotifyWindow=window.open('about:blank','vdjdesk_spotify');
  button.disabled=true;
  try{
    await post('spotify-clipboard-lookup-start',{});
    toast('Appunti azzerati - copia il link Spotify per verificare KR Desk');
    if(spotifyWindow){spotifyWindow.location.href=url;spotifyWindow.focus()}
    clearInterval(externalSpotifyClipboardTimer);
    externalSpotifyClipboardTimer=setInterval(async()=>{
      try{
        const result=await api('spotify-clipboard-lookup-status');
        if(result.pending)return;
        clearInterval(externalSpotifyClipboardTimer);externalSpotifyClipboardTimer=null;button.disabled=false;
        spotifyWindow?.close();window.focus();
        if(result.expired){toast('Verifica Spotify scaduta');return}
        externalResolveDoubtfulTrack(trackId,result);
        toast(result.found?'Spotify ID già in KR Desk - spostato nei presenti':'Spotify ID non presente - spostato nei da scaricare');
      }catch(error){clearInterval(externalSpotifyClipboardTimer);externalSpotifyClipboardTimer=null;button.disabled=false;toast(error.message)}
    },1200);
  }catch(error){button.disabled=false;spotifyWindow?.close();toast(error.message)}
}

function externalParseM3u(text){
  const items=[];let metadata={};
  for(const rawLine of String(text||'').split(/\r?\n/)){
    const line=rawLine.trim();
    if(!line)continue;
    if(line.toUpperCase().startsWith('#EXTVDJ:')){
      const artist=line.match(/<artist>([\s\S]*?)<\/artist>/i)?.[1]||'';
      const title=line.match(/<title>([\s\S]*?)<\/title>/i)?.[1]||'';
      const duration=line.match(/<songlength>([\d.]+)/i)?.[1]||'';
      metadata={artist,title,duration};continue;
    }
    if(line.startsWith('#'))continue;
    items.push({...metadata,path:line,position:items.length+1});metadata={};
  }
  return items;
}

function externalResolveDoubtfulTrack(trackId,result){
  if(!externalCompareResult?.items?.doubtful)return;
  const index=externalCompareResult.items.doubtful.findIndex(item=>(item.matches||[]).some(match=>Number(match.id)===Number(trackId)));
  if(index<0)return;
  const item=externalCompareResult.items.doubtful.splice(index,1)[0];
  item.spotify_id=result.spotify_id||item.spotify_id||'';
  item.spotify_url=result.spotify_url||item.spotify_url||item.trackLink||'';
  item.trackLink=item.spotify_url;
  if(result.found&&result.track){
    item.status='present';
    item.reason='Spotify ID verificato in KR Desk';
    item.matches=[result.track];
    externalCompareResult.items.present.push(item);
    externalCompareResult.present=Number(externalCompareResult.present||0)+1;
  }else{
    item.status='missing';
    item.reason='Spotify ID non presente in KR Desk';
    item.matches=[];
    externalCompareResult.items.missing.push(item);
    externalCompareResult.missing=Number(externalCompareResult.missing||0)+1;
  }
  externalCompareResult.doubtful=Math.max(0,Number(externalCompareResult.doubtful||0)-1);
  externalStats(externalCompareResult);
  externalRender(externalCompareFilter);
}

async function externalOpenSpotmate(payload){
  const text=payload.spotify_url||payload.query||[payload.artist,payload.title].filter(Boolean).join(' ');
  if(text&&navigator.clipboard)await navigator.clipboard.writeText(text);
  window.open('https://spotmate.online/premium','vdjdesk_spotmate','noopener');
  toast(text?'Copiato per SpotMate':'SpotMate aperto');
}

async function externalSearchKrDesk(query){
  $('#library-search').value=query||'';
  state.libraryExtraFilters={};
  showView('library');
  await loadTracks(true,false);
}

async function externalLoadFile(file){
  const text=await file.text();
  const items=/\.m3u8?$/i.test(file.name||'')?externalParseM3u(text):externalFlattenJson(JSON.parse(text));
  if(!items.length)throw new Error('JSON valido ma nessuna lista brani trovata.');
  $('#playlist-integrator-results').classList.add('empty-state');
  $('#playlist-integrator-results').textContent=`Confronto ${items.length} righe con la Libreria Definitiva…`;
  const result=await post('playlist-external-compare',{items});
  externalRawItems=items;
  externalImportedName=String(file.name||'playlist_importata').replace(/\.json$/i,'').trim()||'playlist_importata';
  externalCompareResult=result;
  externalFolderMatchResult=null;
  externalCompareSort={field:'position',direction:1};
  externalStats(result);
  $('#playlist-folder-bridge').classList.remove('hidden');
  $('#playlist-folder-match-results').classList.add('hidden');
  $('#external-apply-safe').disabled=true;
  await externalLoadFolders();
  externalRender('missing');
  toast(`JSON confrontato · ${result.missing} da scaricare · ${result.present} già presenti · ${result.doubtful} dubbi`);
}

async function externalCreatePlaylist(){
  if(!externalRawItems.length){toast('Carica prima un JSON');return}
  const name=window.prompt('Nome della playlist da creare',externalImportedName);
  if(name===null||!name.trim())return;
  const items=externalRawItems;
  const button=$('#external-create-playlist');
  button.disabled=true;button.textContent='Creo playlist...';
  try{
    const result=await post('playlist-external-create',{name:name.trim(),items});
    toast(`Playlist creata: ${result.tracks}/${result.total} brani · ${result.unavailable} senza file fisico`);
    showView('playlists');
    await loadPlaylists();
    $('#playlist-select').value=result.relative;
    await openPlaylist(result.relative);
  }catch(error){toast(error.message)}
  finally{button.disabled=false;button.textContent='Crea in Playlist'}
}

async function externalLoadFolders(){
  const select=$('#external-folder-select');
  if(!select||select.dataset.loaded==='1')return;
  const data=await api('definitive-library-folders');
  select.innerHTML='<option value="">Scegli cartella…</option>'+data.items.map(item=>`<option value="${escapeHtml(item.path)}">${escapeHtml(item.tree_label||item.label||item.path)}</option>`).join('');
  select.dataset.loaded='1';
}

function externalRenderFolderMatches(){
  const target=$('#playlist-folder-match-results');
  if(!externalFolderMatchResult){target.classList.add('hidden');return}
  target.classList.remove('hidden','empty-state');
  const safe=externalFolderMatchResult.items?.safe||[];
  const doubtful=externalFolderMatchResult.items?.doubtful||[];
  const unmatched=externalFolderMatchResult.items?.unmatched||[];
  target.innerHTML=`<div class="external-list-head"><div><strong>Congruenza cartella · ${externalFolderMatchResult.tracks_in_folder} brani</strong><small>Sicuri ${safe.length} · dubbi ${doubtful.length} · non trovati ${unmatched.length}</small></div></div>${safe.length?`<h3 class="external-subtitle">Applicabili sicuri</h3>${safe.map(item=>externalFolderRow(item,'safe')).join('')}`:''}${doubtful.length?`<h3 class="external-subtitle">Dubbi non applicati</h3>${doubtful.slice(0,80).map(item=>externalFolderRow(item,'doubtful')).join('')}`:''}${unmatched.length?`<h3 class="external-subtitle">Non trovati in cartella</h3>${unmatched.slice(0,80).map(item=>externalFolderRow(item,'unmatched')).join('')}`:''}`;
  $('#external-apply-safe').disabled=safe.length===0;
}

function externalFolderRow(item,status){
  const entry=item.entry||{};
  const track=item.track||{};
  const trackText=track.id?`${track.artist||''} — ${track.title||''}`:'—';
  const path=track.file_path||'';
  return `<article class="external-row ${status}"><div class="external-main"><b>${escapeHtml(entry.artist||'')} — ${escapeHtml(entry.title||'')}</b><small>${escapeHtml(item.reason||'')}</small><small class="external-match">KR: ${escapeHtml(trackText)}${path?` · ${escapeHtml(path)}`:''}</small><div class="external-meta">${entry.spotify_id?`<span>ID ${escapeHtml(entry.spotify_id)}</span>`:''}${entry.isrc?`<span>ISRC ${escapeHtml(entry.isrc)}</span>`:''}${entry.duration?`<span>JSON ${formatDuration(entry.duration)}</span>`:''}${track.duration?`<span>KR ${formatDuration(track.duration)}</span>`:''}<span>${Number(item.confidence||0)}%</span></div></div></article>`;
}

async function externalMatchFolder(){
  if(!externalRawItems.length){toast('Carica prima un JSON');return}
  const folder=$('#external-folder-select').value;
  if(!folder){toast('Scegli la cartella scaricata');return}
  const button=$('#external-folder-match');
  button.disabled=true;button.textContent='Verifica…';
  try{
    externalFolderMatchResult=await post('playlist-external-folder-match',{items:externalRawItems,folder});
    externalRenderFolderMatches();
    toast(`Match cartella · ${externalFolderMatchResult.safe} sicuri · ${externalFolderMatchResult.doubtful} dubbi`);
  }catch(error){toast(error.message)}
  finally{button.disabled=false;button.textContent='Verifica congruenza'}
}

async function externalApplySafe(){
  const safe=externalFolderMatchResult?.items?.safe||[];
  if(!safe.length)return;
  if(!window.confirm(`Applicare spotify_id/link/ISRC/album a ${safe.length} brani sicuri?`))return;
  const button=$('#external-apply-safe');
  button.disabled=true;button.textContent='Applico…';
  try{
    const matches=safe.map(item=>({track_id:item.track.id,entry:item.entry,confidence:item.confidence,reason:item.reason}));
    const result=await post('playlist-external-apply-metadata',{matches});
    toast(`${result.applied} brani aggiornati da JSON${result.skipped?` · ${result.skipped} saltati`:''}`);
  }catch(error){toast(error.message)}
  finally{button.disabled=false;button.textContent='Applica metadati sicuri'}
}

function externalExportMissing(){
  if(!externalCompareResult)return;
  const items=externalSortedItems(externalCompareResult.items?.missing||[]).map(item=>({
    platform:item.platform||'spotify',
    type:'track',
    id:item.spotify_id||'',
    title:item.title||'',
    artist:item.artist||'',
    album:item.album||'',
    isrc:item.isrc||'',
    duration:item.duration?String(item.duration):'',
    trackLink:item.trackLink||'',
    position:String(item.position||'')
  }));
  const blob=new Blob([JSON.stringify(items,null,2)],{type:'application/json;charset=utf-8'});
  const link=document.createElement('a');
  link.href=URL.createObjectURL(blob);
  link.download='spotify_mancanti_libreria_definitiva.json';
  link.click();
  URL.revokeObjectURL(link.href);
}

document.addEventListener('change',event=>{
  const input=event.target.closest('#playlist-json-input');
  if(!input||!input.files?.[0])return;
  externalLoadFile(input.files[0]).catch(error=>toast(error.message));
});

document.addEventListener('click',event=>{
  const filter=event.target.closest('[data-external-filter]');
  if(filter){externalRender(filter.dataset.externalFilter);return}
  const sort=event.target.closest('[data-external-sort]');
  if(sort){
    const field=sort.dataset.externalSort;
    externalCompareSort=field===externalCompareSort.field?{field,direction:externalCompareSort.direction*-1}:{field,direction:1};
    externalRender(externalCompareFilter);
    return;
  }
  if(event.target.closest('#external-export-missing'))externalExportMissing();
  if(event.target.closest('#external-create-playlist'))externalCreatePlaylist();
  if(event.target.closest('#external-folder-match'))externalMatchFolder();
  if(event.target.closest('#external-apply-safe'))externalApplySafe();
  const spotifyAcquire=event.target.closest('[data-external-spotify-acquire]');
  if(spotifyAcquire){externalAcquireSpotifyId(spotifyAcquire);return}
  const spotmate=event.target.closest('[data-external-spotmate]');
  if(spotmate){
    try{externalOpenSpotmate(JSON.parse(decodeURIComponent(spotmate.dataset.externalSpotmate||'%7B%7D')))}
    catch(error){toast(error.message)}
  }
  const krSearch=event.target.closest('[data-external-kr-search]');
  if(krSearch)externalSearchKrDesk(krSearch.dataset.externalKrSearch);
});
