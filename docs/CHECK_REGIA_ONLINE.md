# Check Regia Online

Data congelamento: 2026-07-13.

## Obiettivo

Congelare la Regia attuale prima di aggiungere nuove funzioni.

La Regia deve fare solo lavoro live:

- gestire richieste pubblico;
- gestire quiz live;
- mostrare stato live e cronologia essenziale;
- inviare ad Automix solo dal PC locale del DJ;
- evitare funzioni Studio, scansioni, sincronizzazioni, cancellazioni, spostamenti e path Windows in hosting.

## Stato congelato

### Pagine Regia

| Pagina | File | Stato |
| --- | --- | --- |
| Console Regia | `index.php` | Congelata con viste `dashboard`, `requests`, `quiz`, `suggestions` |
| Pagina pubblico | `request.php` | Congelata |
| Schermo quiz | `quiz-screen.php` | Congelato |
| QR | `qr.php` | Congelato |

### Viste Regia

| Vista | Uso | Hosting |
| --- | --- | --- |
| `dashboard` | live desk locale | Da non esporre come vista primaria hosting |
| `requests` | gestione richieste | Consentita |
| `quiz` | regia quiz | Consentita |
| `suggestions` | prossimo brano / Automix locale | Solo locale |

In hosting `assets/app.js` consente solo `requests` e `quiz` tramite `hostingAllowedViews`.

## API Regia hosting consentite

Questi endpoint sono considerati parte del contratto Regia hosting:

- `bootstrap`
- `live`
- `requests`
- `public-search`
- `request-create`
- `request-status`
- `request-estimates-refresh`
- `request-update`
- `request-delete`
- `request-automix-debug`
- `quiz-state`
- `quiz-history`
- `quiz-create`
- `quiz-launch`
- `quiz-close`
- `quiz-reveal`
- `quiz-join`
- `quiz-answer`
- `quiz-heartbeat`
- `quiz-leave`
- `quiz-participant-action`
- `quiz-prefill`
- `network-info`

Endpoint Regia locale, non hosting:

- `suggestions`
- `suggestion-start`
- `vdj-control-status`
- `vdj-automix-add`
- `vdj-prelisten`
- `vdj-prelisten-stop`
- `queue`
- `played`
- `quiz-codex-suggest`

## API Studio vietate in hosting Regia

Questi endpoint non devono essere chiamati dalla Regia hosting:

- `tracks`
- `track`
- `studio-issues`
- `database-status`
- `music-roots`
- `comparison-folders`
- `definitive-library-folders`
- `duplicates`
- `e-duplicates-status`
- `e-duplicates`
- `e-duplicates-scan`
- `e-duplicates-refresh-recommendations`
- `e-duplicates-decision`
- `e-duplicates-mark-nonrecommended`
- `deletion-candidates`
- `deletion-candidate-decision`
- `deletion-candidates-mark-all`
- `deletion-candidates-approve-all`
- `deletion-candidates-clear`
- `approved-folder-summary`
- `compare-folder-e`
- `spotify-audio-features`
- `spotify-link-update`
- `spotify-clipboard-start`
- `spotify-clipboard-status`
- `spotify-identify-features`
- `spotify-identify`
- `spotify-candidates`
- `replacement-watch-start`
- `replacement-watch-status`
- `track-delete`
- `track-move`
- `touch-tracks`
- `touch-track-file`
- `bulk-track-tags`
- `bulk-vdj-metadata`
- `auto-tag-override`
- `vdj-genre-stats`
- `library-standard-validate`
- `library-standard-test`
- `import-vdj`
- `sync-all`
- `reconcile-vdj`
- `prune-library`
- `import-m3u`
- `playlist-*`
- `open-folder`
- `settings`
- `recalculate-kr`
- `session-tracks-export`

## Verifica statica eseguita

Comandi eseguiti:

- `php -l api.php`
- `php -l index.php`
- `php -l request.php`
- `php -l quiz-screen.php`
- ricerca endpoint in `assets/*.js`, `index.php`, `request.php`, `quiz-screen.php`, `api.php`

Esito:

- `api.php`: sintassi OK;
- `index.php`: sintassi OK;
- `request.php`: sintassi OK;
- `quiz-screen.php`: sintassi OK;
- `request.php` / `assets/request.js` chiamano solo endpoint Regia pubblici;
- `quiz-screen.php` / `assets/quiz-public.js` chiamano solo endpoint quiz pubblici;
- `index.php` carica anche asset Studio, ma in hosting `app.js` forza le viste consentite a `requests` e `quiz`;
- il proxy locale verso hosting passa solo gli endpoint in `shouldProxyToHosting()`.

## Warning congelati

Questi punti non vanno ignorati prima della Priorità 3:

1. `assets/automix-suggestions.js` chiama `database-status`, che è endpoint Studio.
   - Impatto: non deve essere raggiungibile/necessario in hosting Regia.
   - Decisione congelamento: tollerato solo in locale, da spostare fuori Regia o proteggere.

2. `assets/automix-suggestions.js` chiama `suggestion-start`.
   - Impatto: endpoint Regia locale non presente nella lista iniziale della mappa.
   - Decisione congelamento: documentato come parte del supporto suggeritore locale, non hosting.

3. `assets/quiz-control.js` chiama `quiz-codex-suggest`.
   - Impatto: funzione assistita locale/studio, non indispensabile alla Regia hosting.
   - Decisione congelamento: non usarla come requisito per la live online.

4. `bootstrap` è condiviso tra Regia e Studio.
   - Impatto: risposta troppo ampia per hosting.
   - Decisione congelamento: accettato temporaneamente; da risolvere con JSON sessione e API Regia/Studio separate.

## Checklist manuale Regia

### Console hosting

- [ ] Aprire `index.php` in hosting.
- [ ] Verificare che la modalità mostrata sia hosting.
- [ ] Verificare che Studio non sia visibile.
- [ ] Verificare che le viste accessibili siano solo `requests` e `quiz`.
- [ ] Verificare che `bootstrap` hosting non esponga token, path Windows o settings Studio.
- [ ] Aprire DevTools console e confermare assenza errori JavaScript.
- [ ] Aprire DevTools network e confermare assenza chiamate a endpoint Studio.

### Richieste pubblico

- [ ] Aprire `request.php` da smartphone o browser esterno.
- [ ] Cercare un brano.
- [ ] Inviare richiesta con nome ospite.
- [ ] Verificare token richiesta e pagina stato.
- [ ] Verificare aggiornamento in console Regia.
- [ ] Approvare richiesta.
- [ ] Rifiutare richiesta.
- [ ] Inserire nota DJ.
- [ ] Eliminare richiesta di test.

### Automix

- [ ] In locale, mettere una richiesta in `queued`.
- [ ] Verificare che il brano venga inviato a VirtualDJ.
- [ ] In hosting, verificare che Automix non richieda path Windows o accesso diretto a VirtualDJ.
- [ ] Se Automix passa dal proxy locale, verificare messaggio di errore chiaro quando il PC DJ non è raggiungibile.

### Quiz

- [ ] Aprire Regia Quiz.
- [ ] Creare domanda manuale.
- [ ] Aprire `request.php` come partecipante.
- [ ] Entrare nel quiz.
- [ ] Lanciare domanda.
- [ ] Rispondere da partecipante.
- [ ] Chiudere domanda.
- [ ] Rivelare risposta.
- [ ] Verificare classifica.
- [ ] Aprire `quiz-screen.php` e verificare aggiornamenti live.

### Path Windows e funzioni locali

- [ ] Confermare che hosting non mostri `E:\`.
- [ ] Confermare che hosting non mostri `C:\`.
- [ ] Confermare che hosting non provi `open-folder`.
- [ ] Confermare che hosting non provi `sync-all`.
- [ ] Confermare che hosting non provi `scan`.
- [ ] Confermare che hosting non provi `import-vdj`.
- [ ] Confermare che hosting non provi cancellazioni o spostamenti file.

## Criterio di chiusura Priorità 1

La Priorità 1 è chiusa solo quando:

- tutti i test manuali critici sono spuntati;
- in DevTools non compaiono errori console durante richieste e quiz;
- in DevTools non compaiono chiamate Studio dalla Regia hosting;
- nessuna schermata hosting richiede path Windows;
- i warning sopra sono accettati come debito tecnico documentato oppure corretti.

## Prossimo passo

Dopo questa verifica, procedere con `docs/SESSION_JSON_SCHEMA.md` per evitare che la Regia hosting dipenda dalla libreria completa.
