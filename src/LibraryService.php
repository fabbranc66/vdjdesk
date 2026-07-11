<?php
declare(strict_types=1);

final class LibraryService
{
    public function __construct(private PDO $pdo) {}

    public function search(array $filters): array
    {
        [$where, $params] = $this->searchConditions($filters);
        $limit = min(200, max(1, (int) ($filters['limit'] ?? 60)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $statement = $this->pdo->prepare(
            'SELECT tracks.*,EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id) AS vdj_linked FROM tracks
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY rating DESC, play_count DESC, artist, title
            LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($params);
        return array_map([$this, 'hydrate'], $statement->fetchAll());
    }

    public function searchCount(array $filters): int
    {
        [$where, $params] = $this->searchConditions($filters);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
            FROM tracks
            WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    private function searchConditions(array $filters): array
    {
        $where = ["file_exists = 1", "UPPER(file_path) LIKE 'E:%'"];
        $params = [];
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $tokens = $this->searchTokens($query);
            if ($tokens) {
                $parts = [];
                foreach ($tokens as $index => $token) {
                    $rawKey = ':qt' . $index;
                    $normKey = ':qtn' . $index;
                    $parts[] = "(artist LIKE $rawKey OR title LIKE $rawKey OR file_name LIKE $rawKey OR genre LIKE $rawKey OR tags LIKE $rawKey OR auto_tags LIKE $rawKey OR folder LIKE $rawKey OR CONCAT(normalized_artist,' ',normalized_title) LIKE $normKey)";
                    $params[$rawKey] = '%' . $token['raw'] . '%';
                    $params[$normKey] = '%' . $token['normalized'] . '%';
                }
                $where[] = '(' . implode(' AND ', $parts) . ')';
            } else {
                $where[] = '(artist LIKE :q OR title LIKE :q OR file_name LIKE :q OR genre LIKE :q OR tags LIKE :q OR auto_tags LIKE :q OR folder LIKE :q)';
                $params[':q'] = '%' . $query . '%';
            }
        }
        $artistFilter = trim((string) ($filters['artist'] ?? ''));
        $titleFilter = trim((string) ($filters['title'] ?? ''));
        if ($artistFilter !== '' || $titleFilter !== '') {
            if ($artistFilter !== '') {
                $where[] = 'normalized_artist = :artist_norm';
                $params[':artist_norm'] = normalizeText($artistFilter);
            }
            if ($titleFilter !== '') {
                $where[] = 'normalized_title = :title_norm';
                $params[':title_norm'] = normalizeTitle($titleFilter);
            }
        }
        foreach (['genre','camelot','folder'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "$field LIKE :$field";
                $params[":$field"] = '%' . $filters[$field] . '%';
            }
        }
        if (!empty($filters['musical_key'])) {
            $keyFilter=trim((string)$filters['musical_key']);
            if(preg_match('/^(?:[1-9]|1[0-2])[AB]$/i',$keyFilter)){
                $where[] = 'UPPER(camelot) = :key_filter';
                $params[':key_filter'] = strtoupper($keyFilter);
            }else{
                $where[] = 'musical_key LIKE :key_filter';
                $params[':key_filter'] = '%' . $keyFilter . '%';
            }
        }
        if (!empty($filters['folder_root'])) {
            $root = rtrim((string) $filters['folder_root'], '\\');
            $where[] = '(folder = :folder_root OR LEFT(folder,CHAR_LENGTH(:folder_prefix)) = :folder_prefix)';
            $params[':folder_root'] = $root;
            $params[':folder_prefix'] = $root . '\\';
        }
        if (!empty($filters['year'])) {
            $where[] = 'year = :year';
            $params[':year'] = (int) $filters['year'];
        }
        if (!empty($filters['bpm'])) {
            $range = (float) ($filters['bpm_range'] ?? setting('bpm_range', '8'));
            $where[] = 'bpm BETWEEN :bpm_min AND :bpm_max';
            $params[':bpm_min'] = (float) $filters['bpm'] - $range;
            $params[':bpm_max'] = (float) $filters['bpm'] + $range;
        }
        if (!empty($filters['quality'])) {
            $extension="LOWER(SUBSTRING_INDEX(file_name,'.',-1))";
            $audio="$extension IN ('mp3','m4a','aac','ogg','opus','wma','flac','wav','aiff','aif','alac')";
            $standard="(($extension='mp3' AND COALESCE(bitrate,0)>=320) OR $extension IN ('flac','wav','aiff','aif','alac'))";
            if($filters['quality']==='video')$where[]="$extension IN ('mp4','mkv','avi','mov','webm','m4v','wmv','mpeg','mpg')";
            elseif($filters['quality']==='standard')$where[]="($audio AND $standard)";
            else $where[]="($audio AND NOT $standard)";
        }
        if (($filters['spotify_metrics']??'') === 'loaded') {
            $where[] = "spotify_id<>'' AND spotify_features_updated_at IS NOT NULL AND spotify_features_status IN ('complete','partial')";
        }
        if (($filters['spotify_state']??'') === 'no_id') {
            $where[] = "COALESCE(spotify_id,'')=''";
        }
        if (($filters['spotify_state']??'') === 'missing_metrics') {
            $where[] = "COALESCE(spotify_id,'')<>'' AND (spotify_features_updated_at IS NULL OR spotify_features_status NOT IN ('complete','partial'))";
        }
        if (($filters['missing']??'') === 'genre') {
            $where[] = "TRIM(COALESCE(genre,''))=''";
        }
        if (($filters['missing']??'') === 'year') {
            $where[] = '(year IS NULL OR year=0)';
        }
        if (($filters['missing']??'') === 'key') {
            $where[] = "TRIM(COALESCE(camelot,''))='' AND TRIM(COALESCE(musical_key,''))=''";
        }
        return [$where, $params];
    }

    private function searchTokens(string $query): array
    {
        $raw = preg_split('/[^\\pL\\pN]+/u', trim($query)) ?: [];
        $tokens = [];
        foreach ($raw as $token) {
            $token = trim($token);
            if ($token === '') continue;
            $normalized = $this->normalizeSearchToken($token);
            if ($normalized === '' || strlen($normalized) < 2) continue;
            if (in_array($normalized, ['feat','ft','featuring'], true)) continue;
            $tokens[$normalized] = ['raw' => $token, 'normalized' => $normalized];
        }
        return array_values($tokens);
    }

    private function normalizeSearchToken(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = ['Ã '=>'a','Ã¡'=>'a','Ã¢'=>'a','Ã¤'=>'a','Ã¨'=>'e','Ã©'=>'e','Ãª'=>'e','Ã«'=>'e','Ã¬'=>'i','Ã­'=>'i','Ã®'=>'i','Ã¯'=>'i','Ã²'=>'o','Ã³'=>'o','Ã´'=>'o','Ã¶'=>'o','Ã¹'=>'u','Ãº'=>'u','Ã»'=>'u','Ã¼'=>'u','Ã±'=>'n','â€™'=>"'",'`'=>"'"];
        $value = strtr($value, $map);
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value);
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare("SELECT tracks.*,EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id) AS vdj_linked FROM tracks WHERE tracks.id = ? AND UPPER(file_path) LIKE 'E:%'");
        $statement->execute([$id]);
        $track = $statement->fetch();
        return $track ? $this->hydrate($track) : null;
    }

    public function hydrateTrack(array $track): array
    {
        return $this->hydrate($track);
    }

    public function duplicateGroupCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM tracks WHERE file_exists=1 AND UPPER(file_path) LIKE 'E:%' AND normalized_title <> '' GROUP BY normalized_artist, normalized_title HAVING COUNT(*) > 1) duplicate_groups")->fetchColumn();
    }

    public function duplicates(int $limit = 200): array
    {
        $limit = min(1000, max(1, $limit));
        $groups = $this->pdo->query("SELECT normalized_artist, normalized_title, COUNT(*) total FROM tracks WHERE file_exists=1 AND UPPER(file_path) LIKE 'E:%' AND normalized_title <> '' GROUP BY normalized_artist, normalized_title HAVING COUNT(*) > 1 ORDER BY total DESC LIMIT $limit")->fetchAll();
        $result = [];
        $fetch = $this->pdo->prepare("SELECT * FROM tracks WHERE file_exists=1 AND UPPER(file_path) LIKE 'E:%' AND normalized_artist = ? AND normalized_title = ? ORDER BY bitrate DESC, rating DESC, play_count DESC");
        foreach ($groups as $group) {
            $fetch->execute([$group['normalized_artist'], $group['normalized_title']]);
            $tracks = array_map([$this, 'hydrate'], $fetch->fetchAll());
            $result[] = [
                'fingerprint' => sha1($group['normalized_artist'] . '|' . $group['normalized_title']),
                'label' => trim($tracks[0]['artist'] . ' - ' . $tracks[0]['title']),
                'reason' => 'Stesso artista e titolo dopo la normalizzazione; confronta versione, qualità e percorso.',
                'recommended' => $tracks[0],
                'items' => $tracks,
            ];
        }
        return $result;
    }

    public function issues(): array
    {
        $checks = [
            'missing_metadata' => "genre = '' OR year IS NULL OR rating = 0",
            'missing_analysis' => 'bpm IS NULL OR bpm = 0 OR (musical_key = \'\' AND camelot = \'\')',
            'missing_files' => 'file_exists = 0',
            'low_quality' => '(bitrate IS NOT NULL AND bitrate < 192) OR (file_size IS NOT NULL AND file_size < 1000000)',
            'dirty_titles' => "lower(title) LIKE '%official%' OR lower(title) LIKE '%lyrics%' OR title = '' OR artist = ''",
        ];
        $result = [];
        foreach ($checks as $key => $condition) {
            $result[$key] = (int) $this->pdo->query("SELECT COUNT(*) FROM tracks WHERE UPPER(file_path) LIKE 'E:%' AND $condition")->fetchColumn();
        }
        return $result;
    }

    public function importVirtualDj(string $path, ?string $databasePath = null, ?string $syncToken = null): array
    {
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xml') {
            throw new RuntimeException('Database VirtualDJ XML non trovato o non valido.');
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (!$xml) {
            throw new RuntimeException('Impossibile leggere il database XML.');
        }
        $databasePath ??= $path;
        $syncToken ??= bin2hex(random_bytes(8));
        $upsert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO tracks(artist,title,normalized_artist,normalized_title,file_path,file_name,folder,genre,year,bpm,musical_key,camelot,duration,rating,play_count,last_played,tags,version,energy,singability,danceability,familiarity,risk,bitrate,file_size,file_exists,source,updated_at)
            VALUES(:artist,:title,:normalized_artist,:normalized_title,:file_path,:file_name,:folder,:genre,:year,:bpm,:musical_key,:camelot,:duration,:rating,:play_count,:last_played,:tags,:version,3,3,3,3,3,:bitrate,:file_size,:file_exists,'virtualdj',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE artist=VALUES(artist),title=VALUES(title),normalized_artist=VALUES(normalized_artist),normalized_title=VALUES(normalized_title),
                genre=CASE WHEN tracks.genre_manual=1 THEN tracks.genre WHEN tracks.metadata_source='sortlee' AND tracks.genre<>'' THEN tracks.genre WHEN VALUES(genre)<>'' THEN VALUES(genre) ELSE tracks.genre END,
                year=CASE WHEN tracks.year_manual=1 THEN tracks.year WHEN tracks.release_date REGEXP '^[0-9]{4}' THEN CAST(SUBSTRING(tracks.release_date,1,4) AS UNSIGNED) ELSE COALESCE(VALUES(year),tracks.year) END,
                bpm=CASE WHEN tracks.metadata_source='sortlee' AND tracks.bpm IS NOT NULL THEN tracks.bpm ELSE COALESCE(VALUES(bpm),tracks.bpm) END,
                musical_key=CASE WHEN tracks.metadata_source='sortlee' AND tracks.musical_key<>'' THEN tracks.musical_key WHEN VALUES(musical_key)<>'' THEN VALUES(musical_key) ELSE tracks.musical_key END,
                camelot=CASE WHEN tracks.metadata_source='sortlee' AND tracks.camelot<>'' THEN tracks.camelot WHEN VALUES(camelot)<>'' THEN VALUES(camelot) ELSE tracks.camelot END,
                duration=CASE WHEN tracks.source='manual' THEN tracks.duration ELSE COALESCE(VALUES(duration),tracks.duration) END,rating=VALUES(rating),play_count=VALUES(play_count),
                last_played=VALUES(last_played),bitrate=CASE WHEN tracks.source='manual' THEN tracks.bitrate ELSE COALESCE(VALUES(bitrate),tracks.bitrate) END,file_size=CASE WHEN tracks.source='manual' THEN tracks.file_size ELSE VALUES(file_size) END,
                file_exists=VALUES(file_exists),updated_at=CURRENT_TIMESTAMP
        SQL);
        $trackId = $this->pdo->prepare('SELECT id FROM tracks WHERE file_path = ?');
        $source = $this->pdo->prepare(<<<'SQL'
            INSERT INTO track_sources(track_id,database_path,source_file_path,sync_token,last_seen_at)
            VALUES(?,?,?,?,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                track_id=VALUES(track_id),sync_token=VALUES(sync_token),last_seen_at=CURRENT_TIMESTAMP
        SQL);
        $count = 0;
        $relinked = 0;
        foreach ($xml->Song as $song) {
            $sourceFilePath = (string) $song['FilePath'];
            if ($sourceFilePath === '') continue;
            $filePath = $this->resolvePathForDatabase($sourceFilePath, $databasePath);
            $tags = $song->Tags;
            $infos = $song->Infos;
            $fileName = basename(str_replace('\\', '/', $filePath));
            $fallback = $this->artistTitleFromFilename($fileName);
            $artist = trim((string) ($tags['Author'] ?? $tags['Artist'] ?? '')) ?: $fallback['artist'];
            $title = trim((string) ($tags['Title'] ?? '')) ?: $fallback['title'];
            $rawBpm = (float) ($tags['Bpm'] ?? $song->Scan['Bpm'] ?? 0);
            $bpm = $rawBpm > 0 && $rawBpm < 2 ? round(60 / $rawBpm, 1) : ($rawBpm ?: null);
            $key = (string) ($tags['Key'] ?? $song->Scan['Key'] ?? '');
            $lastPlay = (int) ($infos['LastPlay'] ?? 0);
            $duration = (int) round((float) ($infos['SongLength'] ?? 0)) ?: null;
            $normalizedArtist = normalizeText($artist);
            $normalizedTitle = normalizeTitle($title);
            $relinked += $this->relinkMovedVirtualDjTrack($filePath, $fileName, dirname($filePath), $normalizedArtist, $normalizedTitle, $duration);
            $upsert->execute([
                ':artist'=>$artist, ':title'=>$title, ':normalized_artist'=>$normalizedArtist, ':normalized_title'=>$normalizedTitle,
                ':file_path'=>$filePath, ':file_name'=>$fileName, ':folder'=>dirname($filePath), ':genre'=>(string) ($tags['Genre'] ?? ''),
                ':year'=>(int) ($tags['Year'] ?? 0) ?: null, ':bpm'=>$bpm, ':musical_key'=>$key, ':camelot'=>$this->toCamelot($key),
                ':duration'=>$duration, ':rating'=>(int) ($tags['Stars'] ?? 0),
                ':play_count'=>(int) ($infos['PlayCount'] ?? 0), ':last_played'=>$lastPlay ? date('Y-m-d H:i:s', $lastPlay) : null,
                ':tags'=>'[]', ':version'=>$this->detectVersion($title . ' ' . $fileName), ':bitrate'=>(int) ($infos['Bitrate'] ?? 0) ?: null,
                ':file_size'=>(int) ($song['FileSize'] ?? 0) ?: null, ':file_exists'=>$this->isLocalFile($filePath) ? 1 : 0,
            ]);
            $trackId->execute([$filePath]);
            $id = (int) $trackId->fetchColumn();
            if ($id > 0) $source->execute([$id, $databasePath, $sourceFilePath, $syncToken]);
            $count++;
        }
        $this->pdo->prepare('DELETE FROM track_sources WHERE database_path = ? AND sync_token <> ?')->execute([$databasePath, $syncToken]);
        return ['imported' => $count, 'source' => $path, 'read_only' => true, 'sync_token' => $syncToken, 'relinked' => $relinked];
    }

    public function syncAllVirtualDjDatabases(bool $force = false): array
    {
        $lock = fopen(APP_ROOT . '/storage/virtualdj-sync.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Una sincronizzazione VirtualDJ è già in corso.');
        }
        try {
            foreach ($this->ignoredVirtualDjDrives() as $drive) {
                $statement = $this->pdo->prepare("DELETE FROM library_databases WHERE drive_letter = ? AND label LIKE 'Database drive %'");
                $statement->execute([$drive]);
            }
            $databases = $this->discoverVirtualDjDatabases();
            $results = [];
            foreach ($databases as $database) {
                $path = $database['path'];
                clearstatcache(true, $path);
                $modified = (int) filemtime($path);
                $size = (int) filesize($path);
                $known = $this->databaseState($path);
                $changed = $force || !$known || (int) $known['imported_modified_at'] !== $modified || (int) $known['imported_size'] !== $size;
                $this->saveDatabaseState($database, $modified, $size, $known, $changed ? 'syncing' : 'unchanged');
                if (!$changed) {
                    $results[] = ['path'=>$path,'label'=>$database['label'],'status'=>'unchanged','records'=>(int)($known['record_count'] ?? 0),'modified_at'=>date('Y-m-d H:i:s',$modified)];
                    continue;
                }
                try {
                    $this->pdo->beginTransaction();
                    $result = $this->importVirtualDj($path, $path, bin2hex(random_bytes(12)));
                    $statement = $this->pdo->prepare("UPDATE library_databases SET imported_modified_at=?,imported_size=?,record_count=?,status='synced',message='',last_synced_at=CURRENT_TIMESTAMP WHERE path=?");
                    $statement->execute([$modified,$size,$result['imported'],$path]);
                    $this->pdo->commit();
                    $results[] = ['path'=>$path,'label'=>$database['label'],'status'=>'synced','records'=>$result['imported'],'relinked'=>(int)($result['relinked'] ?? 0),'modified_at'=>date('Y-m-d H:i:s',$modified)];
                } catch (Throwable $error) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    $statement = $this->pdo->prepare("UPDATE library_databases SET status='error',message=? WHERE path=?");
                    $statement->execute([$error->getMessage(),$path]);
                    $results[] = ['path'=>$path,'label'=>$database['label'],'status'=>'error','error'=>$error->getMessage(),'modified_at'=>date('Y-m-d H:i:s',$modified)];
                }
            }
            $this->pdo->exec("DELETE FROM tracks WHERE source='virtualdj' AND NOT EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id)");
            $changed = count(array_filter($results, fn($item) => $item['status'] === 'synced'));
            $removed = $changed > 0 ? $this->removeMissingLocalTracks() : 0;
            return ['databases'=>$results,'changed'=>$changed,'removed'=>$removed,'total'=>count($results)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function reconcileVirtualDjDatabases(): array
    {
        $before=(int)$this->pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
        $sync=$this->syncAllVirtualDjDatabases(true);
        $orphans=(int)$this->pdo->query("SELECT COUNT(*) FROM tracks t WHERE t.source='virtualdj' AND NOT EXISTS(SELECT 1 FROM track_sources s WHERE s.track_id=t.id)")->fetchColumn();
        $this->pdo->exec("DELETE FROM tracks WHERE source='virtualdj' AND NOT EXISTS(SELECT 1 FROM track_sources s WHERE s.track_id=tracks.id)");
        $after=(int)$this->pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
        $linked=(int)$this->pdo->query('SELECT COUNT(DISTINCT track_id) FROM track_sources')->fetchColumn();
        return ['ok'=>true,'before'=>$before,'after'=>$after,'removed'=>$orphans,'linked'=>$linked,'sync'=>$sync];
    }

    public function virtualDjDatabaseStatus(): array
    {
        $discovered = $this->discoverVirtualDjDatabases();
        foreach ($discovered as $database) {
            clearstatcache(true, $database['path']);
            $known = $this->databaseState($database['path']);
            $this->saveDatabaseState($database, (int) filemtime($database['path']), (int) filesize($database['path']), $known, $known['status'] ?? 'pending');
        }
        return $this->pdo->query("SELECT *,FROM_UNIXTIME(file_modified_at) file_modified_label,FROM_UNIXTIME(NULLIF(imported_modified_at,0)) imported_modified_label FROM library_databases ORDER BY CASE WHEN label='Principale AppData' THEN 0 ELSE 1 END,drive_letter")->fetchAll();
    }

    public function musicRootOptions(): array
    {
        return array_map(static function (array $item): array {
            $item['label'] = $item['tree_label'] ?? $item['label'];
            return $item;
        }, $this->definitiveLibraryFolderOptions());
    }

    public function comparisonFolderOptions(): array
    {
        $this->refreshTrackedFileExistence();
        $root = canonicalPath('E:\\LIBRERIA_DEFINITIVA');
        $roots = [['path'=>$root,'label'=>'Tutta Libreria Definitiva','root'=>$root,'depth'=>0]];
        $folders = $this->pdo->query(<<<'SQL'
            SELECT folder, COUNT(DISTINCT file_path) AS track_count
            FROM tracks
            WHERE file_exists = 1 AND UPPER(file_path) LIKE 'E:%'
            GROUP BY folder
            ORDER BY folder
        SQL)->fetchAll();
        $items = [];
        $seen = [];
        foreach ($roots as $root) {
            $rootPath = rtrim((string) $root['path'], '\\');
            $key = strtolower($rootPath);
            $seen[$key] = true;
            $items[] = [
                'path' => $rootPath,
                'label' => (string) $root['label'],
                'root' => $rootPath,
                'depth' => 0,
            ];
            $prefix = strtolower($rootPath . '\\');
            $childCounts = [];
            foreach ($folders as $folder) {
                $folderPath = rtrim((string) $folder['folder'], '\\');
                if (!str_starts_with(strtolower($folderPath), $prefix) || !is_dir($folderPath)) continue;
                $relative = substr($folderPath, strlen($rootPath) + 1);
                $parts = array_values(array_filter(explode('\\', $relative), fn(string $part) => $part !== ''));
                $current = $rootPath;
                foreach ($parts as $part) {
                    $current .= '\\' . $part;
                    $currentKey = strtolower($current);
                    $childCounts[$currentKey] ??= ['path' => $current, 'count' => 0];
                    $childCounts[$currentKey]['count'] += (int) $folder['track_count'];
                }
            }
            uasort($childCounts, fn(array $a, array $b) => strnatcasecmp($a['path'], $b['path']));
            foreach ($childCounts as $folderKey => $folder) {
                if (isset($seen[$folderKey])) continue;
                $folderPath = $folder['path'];
                $relative = substr($folderPath, strlen($rootPath) + 1);
                $items[] = [
                    'path' => $folderPath,
                    'label' => $relative . ' (' . number_format((int) $folder['count'], 0, ',', '.') . ' brani)',
                    'root' => $rootPath,
                    'depth' => substr_count($relative, '\\') + 1,
                ];
                $seen[$folderKey] = true;
            }
        }
        return $items;
    }

    public function definitiveLibraryFolderOptions(): array
    {
        $this->refreshTrackedFileExistence();
        $root = canonicalPath('E:\\LIBRERIA_DEFINITIVA');
        $prefix = strtolower($root . '\\');
        $folders = $this->pdo->query(<<<'SQL'
            SELECT folder, COUNT(DISTINCT file_path) AS track_count
            FROM tracks
            WHERE file_exists = 1 AND UPPER(file_path) LIKE 'E:%'
            GROUP BY folder
            ORDER BY folder
        SQL)->fetchAll();
        $counts = [$root => 0];
        foreach ($folders as $folder) {
            $folderPath = rtrim(canonicalPath((string) $folder['folder']), '\\');
            if (!str_starts_with(strtolower($folderPath), $prefix) || !is_dir($folderPath)) continue;
            if (preg_match('/\\\\PLAYLIST(\\\\|$)/i', $folderPath)) continue;
            $count = (int) $folder['track_count'];
            $counts[$root] += $count;
            $relative = substr($folderPath, strlen($root) + 1);
            $parts = array_values(array_filter(explode('\\', $relative), fn(string $part): bool => $part !== ''));
            $current = $root;
            foreach ($parts as $part) {
                $current .= '\\' . $part;
                $counts[$current] = ($counts[$current] ?? 0) + $count;
            }
        }
        $items = [];
        foreach ($counts as $path => $count) {
            if ($count < 1 || !is_dir($path)) continue;
            $relative = $path === $root ? 'Tutta Libreria Definitiva' : substr($path, strlen($root) + 1);
            $depth = $path === $root ? 0 : substr_count($relative, '\\') + 1;
            $name = $path === $root ? 'Tutta Libreria Definitiva' : basename($path);
            $items[] = [
                'path' => $path,
                'label' => $relative . ' (' . number_format($count, 0, ',', '.') . ' brani)',
                'tree_label' => ($depth ? str_repeat('↳ ', min($depth, 4)) : '') . $name . ' (' . number_format($count, 0, ',', '.') . ' brani)',
                'root' => $root,
                'depth' => $depth,
                'sort_key' => $path === $root ? '' : str_replace('\\', "\n", $relative),
                'count' => $count,
            ];
        }
        usort($items, fn(array $a,array $b): int => strnatcasecmp((string)$a['sort_key'], (string)$b['sort_key']));
        return $items;
    }

    public function importMetadataFromVirtualDj(array $trackIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$trackIds),fn(int $id): bool=>$id>0)));
        if(!$ids)throw new RuntimeException('Nessun brano visibile da aggiornare.');
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $statement=$this->pdo->prepare("SELECT t.id,t.file_path,s.database_path,s.source_file_path FROM tracks t JOIN track_sources s ON s.track_id=t.id WHERE t.id IN ($placeholders) ORDER BY t.id,CASE WHEN s.database_path=? THEN 0 ELSE 1 END,s.last_seen_at DESC");
        $statement->execute([...$ids,(string)setting('vdj_database','')]);$links=$statement->fetchAll();
        $databases=[];foreach($links as $link)$databases[(string)$link['database_path']]=true;
        $metadataByDatabase=[];
        foreach(array_keys($databases) as $databasePath){
            if(!is_file($databasePath))continue;$xml=@simplexml_load_file($databasePath);if(!$xml)continue;$map=[];
            foreach($xml->Song as $song){$year=(int)($song->Tags['Year']??0);$genre=trim((string)($song->Tags['Genre']??''));if($year<1000||$year>2100)$year=null;if($year===null&&$genre==='')continue;$source=canonicalPath((string)$song['FilePath']);if($source!=='')$map[strtolower($source)]=['year'=>$year,'genre'=>$genre];}
            $metadataByDatabase[$databasePath]=$map;
        }
        $found=[];foreach($links as $link){$id=(int)$link['id'];if(isset($found[$id]))continue;$database=(string)$link['database_path'];$source=canonicalPath((string)$link['source_file_path']);$metadata=$metadataByDatabase[$database][strtolower($source)]??null;if($metadata)$found[$id]=$metadata;}
        $update=$this->pdo->prepare("UPDATE tracks SET year=COALESCE(?,year),year_manual=IF(? IS NULL,year_manual,1),genre=IF(?='',genre,?),genre_manual=IF(?='',genre_manual,1),updated_at=CURRENT_TIMESTAMP WHERE id=?");$this->pdo->beginTransaction();
        try{foreach($found as $id=>$metadata){$year=$metadata['year'];$genre=$metadata['genre'];$update->execute([$year,$year,$genre,$genre,$genre,$id]);}$this->pdo->commit();}catch(Throwable $error){$this->pdo->rollBack();throw $error;}
        $items=[];foreach($found as $id=>$metadata)$items[]=['id'=>(int)$id,'year'=>$metadata['year'],'genre'=>$metadata['genre']];
        return ['ok'=>true,'requested'=>count($ids),'updated'=>count($found),'missing'=>count($ids)-count($found),'items'=>$items];
    }

    private function refreshTrackedFileExistence(): void
    {
        $rows = $this->pdo->query("SELECT id,file_path,file_exists FROM tracks WHERE UPPER(file_path) LIKE 'E:%'")->fetchAll();
        $update = $this->pdo->prepare('UPDATE tracks SET file_exists = ? WHERE id = ?');
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $exists = is_file((string) $row['file_path']) ? 1 : 0;
                if ($exists !== (int) $row['file_exists']) {
                    $update->execute([$exists, (int) $row['id']]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function relinkMovedVirtualDjTrack(string $filePath, string $fileName, string $folder, string $normalizedArtist, string $normalizedTitle, ?int $duration): int
    {
        if ($normalizedTitle === '') return 0;
        $exists = $this->pdo->prepare('SELECT id FROM tracks WHERE file_path = ? LIMIT 1');
        $exists->execute([$filePath]);
        if ((int)$exists->fetchColumn() > 0) return 0;

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $family = $this->mediaFamily($extension);
        $durationCondition = $duration ? 'AND (duration IS NULL OR ABS(COALESCE(duration,0) - :duration) <= 3)' : '';
        $statement = $this->pdo->prepare(<<<SQL
            SELECT id,file_path,file_name,duration,spotify_id,metadata_source
            FROM tracks
            WHERE normalized_artist = :artist
              AND normalized_title = :title
              AND file_path <> :file_path
              $durationCondition
            ORDER BY
              CASE WHEN spotify_id <> '' THEN 0 ELSE 1 END,
              CASE WHEN metadata_source <> '' THEN 0 ELSE 1 END,
              updated_at DESC
            LIMIT 8
        SQL);
        $params = [':artist'=>$normalizedArtist, ':title'=>$normalizedTitle, ':file_path'=>$filePath];
        if ($duration) $params[':duration'] = $duration;
        $statement->execute($params);
        $candidates = array_values(array_filter($statement->fetchAll(), function(array $row) use ($family): bool {
            if ($family !== $this->mediaFamily(strtolower(pathinfo((string)$row['file_name'], PATHINFO_EXTENSION)))) return false;
            return !is_file((string)$row['file_path']);
        }));
        if (count($candidates) !== 1) return 0;

        $update = $this->pdo->prepare(<<<'SQL'
            UPDATE tracks
            SET file_path = ?, file_name = ?, folder = ?, file_exists = ?, source = 'virtualdj', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        SQL);
        $update->execute([$filePath, $fileName, $folder, $this->isLocalFile($filePath) ? 1 : 0, (int)$candidates[0]['id']]);
        return $update->rowCount() > 0 ? 1 : 0;
    }

    private function mediaFamily(string $extension): string
    {
        return in_array($extension, ['mp4','mkv','avi','mov','webm','m4v','wmv','mpeg','mpg'], true) ? 'video' : 'audio';
    }

    private function removeMissingLocalTracks(): int
    {
        $rows = $this->pdo->query("SELECT id,file_path FROM tracks WHERE source='virtualdj' AND UPPER(file_path) LIKE 'E:%'")->fetchAll();
        $delete = $this->pdo->prepare('DELETE FROM tracks WHERE id=?');
        $removed = 0;
        foreach ($rows as $row) {
            $path = (string) $row['file_path'];
            if (!preg_match('/^E:\\\\/i', $path)) continue;
            $drive = strtoupper($path[0]) . ':\\';
            if (!is_dir($drive) || is_file($path)) continue;
            $delete->execute([(int) $row['id']]);
            $removed += $delete->rowCount();
        }
        return $removed;
    }

    private function discoverVirtualDjDatabases(): array
    {
        $paths = [];
        $configured = setting('vdj_database', '');
        if ($configured && is_file($configured)) {
            $paths[strtolower($configured)] = ['path'=>$configured,'label'=>'Principale AppData','drive_letter'=>strtoupper(substr($configured,0,1))];
        }
        foreach (range('C', 'Z') as $letter) {
            if (in_array($letter, $this->ignoredVirtualDjDrives(), true)) continue;
            $path = $letter . ':\\VirtualDJ\\database.xml';
            if (is_file($path)) $paths[strtolower($path)] = ['path'=>$path,'label'=>'Database drive ' . $letter . ':','drive_letter'=>$letter];
        }
        return array_values($paths);
    }

    private function ignoredVirtualDjDrives(): array
    {
        return ['D', 'F'];
    }

    private function databaseState(string $path): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM library_databases WHERE path = ?');
        $statement->execute([$path]);
        return $statement->fetch() ?: null;
    }

    private function saveDatabaseState(array $database, int $modified, int $size, ?array $known, string $status): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO library_databases(path,label,drive_letter,file_modified_at,file_size,status)
            VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE label=VALUES(label),drive_letter=VALUES(drive_letter),
                file_modified_at=VALUES(file_modified_at),file_size=VALUES(file_size),status=VALUES(status)
        SQL);
        $statement->execute([$database['path'],$database['label'],$database['drive_letter'],$modified,$size,$status]);
    }

    public function importM3u(string $path): array
    {
        if (!is_file($path)) throw new RuntimeException('Playlist M3U non trovata.');
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $added = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^[A-Za-z]:\\\\/', $line)) $line = realpath(dirname($path) . DIRECTORY_SEPARATOR . $line) ?: $line;
            $added += $this->addFile($line) ? 1 : 0;
        }
        return ['imported' => $added, 'source' => $path];
    }

    public function scan(string $root): array
    {
        if (!is_dir($root)) throw new RuntimeException('Cartella musica non trovata.');
        $allowed = ['mp3','wav','flac','m4a','aac','ogg','mp4','mkv'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $found = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $allowed, true)) continue;
            $found += $this->addFile($file->getPathname()) ? 1 : 0;
        }
        return ['scanned' => $found, 'source' => $root];
    }

    private function addFile(string $path): bool
    {
        if (!is_file($path)) return false;
        $fileName = basename($path);
        $parsed = $this->artistTitleFromFilename($fileName);
        $statement = $this->pdo->prepare('INSERT IGNORE INTO tracks(artist,title,normalized_artist,normalized_title,file_path,file_name,folder,file_size,file_exists,source,version,tags) VALUES(?,?,?,?,?,?,?,?,1,?,?,\'[]\')');
        $statement->execute([$parsed['artist'],$parsed['title'],normalizeText($parsed['artist']),normalizeTitle($parsed['title']),$path,$fileName,dirname($path),filesize($path),'scan',$this->detectVersion($fileName)]);
        return $statement->rowCount() > 0;
    }

    private function isLocalFile(string $path): bool
    {
        return !preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) && is_file($path);
    }

    private function resolvePathForDatabase(string $filePath, string $databasePath): string
    {
        if ($this->isLocalFile($filePath)) return $filePath;
        if (preg_match('/^([A-Z]):\\\\VirtualDJ\\\\database\.xml$/i', $databasePath, $databaseDrive)
            && preg_match('/^[A-Z]:\\\\(.*)$/i', $filePath, $relative)) {
            $candidate = strtoupper($databaseDrive[1]) . ':\\' . $relative[1];
            if ($this->isLocalFile($candidate)) return $candidate;
        }
        if (str_starts_with($filePath, 'C:\\Utenti\\')) {
            $candidate = 'C:\\Users\\' . substr($filePath, 10);
            if ($this->isLocalFile($candidate)) return $candidate;
        }
        return $filePath;
    }

    private function artistTitleFromFilename(string $fileName): array
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $name, 2);
        return count($parts) === 2 ? ['artist'=>trim($parts[0]),'title'=>trim($parts[1])] : ['artist'=>'','title'=>trim($name)];
    }

    private function detectVersion(string $value): string
    {
        foreach (['Extended Mix','Radio Edit','Clean','Explicit','Intro','Remix','Mashup','Acapella','Instrumental'] as $version) {
            if (stripos($value, $version) !== false) return $version;
        }
        return '';
    }

    private function toCamelot(string $key): string
    {
        $map = ['Abm'=>'1A','G#m'=>'1A','Ebm'=>'2A','D#m'=>'2A','Bbm'=>'3A','A#m'=>'3A','Fm'=>'4A','Cm'=>'5A','Gm'=>'6A','Dm'=>'7A','Am'=>'8A','Em'=>'9A','Bm'=>'10A','F#m'=>'11A','Gbm'=>'11A','C#m'=>'12A','Dbm'=>'12A','B'=>'1B','F#'=>'2B','Gb'=>'2B','Db'=>'3B','C#'=>'3B','Ab'=>'4B','G#'=>'4B','Eb'=>'5B','D#'=>'5B','Bb'=>'6B','A#'=>'6B','F'=>'7B','C'=>'8B','G'=>'9B','D'=>'10B','A'=>'11B','E'=>'12B'];
        return $map[trim($key)] ?? '';
    }

    private function hydrate(array $track): array
    {
        $track['tags'] = trackTags($track);
        $track['auto_tags'] = autoTrackTags($track);
        $track['auto_tag_overrides'] = autoTagOverrides($track);
        foreach (['id','year','duration','rating','play_count','energy','singability','danceability','familiarity','risk','bitrate','file_size','file_exists','vdj_linked'] as $key) {
            $track[$key] = $track[$key] === null ? null : (int) $track[$key];
        }
        $track['bpm'] = $track['bpm'] === null ? null : (float) $track['bpm'];
        return $track;
    }
}
