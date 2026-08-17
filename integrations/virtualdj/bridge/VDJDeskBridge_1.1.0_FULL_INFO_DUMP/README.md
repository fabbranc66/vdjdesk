# VDJDesk Bridge 0.2.24 INTEGRATED

Tutto risiede nella cartella plugin VirtualDJ.

Installazione:
- DLL:
  `%LOCALAPPDATA%\VirtualDJ\Plugins64\SoundEffect\VDJDeskBridgeManual.dll`
- Motore analisi:
  `%LOCALAPPDATA%\VirtualDJ\Plugins64\SoundEffect\VDJDeskBridge\Analyzer\VDJDesk_AutoVocalCue.py`

## Funzionamento

PLUGIN OFF
- watcher bridge fermo
- motore analisi fermo

PLUGIN ON
- parte il watcher bridge
- parte automaticamente il motore analisi Python

CARICHI/CAMBI TRACCIA
- bridge aggiorna VDJDeskBridgeState.json
- analizzatore legge filepath/BPM
- cerca `.vdjstems`
- identifica stream `vocal`
- analizza i blocchi vocali
- pause inferiori a 8 beat vengono unite
- se analisi riuscita cancella cue 1-8 del deck interessato
- crea fino a 8 cue INIZIO PARLATO / FINE PARLATO

PLUGIN OFF
- il motore analisi viene terminato
- il bridge si ferma

## Importante

L'analisi NON gira dentro la DLL: e' un processo Python esterno,
ma i suoi file sono installati dentro la cartella del plugin e
viene avviato/fermato automaticamente dalla DLL.

Questo mantiene VirtualDJ piu stabile rispetto a inserire FFmpeg
e analisi audio direttamente nel processo VDJ.


## Fix 0.2.24

Corretto il recupero della directory della DLL.
La versione precedente usava impropriamente `GetModuleHandleExA` con un
puntatore a metodo membro C++. Ora viene usato direttamente `hInstance`
fornito dal VirtualDJ Plugin SDK:

`GetModuleFileNameA(hInstance, path, MAX_PATH)`

Nessun'altra logica del bridge o dell'analizzatore e' stata modificata.


## Fix ricerca stem 0.2.24

VirtualDJ salva normalmente i prepared stems accanto al file sorgente,
ma l'opzione `stemsSavedFolder` puo spostarli in una cartella dedicata.

L'analizzatore ora:
1. prova a leggere `stemsSavedFolder` tramite Network Control;
2. se non riesce, cerca l'opzione nei settings VirtualDJ;
3. cerca il `.vdjstems` nella cartella sorgente;
4. cerca nella cartella dedicata configurata;
5. usa cartelle VirtualDJ comuni come fallback;
6. scrive nel log tutti i candidati verificati.

Il log `VDJDesk_AutoVocalCue.log` permette quindi di vedere esattamente
dove sta cercando.


## Voice Confidence 0.2.24

Il bridge, la ricerca stem, l'avvio automatico e la regola delle pause
da 8 beat NON sono stati modificati.

E' stato raffinato solo il detector vocal.

Ogni frame candidato viene valutato con quattro segnali:
- energia nello stem `vocal`;
- dominanza del `vocal` rispetto a `instruments`;
- modulazione temporale dell'inviluppo (sillabe/frasi);
- spectral flux, cioe' variazione dello spettro nel tempo.

Il risultato e' un `voice score` 0..1. La soglia iniziale e' 0.42,
volutamente prudente per non perdere rap, canto, autotune e ad-lib.

Nel log vengono aggiunte righe:
- Voice confidence: mean / p50 / p75 / soglia
- Voice features: dominance / modulation / flux

Non sono state aggiunte nuove dipendenze Python.


## Fix dipendenze 0.2.24

Corretto `INSTALLA_DIPENDENZE_ANALISI.bat`.

Prima usava:
`pip install --upgrade numpy`

Questo poteva installare NumPy 2.5.x e creare conflitto con Numba 0.66.

Ora installa:
`numpy>=1.22,<2.5`

E verifica subito:
- versione NumPy;
- versione Numba;
- presenza FFmpeg;
- presenza FFprobe.

Incluso anche:
`RIPARA_NUMPY_COMPATIBILE.bat`

Da usare una volta se sul sistema e' gia' stato installato NumPy 2.5.x.


## Detector 0.2.24 - VOCAL ENVELOPE

La 0.2.14 VOICE FILTER STRONG e' stata abbandonata.

Questa versione torna a seguire direttamente quello che si sente nello
stream `vocal`.

Non usa:
- instruments;
- correlazione cross-stem;
- voice score;
- spectral flux;
- classificatori euristici.

Usa:
1. RMS dello stream vocal;
2. rumore adattivo = percentile 20;
3. livello vocale = percentile 80;
4. soglia ON/OFF con isteresi;
5. rimozione di spike brevissimi;
6. ricucitura di micro-gap;
7. regola finale invariata: silenzio vero solo se >= 8 beat.

La finestra audio e' stata ridotta per evitare di spostare artificialmente
inizio/fine dei cue.

Nel log compare:
`Vocal envelope: noise=... | signal=... | ON=... | OFF=...`


## 0.2.24 - TRUE SILENCE

Correzione concettuale:

PRIMA:
- il detector trovava i silenzi reali;
- poi la regola 8 beat riempiva fisicamente i silenzi piu' corti;
- quindi un vero buco energetico poteva risultare classificato come voce.

ORA:
- la maschera voce/silenzio resta reale;
- i silenzi reali non vengono piu' cancellati dalla regola 8 beat;
- gli 8 beat servono SOLO a decidere se creare una nuova macro-zona cue.

Esempio:
voce -> 1.3s silenzio reale -> voce

La maschera conserva 1.3s di SILENZIO.
Ma, se 1.3s < 8 beat, non vengono creati:
FINE PARLATO / INIZIO PARLATO.

Nel log vengono mostrati i silenzi reali >= 0.75 s con:
- start
- end
- durata
- SPLIT / NO SPLIT


## 0.2.24 - FIX

Corretto un riferimento residuo a `VOICE_SCORE_THRESHOLD` rimasto dalla
vecchia logica voice-confidence.

Nessuna modifica al detector 0.2.16:
- maschera reale voce/silenzio invariata;
- 8 beat usati solo per decidere lo split dei cue;
- log dei silenzi reali invariato.


## 0.2.24 - VOCAL 30 PERCENT

Nuova logica voce/silenzio:

- si usa SOLO lo stream `vocal`;
- si calcola RMS su finestre brevi;
- il massimo RMS smussato dello stem = 100%;
- livello >= 30% = VOCE;
- livello < 30% = SILENZIO.

Gli 8 beat NON modificano la maschera reale.
Servono soltanto a decidere se un silenzio genera uno split dei cue.

Il log mostra per ogni silenzio reale >= 0.75 s:
- inizio;
- fine;
- durata;
- livello minimo %;
- livello medio %;
- livello massimo %;
- SPLIT / NO SPLIT.


## 0.2.24 - NO 8 BEATS

Eliminata completamente la regola degli 8 beat.

Ora:
- massimo RMS vocal smussato = 100%;
- >= 30% = VOCE;
- < 30% = SILENZIO;
- ogni passaggio VOCE -> SILENZIO chiude una sezione;
- ogni passaggio SILENZIO -> VOCE apre una nuova sezione;
- i cue seguono direttamente le sezioni vocali reali.

Restano solo:
- rimozione spike vocali molto brevi;
- ricucitura micro-gap tecnici <= 0.35 s.


## 0.2.24 - THREE LEVEL VOCAL

Classificazione diretta dello stem vocal rispetto al suo massimo RMS smussato:

- 0% - 25%     = SILENZIO
- >25% - 50%   = EFFETTI/CORI
- >50%         = CANTATO

I cue INIZIO/FINE PARLATO vengono creati SOLO per le sezioni CANTATO.

Nessuna regola degli 8 beat.

Restano solo:
- eliminazione spike vocali molto brevi;
- micro-gap tecnico <= 0.35 s.

Il log mostra i segmenti delle tre classi e le percentuali min/medie/max.


## 0.2.24 - NO 8 BEATS FIX

Corretto riferimento residuo a `MIN_SILENCE_BEATS` eseguito subito dopo
la lettura del BPM.

Verifica effettuata:
- zero occorrenze di `MIN_SILENCE_BEATS` nel Python;
- compilazione Python OK;
- classificazione invariata:
  - 0-25% SILENZIO
  - >25-50% EFFETTI/CORI
  - >50% CANTATO


## 0.2.24 - THRESHOLD FIX

Corretto riferimento residuo a `threshold_db` che bloccava l'analisi
dopo la classificazione e prima della scrittura dei cue.

Classificazione invariata:
- 0-25% SILENZIO
- >25-50% EFFETTI/CORI
- >50% CANTATO

Nessuna regola 8 beat.


## 0.2.24 - DROP PROTOTYPE

PARTE VOCAL:
INVARIATA rispetto alla 0.2.22.

DROP:
analisi separata, per ora SOLO log + JSON.
Non modifica e non crea cue.

Segnali:
- stream kick obbligatorio;
- stream bass di conferma;
- stream instruments di conferma.

Ricerca:
1. onset del kick;
2. intervalli convertiti in beat;
3. progressioni tipo:
   1 -> 0.5 -> 0.25 -> 0.125 beat
   (sono accettate anche progressioni parziali);
4. entro 2 beat dalla fine del roll cerca un ingresso forte;
5. conferma salto energetico di kick + bass + instruments;
6. assegna score;
7. deduplica candidati entro 8 beat.

Nel log:
- `ROLL n: ... beat levels: ...`
- `DROP n: ... score=...`

IMPORTANTE:
nessun drop viene ancora scritto nei cue.


## 0.2.24 - FINE PARLATO + DROP

Parte vocal:
- classificazione invariata:
  - 0-25% SILENZIO
  - >25-50% EFFETTI/CORI
  - >50% CANTATO
- eliminati i cue `INIZIO PARLATO`;
- rimangono solo i cue `FINE PARLATO`.

Drop:
- il detector 0.2.23 resta invariato;
- il punto DROP e' il primo beat forte dopo il roll/build-up;
- i DROP vengono ora aggiunti ai cue.

Cue finali:
- `FINE PARLATO n` = rosso
- `DROP n` = giallo
- ordinati cronologicamente
- massimo 8 cue totali

Il JSON mantiene:
- `vocalEndBoundaries`: soli fine-parlato
- `dropAnalysis`: analisi drop completa
- `boundaries`: lista combinata realmente usata per i cue


# VDJDeskBridge 0.3.0 - MIR / ML

## Invariato
- plugin VirtualDJ ON/OFF;
- ricerca automatica `.vdjstems`;
- DLL leggera;
- cue 1-8;
- `FINE PARLATO` rosso;
- `DROP` giallo;
- ordinamento cronologico.

## Vocal
Il vecchio criterio percentuale non decide piu' la voce.

Motore:
- stem `vocal`;
- PyTorch + Transformers;
- AST AudioSet;
- classi semantiche singing/speech/rapping/choir/vocal music;
- librosa solo per rifinire temporalmente la fine del vocal.

Output:
- solo `FINE PARLATO`.

## Drop
Motore:
- librosa onset strength;
- onset detection sul kick;
- quantizzazione degli intervalli in 1 / 0.5 / 0.25 / 0.125 beat;
- novelty su kick+bass+instruments;
- confronto energia prima/dopo;
- rilevazione anche dopo break senza roll classico.

Output:
- `DROP`.

## Dipendenze
Eseguire una volta:
`INSTALLA_DIPENDENZE_ANALISI.bat`

Installa:
- numpy < 2.5
- scipy
- librosa
- soundfile
- torch
- transformers

e scarica il modello:
`MIT/ast-finetuned-audioset-10-10-0.4593`


## 0.3.1 - RESAMPY FIX

Corretto setup dipendenze:
- aggiunto `resampy`, richiesto da librosa con `res_type="kaiser_fast"`;
- aggiunto controllo `resampy` all'avvio;
- incluso `RIPARA_RESAMPY.bat` per sistemare direttamente una 0.3.0 già installata.

Nessuna modifica agli algoritmi:
- Vocal AST AudioSet invariato;
- Drop librosa MIR invariato;
- Cue invariati.


## 0.3.2 - BATCHED ML

Problema 0.3.1:
le finestre AST venivano elaborate una alla volta. Con una traccia
di circa 172 secondi erano circa 340 inferenze separate su CPU e i cue
arrivavano solo alla fine.

Correzione:
- inferenza AST a batch da 16 finestre;
- stesso modello;
- stesse soglie;
- stesso algoritmo vocal;
- stesso algoritmo drop;
- log di avanzamento per ogni batch.

Esempio:
`VOCAL ML progresso: 5/22 batch | 80/342 finestre`

I cue continuano a essere scritti solo dopo il completamento dell'analisi,
ma il tempo di elaborazione viene ridotto drasticamente.


# 0.4.0 - DROP + BREAK

La parte vocal/parlato e' completamente disattivata.

Cue disponibili:
- `DROP n` giallo
- `BREAK n` blu

DROP:
- onset/roll sul kick con librosa;
- novelty e salto energetico su kick+bass+instruments;
- rilevazione anche dopo break senza roll classico.

BREAK:
- cerca il passaggio da zona energica a zona con kick/mix bassi;
- il calo deve durare almeno 4 beat;
- richiede un calo netto rispetto ai 4 beat precedenti;
- deduplica eventi troppo vicini.

Nessun modello AST, PyTorch o Transformers viene caricato.


## 0.4.1 - STEM SEARCH FIX

Corretto errore:
`name 'find_vdjstems' is not defined`

La funzione corretta già presente nel progetto è:
`find_stem(audio_path)`

Non è stato aggiunto o modificato altro.

Restano attivi solo:
- DROP
- BREAK

Nessun codice vocal/AST.


## 0.4.2 - RUNTIME FIX

Corretti insieme i residui della rimozione VOCAL:

- inizializzazione `CACHE = load_cache()`;
- `save_cache(CACHE)` con parametro corretto;
- eliminata doppia ricerca dello stesso `.vdjstems`;
- `analyze_or_cache()` riceve direttamente lo stem gia' trovato;
- log `ERRORE ANALISI VOCAL` -> `ERRORE ANALISI MIR`;
- log vocali residui rimossi.

Nessuna modifica agli algoritmi DROP/BREAK.


## 0.4.3 - CLEAR CUES FIX

Corretto errore:
`name 'clear_cues' is not defined`

Ripristinata dalla 0.3.2 solo la funzione comune `clear_cues()`,
necessaria a pulire i cue 1-8 prima della riscrittura.

Nessuna modifica a:
- algoritmo DROP;
- algoritmo BREAK;
- ricerca stem;
- cache;
- ordine dei cue.


## 0.5.0 - STRUCTURAL DROP / BREAK

Correzione del caso:
- molti falsi DROP;
- nessun BREAK.

### BREAK
Rilevamento relativo alla traccia:
- confronta 4 beat precedenti con 4 beat successivi;
- cerca un calo netto e sostenuto del mix;
- kick o bass devono ridursi in modo coerente;
- non usa piu' una soglia assoluta troppo rigida.

Il cue BREAK viene posizionato all'inizio del calo strutturale.

### DROP
Molto piu' conservativo:
- primo forte ritorno energetico alla fine di un BREAK;
- oppure impatto forte entro 2 beat da un roll/build-up verificato.

Eliminati i DROP generici derivati da semplice novelty/cambio strutturale,
che producevano troppi falsi positivi.

### Audio features
Kick, bass e instruments sono analizzati separatamente.
Le energie vengono combinate dopo il calcolo RMS, evitando cancellazioni
di fase dovute alla somma diretta delle waveform.


## 0.5.1 - PERCUSSIVE DROP

Correzione per DROP non esuberanti.

Il DROP non richiede piu' necessariamente un forte aumento del volume.

Nuovi segnali:
- densita' onset del kick;
- densita' onset della componente percussiva di instruments;
- HPSS per isolare la componente percussiva;
- ritorno ritmico dopo BREAK;
- incremento ritmico dopo roll/build-up.

Il volume/energia rimane un segnale, ma non e' piu' obbligatorio.

Un DROP puo' essere confermato da:
1. ritorno energetico;
2. ritorno/densificazione del kick;
3. aumento della densita' percussiva.

Log aggiuntivo:
- kickDen pre -> post
- percDen pre -> post

Questo permette di verificare direttamente quanto il rilevatore
sta usando la variazione ritmica invece del solo volume.


## 0.5.2 - RELATIVE dB DROP

Correzione per drop con aumento relativo forte ma livello assoluto basso.

Esempio:
- prima: -25 dB
- dopo:  -15 dB
- delta: +10 dB

La 0.5.2 misura esplicitamente il delta dB su finestre corte di 1 beat
prima e 1 beat dopo il candidato.

Nuova prova DROP:
- max delta dB >= +5 dB
- oppure kick delta >= +4 dB
- oppure bass delta >= +5 dB

Il delta dB e' relativo, quindi non importa se il drop resta a -15 dB.

Il log mostra:
`dBΔ=+X.X`

Restano attivi anche:
- densita' kick
- densita' percussioni
- ritorno energetico
- roll/build-up


## 0.6.0 - DEFINIZIONE MUSICALE DROP / BREAK

Definizioni fissate:

### DROP
DROP = accelerazione ritmica.

Un kick costante NON e' un drop.

Il rilevatore confronta:
- intervallo mediano tra kick prima/dopo;
- intervallo mediano tra percussioni prima/dopo;
- densita' degli onset prima/dopo.

Per validare DROP:
- intervallo dopo almeno 20% piu' corto;
- densita' dopo almeno 20% maggiore;
- kick oppure percussioni devono mostrare entrambe le condizioni.

Il volume NON basta mai da solo.

### Volume
Il delta dB serve a creare un candidato da verificare.

Esempio:
- -25 dB -> -10 dB = +15 dB
- viene sicuramente verificato;
- diventa DROP solo se in quel punto le percussioni accelerano.

Soglia candidatura volume:
- delta massimo >= +6 dB

### BREAK
BREAK = assenza di percussioni.

Viene cercato un gap senza onset kick/percussivi di almeno 4 beat.
Non richiede un calo di volume.

Intro e outro vuoti vengono esclusi.

### Log
Per ogni DROP:
- dB delta;
- densita' kick pre/post;
- densita' percussioni pre/post;
- intervalli kick pre/post;
- intervalli percussioni pre/post.


## 0.6.1 - EXPLICIT ACCELERATION FIX

Corretti due problemi della 0.6.0:

1. errore runtime:
   `ERRORE ANALISI MIR: 'rollCandidates'`

2. troppi DROP:
   la scansione ogni 1/4 beat produceva molte finestre localmente
   accelerate e quindi falsi positivi.

### DROP 0.6.1

Non viene piu' scandita tutta la traccia a griglia.

I candidati DROP esistono solo quando viene trovata una vera sequenza
di accelerazione delle suddivisioni ritmiche, per esempio:

`1 -> 0.5 -> 0.25`
oppure
`0.5 -> 0.25 -> 0.125`

Kick costante:
`1 -> 1 -> 1 -> 1`
non produce alcun DROP.

Vengono analizzate:
- accelerazioni sul kick;
- accelerazioni sulla componente percussiva.

La componente percussiva, piu' rumorosa, richiede:
- forte delta dB >= +6 dB
  oppure
- almeno 3 stadi distinti di accelerazione.

Un passaggio -25 dB -> -10 dB (+15 dB) rafforza quindi fortemente
la verifica, ma non crea DROP senza accelerazione.

### BREAK

Resta:
assenza di onset kick/percussivi per almeno 4 beat.


## 0.7.0 - SOLO BUILD UP + DROP

Rimossi i BREAK dai cue.

### BUILD UP
Il build-up e' una transizione crescente di 8 o 16 battute.

Viene verificata:
- accelerazione kick/percussioni;
- aumento densita' ritmica;
- accorciamento intervalli tra onset;
- variazione dB come supporto, non come prova unica.

Il cue BUILD UP viene posizionato all'inizio della finestra 8/16 beat.

### DROP
Ogni DROP deve essere associato a un BUILD UP valido precedente.

Il cue DROP viene posizionato alla fine del BUILD UP.

La sezione successiva viene verificata su 8 o 16 battute:
- kick/percussioni devono restare presenti;
- la sezione deve essere ritmicamente sostenuta;
- un singolo colpo o una variazione momentanea non basta.

### Volume
Il salto di volume rafforza il risultato.
Esempio -25 dB -> -10 dB = +15 dB viene considerato fortemente,
ma senza accelerazione/percussioni non viene creato il DROP.

### Cue
Solo:
- BUILD UP (arancione)
- DROP (giallo)

ordinati cronologicamente, massimo 8 cue.


## 0.7.1 - MAX 16 CUE

Unica modifica funzionale:
- massimo cue da 8 a 16.

La logica BUILD UP / DROP resta invariata.

Con eventi accoppiati questo consente fino a:
- 8 BUILD UP
- 8 DROP

per un massimo totale di 16 cue.


## 0.7.2 - BUILD UP + DROP + BREAK / MAX 16 CUE

Eventi attivi:
- BUILD UP (arancione)
- DROP (giallo)
- BREAK (blu)

Massimo totale:
- 16 cue.

### BREAK
Definizione:
assenza di onset kick/percussivi per almeno 4 beat.

Non viene usato il solo volume per definire un BREAK.
Intro e outro vuoti vengono ignorati.

### BUILD UP / DROP
Logica invariata rispetto alla 0.7.1:
- BUILD UP di 8 o 16 beat;
- accelerazione ritmica;
- DROP associato al BUILD UP;
- verifica sezione DROP di 8 o 16 beat;
- volume come supporto, mai come unica prova.


## 0.8.0 - KICK ONLY

Tutta la logica MIR usa esclusivamente lo stream KICK.

Esclusi completamente dalla decisione:
- bass
- instruments
- componente percussiva ricavata da instruments

### BUILD UP
Possibile solo se nello stem KICK:
- esistono onset;
- la densita' cresce e/o gli intervalli si accorciano;
- viene rilevata una vera accelerazione.

Nessun kick = nessun BUILD UP.

### DROP
Associato a un BUILD UP valido.
La sezione successiva di 8/16 beat deve mantenere il kick.

### BREAK
Assenza di onset KICK per almeno 4 beat.

### Volume
Tutto il delta dB viene calcolato solo sullo stem KICK.

### Cue
- BUILD UP arancione
- DROP giallo
- BREAK blu
- massimo 16 cue totali


## 0.8.1 - DECODE FIX

Corretto errore runtime:
`decode_stream() takes 2 positional arguments but 3 were given`

Modifica:
`decode_stream(stem_path, kick_stream)`

Nessuna modifica alla logica:
- KICK ONLY
- BUILD UP
- DROP
- BREAK
- MAX 16 cue


## 0.8.2 - BARS FIX

Corretto errore concettuale importante:

Prima:
- 8/16 battute interpretate come 8/16 beat.

Ora:
- 8 battute in 4/4 = 32 beat;
- 16 battute in 4/4 = 64 beat.

Quindi BUILD UP e DROP vengono analizzati su finestre musicali reali
di 8/16 battute.

Esempio a 122 BPM:
- 8 battute ~= 15,7 s
- 16 battute ~= 31,5 s

Riferimento Aria:
- DROP: 1:10,8
- BUILD UP 8 battute: circa 0:55,1 -> 1:10,8

Resta invariato:
- KICK ONLY
- BREAK da assenza kick
- max 16 cue


## 0.9.0 - SPECTRAL BUILD UP / DROP

Nuova logica strutturale.

### BUILD UP
Analizza finestre reali di:
- 8 battute = 32 beat
- 16 battute = 64 beat

Segnali:
- crescita densita' percussiva;
- accorciamento intervalli percussivi;
- aumento energia alta > 2.5 kHz;
- riduzione / contenimento energia bassa < 180 Hz.

Il BUILD UP non dipende piu' solo dal kick.

### DROP
Il DROP deve confermare contemporaneamente:
- ingresso/ritorno del KICK;
- ritorno del BASS / basse frequenze;
- salto energetico sul punto.

Il salto volume rafforza il risultato.
Un caso -25 dB -> -10 dB (+15 dB) viene quindi pesato fortemente.

Il DROP deve poi restare una sezione stabile di:
- 8 battute (32 beat)
- oppure 16 battute (64 beat)

### BREAK
Resta assenza di kick/percussioni per almeno 4 beat.

### Cue
- BUILD UP arancione
- DROP giallo
- BREAK blu
- max 16 cue


## 0.10.0 - STRUCTURE SEGMENTER

Cambio di architettura MIR.

Ordine:
1. waveform / RMS;
2. onset kick / percussioni;
3. spettro low / high;
4. chroma + recurrence matrix;
5. novelty / confini di sezione;
6. INTRO / OUTRO;
7. BREAK;
8. BUILD UP;
9. DROP;
10. cue.

### INTRO / OUTRO
Il motore identifica una zona iniziale/finale e vieta:
- BUILD UP nell'INTRO;
- BUILD UP nell'OUTRO;
- DROP nell'OUTRO.

### Waveform
La forma d'onda viene rappresentata tramite RMS/mix dB:
- contrazione = possibile break;
- espansione improvvisa = supporto DROP.

### BREAK
Riduzione strutturale di:
- kick;
- low-end;
- energia waveform.

Non richiede silenzio assoluto.

### BUILD UP
Solo tra INTRO e OUTRO.
Finestre:
- 8 battute = 32 beat;
- 16 battute = 64 beat.

Usa:
- crescita percussiva;
- alte frequenze in salita;
- basse filtrate/ridotte;
- confini strutturali della traccia;
- bonus se collegato a un BREAK.

### DROP
Conferma:
- ritorno KICK;
- ritorno BASS/low-end;
- espansione waveform;
- sezione stabile 8/16 battute.

### Cue
- BUILD UP arancione
- DROP giallo
- BREAK blu
- massimo 16 cue


## 0.10.1 - ADAPTIVE INTRO / OUTRO

Corretto il problema visto su Tiesto / Tate McRae - 10:35:
la 0.10.0 poteva assegnare INTRO/OUTRO enormi usando il boundary
strutturale piu' vicino a durate teoriche.

Ora:
- INTRO termina al primo groove pieno sostenuto;
- richiede kick + low-end + waveform energetica per due frasi consecutive;
- OUTRO parte dopo l'ultimo groove pieno sostenuto;
- i confini vengono allineati alle battute;
- 8/16/32 battute non definiscono piu' INTRO/OUTRO;
- i boundary novelty restano usati per BREAK / BUILD UP / DROP.

Restano:
- BUILD UP vietato nell'INTRO;
- BUILD UP vietato nell'OUTRO;
- BUILD UP e DROP 8/16 battute reali;
- massimo 16 cue.


## 0.11.0 - KICK ZONES

Esperimento semplificato.

Analisi esclusivamente dello stem KICK.

### Zone ritmiche
Il brano viene diviso per battute.
Una zona ritmica importante richiede:
- kick presente in modo stabile;
- densita' compatibile con groove dance;
- livello kick superiore al rumore/bleed;
- almeno 4 battute consecutive significative.

### Intro / Outro
Nessun cue.

INTRO:
- termina vicino all'inizio della prima zona ritmica importante;
- quantizzato a 8 o 16 battute.

OUTRO:
- comincia vicino alla fine dell'ultima zona ritmica importante;
- quantizzato a 8 o 16 battute.

### Break
Solo tra INTRO e OUTRO.

BREAK = zona con forte assenza/riduzione del kick:
- almeno 2 battute;
- densita' kick molto bassa
  oppure
- riduzione kick >= 5 dB rispetto alle zone vicine.

### Cue
Solo BREAK, blu.
Massimo 16.


## 0.12.0 - KICK WAVEFORM ON/OFF

Nuova logica semplificata.

Analisi esclusivamente dello stem KICK.

### 1. Waveform
Costruisce un envelope RMS/dB del kick e una soglia adattiva.

Classifica la traccia come grandi zone:
- KICK ON
- KICK OFF

Piccoli buchi/spike vengono filtrati.

### 2. INTRO / OUTRO
Nessuna durata fissa.

INTRO:
prima della prima zona KICK ON importante.

OUTRO:
dopo l'ultima zona KICK ON importante.

Nessun cue intro/outro.

### 3. BREAK
OFF tra due zone ON.

### 4. DROP
Il DROP coincide con l'inizio della nuova zona KICK ON
dopo il BREAK.

### 5. BUILD UP
Non viene cercato in tutta la traccia.

Si guarda esclusivamente all'indietro dal DROP,
nella parte finale del BREAK.

Vengono provate finestre di:
- 4 beat
- 8 beat
- 16 beat

e viene richiesto:
- aumento densita' kick
oppure
- intervalli kick che si accorciano.

### Cue
- BREAK blu
- BUILD UP arancione
- DROP giallo
- massimo 16


## 0.12.1 - MACRO BREAK

Correzione per casi come Argy, Omnya - Aria.

Problema 0.12.0:
un breve ritorno del kick dentro un BREAK poteva essere interpretato
come nuova zona ritmica e quindi spezzare il BREAK.

Nuova logica:

- MAIN RHYTHMIC ZONE = KICK ON sostenuto per almeno 8 beat / 2 battute;
- un breve ON dentro il gap tra due zone principali NON spezza il BREAK;
- BREAK = macro-zona tra due MAIN RHYTHMIC ZONE;
- fino al 55% di kick interno e' ammesso nella macro-zona;
- quel kick interno viene analizzato come possibile BUILD UP;
- DROP = inizio della successiva MAIN RHYTHMIC ZONE stabile.

Questo permette una struttura del tipo:

KICK ON
BREAK
  kick buildup interno
DROP
KICK ON

senza spezzare il BREAK al primo colpo del buildup.


## 0.12.2 - STRUCTURAL BREAK ONLY

Correzione sui falsi DROP.

Nuove regole:

- DROP possibile solo dopo un BREAK strutturale valido;
- BREAK minimo = 4 battute;
- piccoli buchi/interruzioni del kick non generano BREAK;
- piccoli buchi/interruzioni non possono quindi generare DROP;
- una macro-zona BREAK puo' contenere kick interno da BUILD UP,
  ma l'attivita' kick interna non deve superare il 40% della durata;
- BUILD UP viene cercato solo nella parte finale del BREAK;
- DROP = inizio della successiva MAIN RHYTHMIC ZONE stabile.

Obiettivo sul riferimento Argy, Omnya - Aria:
mantenere i due break/drop principali e scartare i falsi DROP 3/4
generati da piccole variazioni della waveform kick.


## 1.0.0 - TRACK INFO ONLY

Per ora disattivata tutta la logica MIR.

Il bridge:
- rileva caricamento/cambio traccia;
- recupera informazioni brano/deck;
- scrive il log;
- non cerca .vdjstems;
- non analizza kick/bass/instruments;
- non identifica zone;
- non crea BREAK / BUILD UP / DROP;
- non cancella o scrive cue.

Informazioni mantenute:
- title
- artist
- filepath
- bpm
- position
- hasLyrics
- lyricsLanguage


## 1.0.1 - WATCHER FIX

Corretto errore:
`process_track() missing 1 required positional argument: 'track'`

Il watcher chiama `process_track(track)`, quindi la funzione ora accetta
un solo argomento e ricava il deck dal dizionario della traccia.

Nessuna modifica funzionale:
- TRACK INFO ONLY
- nessuna analisi stem/MIR
- nessuna identificazione zone
- nessuna modifica cue


## 1.1.0 - FULL INFO DUMP

Modalita' completamente READ ONLY.

Ad ogni caricamento/cambio traccia prova a recuperare il maggior numero
possibile di informazioni direttamente da VirtualDJ.

Categorie:
- title / artist / remix / album / genre / year / comment / label;
- rating / key / BPM / length;
- filepath / filename;
- bitrate / samplerate / filesize / filetype;
- playcount / firstseen / lastplay;
- position / elapsed / remaining;
- pitch / pitch range / gain / volume;
- play / pause / sync / master tempo / key lock;
- audible / playing / paused / loaded;
- beat / beat position / beat phase / bar / grid / first beat;
- loop / loop size / loop position;
- lyrics presence / language;
- stato deck degli stems;
- cue 1..16: posizione / nome / colore, se esposti.

VirtualDJ non espone necessariamente tutti questi verbi in ogni build.
I valori non restituiti o non supportati vengono ignorati.

Nessun comando /execute viene usato dal FULL INFO DUMP.
Nessuna analisi audio/stem/MIR.
Nessun cue viene scritto, cancellato o modificato.
