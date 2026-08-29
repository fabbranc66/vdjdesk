const escapeScreen=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
let screenState=null;
let screenClockOffset=0;
function quizTimerSeconds(question){
  const target=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;
  return question&&['open','revealed'].includes(question.status)&&target?Math.max(0,Math.ceil((Number(target)-(Date.now()+screenClockOffset))/1000)):null;
}
const birthdayScreen=label=>`<div class="screen-break"><img src="assets/images/amina-birthday.png" alt="Amina's Birthday"><span>${label}</span></div>`;
function finalPodium(items){
  const winners=items.slice(0,3),ordered=[winners[1],winners[0],winners[2]].filter(Boolean);
  return `<div class="screen-final"><h1>PODIO FINALE</h1><div class="screen-podium">${ordered.map(item=>{const rank=winners.indexOf(item)+1;return `<div class="podium-rank rank-${rank}"><b>${rank}</b><strong>${escapeScreen(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} punti</span></div>`}).join('')}</div></div>`;
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
    target.innerHTML=birthdayScreen('Il prossimo quiz sta per iniziare');
  }else if(question.status==='betting'){
    target.innerHTML=birthdayScreen('Puntate aperte · Preparatevi');
  }else if(question.status==='closed'){
    target.innerHTML=data.group_complete?finalPodium(data.leaderboard):birthdayScreen('Risposte chiuse · Classifica live');
  }else{
    target.innerHTML=`<div class="screen-question-head"><span>${question.status==='open'?'RISPONDI ORA':'SOLUZIONE'}</span><small>${question.answers_count} risposte</small></div><h1>${escapeScreen(question.question)}</h1><div class="screen-options">${Object.entries(question.options).map(([letter,text])=>`<div class="${question.status==='revealed'&&question.correct_option===letter?'correct':''}"><b>${letter}</b><span>${escapeScreen(text)}</span></div>`).join('')}</div>`;
  }
  document.querySelector('#screen-ranking').innerHTML=data.leaderboard.length?data.leaderboard.slice(0,10).map((item,index)=>`<div class="rank-${index+1}"><b>${index+1}</b><strong>${escapeScreen(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} punti</span></div>`).join(''):'<p>La classifica apparirà al termine della prima domanda.</p>';
}
async function refreshScreen(){try{const response=await fetch('api.php?action=quiz-state',{cache:'no-store'});renderScreen(await response.json())}catch(error){}}
refreshScreen();
setInterval(refreshScreen,600);
setInterval(()=>{const seconds=quizTimerSeconds(screenState?.question);if(seconds!==null)document.querySelector('#screen-timer').textContent=seconds},100);
