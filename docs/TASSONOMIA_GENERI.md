# Tassonomia generi KR Desk

Data: 2026-07-14.

## Regola

La tassonomia principale deriva dal percorso fisico in `E:\LIBRERIA_DEFINITIVA`.

Esempio:

```text
E:\LIBRERIA_DEFINITIVA\10_Latin\Reggaeton\Brano.mp3
```

diventa:

| Campo | Valore | Origine |
| --- | --- | --- |
| `archive_area` | `LIBRERIA` | area logica |
| `macro_genre` | `Latin` | cartella primo livello |
| `folder_genre` | `Reggaeton` | sottocartella |
| `genre` | valore taggato nel brano | microgenere |

## Mappa macro

| Cartella | `archive_area` | `macro_genre` |
| --- | --- | --- |
| `01_INBOX` | `INBOX` | vuoto |
| `02_DJ_TOOLS` | `DJ_TOOLS` | vuoto |
| `10_Latin` | `LIBRERIA` | `Latin` |
| `20_Urban` | `LIBRERIA` | `Urban` |
| `30_Commerciale` | `LIBRERIA` | `Commerciale` |
| `40_Rock_PopRock` | `LIBRERIA` | `Rock_PopRock` |
| `50_Italiana` | `LIBRERIA` | `Italiana` |
| `80_Karaoke` | `KARAOKE` | `Karaoke` |
| `90_Tematiche` | `TEMATICHE` | `Tematiche` |
| `PLAYLIST` | `PLAYLIST` | vuoto |

## Uso previsto

- `macro_genre` e `folder_genre` guidano filtri, playlist e suggerimenti.
- `genre` resta il microgenere/tag originale e non viene sovrascritto.
- `INBOX`, `DJ_TOOLS` e `PLAYLIST` non sono generi musicali.
- I campi vengono ricalcolati dal path durante import, scan, relink e sostituzioni.
