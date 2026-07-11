const quizTokenKey='kr_quiz_token';
let quizToken=localStorage.getItem(quizTokenKey)||'';
let quizPlayerState=null;
let quizPublicClockOffset=0;

document.addEventListener('click',event=>{
  const tab=event.target.closest('[data-public-mode]');
  if(!tab)return;
  document.querySelectorAll('[data-public-mode]').forEach(item=>item.classList.toggle('active',item===tab));
  $('#public-requests').classList.toggle('hidden',tab.dataset.publicMode!=='requests');
  $('#public-quiz').classList.toggle('hidden',tab.dataset.publicMode!=='quiz');
  if(tab.dataset.publicMode==='quiz')refreshPublicQuiz();
});

$('#quiz-join-form').addEventListener('submit',async event=>{
  event.preventDefault();
  const response=await fetch('api.php?action=quiz-join',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:$('#quiz-player-name').value.trim(),token:quizToken})});
  const data=await response.json();
  if(!response.ok){alert(data.error||'Accesso non riuscito');return}
  quizToken=data.participant.public_token;
  localStorage.setItem(quizTokenKey,quizToken);
  refreshPublicQuiz();
});

function quizRanking(items){return items.slice(0,5).map((item,index)=>`<div><b>${index+1}</b><strong>${escapeHtml(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} pt</span></div>`).join('')}

function renderPublicQuiz(data){
  quizPublicClockOffset=Number(data.server_time_ms||Date.now())-Date.now();
  quizPlayerState=data;
  const participant=data.participant;
  if(!participant){$('#quiz-join').classList.remove('hidden');$('#quiz-player').classList.add('hidden');return}
  $('#quiz-join').classList.add('hidden');
  $('#quiz-player').classList.remove('hidden');
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
  const timerTarget=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;
  const seconds=question&&['open','revealed'].includes(question.status)&&timerTarget?Math.max(0,Math.ceil((Number(timerTarget)-(Date.now()+quizPublicClockOffset))/1000)):null;
  $('#quiz-player-timer').textContent=seconds===null?'--':seconds;
  if(!question){
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting">In attesa della prima domanda…</div>';
  }else if(question.status==='draft'){
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting">La prossima domanda è quasi pronta…</div>';
  }else if(question.status==='closed'){
    $('#quiz-player-timer').textContent='--';
    $('#quiz-player-content').innerHTML='<div class="quiz-waiting">Risposte chiuse.<br>Guarda la classifica.</div>';
  }else{
    $('#quiz-player-content').innerHTML=`<small>${escapeHtml([question.artist,question.title].filter(Boolean).join(' — '))}</small><h2>${escapeHtml(question.question)}</h2><div class="quiz-answer-grid">${Object.entries(question.options).map(([letter,text])=>`<button type="button" data-quiz-answer="${letter}" class="quiz-answer ${question.selected_option===letter?'selected':''} ${question.status==='revealed'&&question.correct_option===letter?'correct':''}" ${question.answered||question.status!=='open'?'disabled':''}><b>${letter}</b><span>${escapeHtml(text)}</span></button>`).join('')}</div><p>${question.answered?'Risposta registrata. Attendi la soluzione.':question.status==='open'?'Scegli una risposta':'Risposte chiuse.'}</p>`;
  }
  $('#quiz-player-ranking').innerHTML=data.leaderboard.length?`<h3>Classifica</h3>${quizRanking(data.leaderboard)}`:'';
}

$('#quiz-player-content').addEventListener('click',async event=>{
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
refreshPublicQuiz();
quizHeartbeat();
setInterval(refreshPublicQuiz,700);
setInterval(quizHeartbeat,3000);
setInterval(()=>{const question=quizPlayerState?.question;const target=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;if(!question||!['open','revealed'].includes(question.status)||!target)return;$('#quiz-player-timer').textContent=Math.max(0,Math.ceil((Number(target)-(Date.now()+quizPublicClockOffset))/1000))},100);
