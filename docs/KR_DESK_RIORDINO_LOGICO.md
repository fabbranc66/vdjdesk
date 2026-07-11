# KR Desk — Riordino logico Fase A

## Obiettivo

Riordinare KR Desk prima di aggiungere nuove logiche musicali.

Questa fase non modifica:

- algoritmi di identificazione brani;
- logiche Spotify / Discogs / Beatport;
- formule musicali;
- classificazione automatica;
- report musicali avanzati.

La Fase A serve solo a rendere KR Desk una console ordinata, separando uso live e uso studio.

## Stato attuale rilevato

KR Desk oggi è un'app locale PHP con una pagina principale monolitica (`index.php`) e molte funzioni distribuite in script JS separati.

### Pagine principali

- `index.php`: console principale, oggi contiene live, libreria, suggeritore, Spotify to VDJ, doppioni, richieste, quiz, playlist, analisi, impostazioni.
- `request.php`: pagina pubblica mobile per richieste e quiz.
- `quiz-screen.php`: pagina schermo esterno quiz.
- `tools/spotify-to-vdj.html`: tool locale incorporato in iframe.
- `api.php`: unico endpoint API con molte azioni eterogenee.

### Aree già presenti

- Live desk / dashboard.
- Ricerca libreria.
- Suggeritore prossimo brano.
- Spotify to VirtualDJ.
- Doppioni e candidati cancellazione.
- Richieste pubblico.
- Quiz live regia / mobile / schermo.
- Playlist e builder playlist.
- Import JSON Spotify / Soundiiz.
- Analisi generi e standard libreria.
- Impostazioni / sincronizzazione VDJ / formule.

### Servizi backend rilevati

- `LibraryService.php`: libreria, import, ricerca, sync VDJ, metadata.
- `VirtualDjControlService.php`: Network Control, Automix, preascolto, comandi VDJ.
- `VirtualDjHistoryService.php`: cronologia live.
- `SuggestionService.php`: suggeritore prossimo brano.
- `RequestEstimateService.php`: richieste pubblico e orario stimato da Automix.
- `QuizService.php`: quiz live, partecipanti, risposte, classifica.
- `PlaylistService.php`: playlist, ordinamenti, import JSON, candidati.
- `SpotifyAudioFeaturesService.php`: Spotify ID, metriche, link, metadati.
- `AudioReplacementService.php`: sostituzione audio via download monitorato.
- `EDuplicateService.php`: doppioni interni a E.
- `EComparisonService.php`: confronto cartelle con libreria definitiva E.
- `TrackDeletionService.php`: cancellazione/spostamento tracce.
- `LibraryStandardService.php`: test standard libreria.
- `CodexQuizSuggestionService.php`: suggerimento domanda quiz.

## Problema principale

Le funzioni non sono sbagliate, ma sono tutte troppo vicine.

Oggi la navigazione mette sullo stesso livello:

- strumenti live;
- strumenti tecnici di manutenzione;
- strumenti di classificazione;
- playlist operative;
- import/export;
- quiz e richieste pubblico;
- impostazioni profonde.

Durante una serata questo crea rumore.

Durante lavoro studio, invece, la libreria contiene troppe azioni operative live.

## Nuovo principio di navigazione

Separare KR Desk in due modalità:

1. Regia
2. Studio

La modalità scelta deve cambiare menu, priorità visiva e dashboard.

## Modalità Regia

Uso: durante evento live.

Deve contenere solo funzioni rapide, leggibili e operative.

### Menu Regia proposto

1. Live
2. Richieste
3. Quiz
4. Karaoke
5. Automix / Coda
6. Note Regia

### Live

Contiene:

- brano on air;
- BPM, key, genere, energia;
- ultimi suonati;
- richieste nuove;
- quiz attivo;
- automix attivo;
- azioni rapide;
- suggeritore prossimo brano in versione compatta.

Non contiene:

- scansione libreria;
- doppioni;
- formule;
- import/export;
- report tecnici.

### Richieste

Contiene:

- richieste nuove;
- approvate;
- mandate ad Automix;
- rifiutate;
- cancellazione richiesta;
- messaggio pubblico;
- orario stimato da Automix;
- QR pagina pubblico.

### Quiz

Contiene:

- domanda in preparazione;
- suggerisci domanda;
- lancia / chiudi / mostra soluzione;
- partecipanti;
- richieste rientro;
- classifica;
- link mobile e schermo.

### Automix / Coda

Contiene:

- lettura lista Automix;
- brano on air come riga di partenza;
- ETA richieste;
- eventuale debug solo collassato.

### Karaoke

Non implementato come area autonoma completa.

Da prevedere come sezione Regia separata, non dentro Libreria.

## Modalità Studio

Uso: prima/dopo serata.

Contiene manutenzione, classificazione, preparazione playlist e configurazioni.

### Menu Studio proposto

1. Dashboard Studio
2. Libreria
3. Playlist
4. Import / Export
5. Qualità / Doppioni
6. Analisi
7. Eventi
8. Configurazione

### Dashboard Studio

Risponde a: cosa devo sistemare adesso?

Mostra:

- database VDJ da sincronizzare;
- brani senza Spotify ID;
- brani senza metriche;
- brani sotto standard qualità;
- doppioni aperti;
- candidati cancellazione;
- playlist in preparazione;
- generi non mappati;
- ultimo report.

### Libreria

Contiene:

- elenco brani;
- ricerca avanzata;
- cartelle reali;
- tag e punteggi;
- Spotify ID / metriche;
- link Spotify;
- SpotMate / sostituzione audio;
- allineamento tag VDJ;
- spostamento fisico in cartella genere;
- cancellazione diretta quando confermata.

### Playlist

Contiene:

- lettura playlist libreria definitiva;
- creazione playlist;
- modifica ordine;
- Camelot Strict / Soft;
- builder candidati;
- export M3U;
- porta in Spotify to VDJ;
- import JSON Spotify / Soundiiz;
- confronto JSON con libreria;
- congruenza lista scaricata.

### Import / Export

Da separare dalla pagina Playlist.

Contiene:

- JSON Spotify / Soundiiz;
- export mancanti;
- export M3U;
- tool Spotify to VDJ;
- procedure Sortlee;
- eventuali report generati.

### Qualità / Doppioni

Contiene:

- doppioni interni E;
- confronto cartella con E;
- archivio marcati;
- archivio approvati;
- azzera stati;
- qualità file;
- bitrate;
- video/audio separati;
- file mancanti.

### Analisi

Contiene:

- generi presenti;
- conteggi;
- standard libreria;
- test mapping;
- report tecnici.

### Eventi

Da creare come area autonoma.

Contiene:

- evento attivo;
- prossimo evento;
- format;
- blocchi musicali;
- playlist associate;
- quiz associati;
- recap.

### Configurazione

Contiene:

- percorsi;
- porta VDJ;
- sincronizzazione DB;
- formule;
- soglie;
- mapping generi;
- impostazioni richieste;
- versionamento regole.

## Spostamenti consigliati

### Da `index.php` a Regia

- `view-dashboard` diventa `regia-live`.
- Parte live di `view-requests` diventa `regia-requests`.
- Parte quiz di `view-requests` diventa `regia-quiz`.
- Suggeritore compatto entra in Regia come supporto live.

### Da `index.php` a Studio

- `view-library` resta in Studio.
- `view-playlists` resta in Studio, ma va separato da import JSON.
- `view-duplicates` resta in Studio.
- `view-analysis` resta in Studio.
- `view-settings` resta in Studio.
- `view-spotify` va spostato in Import / Export.

### Da separare

- Richieste e Quiz oggi sono nella stessa view: vanno separati come due pagine Regia.
- Playlist e Import JSON oggi sono nella stessa view: vanno separati.
- Libreria e Spotify actions oggi sono molto intrecciati: va bene, ma solo in Studio.
- Doppioni e cancellazioni devono restare fuori da Regia.

## Funzioni duplicate o confuse

### `Spotify to VDJ`

Esiste sia come tool iframe sia come azioni sparse in Libreria / Playlist.

Decisione:

- azioni rapide restano su Libreria/Playlist;
- tool completo va in Studio > Import / Export.

### Tag globale

Compare in Libreria e Playlist.

Decisione:

- resta come componente condiviso Studio;
- non deve comparire in Regia.

### Anno VDJ -> KR

Compare in Libreria e Playlist.

Decisione:

- componente Studio condiviso;
- posizione consigliata: Libreria e Playlist, ma con stessa UI.

### Doppioni / cancellazione

Oggi ci sono stati archivio, marcati, approvati, cerca e sposta.

Decisione:

- tutto in Studio > Qualità / Doppioni;
- nessuna esposizione in Regia.

### Quiz

Oggi è dentro Richieste.

Decisione:

- Regia > Quiz come voce separata;
- pagina mobile può restare unica `request.php` con tab Richieste / Quiz.

## Nuova mappa navigazione proposta

```text
KR Desk
├── Modalità Regia
│   ├── Live
│   ├── Richieste
│   ├── Quiz
│   ├── Karaoke
│   ├── Automix / Coda
│   └── Note Regia
│
└── Modalità Studio
    ├── Dashboard Studio
    ├── Libreria
    ├── Playlist
    ├── Import / Export
    ├── Qualità / Doppioni
    ├── Analisi
    ├── Eventi
    └── Configurazione
```

## Primo MVP di riordino

Per evitare di rompere funzioni già operative, il primo intervento deve essere solo di layout/navigazione.

### Step 1

Creare switch Regia / Studio nella sidebar.

Effetto:

- in Regia mostra solo Live, Richieste, Quiz, Automix;
- in Studio mostra Libreria, Playlist, Import / Export, Qualità, Analisi, Configurazione.

Nessun endpoint nuovo.

### Step 2

Separare visivamente Richieste e Quiz.

Effetto:

- `view-requests` diventa solo richieste;
- nuova `view-quiz` usa lo stesso markup quiz già presente.

Nessuna modifica a `QuizService`.

### Step 3

Separare Playlist da Import JSON.

Effetto:

- nuova `view-import-export`;
- spostare blocco `playlist-integrator`;
- mantenere gli stessi JS e API.

### Step 4

Rinominare Doppioni in Qualità / Doppioni.

Effetto:

- più chiaro che contiene qualità, confronto E, archivi, cancellazioni.

### Step 5

Dashboard pulita.

Regia dashboard:

- on air;
- richieste nuove;
- quiz attivo;
- automix;
- ultimi suonati;
- azioni rapide.

Studio dashboard:

- sincronizzazioni;
- brani da completare;
- doppioni;
- import/export;
- report.

## File da modificare nel primo intervento

### Obbligatori

- `index.php`
  - aggiungere switch Regia / Studio;
  - riorganizzare nav;
  - separare `view-requests` e `view-quiz`;
  - aggiungere `view-import-export`;
  - rinominare `view-duplicates` in area Qualità / Doppioni.

- `assets/app.js`
  - aggiornare `showView`;
  - gestire visibilità menu per modalità;
  - caricare quiz quando si apre `view-quiz`;
  - caricare import/export quando necessario;
  - non cambiare logiche musicali.

- `assets/app.css`
  - stile switch modalità;
  - eventuale badge Regia / Studio;
  - visibilità menu.

### Probabili

- `assets/quiz-control.js`
  - piccoli aggiustamenti selettori se il markup quiz esce da `view-requests`.

- `assets/playlist-integrator.js`
  - piccoli aggiustamenti se il blocco viene spostato in nuova view.

### Da non modificare in Fase A

- `SuggestionService.php`
- `SpotifyAudioFeaturesService.php`
- `LibraryStandardService.php`
- `EDuplicateService.php`
- `EComparisonService.php`
- formule e mapping musicale
- schema logico di classificazione brani

## Rischi

### Rischio 1: JS monolitico

`assets/app.js` sovrascrive e viene sovrascritto da altri script.

Mitigazione:

- non riscrivere tutto;
- spostare markup mantenendo ID esistenti;
- cambiare solo routing/view.

### Rischio 2: mojibake

Alcuni testi in `index.php` e `assets/app.js` risultano già corrotti.

Mitigazione:

- durante il riordino, sostituire testi e icone con UTF-8 pulito o entità HTML;
- non farlo insieme a modifiche musicali.

### Rischio 3: funzioni live dipendenti da ID

Quiz, richieste e playlist usano selettori precisi.

Mitigazione:

- mantenere gli stessi ID;
- spostare blocchi senza rinominare internamente.

## Decisione finale consigliata

Procedere con Fase A in questo ordine:

1. switch Regia / Studio;
2. menu filtrato per modalità;
3. separazione Richieste / Quiz;
4. separazione Playlist / Import Export;
5. dashboard Studio leggera;
6. pulizia mojibake visibile;
7. solo dopo, Fase B motore validazione brani.

Non iniziare Discogs / Beatport prima di questi passaggi.
