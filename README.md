# KR DJ Desk

Tool locale PHP 8 + SQLite per consultare e classificare la libreria VirtualDJ durante una serata.

## Avvio

Con XAMPP attivo, aprire `http://localhost/vdjdesk/`. La pagina pubblica per le richieste Ã¨ `http://IP-DEL-PC/vdjdesk/request.php` e funziona sui dispositivi collegati alla stessa rete locale.

Il database dell'app usa MariaDB di XAMPP (`vdjdesk`). Il database XML di VirtualDJ viene letto soltanto e non viene mai modificato.

## MVP incluso

- Dashboard live, cronologia e azioni rapide.
- Ricerca per metadati, percorso, BPM, key, anno e tag.
- Importazione XML VirtualDJ, M3U e scansione cartella musica.
- Sincronizzazione incrementale del database AppData e di tutti i database `X:\VirtualDJ\database.xml`, attivata solo quando data o dimensione del file cambia.
- Normalizzazione artista/titolo e revisione doppioni.
- Suggerimenti con BPM, Camelot, genere, energia e recenti.
- Tag DJ e punteggi da 1 a 5.
- Richieste pubblico con pagina smartphone e coda DJ.

## Roadmap

- Gestione doppioni: inviare a VirtualDJ il comando di cancellazione tramite Network Control, con conferma esplicita e registrazione dell'operazione. KR DJ Desk non deve cancellare direttamente il file dal filesystem quando Ã¨ disponibile il controllo VirtualDJ.
- Obiettivo libreria musicale: consolidare tutti i brani unici nel drive `E:`. Le azioni di cancellazione durante il trasferimento devono riguardare soltanto copie presenti su drive diversi da `E:`. Il drive `E:` deve avere un controllo doppioni dedicato interno; nessun brano su `E:` va cancellato automaticamente e ogni gruppo deve conservare una sola copia valida dopo revisione.
- Flusso candidati cancellazione: per ogni brano presente fuori da `E:`, verificare prima l'esistenza di una copia corrispondente su `E:`. Se esiste, non cancellare subito: registrare il file esterno in un archivio persistente di KR DJ Desk con stato `marcato per cancellazione`, motivazione, corrispondenza su `E:`, data e successiva decisione operativa.
