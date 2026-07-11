# KR DJ Desk — Regole Definitive Libreria DJ v1

## Principio

Spotify è la fonte primaria per identità musicale, metadati, popolarità, release date, generi e metriche quando disponibili.
La libreria locale decide solo se il file fisico esiste.

KR Desk non deve inventare dati Spotify mancanti: ogni valore non reale deve essere salvato come derivato, con fonte e confidenza.

## Scheda minima brano

Ogni brano deve poter arrivare a questi campi:

- `artist`
- `title`
- `spotify_track_id`
- `isrc`
- `release_date`
- `popularity`
- `bpm`
- `key`
- `mode`
- `camelot`
- `spotify_genres`
- `macro_area`
- `main_genre`
- `subgenre`
- `era`
- `dj_energy`
- `mood`
- `dj_function`
- `danceability_class`
- `tags`
- `path`
- `found`
- `match_confidence`
- `notes`

## Macro-aree consentite

- `Latin`
- `Urban`
- `Commerciale`
- `Rock / Pop Rock`
- `Italiana`
- `Karaoke`
- `Tematiche / Eventi`
- `Da classificare`

## Generi principali consentiti

- `Reggaeton`
- `Dembow`
- `Bachata`
- `Salsa`
- `Merengue`
- `Cubaton`
- `Timba`
- `Latin Pop`
- `Latin Urban`
- `Hip Hop`
- `R&B`
- `Urban Pop`
- `Rap Italiano`
- `Pop`
- `Dance Pop`
- `EDM Commerciale`
- `House`
- `Rock / Alternative`
- `Pop Rock`
- `Ballad`
- `Acoustic`
- `Karaoke`
- `Da classificare`

## Regole operative

1. Ogni brano viene normalizzato come `Artista - Titolo`.
2. Il match locale forte richiede `artista + titolo`.
3. Il solo titolo genera dubbio, non certezza.
4. `Latin` è macro-area, non genere operativo.
5. Decide il brano, non l'artista: lo stesso artista può finire in generi diversi.
6. I generi Spotify vanno mappati in categorie DJ stabili.
7. Se un genere Spotify non è mappato, il brano resta `Da classificare`.
8. Le metriche Spotify reali restano separate dalle metriche stimate.
9. Le playlist sono per uso reale in serata, non solo per cartella.
10. Non si cambiano categorie o formule in corsa senza nuova versione.

## Popularity

- `0-30`: nicchia / poco riconosciuto
- `31-50`: medio
- `51-70`: conosciuto
- `71-85`: hit forte
- `86-100`: super hit

Campi derivabili:

- `popularity_class`
- `public_request_probability`
- `singalong_strength`
- `playlist_priority`

## Epoca

- `1990-1999`: `90s`
- `2000-2003`: `Early 2000`
- `2004-2006`: `Mid 2000`
- `2007-2009`: `Late 2000`
- `2010-2015`: `2010s`
- `2016-2019`: `Late 2010s`
- `2020+`: `Current / 2020s`

Se Spotify segnala una riedizione o remaster recente, separare:

- `original_era`
- `spotify_release`
- `notes`

## BPM class

- sotto `80`: `Slow`
- `80-100`: `Groove`
- `100-115`: `Mid`
- `115-128`: `Danceable`
- `128-135`: `Club`
- `135+`: `Fast`

Hip hop, trap, reggaeton e dembow possono essere half-time o double-time: segnalare il dubbio, non correggere automaticamente.

## Key / mode / Camelot

- `mode = 0`: minore
- `mode = 1`: maggiore
- Camelot `A`: minore
- Camelot `B`: maggiore

La chiave Camelot completa `numero + A/B` è il dato armonico finale.

## Energia DJ

Valori consentiti:

- `Warmup`
- `Groove`
- `Club`
- `Peak`
- `Sing Along`
- `Break`
- `Outro / Chiusura`
- `Karaoke`
- `Trash / Fun`
- `Da valutare`

## Funzione DJ

Valori consentiti:

- `Warmup`
- `Ballabile`
- `Peak Time`
- `Sing Along`
- `Karaoke`
- `Richiesta pubblico`
- `Cambio genere`
- `Ponte tra generi`
- `Pausa / Break`
- `Finale serata`
- `Quiz Music`
- `Tema evento`
- `Da valutare`

## Metriche derivate

Ogni metrica stimata deve avere campi separati:

- valore Spotify reale, se disponibile
- valore stimato
- fonte
- confidenza

Esempio:

- `danceability_spotify = null`
- `danceability_estimated = 82`
- `danceability_source = derived`
- `danceability_confidence = medium`

## Ballabilità stimata

Formula v1:

- `BPMScore`: 35%
- `GenreScore`: 35%
- `EnergyScore`: 15%
- `PopularityScore`: 10%
- `StructureBonus`: 5%

Se una metrica manca, il peso viene redistribuito sui dati disponibili.

## Output obbligatori degli script

Ogni procedura massiva deve poter produrre:

1. Playlist M3U dei brani trovati
2. CSV completo con metadati
3. Report mancanti
4. Report dubbi
5. Report duplicati
6. Report match Spotify incerti
7. Report brani senza dati Spotify
8. Report metriche Spotify mancanti
9. Report metriche derivate
10. Report metriche con confidenza bassa
11. Report generi Spotify non mappati
12. Report brani esclusi da playlist DJ normale

