# Inbox Stati

Data definizione: 2026-07-13.

## Obiettivo

Fare della Inbox il punto di ingresso governato dei brani nuovi.

Regola base: la Inbox non sposta, non cancella e non archivia automaticamente. Produce solo stato, colonne di controllo e azione suggerita.

## Cartelle Inbox esistenti

Cartella radice operativa:

- `E:\LIBRERIA_TECNICA\01_INBOX`

Cartelle giÃ  emerse nel codice:

- `E:\LIBRERIA_TECNICA\01_INBOX\Da_cancellare`
- `E:\LIBRERIA_TECNICA\01_INBOX\Sostituzioni`

Uso:

- `Da_cancellare`: area di parcheggio per candidati giÃ  approvati o marcati per cancellazione, senza cancellazione fisica automatica;
- `Sostituzioni`: backup/archivio temporaneo quando un audio viene sostituito;
- radice Inbox e sottocartelle future: ingresso dei brani da catalogare.

## Stati tecnici

| Stato | Significato | Uscita consentita |
| --- | --- | --- |
| `DA_CATALOGARE` | Brano in Inbox con metadati minimi mancanti | Completare artista/titolo/genere/Spotify |
| `CATALOGATO_DA_CONFERMARE` | Metadati presenti ma non confermati dal DJ | Revisione rapida |
| `CATALOGATO_CONFERMATO` | Metadati e classificazione confermati | PuÃ² diventare pronto archiviazione |
| `DA_ASCOLTARE` | Serve ascolto umano prima di decidere | Playlist/M3U di controllo |
| `DUBBIO` | Dati incompleti o incoerenti ma non critici | Revisione mirata |
| `CONFLITTO` | Match o metadati in conflitto/errore | Risoluzione manuale obbligatoria |
| `DA_RICLASSIFICARE` | Genere/tag/punteggi non coerenti | Riclassificare senza spostare |
| `DA_CANCELLARE` | Brano parcheggiato come candidato cancellazione | Solo conferma finale esterna |
| `PRONTO_ARCHIVIAZIONE` | Completo e coerente, pronto per proposta spostamento | Proposta spostamento in PrioritÃ  7 |
| `ARCHIVIATO` | Brano fuori Inbox nella libreria musicale | Nessuna azione Inbox |
| `SCARTATO` | Brano escluso dal flusso operativo | Nessuna automazione distruttiva |

## Colonne obbligatorie

| Colonna | Tipo | Calcolo iniziale |
| --- | --- | --- |
| `completezza_metriche` | 0-100 | Spotify ID, metriche, BPM, chiave, genere, anno |
| `confidenza_match` | 0-100 | Spotify ID, metadata source, artista/titolo normalizzati |
| `fonte_genere` | string | `manuale`, `sortlee`, `spotify`, `virtualdj`, `mancante` |
| `coerenza_classificazione` | 0-100 | tag/punteggi/metriche disponibili e non in conflitto |
| `azione_suggerita` | string | prossima azione non distruttiva |

## Regole iniziali stato

Ordine di precedenza:

1. Se il path contiene `\01_INBOX\Da_cancellare\` â†’ `DA_CANCELLARE`.
2. Se il path contiene `\01_INBOX\Sostituzioni\` â†’ `DA_ASCOLTARE`.
3. Se il brano non Ã¨ nella Inbox ma Ã¨ in `E:\LIBRERIA_MUSICALE\` â†’ `ARCHIVIATO`.
4. Se artista o titolo mancano â†’ `DA_CATALOGARE`.
5. Se Spotify ha errore o match molto basso â†’ `CONFLITTO`.
6. Se genere manca o classificazione bassa â†’ `DA_RICLASSIFICARE`.
7. Se metriche incomplete â†’ `DUBBIO`.
8. Se metadati completi ma non confermati dal DJ â†’ `CATALOGATO_DA_CONFERMARE`.
9. Se completo e confermato â†’ `PRONTO_ARCHIVIAZIONE`.

## Azioni suggerite

| Stato | Azione suggerita |
| --- | --- |
| `DA_CATALOGARE` | Compila metadati base e cerca Spotify ID |
| `CATALOGATO_DA_CONFERMARE` | Conferma genere/tag/punteggi |
| `CATALOGATO_CONFERMATO` | Valuta proposta archiviazione |
| `DA_ASCOLTARE` | Aggiungi a lista ascolto |
| `DUBBIO` | Completa metriche mancanti |
| `CONFLITTO` | Risolvi match o errore manualmente |
| `DA_RICLASSIFICARE` | Correggi genere/tag/punteggi |
| `DA_CANCELLARE` | Verifica prima della cancellazione finale |
| `PRONTO_ARCHIVIAZIONE` | Proponi destinazione, senza spostare |
| `ARCHIVIATO` | Nessuna azione |
| `SCARTATO` | Nessuna azione automatica |

## Implementazione iniziale

Endpoint Studio locale:

- `api.php?action=inbox-status`

Risposta:

- `summary`: conteggi per stato;
- `items`: primi brani con stato tecnico e colonne obbligatorie;
- `root`: cartella Inbox standard.

Vincoli:

- solo Studio locale;
- vietato in hosting;
- nessuno spostamento file;
- nessuna cancellazione;
- nessun aggiornamento DB.

## Criterio di chiusura PrioritÃ  4

La prioritÃ  Ã¨ chiusa quando:

- gli stati sono documentati;
- la cartella Inbox esistente Ã¨ mappata;
- esiste un endpoint non distruttivo per leggere stato e colonne;
- `inbox-status` Ã¨ classificato come Studio locale;
- nessuna funzione Inbox sposta o cancella file automaticamente.
