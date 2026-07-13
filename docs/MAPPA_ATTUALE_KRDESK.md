# Mappa attuale KR Desk

Data mappatura: 2026-07-13.

## Sintesi

KR Desk è una web app PHP locale/hosting con frontend unico in `index.php`, API centrale in `api.php`, servizi in `src/` e moduli JavaScript in `assets/`.

La separazione visuale Regia/Studio esiste già nel frontend:

- Regia: `dashboard`, `requests`, `quiz`, `suggestions`.
- Studio: `studio-dashboard`, `library`, `playlists`, `spotify`, `duplicates`, `analysis`, `settings`.

La separazione tecnica è parziale: molte API Studio e Regia convivono nello stesso `api.php`; alcune azioni live/richieste sono state collegate a un proxy hosting, mentre molte funzioni pesanti restano accessibili dallo stesso endpoint.

## Entry point

| File | Ruolo | Area |
| --- | --- | --- |
| `index.php` | Shell principale KR Desk con Regia e Studio | Condivisa |
| `api.php` | Router API monolitico per tutte le azioni | Condivisa |
| `request.php` | Pagina pubblica mobile richieste + quiz | Regia |
| `quiz-screen.php` | Schermo esterno quiz | Regia |
| `qr.php` | Generazione QR verso pagina pubblica | Regia |
| `config.php` | Config locale/hosting runtime | Condivisa, non versionare |
| `config.example.php` | Esempio config | Condivisa |

## Servizi PHP

| File | Funzione | Area corretta |
| --- | --- | --- |
| `src/bootstrap.php` | DB, schema, config, utility, ambiente locale/hosting | Condivisa |
| `src/LibraryService.php` | Import VDJ, ricerca libreria, cartelle, sync DB | Studio locale |
| `src/SpotifyAudioFeaturesService.php` | Spotify ID, metadati, metriche, token Edge/Sortlee | Studio locale |
| `src/EDuplicateService.php` | Scansione doppioni interni E: | Studio locale |
| `src/EComparisonService.php` | Confronto cartelle e candidati cancellazione | Studio locale |
| `src/TrackDeletionService.php` | Cancellazione/spostamento fisico file | Studio locale |
| `src/AudioReplacementService.php` | Sostituzione audio scaricato | Studio locale |
| `src/PlaylistService.php` | Lettura/scrittura playlist, candidati, import JSON | Studio locale |
| `src/VirtualDjControlService.php` | Network Control VirtualDJ, automix, preascolto | Locale/Regia locale |
| `src/VirtualDjHistoryService.php` | Cronologia/live VirtualDJ | Locale/Regia locale |
| `src\RequestEstimateService.php` | Orari stimati richieste da automix | Regia, ma dipende da VDJ locale |
| `src/QuizService.php` | Quiz, partecipanti, risposte, classifica | Regia |
| `src/CodexQuizSuggestionService.php` | Suggerimento domanda via Codex CLI | Studio/Regia assistita, locale |
| `src/SuggestionService.php` | Suggeritore prossimo brano | Regia locale |
| `src/LibraryStandardService.php` | Regole standard classificazione | Studio locale |

## Frontend JavaScript

| File | Funzione | Area |
| --- | --- | --- |
| `assets/app.js` | Stato globale, navigazione, dashboard, libreria base, richieste | Condivisa |
| `assets/quiz-control.js` | Regia quiz | Regia |
| `assets/quiz-public.js` | Quiz pubblico mobile | Regia |
| `assets/quiz-screen.js` | Schermo quiz | Regia |
| `assets/request.js` | Richieste pubblico mobile | Regia |
| `assets/automix-suggestions.js` | Azioni automix/suggeritore | Regia locale |
| `assets/spotify-features.js` | Spotify link, metriche, Spotmate | Studio locale |
| `assets/spotify-export-filter.js` | Export lista a Spotify to VDJ | Studio locale |
| `assets/library-quality.js` | Filtri qualità libreria | Studio locale |
| `assets/library-sort.js` | Ordinamento tabella libreria | Studio locale |
| `assets/bulk-tags.js` | Tag massivi | Studio locale |
| `assets/vdj-years.js` | Allineamento anno/genere da VDJ | Studio locale |
| `assets/playlists.js` | Gestione playlist | Studio locale |
| `assets/playlist-builder.js` | Candidati playlist | Studio locale |
| `assets/playlist-integrator.js` | Import JSON Spotify/Soundiiz | Studio locale |
| `assets/formula-settings.js` | Formule punteggi KR | Studio locale |
| `assets/analysis.js` | Analisi generi/standard | Studio locale |

## Tool locali

| File | Funzione | Area |
| --- | --- | --- |
| `tools/spotify-to-vdj.html` | Tool embedded Spotify -> VDJ | Studio locale |
| `tools/enrich-sortlee-folder.php` | Arricchimento cartelle da Sortlee/Spotify | Studio locale |
| `tools/export-soundiiz-json.php` | Export JSON per Soundiiz | Studio locale |
| `tools/migrate-sqlite-to-mariadb.php` | Migrazione DB | Tecnico, non live |

## Tabelle DB rilevate

| Tabella | Record attuali | Area |
| --- | ---: | --- |
| `tracks` | 9425 | Studio locale |
| `track_sources` | 9424 | Studio locale |
| `library_databases` | 2 | Studio locale |
| `e_duplicate_scans` | 1 | Studio locale |
| `e_file_inventory` | 9417 | Studio locale |
| `e_duplicate_groups` | 25 | Studio locale |
| `e_duplicate_group_items` | 50 | Studio locale |
| `deletion_candidates` | 1 | Studio locale |
| `duplicate_decisions` | 0 | Studio locale |
| `history` | 0 | Regia locale / Studio |
| `queue` | 0 | Regia locale |
| `requests` | 0 | Regia hosting/live |
| `quiz_questions` | 12 | Regia hosting/live |
| `quiz_participants` | 2 | Regia hosting/live |
| `quiz_answers` | 3 | Regia hosting/live |
| `settings` | 29 | Condivisa, da separare per ambiente |

## API Regia

Endpoint orientati al live:

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
- `suggestions`
- `vdj-automix-add`
- `vdj-prelisten`
- `vdj-prelisten-stop`

Nota: alcune API Regia sono davvero live/hosting, altre richiedono VirtualDJ locale e non possono funzionare in hosting senza proxy/ponte locale.

## API Studio

Endpoint pesanti o locali:

- `tracks`
- `track`
- `studio-issues`
- `inbox-status`
- `database-status`
- `music-roots`
- `comparison-folders`
- `definitive-library-folders`
- `duplicates`
- `spotify-duplicates`
- `spotify-duplicates-report`
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
- `scan`
- `playlist-*`
- `open-folder`
- `settings`
- `recalculate-kr`
- `session-tracks-export`

## Parti condivise da chiarire

- `bootstrap` serve sia Regia sia Studio ma restituisce dati di libreria, statistiche, richieste e stato live insieme.
- `settings` contiene sia configurazioni locali pesanti sia configurazioni live.
- `index.php` contiene entrambe le aree e nasconde/mostra in base a `environment`.
- `api.php` è monolitico: separazione logica non corrisponde ancora a separazione fisica.

## Stato operativo attuale

- DB libreria locale già limitato a `E:`.
- Inventario doppioni mantiene solo ultima scansione.
- Suggeritore aggiornato con direzioni più differenziate.
- Discogs e Beatport hanno campi impostazioni, ma integrazione metadati non completa.
- Token Spotify operativo dopo refresh recente, ma dipende da token Edge/Sortlee.
