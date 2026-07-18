# Problemi architetturali KR Desk

Data mappatura: 2026-07-13.

## Sintesi

Il problema principale non Ã¨ una singola funzione, ma la convivenza di funzioni Studio pesanti e funzioni Regia live nello stesso `api.php`, nello stesso `index.php` e nella stessa tabella `settings`.

Questo rende facile portare in hosting cose che dovrebbero restare locali.

## Rischi principali

### 1. API monolitica

`api.php` espone sia endpoint live sia endpoint pesanti:

- richieste/quiz live;
- scansione libreria;
- Spotify metriche;
- cancellazione/spostamento file;
- sync VirtualDJ;
- playlist;
- doppioni;
- impostazioni token/API.

Rischio: una pagina hosting puÃ² chiamare per errore endpoint Studio, o una modifica Studio puÃ² rompere Regia.

### 2. Bootstrap troppo carico

`bootstrap` restituisce insieme:

- live current/recent;
- stats libreria;
- request counts;
- impostazioni;
- tag;
- ambiente.

Rischio: Regia hosting consuma o espone dati non necessari alla live.

### 3. Settings miste

`settings` contiene:

- percorsi Windows;
- porta VirtualDJ;
- formule KR;
- token Spotify;
- chiavi Discogs;
- endpoint Beatport;
- intervallo richieste;
- hosting URL.

Rischio: in hosting possono finire configurazioni locali, path e credenziali non necessarie.

### 4. Proxy locale/hosting delicato

Il codice contiene funzioni `remoteApiFetch`, `remoteApiPassthrough` e `shouldProxyToHosting`.

Rischio: doppia veritÃ  fra locale e hosting, specialmente per richieste/quiz/stato live.

Nota: non va rimosso senza decisione architetturale esplicita, ma va isolato e documentato.

### 5. Regia online potenzialmente legata alla libreria

Endpoint come `public-search` oggi usano `LibraryService`.

Rischio: se usato in hosting, richiede libreria completa nel DB hosting; questo viola la roadmap. La soluzione corretta Ã¨ JSON sessione leggero.

### 6. Dipendenze Windows/locali

Dipendenze locali rilevate:

- `E:\LIBRERIA_MUSICALE`
- `E:\VirtualDJ\database.xml`
- `USERPROFILE`
- `LOCALAPPDATA`
- `powershell.exe`
- clipboard Windows
- Edge Local Storage
- VirtualDJ Network Control
- `explorer.exe`
- file fisici `is_file`, `is_dir`, `touch`, `rename`

Rischio: se chiamate in hosting generano errori o comportamenti inutili.

### 7. Token/API key troppo vicini alla UI generale

Campi Discogs/Beatport/Spotify sono in impostazioni generali.

Rischio: in hosting non servono e non dovrebbero essere esposti.

### 8. Spotify dipende da token Sortlee/Edge

`SpotifyAudioFeaturesService` recupera token da Edge/Sortlee e refresh token salvati.

Rischio: funzione fragile, accettabile in Studio locale ma inadatta alla Regia hosting.

### 9. Playlist ancora troppo potente rispetto alla roadmap

La pagina playlist include giÃ :

- candidati;
- ricerca libreria;
- Spotify ID;
- metriche;
- export;
- ordinamenti.

Rischio: anticipare playlist avanzate prima che metriche/validazione siano stabili.

### 10. Inbox non ancora chiaramente formalizzata

La roadmap vuole Inbox come flusso principale, ma attualmente le funzioni sono sparse fra:

- libreria;
- playlist integrator;
- Spotify;
- spostamento file;
- report;
- cartelle fisiche.

Rischio: catalogazione e archiviazione restano operative ma non governate da stati chiari.

## Funzioni fuori posto o da isolare

| Funzione | Stato | Azione consigliata |
| --- | --- | --- |
| `public-search` su libreria DB | utile ma non ideale per hosting | sostituire con JSON sessione |
| `settings` unico | troppo ampio | separare settings Studio/Regia |
| `bootstrap` unico | troppo carico | creare bootstrap regia leggero |
| endpoint Spotify | corretti solo Studio | bloccarli/ignorarli in hosting |
| endpoint file fisici | corretti solo Studio | bloccarli/ignorarli in hosting |
| endpoint VDJ Network Control | locali | tenere solo in locale/regia locale |
| quiz Codex suggest | utile ma locale/credit-dependent | non renderlo dipendenza live obbligatoria |

## Cose da non fare ora

- Non refactoring massivo di `api.php` prima di avere JSON sessione.
- Non spostare playlist avanzate in prioritÃ  alta.
- Non mettere libreria completa in hosting.
- Non spostare file automaticamente.
- Non usare Beatport come fonte universale.
- Non riclassificare tutta la libreria da zero.

## Rischi hosting specifici

La Regia hosting dovrebbe avere solo:

- eventi;
- richieste;
- quiz;
- partecipanti;
- risposte;
- classifica;
- log essenziali;
- JSON sessione.

Ogni presenza di queste cose in hosting Ã¨ un rischio:

- `tracks` completa;
- `track_sources`;
- `e_file_inventory`;
- `library_databases`;
- token Spotify/Discogs/Beatport;
- path Windows;
- scansioni disco;
- funzioni di cancellazione/spostamento;
- report pesanti.

## Conclusione

L'app funziona, ma va messa in sicurezza architetturale prima di aggiungere altra automazione.

La prioritÃ  non Ã¨ aggiungere feature: Ã¨ separare chiaramente il contratto dati fra Studio e Regia.
