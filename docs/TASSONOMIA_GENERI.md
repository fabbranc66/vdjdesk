# Tassonomia generi KR Desk

Data: 2026-07-14.

## Regola

La tassonomia principale deriva da due root fisiche distinte:

- `E:\LIBRERIA_MUSICALE`: solo musica definitiva.
- `E:\LIBRERIA_TECNICA`: aree operative, inbox, cancellazioni, sostituzioni, playlist.

Esempio:

```text
E:\LIBRERIA_MUSICALE\10_Latin\Reggaeton\Brano.mp3
```

diventa:

| Campo | Valore | Origine |
| --- | --- | --- |
| `archive_area` | `LIBRERIA` | musica definitiva |
| `macro_genre` | `Latin` | cartella primo livello |
| `folder_genre` | `Reggaeton` | sottocartella |
| `genre` | valore taggato nel brano | microgenere |

## Mappa macro

| Root/cartella | `archive_area` | `macro_genre` |
| --- | --- | --- |
| `E:\LIBRERIA_MUSICALE\10_Latin` | `LIBRERIA` | `Latin` |
| `E:\LIBRERIA_MUSICALE\20_Urban` | `LIBRERIA` | `Urban` |
| `E:\LIBRERIA_MUSICALE\30_Commerciale` | `LIBRERIA` | `Commerciale` |
| `E:\LIBRERIA_MUSICALE\40_Rock_PopRock` | `LIBRERIA` | `Rock_PopRock` |
| `E:\LIBRERIA_MUSICALE\50_Italiana` | `LIBRERIA` | `Italiana` |
| `E:\LIBRERIA_TECNICA\01_INBOX` | `01_INBOX` | vuoto |
| `E:\LIBRERIA_TECNICA\02_DJ_TOOLS` | `02_DJ_TOOLS` | vuoto |
| `E:\LIBRERIA_TECNICA\80_Karaoke` | `80_KARAOKE` | vuoto |
| `E:\LIBRERIA_TECNICA\90_Tematiche` | `90_TEMATICHE` | vuoto |
| `E:\LIBRERIA_TECNICA\PLAYLIST` | `PLAYLIST` | vuoto |

## Uso previsto

- `macro_genre` e `folder_genre` guidano filtri, playlist e suggerimenti solo sulla musica definitiva.
- `genre` resta il microgenere/tag originale e non viene sovrascritto.
- Le aree tecniche non sono generi musicali e non entrano nei dropdown playlist.
- I campi vengono ricalcolati dal path durante import, scan, relink e sostituzioni.
