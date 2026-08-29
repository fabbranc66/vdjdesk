<?php
declare(strict_types=1);

final class VirtualDjControlService
{
    public function __construct(private PDO $pdo) {}

    public function status(): array
    {
        $host = setting('vdj_network_host','127.0.0.1');
        $port = min(65535,max(1,(int)setting('vdj_network_port','9665')));
        $started = microtime(true);
        try {
            $version = $this->request('query','get_version');
            return ['online'=>true,'version'=>$version,'host'=>$host,'port'=>$port,'latency_ms'=>(int)round((microtime(true)-$started)*1000),'authentication'=>false,'error'=>''];
        } catch (Throwable $error) {
            return ['online'=>false,'version'=>'','host'=>$host,'port'=>$port,'latency_ms'=>(int)round((microtime(true)-$started)*1000),'authentication'=>false,'error'=>$error->getMessage()];
        }
    }

    public function currentTrack(): ?array
    {
        $deck=(int)$this->request('query','get_deck');
        if(!in_array($deck,[1,2],true))$deck=1;
        $deckPrefix='deck '.$deck.' ';
        $filePath = canonicalPath($this->request('query', $deckPrefix.'get_filepath'));
        if (str_starts_with(strtolower($filePath), 'error:')) $filePath='';
        $track=[];
        if($filePath!==''){
            $statement = $this->pdo->prepare('SELECT * FROM tracks WHERE file_path=? LIMIT 1');
            $statement->execute([$filePath]);
            $track = $statement->fetch() ?: [];
        }
        $artist = trim($this->request('query', $deckPrefix.'get_artist'));
        $title = trim($this->request('query', $deckPrefix.'get_title'));
        if($filePath===''&&$artist===''&&$title==='')return null;
        $bpm = trim($this->request('query', $deckPrefix.'get_bpm'));
        $key = trim($this->request('query', $deckPrefix.'get_key'));
        $genre = trim($this->request('query', $deckPrefix.'get_genre'));
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
            'deck' => $deck,
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

    public function setAutomixTransition(int $outgoingTrackId, int $incomingTrackId, float $outgoingInSeconds, float $outgoingOutSeconds, float $incomingInSeconds, float $incomingOutSeconds, int $transitionBeats): array
    {
        if ($outgoingTrackId < 1 || $incomingTrackId < 1 || $outgoingTrackId === $incomingTrackId) {
            throw new RuntimeException('Servono due brani diversi per la transizione Automix.');
        }
        $statement = $this->pdo->prepare('SELECT id,artist,title,file_path,duration,file_exists,bpm FROM tracks WHERE id IN (?,?)');
        $statement->execute([$outgoingTrackId, $incomingTrackId]);
        $tracks = [];
        foreach ($statement->fetchAll() as $track) $tracks[(int)$track['id']] = $track;
        $outgoing = $tracks[$outgoingTrackId] ?? null;
        $incoming = $tracks[$incomingTrackId] ?? null;
        if (!$outgoing || !$incoming) throw new RuntimeException('Brani della transizione non trovati.');
        foreach ([$outgoing, $incoming] as $track) {
            $path = canonicalPath((string)$track['file_path']);
            if (empty($track['file_exists']) || !is_file($path)) throw new RuntimeException('File della transizione non disponibile: ' . basename($path));
        }
        $outgoingDuration = $this->mediaDuration((string)$outgoing['file_path'], (float)($outgoing['duration'] ?? 0));
        $incomingDuration = $this->mediaDuration((string)$incoming['file_path'], (float)($incoming['duration'] ?? 0));
        $outgoingBpm = max(1.0, (float)($outgoing['bpm'] ?? 0));
        $incomingBpm = max(1.0, (float)($incoming['bpm'] ?? 0));
        if (!is_finite($outgoingInSeconds) || !is_finite($outgoingOutSeconds) || !is_finite($incomingInSeconds) || !is_finite($incomingOutSeconds)) {
            throw new RuntimeException('Punti della transizione fuori dalla durata dei brani.');
        }
        $outgoingInSeconds = max(0.0, min($outgoingInSeconds, $outgoingDuration));
        $outgoingOutSeconds = max($outgoingInSeconds, min($outgoingOutSeconds, $outgoingDuration));
        $incomingInSeconds = max(0.0, min($incomingInSeconds, $incomingDuration));
        $incomingOutSeconds = max($incomingInSeconds, min($incomingOutSeconds, $incomingDuration));
        $transitionBeats = max(1, $transitionBeats);
        $transitionSeconds = max(0.001, min($outgoingOutSeconds - $outgoingInSeconds, $outgoingDuration, $incomingDuration));
        $syncBpm = abs($outgoingBpm - $incomingBpm) < 15.0;
        $customMix = $this->writeCustomMix(
            (string)$outgoing['file_path'],
            (string)$incoming['file_path'],
            $incomingInSeconds,
            $outgoingInSeconds,
            $transitionSeconds,
            $syncBpm
        );
        return [
            'ok' => true,
            'outgoing_track_id' => $outgoingTrackId,
            'incoming_track_id' => $incomingTrackId,
            'outgoing_in_seconds' => round($outgoingInSeconds, 3),
            'outgoing_out_seconds' => round($outgoingOutSeconds, 3),
            'incoming_in_seconds' => round($incomingInSeconds, 3),
            'incoming_out_seconds' => round($incomingOutSeconds, 3),
            'actual_mix_out_seconds' => round($outgoingOutSeconds, 3),
            'actual_mix_in_seconds' => round($incomingInSeconds, 3),
            'transition_beats' => $transitionBeats,
            'transition_seconds' => round($transitionSeconds, 3),
            'sync_bpm' => $syncBpm,
            'custom_mix' => $customMix,
        ];
    }

    private function writeCustomMix(string $outgoingPath, string $incomingPath, float $incomingStart, float $outgoingStart, float $duration, bool $syncBpm): string
    {
        $drive = preg_match('/^([A-Z]):\\\\/i', $outgoingPath, $match) ? strtoupper($match[1]) : '';
        $databasePath = $drive !== '' ? $drive . ':\\VirtualDJ\\database.xml' : canonicalPath((string)setting('vdj_database', ''));
        if ($databasePath === '' || !is_file($databasePath)) throw new RuntimeException('Database VirtualDJ del brano uscente non disponibile.');

        try {
            [$incomingSid, $temporaryRelation] = $this->resolveVirtualDjSid($outgoingPath, $incomingPath);
        } catch (Throwable $error) {
            $this->startVirtualDj();
            throw $error;
        }
        $this->closeVirtualDj();
        $this->waitForFileRelease($databasePath);

        $backupPath = $databasePath . '.krdesk-custommix-' . date('Ymd-His') . '.bak';
        if (!copy($databasePath, $backupPath)) throw new RuntimeException('Backup database VirtualDJ non riuscito.');
        try {
            $xml = file_get_contents($databasePath);
            if ($xml === false) throw new RuntimeException('Lettura database VirtualDJ non riuscita.');
            $matchingPaths = $this->matchingVirtualDjSongPaths($xml, $outgoingPath);
            if (!$matchingPaths) throw new RuntimeException('Record VirtualDJ del brano uscente non trovato.');
            if ($temporaryRelation) $this->removeTemporaryVirtualDjRelation($temporaryRelation);
            $updatedXml = $xml;
            $encoded = '';
            foreach ($matchingPaths as $matchingPath) {
                $escapedPath = htmlspecialchars($matchingPath, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $songPattern = '/<Song\\b(?=[^>]*\\bFilePath="' . preg_quote($escapedPath, '/') . '")[^>]*>.*?<\\/Song>/su';
                if (!preg_match($songPattern, $updatedXml, $songMatch)) continue;
                [$updatedSong, $encoded] = $this->updateCustomMixSong($songMatch[0], $incomingSid, $incomingStart, $outgoingStart, $duration, $syncBpm);
                $position = strpos($updatedXml, $songMatch[0]);
                if ($position !== false) $updatedXml = substr_replace($updatedXml, $updatedSong, $position, strlen($songMatch[0]));
            }
            if ($encoded === '') throw new RuntimeException('Aggiornamento CustomMix non riuscito.');
            if ($updatedXml !== $xml) {
                $temporaryPath = $databasePath . '.krdesk.tmp';
                if (file_put_contents($temporaryPath, $updatedXml, LOCK_EX) === false || !rename($temporaryPath, $databasePath)) {
                    @unlink($temporaryPath);
                    throw new RuntimeException('Scrittura atomica CustomMix non riuscita.');
                }
            }
            $verifiedXml = file_get_contents($databasePath);
            if (!is_string($verifiedXml) || !str_contains($verifiedXml, $encoded)) throw new RuntimeException('Verifica CustomMix scritto non riuscita.');
        } finally {
            $this->startVirtualDj();
        }
        return $encoded;
    }

    private function matchingVirtualDjSongPaths(string $xml, string $trackPath): array
    {
        $database = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (!$database) return [];
        $target = canonicalPath($trackPath);
        $matches = [];
        foreach ($database->Song as $song) {
            $path = (string)$song['FilePath'];
            if (strcasecmp(canonicalPath($path), $target) === 0) $matches[] = $path;
        }
        return array_values(array_unique($matches));
    }

    private function updateCustomMixSong(string $songXml, string $incomingSid, float $incomingStart, float $outgoingStart, float $duration, bool $syncBpm): array
    {
        $items = [];
        $hasCustomMix = preg_match('/<CustomMix>(.*?)<\/CustomMix>/su', $songXml, $customMatch) === 1;
        if ($hasCustomMix) {
            $items = array_values(array_filter(explode(',', trim(html_entity_decode($customMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8'))), static fn(string $item): bool => trim($item) !== ''));
        }
        $itemIndex = null;
        foreach ($items as $index => $item) {
            if (str_starts_with(strtoupper($item), $incomingSid . '|')) {
                $itemIndex = $index;
                break;
            }
        }
        $fields = $itemIndex === null ? [$incomingSid, '', '', '', $syncBpm ? '1' : '0'] : explode('|', $items[$itemIndex]);
        if (count($fields) < 5) throw new RuntimeException('Formato CustomMix VirtualDJ non riconosciuto.');
        $fields[1] = $this->customMixDouble($incomingStart);
        $fields[2] = $this->customMixDouble($outgoingStart);
        $fields[3] = $this->customMixFloat($duration);
        $fields[4] = $syncBpm ? '1' : '0';
        $encoded = implode('|', $fields);
        if ($itemIndex === null) $items[] = $encoded; else $items[$itemIndex] = $encoded;
        $customXml = '<CustomMix>' . implode(',', $items) . '</CustomMix>';
        $updatedSong = $hasCustomMix
            ? preg_replace('/<CustomMix>.*?<\/CustomMix>/su', $customXml, $songXml, 1)
            : preg_replace('/<\/Song>$/u', $customXml . "\n</Song>", $songXml, 1);
        if (!is_string($updatedSong)) throw new RuntimeException('Aggiornamento CustomMix non riuscito.');
        return [$updatedSong, $encoded];
    }

    private function resolveVirtualDjSid(string $outgoingPath, string $incomingPath): array
    {
        $customMixSid = $this->storedCustomMixSid($outgoingPath, $incomingPath);
        if ($customMixSid !== '') return [$customMixSid, null];

        $extraPath = rtrim((string)(getenv('LOCALAPPDATA') ?: 'C:\\Users\\fabbr\\AppData\\Local'), '\\') . '\\VirtualDJ\\extra.db';
        if (!is_file($extraPath)) throw new RuntimeException('Database SID VirtualDJ non disponibile.');
        $snapshotPath = sys_get_temp_dir() . '\\vdjdesk-extra-' . bin2hex(random_bytes(6)) . '.db';
        if (!copy($extraPath, $snapshotPath)) throw new RuntimeException('Lettura SID VirtualDJ non riuscita.');
        try {
            $incomingSid = $this->virtualDjSidFromDatabase($snapshotPath, $incomingPath);
            if ($incomingSid !== '') return [$incomingSid, null];
            $relations = $this->virtualDjRelations($snapshotPath);
            $this->execute('deck 1 load "' . $this->scriptValue($outgoingPath) . '" & deck 1 pause');
            $this->execute('deck 2 load "' . $this->scriptValue($incomingPath) . '" & deck 2 pause');
            usleep(800000);
            if (strcasecmp(canonicalPath($this->request('query', 'deck 1 get_filepath')), canonicalPath($outgoingPath)) !== 0
                || strcasecmp(canonicalPath($this->request('query', 'deck 2 get_filepath')), canonicalPath($incomingPath)) !== 0) {
                throw new RuntimeException('VirtualDJ non ha caricato la coppia richiesta.');
            }
            $this->execute('mark_linked_tracks');
            $this->closeVirtualDj();
            $outgoingSid = $this->virtualDjSidFromDatabase($extraPath, $outgoingPath);
            $incomingSid = $this->virtualDjSidFromDatabase($extraPath, $incomingPath);
            if ($outgoingSid === '' || $incomingSid === '') throw new RuntimeException('VirtualDJ non ha generato i SID della coppia.');
            $relationKey = $this->virtualDjRelationKey($outgoingSid, $incomingSid);
            return [$incomingSid, isset($relations[$relationKey]) ? null : [$extraPath, $outgoingSid, $incomingSid]];
        } finally {
            @unlink($snapshotPath);
        }
    }

    private function storedCustomMixSid(string $outgoingPath, string $incomingPath): string
    {
        $path = APP_ROOT . '/storage/vdj_custommix_pair_ids.json';
        if (!is_file($path)) return '';
        $items = json_decode((string)file_get_contents($path), true);
        if (!is_array($items)) return '';
        $outgoing = normalizeText(canonicalPath($outgoingPath));
        $incoming = normalizeText(canonicalPath($incomingPath));
        foreach ($items as $item) {
            if (!is_array($item)
                || normalizeText(canonicalPath((string)($item['outgoing'] ?? ''))) !== $outgoing
                || normalizeText(canonicalPath((string)($item['incoming'] ?? ''))) !== $incoming) continue;
            $sid = strtoupper((string)($item['sid'] ?? ''));
            if (preg_match('/^[0-9A-F]{16}$/', $sid)) return $sid;
        }
        return '';
    }

    private function virtualDjSidFromDatabase(string $databasePath, string $filePath): string
    {
        $sqlite = new PDO('sqlite:' . $databasePath);
        $statement = $sqlite->prepare("SELECT printf('%016X',sid) FROM track_data WHERE lower(file)=lower(?) LIMIT 1");
        $statement->execute([canonicalPath($filePath)]);
        $sid = strtoupper((string)($statement->fetchColumn() ?: ''));
        if ($sid === '') {
            $target = normalizeText(canonicalPath($filePath));
            foreach ($sqlite->query("SELECT printf('%016X',sid) sid,file FROM track_data") as $row) {
                if (normalizeText(canonicalPath((string)$row['file'])) === $target) {
                    $sid = strtoupper((string)$row['sid']);
                    break;
                }
            }
        }
        return preg_match('/^[0-9A-F]{16}$/', $sid) ? $sid : '';
    }

    private function virtualDjRelations(string $databasePath): array
    {
        $sqlite = new PDO('sqlite:' . $databasePath);
        $relations = [];
        foreach ($sqlite->query("SELECT printf('%016X',sid1),printf('%016X',sid2) FROM related_tracks") as $row) {
            $relations[$this->virtualDjRelationKey((string)$row[0], (string)$row[1])] = true;
        }
        return $relations;
    }

    private function virtualDjRelationKey(string $leftSid, string $rightSid): string
    {
        $items = [strtoupper($leftSid), strtoupper($rightSid)];
        sort($items, SORT_STRING);
        return implode('|', $items);
    }

    private function removeTemporaryVirtualDjRelation(array $relation): void
    {
        [$extraPath, $leftSid, $rightSid] = $relation;
        $this->waitForFileRelease($extraPath);
        $backupPath = $extraPath . '.krdesk-custommix-' . date('Ymd-His') . '.bak';
        if (!copy($extraPath, $backupPath)) throw new RuntimeException('Backup collegamenti VirtualDJ non riuscito.');
        $sqlite = new PDO('sqlite:' . $extraPath);
        $statement = $sqlite->prepare("DELETE FROM related_tracks WHERE (printf('%016X',sid1)=? AND printf('%016X',sid2)=?) OR (printf('%016X',sid1)=? AND printf('%016X',sid2)=?)");
        $statement->execute([$leftSid, $rightSid, $rightSid, $leftSid]);
    }

    private function closeVirtualDj(): void
    {
        if (!$this->isVirtualDjRunning()) return;
        try {
            $this->request('execute', 'exit');
        } catch (Throwable) {
        }
        usleep(600000);
        if ($this->isVirtualDjRunning()) exec('taskkill /IM virtualdj.exe');
        $deadline = microtime(true) + 10.0;
        while ($this->isVirtualDjRunning() && microtime(true) < $deadline) usleep(200000);
        if ($this->isVirtualDjRunning()) throw new RuntimeException('VirtualDJ non si e chiuso correttamente.');
    }

    private function waitForFileRelease(string $path, float $timeoutSeconds = 10.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastSize = -1;
        $stableReads = 0;
        do {
            clearstatcache(true, $path);
            $handle = @fopen($path, 'r+b');
            if (is_resource($handle)) {
                fclose($handle);
                $size = (int)@filesize($path);
                $stableReads = $size === $lastSize ? $stableReads + 1 : 0;
                $lastSize = $size;
                if ($stableReads >= 2) return;
            } else {
                $stableReads = 0;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('VirtualDJ non ha ancora rilasciato il database.');
    }

    private function customMixDouble(float $value): string
    {
        return abs($value) < 0.0000005 ? '' : strtoupper(bin2hex(pack('e', $value)));
    }

    private function customMixFloat(float $value): string
    {
        return strtoupper(bin2hex(pack('g', $value)));
    }

    private function isVirtualDjRunning(): bool
    {
        exec('tasklist /FI "IMAGENAME eq virtualdj.exe" /NH', $output);
        return str_contains(strtolower(implode("\n", $output)), 'virtualdj.exe');
    }

    private function startVirtualDj(): void
    {
        $executable = 'C:\\Program Files\\VirtualDJ\\virtualdj.exe';
        if (!is_file($executable) || $this->isVirtualDjRunning()) return;
        pclose(popen('start "" "' . $executable . '"', 'r'));
    }

    private function mediaDuration(string $filePath, float $fallback): float
    {
        $path = canonicalPath($filePath);
        $process = proc_open(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            APP_ROOT
        );
        if (is_resource($process)) {
            fclose($pipes[0]);
            $output = trim((string)stream_get_contents($pipes[1]));
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $duration = (float)$output;
            if ($exitCode === 0 && is_finite($duration) && $duration > 0) return $duration;
        }
        return max(0.01, $fallback);
    }

    private function setMixPoints(string $filePath, float $duration, array $points): array
    {
        $actual = [];
        foreach ($this->virtualDjPathVariants($filePath) as $filePathVariant) {
            $path = $this->scriptValue($filePathVariant);
            $this->execute('deck 1 load "' . $path . '"');
            usleep(500000);
            $this->execute('deck 1 pause');
            foreach ($points as $point => $seconds) {
                $seconds = max(0.0, min($duration, (float)$seconds));
                $position = number_format(max(0.0, min(100.0, $seconds / $duration * 100)), 6, '.', '') . '%';
                $this->execute('deck 1 goto ' . $position);
                usleep(100000);
                $this->execute('deck 1 set_mixpoint "' . $point . '"');
                usleep(100000);
                $this->execute('deck 1 goto_mixpoint "' . $point . '"');
                usleep(100000);
                $variantActual = max(0.0, min(1.0, (float)$this->request('query', 'deck 1 get_position'))) * $duration;
                if (abs($variantActual - $seconds) > 1.5) throw new RuntimeException('VirtualDJ non ha memorizzato correttamente il POI ' . $point . ': richiesto ' . round($seconds, 3) . 's, rilevato ' . round($variantActual, 3) . 's.');
                $actual[$point] ??= $variantActual;
            }
        }
        return $actual;
    }

    private function virtualDjPathVariants(string $filePath): array
    {
        $target = canonicalPath($filePath);
        $variants = [$target];
        $databases = array_values(array_unique(array_filter([
            canonicalPath((string)setting('vdj_database', '')),
            'E:\\VirtualDJ\\database.xml',
        ])));
        foreach ($databases as $databasePath) {
            if (!is_file($databasePath)) continue;
            $xml = @simplexml_load_file($databasePath, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if (!$xml) continue;
            foreach ($xml->Song as $song) {
                $candidate = canonicalPath((string)$song['FilePath']);
                if ($candidate !== '' && strcasecmp($candidate, $target) === 0 && !in_array($candidate, $variants, true)) $variants[] = $candidate;
            }
        }
        return $variants;
    }

    private function execute(string $script): void
    {
        if (strtolower($this->request('execute', $script)) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha accettato il comando: ' . $script);
        }
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

    public function prelistenPath(string $path): array
    {
        $path = canonicalPath($path);
        if ($path === '' || !is_file($path) || !preg_match('/^[A-Z]:\\\\/i', $path)) {
            throw new RuntimeException('File non disponibile per il preascolto.');
        }
        $query = $this->scriptValue($path);
        if ($query === '') throw new RuntimeException('Percorso file non disponibile.');
        $script = 'prelisten_stop & search "' . $query . '" & browser_window "songs" & browser_scroll "top"';
        if (strtolower($this->request('execute', $script)) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha selezionato il file.');
        }
        usleep(500000);
        $selectedPath = canonicalPath($this->request('query', 'get_browsed_filepath'));
        if (strcasecmp($selectedPath, $path) !== 0) {
            throw new RuntimeException('VirtualDJ ha selezionato un file diverso dal percorso richiesto.');
        }
        if (strtolower($this->request('execute', 'prelisten')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha avviato il preascolto.');
        }
        return ['ok'=>true,'path'=>$path,'selected_path'=>$selectedPath,'title'=>pathinfo($path,PATHINFO_FILENAME),'start_at'=>0];
    }

    public function stopPrelisten(): array
    {
        if (strtolower($this->request('execute', 'prelisten_stop')) !== 'true') {
            throw new RuntimeException('VirtualDJ non ha fermato il preascolto.');
        }
        return ['ok'=>true,'stopped'=>true];
    }

    public function replaceTrackCues(int $trackId, array $cues): array
    {
        $statement=$this->pdo->prepare('SELECT artist,title,file_path FROM tracks WHERE id=? AND file_exists=1');
        $statement->execute([$trackId]);
        $track=$statement->fetch();
        if(!$track)throw new RuntimeException('Brano non disponibile nella Libreria Musicale.');
        $trackPath=canonicalPath((string)$track['file_path']);
        $deck=0;
        for($candidate=1;$candidate<=4;$candidate++){
            $deckPath=canonicalPath($this->request('query','deck '.$candidate.' get_filepath'));
            if($deckPath!==''&&strcasecmp($deckPath,$trackPath)===0){$deck=$candidate;break;}
        }
        if($deck===0)throw new RuntimeException('Carica il brano analizzato in un deck VirtualDJ prima di esportare i cue.');
        $cues=array_slice(array_values($cues),0,16);
        if(!$cues)throw new RuntimeException('Nessun cue da esportare.');
        $quantize=strtolower($this->request('query','deck '.$deck.' quantize_setcue'));
        $this->request('execute','deck '.$deck.' quantize_setcue off');
        try{
            for($number=1;$number<=16;$number++)$this->request('execute','deck '.$deck.' delete_cue '.$number);
            foreach($cues as $index=>$cue){
                $number=$index+1;
                $milliseconds=max(1,(int)round((float)($cue['time']??0)*1000));
                $name=$this->scriptValue((string)($cue['name']??('CUE '.$number)));
                $color=$this->scriptValue((string)($cue['color']??'#FFFFFF'));
                if(strtolower($this->request('execute','deck '.$deck.' set_cue '.$number.' '.$milliseconds.'ms'))!=='true')throw new RuntimeException('VirtualDJ non ha accettato il cue '.$number.'.');
                $this->request('execute','deck '.$deck.' cue_name '.$number.' "'.$name.'"');
                $this->request('execute','deck '.$deck.' cue_color '.$number.' "'.$color.'"');
            }
        }finally{
            if(in_array($quantize,['true','on','yes','1'],true))$this->request('execute','deck '.$deck.' quantize_setcue on');
        }
        return ['ok'=>true,'deck'=>$deck,'count'=>count($cues),'track_id'=>$trackId,'title'=>trim((string)$track['artist'].' - '.(string)$track['title'])];
    }

    public function releasePrelistenToPath(string $path): array
    {
        $path=canonicalPath($path);
        if($path===''||!is_file($path)||!preg_match('/^[A-Z]:\\\\/i',$path))throw new RuntimeException('File di rilascio VDJ non disponibile.');
        $query=$this->scriptValue($path);
        $script='prelisten_stop & search "'.$query.'" & browser_window "songs" & browser_scroll "top"';
        if(strtolower($this->request('execute',$script))!=='true')throw new RuntimeException('VirtualDJ non ha selezionato il file di rilascio.');
        usleep(450000);
        $selectedPath=canonicalPath($this->request('query','get_browsed_filepath'));
        if(strcasecmp($selectedPath,$path)!==0)throw new RuntimeException('VirtualDJ ha selezionato un file di rilascio diverso.');
        if(strtolower($this->request('execute','prelisten & prelisten_pos 100%'))!=='true')throw new RuntimeException('VirtualDJ non ha trasferito il preascolto.');
        usleep(250000);
        $this->request('execute','prelisten_stop');
        usleep(600000);
        return ['ok'=>true,'path'=>$path];
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

    public function colorTrackByKrTaxonomy(int $trackId): array
    {
        $statement=$this->pdo->prepare('SELECT id,artist,title,file_path,macro_genre,folder_genre,genre FROM tracks WHERE id=? AND file_exists=1');
        $statement->execute([$trackId]);$track=$statement->fetch();
        if(!$track)throw new RuntimeException('Brano playlist non disponibile.');
        $path=canonicalPath((string)$track['file_path']);
        if($path===''||!is_file($path))throw new RuntimeException('File fisico non disponibile.');
        $query=$this->scriptValue($path);
        if($query===''||strtolower($this->request('execute','search "'.$query.'" & browser_window "songs" & browser_scroll "top"'))!=='true')throw new RuntimeException('VirtualDJ non ha trovato il file.');
        usleep(700000);
        $selectedPath=canonicalPath($this->request('query','get_browsed_filepath'));
        if(strcasecmp($selectedPath,$path)!==0){usleep(350000);$selectedPath=canonicalPath($this->request('query','get_browsed_filepath'));}
        if(strcasecmp($selectedPath,$path)!==0)throw new RuntimeException('VirtualDJ ha selezionato un file diverso.');
        $colorMacroGenre=$this->macroGenreForVdjMicroGenre((string)$track['genre'],(string)$track['macro_genre']);
        $color=$this->krTaxonomyColor($colorMacroGenre,(string)$track['genre']);
        if(strtolower($this->request('execute','browsed_file_color "'.$color.'"'))!=='true')throw new RuntimeException('VirtualDJ non ha accettato il colore.');
        return ['ok'=>true,'id'=>(int)$track['id'],'artist'=>$track['artist'],'title'=>$track['title'],'macro_genre'=>$track['macro_genre'],'color_macro_genre'=>$colorMacroGenre,'folder_genre'=>$track['folder_genre'],'micro_genre'=>$track['genre'],'color'=>$color,'path'=>$path];
    }

    private function macroGenreForVdjMicroGenre(string $microGenre,string $fallback): string
    {
        $microGenre=trim($microGenre);
        if($microGenre==='')return $fallback;
        $statement=$this->pdo->prepare("SELECT macro_genre,COUNT(*) total FROM tracks WHERE file_exists=1 AND ".definitiveMusicSqlCondition()." AND TRIM(macro_genre)<>'' AND LOWER(TRIM(genre))=LOWER(?) GROUP BY macro_genre ORDER BY total DESC,macro_genre LIMIT 1");
        $statement->execute([$microGenre]);
        return trim((string)($statement->fetchColumn()?:$fallback));
    }

    private function krTaxonomyColor(string $macroGenre,string $microGenre): string
    {
        $bases=[
            'Commerciale'=>[37,99,235],
            'Italiana'=>[34,197,94],
            'Latin'=>[249,115,22],
            'Rock_PopRock'=>[239,68,68],
            'Urban'=>[168,85,247],
        ];
        $base=$bases[$macroGenre]??[100,100,100];
        $levels=[-0.28,-0.14,0.0,0.14,0.28];
        $normalizedMicroGenre=mb_strtolower(trim($microGenre),'UTF-8');
        $hash=(int)sprintf('%u',crc32($normalizedMicroGenre));
        $level=$normalizedMicroGenre===''?0.0:$levels[$hash%count($levels)];
        $rgb=array_map(static function(int $component) use ($level): int {
            $value=$level<0?$component*(1+$level):$component+(255-$component)*$level;
            return max(0,min(255,(int)round($value)));
        },$base);
        return sprintf('#%02X%02X%02X',$rgb[0],$rgb[1],$rgb[2]);
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
        $destination = technicalAreaPath('01_INBOX\\Da_cancellare');
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
        if (!$this->isReachable($host, $port)) {
            throw new RuntimeException("VirtualDJ Network Control offline su $host:$port.");
        }
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: text/plain\r\nConnection: close\r\n",'content'=>$script,'timeout'=>1,'ignore_errors'=>true]]);
        $response = @file_get_contents("http://$host:$port/$endpoint",false,$context);
        if ($response === false) throw new RuntimeException("VirtualDJ Network Control non risponde su $host:$port.");
        return trim($response);
    }

    private function isReachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $error, 0.25);
        if (!$socket) return false;
        fclose($socket);
        return true;
    }
}
