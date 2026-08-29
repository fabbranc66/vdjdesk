(function(){
const $=selector=>document.querySelector(selector);
const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
const tokenKey='kr_chill_reel_token';
const localIdentifierKey='kr_chill_reel_local_identifier';
const consonants=[...'BCDFGHJKLMNPQRSTVWXYZ'];
const vowels=[...'AEIOU'];
let token=localStorage.getItem(tokenKey)||'';
let localIdentifier=localStorage.getItem(localIdentifierKey)||'';
let playerState=null,spinPressed=false,spinRequest=null,actionView='choice';

if(!/^[a-f0-9-]{36}$/i.test(localIdentifier)){
  localIdentifier=crypto.randomUUID();
  localStorage.setItem(localIdentifierKey,localIdentifier);
}

async function api(action,options={}){
  const response=await fetch(`api.php?action=${action}`,options);
  const data=await response.json();
  if(!response.ok||data.error)throw new Error(data.error||'Operazione non riuscita');
  return data;
}

function post(action,payload){return api(action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})}
function message(text,error=false){$('#player-message').textContent=text;$('#player-message').style.color=error?'#ff7896':'#dbe9e3'}

function render(data){
  playerState=data;
  $('#player-game-name').textContent=data.game?.name||'Non attivo';
  $('#player-ranking').innerHTML=(data.players||[]).slice().sort((left,right)=>right.score-left.score).map((item,index)=>`<div class="ranking-row ${item.current?'current':''}"><b>${index+1}</b><span>${escapeHtml(item.name)}</span><strong>${Number(item.score).toLocaleString('it-IT')}</strong></div>`).join('')||'Nessun giocatore.';
  const player=data.player;
  const completed=data.game?.status==='completed';
  if(!player&&completed){
    const winner=(data.players||[]).slice().sort((left,right)=>Number(right.score)-Number(left.score)||Number(left.id)-Number(right.id))[0];
    $('#player-login').classList.add('hidden');
    $('#player-game').classList.remove('hidden');
    $('#player-name').textContent=winner?.name||'—';
    $('#player-score').textContent=Number(winner?.score||0).toLocaleString('it-IT');
    $('#player-turn').textContent='FINE MANCHE';
    $('#player-turn').classList.remove('active');
    $('#player-wait-phase').classList.remove('hidden');
    $('#player-wait-phase').innerHTML=`<span class="phase-kicker">MANCHE CONCLUSA</span><h2>${winner?`Vince ${escapeHtml(winner.name)}`:'Fine manche'}</h2><p>Classifica finale della manche.</p>`;
    $('#player-spin-phase').classList.add('hidden');
    $('#player-action-choice').classList.add('hidden');
    $('#player-letters').classList.add('hidden');
    $('#player-solve').classList.add('hidden');
    $('#player-next').textContent='';
    $('#player-ranking-card').classList.remove('hidden');
    return;
  }
  if(!player){
    if(token){token='';localStorage.removeItem(tokenKey)}
    $('#player-login').classList.remove('hidden');
    $('#player-game').classList.add('hidden');
    $('#player-ranking-card').classList.remove('hidden');
    return;
  }

  $('#player-login').classList.add('hidden');
  $('#player-game').classList.remove('hidden');
  $('#player-name').textContent=player.name;
  $('#player-score').textContent=Number(player.score).toLocaleString('it-IT');
  const pending=player.status==='pending';
  if(completed){
    const winner=(data.players||[]).slice().sort((left,right)=>Number(right.score)-Number(left.score)||Number(left.id)-Number(right.id))[0];
    $('#player-turn').textContent='FINE MANCHE';
    $('#player-turn').classList.remove('active');
    $('#player-wait-phase').classList.remove('hidden');
    $('#player-wait-phase').innerHTML=`<span class="phase-kicker">MANCHE CONCLUSA</span><h2>${winner?`Vince ${escapeHtml(winner.name)}`:'Fine manche'}</h2><p>Classifica finale della manche.</p>`;
    $('#player-spin-phase').classList.add('hidden');
    $('#player-action-choice').classList.add('hidden');
    $('#player-letters').classList.add('hidden');
    $('#player-solve').classList.add('hidden');
    $('#player-next').textContent='';
    $('#player-ranking-card').classList.remove('hidden');
    return;
  }
  $('#player-wait-phase').innerHTML='<span class="phase-kicker">PARTITA IN CORSO</span><h2>Guarda lo schermo principale</h2><p>La pagina si aggiorner&agrave; automaticamente quando arriver&agrave; il tuo turno.</p>';
  $('#player-turn').textContent=pending?'RIENTRO DA APPROVARE':player.own_turn?'È IL VOSTRO TURNO':'ATTENDETE IL VOSTRO TURNO';
  $('#player-turn').classList.toggle('active',player.own_turn&&!pending);
  const players=data.players||[];
  const currentIndex=players.findIndex(item=>item.current);
  const nextPlayer=currentIndex>=0&&players.length?players[(currentIndex+1)%players.length]:null;
  $('#player-next').textContent=nextPlayer?`PROSSIMO: ${nextPlayer.name}`:'';

  const spinning=Number(data.game?.wheel_spinning||0)>0;
  const canChoose=Boolean(data.can_choose_letter);
  const canSolve=Boolean(data.can_solve);
  if((actionView==='consonants'||actionView==='vowels')&&!canChoose)actionView='choice';
  if(actionView==='solve'&&!canSolve)actionView='choice';
  if(!spinPressed){
    $('#player-spin').disabled=!data.can_spin||spinning;
    $('#player-spin').textContent=spinning?'RUOTA IN MOVIMENTO':'TIENI PREMUTO · GIRA LA RUOTA';
  }
  const result=data.game?.wheel_result||'';
  $('#player-wheel-result').innerHTML=spinning?'RUOTA IN MOVIMENTO…':result?`<small>RISULTATO RUOTA</small><b>${escapeHtml(result)}</b>${/^\d+$/.test(result)?'<span>PUNTI</span>':''}`:'<small>RISULTATO RUOTA</small><b>—</b>';

  const dashboard=player.own_turn&&!pending&&actionView==='choice';
  $('#player-wait-phase').classList.toggle('hidden',player.own_turn&&!pending);
  $('#player-spin-phase').classList.toggle('hidden',!dashboard);
  $('#player-action-choice').classList.toggle('hidden',!dashboard);
  $('#player-open-consonants').disabled=!canChoose;
  $('#player-open-vowels').disabled=!canChoose||Number(player.score)<100;
  $('#player-open-solve').disabled=!canSolve;

  const selectingLetters=player.own_turn&&canChoose&&['consonants','vowels'].includes(actionView);
  $('#player-letters').classList.toggle('hidden',!selectingLetters);
  const revealed=String(data.puzzle?.revealed_letters||'');
  const letterSet=actionView==='vowels'?vowels:consonants;
  $('#player-letter-title').textContent=actionView==='vowels'?'SCEGLI UNA VOCALE · COSTO 100':'SCEGLI UNA CONSONANTE';
  $('#player-letter-grid').innerHTML=letterSet.map(letter=>`<button type="button" data-letter="${letter}" ${revealed.includes(letter)?'disabled':''}>${letter}</button>`).join('');
  $('#player-solve').classList.toggle('hidden',!(player.own_turn&&canSolve&&actionView==='solve'));
  $('#player-ranking-card').classList.toggle('hidden',player.own_turn&&!pending);
}

async function refresh(){try{render(await api(`chill-reel-player-state&token=${encodeURIComponent(token)}`))}catch(error){if($('#player-message'))message(error.message,true)}}

$('#player-join-form').addEventListener('submit',async event=>{
  event.preventDefault();
  try{
    const data=await post('chill-reel-player-join',{name:$('#player-join-name').value.trim(),token,local_identifier:localIdentifier});
    token=data.player.public_token;
    localStorage.setItem(tokenKey,token);
    await refresh();
  }catch(error){alert(error.message)}
});

$('#player-open-consonants').addEventListener('click',()=>{actionView='consonants';render(playerState)});
$('#player-open-vowels').addEventListener('click',()=>{actionView='vowels';render(playerState)});
$('#player-open-solve').addEventListener('click',()=>{actionView='solve';render(playerState);$('#player-answer').focus()});
document.querySelectorAll('.player-back-actions').forEach(button=>button.addEventListener('click',()=>{actionView='choice';render(playerState)}));

$('#player-letter-grid').addEventListener('click',async event=>{
  const button=event.target.closest('[data-letter]');
  if(!button||!playerState?.can_choose_letter)return;
  try{
    const data=await post('chill-reel-player-letter',{game_id:playerState.game.id,token,letter:button.dataset.letter});
    actionView='choice';render(data);
    const result=data.letter_result;
    if(result.vowel)message(result.occurrences?`${result.letter}: ${result.occurrences} · costo 100 punti`:`${result.letter} non presente · turno successivo`);
    else message(result.occurrences?`${result.letter}: ${result.occurrences} · +${result.points} punti`:`${result.letter} non presente · turno successivo`);
  }catch(error){message(error.message,true)}
});

$('#player-solve').addEventListener('submit',async event=>{
  event.preventDefault();
  const answer=$('#player-answer').value.trim();
  if(!answer)return;
  try{
    const data=await post('chill-reel-player-solve',{game_id:playerState.game.id,token,answer});
    actionView='choice';render(data);message(data.solve_correct?'SOLUZIONE ESATTA!':'Soluzione errata · turno successivo',!data.solve_correct);$('#player-answer').value='';
  }catch(error){message(error.message,true)}
});

const spinButton=$('#player-spin');
spinButton.addEventListener('pointerdown',event=>{
  if(event.button!==0||spinButton.disabled||spinPressed)return;
  event.preventDefault();spinPressed=true;spinButton.setPointerCapture(event.pointerId);spinButton.classList.add('pressed');spinButton.textContent='RILASCIA PER FERMARLA';
  spinRequest=post('chill-reel-player-spin-start',{game_id:playerState.game.id,token});
});

async function releaseSpin(){
  if(!spinPressed)return;
  spinPressed=false;spinButton.classList.remove('pressed');spinButton.textContent='DECELERAZIONE…';actionView='choice';
  try{
    await spinRequest;render(await post('chill-reel-player-spin',{game_id:playerState.game.id,token}));
    setTimeout(()=>post('chill-reel-player-spin-finish',{game_id:playerState.game.id,token}).then(render).catch(()=>refresh()),7000);
  }catch(error){message(error.message,true)}finally{spinRequest=null}
}

async function heartbeat(){if(!token)return;try{await post('chill-reel-player-heartbeat',{token})}catch(error){}}
spinButton.addEventListener('pointerup',releaseSpin);
spinButton.addEventListener('pointercancel',releaseSpin);
window.addEventListener('pointerup',releaseSpin,true);
window.addEventListener('pointercancel',releaseSpin,true);
window.addEventListener('touchend',releaseSpin,{passive:true,capture:true});
window.addEventListener('touchcancel',releaseSpin,{passive:true,capture:true});
window.addEventListener('blur',releaseSpin);
window.addEventListener('pagehide',()=>{if(token)navigator.sendBeacon('api.php?action=chill-reel-player-leave',new Blob([JSON.stringify({token})],{type:'application/json'}))});
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')heartbeat()});
refresh();heartbeat();setInterval(refresh,700);setInterval(heartbeat,3000);
})();
