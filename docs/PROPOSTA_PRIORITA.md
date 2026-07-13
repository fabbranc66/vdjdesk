# Proposta priorità KR Desk

Data proposta: 2026-07-13.

## Obiettivo immediato

Applicare la roadmap senza rompere ciò che funziona.

Priorità: non aggiungere complessità alla Regia; spostare il lavoro pesante nello Studio; usare un JSON sessione come ponte leggero.

## Priorità 1 - Congelare e verificare Regia

### Scopo

Garantire che hosting/live faccia solo live.

### Azioni

1. Creare checklist Regia.
2. Testare:
   - dashboard live;
   - richieste pubblico;
   - approva/rifiuta/automix;
   - quiz;
   - classifica;
   - pagina mobile;
   - schermo esterno.
3. Verificare console browser.
4. Verificare che in hosting non vengano chiamati endpoint Studio.
5. Verificare che hosting non richieda path Windows.

### Output

- `docs/CHECK_REGIA_ONLINE.md`

## Priorità 2 - Definire JSON sessione

### Scopo

Evitare libreria completa in hosting.

### Azioni

1. Definire schema `krdesk_session_tracks.json`.
2. Esportare da Studio locale:
   - `track_id`;
   - artista/titolo;
   - normalizzati;
   - search text;
   - genere/macro/sottogenere;
   - bpm/key/camelot opzionali;
   - tag/versione/priorità;
   - evento/sessione;
   - disponibile.
3. Fare usare il JSON alla ricerca pubblica.
4. Salvare richieste in hosting con `track_id` + snapshot artista/titolo.
5. Preparare import post-serata nello Studio.

### Output

- `docs/SESSION_JSON_SCHEMA.md`
- export JSON Studio
- caricamento JSON Regia

## Priorità 3 - Separare API Regia e Studio

### Scopo

Ridurre rischio del router monolitico.

### Azioni minime, senza refactoring violento

1. Aggiungere una lista esplicita di endpoint Regia consentiti.
2. Aggiungere una lista esplicita di endpoint Studio locali.
3. In hosting, bloccare o nascondere endpoint Studio.
4. In Studio, continuare a usare endpoint completi.
5. Documentare comportamento.

### Output

- `docs/API_AREE_KRDESK.md`

## Priorità 4 - Consolidare Inbox

### Scopo

Fare della Inbox il punto di ingresso dei brani nuovi.

### Azioni

1. Mappare stati/cartelle Inbox esistenti.
2. Definire stati tecnici:
   - `DA_CATALOGARE`
   - `CATALOGATO_DA_CONFERMARE`
   - `CATALOGATO_CONFERMATO`
   - `DA_ASCOLTARE`
   - `DUBBIO`
   - `CONFLITTO`
   - `DA_RICLASSIFICARE`
   - `DA_CANCELLARE`
   - `PRONTO_ARCHIVIAZIONE`
   - `ARCHIVIATO`
   - `SCARTATO`
3. Mostrare colonne:
   - completezza metriche;
   - confidenza match;
   - fonte genere;
   - coerenza classificazione;
   - azione suggerita.
4. Nessuno spostamento automatico.

### Output

- `docs/INBOX_STATI.md`
- endpoint Studio locale `inbox-status`

## Priorità 5 - Stabilizzare metriche

### Scopo

Rendere affidabili gli indici prima di usarli per archiviare o playlist.

### Azioni

1. Report brani:
   - senza Spotify ID;
   - con Spotify ID senza metriche;
   - con metriche complete;
   - con match incerto;
   - con genere mancante;
   - con metriche derivate.
2. Separare metriche originali da derivate.
3. Non inventare campi originali mancanti.
4. Discogs e Beatport solo dopo schema stabile.

### Output

- `docs/METRICHE_STATO.md`
- report CSV in `storage/reports/`

## Priorità 6 - Validazione classificazione

### Scopo

Non riclassificare tutto: controllare solo dubbi.

### Azioni

1. Calcolare coerenza classificazione 0-100.
2. Produrre esiti:
   - `OK`
   - `OK_CON_NOTE`
   - `DUBBIO`
   - `CONFLITTO`
   - `DA_ASCOLTARE`
   - `DA_RICLASSIFICARE`
   - `ESCLUDERE`
3. Generare CSV/M3U per ascolto mirato.

### Output

- report CSV classificazione;
- M3U dubbi/conflitti/versioni sospette.

## Priorità 7 - Spostamento file SAFE

### Scopo

Solo dopo catalogazione confermata.

### Azioni

1. Definire mapping destinazioni.
2. Proporre spostamenti.
3. Richiedere conferma.
4. Spostare senza sovrascrivere.
5. Loggare old/new path.
6. Segnare verifica VDJ.

### Output

- `GENRE_FOLDER_MAPPING.csv`
- report spostamenti.

## Priorità rimandate

- Playlist avanzate.
- Ordinamenti automatici scaletta.
- Automazioni forti basate su indici sperimentali.
- Beatport completo fino a credenziali e schema metadati stabile.

## Prossimo passo consigliato

Procedere con `CHECK_REGIA_ONLINE.md`, poi subito `SESSION_JSON_SCHEMA.md`.

Questo evita di continuare a spingere libreria e funzioni pesanti verso hosting.
