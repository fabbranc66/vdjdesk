# Doppioni Spotify

Data definizione: 2026-07-13.

## Obiettivo

Usare `spotify_id` e `isrc` per individuare doppioni nella libreria definitiva `E:\LIBRERIA_DEFINITIVA`.

Il report base e diagnostico:

- non cancella file;
- non sposta file;
- propone solo un brano consigliato da tenere.

La marcatura avviene solo quando premi il pulsante dedicato.

## Tipi gruppo

| Tipo | Confidenza | Significato |
| --- | --- | --- |
| `same_spotify_id` | 100 | Piu file VDJ hanno stesso Spotify ID, artista e titolo |

## Regola consigliato da tenere

Il brano consigliato viene scelto ordinando per:

1. file esistente;
2. bitrate piu alto;
3. rating piu alto;
4. play count piu alto;
5. dimensione file piu alta;
6. metriche Spotify complete.

La scelta e una proposta, non una cancellazione.

## Endpoint

Solo Studio locale:

- `api.php?action=spotify-duplicates`
- `api.php?action=spotify-duplicates-report`
- `api.php?action=spotify-duplicates-mark`

`spotify-duplicates` restituisce:

- `summary.groups`;
- `summary.shown`;
- conteggi per tipo gruppo;
- lista gruppi con `recommended_id`.

`spotify-duplicates-report` genera un CSV in:

- `storage/reports/spotify_duplicates_YYYYMMDD_HHMMSS.csv`

`spotify-duplicates-mark` inserisce in `deletion_candidates` i brani non consigliati dei soli gruppi ad alta confidenza:

- `status = marked`;
- `source_path` = file candidato;
- `e_file_path` = file consigliato da tenere;
- mantiene invariati eventuali candidati gia `approved`, `moved` o `deleted`.
- salta i gruppi con confidenza inferiore a 95.

## UI

Nella vista `Qualita / Doppioni`:

- `Doppioni Spotify` mostra i gruppi in pagina;
- `CSV Spotify` genera il report;
- `Marca non consigliati Spotify` marca i non consigliati come gli altri doppioni;
- `Approva tutti` approva i candidati marcati;
- `Archivio approvati` mostra i candidati approvati;
- `Cerca e sposta tutto` usa il flusso esistente e sposta i file approvati in `E:\LIBRERIA_DEFINITIVA\01_INBOX\Da_cancellare`.

## Criteri di sicurezza

`same_spotify_id` viene considerato doppione marcabile solo quando coincidono Spotify ID, artista normalizzato e titolo normalizzato.

La revisione Spotify non separa audio e video: se VDJ importa entrambi e Spotify ID, artista e titolo coincidono, il gruppo viene mostrato.

I record non presenti su disco (`file_exists=0`) o non collegati a un database VirtualDJ importato non entrano nei gruppi attivi.

`same_artist_title_different_spotify` va trattato come revisione manuale: puo indicare remix, edit, explicit/clean, live, remaster o versione alternativa.

Il flusso non elimina file: li marca, li fa approvare e poi li sposta nella cartella di cancellazione controllata.
