<?php
declare(strict_types=1);

final class AudioReplacementService
{
    private const EXTENSIONS = ['mp3','m4a','aac','ogg','opus','flac','wav','mp4','m4v','mov','mkv','avi','webm','wmv'];
    private const VIDEO_EXTENSIONS = ['mp4','m4v','mov','mkv','avi','webm','wmv'];
    public function __construct(private PDO $pdo, private LibraryService $library) {}

    public function start(int $trackId): array
    {
        $track = $this->library->find($trackId);
        if (!$track || !is_file((string)$track['file_path'])) throw new RuntimeException('File originale non disponibile.');
        if (!in_array(strtolower(pathinfo((string)$track['file_path'], PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw new RuntimeException('La sostituzione e consentita solo tra formati media supportati.');
        }
        $directory = $this->browserDownloadDirectory();
        $staging = $this->stagingDirectory();
        $statement = $this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $statement->execute(['replacement_track_id', (string)$trackId]);
        $statement->execute(['replacement_started_at', (string)time()]);
        $statement->execute(['replacement_download_snapshot', json_encode($this->downloadSnapshot($directory), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $this->pdo->exec("DELETE FROM settings WHERE `key` IN ('replacement_pending_download','replacement_media_change_confirmed')");
        return ['ok' => true, 'track_id' => $trackId, 'download_folder' => $directory, 'staging_folder' => $staging, 'message' => 'Monitoraggio download avviato.'];
    }

    public function status(): array
    {
        $settings = $this->pdo->query("SELECT `key`,value FROM settings WHERE `key` IN ('replacement_track_id','replacement_started_at','replacement_download_snapshot','replacement_pending_download')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $trackId = (int)($settings['replacement_track_id'] ?? 0);
        $started = (int)($settings['replacement_started_at'] ?? 0);
        if ($trackId < 1 || $started < 1) return ['pending' => false];
        if ($started < time() - 900) {
            $this->clear();
            return ['pending' => false, 'expired' => true];
        }
        $pendingDownload = canonicalPath((string)($settings['replacement_pending_download'] ?? ''));
        $download = $pendingDownload !== '' && is_file($pendingDownload) ? $pendingDownload : $this->latestDownload($started, $this->decodeSnapshot((string)($settings['replacement_download_snapshot'] ?? '')));
        if ($download === null) return ['pending' => true, 'download_folder' => $this->browserDownloadDirectory(), 'staging_folder' => $this->stagingDirectory()];
        return $this->replace($trackId, $download);
    }

    public function confirmMediaChange(): array
    {
        $settings = $this->pdo->query("SELECT `key`,value FROM settings WHERE `key` IN ('replacement_track_id','replacement_pending_download')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $trackId = (int)($settings['replacement_track_id'] ?? 0);
        $download = canonicalPath((string)($settings['replacement_pending_download'] ?? ''));
        if ($trackId < 1 || $download === '' || !is_file($download)) throw new RuntimeException('Nessuna sostituzione media da confermare.');
        $statement = $this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $statement->execute(['replacement_media_change_confirmed', $this->confirmationToken($trackId, $download)]);
        return ['ok' => true, 'confirmed' => true];
    }

    private function latestDownload(int $started, array $snapshot): ?string
    {
        $directory = $this->browserDownloadDirectory();
        $matches = [];
        foreach (new DirectoryIterator($directory) as $file) {
            if (!$file->isFile()) continue;
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::EXTENSIONS, true)) continue;
            if ($file->getSize() < 500000 || $file->getMTime() > time() - 3) continue;
            $path = canonicalPath($file->getPathname());
            $signature = $file->getSize() . '|' . $file->getMTime();
            if ($snapshot) {
                if (($snapshot[$path] ?? '') === $signature) continue;
            } elseif ($file->getMTime() < $started - 60) {
                continue;
            }
            $matches[] = $file->getPathname();
        }
        usort($matches, fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
        return $matches[0] ?? null;
    }

    private function downloadSnapshot(string $directory): array
    {
        $snapshot = [];
        if (!is_dir($directory)) return $snapshot;
        foreach (new DirectoryIterator($directory) as $file) {
            if (!$file->isFile()) continue;
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::EXTENSIONS, true)) continue;
            $snapshot[canonicalPath($file->getPathname())] = $file->getSize() . '|' . $file->getMTime();
        }
        return $snapshot;
    }

    private function decodeSnapshot(string $json): array
    {
        $data = $json !== '' ? json_decode($json, true) : [];
        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    private function browserDownloadDirectory(): string
    {
        $directory = canonicalPath((string)(getenv('USERPROFILE') ?: 'C:\\Users\\fabbr') . '\\Downloads');
        if ($directory === '' || !is_dir($directory)) throw new RuntimeException('Cartella Downloads non disponibile.');
        return $directory;
    }

    private function stagingDirectory(): string
    {
        $fallback = technicalAreaPath('01_INBOX\\Da_classificare');
        $directory = canonicalPath((string)setting('spotmate_download_folder', $fallback));
        if ($directory === '') $directory = $fallback;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cartella download SpotMate non disponibile: ' . $directory);
        }
        return $directory;
    }

    private function replace(int $trackId, string $download): array
    {
        $track = $this->library->find($trackId);
        if (!$track) throw new RuntimeException('Brano target non trovato.');
        $old = canonicalPath((string)$track['file_path']);
        if (!is_file($old)) throw new RuntimeException('File originale non piu presente.');
        $download = $this->stageDownload($download);
        $newExtension = strtolower(pathinfo($download, PATHINFO_EXTENSION));
        if (!in_array($newExtension, self::EXTENSIONS, true)) throw new RuntimeException('Download media non supportato.');
        $oldExtension = strtolower(pathinfo($old, PATHINFO_EXTENSION));

        if ($this->mediaFamily($oldExtension) !== $this->mediaFamily($newExtension) && !$this->isMediaChangeConfirmed($trackId, $download)) {
            $statement = $this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $statement->execute(['replacement_pending_download', canonicalPath($download)]);
            return [
                'pending' => true,
                'requires_confirmation' => true,
                'warning' => 'Cambio tipo media: stai sostituendo un file ' . $this->mediaFamily($oldExtension) . ' con un file ' . $this->mediaFamily($newExtension) . '.',
                'old_path' => $old,
                'download_path' => canonicalPath($download),
                'old_extension' => $oldExtension,
                'new_extension' => $newExtension,
            ];
        }

        $target = $oldExtension === $newExtension ? $old : dirname($old) . '\\' . pathinfo($old, PATHINFO_FILENAME) . '.' . $newExtension;
        if ($target !== $old && file_exists($target)) throw new RuntimeException('Esiste gia un file con il nuovo formato nella cartella originale.');
        $archive = technicalAreaPath('01_INBOX\\Sostituzioni');
        if (!is_dir($archive) && !mkdir($archive, 0777, true) && !is_dir($archive)) throw new RuntimeException('Impossibile creare Inbox/Sostituzioni.');
        $backup = $archive . '\\' . basename($old);
        if (file_exists($backup)) $backup = $archive . '\\' . pathinfo($old, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '.' . $oldExtension;
        if (!rename($old, $backup)) throw new RuntimeException('Impossibile archiviare il file precedente.');
        try {
            $this->moveVerified($download, $target);
        } catch (Throwable $error) {
            @rename($backup, $old);
            throw $error;
        }

        try {
            $audio = $this->inspectAudio($target);
            $taxonomy = trackTaxonomyFromPath($target);
            $statement = $this->pdo->prepare("UPDATE tracks SET file_path=?,file_name=?,folder=?,archive_area=?,macro_genre=?,folder_genre=?,file_size=?,bitrate=COALESCE(?,bitrate),duration=COALESCE(?,duration),file_exists=1,source='manual',updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $statement->execute([$target, basename($target), dirname($target), $taxonomy['archive_area'], $taxonomy['macro_genre'], $taxonomy['folder_genre'], (int)filesize($target), $audio['bitrate'], $audio['duration'], $trackId]);
            $this->clear();
        } catch (Throwable $error) {
            @rename($target, $download);
            @rename($backup, $old);
            throw $error;
        }

        $spotifyUpdated = false;
        $spotifyError = '';
        try {
            (new SpotifyAudioFeaturesService($this->pdo))->refreshReplacementMetadata($trackId);
            $spotifyUpdated = true;
        } catch (Throwable $error) {
            $spotifyError = $error->getMessage();
        }

        $updatedTrack = $this->library->find($trackId);
        $playlistUpdate = ['files' => 0, 'references' => 0];
        $playlistError = '';
        try {
            $playlistUpdate = (new PlaylistService())->replaceTrackReference($old, $target, (string)($updatedTrack['artist'] ?? ''), (string)($updatedTrack['title'] ?? ''));
        } catch (Throwable $error) {
            $playlistError = $error->getMessage();
        }

        return ['pending' => false, 'completed' => true, 'track' => $updatedTrack, 'installed' => $target, 'archived' => $backup, 'spotify_updated' => $spotifyUpdated, 'spotify_error' => $spotifyError, 'playlist_updated' => $playlistUpdate, 'playlist_error' => $playlistError];
    }

    private function mediaFamily(string $extension): string
    {
        return in_array(strtolower($extension), self::VIDEO_EXTENSIONS, true) ? 'video' : 'audio';
    }

    private function stageDownload(string $download): string
    {
        $source = canonicalPath($download);
        $staging = $this->stagingDirectory();
        if (str_starts_with(strtolower($source), strtolower($staging . '\\'))) return $source;
        $target = $staging . '\\' . basename($source);
        if (file_exists($target)) {
            $target = $staging . '\\' . pathinfo($source, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '.' . strtolower(pathinfo($source, PATHINFO_EXTENSION));
        }
        $this->moveVerified($source, $target);
        return $target;
    }

    private function confirmationToken(int $trackId, string $download): string
    {
        return sha1($trackId . '|' . canonicalPath($download) . '|' . (string)@filesize($download));
    }

    private function isMediaChangeConfirmed(int $trackId, string $download): bool
    {
        return setting('replacement_media_change_confirmed', '') === $this->confirmationToken($trackId, $download);
    }

    private function moveVerified(string $source, string $target): void
    {
        $size = (int)filesize($source);
        if (@rename($source, $target)) return;
        if (!@copy($source, $target) || !is_file($target) || (int)filesize($target) !== $size) {
            @unlink($target);
            throw new RuntimeException('Copia del nuovo media non riuscita.');
        }
        if (!@unlink($source)) {
            @unlink($target);
            throw new RuntimeException('Impossibile completare lo spostamento del download.');
        }
    }

    private function inspectAudio(string $path): array
    {
        $command = ['ffprobe','-v','error','-select_streams','a:0','-show_entries','stream=bit_rate:format=duration,bit_rate','-of','json',$path];
        $process = proc_open($command, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true]);
        $output = '';
        if (is_resource($process)) {
            $output = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $data = $output !== '' ? json_decode($output, true) : null;
        $format = (array)($data['format'] ?? []);
        $stream = (array)($data['streams'][0] ?? []);
        $rawBitrate = $stream['bit_rate'] ?? $format['bit_rate'] ?? null;
        $bitrate = $rawBitrate !== null ? (int)round(((float)$rawBitrate) / 1000) : null;
        $duration = isset($format['duration']) ? (int)round((float)$format['duration']) : null;
        return ['bitrate' => $bitrate && $bitrate > 0 ? $bitrate : null, 'duration' => $duration && $duration > 0 ? $duration : null];
    }

    private function clear(): void
    {
        $this->pdo->exec("DELETE FROM settings WHERE `key` IN ('replacement_track_id','replacement_started_at','replacement_download_snapshot','replacement_pending_download','replacement_media_change_confirmed')");
    }
}
