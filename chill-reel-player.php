<?php declare(strict_types=1); ?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#07100d">
  <title>Chill Reel · Player</title>
  <link rel="stylesheet" href="assets/chill-reel-player.css?v=1">
  <link rel="stylesheet" href="assets/chill-reel-player-mobile.css?v=4">
</head>
<body>
<main class="player-shell chill-player-embedded">
  <header><img src="assets/images/kr-music-logo-transparent.png" alt="KR Music"><div><b>CHILL REEL</b><small>PLAYER</small></div><span id="player-game-name">In attesa</span></header>
  <section class="player-card" id="player-login"><span class="kicker">ENTRA IN PARTITA</span><h1>Chill Reel</h1><p>Inserisci il nome del tavolo o della squadra. Questo telefono rester&agrave; associato al giocatore.</p><form id="player-join-form"><label>Nome tavolo o squadra<input id="player-join-name" maxlength="80" required autocomplete="nickname" placeholder="Es. Tavolo 4"></label><button class="button primary public-submit">Partecipa</button></form></section>
  <section class="player-card hidden" id="player-game">
    <div class="player-head"><div><small>TAVOLO</small><strong id="player-name"></strong></div><div class="player-score-box"><b id="player-score">0</b><small>PUNTI</small></div></div>
    <div id="player-turn" class="turn-status">Attendi il tuo turno</div>
    <div id="player-wait-phase" class="player-phase hidden"><span class="phase-kicker">PARTITA IN CORSO</span><h2>Guarda lo schermo principale</h2><p>La pagina si aggiorner&agrave; automaticamente quando arriver&agrave; il tuo turno.</p></div>
    <div id="player-spin-phase" class="player-phase hidden"><button type="button" id="player-spin" class="spin-control" disabled>TIENI PREMUTO &middot; GIRA</button><div id="player-wheel-result" class="wheel-result">&mdash;</div></div>
    <section id="player-action-choice" class="player-phase action-section hidden"><div class="player-action-grid"><button type="button" id="player-open-consonants"><b>C</b><span>Scegli<br>consonante</span></button><button type="button" id="player-open-vowels"><b>A</b><span>Compra vocale<br>-100 punti</span></button><button type="button" id="player-open-solve"><b>&#9678;</b><span>Risolvi</span></button></div></section>
    <section id="player-letters" class="player-phase action-section hidden"><small id="player-letter-title">SCEGLI UNA CONSONANTE</small><div id="player-letter-grid" class="letter-grid"></div><button type="button" class="phase-switch secondary player-back-actions">Torna alle azioni</button></section>
    <form id="player-solve" class="player-phase action-section hidden"><small>RISOLVI LA FRASE</small><div><input id="player-answer" autocomplete="off" placeholder="Scrivi la soluzione"><button>Risolvi</button></div><button type="button" class="phase-switch secondary player-back-actions">Torna alle azioni</button></form>
    <div id="player-message" class="player-message"></div>
    <div id="player-next" class="player-next"></div>
  </section>
  <section id="player-ranking-card" class="player-card ranking-card"><span class="kicker">CLASSIFICA</span><div id="player-ranking"></div></section>
</main>
<script src="assets/chill-reel-player.js?v=9"></script>
</body>
</html>
