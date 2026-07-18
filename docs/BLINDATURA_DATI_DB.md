# Blindatura dati DB

Data: 2026-07-15.

## Regola

La verità operativa è il file fisico presente in `E:\LIBRERIA_MUSICALE`.

Ogni operazione su path, cartelle, sync VirtualDJ o pulizia deve conservare i dati collegati al brano:

- Spotify ID e URL.
- Spotify audio features.
- KR score.
- Genere, anno, tag automatici.
- Metadata source e date di aggiornamento.

## Protezioni

- Prima delle operazioni rischiose viene creato uno snapshot `tracks_guard_*`.
- I record arricchiti non vengono cancellati se il file fisico non è trovato: vengono marcati `file_exists=0`.
- Prima della pulizia, i dati dei record non fisici compatibili vengono copiati sui record fisici presenti.
- Se dopo sync/prune calano Spotify ID, audio features, KR score o genre/year, l'operazione viene bloccata.

## Endpoint locali

- `data-snapshot`: crea snapshot manuale.
- `path-prefix-rename`: aggiorna una root path portandosi dietro tassonomia e snapshot.
- `physical-data-status`: mostra copertura dati sui file fisici.
- `physical-data-complete`: completa i dati dei file fisici da record serbatoio compatibili.
