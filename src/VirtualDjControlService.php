<?php
declare(strict_types=1);

final class VirtualDjControlService
{
    public function __construct(private PDO $pdo) {}

    public function status(): array
    {
        return ['online'=>true,'version'=>$this->request('query','get_version'),'port'=>(int)setting('vdj_network_port','9665'),'authentication'=>false];
    }

    public function currentTrack(): ?array
    {
        $filePath = canonicalPath($this->request('query', 'deck active get_filepath'));
        if ($filePath === '' || str_starts_with(strtolower($filePath), 'error:')) return null;
        $statement = $this->pdo->prepare('SELECT * FROM tracks WHERE file_path=? LIMIT 1');
        $statement->execute([$filePath]);
        $track = $statement->fetch() ?: [];
        $artist = trim($this->request('query', 'deck active get_artist'));
        $title = trim($this->request('query', 'deck active get_title'));
        $bpm = trim($this->request('query', 'deck active get_bpm'));
        $key = trim($this->request('query', 'deck active get_key'));
        $genre = trim($this->request('query', 'deck active get_genre'));
        return array_merge($track, [
            'id' => isset($track['id']) ? (int) $track['id'] : null,
            'artist' => $artist !== '' ? $artist : (string) ($track['artist'] ?? ''),
            'title' => $title !== '' ? $title : (string) ($track['title'] ?? basename($filePath)),
            'bpm' => is_numeric($bpm) ? (float) $bpm : ($track['bpm'] ?? null),
            'musical_key' => $key !== '' ? $key : (string) ($track['musical_key'] ?? ''),
            'camelot' => $key !== '' ? ltrim($key, '0') : (string) ($track['camelot'] ?? ''),
            'genre' => trim((string)($track['genre'] ?? '')) !== '' ? (string)$track['genre'] : $genre,
            'file_path' => $filePath,
            'energy' => (int) ($track['energy'] ?? 3),
            'tags' => trackTags($track),
            'auto_tags' => autoTrackTags($track),
            'auto_tag_overrides' => autoTagOverrides($track),
            'on_air' => true,
        ]);
    }

    public function searchApprovedCandidate(int $id): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM deletion_candidates WHERE id=? AND status='approved'");
        $statement->execute([$id]);
        $candidate = $statement->fetch();
        if (!$candidate) throw new RuntimeException('Brano approvato non trovato.');
        $title = trim(pathinfo((string)$candidate['source_name'], PATHINFO_FILENAME));
        $title = trim(preg_replace('/\s+/', ' ', preg_replace('/[_\x00-\x1F\x7F"]+/', ' ', $title) ?? $title) ?? $title);
        $drive = preg_match('/^([A-Z]):\\\\/i', (string)$candidate['source_path'], $match) ? strtolower($match[1]) . ':' : '';
        $query = trim($drive . ' ' . $title);
        if ($query === '') throw new RuntimeException('Testo di ricerca non disponibile.');
        $script = 'search "'.$query.'" & browser_window "songs" & browser_scroll "top"';
        if (strtolower($this->request('execute',$script)) !== 'true') throw new RuntimeException('VirtualDJ non ha accettato la ricerca.');
        usleep(1000000);
        $target = $this->moveToDeletionFolder((string)$candidate['source_path']);
        usleep(200000);
        $this->pdo->prepare("UPDATE deletion_candidates SET status='moved',last_vdj_search_at=CURRENT_TIMESTAMP,moved_to_path=?,moved_at=CURRENT_TIMESTAMP,decision_note='Cercato in VirtualDJ e spostato in Da_cancellare' WHERE id=?")->execute([$target,$id]);
        return ['ok'=>true,'query'=>$query,'selected_first_result'=>true,'moved_to'=>$target];
    }

    public function addTrackToAutomix(int $trackId): array
    {
        $statement = $this->pdo->prepare('SELECT id,artist,title,file_path,file_exists FROM tracks WHERE id=?');
        $statement->execute([$trackId]);
        $track = $statement->fetch();
        if (!$track) throw new RuntimeException('Brano non presente in VDJ Desk.');
        $drive = preg_match('/^([A-Z]):\\\\/i', (string) $track['file_path'], $match) ? strtolower($match[1]) . ':' : '';
        $query = trim($drive . ' ' . $track['artist'] . ' ' . $track['title']);
        $query = trim(preg_replace('/\s+/', ' ', preg_replace('/[_\x00-\x1F\x7F"]+/', ' ', $query) ?? $query) ?? $query);
        if ($query === '') throw new RuntimeException('Testo di ricerca non disponibile.');
        if (strtolower($this->request('execute', 'search "' . $query . '"')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha accettato la ricerca.');
        }
        usleep(1000000);
        if (strtolower($this->request('execute', 'browser_window "songs" & browser_scroll "top"')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha selezionato il risultato della ricerca.');
        }
        usleep(250000);
        if (strtolower($this->request('execute', 'playlist_add')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha accettato il brano in Automix.');
        }
        $this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(\'suggestion_start_track_id\',?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([(string) $trackId]);
        return ['ok'=>true,'track_id'=>$trackId,'query'=>$query,'title'=>trim($track['artist'] . ' - ' . $track['title'])];
    }

    public function prelistenTrack(int $trackId): array
    {
        $statement = $this->pdo->prepare('SELECT id,artist,title,file_path,duration FROM tracks WHERE id=? AND file_exists=1');
        $statement->execute([$trackId]);
        $track = $statement->fetch();
        if (!$track || !is_file(canonicalPath((string) $track['file_path']))) {
            throw new RuntimeException('Brano non disponibile per il preascolto.');
        }
        $drive = preg_match('/^([A-Z]):\\\\/i', (string) $track['file_path'], $match) ? strtolower($match[1]) . ':' : '';
        $query = $this->scriptValue(trim($drive . ' ' . $track['artist'] . ' ' . $track['title']));
        if ($query === '') throw new RuntimeException('Testo di ricerca non disponibile.');
        if (strtolower($this->request('execute', 'prelisten_stop & search "' . $query . '" & browser_window "songs" & browser_scroll "top"')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha selezionato il brano.');
        }
        usleep(500000);
        if (strtolower($this->request('execute', 'prelisten')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha avviato il preascolto.');
        }
        usleep(100000);
        $duration = (float) ($track['duration'] ?? 0);
        $startAt = $duration > 5 ? min(60.0, $duration - 5.0) : 0.0;
        $position = $duration > 0 ? number_format(($startAt / $duration) * 100, 4, '.', '') . '%' : '0%';
        if (strtolower($this->request('execute', 'prelisten_pos ' . $position)) !== 'true') {
            $this->request('execute', 'prelisten_stop');
            throw new RuntimeException('VirtualDJ non ha posizionato il preascolto a 60 secondi.');
        }
        return ['ok'=>true,'track_id'=>$trackId,'query'=>$query,'title'=>trim($track['artist'] . ' - ' . $track['title']),'start_at'=>round($startAt,1)];
    }

    public function stopPrelisten(): array
    {
        if (strtolower($this->request('execute', 'prelisten_stop')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha fermato il preascolto.');
        }
        return ['ok'=>true,'stopped'=>true];
    }

    public function markTrackAsNew(int $trackId): bool
    {
        $statement=$this->pdo->prepare('SELECT artist,title,file_path FROM tracks WHERE id=? AND file_exists=1');$statement->execute([$trackId]);$track=$statement->fetch();
        if(!$track)return false;
        $drive=preg_match('/^([A-Z]):\\\\/i',(string)$track['file_path'],$match)?strtolower($match[1]).':':'';
        $query=trim($drive.' '.$track['artist'].' '.$track['title']);
        $query=trim(preg_replace('/\s+/',' ',preg_replace('/[_\x00-\x1F\x7F"]+/',' ',$query)??$query)??$query);
        if($query===''||strtolower($this->request('execute','search "'.$query.'" & browser_window "songs" & browser_scroll "top"'))!=='true')return false;
        usleep(350000);
        return strtolower($this->request('execute','browsed_song "info" "#N"'))==='true';
    }

    public function alignArtistTitle(int $trackId): array
    {
        $statement=$this->pdo->prepare('SELECT t.id,t.artist,t.title,t.file_path,t.file_name FROM tracks t WHERE t.id=? AND t.file_exists=1 AND EXISTS(SELECT 1 FROM track_sources s WHERE s.track_id=t.id)');
        $statement->execute([$trackId]);$track=$statement->fetch();
        if(!$track)throw new RuntimeException('Brano non collegato al database VirtualDJ.');
        $drive=preg_match('~^([A-Z]):\\\\~i',(string)$track['file_path'],$match)?strtolower($match[1]).':':'';
        $fileTitle=pathinfo((string)($track['file_name']?:basename((string)$track['file_path'])),PATHINFO_FILENAME);
        $query=$this->scriptValue(trim($drive.' '.$fileTitle));
        if($query===''||strtolower($this->request('execute','search "'.$query.'" & browser_window "songs" & browser_scroll "top"'))!=='true')throw new RuntimeException('VirtualDJ non ha trovato il file collegato.');
        usleep(450000);
        $artist=$this->scriptValue((string)$track['artist']);$title=$this->scriptValue((string)$track['title']);
        if($artist===''||$title==='')throw new RuntimeException('Artista o titolo KR Desk mancanti.');
        if(strtolower($this->request('execute','browsed_song "artist" "'.$artist.'" & browsed_song "title" "'.$title.'"'))!=='true')throw new RuntimeException('VirtualDJ non ha accettato artista e titolo.');
        return ['ok'=>true,'id'=>(int)$track['id'],'artist'=>$track['artist'],'title'=>$track['title']];
    }

    private function moveToDeletionFolder(string $source): string
    {
        if (!is_file($source)) throw new RuntimeException('Il file sorgente non esiste più.');
        $destination = 'E:\\LIBRERIA_DEFINITIVA\\01_INBOX\\Da_cancellare';
        if (!is_dir($destination)) throw new RuntimeException('Cartella Da_cancellare non trovata.');
        if (str_starts_with(strtoupper($source), strtoupper($destination.'\\'))) throw new RuntimeException('Il file è già nella cartella Da_cancellare.');
        $target = $destination . '\\' . basename($source);
        if (file_exists($target)) throw new RuntimeException('Un file con lo stesso nome è già presente in Da_cancellare.');
        $sourceSize = filesize($source);
        if (!@rename($source,$target)) {
            if (!@copy($source,$target)) throw new RuntimeException('Impossibile copiare il file in Da_cancellare.');
            clearstatcache(true,$target);
            if (!is_file($target) || filesize($target)!==$sourceSize || sha1_file($source)!==sha1_file($target)) {
                @unlink($target);
                throw new RuntimeException('Verifica del file copiato non riuscita; sorgente conservata.');
            }
            if (!@unlink($source)) {
                @unlink($target);
                throw new RuntimeException('Impossibile rimuovere la sorgente dopo la copia; operazione annullata.');
            }
        }
        clearstatcache(true,$target);
        if (!is_file($target) || file_exists($source) || filesize($target)!==$sourceSize) throw new RuntimeException('Verifica finale dello spostamento non riuscita.');
        return $target;
    }

    private function scriptValue(string $value): string
    {
        $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $value=(string)(iconv('UTF-8','UTF-8//IGNORE',$value)?:$value);
        $value=preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{FFFD}]/u','',$value)??$value;
        $value=preg_replace('/["\x00-\x1F\x7F]+/u',' ',$value)??$value;
        return trim(preg_replace('/\s+/u',' ',$value)??$value);
    }

    private function request(string $endpoint, string $script): string
    {
        $host = setting('vdj_network_host','127.0.0.1');
        $port = min(65535,max(1,(int)setting('vdj_network_port','9665')));
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: text/plain\r\nConnection: close\r\n",'content'=>$script,'timeout'=>3,'ignore_errors'=>true]]);
        $response = @file_get_contents("http://$host:$port/$endpoint",false,$context);
        if ($response === false) throw new RuntimeException("VirtualDJ Network Control non raggiungibile sulla porta $port.");
        return trim($response);
    }
}
