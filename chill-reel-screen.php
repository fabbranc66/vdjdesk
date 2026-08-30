<?php declare(strict_types=1); ?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#07100d">
  <title>Chill Reel · Schermo</title>
  <link rel="stylesheet" href="assets/chill-reel-screen.css?v=2">
  <link rel="stylesheet" href="assets/chill-reel-wheel.css?v=5">
  <link rel="stylesheet" href="assets/chill-reel-board.css?v=7">
  <style>.wheel-art text{font-family:Inter,Segoe UI,Arial,sans-serif;font-weight:400;stroke-linejoin:round}.wheel-number,.wheel-special{fill:#fff;stroke:#050505;stroke-width:3.4px;letter-spacing:-.5px}.wheel-number{font-size:22px}.wheel-special{font-size:16px}</style>
</head>
<body>
  <main class="reel-screen">
    <header>
      <div class="reel-brand"><img src="assets/images/kr-music-logo-transparent.png" alt="KR Music"><span><b>CHILL REEL</b><small>KR LIVE GAME</small></span></div>
      <div id="reel-screen-round" class="round-name">In attesa della manche</div>
      <div id="reel-screen-status" class="status-pill">OFF</div>
    </header>
    <section class="reel-stage">
      <div class="reel-main">
        <div class="reel-category" id="reel-screen-category">CHILL REEL</div>
        <div class="reel-board" id="reel-screen-board"><div class="screen-message">La manche sta per iniziare</div></div>
        <div class="reel-live-strip">
          <div><small>TURNO</small><strong id="reel-screen-turn">—</strong></div>
          <div><small>MANCHE FIGLIA</small><strong id="reel-screen-progress">0 / 0</strong></div>
          <div><small>LETTERE USCITE</small><strong id="reel-screen-letters">—</strong></div>
        </div>
      </div>
      <aside>
        <div class="wheel-wrap">
          <div class="wheel-pointer"></div>
          <div class="wheel" id="reel-screen-wheel"><div class="wheel-hub">CHILL<br>REEL</div></div>
        </div>
        <div class="wheel-result"><small>RISULTATO RUOTA</small><strong id="reel-screen-wheel-result">—</strong></div>
        <div class="table-ranking"><h2>Tavoli</h2><div id="reel-screen-tables"><p>Nessun tavolo registrato</p></div></div>
      </aside>
    </section>
  </main>
  <script src="assets/chill-reel-screen.js?v=33"></script>
</body>
</html>
