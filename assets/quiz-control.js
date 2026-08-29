let quizControlQuestion = null;
let quizControlClockOffset = 0;
let quizSelectedHistoryId = 0;
let quizGroups = [];
let quizHistoryItems = [];
let quizDraggedQuestionId = 0;
let quizSelectedGroupId = Number(localStorage.getItem('krdesk_quiz_group_id') || 0);
let quizPrefillNonce = Number(sessionStorage.getItem('quiz_prefill_nonce') || 0);
const quizStatusLabels = { draft: 'Pronta', betting: 'Puntate aperte', open: 'Risposte aperte', closed: 'Risposte chiuse', revealed: 'Soluzione mostrata' };
if($('#quiz-launch')&&!$('#quiz-bet-open'))$('#quiz-launch').insertAdjacentHTML('beforebegin','<button type="button" id="quiz-bet-open" class="button accent" disabled>Apri puntate</button>');

function syncQuizGroupSelection() {
  const select=$('#quiz-group-select');
  if(select)select.value=String(quizSelectedGroupId);
  if($('#quiz-question-group-id'))$('#quiz-question-group-id').value=String(quizSelectedGroupId);
  localStorage.setItem('krdesk_quiz_group_id',String(quizSelectedGroupId));
  const group=quizGroups.find(item=>item.id===quizSelectedGroupId);
  const status=$('#quiz-group-status');
  if(status){status.textContent=group?(group.status==='active'?'Serata attiva':'Pianificata'):'Archivio';status.classList.toggle('blue',Boolean(group&&group.status!=='active'));}
  if($('#quiz-group-activate')){
    $('#quiz-group-activate').disabled=!group;
    $('#quiz-group-activate').textContent=group?.status==='active'?'Riavvia serata':'Avvia serata';
  }
  if($('#quiz-group-duplicate'))$('#quiz-group-duplicate').disabled=!group;
}

function renderQuizGroups(data) {
  quizGroups=Array.isArray(data.items)?data.items:[];
  if(quizSelectedGroupId>0&&!quizGroups.some(item=>item.id===quizSelectedGroupId))quizSelectedGroupId=0;
  if(!localStorage.getItem('krdesk_quiz_group_id'))quizSelectedGroupId=quizGroups.find(item=>item.status==='active')?.id||0;
  $('#quiz-group-select').innerHTML=`<option value="0">Senza gruppo / Archivio (${Number(data.ungrouped_count||0)})</option>${quizGroups.map(group=>`<option value="${group.id}">${escapeHtml(group.name)}${group.event_date?` · ${escapeHtml(group.event_date.split('-').reverse().join('/'))}`:''} (${group.question_count})${group.status==='active'?' · ATTIVA':''}</option>`).join('')}`;
  syncQuizGroupSelection();
}

const quizHistoryUrl=()=>`quiz-history&limit=100&group_id=${quizSelectedGroupId}`;

async function selectQuizQuestion(question) {
  if(!question)return;
  quizSelectedHistoryId=Number(question.id);quizControlQuestion=question;
  const stateData=await api('quiz-state&control=1');
  renderQuizControl({...stateData,question});
  $$('#quiz-history [data-quiz-id]').forEach(item=>item.classList.toggle('active',Number(item.dataset.quizId)===quizSelectedHistoryId));
}

async function setQuizTrack() {
  let track = state.bootstrap?.current;
  if (!track?.id) {
    try {
      const live = await api('live');
      state.bootstrap.current = live.current;
      track = live.current;
    } catch (error) {}
  }
  if (!track?.id) {
    $('#quiz-track-id').value = '';
    $('#quiz-live-track').textContent = 'Brano ON AIR non disponibile';
    return;
  }
  $('#quiz-track-id').value = track.id;
  $('#quiz-live-track').innerHTML = `<span>ON AIR</span><strong>${escapeHtml(track.artist)} - ${escapeHtml(track.title)}</strong><small>${escapeHtml(track.genre || '')} | ${track.bpm || '-'} BPM | ${escapeHtml(track.camelot || track.musical_key || '-')}</small>`;
}

function renderQuizLeaderboard(items) {
  $('#quiz-leaderboard').innerHTML = items.length
    ? items.map((item, index) => `<div class="quiz-ranking-row rank-${index+1}"><b>${index + 1}</b><strong>${escapeHtml(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} punti</span><small>${Number(item.correct_answers)} risposte corrette</small></div>`).join('')
    : '<div class="empty-state">Nessuna risposta.</div>';
}

function renderQuizParticipants(items) {
  const online = items.filter(item => Number(item.online)).length;
  const pending = items.filter(item => item.status === 'pending').length;
  $('#quiz-participant-count').textContent = `${online} online | ${pending} rientri | ${items.length} totali`;
  $('#quiz-participants').innerHTML = items.length
    ? items.map(item => {
      const isPending = item.status === 'pending';
      const isOnline = Number(item.online);
      const status = isPending ? 'Rientro da accettare' : isOnline ? 'Collegato' : 'Uscito / offline';
      const answer = item.selected_option ? `Risposta ${escapeHtml(item.selected_option)}` : 'Non ha risposto';
      return `<div class="quiz-participant-row ${isPending ? 'pending' : ''}" data-participant-id="${item.id}"><i class="${isOnline ? 'online' : 'offline'}"></i><strong>${escapeHtml(item.display_name)}</strong><span class="badge ${item.selected_option ? '' : 'amber'}">${answer}</span><small>${escapeHtml(status)}</small><div class="quiz-participant-actions">${isPending ? '<button type="button" class="button primary quiz-participant-action" data-action="accept">Accetta</button>' : ''}<button type="button" class="button ghost quiz-participant-action" data-action="disconnect">Scollega</button><button type="button" class="button ghost quiz-participant-action" data-action="remove">Rimuovi</button><button type="button" class="button ghost danger quiz-participant-action" data-action="delete">Cancella</button></div></div>`;
    }).join('')
    : '<div class="empty-state">Nessun partecipante.</div>';
}

function syncedQuizSeconds(question, offset) {
  if (!question || !['open', 'revealed'].includes(question.status)) return null;
  const target = question.status === 'revealed' ? question.revealed_until_ms : question.closes_at_ms;
  return target ? Math.max(0, Math.ceil((Number(target) - (Date.now() + offset)) / 1000)) : null;
}

function renderQuizControl(stateData) {
  quizControlClockOffset = Number(stateData.server_time_ms || Date.now()) - Date.now();
  renderQuizParticipants(stateData.participants || []);
  const question = stateData.question;
  quizControlQuestion = question;
  if (!question) {
    $('#quiz-control-status').textContent = 'In attesa';
    $('#quiz-control-timer').textContent = '--';
    $('#quiz-control-question').innerHTML = '<div class="empty-state">Nessuna domanda preparata.</div>';
    ['#quiz-bet-open','#quiz-launch', '#quiz-close'].forEach(id => { $(id).disabled = true; });
    renderQuizLeaderboard(stateData.leaderboard || []);
    return;
  }
  $('#quiz-control-status').textContent = quizStatusLabels[question.status] || question.status;
  const seconds = syncedQuizSeconds(question, quizControlClockOffset);
  $('#quiz-control-timer').textContent = seconds === null ? '--' : `${seconds}s`;
  $('#quiz-control-question').innerHTML = `<small>${escapeHtml([question.artist, question.title].filter(Boolean).join(' - ') || 'Domanda libera')}</small><h3>${escapeHtml(question.question)}</h3><div class="quiz-control-options">${Object.entries(question.options).map(([letter, text]) => `<div class="${question.status === 'revealed' && letter === question.correct_option ? 'correct' : ''}"><b>${letter}</b>${escapeHtml(text)}</div>`).join('')}</div><p>${question.status==='betting'?`${question.bets_count} puntate ricevute · bonus più veloce 250 punti`:`${question.answers_count} risposte ricevute`}</p>`;
  $('#quiz-bet-open').disabled = question.status !== 'draft';
  $('#quiz-launch').disabled = question.status !== 'betting';
  $('#quiz-close').disabled = question.status !== 'open';
  renderQuizLeaderboard(stateData.leaderboard || []);
}

async function loadQuizControl() {
  try {
    await setQuizTrack();
    const [stateData, groupsData, network] = await Promise.all([api('quiz-state&control=1'),api('quiz-groups'),api('network-info')]);
    renderQuizGroups(groupsData);
    const history=await api(quizHistoryUrl());quizHistoryItems=history.items||[];
    const selectedQuestion=quizSelectedHistoryId?quizHistoryItems.find(item=>item.id===quizSelectedHistoryId):null;
    const stateQuestion=stateData.question&&Number(stateData.question.group_id||0)===quizSelectedGroupId?stateData.question:null;
    const groupQuestion=selectedQuestion||(stateQuestion&&(['open','revealed'].includes(stateQuestion.status)||stateQuestion.opened_at)?stateQuestion:quizHistoryItems[0]||null);
    renderQuizControl({...stateData,question:groupQuestion});
    $('#quiz-history').innerHTML = quizHistoryItems.length
      ? quizHistoryItems.map((item,index) => `<button type="button" draggable="true" class="quiz-history-row ${quizControlQuestion?.id === item.id ? 'active' : ''}" data-quiz-id="${item.id}"><span class="quiz-drag-handle" title="Trascina per cambiare ordine">&#10239;</span><span class="badge">${index+1}</span><span class="badge">${quizStatusLabels[item.status] || item.status}</span><strong>${escapeHtml(item.question)}</strong><small>${escapeHtml([item.artist, item.title].filter(Boolean).join(' - ') || 'Domanda libera')} | ${item.answers_count} risposte</small></button>`).join('')
      : '<div class="empty-state">Nessuna domanda.</div>';
    $('#quiz-public-link').href = network.public_url;
    $('#quiz-screen-link').href = network.screen_url;
    const header = $('#view-requests .request-header code');
    if (header) header.textContent = network.public_url;
    if ($('#quiz-public-link-top')) $('#quiz-public-link-top').href = network.public_url;
    if ($('#quiz-screen-link-top')) $('#quiz-screen-link-top').href = network.screen_url;
    const qr = $('#qr-box img');
    if (qr && !qr.dataset.networkIp) {
      qr.src = `qr.php?target=public&t=${Date.now()}`;
      qr.dataset.networkIp = network.ip;
    }
  } catch (error) {
    toast(error.message);
  }
}

async function loadQuizPrefill() {
  try {
    const data = await api('quiz-prefill');
    const prefill = data.prefill;
    if (!prefill || Number(prefill.nonce) <= quizPrefillNonce) return;
    const form = $('#quiz-create-form');
    for (const key of ['track_id', 'question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'duration_seconds']) {
      if (form.elements[key]) form.elements[key].value = prefill[key] || '';
    }
    quizPrefillNonce = Number(prefill.nonce);
    sessionStorage.setItem('quiz_prefill_nonce', String(quizPrefillNonce));
    const track = state.tracks.find(item => Number(item.id) === Number(prefill.track_id)) || state.bootstrap?.current;
    if (track) $('#quiz-live-track').innerHTML = `<span>DOMANDA PER</span><strong>${escapeHtml(track.artist)} - ${escapeHtml(track.title)}</strong><small>${escapeHtml(track.genre || '')} | pronta da modificare o salvare</small>`;
    toast('Nuova domanda caricata nei campi della regia');
  } catch (error) {}
}

document.addEventListener('click', event => {
  const tab = event.target.closest('[data-request-mode]');
  if (!tab) return;
  $$('[data-request-mode]').forEach(item => item.classList.toggle('active', item === tab));
  if ($('#request-mode-requests')) $('#request-mode-requests').classList.toggle('hidden', tab.dataset.requestMode !== 'requests');
  if ($('#request-mode-quiz')) $('#request-mode-quiz').classList.toggle('hidden', tab.dataset.requestMode !== 'quiz');
  if (tab.dataset.requestMode === 'quiz') {
    setQuizTrack();
    loadQuizControl();
  }
});

$('#quiz-create-form').addEventListener('submit', async event => {
  event.preventDefault();
  const button = event.submitter;
  button.disabled = true;
  try {
    const payload=Object.fromEntries(new FormData(event.currentTarget));
    payload.group_id=Number($('#quiz-group-select')?.value||0);
    const result = await post('quiz-create', payload);
    quizSelectedHistoryId = 0;
    quizControlQuestion = result.question;
    event.currentTarget.reset();
    syncQuizGroupSelection();
    setQuizTrack();
    toast('Domanda salvata e pronta al lancio');
    await loadQuizControl();
  } catch (error) {
    toast(error.message);
  } finally {
    button.disabled = false;
  }
});

$('#quiz-codex-suggest').addEventListener('click', async event => {
  const button = event.currentTarget;
  const form = $('#quiz-create-form');
  const trackId = Number(form.elements.track_id.value || state.bootstrap?.current?.id || 0);
  if (!trackId) {
    toast('Brano ON AIR non disponibile');
    return;
  }
  const label = button.textContent;
  button.disabled = true;
  button.textContent = '* Codex sta cercando...';
  try {
    const result = await post('quiz-codex-suggest', { track_id: trackId, current_question: form.elements.question.value.trim() });
    const suggestion = result.suggestion;
    for (const key of ['question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'duration_seconds']) {
      if (form.elements[key]) form.elements[key].value = suggestion[key] || '';
    }
    const source = $('#quiz-suggestion-source');
    source.href = suggestion.source_url || '#';
    source.classList.toggle('hidden', !suggestion.source_url);
    toast('Nuova domanda Codex caricata nei campi');
  } catch (error) {
    toast(error.message);
  } finally {
    button.disabled = false;
    button.textContent = label;
  }
});

$('#quiz-history').addEventListener('click', async event => {
  const row = event.target.closest('[data-quiz-id]');
  if (!row) return;
  await selectQuizQuestion(quizHistoryItems.find(item=>item.id===Number(row.dataset.quizId))||null);
});

$('#quiz-group-select').addEventListener('change',async event=>{
  quizSelectedGroupId=Number(event.target.value||0);quizSelectedHistoryId=0;quizControlQuestion=null;syncQuizGroupSelection();await loadQuizControl();
});

$('#quiz-group-create').addEventListener('click',async event=>{
  const button=event.currentTarget,name=$('#quiz-group-name').value.trim(),eventDate=$('#quiz-group-date').value;
  if(!name){toast('Inserisci il nome del gruppo quiz');return}
  button.disabled=true;try{const result=await post('quiz-group-create',{name,event_date:eventDate});quizSelectedGroupId=Number(result.id);quizSelectedHistoryId=0;$('#quiz-group-name').value='';await loadQuizControl();toast('Gruppo quiz creato')}catch(error){toast(error.message)}finally{button.disabled=false}
});

$('#quiz-group-activate').addEventListener('click',async event=>{
  if(quizSelectedGroupId<1)return;event.currentTarget.disabled=true;
  try{const result=await post('quiz-group-activate',{id:quizSelectedGroupId});quizSelectedHistoryId=0;await loadQuizControl();toast(`Serata avviata: ${result.questions} domande pronte`)}catch(error){toast(error.message)}
});

$('#quiz-group-duplicate').addEventListener('click',async event=>{
  const group=quizGroups.find(item=>item.id===quizSelectedGroupId);if(!group)return;
  const name=prompt('Nome del nuovo gruppo:',`${group.name} - copia`);if(name===null||!name.trim())return;
  const eventDate=prompt('Data serata YYYY-MM-DD (facoltativa):','');if(eventDate===null)return;
  event.currentTarget.disabled=true;try{const result=await post('quiz-group-duplicate',{id:group.id,name:name.trim(),event_date:eventDate.trim()});quizSelectedGroupId=Number(result.id);quizSelectedHistoryId=0;await loadQuizControl();toast(`Gruppo duplicato: ${result.questions} domande`)}catch(error){toast(error.message)}finally{event.currentTarget.disabled=false}
});

$('#quiz-next-question').addEventListener('click',async()=>{
  if(!quizHistoryItems.length){toast('Nessuna domanda nel gruppo');return}
  const currentIndex=quizHistoryItems.findIndex(item=>item.id===Number(quizControlQuestion?.id||0));
  const next=quizHistoryItems[currentIndex>=0?(currentIndex+1)%quizHistoryItems.length:0];await selectQuizQuestion(next);
});

$('#quiz-history').addEventListener('dragstart',event=>{const row=event.target.closest('[data-quiz-id]');if(!row)return;quizDraggedQuestionId=Number(row.dataset.quizId);row.classList.add('dragging')});
$('#quiz-history').addEventListener('dragover',event=>{const row=event.target.closest('[data-quiz-id]');if(!row||Number(row.dataset.quizId)===quizDraggedQuestionId)return;event.preventDefault();$$('#quiz-history .drag-over').forEach(item=>item.classList.remove('drag-over'));row.classList.add('drag-over')});
$('#quiz-history').addEventListener('drop',async event=>{const target=event.target.closest('[data-quiz-id]');if(!target||!quizDraggedQuestionId)return;event.preventDefault();const source=$(`#quiz-history [data-quiz-id="${quizDraggedQuestionId}"]`);if(source&&source!==target)target.before(source);$$('#quiz-history .drag-over,#quiz-history .dragging').forEach(item=>item.classList.remove('drag-over','dragging'));const ids=$$('#quiz-history [data-quiz-id]').map(item=>Number(item.dataset.quizId));quizDraggedQuestionId=0;try{await post('quiz-group-reorder',{group_id:quizSelectedGroupId,ids});await loadQuizControl();toast('Ordine domande salvato')}catch(error){toast(error.message)}});
$('#quiz-history').addEventListener('dragend',()=>{$$('#quiz-history .drag-over,#quiz-history .dragging').forEach(item=>item.classList.remove('drag-over','dragging'));quizDraggedQuestionId=0});

$('#quiz-launch').addEventListener('click', async () => {
  if (!quizControlQuestion) return;
  try {
    const result=await post('quiz-launch', { id: quizControlQuestion.id });
    quizSelectedHistoryId = 0;
    quizControlQuestion=result.question;
    toast('Domanda lanciata sul player');
    await loadQuizControl();
  } catch(error) {
    toast(error.message);
  }
});

$('#quiz-bet-open').addEventListener('click',async()=>{
  if(!quizControlQuestion)return;
  try{const result=await post('quiz-bet-open',{id:quizControlQuestion.id});quizControlQuestion=result.question;renderQuizControl({...await api('quiz-state&control=1'),question:result.question});toast('Puntate aperte sulla prossima domanda')}catch(error){toast(error.message)}
});

$('#quiz-close').addEventListener('click', async () => {
  if (!quizControlQuestion) return;
  await post('quiz-close', { id: quizControlQuestion.id });
  toast('Risposte chiuse');
  await loadQuizControl();
});

$('#quiz-participants').addEventListener('click', async event => {
  const button = event.target.closest('.quiz-participant-action');
  if (!button) return;
  const row = button.closest('[data-participant-id]');
  const action = button.dataset.action;
  if (action === 'delete' && !confirm('Cancellare definitivamente partecipante e risposte?')) return;
  await post('quiz-participant-action', { id: Number(row.dataset.participantId), action });
  toast(action === 'accept' ? 'Rientro accettato' : action === 'delete' ? 'Partecipante cancellato' : 'Partecipante aggiornato');
  await loadQuizControl();
});

window.addEventListener('vdj-live-track-change', () => { setQuizTrack(); });

async function pollQuizQuestionCard() {
  if (!$('#view-quiz')?.classList.contains('active')) return;
  try {
    const stateData=await api('quiz-state&control=1');
    const stateQuestion=stateData.question&&Number(stateData.question.group_id||0)===quizSelectedGroupId?stateData.question:null;
    const question=quizSelectedHistoryId&&quizControlQuestion?quizControlQuestion:stateQuestion&&(['open','revealed'].includes(stateQuestion.status)||stateQuestion.opened_at)?stateQuestion:quizControlQuestion;
    renderQuizControl({...stateData,question});
  } catch (error) {}
}

setInterval(() => { loadQuizPrefill(); pollQuizQuestionCard(); }, 1000);
setInterval(() => {
  if (!quizControlQuestion) return;
  const seconds = syncedQuizSeconds(quizControlQuestion, quizControlClockOffset);
  if (seconds !== null) $('#quiz-control-timer').textContent = `${seconds}s`;
}, 100);
