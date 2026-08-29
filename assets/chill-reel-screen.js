const $=selector=>document.querySelector(selector);
const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
const wheelSegments=['100','500','200','PASSA','300','700','150','RADDOPPIA','400','250','600','PERDI TURNO','100','800','350','JOLLY','200','500','300','BANCAROTTA','1000','400','250','PASSA'];
let wheelSpinToken=0,wheelRunning=false,wheelDecelerating=false,wheelAngle=0,wheelSpeed=0,wheelFrame=0,wheelLastTime=0;
let currentGameId=0,wheelPointerPressed=false,wheelStartRequest=null;
let screenBoardPuzzleId=0,screenBoardLetters=null;
let screenBoardSignature='';

function boardHtml(solution,letters,previousLetters){return String(solution||'').split(/\s+/).filter(Boolean).map(word=>`<span class="word">${[...word].map(char=>{const isLetter=/[A-ZÀ-ÖØ-Ý]/u.test(char);const revealed=isLetter&&letters.includes(char);const isNew=revealed&&previousLetters!==null&&!previousLetters.includes(char);return isLetter&&!revealed?'<i>&nbsp;</i>':`<i class="${[!isLetter?'punctuation':'',isNew?'revealed-now':''].filter(Boolean).join(' ')}">${escapeHtml(char)}</i>`}).join('')}</span>`).join('')}

function prepareWheel(){
  const wheel=$('#reel-screen-wheel');
  if(wheel.querySelector('.wheel-art'))return;
  const namespace='http://www.w3.org/2000/svg';
  const artwork=document.createElementNS(namespace,'svg');
  artwork.setAttribute('class','wheel-art');
  artwork.setAttribute('viewBox','0 0 280 280');
  const center=140;
  const radius=134;
  const labelRadius=108;
  const specialLabels={'RADDOPPIA':'X2','PERDI TURNO':'SALTA','BANCAROTTA':'ZERO'};
  const colors=['#ef476f','#ffd166','#06d6a0','#118ab2','#f78c6b','#9b5de5','#00bbf9','#f15bb5'];
  const polar=(angle,distance)=>({x:center+Math.cos(angle*Math.PI/180)*distance,y:center+Math.sin(angle*Math.PI/180)*distance});
  wheelSegments.forEach((label,index)=>{
    const start=index*(360/wheelSegments.length)-90;
    const end=(index+1)*(360/wheelSegments.length)-90;
    const from=polar(start,radius);
    const to=polar(end,radius);
    const path=document.createElementNS(namespace,'path');
    const special=!/^\d+$/.test(label);
    const fill=label==='JOLLY'?'#e91e63':special?'#11131a':colors[index%colors.length];
    path.setAttribute('d',`M ${center} ${center} L ${from.x} ${from.y} A ${radius} ${radius} 0 0 1 ${to.x} ${to.y} Z`);
    path.setAttribute('fill',fill);
    path.setAttribute('stroke','#f4d35e');
    path.setAttribute('stroke-width','1.5');
    artwork.appendChild(path);
    const element=document.createElementNS(namespace,'text');
    const degrees=(index+.5)*(360/wheelSegments.length)-90;
    const radians=degrees*Math.PI/180;
    const x=center+Math.cos(radians)*labelRadius;
    const y=center+Math.sin(radians)*labelRadius;
    element.setAttribute('class',special?'wheel-special':'wheel-number');
    element.setAttribute('x',String(x));
    element.setAttribute('y',String(y));
    element.setAttribute('text-anchor','middle');
    element.setAttribute('dominant-baseline','middle');
    element.setAttribute('transform',`rotate(${degrees} ${x} ${y})`);
    element.textContent=specialLabels[label]||label;
    artwork.appendChild(element);
  });
  wheel.appendChild(artwork);
}

function wheelTick(time){
  if(!wheelRunning)return;
  const elapsed=Math.min(.05,(time-wheelLastTime)/1000||0);
  wheelLastTime=time;
  wheelSpeed=Math.min(920,wheelSpeed+1050*elapsed);
  wheelAngle=(wheelAngle+wheelSpeed*elapsed)%360;
  $('#reel-screen-wheel').style.transform=`rotate(${wheelAngle}deg)`;
  wheelFrame=requestAnimationFrame(wheelTick);
}

function startWheel(){
  if(wheelRunning)return;
  const wheel=$('#reel-screen-wheel');
  wheel.getAnimations().forEach(animation=>animation.cancel());
  wheelDecelerating=false;
  wheelRunning=true;
  wheelSpeed=Math.max(wheelSpeed,120);
  wheelLastTime=performance.now();
  $('#reel-screen-wheel-result').textContent='…';
  wheelFrame=requestAnimationFrame(wheelTick);
}

function stopWheel(result,token){
  $('#reel-screen-wheel-result').textContent='…';
  if(!wheelRunning&&!wheelDecelerating){$('#reel-screen-wheel-result').textContent=result||'—';return}
  if(wheelDecelerating)return;
  wheelRunning=false;
  wheelDecelerating=true;
  cancelAnimationFrame(wheelFrame);
  const wheel=$('#reel-screen-wheel');
  const matches=wheelSegments.map((label,index)=>label===result?index:-1).filter(index=>index>=0);
  const index=matches.length?matches[token%matches.length]:0;
  const step=360/wheelSegments.length;
  const target=(360-((index+.5)*step))%360;
  const baseDelta=(target-wheelAngle+360)%360;
  const releaseSpeed=Math.max(wheelSpeed,90);
  const suspensePower=3.2;
  const desiredDuration=6.4;
  const desiredDistance=(releaseSpeed*desiredDuration)/suspensePower;
  const rotations=Math.max(1,Math.round((desiredDistance-baseDelta)/360));
  const distance=baseDelta+(rotations*360);
  const duration=(distance*suspensePower)/releaseSpeed;
  const startAngle=wheelAngle;
  const startTime=performance.now();
  const decelerate=time=>{
    const elapsed=Math.min(duration,(time-startTime)/1000);
    const progress=elapsed/duration;
    const travelled=distance*(1-Math.pow(1-progress,suspensePower));
    wheelAngle=startAngle+travelled;
    wheel.style.transform=`rotate(${wheelAngle}deg)`;
    if(elapsed<duration){wheelFrame=requestAnimationFrame(decelerate);return}
    wheelAngle=target;
    wheel.style.transform=`rotate(${wheelAngle}deg)`;
    wheelSpeed=0;
    wheelDecelerating=false;
    $('#reel-screen-wheel-result').textContent=result||'—';
    postWheel('chill-reel-spin-finish').then(renderScreen).catch(()=>{});
  };
  wheelFrame=requestAnimationFrame(decelerate);
}

function renderCompletedScreen(data){
  const game=data.game;
  wheelRunning=false;
  wheelDecelerating=false;
  cancelAnimationFrame(wheelFrame);
  wheelSpeed=0;
  const ranking=data.tables.slice().sort((left,right)=>Number(right.score)-Number(left.score)||Number(left.registration_order)-Number(right.registration_order));
  const winner=ranking[0];
  $('#reel-screen-round').textContent=game?.name||'Manche conclusa';
  $('#reel-screen-status').textContent=data.active_game==='chill_reel'?'ON':'OFF';
  $('#reel-screen-status').classList.toggle('on',data.active_game==='chill_reel');
  $('#reel-screen-category').textContent='FINE MANCHE';
  $('#reel-screen-board').innerHTML=`<div class="screen-message"><small>FINE MANCHE</small><br>Vince ${escapeHtml(winner?.name||'—')}<br><small>${Number(winner?.score||0).toLocaleString('it-IT')} punti</small></div>`;
  $('#reel-screen-turn').textContent='Manche conclusa';
  $('#reel-screen-progress').textContent=`${data.puzzles.length} / ${data.puzzles.length}`;
  $('#reel-screen-letters').textContent='—';
  $('#reel-screen-wheel-result').textContent='—';
  $('#reel-screen-tables').innerHTML=ranking.length?ranking.map((item,index)=>`<div class="table-row ${index===0?'current':''}"><b>${index+1}</b><span>${escapeHtml(item.name)}<small>${index===0?'Vincitore':''}</small></span><strong>${Number(item.score)} pt</strong></div>`).join(''):'<p>Nessun tavolo registrato</p>';
}

function renderScreen(data){
  const game=data.game;
  currentGameId=Number(game?.id||0);
  if(game?.status==='completed'){
    renderCompletedScreen(data);
    return;
  }
  const active=data.puzzles.find(item=>Number(item.id)===Number(game?.current_puzzle_id));
  const currentTable=data.tables.find(item=>Number(item.id)===Number(game?.current_table_id));
  const activeIndex=active?data.puzzles.indexOf(active)+1:0;
  const revealedLetters=String(active?.revealed_letters||'');
  if(Number(active?.id||0)!==screenBoardPuzzleId){screenBoardPuzzleId=Number(active?.id||0);screenBoardLetters=null}
  $('#reel-screen-round').textContent=game?.name||'In attesa della manche';
  $('#reel-screen-status').textContent=data.active_game==='chill_reel'?'ON':'OFF';
  $('#reel-screen-status').classList.toggle('on',data.active_game==='chill_reel');
  $('#reel-screen-category').textContent=active?.category||(game?.status==='booking'?'PRENOTAZIONE':'CHILL REEL');
  const boardSignature=active?`${active.id}|${active.solution}|${revealedLetters}`:`empty|${game?.status||''}`;
  if(boardSignature!==screenBoardSignature){$('#reel-screen-board').innerHTML=active?boardHtml(active.solution,revealedLetters,screenBoardLetters):`<div class="screen-message">${game?.status==='booking'?'Prenotatevi: il primo tavolo inizierà la manche':'La manche sta per iniziare'}</div>`;screenBoardSignature=boardSignature}
  screenBoardLetters=active?revealedLetters:null;
  $('#reel-screen-turn').textContent=currentTable?.name||(game?.status==='booking'?'Prenotazione aperta':'—');
  $('#reel-screen-progress').textContent=`${activeIndex} / ${data.puzzles.length}`;
  $('#reel-screen-letters').textContent=active?.revealed_letters||'—';
  const nextSpinToken=Number(game?.wheel_spin_token||0);
  if(nextSpinToken!==wheelSpinToken){
    wheelSpinToken=nextSpinToken;
    if(Number(game?.wheel_spinning)===1)startWheel();
    else stopWheel(game?.wheel_result||'—',nextSpinToken);
  }else if(!wheelRunning&&!wheelDecelerating&&Number(game?.wheel_spinning)!==1)$('#reel-screen-wheel-result').textContent=game?.wheel_result||'—';
  $('#reel-screen-tables').innerHTML=data.tables.length?data.tables.slice().sort((a,b)=>Number(b.score)-Number(a.score)||Number(a.registration_order)-Number(b.registration_order)).map((item,index)=>`<div class="table-row ${Number(item.id)===Number(game?.current_table_id)?'current':''}"><b>${index+1}</b><span>${escapeHtml(item.name)}<small>${item.booked_at?'Prenotato':''}</small></span><strong>${Number(item.score)} pt</strong></div>`).join(''):'<p>Nessun tavolo registrato</p>';
}

async function refreshScreen(){try{const response=await fetch('api.php?action=chill-reel-state',{cache:'no-store'});const data=await response.json();if(!response.ok||data.error)throw new Error(data.error||'Errore stato');renderScreen(data)}catch(error){$('#reel-screen-board').innerHTML='<div class="screen-message">Schermo Chill Reel non disponibile</div>'}}
async function postWheel(action){const response=await fetch(`api.php?action=${action}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({game_id:currentGameId})});const data=await response.json();if(!response.ok||data.error)throw new Error(data.error||'Errore ruota');return data}
const wheelElement=$('#reel-screen-wheel');
wheelElement.addEventListener('pointerdown',event=>{
  if(event.button!==0||!currentGameId||wheelPointerPressed)return;
  event.preventDefault();
  wheelPointerPressed=true;
  wheelElement.setPointerCapture(event.pointerId);
  wheelElement.classList.add('pressed');
  startWheel();
  wheelStartRequest=postWheel('chill-reel-spin-start').then(renderScreen);
});
async function releaseWheel(){
  if(!wheelPointerPressed)return;
  wheelPointerPressed=false;
  wheelElement.classList.remove('pressed');
  try{await wheelStartRequest;renderScreen(await postWheel('chill-reel-spin'))}catch(error){wheelRunning=false;cancelAnimationFrame(wheelFrame);$('#reel-screen-wheel-result').textContent='ERRORE'}finally{wheelStartRequest=null}
}
wheelElement.addEventListener('pointerup',releaseWheel);
wheelElement.addEventListener('pointercancel',releaseWheel);
window.addEventListener('pointerup',releaseWheel,true);
window.addEventListener('pointercancel',releaseWheel,true);
window.addEventListener('touchend',releaseWheel,{passive:true,capture:true});
window.addEventListener('touchcancel',releaseWheel,{passive:true,capture:true});
window.addEventListener('blur',releaseWheel);
prepareWheel();
refreshScreen();
setInterval(refreshScreen,250);
