const $=selector=>document.querySelector(selector);
let selected=null;
let timer;
let requestStatusTimer=null;
let automixRefreshTimer=null;
const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));

function clientToken(){
  let token=localStorage.getItem('kr_request_client_token')||'';
  if(!token){
    token=(window.crypto?.randomUUID?.()||`${Date.now()}-${Math.random()}`).replace(/[^a-zA-Z0-9-]/g,'');
    localStorage.setItem('kr_request_client_token',token);
  }
  return token;
}

function storedRequestTokens(){
  try{return JSON.parse(localStorage.getItem('kr_request_tokens')||'[]').filter(Boolean)}catch(error){return []}
}

function rememberRequestToken(token){
  const tokens=[token,...storedRequestTokens().filter(item=>item!==token)].slice(0,10);
  localStorage.setItem('kr_request_tokens',JSON.stringify(tokens));
  localStorage.setItem('kr_last_request_token',token);
}

function showRequestEstimate(request){
  const message=$('#public-message');
  if(!request)return;
  const label=request?.estimated_play_label||'';
  const track=[request.artist,request.title].filter(Boolean).join(' - ')||request.query||'il tuo brano';
  message.className='success-message';
  if(label){
    message.textContent=`Orario stimato del tuo brano ${track}: ${label}`;
    return;
  }
  if(request.status==='approved')message.textContent=`Richiesta approvata: ${track}`;
  else if(request.status==='rejected')message.textContent=`Richiesta non accettata: ${track}`;
  else if(request.status==='played')message.textContent=`Brano gia riprodotto: ${track}`;
  else message.textContent=request?.status_label||'Richiesta inviata al DJ.';
}

async function pollRequestStatus(token){
  if(!token)return;
  try{
    const response=await fetch(`api.php?action=request-status&token=${encodeURIComponent(token)}`,{cache:'no-store'});
    const data=await response.json();
    if(!response.ok||!data.request)return;
    showRequestEstimate(data.request);
    return data.request;
  }catch(error){}
  return null;
}

async function pollAllRequestStatuses(){
  const tokens=storedRequestTokens();
  if(!tokens.length)return;
  const requests=(await Promise.all(tokens.map(token=>pollRequestStatus(token)))).filter(Boolean);
  if(!requests.length)return;
  const priority={queued:5,approved:4,next:3,rejected:2,played:2,new:1};
  requests.sort((a,b)=>(priority[b.status]||0)-(priority[a.status]||0)||String(b.updated_at||'').localeCompare(String(a.updated_at||'')));
  showRequestEstimate(requests[0]);
}

async function refreshAutomixEstimates(){
  try{
    await fetch('api.php?action=request-estimates-refresh',{cache:'no-store'});
    await pollAllRequestStatuses();
  }catch(error){}
}

function renderPublicResults(items){
  const container=$('#public-results');
  container.innerHTML='';
  if(!items.length){
    const empty=document.createElement('div');
    empty.className='empty-state';
    empty.textContent='Non trovato: puoi inviare comunque il testo.';
    container.appendChild(empty);
    return;
  }
  items.forEach(track=>{
    const button=document.createElement('button');
    button.type='button';
    button.className='public-result';
    const artist=String(track.artist||'');
    const title=String(track.title||'');
    button.dataset.id=String(track.id||'');
    button.dataset.query=`${artist} - ${title}`;
    const strong=document.createElement('strong');
    strong.textContent=`${artist} - ${title}`;
    const small=document.createElement('small');
    small.textContent=`${track.genre||''} ${track.year||''}`.trim();
    button.append(strong,small);
    container.appendChild(button);
  });
}

$('#public-search').addEventListener('input',event=>{
  selected=null;
  $('#selected-track').value='';
  $('#selected-query').value=event.target.value;
  clearTimeout(timer);
  const query=event.target.value.trim();
  if(query.length<2){$('#public-results').innerHTML='';return}
  timer=setTimeout(async()=>{
    const response=await fetch(`api.php?action=public-search&q=${encodeURIComponent(query)}`);
    const data=await response.json();
    renderPublicResults(data.items||[]);
  },250);
});

$('#public-results').addEventListener('click',event=>{
  const button=event.target.closest('.public-result');
  if(!button)return;
  document.querySelectorAll('.public-result').forEach(item=>item.classList.toggle('active',item===button));
  selected={id:Number(button.dataset.id),query:button.dataset.query};
  $('#selected-track').value=selected.id;
  $('#selected-query').value=selected.query;
});

$('#public-request-form').addEventListener('submit',async event=>{
  event.preventDefault();
  const query=selected?.query||$('#public-search').value.trim();
  const response=await fetch('api.php?action=request-create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({guest_name:$('#guest-name').value.trim(),query,track_id:selected?.id||null,client_token:clientToken()})});
  const data=await response.json();
  const message=$('#public-message');
  if(!response.ok){message.className='error-message';message.textContent=data.error||'Richiesta non inviata';return}
  message.className='success-message';
  message.textContent='Richiesta inviata al DJ.';
  if(requestStatusTimer)clearInterval(requestStatusTimer);
  if(data.token){
    rememberRequestToken(data.token);
    pollAllRequestStatuses();
    requestStatusTimer=setInterval(pollAllRequestStatuses,10000);
  }
  event.currentTarget.reset();
  $('#public-results').innerHTML='';
  selected=null;
});

const previousToken=localStorage.getItem('kr_last_request_token')||'';
if(previousToken&&!storedRequestTokens().includes(previousToken))rememberRequestToken(previousToken);
if(storedRequestTokens().length){
  pollAllRequestStatuses();
  requestStatusTimer=setInterval(pollAllRequestStatuses,10000);
  automixRefreshTimer=setInterval(refreshAutomixEstimates,60000);
}
