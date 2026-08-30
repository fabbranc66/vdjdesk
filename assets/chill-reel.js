let chillReelState=null;
let chillReelBoardPuzzleId=0;
let chillReelBoardLetters=null;
const chillReelSfxVolumeKey='kr_chill_reel_sfx_volume';

function chillReelPayloadLines(value){return String(value||'').split(/\r?\n/).map(item=>item.trim()).filter(Boolean)}
function chillReelBoardHtml(solution,letters,previousLetters){return String(solution||'').split(/\s+/).filter(Boolean).map(word=>`<span class="word">${[...word].map(char=>{const isLetter=/[A-ZÀ-ÖØ-Ý]/u.test(char);const revealed=isLetter&&letters.includes(char);const isNew=revealed&&previousLetters!==null&&!previousLetters.includes(char);return isLetter&&!revealed?'<i>_</i>':`<i class="${[!isLetter?'punctuation':'',isNew?'revealed-now':''].filter(Boolean).join(' ')}">${escapeHtml(char)}</i>`}).join('')}</span>`).join('')}

async function createChillReelFromForm(){
  const tables=chillReelPayloadLines($('#chill-reel-tables').value);
  const puzzles=chillReelPayloadLines($('#chill-reel-puzzles').value).map(line=>{const parts=line.split('|');return{category:(parts.shift()||'').trim(),solution:parts.join('|').trim()}});
  return post('chill-reel-create',{name:$('#chill-reel-name').value,tables,puzzles});
}

function renderChillReel(data){
  chillReelState=data;
  const game=data.game;
  $('#chill-reel-game-select').innerHTML='<option value="">Nuova manche</option>'+data.games.map(item=>`<option value="${item.id}">${escapeHtml(item.name)} · ${escapeHtml(item.status)}</option>`).join('');
  if(game){
    $('#chill-reel-game-select').value=String(game.id);
    $('#chill-reel-name').value=game.name||'';
    $('#chill-reel-tables').value=data.tables.map(item=>item.name).join('\n');
    $('#chill-reel-puzzles').value=data.puzzles.map(item=>`${item.category||''} | ${item.solution}`).join('\n');
  }
  $('#chill-reel-game-status').textContent=!game?'Non attivo':`${game.status}${data.active_game==='chill_reel'?' · PUBBLICO':''}`;
  $('#chill-reel-save').disabled=!game;
  $('#chill-reel-game-status').classList.toggle('blue',data.active_game==='chill_reel');
  $('#chill-reel-activate').disabled=false;
  $('#chill-reel-activate').classList.toggle('accent',data.active_game==='chill_reel');
  $('#chill-reel-activate').classList.toggle('ghost',data.active_game!=='chill_reel');
  $('#chill-reel-activate').textContent=`Chill Reel: ${data.active_game==='chill_reel'?'ON':'OFF'}`;
  const currentTable=data.tables.find(item=>Number(item.id)===Number(game?.current_table_id));
  $('#chill-reel-current-table').textContent=currentTable?`Turno: ${currentTable.name}`:(game?.status==='booking'?'Prenotazione aperta':'Nessun turno');
  $('#chill-reel-table-list').classList.toggle('empty-state',!data.tables.length);
  $('#chill-reel-table-list').innerHTML=data.tables.length?data.tables.map(item=>{const pending=item.status==='pending';const online=Number(item.online);const state=pending?'Rientro da accettare':online?'Collegato':item.public_token?'Offline':'Non associato';return `<div class="chill-reel-table ${Number(item.id)===Number(game?.current_table_id)?'current':''} ${pending?'pending':''}" data-player-id="${item.id}"><b>${item.registration_order}</b><span><strong>${escapeHtml(item.name)}</strong><small><i class="${online?'online':'offline'}"></i>${state}</small></span><strong>${Number(item.score)} pt</strong><div class="chill-reel-player-actions">${pending?'<button type="button" class="button primary chill-reel-player-action" data-action="accept">Accetta</button>':''}<button type="button" class="button ghost chill-reel-starter" data-id="${item.id}" ${item.public_token?'':'disabled'}>${item.booked_at?'Parte':'Imposta partenza'}</button>${item.public_token?'<button type="button" class="button ghost chill-reel-player-action" data-action="disconnect">Scollega</button><button type="button" class="button ghost chill-reel-player-action" data-action="remove">Rimuovi</button>':''}<button type="button" class="button ghost danger chill-reel-player-action" data-action="delete">Cancella</button></div></div>`}).join(''):'Crea o seleziona una manche.';
  $('#chill-reel-winner').innerHTML='<option value="0">Nessun vincitore</option>'+data.tables.map(item=>`<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('');
  const active=data.puzzles.find(item=>Number(item.id)===Number(game?.current_puzzle_id));
  const activeIndex=active?data.puzzles.indexOf(active)+1:0;
  $('#chill-reel-puzzle-progress').textContent=`${activeIndex} / ${data.puzzles.length}`;
  $('#chill-reel-category').textContent=active?.category||'Nessuna categoria';
  const letters=String(active?.revealed_letters||'');
  if(Number(active?.id||0)!==chillReelBoardPuzzleId){chillReelBoardPuzzleId=Number(active?.id||0);chillReelBoardLetters=null}
  $('#chill-reel-board').classList.toggle('empty-state',!active);
  $('#chill-reel-board').innerHTML=active?chillReelBoardHtml(active.solution,letters,chillReelBoardLetters):'Nessuna frase attiva.';
  chillReelBoardLetters=active?letters:null;
  $('#chill-reel-puzzle-list').classList.toggle('empty-state',!data.puzzles.length);
  $('#chill-reel-puzzle-list').innerHTML=data.puzzles.length?data.puzzles.map((item,index)=>`<div class="${item.status}"><b>${index+1}</b><span><small>${escapeHtml(item.category||'Senza categoria')}</small>${escapeHtml(item.solution)}</span><strong>${escapeHtml(item.status)}</strong></div>`).join(''):'Nessuna frase.';
  const activeGame=Boolean(game&&game.status==='active');
  $('#chill-reel-next-turn').disabled=!activeGame;
  $('#chill-reel-reveal').disabled=!active;
  $('#chill-reel-next-puzzle').disabled=!active;
}

async function loadChillReelControl(gameId=0){
  try{renderChillReel(await api(`chill-reel-state${gameId?`&game_id=${gameId}`:''}`))}catch(error){toast(error.message)}
}
window.loadChillReelControl=loadChillReelControl;

const chillReelSfxVolume=$('#chill-reel-sfx-volume');
if(chillReelSfxVolume){
  const savedVolume=Math.max(0,Math.min(100,Number(localStorage.getItem(chillReelSfxVolumeKey)??70)));
  chillReelSfxVolume.value=String(savedVolume);
  $('#chill-reel-sfx-volume-value').textContent=`${savedVolume}%`;
  chillReelSfxVolume.addEventListener('input',event=>{
    const volume=Math.max(0,Math.min(100,Number(event.target.value)));
    localStorage.setItem(chillReelSfxVolumeKey,String(volume));
    $('#chill-reel-sfx-volume-value').textContent=`${volume}%`;
  });
}

$('#chill-reel-game-select')?.addEventListener('change',event=>loadChillReelControl(Number(event.target.value||0)));
$('#chill-reel-create')?.addEventListener('click',async()=>{try{renderChillReel(await createChillReelFromForm());toast('Manche Chill Reel creata')}catch(error){toast(error.message)}});
$('#chill-reel-save')?.addEventListener('click',async()=>{if(!chillReelState?.game)return;try{const tables=chillReelPayloadLines($('#chill-reel-tables').value);const puzzles=chillReelPayloadLines($('#chill-reel-puzzles').value).map(line=>{const parts=line.split('|');return{category:(parts.shift()||'').trim(),solution:parts.join('|').trim()}});renderChillReel(await post('chill-reel-update',{game_id:chillReelState.game.id,name:$('#chill-reel-name').value,tables,puzzles}));toast('Manche aggiornata')}catch(error){toast(error.message)}});
document.addEventListener('click',async event=>{const button=event.target.closest('[data-public-game="chill_reel"]');if(!button)return;event.stopPropagation();button.disabled=true;try{let local=await api('chill-reel-state');let gameId=Number(local.game?.id||0);const modules=await api('public-modules');if(local.active_game==='chill_reel'){if(gameId)local=await post('chill-reel-deactivate',{game_id:gameId});const remote=await post('public-modules-update',{requests_enabled:!!modules.requests_enabled,quiz_enabled:false,active_game:'none'});renderPublicModules({...remote,active_game:'none'});renderChillReel(local);toast('Chill Reel OFF');return}if(!gameId){showView('chill-reel');toast('Crea prima la manche padre');return}local=await post('chill-reel-activate',{game_id:gameId});const remote=await post('public-modules-update',{requests_enabled:!!modules.requests_enabled,quiz_enabled:false,active_game:'chill_reel'});renderPublicModules({...remote,active_game:'chill_reel'});renderChillReel(local);toast('Chill Reel ON · Quiz Live OFF')}catch(error){toast(error.message)}finally{button.disabled=false}});
$('#chill-reel-next-turn')?.addEventListener('click',async()=>{try{renderChillReel(await post('chill-reel-next-turn',{game_id:chillReelState.game.id}))}catch(error){toast(error.message)}});
$('#chill-reel-reveal')?.addEventListener('click',async()=>{try{renderChillReel(await post('chill-reel-reveal-letter',{game_id:chillReelState.game.id,letter:$('#chill-reel-letter').value}));$('#chill-reel-letter').value=''}catch(error){toast(error.message)}});
$('#chill-reel-next-puzzle')?.addEventListener('click',async()=>{try{renderChillReel(await post('chill-reel-next-puzzle',{game_id:chillReelState.game.id,winner_table_id:Number($('#chill-reel-winner').value||0)}))}catch(error){toast(error.message)}});
document.addEventListener('click',async event=>{const button=event.target.closest('.chill-reel-starter');if(!button)return;try{renderChillReel(await post('chill-reel-starter',{game_id:chillReelState.game.id,table_id:Number(button.dataset.id)}));toast('Ordine di gioco avviato')}catch(error){toast(error.message)}});
$('#chill-reel-table-list')?.addEventListener('click',async event=>{const button=event.target.closest('.chill-reel-player-action');if(!button)return;const row=button.closest('[data-player-id]');const action=button.dataset.action;if(action==='delete'&&!confirm('Cancellare definitivamente il giocatore Chill Reel?'))return;try{await post('chill-reel-player-action',{id:Number(row.dataset.playerId),action});await loadChillReelControl(Number(chillReelState?.game?.id||0));toast(action==='accept'?'Rientro accettato':'Giocatore aggiornato')}catch(error){toast(error.message)}});
setInterval(()=>{if(!$('#view-chill-reel')?.classList.contains('active')||document.activeElement?.closest('.chill-reel-setup'))return;loadChillReelControl(Number(chillReelState?.game?.id||0))},2000);
