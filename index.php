<?php declare(strict_types=1); ?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#080b10"><title>KR DJ Desk</title>
  <link rel="stylesheet" href="assets/app.css?v=22">
  <link rel="stylesheet" href="assets/automix-suggestions.css?v=6">
  <link rel="stylesheet" href="assets/spotify-features.css?v=20">
  <link rel="stylesheet" href="assets/bulk-tags.css?v=1">
  <link rel="stylesheet" href="assets/playlist-builder.css?v=6">
  <link rel="stylesheet" href="assets/library-sort.css?v=2">
  <link rel="stylesheet" href="assets/formula-settings.css?v=3">
  <link rel="stylesheet" href="assets/playlists.css?v=6">
  <link rel="stylesheet" href="assets/playlists-simple.css?v=4">
  <link rel="stylesheet" href="assets/playlist-integrator.css?v=3">
  <link rel="stylesheet" href="assets/quiz.css?v=5">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="#dashboard"><span class="brand-mark">KR</span><span><b>DJ Desk</b><small>LOCAL PERFORMANCE TOOL</small></span></a>
    <div class="desk-mode-switch" role="group" aria-label="Modalita KR Desk">
      <button type="button" class="active" data-desk-mode="regia">Regia</button>
      <button type="button" data-desk-mode="studio">Studio</button>
    </div>
    <nav id="main-nav">
      <button class="nav-item active" data-view="dashboard" data-mode="regia"><span>LIVE</span> Live desk</button>
      <button class="nav-item" data-view="requests" data-mode="regia"><span>REQ</span> Richieste <i id="request-badge">0</i></button>
      <button class="nav-item" data-view="quiz" data-mode="regia"><span>QUIZ</span> Quiz Live</button>
      <button class="nav-item" data-view="suggestions" data-mode="regia"><span>NEXT</span> Prossimo brano</button>
      <button class="nav-item" data-view="studio-dashboard" data-mode="studio"><span>HOME</span> Dashboard Studio</button>
      <button class="nav-item" data-view="library" data-mode="studio"><span>LIB</span> Libreria</button>
      <button class="nav-item" data-view="playlists" data-mode="studio"><span>PL</span> Playlist</button>
      <button class="nav-item" data-view="spotify" data-mode="studio"><span>IMP</span> Import / Export</button>
      <button class="nav-item" data-view="duplicates" data-mode="studio"><span>QC</span> Qualita / Doppioni</button>
      <button class="nav-item" data-view="analysis" data-mode="studio"><span>ANA</span> Analisi</button>
      <button class="nav-item" data-view="settings" data-mode="studio"><span>CFG</span> Configurazione</button>
    </nav>
    <div class="system-state"><i></i><span>Sistema locale<br><small id="library-count">0 brani indicizzati</small></span></div>
  </aside>
  <main>
    <header class="topbar">
      <button id="menu-toggle" class="icon-button">☰</button>
      <div><p id="eyebrow">SERATA ATTIVA</p><h1 id="view-title">Live desk</h1></div>
      <div class="top-actions"><span class="clock" id="clock">00:00</span><a class="button ghost" href="https://www.kr-solutions.it/vdjdesk/request.php" target="_blank">Pagina QR ↗</a></div>
    </header>

    <section class="view active" id="view-dashboard">
      <div class="live-grid">
        <article class="now-playing panel">
          <div class="panel-label"><span>● ON AIR</span><button class="text-button" data-view-link="suggestions">Apri suggeritore →</button></div>
          <div id="current-track" class="current-track skeleton"></div>
        </article>
        <article class="session-card panel">
          <span class="muted-label">DURATA SERATA</span><strong id="session-timer">00:00:00</strong>
          <div class="session-stats"><div><b id="played-count">0</b><span>Suonati</span></div><div><b id="new-requests">0</b><span>Richieste</span></div></div><span id="dup-count" hidden></span>
        </article>
      </div>
      <div class="section-head"><div><span class="kicker">AZIONI RAPIDE</span><h2>Che direzione prende la pista?</h2></div></div>
      <div class="quick-grid" id="quick-actions">
        <button data-mode="same"><b>≈</b><span>Stessa vibe<small>Continuità sicura</small></span></button>
        <button data-mode="up"><b>↗</b><span>Più energia<small>Alza di un livello</small></span></button>
        <button data-mode="down"><b>↘</b><span>Meno energia<small>Fai respirare</small></span></button>
        <button data-mode="genre"><b>⇄</b><span>Cambio genere<small>Transizione compatibile</small></span></button>
        <button data-mode="sing"><b>♪</b><span>Hit da cantare<small>Ritornello noto</small></span></button>
        <button data-mode="recover"><b>⚡</b><span>Recupero pista<small>Vai sul sicuro</small></span></button>
        <button data-mode="peak"><b>!</b><span>Brano urlante<small>Momento picco</small></span></button>
        <button data-mode="fresh"><b>⟳</b><span>Evita ripetizioni<small>Solo materiale fresco</small></span></button>
      </div>
      <div class="two-columns">
        <article class="panel"><div class="panel-label"><span>ULTIMI SUONATI</span></div><div id="recent-tracks" class="compact-list"></div></article>
        <article class="panel"><div class="panel-label"><span>RICHIESTE IN ARRIVO</span><button class="text-button" data-view-link="requests">Gestisci tutte →</button></div><div id="dashboard-requests" class="compact-list empty-state">Nessuna richiesta in attesa</div></article>
      </div>
    </section>

    <section class="view" id="view-studio-dashboard">
      <div class="section-head"><div><span class="kicker">STUDIO</span><h2>Cosa devo sistemare adesso?</h2><p class="form-note">Riepilogo operativo per preparazione, manutenzione e controllo libreria.</p></div><button type="button" class="button ghost" id="refresh-studio-dashboard">Aggiorna</button></div>
      <div class="metric-grid studio-summary-grid" id="studio-summary-cards">
        <article class="metric-card"><span>Database VDJ</span><b>--</b><small class="muted-label">controllo in corso</small></article>
        <article class="metric-card"><span>Brani indicizzati</span><b>--</b><small class="muted-label">libreria KR</small></article>
        <article class="metric-card"><span>Richieste aperte</span><b>--</b><small class="muted-label">pubblico</small></article>
        <article class="metric-card"><span>Doppioni</span><b>--</b><small class="muted-label">gruppi normalizzati</small></article>
        <article class="metric-card"><span>Candidati cancellazione</span><b>--</b><small class="muted-label">archivio</small></article>
      </div>
      <article class="panel studio-status-panel">
        <div class="panel-label"><span>PRIORITÀ LIBRERIA E:</span><button type="button" class="text-button" data-view-link="library">Apri libreria</button></div>
        <div id="studio-issue-cards" class="studio-issue-grid">
          <button type="button" class="studio-issue-card skeleton"><strong>--</strong><span>lettura controlli</span></button>
        </div>
      </article>
      <div class="studio-action-grid">
        <button type="button" class="panel studio-action-card" data-view-link="library"><span>LIB</span><strong>Libreria</strong><small>Cerca, filtra, tag, Spotify ID e qualita file.</small></button>
        <button type="button" class="panel studio-action-card" data-view-link="playlists"><span>PL</span><strong>Playlist</strong><small>Ordine, Camelot, builder e salvataggio liste VDJ.</small></button>
        <button type="button" class="panel studio-action-card" data-view-link="spotify"><span>IMP</span><strong>Import / Export</strong><small>JSON Spotify/Soundiiz e Spotify to VDJ.</small></button>
        <button type="button" class="panel studio-action-card" data-view-link="duplicates"><span>QC</span><strong>Qualita / Doppioni</strong><small>Confronto E, marcati, approvati e pulizia.</small></button>
        <button type="button" class="panel studio-action-card" data-view-link="analysis"><span>ANA</span><strong>Analisi</strong><small>Generi, standard e report tecnici.</small></button>
        <button type="button" class="panel studio-action-card" data-view-link="settings"><span>CFG</span><strong>Configurazione</strong><small>Percorsi, sync VDJ, soglie e formule.</small></button>
        <button type="button" class="panel studio-action-card publish-session-json"><span>JSON</span><strong>Genera + carica hosting</strong><small>Aggiorna la ricerca pubblica Regia dal JSON sessione.</small></button>
      </div>
      <article class="panel studio-status-panel"><div class="panel-label"><span>DATABASE VIRTUALDJ</span><button type="button" class="text-button" data-view-link="settings">Apri configurazione</button></div><div id="studio-database-summary" class="compact-list empty-state">Lettura stato database...</div></article>
    </section>

    <section class="view" id="view-library">
      <div class="search-toolbar panel"><div class="big-search"><span>⌕</span><input id="library-search" placeholder="Cerca artista o titolo…" autocomplete="off"></div><button class="button primary" id="search-button">Cerca</button></div>
      <div class="library-simple-filters">
        <label><span>VEDI CARTELLA · INCLUSE SOTTOCARTELLE</span><select id="filter-folder"><option value="">Tutta la libreria</option></select></label>
        <details><summary>Filtri avanzati</summary><div class="filter-row"><select id="filter-macro-genre"><option value="">Macrogenere: tutti</option></select><select id="filter-folder-genre"><option value="">Genere cartella: tutti</option></select><input id="filter-bpm" type="number" placeholder="BPM"><input id="filter-key" placeholder="Key / Camelot"><input id="filter-genre" placeholder="Microgenere/tag"><input id="filter-year" type="number" placeholder="Anno"><select id="filter-quality"><option value="">Qualità: tutte</option><option value="below">Audio sotto standard</option><option value="standard">Audio standard o superiore</option><option value="video">Video</option></select><button class="button ghost" id="clear-filters">Azzera filtri</button></div></details>
      </div>
      <div class="section-head"><div><span class="kicker">LIBRERIA LOCALE</span><h2 id="results-title">Tutti i brani</h2></div><div class="library-heading-actions"><button id="expand-folder-tracks" class="button accent hidden">Espandi tutta la cartella</button><span id="results-count" class="count-pill">0 risultati</span><button class="button ghost open-bulk-tags">Tag globale</button><button class="button ghost sync-vdj-years">Anno VDJ → KR</button><button id="align-filtered-vdj-tags" class="button ghost"><span class="vdj-align-bulk-icon">A</span> Allinea nomi VDJ</button><button id="send-library-to-spotify" class="button accent">Porta in Spotify → VDJ</button></div></div>
      <div id="library-sort-header" class="library-sort-header"><button data-sort="artist">Artista / Titolo</button><button data-sort="bpm">BPM</button><button data-sort="key">Key</button><button class="hide-mobile" data-sort="duration">Durata</button><div class="hide-mobile"><button data-sort="format">Formato</button><button data-sort="bitrate">Bitrate</button></div><div class="hide-tablet hide-mobile"><button data-sort="genre">Genere</button><button data-sort="year">Anno</button></div><button class="hide-mobile" data-sort="tags">Tag</button><span></span></div>
      <div id="library-results" class="track-list"></div>
      <div class="library-load-actions"><button id="load-more-tracks" class="button ghost load-more hidden">Carica altri brani</button></div>
    </section>

    <section class="view" id="view-suggestions">
      <div class="suggestion-control panel"><div><span class="kicker">BRANO DI PARTENZA</span><select id="current-track-select"></select></div><div class="segmented" id="suggestion-modes"><button class="active" data-mode="same">Stessa vibe</button><button data-mode="up">Più energia</button><button data-mode="down">Meno energia</button><button data-mode="genre">Cambio genere</button><button data-mode="sing">Canto</button><button data-mode="recover">Recupero</button></div></div>
      <div class="section-head"><div><span class="kicker">MOTORE DI COMPATIBILITÀ</span><h2>Le prossime mosse</h2></div></div>
      <div id="suggestions-list" class="suggestions-list empty-state">Scegli un brano per generare i suggerimenti.</div>
    </section>

    <section class="view embedded-tool-view" id="view-spotify">
      <div class="embedded-tool-head">
        <div><span class="kicker">IMPORT / EXPORT</span><h2>Strumenti di integrazione</h2><p class="form-note">JSON Spotify / Soundiiz, confronto con libreria e tool Spotify to VDJ.</p></div>
        <span class="badge">Studio</span>
      </div>
      <article class="panel playlist-integrator" id="playlist-integrator">
        <div class="playlist-integrator-head">
          <div><span class="kicker">INTEGRA LIBRERIA</span><h2>JSON Spotify / Soundiiz</h2><p>Analizza una lista esterna senza file fisici: esclude i brani già presenti in <code>E:\LIBRERIA_DEFINITIVA</code> ed esporta solo i mancanti.</p></div>
          <label class="button accent playlist-json-button">Carica JSON<input id="playlist-json-input" type="file" accept=".json,application/json" hidden></label>
        </div>
        <div class="playlist-integrator-stats" id="playlist-integrator-stats"><span>Totale: --</span><span class="ok">Già presenti: --</span><span class="warn">Dubbi: --</span><span class="missing">Da scaricare: --</span></div>
        <div class="playlist-integrator-actions hidden" id="playlist-integrator-actions"><button type="button" class="button primary" data-external-filter="missing">Mostra da scaricare</button><button type="button" class="button ghost" data-external-filter="doubtful">Mostra dubbi</button><button type="button" class="button ghost" data-external-filter="present">Mostra presenti</button><button type="button" class="button accent" id="external-export-missing">Esporta JSON mancanti</button></div>
        <div class="playlist-folder-bridge hidden" id="playlist-folder-bridge"><label>Cartella scaricata da confrontare<select id="external-folder-select"><option value="">Scegli cartella…</option></select></label><button type="button" class="button ghost" id="external-folder-match">Verifica congruenza</button><button type="button" class="button accent" id="external-apply-safe" disabled>Applica metadati sicuri</button></div>
        <div id="playlist-folder-match-results" class="playlist-integrator-results hidden"></div>
        <div id="playlist-integrator-results" class="playlist-integrator-results empty-state">Carica un JSON per iniziare.</div>
      </article>
      <div class="embedded-tool-head spotify-tool-subhead">
        <div><span class="kicker">STRUMENTO LOCALE</span><h2>Spotify to VirtualDJ</h2></div>
        <span class="badge">Network Control 9665</span>
      </div>
      <iframe class="embedded-tool-frame" src="tools/spotify-to-vdj.html?v=11" title="Spotify to VirtualDJ"></iframe>
    </section>

    <section class="view" id="view-duplicates">
      <article class="panel e-duplicate-procedure"><div><span class="kicker">CONTROLLO MIRATO</span><h2>Scegli la cartella da analizzare</h2><p>Cartella su E: → doppioni interni. Cartella su altro drive → confronto con E: e archivio dei candidati alla cancellazione.</p></div><div class="duplicate-folder-control"><select id="duplicate-folder"><option value="">Scegli una cartella…</option></select><button id="scan-e-duplicates" class="button primary">Controlla cartella</button></div></article>
      <div id="issue-cards" class="metric-grid"></div>
      <div class="section-head"><div><span class="kicker">REVISIONE MANUALE</span><h2 id="duplicate-results-title">Risultati della cartella</h2></div><div class="button-row"><button id="show-spotify-duplicates" class="button ghost">Doppioni Spotify</button><button id="export-spotify-duplicates" class="button ghost">CSV Spotify</button><button id="mark-all-candidates" class="button accent">Marca non consigliati</button><button id="approve-all-marked" class="button primary hidden">Approva tutti</button><button id="search-move-all-approved" class="button primary hidden">Cerca e sposta tutto</button><button id="show-marked-candidates" class="button ghost">Marcati da approvare</button><button id="show-approved-candidates" class="button ghost">Archivio approvati</button><button id="clear-candidate-states" class="button ghost">Azzera stati archivio</button><button id="refresh-duplicates" class="hidden" aria-hidden="true"></button></div></div>
      <div id="duplicates-list" class="duplicates-list"></div>
    </section>

    <section class="view" id="view-requests">
      <div class="request-header panel"><div><span class="kicker">RICHIESTE PUBBLICO · QUIZ</span><h2>Portale KR Live</h2><p>QR rigenerato automaticamente usando l’IP della rete attiva.</p><code>Rilevamento rete…</code></div><div id="qr-box" class="qr-placeholder"><img src="qr.php?target=public" alt="QR richieste e quiz"></div></div>
      <div id="request-mode-requests"><div class="status-tabs" id="request-tabs"><button class="active" data-status="all">Tutte</button><button data-status="new">Nuove</button><button data-status="approved">Approvate</button><button data-status="queued">Automix</button><button data-status="rejected">Rifiutate</button></div><div id="requests-list" class="requests-list"></div></div>
    </section>

    <section class="view" id="view-quiz">
      <div class="request-header panel"><div><span class="kicker">QUIZ LIVE</span><h2>Regia Quiz</h2><p>Domande, partecipanti, timer e classifica live.</p></div><div class="quiz-links"><a class="button ghost" id="quiz-public-link-top" target="_blank">Pagina mobile</a><a class="button ghost" id="quiz-screen-link-top" target="_blank">Schermo esterno</a></div></div>
      <div id="request-mode-quiz">
        <div class="quiz-control-grid">
          <article class="panel quiz-editor"><span class="kicker">REGIA QUIZ</span><h2>Prepara la prossima domanda</h2><div id="quiz-live-track" class="quiz-live-track">Brano ON AIR non disponibile</div><form id="quiz-create-form"><input type="hidden" name="track_id" id="quiz-track-id"><label>Domanda<input name="question" required maxlength="500" placeholder="Quale curiosità è legata a questo brano?"></label><div class="quiz-option-editor"><label><b>A</b><input name="option_a" required></label><label><b>B</b><input name="option_b" required></label><label><b>C</b><input name="option_c" required></label><label><b>D</b><input name="option_d" required></label></div><div class="quiz-editor-footer"><label>Risposta corretta<select name="correct_option"><option>A</option><option>B</option><option>C</option><option>D</option></select></label><label>Timer<select name="duration_seconds"><option>10</option><option>15</option><option selected>20</option><option>30</option><option>45</option><option>60</option></select></label><button class="button primary" type="button" id="quiz-codex-suggest">✦ Suggerisci domanda</button><button class="button accent" type="submit">Salva domanda</button></div><a id="quiz-suggestion-source" class="quiz-suggestion-source hidden" target="_blank" rel="noopener">Fonte verificata ↗</a></form></article>
          <article class="panel quiz-live-control"><div class="quiz-live-head"><div><span class="kicker">STATO LIVE</span><h2 id="quiz-control-status">In attesa</h2></div><strong id="quiz-control-timer">--</strong></div><div id="quiz-control-question" class="empty-state">Nessuna domanda preparata.</div><div class="button-row"><button type="button" id="quiz-launch" class="button primary" disabled>Lancia domanda</button><button type="button" id="quiz-close" class="button ghost" disabled>Chiudi risposte</button><button type="button" id="quiz-reveal" class="button accent" disabled>Mostra soluzione</button></div><div class="quiz-links"><a id="quiz-public-link" target="_blank">Pagina mobile ↗</a><a id="quiz-screen-link" target="_blank">Schermo esterno ↗</a></div></article>
        </div>
        <div class="quiz-bottom-grid"><article class="panel"><div class="panel-label"><span>DOMANDE PREPARATE</span></div><div id="quiz-history" class="quiz-history empty-state">Nessuna domanda.</div></article><article class="panel"><div class="panel-label"><span>PARTECIPANTI LIVE</span><b id="quiz-participant-count">0 online</b></div><div id="quiz-participants" class="quiz-participants empty-state">Nessun partecipante.</div></article><article class="panel"><div class="panel-label"><span>CLASSIFICA LIVE</span></div><div id="quiz-leaderboard" class="quiz-leaderboard empty-state">Nessuna risposta.</div></article></div>
      </div>
    </section>
    </section>

    <section class="view" id="view-playlists">
      <div class="search-toolbar panel"><div class="big-search"><span>⌕</span><input id="playlist-search" placeholder="Cerca artista o titolo…" autocomplete="off"></div><button class="button primary" id="playlist-search-button">Cerca playlist</button><button class="button accent" id="playlist-search-library">Cerca in libreria E</button></div>
      <div class="library-simple-filters" id="playlist-filters"><label><span>PLAYLIST VIRTUALDJ</span><select id="playlist-select"><option value="">Lettura playlist…</option></select></label><details><summary>Filtri avanzati</summary><div class="filter-row"><select id="playlist-macro-genre"><option value="">Macrogenere: tutti</option></select><select id="playlist-folder-genre"><option value="">Genere cartella: tutti</option></select><input id="playlist-bpm" type="number" placeholder="BPM"><input id="playlist-key" placeholder="Key / Camelot"><input id="playlist-genre" placeholder="Microgenere/tag"><input id="playlist-year" type="number" placeholder="Anno"><select id="playlist-quality"><option value="">Qualità: tutte</option><option value="below">Audio sotto standard</option><option value="standard">Audio standard o superiore</option><option value="video">Video</option></select><button class="button ghost" id="playlist-clear">Azzera filtri</button></div></details></div>
      <div class="section-head"><div><span class="kicker">PLAYLIST VIRTUALDJ</span><h2 id="playlist-title">Playlist</h2><p class="playlist-root" id="playlist-root">Lettura cartella…</p></div><div class="library-heading-actions"><span id="playlist-count" class="count-pill">0 brani</span><button type="button" class="button ghost open-bulk-tags">Tag globale</button><button type="button" class="button ghost sync-vdj-years">Anno VDJ → KR</button><button type="button" class="button ghost" id="align-filtered-playlist-vdj-tags"><span class="vdj-align-bulk-icon">A</span> Allinea nomi VDJ</button><button type="button" class="button ghost" id="playlist-force-spotify">Forza ID lista</button><button type="button" class="button accent" id="playlist-identify-spotify">Trova Spotify ID</button><button type="button" class="button primary" id="playlist-bulk-spotify">Metriche Spotify</button><button type="button" class="button accent" id="playlist-send-to-spotify">Porta in Spotify → VDJ</button><button type="button" class="button ghost" onclick="loadPlaylists()">Aggiorna</button></div></div>
      <div class="playlist-editor-toolbar panel"><button class="button accent" id="playlist-complete">＋ Completa playlist</button><button type="button" class="button primary playlist-search-library-action">Cerca in libreria E</button><button class="button ghost" id="playlist-original">Ordine originale</button><button class="button ghost" id="playlist-bpm-up">BPM ↑</button><button class="button ghost" id="playlist-bpm-down">BPM ↓</button><button class="button accent" id="playlist-camelot-strict">Camelot Strict</button><button class="button ghost" id="playlist-camelot-soft">Camelot Soft</button><button class="button ghost" id="playlist-genre-bpm">Genere + BPM</button><span>Trascina le righe per l’ordine manuale</span><button class="button primary" id="playlist-save">Salva playlist VDJ</button></div>
      <div id="playlist-camelot-debug" class="camelot-debug"></div>
      <div id="playlist-sort-header" class="library-sort-header"><button data-sort="artist">Artista / Titolo</button><button data-sort="bpm">BPM</button><button data-sort="key">Key</button><button class="hide-mobile" data-sort="duration">Durata</button><div class="hide-mobile"><button data-sort="format">Formato</button><button data-sort="bitrate">Bitrate</button></div><div class="hide-tablet hide-mobile"><button data-sort="genre">Genere</button><button data-sort="year">Anno</button></div><button class="hide-mobile" data-sort="tags">Tag</button><span></span></div>
      <div id="playlist-results" class="track-list"></div>
    </section>

    <section class="view" id="view-analysis">
      <div class="section-head"><div><span class="kicker">DATABASE VIRTUALDJ</span><h2>Analisi libreria</h2><p class="form-note" id="vdj-genre-summary">Conteggio generi in corso…</p></div><button type="button" class="button ghost" onclick="loadLibraryAnalysis()">Aggiorna</button></div>
      <article class="panel form-panel"><h2>Test standard libreria v1</h2><p class="form-note">Prova il mapping Spotify → categorie DJ senza toccare il database.</p><div class="filter-row"><input id="standard-test-genres" value="reggaeton, latin pop" placeholder="Generi Spotify"><input id="standard-test-release" value="2021-05-14" placeholder="Release date"><input id="standard-test-popularity" type="number" value="78" placeholder="Popularity"><input id="standard-test-bpm" type="number" value="94" placeholder="BPM"><button type="button" class="button accent" id="standard-test-run">Testa</button></div><div id="standard-test-output" class="empty-state">Premi Testa per verificare le regole.</div></article>
      <article class="panel form-panel"><h2>Generi presenti</h2><div class="genre-stats-scroll"><table class="formula-table genre-stats-table"><thead><tr><th>Genere</th><th>Brani</th></tr></thead><tbody id="vdj-genre-stats"><tr><td colspan="2">Apri la pagina per caricare i dati.</td></tr></tbody></table></div></article>
    </section>

    <section class="view" id="view-settings">
      <form id="settings-form" class="settings-layout">
        <article class="panel form-panel"><span class="kicker">PERCORSI LOCALI</span><h2>Integrazione VirtualDJ</h2>
          <label>Cartella musica da visualizzare<select name="music_root" id="music-root-select"><option value="">Rilevamento cartelle…</option></select></label>
          <label>Database VirtualDJ XML<input name="vdj_database"></label>
          <label>Cartella playlist VirtualDJ<input name="playlist_folder"></label>
          <label>Playlist libreria definitiva<input name="definitive_playlist_folder"></label>
          <label>Cartella download SpotMate<input name="spotmate_download_folder" placeholder="E:\LIBRERIA_DEFINITIVA\01_INBOX\Da_classificare"></label>
          <label>Porta VirtualDJ Network Control<input name="vdj_network_port" type="number" min="1" max="65535"></label>
          <div class="button-row"><button type="button" class="button primary" id="sync-all-vdj">Sincronizza tutti i DB</button><button type="button" class="button accent" id="reconcile-vdj">Riconcilia database</button><button type="button" class="button accent" id="import-vdj">Importa solo principale</button><button type="button" class="button ghost" id="scan-music">Scansiona musica</button><button type="button" class="button primary publish-session-json" id="publish-session-json">Genera + carica hosting</button></div>
          <p class="form-note">Network Control usa <code>127.0.0.1:9665</code> senza autenticazione. La sincronizzazione non scrive nei database VirtualDJ.</p>
          <div id="database-status" class="database-status"><div class="empty-state">Lettura stato database…</div></div>
        </article>
        <article class="panel form-panel"><span class="kicker">REGOLE MOTORE</span><h2>Compatibilità</h2>
          <label>Soglia doppioni (%)<input name="duplicate_threshold" type="number" min="50" max="100"></label>
          <label>Brani recenti da escludere<input name="recent_exclusion" type="number" min="1" max="200"></label>
          <label>Intervallo BPM accettato<input name="bpm_range" type="number" min="1" max="30"></label>
          <label>Compatibilità key<select name="key_mode"><option value="camelot">Camelot</option><option value="exact">Solo key esatta</option><option value="off">Disattivata</option></select></label>
          <label>Intervallo richieste pubblico (minuti)<input name="request_interval_minutes" type="number" min="0" max="120" value="5"></label>
          <button class="button primary" type="submit">Salva impostazioni</button>
        </article>
        <article class="panel form-panel"><span class="kicker">DISCOGS</span><h2>Chiavi API</h2>
          <label>Chiave utente<input name="discogs_consumer_key" autocomplete="off"></label>
          <label>Segreto utente<input name="discogs_consumer_secret" type="password" autocomplete="off"></label>
          <label>URL token richiesta<input name="discogs_request_token_url"></label>
          <label>URL autorizzazione<input name="discogs_authorize_url"></label>
          <label>URL token accesso<input name="discogs_access_token_url"></label>
          <p class="form-note">Per ora sono solo salvate in KR Desk; le colleghiamo dopo alla ricerca metadati Discogs.</p>
        </article>
        <article class="panel form-panel"><span class="kicker">BEATPORT</span><h2>Migrator API</h2>
          <label>Base URL<input name="beatport_api_base_url" autocomplete="off"></label>
          <label>Endpoint singolo<input name="beatport_track_endpoint" autocomplete="off"></label>
          <label>Endpoint bulk<input name="beatport_bulk_endpoint" autocomplete="off"></label>
          <p class="form-note">Questi endpoint non richiedono autenticazione. Serve solo il Beatport track ID.</p>
        </article>
        <article class="panel form-panel formula-panel" id="formula-settings"><span class="kicker">PUNTEGGI KR</span><h2>Formule modificabili</h2><p class="form-note">Coefficienti applicati a metriche 0–100. Le penalità sono punti.</p><input type="hidden" name="kr_formula_weights" id="kr-formula-weights">
          <table class="formula-table"><thead><tr><th>Punteggio</th><th>Formula / pesi</th></tr></thead><tbody>
            <tr><td><b>Energia</b></td><td><div class="formula-inputs"><label>Energia Spotify <input type="number" step="0.01" data-formula-group="energy" data-formula-key="spotify_energy"></label><label>Volume <input type="number" step="0.01" data-formula-group="energy" data-formula-key="loudness"></label><label>Ballabilità <input type="number" step="0.01" data-formula-group="energy" data-formula-key="dance"></label><label>Tempo <input type="number" step="0.01" data-formula-group="energy" data-formula-key="tempo"></label></div></td></tr>
            <tr><td><b>Cantabilità</b></td><td><div class="formula-inputs"><label>Popolarità <input type="number" step="0.01" data-formula-group="singability" data-formula-key="popularity"></label><label>Vocalità <input type="number" step="0.01" data-formula-group="singability" data-formula-key="vocal"></label><label>Valenza <input type="number" step="0.01" data-formula-group="singability" data-formula-key="valence"></label><label>Penalità parlato <input type="number" step="1" data-formula-group="singability" data-formula-key="speech_penalty"></label></div></td></tr>
            <tr><td><b>Potenza pista</b></td><td><div class="formula-inputs"><label>Ballabilità <input type="number" step="0.01" data-formula-group="floor" data-formula-key="dance"></label><label>Energia <input type="number" step="0.01" data-formula-group="floor" data-formula-key="spotify_energy"></label><label>Volume <input type="number" step="0.01" data-formula-group="floor" data-formula-key="loudness"></label><label>Tempo <input type="number" step="0.01" data-formula-group="floor" data-formula-key="tempo"></label><label>Valenza <input type="number" step="0.01" data-formula-group="floor" data-formula-key="valence"></label></div></td></tr>
            <tr><td><b>Familiarità</b></td><td><div class="formula-inputs"><label>Popolarità <input type="number" step="0.01" data-formula-group="familiarity" data-formula-key="popularity"></label></div></td></tr>
            <tr><td><b>Rischio</b></td><td><div class="formula-inputs"><label>Familiarità <input type="number" step="0.01" data-formula-group="risk" data-formula-key="familiarity"></label><label>Potenza pista <input type="number" step="0.01" data-formula-group="risk" data-formula-key="floor"></label><label>Cantabilità <input type="number" step="0.01" data-formula-group="risk" data-formula-key="singability"></label><label>Penalità parlato <input type="number" step="1" data-formula-group="risk" data-formula-key="speech_penalty"></label><label>Penalità strumentale <input type="number" step="1" data-formula-group="risk" data-formula-key="instrumental_penalty"></label></div></td></tr>
            <tr><td><b>Picco</b></td><td><div class="formula-inputs"><label>Energia <input type="number" step="0.01" data-formula-group="peak" data-formula-key="spotify_energy"></label><label>Volume <input type="number" step="0.01" data-formula-group="peak" data-formula-key="loudness"></label><label>Ballabilità <input type="number" step="0.01" data-formula-group="peak" data-formula-key="dance"></label><label>Popolarità <input type="number" step="0.01" data-formula-group="peak" data-formula-key="popularity"></label></div></td></tr>
            <tr><td><b>Recupero pista</b></td><td><div class="formula-inputs"><label>Familiarità <input type="number" step="0.01" data-formula-group="recovery" data-formula-key="familiarity"></label><label>Potenza pista <input type="number" step="0.01" data-formula-group="recovery" data-formula-key="floor"></label><label>Cantabilità <input type="number" step="0.01" data-formula-group="recovery" data-formula-key="singability"></label><label>Valenza <input type="number" step="0.01" data-formula-group="recovery" data-formula-key="valence"></label><label>Rischio inverso <input type="number" step="0.01" data-formula-group="recovery" data-formula-key="inverse_risk"></label></div></td></tr>
          </tbody></table><div class="formula-actions"><button type="button" class="button ghost" id="reset-formulas">Ripristina predefinite</button><button type="button" class="button accent" id="recalculate-formulas">Salva e ricalcola libreria</button></div>
        </article>
      </form>
      <div id="operation-log" class="toast"></div>
    </section>
  </main>
</div>

<dialog id="track-dialog"><form method="dialog"><button class="dialog-close">×</button></form><div id="track-editor"></div></dialog>
<dialog id="bulk-tag-dialog"><form method="dialog"><button class="dialog-close">×</button></form><div class="bulk-tag-editor"><span class="kicker">LISTA FILTRATA VISIBILE</span><h2>Tag globale</h2><p id="bulk-tag-count">0 brani selezionati</p><p class="form-note">Primo clic: <b class="bulk-add-label">verde, aggiungi</b> · secondo clic: <b class="bulk-remove-label">rosso, rimuovi</b> · terzo clic: annulla.</p><div id="bulk-tag-picker" class="tag-picker"></div><button type="button" class="button primary" id="apply-bulk-tags">Applica ai brani visibili</button></div></dialog>
<dialog id="playlist-builder-dialog"><form method="dialog"><button class="dialog-close">×</button></form><div class="playlist-builder"><span class="kicker">COMPLETA PLAYLIST</span><h2>Aggiungi brani dalla libreria</h2><div class="playlist-builder-grid"><label>Numero brani<input id="builder-limit" type="number" min="1" max="100" value="10"></label><label>Macrogenere<select id="builder-macro-genre"><option value="">Qualsiasi macro</option></select></label><label>Genere cartella<select id="builder-folder-genre"><option value="">Qualsiasi cartella</option></select></label><label>Microgenere<input id="builder-genre" placeholder="es. salsa dura"></label><label>Tag<select id="builder-tag"><option value="">Qualsiasi tag</option></select></label><label>BPM minimo<input id="builder-bpm-min" type="number"></label><label>BPM massimo<input id="builder-bpm-max" type="number"></label><label>Camelot compatibile<input id="builder-camelot" placeholder="es. 8A"></label><label>Energia minima<select id="builder-energy"><option value="">Qualsiasi</option><option>3</option><option>4</option><option>5</option></select></label><label>Ballabilità minima<select id="builder-dance"><option value="">Qualsiasi</option><option>3</option><option>4</option><option>5</option></select></label><label>Popolarità minima<input id="builder-popularity" type="number" min="0" max="100"></label><label>Anno da<input id="builder-year-min" type="number"></label><label>Anno a<input id="builder-year-max" type="number"></label><label>Inserisci<select id="builder-position"><option value="end">In fondo</option></select></label></div><label class="builder-quality"><input id="builder-mp3-320" type="checkbox" checked> Solo MP3 320 kbps o superiore</label><div class="button-row"><button type="button" class="button accent" id="builder-search">Trova candidati</button><button type="button" class="button primary" id="builder-add" disabled>Aggiungi selezionati</button></div><div id="builder-results" class="builder-results"><div class="empty-state">Imposta i criteri e cerca.</div></div></div></dialog>
<div id="app-toast" class="toast"></div>
  <script src="assets/app.js?v=25"></script>
  <script src="assets/library-quality.js?v=3"></script>
  <script src="assets/spotify-export-filter.js?v=7"></script>
  <script src="assets/library-sort.js?v=2"></script>
  <script src="assets/formula-settings.js?v=3"></script>
  <script src="assets/analysis.js?v=2"></script>
  <script src="assets/playlists.js?v=25"></script>
  <script src="assets/playlist-integrator.js?v=6"></script>
  <script src="assets/automix-suggestions.js?v=17"></script>
  <script src="assets/spotify-features.js?v=50"></script>
  <script src="assets/bulk-tags.js?v=3"></script>
  <script src="assets/vdj-years.js?v=4"></script>
  <script src="assets/playlist-builder.js?v=16"></script>
  <script src="assets/quiz-control.js?v=14"></script>
</body></html>
