# Session JSON Schema

Data definizione: 2026-07-13.

## Obiettivo

Usare un file `krdesk_session_tracks.json` come ponte leggero tra Studio locale e Regia hosting.

La Regia hosting non deve interrogare la libreria completa, non deve conoscere path Windows e non deve dipendere da `E:\`.

## File

Nome standard:

- `krdesk_session_tracks.json`

Percorso consigliato:

- export Studio locale: `storage/exports/krdesk_session_tracks.json`
- copia hosting: `storage/session/krdesk_session_tracks.json`

Il file deve essere UTF-8, JSON valido, senza BOM.

## Struttura root

```json
{
  "schema": "krdesk.session_tracks",
  "schema_version": 1,
  "generated_at": "2026-07-13T20:30:00+02:00",
  "source": {
    "app": "KR DJ Desk",
    "environment": "studio-local",
    "database": "vdjdesk",
    "library_root": "E:",
    "export_profile": "public-request-search"
  },
  "session": {
    "id": "kr-2026-07-13-serata",
    "name": "Serata KR",
    "event_date": "2026-07-13",
    "venue": "",
    "notes": ""
  },
  "stats": {
    "tracks": 2,
    "available": 2,
    "unavailable": 0
  },
  "tracks": []
}
```

## Campi root

| Campo | Tipo | Obbligatorio | Note |
| --- | --- | --- | --- |
| `schema` | string | sÃ¬ | Sempre `krdesk.session_tracks` |
| `schema_version` | integer | sÃ¬ | Versione iniziale `1` |
| `generated_at` | string | sÃ¬ | ISO 8601 con timezone |
| `source` | object | sÃ¬ | Informazioni Studio/export |
| `session` | object | sÃ¬ | Evento o sessione live |
| `stats` | object | sÃ¬ | Conteggi rapidi |
| `tracks` | array | sÃ¬ | Elenco brani pubblicabili |

## Oggetto `source`

| Campo | Tipo | Obbligatorio | Note |
| --- | --- | --- | --- |
| `app` | string | sÃ¬ | `KR DJ Desk` |
| `environment` | string | sÃ¬ | `studio-local` |
| `database` | string | no | Nome database sorgente |
| `library_root` | string | no | Solo indicazione sintetica, es. `E:` |
| `export_profile` | string | sÃ¬ | `public-request-search` |

`source` non deve contenere path completi dei file audio.

## Oggetto `session`

| Campo | Tipo | Obbligatorio | Note |
| --- | --- | --- | --- |
| `id` | string | sÃ¬ | ID stabile evento/sessione |
| `name` | string | sÃ¬ | Nome leggibile |
| `event_date` | string | no | `YYYY-MM-DD` |
| `venue` | string | no | Locale/evento |
| `notes` | string | no | Note non pubbliche o vuote |

## Oggetto `track`

```json
{
  "track_id": 123,
  "artist": "Artist Name",
  "title": "Track Title",
  "artist_normalized": "artist name",
  "title_normalized": "track title",
  "search_text": "artist name track title genre tag versione",
  "genre": "Reggaeton",
  "macro_genre": "Urban Latino",
  "subgenre": "Reggaeton",
  "year": 2018,
  "bpm": 96.0,
  "musical_key": "G#m",
  "camelot": "1A",
  "tags": ["PISTA", "URLANTE"],
  "version": "clean",
  "priority": 80,
  "available": true,
  "public": true,
  "requestable": true,
  "session": {
    "id": "kr-2026-07-13-serata",
    "bucket": "main",
    "note": ""
  }
}
```

## Campi `track`

| Campo | Tipo | Obbligatorio | Note |
| --- | --- | --- | --- |
| `track_id` | integer | sÃ¬ | ID locale KR Desk; diventa riferimento richiesta |
| `artist` | string | sÃ¬ | Snapshot pubblico artista |
| `title` | string | sÃ¬ | Snapshot pubblico titolo |
| `artist_normalized` | string | sÃ¬ | Da `normalized_artist` |
| `title_normalized` | string | sÃ¬ | Da `normalized_title` |
| `search_text` | string | sÃ¬ | Testo giÃ  normalizzato per ricerca client/server |
| `genre` | string | no | Genere principale attuale |
| `macro_genre` | string | no | Macro area DJ, se disponibile |
| `subgenre` | string | no | Sottogenere, se disponibile |
| `year` | integer/null | no | Anno se affidabile |
| `bpm` | number/null | no | BPM se disponibile |
| `musical_key` | string | no | Chiave originale |
| `camelot` | string | no | Chiave Camelot |
| `tags` | array[string] | no | Tag manuali/automatici pubblicabili |
| `version` | string | no | Clean, remix, extended, live, ecc. |
| `priority` | integer | sÃ¬ | 0-100 per ordinamento risultati |
| `available` | boolean | sÃ¬ | Brano disponibile nella sessione |
| `public` | boolean | sÃ¬ | Brano visibile alla ricerca pubblico |
| `requestable` | boolean | sÃ¬ | Brano richiedibile |
| `session` | object | sÃ¬ | Collegamento evento/sessione |

## Campi esclusi

Il JSON sessione non deve contenere:

- `file_path`
- `folder`
- `file_name`
- path `E:\`, `C:\`, `D:\`
- bitrate, file size, hash, database VirtualDJ;
- token Spotify o credenziali;
- dati di cancellazione, doppioni, scan o qualitÃ  file;
- campi Studio usati solo per manutenzione.

## Regole di export Studio

Lo Studio locale esporta solo brani:

- con root musica configurata (`definitive_music_root`, default `E:\LIBRERIA_MUSICALE`);
- disponibili o selezionati manualmente per la sessione;
- non marcati come esclusi dalla serata;
- con artista e titolo valorizzati;
- con `track_id` stabile.

Ordinamento consigliato:

1. `priority` desc;
2. `artist` asc;
3. `title` asc;
4. `track_id` asc.

## Calcolo `search_text`

`search_text` deve includere contenuti utili alla ricerca pubblica:

- artista;
- titolo;
- artista/titolo normalizzati;
- genere;
- macro genere;
- sottogenere;
- anno;
- tag pubblicabili;
- versione.

Deve essere:

- minuscolo;
- senza path;
- senza doppie spaziature;
- stabile tra export successivi.

## Calcolo `priority`

Valore intero `0-100`.

Formula iniziale consigliata:

- base `50`;
- `+20` se `rating >= 4`;
- `+10` se `play_count > 0`;
- `+10` se contiene tag pubblico forte (`PISTA`, `URLANTE`, `SUCCESSO`, `POPOLARE`);
- `-20` se `risk >= 4`;
- `-10` se `available=false`.

La formula puÃ² cambiare, ma il campo esportato deve restare giÃ  calcolato: la Regia hosting non deve ricalcolarlo usando logiche Studio.

## Uso in Regia hosting

`public-search` in hosting deve:

1. caricare `storage/session/krdesk_session_tracks.json`;
2. rifiutare file con `schema` o `schema_version` non supportati;
3. cercare solo dentro `tracks`;
4. filtrare `public=true`, `requestable=true`, `available=true`;
5. ordinare per match e `priority`;
6. restituire al browser solo:
   - `id`;
   - `artist`;
   - `title`;
   - `genre`;
   - `year`.

Mappatura risposta attuale:

| Risposta `public-search` | Da JSON |
| --- | --- |
| `id` | `track_id` |
| `artist` | `artist` |
| `title` | `title` |
| `genre` | `genre` |
| `year` | `year` |

Implementazione iniziale:

- `api.php?action=public-search` usa `SessionTrackService` quando l'ambiente non usa file locali;
- in locale resta il fallback su `LibraryService`;
- la risposta include `source=session-json` quando arriva dal JSON sessione.

## Salvataggio richieste hosting

Quando arriva `request-create`, hosting deve salvare:

- `track_id`, se selezionato;
- `query` digitata o snapshot `artist - title`;
- snapshot artista/titolo del JSON al momento della richiesta;
- `public_token`;
- `client_token`;
- `client_ip`;
- stato iniziale `new`.

La tabella attuale `requests` salva giÃ  `track_id` e `query`.

Debito tecnico da aggiungere prima del pieno distacco:

- campo `track_artist_snapshot`;
- campo `track_title_snapshot`;
- campo `session_id`;
- campo `session_track_payload` opzionale JSON.

FinchÃ© questi campi non esistono, `query` deve contenere uno snapshot leggibile.

## Import post-serata nello Studio

Dopo la serata:

1. esportare richieste dal database hosting;
2. importarle nello Studio locale;
3. riconciliare usando `track_id`;
4. se `track_id` non esiste piÃ¹, usare snapshot artista/titolo;
5. non usare mai path hosting per modificare file locali;
6. applicare eventuali azioni Automix solo dal PC DJ locale.

## Validazione minima

Un file sessione Ã¨ valido se:

- JSON parse OK;
- `schema = krdesk.session_tracks`;
- `schema_version = 1`;
- `tracks` Ã¨ array;
- ogni track ha `track_id`, `artist`, `title`, `search_text`, `priority`, `available`, `public`, `requestable`;
- nessun valore contiene path Windows completi;
- `stats.tracks` coincide con `tracks.length`.

## Esempio completo

```json
{
  "schema": "krdesk.session_tracks",
  "schema_version": 1,
  "generated_at": "2026-07-13T20:30:00+02:00",
  "source": {
    "app": "KR DJ Desk",
    "environment": "studio-local",
    "database": "vdjdesk",
    "library_root": "E:",
    "export_profile": "public-request-search"
  },
  "session": {
    "id": "kr-2026-07-13-serata",
    "name": "Serata KR",
    "event_date": "2026-07-13",
    "venue": "",
    "notes": ""
  },
  "stats": {
    "tracks": 1,
    "available": 1,
    "unavailable": 0
  },
  "tracks": [
    {
      "track_id": 123,
      "artist": "Daddy Yankee",
      "title": "Gasolina",
      "artist_normalized": "daddy yankee",
      "title_normalized": "gasolina",
      "search_text": "daddy yankee gasolina reggaeton urban latino pista urlante",
      "genre": "Reggaeton",
      "macro_genre": "Urban Latino",
      "subgenre": "Reggaeton",
      "year": 2004,
      "bpm": 96.0,
      "musical_key": "",
      "camelot": "",
      "tags": ["PISTA", "URLANTE"],
      "version": "",
      "priority": 90,
      "available": true,
      "public": true,
      "requestable": true,
      "session": {
        "id": "kr-2026-07-13-serata",
        "bucket": "main",
        "note": ""
      }
    }
  ]
}
```

## Prossimo passo tecnico

Implementato in modo minimo:

1. `src/SessionTrackService.php` genera, valida e legge il JSON sessione;
2. `tools/export-session-tracks.php` genera `storage/exports/krdesk_session_tracks.json` da CLI;
3. `api.php?action=session-tracks-export` genera il file solo in Studio locale;
4. `api.php?action=public-search` in hosting legge `storage/session/krdesk_session_tracks.json`;
5. in locale, `public-search` continua a usare `LibraryService`.

Comando export consigliato:

```powershell
php tools/export-session-tracks.php --id="kr-2026-07-13-serata" --name="Serata KR" --date="2026-07-13"
```

Da fare dopo:

1. aggiungere una UI Studio per l'export;
2. configurare `session_tracks_upload` in `config.php` per usare il pulsante `Genera + carica hosting`;
3. aggiungere campi snapshot richiesta nel database hosting;
4. separare fisicamente API Regia e Studio nella PrioritÃ  3.
