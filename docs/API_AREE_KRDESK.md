# API Aree KR Desk

Data definizione: 2026-07-13.

## Obiettivo

Separare il contratto API tra:

- Regia hosting;
- Regia locale sul PC DJ;
- Studio locale.

La separazione è applicata senza refactoring violento: `api.php` resta il router unico, ma ora ha whitelist esplicite e blocco centrale degli endpoint non consentiti in hosting.

## Regola runtime

`api.php` usa `appUsesLocalFiles()` per distinguere l'ambiente:

- `true`: Studio locale / PC DJ, endpoint completi;
- `false`: hosting, solo endpoint Regia hosting.

In hosting, se viene chiamato un endpoint Studio o Regia locale, la risposta è:

```json
{
  "error": "Endpoint disponibile solo nello Studio locale o sul PC DJ.",
  "action": "sync-all",
  "area": "studio-local"
}
```

Status HTTP: `403`.

## Regia hosting

Endpoint ammessi in hosting:

- `bootstrap`
- `live`
- `requests`
- `public-search`
- `request-create`
- `request-status`
- `request-estimates-refresh`
- `request-automix-debug`
- `request-update`
- `request-delete`
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
- `session-tracks-receive`

Note:

- `bootstrap` in hosting è volutamente ridotto: non espone `settings` completi, token, path Windows, `suggestion_start` completo o dati libreria Studio;
- `public-search` in hosting usa `krdesk_session_tracks.json`;
- `request-update` in hosting aggiorna lo stato richiesta, ma non parla direttamente con VirtualDJ;
- l'invio ad Automix resta responsabilità del PC DJ locale tramite proxy o azione locale.

## Regia locale PC DJ

Endpoint Regia solo locali:

- `suggestions`
- `suggestion-start`
- `vdj-control-status`
- `vdj-automix-add`
- `vdj-prelisten`
- `vdj-prelisten-stop`
- `queue`
- `played`
- `quiz-codex-suggest`

Motivo:

- richiedono VirtualDJ Network Control;
- dipendono dal PC DJ;
- possono leggere libreria locale o stato live locale;
- non sono requisito per la Regia hosting.

## Studio locale

Endpoint Studio solo locali:

- `tracks`
- `track`
- `track-update`
- `studio-issues`
- `inbox-status`
- `playlists`
- `playlist-create`
- `playlist-detail`
- `playlist-candidates`
- `playlist-external-compare`
- `playlist-external-folder-match`
- `playlist-external-apply-metadata`
- `playlist-save-order`
- `playlist-replace-track`
- `playlist-remove-track`
- `duplicates`
- `spotify-duplicates`
- `spotify-duplicates-report`
- `duplicate-decision`
- `database-status`
- `music-roots`
- `comparison-folders`
- `definitive-library-folders`
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
- `vdj-search-candidate`
- `vdj-align-artist-title`
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
- `open-folder`
- `settings`
- `recalculate-kr`
- `session-tracks-export`
- `session-tracks-publish`

Motivo:

- lavorano sulla libreria completa;
- leggono o scrivono file locali;
- possono usare path Windows;
- possono spostare, cancellare, sincronizzare o arricchire brani;
- non devono essere raggiungibili da hosting Regia.

## Proxy locale verso hosting

Sul PC DJ locale, alcune azioni Regia vengono inoltrate all'hosting da `shouldProxyToHosting()`:

- `requests`
- `request-create`
- `request-status`
- `request-estimates-refresh`
- `request-automix-debug`
- `request-update`
- `request-delete`
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

Eccezione importante:

- se `request-update` imposta `queued`, il PC locale aggiunge il brano ad Automix prima di inoltrare l'aggiornamento all'hosting.

## File coinvolti

- `api.php`: whitelist e blocco centrale;
- `src/bootstrap.php`: rilevamento ambiente con `appUsesLocalFiles()`;
- `src/SessionTrackUploadService.php`: upload HTTP multipart del JSON sessione verso hosting;
- `docs/CHECK_REGIA_ONLINE.md`: checklist di verifica Regia;
- `docs/SESSION_JSON_SCHEMA.md`: contratto JSON per `public-search` hosting.

## Criterio di chiusura Priorità 3

La priorità è chiusa quando:

- un endpoint Studio in hosting risponde `403`;
- un endpoint Regia locale in hosting risponde `403`;
- `public-search` in hosting usa il JSON sessione;
- gli endpoint pubblico/quiz continuano a rispondere;
- `php -l api.php` è OK;
- la checklist Regia resta coerente.
