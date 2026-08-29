const quizTokenKey='kr_quiz_token';
const quizLocalIdentifierKey='kr_quiz_local_identifier';
let quizToken=localStorage.getItem(quizTokenKey)||'';
let quizLocalIdentifier=localStorage.getItem(quizLocalIdentifierKey)||'';
if(!/^[a-f0-9-]{36}$/i.test(quizLocalIdentifier)){quizLocalIdentifier=crypto.randomUUID();localStorage.setItem(quizLocalIdentifierKey,quizLocalIdentifier)}
let quizPlayerState=null;
let quizPublicClockOffset=0;
let quizLastOpenQuestionId=0;
let publicModules={requests_enabled:true,quiz_enabled:true};

function activatePublicMode(mode){
  if(mode==='requests'&&!publicModules.requests_enabled)mode=publicModules.quiz_enabled?'quiz':'disabled';
  if(mode==='quiz'&&!publicModules.quiz_enabled)mode=publicModules.requests_enabled?'requests':'disabled';
  document.querySelectorAll('[data-public-mode]').forEach(item=>item.classList.toggle('active',item.dataset.publicMode===mode));
  $('#public-requests').classList.toggle('hidden',mode!=='requests');
  $('#public-quiz').classList.toggle('hidden',mode!=='quiz');
  $('#public-disabled').classList.toggle('hidden',mode!=='disabled');
}

async function loadPublicModules(){try{const response=await fetch('api.php?action=public-modules',{cache:'no-store'});const data=await response.json();if(!response.ok)throw new Error(data.error||'Configurazione non disponibile');publicModules={requests_enabled:!!data.requests_enabled,quiz_enabled:!!data.quiz_enabled};document.querySelector('[data-public-mode="requests"]').classList.toggle('hidden',!publicModules.requests_enabled);document.querySelector('[data-public-mode="quiz"]').classList.toggle('hidden',!publicModules.quiz_enabled);activatePublicMode(document.querySelector('[data-public-mode].active')?.dataset.publicMode||'requests')}catch(error){}}

document.addEventListener('click',event=>{
  const tab=event.target.closest('[data-public-mode]');
  if(!tab)return;
  activatePublicMode(tab.dataset.publicMode);
  if(tab.dataset.publicMode==='quiz')refreshPublicQuiz();
});

$('#quiz-join-form').addEventListener('submit',async event=>{
  event.preventDefault();
  const response=await fetch('api.php?action=quiz-join',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:$('#quiz-player-name').value.trim(),token:quizToken,local_identifier:quizLocalIdentifier})});
  const data=await response.json();
  if(!response.ok){alert(data.error||'Accesso non riuscito');return}
  quizToken=data.participant.public_token;
  localStorage.setItem(quizTokenKey,quizToken);
  refreshPublicQuiz();
});

function quizRanking(items){return items.slice(0,5).map((item,index)=>`<div class="quiz-public-ranking-row rank-${index+1}"><b>${index+1}</b><strong>${escapeHtml(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} punti</span></div>`).join('')}

function renderPublicQuiz(data){
  quizPublicClockOffset=Number(data.server_time_ms||Date.now())-Date.now();
  quizPlayerState=data;
  const participant=data.participant;
  if(!participant){$('#quiz-join').classList.remove('hidden');$('#quiz-player').classList.add('hidden');return}
  $('#quiz-join').classList.add('hidden');
  $('#quiz-player').classList.remove('hidden');
  $('#quiz-player-content').classList.remove('quiz-waiting','quiz-pending');
  $('#quiz-player-name-label').textContent=participant.display_name;
  const status=participant.status||'active';
  if(status==='pending'){
    $('#quiz-player-timer').textContent='--';
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting quiz-pending">Rientro richiesto.<br>Attendi che la regia ti riattivi.</div>';
    $('#quiz-player-ranking').innerHTML='';
    return;
  }
  if(status==='removed'){
    $('#quiz-player-timer').textContent='--';
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting quiz-pending">Partecipante rimosso dalla regia.<br>Rientra con un nuovo nome se autorizzato.</div>';
    $('#quiz-player-ranking').innerHTML='';
    return;
  }
  const question=data.question;
  if(['betting','open'].includes(question?.status)&&Number(question.id)!==quizLastOpenQuestionId){
    quizLastOpenQuestionId=Number(question.id);
    activatePublicMode('quiz');
  }
  const timerTarget=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;
  const seconds=question&&['open','revealed'].includes(question.status)&&timerTarget?Math.max(0,Math.ceil((Number(timerTarget)-(Date.now()+quizPublicClockOffset))/1000)):null;
  let rankingInContent=false;
  $('#quiz-player-timer').textContent=seconds===null?'--':seconds;
  if(!question){
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting">In attesa della prima domanda…</div>';
  }else if(question.status==='betting'){
    const points=Number(participant.points||0),bet=question.bet||null,half=Math.max(1,Math.floor(points/2));
    $('#quiz-player-timer').textContent='--';
    $('#quiz-player-content').innerHTML=`<small>PUNTATA SULLA PROSSIMA DOMANDA</small><h2>Quanto vuoi rischiare?</h2><p class="quiz-bet-score">Punteggio attuale: <b>${points.toLocaleString('it-IT')}</b></p><div class="quiz-bet-grid"><button type="button" data-quiz-bet="half" class="quiz-bet ${bet?.mode==='half'?'selected':''}" ${points<1?'disabled':''}><b>METÀ</b><span>${half.toLocaleString('it-IT')} punti</span></button><button type="button" data-quiz-bet="all_in" class="quiz-bet all-in ${bet?.mode==='all_in'?'selected':''}" ${points<1?'disabled':''}><b>ALL-IN</b><span>${points.toLocaleString('it-IT')} punti</span></button></div><p>${bet?`Puntata registrata: ${Number(bet.stake_points).toLocaleString('it-IT')} punti`:(points<1?'Servono punti per partecipare alla puntata.':'Puoi cambiare scelta finché la domanda non parte.')}</p>`;
  }else if(question.status==='draft'){
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting">La prossima domanda è quasi pronta…</div>';
  }else if(question.status==='closed'){
    $('#quiz-player-timer').textContent='--';
    rankingInContent=true;
    $('#quiz-player-content').innerHTML=`<div class="quiz-waiting"><h2>Classifica della serata</h2>${data.leaderboard.length?quizRanking(data.leaderboard):'<p>Nessun punteggio registrato.</p>'}</div>`;
  }else{
    $('#quiz-player-content').innerHTML=`<small>${escapeHtml([question.artist,question.title].filter(Boolean).join(' — '))}</small><h2>${escapeHtml(question.question)}</h2><div class="quiz-answer-grid">${Object.entries(question.options).map(([letter,text])=>`<button type="button" data-quiz-answer="${letter}" class="quiz-answer ${question.selected_option===letter?'selected':''} ${question.status==='revealed'&&question.correct_option===letter?'correct':''}" ${question.answered||question.status!=='open'?'disabled':''}><b>${letter}</b><span>${escapeHtml(text)}</span></button>`).join('')}</div><p>${question.answered?'Risposta registrata. Attendi la soluzione.':question.status==='open'?'Scegli una risposta':'Risposte chiuse.'}</p>`;
  }
  $('#quiz-player-ranking').innerHTML=!rankingInContent&&data.leaderboard.length?`<h3>Classifica della serata</h3>${quizRanking(data.leaderboard)}`:'';
}

$('#quiz-player-content').addEventListener('click',async event=>{
  const betButton=event.target.closest('[data-quiz-bet]');
  if(betButton&&quizPlayerState?.question?.status==='betting'){
    document.querySelectorAll('[data-quiz-bet]').forEach(item=>item.disabled=true);
    const response=await fetch('api.php?action=quiz-bet-place',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question_id:quizPlayerState.question.id,token:quizToken,mode:betButton.dataset.quizBet})});
    const data=await response.json();if(!response.ok)alert(data.error||'Puntata non accettata');refreshPublicQuiz();return;
  }
  const button=event.target.closest('[data-quiz-answer]');
  if(!button||!quizPlayerState?.question)return;
  document.querySelectorAll('[data-quiz-answer]').forEach(item=>item.disabled=true);
  const response=await fetch('api.php?action=quiz-answer',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question_id:quizPlayerState.question.id,token:quizToken,option:button.dataset.quizAnswer})});
  const data=await response.json();
  if(!response.ok)alert(data.error||'Risposta non accettata');
  refreshPublicQuiz();
});

async function refreshPublicQuiz(){try{const response=await fetch(`api.php?action=quiz-state&token=${encodeURIComponent(quizToken)}`,{cache:'no-store'});renderPublicQuiz(await response.json())}catch(error){}}
async function quizHeartbeat(){if(!quizToken)return;try{await fetch('api.php?action=quiz-heartbeat',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:quizToken}),keepalive:true})}catch(error){}}

window.addEventListener('beforeunload',event=>{const question=quizPlayerState?.question;if(question?.status==='open'&&!question.answered){event.preventDefault();event.returnValue=''}});
window.addEventListener('pagehide',()=>{if(quizToken)navigator.sendBeacon('api.php?action=quiz-leave',new Blob([JSON.stringify({token:quizToken})],{type:'application/json'}))});
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')quizHeartbeat()});
loadPublicModules().then(()=>{if(quizToken)activatePublicMode('quiz')});
refreshPublicQuiz();
quizHeartbeat();
setInterval(refreshPublicQuiz,700);
setInterval(quizHeartbeat,3000);
setInterval(()=>{const question=quizPlayerState?.question;const target=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;if(!question||!['open','revealed'].includes(question.status)||!target)return;$('#quiz-player-timer').textContent=Math.max(0,Math.ceil((Number(target)-(Date.now()+quizPublicClockOffset))/1000))},100);
