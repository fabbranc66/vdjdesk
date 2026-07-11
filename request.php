<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#080b10">
  <title>KR Live</title>
  <link rel="stylesheet" href="assets/app.css?v=17">
  <link rel="stylesheet" href="assets/quiz-public.css?v=2">
</head>
<body class="public-page">
<main class="public-shell">
  <div class="public-brand">
    <span class="brand-mark">KR</span>
    <span><b>KR Live</b><small>RICHIESTE &middot; QUIZ</small></span>
  </div>
  <nav class="public-mode-tabs">
    <button class="active" data-public-mode="requests">Richiedi un brano</button>
    <button data-public-mode="quiz">Quiz Live</button>
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
  <p class="privacy-note">Funziona solo sulla rete locale dell&rsquo;evento.</p>
</main>
<script src="assets/request.js?v=9"></script>
<script src="assets/quiz-public.js?v=7"></script>
</body>
</html>
