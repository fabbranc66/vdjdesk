let externalCompareResult=null;
let externalCompareFilter='missing';
let externalCompareSort={field:'position',direction:1};
let externalRawItems=[];
let externalFolderMatchResult=null;
let externalImportedName='playlist_importata';
let vdjImportedResult=null;
let vdjImportedItems=[];
let vdjImportedName='playlist_importata';
let vdjImportedFilter='all';
let vdjSpotifyClipboardTimer=null;
let vdjSpotmateTimer=null;
let vdjDirtyPositions=new Set();
window.vdjPlaylistTrackLookup=window.vdjPlaylistTrackLookup||new Map();
window.vdjPlaylistPendingSpotify=window.vdjPlaylistPendingSpotify||new Map();

function cleanVdjPlaylistName(name){
  return String(name||'playlist_importata').replace(/\s*(?:Â·|·)\s*C\s*\+\s*E\s*$/i,'').trim();
}

function addVdjPlaylistUseButtons(){
  if(!window.vdjPlaylistUseContext)return;
  $$('.track-row .track-actions').forEach(actions=>{if(!actions.querySelector('[data-action="vdj-playlist-use"]'))actions.insertAdjacentHTML('afterbegin','<button type="button" class="button accent" data-action="vdj-playlist-use">Usa in playlist</button>')});
}

const vdjLibraryResults=$('#library-results');
if(vdjLibraryResults)new MutationObserver(addVdjPlaylistUseButtons).observe(vdjLibraryResults,{childList:true,subtree:true});

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
  $('#playlist-integrator-stats').innerHTML=`<span>Totale: <b>${result.total}</b></span><span class="ok">GiÃ  presenti: <b>${result.present}</b></span><span class="warn">Dubbi: <b>${result.doubtful}</b></span><span class="missing">Da scaricare: <b>${result.missing}</b></span>`;
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
  return `${labels[externalCompareSort.field]||externalCompareSort.field} ${externalCompareSort.direction>0?'â†‘':'â†“'}`;
}

function externalRender(filter='missing'){
  externalCompareFilter=filter;
  const target=$('#playlist-integrator-results');
  if(!externalCompareResult){target.innerHTML='Carica un JSON per iniziare.';return}
  const source=filter==='all'?['present','doubtful','missing'].flatMap(status=>externalCompareResult.items?.[status]||[]):externalCompareResult.items?.[filter]||[];
  const items=externalSortedItems(source);
  const labels={all:'Playlist completa',missing:'Da scaricare',present:'GiÃ  presenti',doubtful:'Dubbi'};
  target.classList.remove('empty-state');
  target.innerHTML=items.length
    ? `<div class="external-list-head"><div><strong>${labels[filter]} Â· ${items.length}</strong><small>${filter==='all'?'Tutti i brani importati, nello stesso ordine del JSON.':filter==='missing'?'JSON finale dei brani da scaricare.':filter==='doubtful'?'Controllo manuale: non li considero mancanti sicuri.':'Questi non vanno riscaricati.'}</small></div><small>Ordine: ${escapeHtml(externalSortLabel())}</small></div><div class="external-sortbar"><button type="button" data-external-sort="position">Pos. JSON</button><button type="button" data-external-sort="artist">Artista</button><button type="button" data-external-sort="title">Titolo</button><button type="button" data-external-sort="duration">Durata</button><button type="button" data-external-sort="reason">Motivo</button></div>${items.map(item=>externalRow(item,filter==='all'?(item.status||'missing'):filter)).join('')}`
    : `<div class="empty-state">Nessun brano in ${labels[filter].toLowerCase()}.</div>`;
}

function externalRow(item,filter){
  const spotify=externalSpotifyUrl(item);
  const match=(item.matches||[])[0];
  const matchHtml=match?`<small class="external-match">Match: ${escapeHtml(match.artist||'')} â€” ${escapeHtml(match.title||'')} Â· ${escapeHtml(match.file_path||'')}</small>`:'';
  const actions=filter==='missing'?`<div class="external-actions">${spotify?`<a class="button ghost" target="vdjdesk_spotify" href="${escapeHtml(spotify)}">Apri Spotify</a>`:''}</div>`:'';
  const titleDots=filter==='doubtful'?externalTitleDots(item):'';
  return `<article class="external-row ${filter}" data-spotify-id="${escapeHtml(item.spotify_id||'')}"><div class="external-main"><div class="external-title-line"><b>${escapeHtml(item.artist||'Artista sconosciuto')} â€” ${escapeHtml(item.title||'Titolo mancante')}</b>${titleDots}</div><small>${escapeHtml(item.reason||'')}</small>${matchHtml}<div class="external-meta"><span>#${escapeHtml(String(item.position||''))}</span>${item.spotify_id?`<span>ID ${escapeHtml(item.spotify_id)}</span>`:''}${item.isrc?`<span>ISRC ${escapeHtml(item.isrc)}</span>`:''}${item.duration?`<span>${formatDuration(item.duration)}</span>`:''}</div></div>${actions}</article>`;
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
        toast(result.found?'Spotify ID giÃ  in KR Desk - spostato nei presenti':'Spotify ID non presente - spostato nei da scaricare');
      }catch(error){clearInterval(externalSpotifyClipboardTimer);externalSpotifyClipboardTimer=null;button.disabled=false;toast(error.message)}
    },1200);
  }catch(error){button.disabled=false;spotifyWindow?.close();toast(error.message)}
}

function cleanImportedText(value){
  return String(value??'').replace(/^\uFEFF/,'').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g,'').replace(/&amp;/g,'&').replace(/&quot;/g,'"').replace(/&#039;|&apos;/g,"'").replace(/\u00e2\u20ac[\u201c\u201d]/g,'-').replace(/\u00e2\u20ac\u2122/g,"'").replace(/\u00c3\u00b1/g,'ñ').replace(/Ãƒ(.)/g,(_,char)=>({'Â©':'Ã©','Â¨':'Ã¨','Â¬':'Ã¬','Â²':'Ã²','Â¹':'Ã¹','â‚¬':'Ã '}[char]||`Ãƒ${char}`)).replace(/Ã¢â‚¬â„¢/g,"'").replace(/Ã¢â‚¬Å“|Ã¢â‚¬Â/g,'"').replace(/Ã¢â‚¬â€œ|Ã¢â‚¬â€/g,'-').replace(/\s+/g,' ').trim();
}

function shortVdjPath(path){
  const parts=String(path||'-').split(/[\\/]+/).filter(Boolean);
  return parts.length>=2?parts.slice(-2).join('\\'):(parts[0]||'-');
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
      metadata={artist:cleanImportedText(artist),title:cleanImportedText(title),duration};continue;
    }
    if(line.startsWith('#'))continue;
    items.push({...metadata,path:cleanImportedText(line),position:items.length+1});metadata={};
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

async function externalSearchKrDesk(query,playlistPosition=''){
  window.vdjPlaylistUseContext=playlistPosition?{position:Number(playlistPosition),query,scrollY:window.scrollY,filter:vdjImportedFilter}:null;
  $('#library-search').value=query||'';
  state.libraryExtraFilters={};
  showView('library');
  await loadTracks(true,false);
  addVdjPlaylistUseButtons();
}

async function externalLoadFile(file){
  const text=await file.text();
  const items=/\.m3u8?$/i.test(file.name||'')?externalParseM3u(text):externalFlattenJson(JSON.parse(text));
  if(!items.length)throw new Error('JSON valido ma nessuna lista brani trovata.');
  $('#playlist-integrator-results').classList.add('empty-state');
  $('#playlist-integrator-results').textContent=`Confronto ${items.length} righe con la Libreria Definitivaâ€¦`;
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
  toast(`JSON confrontato Â· ${result.missing} da scaricare Â· ${result.present} giÃ  presenti Â· ${result.doubtful} dubbi`);
}

function parseVdjFolder(text){
  return [...String(text||'').matchAll(/<(?:song|track)\b([^>]*)>/gi)].map((match,index)=>{const attrs=match[1]||'',attr=name=>cleanImportedText((attrs.match(new RegExp(`\\b${name}="([^"]*)"`,`i`))||[])[1]||'');return {path:attr('path')||attr('FilePath'),artist:attr('artist'),title:attr('title'),duration:attr('songlength'),position:index+1}}).filter(item=>item.path);
}

async function loadVdjPlaylistFile(file){
  const text=await file.text();
  const items=/\.vdjfolder$/i.test(file.name||'')?parseVdjFolder(text):externalParseM3u(text);
  if(!items.length)throw new Error('Playlist VDJ vuota o non riconosciuta.');
  await loadVdjPlaylistItems(items,file.name||'playlist_importata');
}

async function loadVdjPlaylistSources(){
  const select=$('#vdj-playlist-select');if(!select)return;
  const data=await api('playlist-import-sources');
  select.innerHTML=data.items?.length?'<option value="">Scegli una playlist...</option>'+data.items.map((item,index)=>`<option value="${index}">${escapeHtml(item.name)}</option>`).join(''):'<option value="">Nessuna playlist trovata</option>';
}

async function loadVdjSelectedPlaylist(){
  const index=$('#vdj-playlist-select')?.value;if(index==='')return;
  const data=await api(`playlist-import-source-content&index=${encodeURIComponent(index)}`);if(data.parts?.length>1){const items=data.parts.flatMap(part=>/\.vdjfolder$/i.test(part.name)?parseVdjFolder(part.content):externalParseM3u(part.content)).map((item,position)=>({...item,position:position+1}));await loadVdjPlaylistItems(items,data.name);return}const blob=new Blob([data.content],{type:'text/plain'});Object.defineProperty(blob,'name',{value:data.name});await loadVdjPlaylistFile(blob);
}

async function loadVdjPlaylistItems(items,name){
  if(!items.length)throw new Error('Playlist VDJ vuota o non riconosciuta.');
  const target=$('#vdj-playlist-results');target.classList.add('empty-state');target.textContent=`Confronto ${items.length} righe con la Libreriaâ€¦`;
  const result=await post('playlist-external-compare',{items});vdjImportedResult=result;vdjImportedItems=items;vdjImportedName=cleanVdjPlaylistName(name);vdjDirtyPositions.clear();
  $('#vdj-playlist-stats').innerHTML=`<button type="button" data-vdj-filter="all">Totale: <b>${result.total}</b></button><button type="button" class="ok" data-vdj-filter="present">Presenti: <b>${result.present}</b></button><button type="button" class="warn" data-vdj-filter="doubtful">Dubbi: <b>${result.doubtful}</b></button><button type="button" class="missing" data-vdj-filter="missing">Da sistemare: <b>${result.missing}</b></button>`;
  $('#vdj-playlist-actions').classList.remove('hidden');renderVdjResults('all');
}

function recalculateVdjImportCounts(){
  if(!vdjImportedResult?.items)return;
  ['present','doubtful','missing'].forEach(group=>vdjImportedResult.items[group]=Array.isArray(vdjImportedResult.items[group])?vdjImportedResult.items[group]:[]);
  vdjImportedResult.present=vdjImportedResult.items.present.length;
  vdjImportedResult.doubtful=vdjImportedResult.items.doubtful.length;
  vdjImportedResult.missing=vdjImportedResult.items.missing.length;
  vdjImportedResult.total=vdjImportedResult.present+vdjImportedResult.doubtful+vdjImportedResult.missing;
}

function normalizeVdjImportSnapshot(result,expectedRows=0){
  if(!result||typeof result!=='object')return null;
  result.items=result.items&&typeof result.items==='object'?result.items:{};
  ['present','doubtful','missing'].forEach(group=>{
    result.items[group]=Array.isArray(result.items[group])?result.items[group]:[];
    result.items[group].forEach(item=>{
      item.status=item.status||group;
      item.matches=Array.isArray(item.matches)?item.matches.filter(match=>match&&typeof match==='object'):[];
    });
  });
  const total=result.items.present.length+result.items.doubtful.length+result.items.missing.length;
  if(expectedRows>0&&total===0)return null;
  return result;
}

function markVdjImportDirty(position){
  const value=Number(position||0);
  if(value>0)vdjDirtyPositions.add(value);
}

function markVdjImportDirtyByTrackId(trackId){
  ['present','doubtful','missing'].forEach(group=>(vdjImportedResult?.items?.[group]||[]).forEach(item=>{
    if((item.matches||[]).some(match=>Number(match.id)===Number(trackId)))markVdjImportDirty(item.position);
  }));
}

function mergeVdjRecalculatedRows(recalculated,positions){
  const dirty=new Set([...positions].map(Number));
  ['present','doubtful','missing'].forEach(group=>{vdjImportedResult.items[group]=(vdjImportedResult.items[group]||[]).filter(item=>!dirty.has(Number(item.position||0)))});
  ['present','doubtful','missing'].forEach(group=>{vdjImportedResult.items[group]=[...(vdjImportedResult.items[group]||[]),...((recalculated.items?.[group]||[]).map(item=>({...item,status:item.status||group})))]});
  recalculateVdjImportCounts();
}

function findVdjImportItemByPosition(position){
  for(const group of ['present','doubtful','missing']){
    const item=(vdjImportedResult?.items?.[group]||[]).find(entry=>Number(entry.position||0)===Number(position));
    if(item)return {group,item};
  }
  return null;
}

async function refreshResolvedVdjDirtyRows(positions){
  const resolved=[];const unresolved=[];
  for(const position of positions){
    const found=findVdjImportItemByPosition(position);
    const trackId=Number(found?.item?.matches?.[0]?.id||0);
    if(found?.group==='present'&&trackId>0)resolved.push({position:Number(position),trackId,item:found.item});
    else unresolved.push(Number(position));
  }
  if(resolved.length){
    const refreshed=await post('playlist-import-tracks-refresh',{ids:resolved.map(entry=>entry.trackId)});
    const byId=new Map((refreshed.items||[]).map(track=>[Number(track.id),track]));
    for(const entry of resolved){
      const track=byId.get(entry.trackId);
      if(track&&Number(track.file_exists)!==0){entry.item.matches=[track];entry.item.artist=track.artist||entry.item.artist;entry.item.title=track.title||entry.item.title;entry.item.file_path=track.file_path;entry.item.path=track.file_path;entry.item.status='present';entry.item.reason='Scelta manuale verificata in libreria'}
      else unresolved.push(entry.position);
    }
  }
  return unresolved;
}

async function refreshCompletedVdjMissingRows(){
  const missing=[...(vdjImportedResult?.items?.missing||[])];
  const ids=[...new Set(missing.map(item=>Number(item.matches?.[0]?.id||item.track_id||0)).filter(Boolean))];
  if(!ids.length)return 0;
  const refreshed=await post('playlist-import-tracks-refresh',{ids});
  const byId=new Map((refreshed.items||[]).map(track=>[Number(track.id),track]));
  let moved=0;
  for(const item of missing){
    const track=byId.get(Number(item.matches?.[0]?.id||item.track_id||0));
    if(!track||Number(track.file_exists||0)===0||!track.file_path||String(track.file_path).startsWith('KRDESK://'))continue;
    updateVdjImportItemWithTrack(item,track,'File fisico verificato nella libreria');
    moved++;
  }
  return moved;
}

async function saveVdjImportSnapshot(silent=false){
  if(!vdjImportedResult){toast('Nessuna lista importata da salvare');return}
  recalculateVdjImportCounts();
  const result=await post('playlist-import-snapshot-save',{name:vdjImportedName,filter:vdjImportedFilter,scroll:window.scrollY,items:vdjImportedItems,result:vdjImportedResult,dirty_positions:[...vdjDirtyPositions]});
  if(!silent)toast(`Sessione creata - ${result.total} righe, ${result.dirty} modificate`);
}

async function loadVdjImportSnapshot(){
  const data=await api('playlist-import-snapshot-load');
  const items=Array.isArray(data.items)?data.items:[];
  if(!items.length)throw new Error('Sessione import senza righe playlist');
  vdjImportedName=cleanVdjPlaylistName(data.name);
  vdjImportedItems=items;
  vdjImportedFilter=data.filter||'all';
  const target=$('#vdj-playlist-results');target.classList.add('empty-state');
  vdjDirtyPositions=new Set((Array.isArray(data.dirty_positions)?data.dirty_positions:[]).map(Number).filter(Boolean));
  vdjImportedResult=normalizeVdjImportSnapshot(data.result,items.length);
  if(!vdjImportedResult){
    target.textContent=`Primo ripristino: confronto ${items.length} righe con la libreria attuale…`;
    vdjImportedResult=await post('playlist-external-compare',{items});
    vdjDirtyPositions.clear();
  }else if(vdjDirtyPositions.size){
    target.textContent=`Ripristino intelligente: verifico ${vdjDirtyPositions.size} righe modificate…`;
    const positionsToRecalculate=await refreshResolvedVdjDirtyRows(vdjDirtyPositions);
    const dirtyItems=items.filter(item=>positionsToRecalculate.includes(Number(item.position||0)));
    if(dirtyItems.length)mergeVdjRecalculatedRows(await post('playlist-external-compare',{items:dirtyItems}),positionsToRecalculate);
    vdjDirtyPositions.clear();
  }
  await refreshCompletedVdjMissingRows();
  recalculateVdjImportCounts();
  $('#vdj-playlist-actions').classList.remove('hidden');
  renderVdjResults(vdjImportedFilter);
  requestAnimationFrame(()=>window.scrollTo({top:Number(data.scroll||0),behavior:'instant'}));
  await saveVdjImportSnapshot(true);
  toast(`Sessione ripresa - ${vdjImportedResult.total} righe, ${vdjImportedResult.missing} da sistemare`);
}

function updateVdjImportItemWithTrack(item,track,reason){
  if(!item||!track||!vdjImportedResult?.items)return;
  const position=Number(item.position||0);
  const hasPhysicalFile=Number(track.file_exists||0)!==0&&track.file_path&&!String(track.file_path).startsWith('KRDESK://');
  const targetGroup=hasPhysicalFile?'present':'missing';
  item.matches=[track];item.track_id=track.id||item.track_id;item.spotify_id=track.spotify_id||item.spotify_id;item.spotify_url=track.spotify_url||item.spotify_url;item.file_path=hasPhysicalFile?(track.file_path||item.file_path):item.file_path;item.path=hasPhysicalFile?(track.file_path||item.path):item.path;item.artist=track.artist||item.artist;item.title=track.title||item.title;item.status=targetGroup;item.reason=reason;
  const source=vdjImportedItems.find(entry=>Number(entry.position)===position);
  if(source){source.path=item.path;source.artist=item.artist;source.title=item.title;source.track_id=item.track_id;source.spotify_id=item.spotify_id;source.spotify_url=item.spotify_url}
  ['present','doubtful','missing'].forEach(group=>{vdjImportedResult.items[group]=(vdjImportedResult.items[group]||[]).filter(entry=>entry!==item&&Number(entry.position||0)!==position)});
  vdjImportedResult.items[targetGroup]=[...(vdjImportedResult.items[targetGroup]||[]),item].sort((left,right)=>Number(left.position||0)-Number(right.position||0));
  markVdjImportDirty(position);
  recalculateVdjImportCounts();
}

function renderVdjResults(presentOnly){
  if(!vdjImportedResult)return;
  const present=vdjImportedResult.items?.present?.length||0,doubtful=vdjImportedResult.items?.doubtful?.length||0,missing=vdjImportedResult.items?.missing?.length||0,total=present+doubtful+missing;
  const stats=$('#vdj-playlist-stats');
  if(stats)stats.innerHTML=`<button type="button" data-vdj-filter="all">Totale: <b>${total}</b></button><button type="button" class="ok" data-vdj-filter="present">Presenti: <b>${present}</b></button><button type="button" class="warn" data-vdj-filter="doubtful">Dubbi: <b>${doubtful}</b></button><button type="button" class="missing" data-vdj-filter="missing">Da sistemare: <b>${missing}</b></button>`;
  const target=$('#vdj-playlist-results');
  target.classList.remove('empty-state');
  const rows=(presentOnly==='all'?[...(vdjImportedResult.items?.present||[]),...(vdjImportedResult.items?.doubtful||[]),...(vdjImportedResult.items?.missing||[])]:[...(vdjImportedResult.items?.[presentOnly]||[])]).sort((left,right)=>Number(left.position||0)-Number(right.position||0));
  window.vdjPlaylistTrackLookup.clear();
  window.vdjPlaylistPendingSpotify.clear();
  target.innerHTML=`<div class="external-list-head"><strong>${escapeHtml(vdjImportedName)}</strong></div>`+rows.map(item=>{
    const query=externalQuery(item),track=item.matches?.[0]||{},artist=item.artist||track.artist||'',title=item.title||track.title||item.path||'',path=track.file_path||item.file_path||item.path||item.reason||'-',trackId=Number(track.id||item.track_id||0),spotifyId=track.spotify_id||item.spotify_id||'',spotifyUrl=item.trackLink||item.spotify_url||(spotifyId?`https://open.spotify.com/track/${spotifyId}`:`https://open.spotify.com/search/${encodeURIComponent(query)}`),spotifySearchUrl=`https://open.spotify.com/search/${encodeURIComponent(query)}`,isDoubtful=(vdjImportedResult.items?.doubtful||[]).includes(item);
    item.track_id=trackId||item.track_id||0;
    if(trackId)window.vdjPlaylistTrackLookup.set(trackId,track);
    window.vdjPlaylistPendingSpotify.set(Number(item.position),item);
    const eButton=`<button type="button" class="external-title-dot kr" data-external-kr-search="${escapeHtml(query)}" data-vdj-position="${escapeHtml(String(item.position||''))}" title="Confronta con la libreria E:">E</button>`;
    const spotifyButton=spotifyId
      ? `<a class="spotify-title-action spotify-open" target="vdjdesk_spotify" href="${escapeHtml(spotifyUrl)}" title="Apri Spotify" aria-label="Apri Spotify"></a>`
      : `<a class="spotify-title-action spotify-open vdj-spotify-search-link" target="vdjdesk_spotify" href="${escapeHtml(spotifyUrl)}" data-vdj-spotify-position="${escapeHtml(String(item.position||''))}" data-vdj-track-id="${escapeHtml(String(trackId||''))}" data-vdj-artist="${escapeHtml(artist)}" data-vdj-title="${escapeHtml(title)}" data-vdj-path="${escapeHtml(path)}" title="Cerca su Spotify e acquisisci il link copiato">S</a>`;
    const textualSpotifyButton=spotifyId&&isDoubtful?`<a class="external-title-dot spotify vdj-spotify-search-link" target="vdjdesk_spotify" href="${escapeHtml(spotifySearchUrl)}" data-vdj-spotify-position="${escapeHtml(String(item.position||''))}" data-vdj-track-id="${escapeHtml(String(trackId||''))}" data-vdj-artist="${escapeHtml(artist)}" data-vdj-title="${escapeHtml(title)}" data-vdj-path="${escapeHtml(path)}" title="Ricerca testuale Spotify e sostituisci l'ID copiato">S</a>`:'';
    const spotmateButton=spotifyId&&trackId?`<a class="spotify-title-action spotify-spotmate spotmate-link" target="vdjdesk_spotmate" href="https://spotmate.online/premium" data-track-id="${escapeHtml(String(trackId))}" data-spotify-url="${escapeHtml(spotifyUrl)}" title="Apri SpotMate e aggancia il download">S</a>`:'';
    const dots=`<span class="spotify-title-actions vdj-missing-actions">${spotifyButton}${textualSpotifyButton}${spotmateButton}${eButton}</span>`;
    const menu=trackId?'<button class="more-button">...</button><div class="action-menu"><button data-action="edit">Tag e punteggi</button><button data-action="played">Segna come suonato</button><button data-action="queue">Aggiungi ad Automix</button></div>':'';
    return `<article class="track-row vdj-track-row ${escapeHtml(item.status||'missing')}" data-id="${trackId||''}" data-vdj-position="${escapeHtml(String(item.position||''))}"><div class="track-identity"><strong>${dots} ${escapeHtml(artist)} - ${escapeHtml(title)}</strong><small title="${escapeHtml(path)}">${escapeHtml(shortVdjPath(path))}</small></div><div><span class="cell-label">BPM</span><span class="cell-value">${track.bpm??'-'}</span></div><div><span class="cell-label">KEY / SCALA</span><span class="cell-value">${escapeHtml(track.camelot||track.musical_key||'-')} | ${scaleMode(track)}</span></div><div class="hide-mobile"><span class="cell-label">DURATA</span><span class="cell-value">${formatDuration(track.duration)}</span></div><div class="hide-tablet hide-mobile"><span class="cell-label">GENERE / ANNO</span><span class="cell-value">${escapeHtml(track.genre||'-')} | ${track.year||'-'}</span></div><div class="track-tags hide-mobile"></div><div class="track-actions">${menu}</div></article>`;
  }).join('')||'<div class="empty-state">Nessun brano nella vista.</div>';
}

function exportVdjCleanPlaylist(){
  const items=vdjImportedResult?.items?.present||[];const paths=items.map(item=>item.matches?.[0]?.file_path||item.file_path).filter(Boolean);if(!paths.length){toast('Nessun brano completo da esportare');return}const content='<?xml version="1.0" encoding="UTF-8"?>\r\n<VirtualFolder noDuplicates="no" ordered="yes">\r\n'+paths.map(path=>`\t<song path="${String(path).replace(/&/g,'&amp;').replace(/"/g,'&quot;')}" />`).join('\r\n')+'\r\n</VirtualFolder>\r\n';const blob=new Blob([content],{type:'application/xml'});const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=`${vdjImportedName.replace(/\s*Â·.*$/,'').replace(/\.[^.]+$/,'')}_pulita.vdjfolder`;link.click();URL.revokeObjectURL(link.href);toast(`Esportati ${paths.length} brani nella playlist pulita`);
}

function vdjVisibleItems(){if(!vdjImportedResult)return[];return vdjImportedFilter==='all'?['present','doubtful','missing'].flatMap(status=>vdjImportedResult.items?.[status]||[]):vdjImportedResult.items?.[vdjImportedFilter]||[]}
window.refreshVdjPlaylistTrack=function(track){
  if(!track?.id||!vdjImportedResult)return false;
  const linked=['present','doubtful','missing'].flatMap(group=>vdjImportedResult.items?.[group]||[]).filter(item=>(item.matches||[]).some(candidate=>Number(candidate.id)===Number(track.id))||Number(item.track_id||0)===Number(track.id));
  linked.forEach(item=>updateVdjImportItemWithTrack(item,track,'Record playlist aggiornato dalla libreria'));
  window.vdjPlaylistTrackLookup.set(Number(track.id),track);
  renderVdjResults(vdjImportedFilter);
  saveVdjImportSnapshot(true).catch(error=>toast(error.message));
  return true;
}
function decorateVdjRows(){
  $$('#vdj-playlist-results .vdj-track-row').forEach(row=>row.classList.add('track-row'))
}
const vdjResultsObserver=$('#vdj-playlist-results');if(vdjResultsObserver)new MutationObserver(decorateVdjRows).observe(vdjResultsObserver,{childList:true,subtree:true});
async function runVdjSpotifyAction(type){
  const tracks=vdjVisibleItems().map(item=>item.matches?.[0]).filter(track=>track?.id);if(!tracks.length){toast('Nessun brano della lista collegato alla libreria');return}
  const pending=type==='identify'?tracks.filter(track=>!track.spotify_id):tracks.filter(track=>track.spotify_id&&(!track.spotify_features_updated_at||!['complete','partial'].includes(track.spotify_features_status)));
  if(!pending.length){toast(type==='identify'?'Tutti i brani hanno giÃ  Spotify ID':'Metriche giÃ  aggiornate');return}
  const button=type==='identify'?$('#vdj-identify-spotify'):$('#vdj-fetch-metrics');button.disabled=true;krProgress.start(type==='identify'?'Ricerca Spotify ID':'Recupero metriche Spotify',pending.length,'Lista VDJ visibile');let done=0;
  try{for(const track of pending){try{const result=await post(type==='identify'?'spotify-identify':'spotify-audio-features',{id:Number(track.id)});Object.assign(track,result.track||{});markVdjImportDirtyByTrackId(track.id);done++}catch(error){}}renderVdjResults(vdjImportedFilter);toast(`${done}/${pending.length} brani aggiornati`)}finally{button.disabled=false;krProgress.done(type==='identify'?'Ricerca Spotify ID completata':'Metriche Spotify completate',`${done}/${pending.length}`)}
}
function sendVdjVisibleToSpotify(){
  const tracks=vdjVisibleItems().map(item=>item.matches?.[0]).filter(track=>track?.spotify_id&&track.spotify_features_updated_at&&['complete','partial'].includes(track.spotify_features_status));if(!tracks.length){toast('Nessun brano visibile con ID e metriche Spotify');return}showView('spotify');const frame=$('.embedded-tool-frame');const send=()=>frame.contentWindow.postMessage({type:'vdjdesk-library',tracks},location.origin);if(frame.contentDocument?.readyState==='complete')send();else frame.addEventListener('load',send,{once:true});toast(`${tracks.length} brani inviati a Spot to VDJ`)
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
    toast(`Playlist creata: ${result.tracks}/${result.total} brani Â· ${result.unavailable} senza file fisico`);
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
  select.innerHTML='<option value="">Scegli cartellaâ€¦</option>'+data.items.map(item=>`<option value="${escapeHtml(item.path)}">${escapeHtml(item.tree_label||item.label||item.path)}</option>`).join('');
  select.dataset.loaded='1';
}

function externalRenderFolderMatches(){
  const target=$('#playlist-folder-match-results');
  if(!externalFolderMatchResult){target.classList.add('hidden');return}
  target.classList.remove('hidden','empty-state');
  const safe=externalFolderMatchResult.items?.safe||[];
  const doubtful=externalFolderMatchResult.items?.doubtful||[];
  const unmatched=externalFolderMatchResult.items?.unmatched||[];
  target.innerHTML=`<div class="external-list-head"><div><strong>Congruenza cartella Â· ${externalFolderMatchResult.tracks_in_folder} brani</strong><small>Sicuri ${safe.length} Â· dubbi ${doubtful.length} Â· non trovati ${unmatched.length}</small></div></div>${safe.length?`<h3 class="external-subtitle">Applicabili sicuri</h3>${safe.map(item=>externalFolderRow(item,'safe')).join('')}`:''}${doubtful.length?`<h3 class="external-subtitle">Dubbi non applicati</h3>${doubtful.slice(0,80).map(item=>externalFolderRow(item,'doubtful')).join('')}`:''}${unmatched.length?`<h3 class="external-subtitle">Non trovati in cartella</h3>${unmatched.slice(0,80).map(item=>externalFolderRow(item,'unmatched')).join('')}`:''}`;
  $('#external-apply-safe').disabled=safe.length===0;
}

function externalFolderRow(item,status){
  const entry=item.entry||{};
  const track=item.track||{};
  const trackText=track.id?`${track.artist||''} â€” ${track.title||''}`:'â€”';
  const path=track.file_path||'';
  return `<article class="external-row ${status}"><div class="external-main"><b>${escapeHtml(entry.artist||'')} â€” ${escapeHtml(entry.title||'')}</b><small>${escapeHtml(item.reason||'')}</small><small class="external-match">KR: ${escapeHtml(trackText)}${path?` Â· ${escapeHtml(path)}`:''}</small><div class="external-meta">${entry.spotify_id?`<span>ID ${escapeHtml(entry.spotify_id)}</span>`:''}${entry.isrc?`<span>ISRC ${escapeHtml(entry.isrc)}</span>`:''}${entry.duration?`<span>JSON ${formatDuration(entry.duration)}</span>`:''}${track.duration?`<span>KR ${formatDuration(track.duration)}</span>`:''}<span>${Number(item.confidence||0)}%</span></div></div></article>`;
}

async function externalMatchFolder(){
  if(!externalRawItems.length){toast('Carica prima un JSON');return}
  const folder=$('#external-folder-select').value;
  if(!folder){toast('Scegli la cartella scaricata');return}
  const button=$('#external-folder-match');
  button.disabled=true;button.textContent='Verificaâ€¦';
  try{
    externalFolderMatchResult=await post('playlist-external-folder-match',{items:externalRawItems,folder});
    externalRenderFolderMatches();
    toast(`Match cartella Â· ${externalFolderMatchResult.safe} sicuri Â· ${externalFolderMatchResult.doubtful} dubbi`);
  }catch(error){toast(error.message)}
  finally{button.disabled=false;button.textContent='Verifica congruenza'}
}

async function externalApplySafe(){
  const safe=externalFolderMatchResult?.items?.safe||[];
  if(!safe.length)return;
  if(!window.confirm(`Applicare spotify_id/link/ISRC/album a ${safe.length} brani sicuri?`))return;
  const button=$('#external-apply-safe');
  button.disabled=true;button.textContent='Applicoâ€¦';
  try{
    const matches=safe.map(item=>({track_id:item.track.id,entry:item.entry,confidence:item.confidence,reason:item.reason}));
    const result=await post('playlist-external-apply-metadata',{matches});
    toast(`${result.applied} brani aggiornati da JSON${result.skipped?` Â· ${result.skipped} saltati`:''}`);
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
  if(input?.files?.[0])externalLoadFile(input.files[0]).catch(error=>toast(error.message));
  const vdjInput=event.target.closest('#vdj-playlist-input');
  if(vdjInput?.files?.[0])loadVdjPlaylistFile(vdjInput.files[0]).catch(error=>toast(error.message));
});
loadVdjPlaylistSources().catch(error=>toast(error.message));
$('#vdj-playlist-load')?.addEventListener('click',()=>loadVdjSelectedPlaylist().catch(error=>toast(error.message)));
$('#vdj-snapshot-save')?.addEventListener('click',()=>saveVdjImportSnapshot().catch(error=>toast(error.message)));
$('#vdj-snapshot-load')?.addEventListener('click',()=>loadVdjImportSnapshot().catch(error=>toast(error.message)));
document.addEventListener('click',event=>{const filter=event.target.closest('[data-vdj-filter]');if(filter){vdjImportedFilter=filter.dataset.vdjFilter;renderVdjResults(vdjImportedFilter)}});
$('#vdj-export-clean')?.addEventListener('click',exportVdjCleanPlaylist);
$('#vdj-identify-spotify')?.addEventListener('click',()=>runVdjSpotifyAction('identify'));
$('#vdj-fetch-metrics')?.addEventListener('click',()=>runVdjSpotifyAction('metrics'));
$('#vdj-send-to-spotify')?.addEventListener('click',sendVdjVisibleToSpotify);

document.addEventListener('click',async event=>{
  const link=event.target.closest('#vdj-playlist-results .spotmate-link');
  if(!link)return;
  event.preventDefault();event.stopImmediatePropagation();
  const trackId=Number(link.dataset.trackId||0);
  const track=window.vdjPlaylistTrackLookup?.get?.(trackId)||state.tracks.find(item=>Number(item.id)===trackId);
  const spotifyUrl=link.dataset.spotifyUrl||track?.spotify_url||(track?.spotify_id?`https://open.spotify.com/track/${track.spotify_id}`:'');
  if(!trackId||!spotifyUrl){toast('Link Spotify non disponibile per SpotMate');return}
  try{
    await navigator.clipboard.writeText(spotifyUrl);
    await post('playlist-spotmate-start',{file:'',id:trackId,old_path:track?.file_path||''});
    const spotmateWindow=window.open('https://spotmate.online/premium','vdjdesk_spotmate');
    spotmateWindow?.focus();
    toast('Link Spotify copiato - incolla in SpotMate, monitoro il download');
    clearInterval(vdjSpotmateTimer);
    vdjSpotmateTimer=setInterval(async()=>{
      try{
        const result=await api('playlist-spotmate-status');
        if(result.pending)return;
        clearInterval(vdjSpotmateTimer);vdjSpotmateTimer=null;spotmateWindow?.close();window.focus();
        if(result.replaced){
          const updatedTrack=window.vdjPlaylistTrackLookup?.get?.(trackId)||track||{};
          Object.assign(updatedTrack,{id:trackId,file_path:result.download_path,file_name:result.download_path?result.download_path.split(/[\\/]+/).pop():updatedTrack.file_name,folder:result.download_path?result.download_path.replace(/[\\/][^\\/]+$/,''):updatedTrack.folder,file_exists:1,bitrate:result.bitrate??updatedTrack.bitrate,duration:result.duration??updatedTrack.duration});
          window.refreshVdjPlaylistTrack?.(updatedTrack);
          toast('Download spostato in Da_classificare e associato al record');
          return;
        }
        toast('Monitor SpotMate terminato senza download');
      }catch(error){clearInterval(vdjSpotmateTimer);vdjSpotmateTimer=null;spotmateWindow?.close();window.focus();toast(error.message)}
    },1500);
  }catch(error){toast(error.message)}
},true);

document.addEventListener('click',event=>{
  const vdjSpotify=event.target.closest('[data-vdj-spotify-position]');
  if(vdjSpotify){
    event.preventDefault();event.stopImmediatePropagation();
    const position=Number(vdjSpotify.dataset.vdjSpotifyPosition||0);
    const found=findVdjImportItemByPosition(position);
    const item=window.vdjPlaylistPendingSpotify.get(position)||found?.item||{position,track_id:Number(vdjSpotify.dataset.vdjTrackId||0),artist:vdjSpotify.dataset.vdjArtist||'',title:vdjSpotify.dataset.vdjTitle||'',path:vdjSpotify.dataset.vdjPath||''};
    if(!item.artist||!item.title){toast('Riga playlist non riconosciuta: impossibile agganciare Spotify');return}
    const spotifyWindow=window.open('about:blank','vdjdesk_spotify');
    const context={type:'playlist-import',track_id:item.track_id||item.matches?.[0]?.id||0,artist:item.artist||'',title:item.title||'',path:item.path||item.file_path||'',position:item.position||0};
    post('spotify-clipboard-lookup-start',{context}).then(()=>{if(spotifyWindow){spotifyWindow.location.href=vdjSpotify.href;spotifyWindow.focus()}toast('Appunti azzerati - copia il link del brano da Spotify');clearInterval(vdjSpotifyClipboardTimer);vdjSpotifyClipboardTimer=setInterval(async()=>{try{const result=await api('spotify-clipboard-lookup-status');if(result.pending)return;clearInterval(vdjSpotifyClipboardTimer);vdjSpotifyClipboardTimer=null;spotifyWindow?.close();window.focus();if(!result.spotify_id&&!result.spotify_url){toast('Acquisizione link Spotify scaduta');return}const updated=result.track?result:await post('playlist-spotify-link-update',{...context,url:result.spotify_url||result.trackLink||''});updateVdjImportItemWithTrack(item,updated.track,updated.found?'Spotify ID già presente in libreria':'Spotify ID associato a record playlist');renderVdjResults(vdjImportedFilter);await saveVdjImportSnapshot(true);toast('Spotify ID aggiornato nel record playlist - da sistemare '+vdjImportedResult.missing)}catch(error){clearInterval(vdjSpotifyClipboardTimer);vdjSpotifyClipboardTimer=null;spotifyWindow?.close();window.focus();toast(error.message)}},1200)}).catch(error=>{spotifyWindow?.close();toast(error.message)});
    return;
  }
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
  if(krSearch)externalSearchKrDesk(krSearch.dataset.externalKrSearch,krSearch.dataset.vdjPosition);
  const useInPlaylist=event.target.closest('[data-action="vdj-playlist-use"]');
  if(useInPlaylist){
    const row=useInPlaylist.closest('.track-row');const track=state.tracks.find(item=>Number(item.id)===Number(row?.dataset.id));const context=window.vdjPlaylistUseContext;
    if(track&&context&&vdjImportedResult){
      const groups=['present','doubtful','missing'];let item=null;
      groups.some(group=>{item=(vdjImportedResult.items?.[group]||[]).find(entry=>Number(entry.position)===context.position);return Boolean(item)});
      if(item){updateVdjImportItemWithTrack(item,track,'File sostituito con brano trovato in libreria E:');const scrollY=context.scrollY||0;const filter=context.filter||'all';window.vdjPlaylistUseContext=null;showView('spotify');renderVdjResults(filter);toast('Sostituzione applicata - da sistemare '+vdjImportedResult.missing);requestAnimationFrame(()=>window.scrollTo({top:scrollY,behavior:'instant'}))}
    }
  }
},true);
