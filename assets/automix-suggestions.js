const hiddenStartSelect = $('#current-track-select');
const startTrackDisplay = document.createElement('div');
const suggestionFilterBar = document.createElement('div');
suggestionFilterBar.className = 'suggestion-filter-bar hidden';
suggestionFilterBar.innerHTML = '<span></span><button type="button">Mostra tutti</button>';
$('#suggestions-list').before(suggestionFilterBar);
let activeSuggestionTag = '';
startTrackDisplay.className = 'start-track-display';
hiddenStartSelect.classList.add('hidden');
hiddenStartSelect.before(startTrackDisplay);

function renderStartTrack(currentId) {
  const track = state.tracks.find(item => Number(item.id) === Number(currentId))
    || (Number(state.bootstrap?.current?.id) === Number(currentId) ? state.bootstrap.current : null)
    || (Number(state.bootstrap?.suggestion_start?.id) === Number(currentId) ? state.bootstrap.suggestion_start : null);
  const option = hiddenStartSelect.querySelector(`option[value="${currentId}"]`);
  startTrackDisplay.innerHTML = track ? `<strong>${escapeHtml(track.artist)} — ${escapeHtml(track.title)}</strong><small>${escapeHtml(track.genre || '')} · ${track.bpm || '—'} BPM · ${escapeHtml(track.camelot || track.musical_key || '—')} · ${scaleMode(track)}</small>` : `<strong>${escapeHtml(option?.textContent || 'Brano live non disponibile')}</strong>`;
}

loadSuggestions = async function(mode = state.currentMode, tag = activeSuggestionTag) {
  state.currentMode = mode;
  activeSuggestionTag = tag;
  const modeLabels = {same:'Stessa vibe',up:'Piu energia',down:'Meno energia',genre:'Cambio genere',sing:'Canto',recover:'Recupero pista'};
  const referenceLabel = document.querySelector('#view-suggestions .suggestion-control .kicker');
  if (referenceLabel) referenceLabel.textContent = 'BRANO DI PARTENZA - LIVE';
  const currentId = Number($('#current-track-select').value || state.bootstrap?.current?.id || state.tracks[0]?.id);
  if (!currentId) return;
  suggestionFilterBar.classList.toggle('hidden', !activeSuggestionTag);
  suggestionFilterBar.querySelector('span').textContent = activeSuggestionTag ? `Tag: ${activeSuggestionTag}` : '';
  renderStartTrack(currentId);
  $('#suggestions-list').innerHTML = '<div class="empty-state">Analisi compatibilità in corso…</div>';
  const params = typeof suggestionFilterParams === 'function' ? suggestionFilterParams() : new URLSearchParams();
  params.set('current_id', String(currentId));
  params.set('mode', mode);
  params.set('tag', activeSuggestionTag);
  const data = await api(`suggestions&${params}`);
  const items = [...data.items];
  const reference = state.tracks.find(track => Number(track.id) === currentId) || state.bootstrap?.current || {};
  const metric = {up:'kr_energy',down:'kr_energy',genre:'kr_genre_change_safe',sing:'kr_singability',recover:'kr_recovery'}[mode];
  let primaryIndex = 0;
  if (mode === 'same') {
    const sameGenre = items.findIndex(track => track.genre && reference.genre && track.genre.toLowerCase() === reference.genre.toLowerCase());
    if (sameGenre >= 0) primaryIndex = sameGenre;
  } else if (metric) {
    const candidates = items.map((track,index)=>({index,value:track[metric]===null||track[metric]===undefined?null:Number(track[metric])})).filter(item=>item.value!==null&&Number.isFinite(item.value));
    if (candidates.length) primaryIndex = candidates.reduce((best,item)=>mode==='down'?(item.value<best.value?item:best):(item.value>best.value?item:best)).index;
  }
  if (primaryIndex > 0) items.unshift(items.splice(primaryIndex,1)[0]);
  $('#suggestions-list').innerHTML = items.length ? items.map((track, index) => `
    <article class="suggestion-card ${index === 0 ? 'primary-suggestion' : ''}" data-mode-label="PROPOSTA PRINCIPALE - ${escapeHtml(modeLabels[mode] || mode)}">
      <span class="rank">${index === 0 ? 'TOP' : String(index + 1).padStart(2, '0')}</span>
      <div class="track-identity"><strong>${escapeHtml(track.artist)} — ${escapeHtml(track.title)}</strong><small>${escapeHtml(track.genre || '—')} · ${track.energy}/5 energia</small></div>
      <div class="mobile-hidden"><span class="cell-label">BPM</span><b>${track.bpm ?? '—'}</b></div>
      <div class="mobile-hidden"><span class="cell-label">KEY / SCALA</span><b>${escapeHtml(track.camelot || track.musical_key || '—')} · ${scaleMode(track)}</b></div>
      <div class="suggestion-reason mobile-hidden"><b>${track.score}% compatibile</b>${escapeHtml(track.reasons.join(' · '))}</div>
      <div><div class="suggestion-badges">${track.badges.map(badge => `<button type="button" class="badge suggestion-tag-filter" data-tag="${escapeHtml(badge)}">${escapeHtml(badge)}</button>`).join('')}</div><div class="suggestion-actions"><button type="button" class="button ghost vdj-prelisten-title suggestion-prelisten" data-track-id="${track.id}" title="Preascolta in cuffia con VirtualDJ da 60 secondi">🎧</button><button class="button primary suggestion-automix" data-id="${track.id}">Invia ad Automix</button></div></div>
    </article>`).join('') : '<div class="empty-state">Nessuna proposta disponibile.</div>';
};

document.addEventListener('click',async event=>{
  const tagButton=event.target.closest('.suggestion-tag-filter');
  if(!tagButton)return;
  event.stopPropagation();
  await loadSuggestions(state.currentMode, tagButton.dataset.tag);
});
suggestionFilterBar.querySelector('button').addEventListener('click',()=>loadSuggestions(state.currentMode, ''));

document.addEventListener('click', async event => {
  const button = event.target.closest('.suggestion-automix');
  if (!button) return;
  event.stopImmediatePropagation();
  button.disabled = true;
  const label = button.textContent;
  button.textContent = 'Invio…';
  try {
    const result = await post('vdj-automix-add', {id: Number(button.dataset.id)});
    const select = $('#current-track-select');
    if (select && !select.querySelector(`option[value="${result.track_id}"]`)) {
      select.insertAdjacentHTML('afterbegin', `<option value="${result.track_id}">${escapeHtml(result.title)}</option>`);
    }
    if (select) select.value = String(result.track_id);
    toast(`${result.title} aggiunto ad Automix`);
    await loadSuggestions(state.currentMode);
  } catch (error) {
    toast(error.message);
  } finally {
    button.disabled = false;
    button.textContent = label;
  }
});

let automaticDatabaseSyncRunning = false;
async function refreshDatabaseStatusAndSync() {
  if (automaticDatabaseSyncRunning) return;
  const data = await api('database-status');
  const changed = data.items.some(item => Number(item.file_modified_at) !== Number(item.imported_modified_at) || Number(item.file_size) !== Number(item.imported_size));
  if (!changed) {
    await loadDatabaseStatus();
    return;
  }
  automaticDatabaseSyncRunning = true;
  try {
    await syncAllDatabases(false, true);
  } finally {
    automaticDatabaseSyncRunning = false;
  }
}
setInterval(refreshDatabaseStatusAndSync, 10000);
window.addEventListener('vdj-live-track-change', event => {
  const liveTrack = event.detail;
  const select = $('#current-track-select');
  if (!liveTrack?.id || !select) return;
  if (!select.querySelector(`option[value="${liveTrack.id}"]`)) {
    select.insertAdjacentHTML('afterbegin', `<option value="${liveTrack.id}">${escapeHtml(liveTrack.artist)} — ${escapeHtml(liveTrack.title)}</option>`);
  }
  select.value = String(liveTrack.id);
  post('suggestion-start', {id: Number(liveTrack.id)}).catch(() => {});
  renderStartTrack(liveTrack.id);
  if ($('#view-suggestions')?.classList.contains('active')) loadSuggestions(state.currentMode);
});
