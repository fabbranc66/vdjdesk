const escapeScreen=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
let screenState=null;
let screenClockOffset=0;
let screenQuizEnabled=false;
function quizTimerSeconds(question){
  const target=question?.status==='revealed'?question.revealed_until_ms:question?.closes_at_ms;
  return question&&['open','revealed'].includes(question.status)&&target?Math.max(0,Math.ceil((Number(target)-(Date.now()+screenClockOffset))/1000)):null;
}
const pauseScreen=(data,label)=>data.group?.image_url?`<div class="screen-break"><img src="${escapeScreen(data.group.image_url)}" alt="${escapeScreen(data.group.name||'Immagine serata')}"><span>${escapeScreen(label)}</span></div>`:`<div class="screen-waiting"><div><h1>${escapeScreen(data.group?.name||'Quiz Live')}</h1><p>${escapeScreen(label)}</p></div></div>`;
function finalPodium(items){
  const winners=items.slice(0,3),ordered=[winners[1],winners[0],winners[2]].filter(Boolean);
  return `<div class="screen-final"><h1>PODIO FINALE</h1><div class="screen-podium">${ordered.map(item=>{const rank=winners.indexOf(item)+1;return `<div class="podium-rank rank-${rank}"><b>${rank}</b><strong>${escapeScreen(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} punti</span></div>`}).join('')}</div></div>`;
}
function fastestPlayerPopup(player){return player?`<div class="screen-fastest"><small>RISPOSTA PIÙ VELOCE</small><strong>${escapeScreen(player.display_name)}</strong><span>+${Number(player.bonus_points).toLocaleString('it-IT')} punti bonus</span></div>`:''}
function renderScreen(data){
  document.querySelector('main').classList.remove('quiz-disabled');
  screenState=data;
  screenClockOffset=Number(data.server_time_ms||Date.now())-Date.now();
  const question=data.question;
  const seconds=quizTimerSeconds(question);
  document.querySelector('#screen-timer').textContent=seconds===null?'--':seconds;
  document.querySelector('#screen-track').textContent=question&&question.artist?`${question.artist} — ${question.title}`:data.group?.name||'Musica e divertimento';
  const target=document.querySelector('#screen-question');
  if(!question||question.status==='draft'){
    target.innerHTML=pauseScreen(data,'Il prossimo quiz sta per iniziare');
  }else if(question.status==='betting'){
    target.innerHTML=pauseScreen(data,'Puntate aperte · Preparatevi');
  }else if(question.status==='closed'){
    target.innerHTML=data.group_complete?finalPodium(data.leaderboard):pauseScreen(data,'Risposte chiuse · Classifica live');
  }else{
    target.innerHTML=`<div class="screen-question-head"><span>${question.status==='open'?'RISPONDI ORA':'SOLUZIONE'}</span><small>${question.answers_count} risposte</small></div><h1>${escapeScreen(question.question)}</h1><div class="screen-options">${Object.entries(question.options).map(([letter,text])=>`<div class="${question.status==='revealed'&&question.correct_option===letter?'correct':''}"><b>${letter}</b><span>${escapeScreen(text)}</span></div>`).join('')}</div>`;
  }
  if(question?.status==='closed'&&data.fastest_player)target.insertAdjacentHTML('beforeend',fastestPlayerPopup(data.fastest_player));
  document.querySelector('#screen-ranking').innerHTML=data.leaderboard.length?data.leaderboard.slice(0,10).map((item,index)=>`<div class="rank-${index+1}"><b>${index+1}</b><strong>${escapeScreen(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} punti</span></div>`).join(''):'<p>La classifica apparirà al termine della prima domanda.</p>';
}
function renderQuizDisabled(){document.querySelector('main').classList.add('quiz-disabled');document.querySelector('#screen-timer').textContent='--';document.querySelector('#screen-track').textContent='Quiz non disponibile';document.querySelector('#screen-question').innerHTML='<div class="screen-disabled"><strong>Quiz non disponibile</strong><span>La Regia non ha ancora attivato il quiz.</span></div>';document.querySelector('#screen-ranking').innerHTML=''}
async function refreshScreen(){if(!screenQuizEnabled)return;try{const response=await fetch('api.php?action=quiz-state',{cache:'no-store'});if(response.status===403){screenQuizEnabled=false;renderQuizDisabled();return}if(!response.ok)return;renderScreen(await response.json())}catch(error){}}
async function refreshScreenAvailability(){try{const response=await fetch('api.php?action=public-modules',{cache:'no-store'});if(!response.ok)return;const data=await response.json();screenQuizEnabled=!!data.quiz_enabled;if(!screenQuizEnabled){renderQuizDisabled();return}refreshScreen()}catch(error){}}
refreshScreenAvailability();
setInterval(refreshScreen,600);
setInterval(refreshScreenAvailability,2000);
setInterval(()=>{const seconds=quizTimerSeconds(screenState?.question);if(seconds!==null)document.querySelector('#screen-timer').textContent=seconds},100);
