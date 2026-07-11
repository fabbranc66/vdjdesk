let quizControlQuestion = null;
let quizControlClockOffset = 0;
let quizPrefillNonce = Number(sessionStorage.getItem('quiz_prefill_nonce') || 0);
const quizStatusLabels = { draft: 'Pronta', open: 'Risposte aperte', closed: 'Risposte chiuse', revealed: 'Soluzione mostrata' };

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
    ? items.map((item, index) => `<div class="quiz-ranking-row"><b>${index + 1}</b><strong>${escapeHtml(item.display_name)}</strong><span>${Number(item.points).toLocaleString('it-IT')} pt</span><small>${Number(item.correct_answers)} corrette</small></div>`).join('')
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
    ['#quiz-launch', '#quiz-close', '#quiz-reveal'].forEach(id => { $(id).disabled = true; });
    renderQuizLeaderboard(stateData.leaderboard || []);
    return;
  }
  $('#quiz-control-status').textContent = quizStatusLabels[question.status] || question.status;
  const seconds = syncedQuizSeconds(question, quizControlClockOffset);
  $('#quiz-control-timer').textContent = seconds === null ? '--' : `${seconds}s`;
  $('#quiz-control-question').innerHTML = `<small>${escapeHtml([question.artist, question.title].filter(Boolean).join(' - ') || 'Domanda libera')}</small><h3>${escapeHtml(question.question)}</h3><div class="quiz-control-options">${Object.entries(question.options).map(([letter, text]) => `<div class="${question.status === 'revealed' && letter === question.correct_option ? 'correct' : ''}"><b>${letter}</b>${escapeHtml(text)}</div>`).join('')}</div><p>${question.answers_count} risposte ricevute</p>`;
  $('#quiz-launch').disabled = question.status !== 'draft';
  $('#quiz-close').disabled = question.status !== 'open';
  $('#quiz-reveal').disabled = !['open', 'closed'].includes(question.status);
  renderQuizLeaderboard(stateData.leaderboard || []);
}

async function loadQuizControl() {
  try {
    await setQuizTrack();
    const [stateData, history, network] = await Promise.all([api('quiz-state&control=1'), api('quiz-history'), api('network-info')]);
    renderQuizControl(stateData);
    $('#quiz-history').innerHTML = history.items.length
      ? history.items.map(item => `<button type="button" class="quiz-history-row ${quizControlQuestion?.id === item.id ? 'active' : ''}" data-quiz-id="${item.id}"><span class="badge">${quizStatusLabels[item.status] || item.status}</span><strong>${escapeHtml(item.question)}</strong><small>${escapeHtml([item.artist, item.title].filter(Boolean).join(' - ') || 'Domanda libera')} | ${item.answers_count} risposte</small></button>`).join('')
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
    const result = await post('quiz-create', Object.fromEntries(new FormData(event.currentTarget)));
    quizControlQuestion = result.question;
    event.currentTarget.reset();
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
  const history = await api('quiz-history');
  quizControlQuestion = history.items.find(item => item.id === Number(row.dataset.quizId)) || null;
  if (quizControlQuestion) {
    const stateData = await api('quiz-state&control=1');
    renderQuizControl({ question: quizControlQuestion, leaderboard: stateData.leaderboard, participants: stateData.participants, server_time_ms: stateData.server_time_ms });
  }
});

$('#quiz-launch').addEventListener('click', async () => {
  if (!quizControlQuestion) return;
  await post('quiz-launch', { id: quizControlQuestion.id });
  toast('Domanda lanciata');
  await loadQuizControl();
});

$('#quiz-close').addEventListener('click', async () => {
  if (!quizControlQuestion) return;
  await post('quiz-close', { id: quizControlQuestion.id });
  toast('Risposte chiuse');
  await loadQuizControl();
});

$('#quiz-reveal').addEventListener('click', async () => {
  if (!quizControlQuestion) return;
  await post('quiz-reveal', { id: quizControlQuestion.id });
  toast('Soluzione mostrata');
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
    renderQuizControl(await api('quiz-state&control=1'));
  } catch (error) {}
}

setInterval(() => { loadQuizPrefill(); pollQuizQuestionCard(); }, 1000);
setInterval(() => {
  if (!quizControlQuestion) return;
  const seconds = syncedQuizSeconds(quizControlQuestion, quizControlClockOffset);
  if (seconds !== null) $('#quiz-control-timer').textContent = `${seconds}s`;
}, 100);
