const escapeScreen=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
let screenState=null;
let screenClockOffset=0;
function quizTimerSeconds(question){
  const target=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;
  return question&&['open','revealed'].includes(question.status)&&target?Math.max(0,Math.ceil((Number(target)-(Date.now()+screenClockOffset))/1000)):null;
}
function renderScreen(data){
  screenState=data;
  screenClockOffset=Number(data.server_time_ms||Date.now())-Date.now();
  const question=data.question;
  const seconds=quizTimerSeconds(question);
  document.querySelector('#screen-timer').textContent=seconds===null?'--':seconds;
  document.querySelector('#screen-track').textContent=question&&question.artist?`${question.artist} — ${question.title}`:'Musica e divertimento';
  const target=document.querySelector('#screen-question');
  if(!question||question.status==='draft'){
    target.innerHTML='<div class="screen-waiting">Il prossimo quiz sta per iniziare</div>';
  }else if(question.status==='closed'){
    target.innerHTML='<div class="screen-waiting">Risposte chiuse<br>Classifica live</div>';
  }else{
    target.innerHTML=`<div class="screen-question-head"><span>${question.status==='open'?'RISPONDI ORA':'SOLUZIONE'}</span><small>${question.answers_count} risposte</small></div><h1>${escapeScreen(question.question)}</h1><div class="screen-options">${Object.entries(question.options).map(([letter,text])=>`<div class="${question.status==='revealed'&&question.correct_option===letter?'correct':''}"><b>${letter}</b><span>${escapeScreen(text)}</span></div>`).join('')}</div>`;
  }
  document.querySelector('#screen-ranking').innerHTML=data.leaderboard.length?data.leaderboard.slice(0,10).map((item,index)=>`<div><b>${index+1}</b><strong>${escapeScreen(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')}</span></div>`).join(''):'<p>La classifica apparirà dopo le prime risposte.</p>';
}
async function refreshScreen(){try{const response=await fetch('api.php?action=quiz-state',{cache:'no-store'});renderScreen(await response.json())}catch(error){}}
refreshScreen();
setInterval(refreshScreen,600);
setInterval(()=>{const seconds=quizTimerSeconds(screenState?.question);if(seconds!==null)document.querySelector('#screen-timer').textContent=seconds},100);
