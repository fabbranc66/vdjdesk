<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#080b10">
  <title>KR Live</title>
  <link rel="stylesheet" href="assets/app.css?v=17">
  <link rel="stylesheet" href="assets/quiz-public.css?v=8">
  <link rel="stylesheet" href="assets/chill-reel-public.css?v=1">
  <link rel="stylesheet" href="assets/chill-reel-player-mobile.css?v=4">
  <style>body.public-page{place-items:start center;align-content:start}.public-shell{grid-column:1;margin:0 auto}#public-chill-reel{display:flex;min-height:calc(100vh - 190px);flex-direction:column;align-items:center;justify-content:flex-start;text-align:center}#public-chill-reel>div{width:min(100%,520px)}</style>
</head>
<body class="public-page">
<main class="public-shell">
  <div class="public-brand">
    <img class="brand-mark" src="assets/images/kr-music-logo-transparent.png" alt="KR Music">
    <span><b>KR Live</b><small>RICHIESTE &middot; QUIZ</small></span>
  </div>
  <nav class="public-mode-tabs">
    <button class="active" data-public-mode="requests">Richiedi un brano</button>
    <button data-public-mode="quiz">Quiz Live</button>
    <button class="hidden" data-public-mode="chill-reel">Chill Reel</button>
  </nav>

  <section class="public-card" id="public-requests">
    <span class="kicker">LIVE REQUEST</span>
    <h1>Che cosa vuoi ascoltare?</h1>
    <p>Cerca nella libreria del DJ o invia il titolo che hai in mente.</p>
    <form id="public-request-form">
      <label>Il tuo nome <span>(facoltativo)</span><input id="guest-name" placeholder="Come ti chiami?"></label>
      <label>Cerca un brano
        <div class="big-search"><span>&#8981;</span><input id="public-search" placeholder="Artista o titolo..." autocomplete="off"></div>
      </label>
      <div id="public-results" class="public-results"></div>
      <input id="selected-track" type="hidden">
      <input id="selected-query" type="hidden">
      <button class="button primary public-submit" type="submit">Invia richiesta</button>
    </form>
    <div id="public-message"></div>
  </section>

  <section class="public-card hidden chill-player-embedded" id="public-chill-reel">
    <div id="player-login"><span class="kicker">ENTRA IN PARTITA</span><h1>Chill Reel</h1><p>Inserisci il nome del tavolo o della squadra. Questo telefono rester&agrave; associato al giocatore.</p><form id="player-join-form"><label>Nome tavolo o squadra<input id="player-join-name" maxlength="80" required autocomplete="nickname" placeholder="Es. Tavolo 4"></label><button class="button primary public-submit">Partecipa</button></form></div>
    <div class="hidden" id="player-game">
      <div class="player-head"><div><small>TAVOLO</small><strong id="player-name"></strong></div><div class="player-score-box"><b id="player-score">0</b><small>PUNTI</small></div></div>
      <div id="player-turn" class="turn-status">Attendi il tuo turno</div>
      <div id="player-wait-phase" class="player-phase hidden"><span class="phase-kicker">PARTITA IN CORSO</span><h2>Guarda lo schermo principale</h2><p>La pagina si aggiorner&agrave; automaticamente quando arriver&agrave; il tuo turno.</p></div>
      <div id="player-spin-phase" class="player-phase hidden"><button type="button" id="player-spin" class="spin-control" disabled>TIENI PREMUTO &middot; GIRA</button><div id="player-wheel-result" class="wheel-result">&mdash;</div></div>
      <section id="player-action-choice" class="player-phase action-section hidden"><div class="player-action-grid"><button type="button" id="player-open-consonants"><b>C</b><span>Scegli<br>consonante</span></button><button type="button" id="player-open-vowels"><b>A</b><span>Compra vocale<br>-100 punti</span></button><button type="button" id="player-open-solve"><b>&#9678;</b><span>Risolvi</span></button></div></section>
      <section id="player-letters" class="player-phase action-section hidden"><small id="player-letter-title">SCEGLI UNA CONSONANTE</small><div id="player-letter-grid" class="letter-grid"></div><button type="button" class="phase-switch secondary player-back-actions">Torna alle azioni</button></section>
      <form id="player-solve" class="player-phase action-section hidden"><small>RISOLVI LA FRASE</small><div><input id="player-answer" autocomplete="off" placeholder="Scrivi la soluzione"><button>Risolvi</button></div><button type="button" class="phase-switch secondary player-back-actions">Torna alle azioni</button></form>
      <div id="player-message" class="player-message"></div>
      <div id="player-next" class="player-next"></div>
    </div>
    <div id="player-ranking-card" class="ranking-card"><span class="kicker">CLASSIFICA</span><div id="player-ranking"></div></div>
    <span id="player-game-name" class="hidden"></span>
  </section>

  <section class="public-card hidden" id="public-quiz">
    <div id="quiz-join">
      <span class="kicker">ENTRA IN PARTITA</span>
      <h1>Quiz Live</h1>
      <p>Inserisci il tuo nome o quello della squadra. Resta su questa pagina: la domanda arriver&agrave; automaticamente.</p>
      <form id="quiz-join-form">
        <label>Nome o squadra<input id="quiz-player-name" maxlength="80" required placeholder="Es. Tavolo 7"></label>
        <button class="button primary public-submit">Partecipa</button>
      </form>
    </div>
    <div id="quiz-player" class="hidden">
      <div class="quiz-player-head">
        <div><span class="kicker">QUIZ LIVE</span><strong id="quiz-player-name-label"></strong></div>
        <b id="quiz-player-timer">--</b>
      </div>
      <div id="quiz-player-content" class="quiz-waiting">In attesa della prossima domanda...</div>
      <div id="quiz-player-ranking"></div>
    </div>
  </section>
  <section class="public-card hidden" id="public-disabled">
    <span class="kicker">KR LIVE</span>
    <h1>Servizi non disponibili</h1>
    <p>Quiz e richieste sono temporaneamente disabilitati dalla Regia.</p>
  </section>
  <p class="privacy-note">Funziona solo sulla rete locale dell&rsquo;evento.</p>
</main>
<script src="assets/request.js?v=9"></script>
<script src="assets/quiz-public.js?v=18"></script>
<script src="assets/chill-reel-player.js?v=9"></script>
</body>
</html>
