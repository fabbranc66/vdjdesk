<?php
declare(strict_types=1);

final class EDuplicateService
{
    private const ROOT = 'E:\\';
    private const AUDIO_EXTENSIONS = ['mp3','m4a','flac','wav','ogg','opus','aac','mp4','mkv','vob'];
    private const EXCLUDED_ROOTS = ['$RECYCLE.BIN','System Volume Information','VirtualDJ'];
    private const DELETION_FOLDER = 'E:\\LIBRERIA_DEFINITIVA\\01_INBOX\\Da_cancellare';

    public function __construct(private PDO $pdo) {}

    public function scan(): array
    {
        if (!is_dir(self::ROOT)) throw new RuntimeException('Drive E: non disponibile.');
        $lock = fopen(APP_ROOT . '/storage/e-duplicate-scan.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Un controllo doppioni E: è già in corso.');
        $this->pdo->exec("UPDATE e_duplicate_scans SET status='aborted',completed_at=CURRENT_TIMESTAMP,message='Scansione interrotta' WHERE status='running'");
        $statement = $this->pdo->prepare('INSERT INTO e_duplicate_scans(root_path) VALUES(?)');
        $statement->execute([self::ROOT]);
        $scanId = (int) $this->pdo->lastInsertId();
        try {
            $count = $this->inventory($scanId);
            $exact = $this->createExactGroups($scanId);
            $normalized = $this->createNormalizedGroups($scanId);
            $finish = $this->pdo->prepare("UPDATE e_duplicate_scans SET status='completed',completed_at=CURRENT_TIMESTAMP,files_scanned=?,exact_groups=?,normalized_groups=? WHERE id=?");
            $finish->execute([$count,$exact,$normalized,$scanId]);
            $this->pdo->exec('DELETE FROM e_duplicate_scans WHERE id NOT IN (SELECT id FROM (SELECT id FROM e_duplicate_scans ORDER BY id DESC LIMIT 5) recent_scans)');
            return $this->summary($scanId);
        } catch (Throwable $error) {
            $statement = $this->pdo->prepare("UPDATE e_duplicate_scans SET status='error',message=?,completed_at=CURRENT_TIMESTAMP WHERE id=?");
            $statement->execute([$error->getMessage(),$scanId]);
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function latest(): ?array
    {
        $id = $this->pdo->query('SELECT id FROM e_duplicate_scans ORDER BY id DESC LIMIT 1')->fetchColumn();
        return $id ? $this->summary((int) $id) : null;
    }

    public function latestCompleted(): ?array
    {
        $id = $this->pdo->query("SELECT id FROM e_duplicate_scans WHERE status='completed' ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $id ? $this->summary((int) $id) : null;
    }

    public function refreshRecommendations(): int
    {
        $scan=$this->latestCompleted();if(!$scan)return 0;$scanId=(int)$scan['id'];
        $sync=$this->pdo->prepare("UPDATE e_file_inventory i LEFT JOIN tracks t ON t.file_path=i.file_path SET i.genre=COALESCE(t.genre,''),i.has_spotify=IF(COALESCE(t.spotify_id,'')<>'',1,0),i.spotify_complete=IF(t.spotify_features_status='complete',1,0),i.bitrate=COALESCE(t.bitrate,i.bitrate) WHERE i.scan_id=?");$sync->execute([$scanId]);
        $groups=$this->pdo->prepare('SELECT id FROM e_duplicate_groups WHERE scan_id=?');$groups->execute([$scanId]);
        $items=$this->pdo->prepare('SELECT f.* FROM e_duplicate_group_items gi JOIN e_file_inventory f ON f.id=gi.file_id WHERE gi.group_id=?');
        $setGroup=$this->pdo->prepare('UPDATE e_duplicate_groups SET recommended_file_id=? WHERE id=?');
        $clear=$this->pdo->prepare('UPDATE e_duplicate_group_items SET is_recommended=0 WHERE group_id=?');
        $setItem=$this->pdo->prepare('UPDATE e_duplicate_group_items SET is_recommended=1 WHERE group_id=? AND file_id=?');
        $count=0;$this->pdo->beginTransaction();
        foreach($groups->fetchAll(PDO::FETCH_COLUMN) as $groupId){$items->execute([$groupId]);$members=$items->fetchAll();if(!$members)continue;usort($members,fn(array $a,array $b)=>$this->qualityScore($b)<=>$this->qualityScore($a));$recommended=$members[0];$setGroup->execute([$recommended['id'],$groupId]);$clear->execute([$groupId]);$setItem->execute([$groupId,$recommended['id']]);$count++;}
        $this->pdo->commit();return $count;
    }

    public function groups(string $type = 'all', int $limit = 100, int $offset = 0, string $folderRoot = ''): array
    {
        $scan = $this->latest();
        if (!$scan) return ['items'=>[],'total'=>0,'scan'=>null];
        $where = 'scan_id = :scan_id';
        $params = [':scan_id'=>$scan['id']];
        if (in_array($type, ['exact','normalized'], true)) {
            $where .= ' AND type = :type';
            $params[':type'] = $type;
        }
        if ($folderRoot !== '') {
            $root = rtrim($folderRoot, '\\');
            $where .= ' AND EXISTS(SELECT 1 FROM e_duplicate_group_items gi JOIN e_file_inventory fi ON fi.id=gi.file_id WHERE gi.group_id=e_duplicate_groups.id AND (fi.folder=:folder_root OR fi.folder LIKE :folder_children))';
            $params[':folder_root'] = $root;
            $params[':folder_children'] = $root . '\\%';
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM e_duplicate_groups WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $limit = min(200,max(1,$limit));
        $offset = max(0,$offset);
        $query = $this->pdo->prepare("SELECT * FROM e_duplicate_groups WHERE $where ORDER BY CASE type WHEN 'exact' THEN 0 ELSE 1 END,confidence DESC,label LIMIT $limit OFFSET $offset");
        $query->execute($params);
        $groups = $query->fetchAll();
        $items = $this->pdo->prepare('SELECT f.*,i.is_recommended FROM e_duplicate_group_items i JOIN e_file_inventory f ON f.id=i.file_id WHERE i.group_id=? ORDER BY i.is_recommended DESC,f.file_size DESC');
        foreach ($groups as &$group) {
            $items->execute([$group['id']]);
            $group['items'] = $items->fetchAll();
        }
        return ['items'=>$groups,'total'=>$total,'scan'=>$scan,'has_more'=>$offset+count($groups)<$total];
    }

    public function decide(int $groupId, string $decision): void
    {
        if (!in_array($decision, ['keep_recommended','ignore','reviewed'], true)) throw new RuntimeException('Decisione non valida.');
        $statement = $this->pdo->prepare('UPDATE e_duplicate_groups SET decision=? WHERE id=?');
        $statement->execute([$decision,$groupId]);
    }

    public function markNonRecommended(string $folderRoot): int
    {
        if ($folderRoot === '' || !str_starts_with(strtoupper($folderRoot), 'E:\\')) throw new RuntimeException('Seleziona una cartella del drive E:.');
        $scan = $this->latest();
        if (!$scan) throw new RuntimeException('Esegui prima il controllo doppioni E:.');
        $root = rtrim($folderRoot, '\\');
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT g.type,g.confidence,g.reason,source.file_path source_path,source.folder source_folder,
                   source.file_name source_name,source.file_size source_size,
                   keep.file_path e_file_path,keep.file_name e_file_name,keep.file_size e_file_size
            FROM e_duplicate_groups g
            JOIN e_duplicate_group_items source_link ON source_link.group_id=g.id AND source_link.is_recommended=0
            JOIN e_file_inventory source ON source.id=source_link.file_id
            JOIN e_file_inventory keep ON keep.id=g.recommended_file_id
            WHERE g.scan_id=? AND (source.folder=? OR source.folder LIKE ?)
        SQL);
        $statement->execute([(int)$scan['id'],$root,$root.'\\%']);
        $upsert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO deletion_candidates(source_path,source_folder,source_name,source_size,e_file_path,e_file_name,e_file_size,match_type,confidence,reason,status)
            VALUES(?,?,?,?,?,?,?,?,?,?,'marked')
            ON DUPLICATE KEY UPDATE source_folder=VALUES(source_folder),source_name=VALUES(source_name),source_size=VALUES(source_size),
                e_file_path=VALUES(e_file_path),e_file_name=VALUES(e_file_name),
                e_file_size=VALUES(e_file_size),match_type=VALUES(match_type),confidence=VALUES(confidence),
                reason=VALUES(reason),last_seen_at=CURRENT_TIMESTAMP,
                status='marked',decision_note='',approved_at=NULL,moved_to_path=NULL,moved_at=NULL
        SQL);
        $count = 0;
        foreach ($statement->fetchAll() as $item) {
            $upsert->execute([$item['source_path'],$root,$item['source_name'],$item['source_size'],$item['e_file_path'],$item['e_file_name'],$item['e_file_size'],$item['type'],$item['confidence'],'Doppione interno E:. '.$item['reason']]);
            $count++;
        }
        return $count;
    }

    private function inventory(int $scanId): int
    {
        $metadata = $this->pdo->prepare('SELECT artist,title,version,bitrate,rating,play_count,genre,spotify_id,spotify_features_status FROM tracks WHERE file_path = ? ORDER BY file_exists DESC LIMIT 1');
        $insert = $this->pdo->prepare('INSERT INTO e_file_inventory(scan_id,file_path,file_name,folder,file_size,modified_at,extension,artist,title,normalized_artist,normalized_title,version,bitrate,rating,play_count,genre,has_spotify,spotify_complete) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(self::ROOT, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $file): bool {
                    if ($file->isDir() && strcasecmp(rtrim($file->getPathname(),'\\'),self::DELETION_FOLDER)===0) return false;
                    if ($file->isDir() && rtrim(dirname($file->getPathname()), '\\') === rtrim(self::ROOT, '\\')) return !in_array($file->getFilename(), self::EXCLUDED_ROOTS, true);
                    return true;
                }
            )
        );
        $count = 0;
        $this->pdo->beginTransaction();
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) continue;
            $path = $file->getPathname();
            $metadata->execute([$path]);
            $track = $metadata->fetch() ?: [];
            [$fallbackArtist,$fallbackTitle] = $this->artistTitleFromFilename($file->getFilename());
            $artist = trim((string)($track['artist']??'')) ?: $fallbackArtist;
            $title = trim((string)($track['title']??'')) ?: $fallbackTitle;
            $version = trim((string)($track['version']??'')) ?: $this->detectVersion($file->getFilename());
            $insert->execute([$scanId,$path,$file->getFilename(),$file->getPath(),$file->getSize(),$file->getMTime(),$extension,$artist,$title,normalizeText($artist),normalizeTitle($title),$version,$track['bitrate']??null,(int)($track['rating']??0),(int)($track['play_count']??0),(string)($track['genre']??''),empty($track['spotify_id'])?0:1,($track['spotify_features_status']??'')==='complete'?1:0]);
            $count++;
        }
        $this->pdo->commit();
        return $count;
    }

    private function createExactGroups(int $scanId): int
    {
        $familySql = "CASE WHEN extension IN ('mp4','mkv','vob') THEN 'video' ELSE 'audio' END";
        $sizes = $this->pdo->prepare("SELECT $familySql family,file_size FROM e_file_inventory WHERE scan_id=? AND file_size>0 GROUP BY family,file_size HAVING COUNT(*)>1");
        $sizes->execute([$scanId]);
        $files = $this->pdo->prepare("SELECT id,file_path FROM e_file_inventory WHERE scan_id=? AND $familySql=? AND file_size=?");
        $quickGroups = [];
        foreach ($sizes->fetchAll() as $sizeGroup) {
            $size = (int) $sizeGroup['file_size'];
            $family = (string) $sizeGroup['family'];
            $files->execute([$scanId,$family,$size]);
            foreach ($files->fetchAll() as $file) {
                $hash = $this->quickHash($file['file_path'], (int) $size);
                if ($hash) $quickGroups[$family . '|' . $size . '|' . $hash][] = $file;
            }
        }
        $update = $this->pdo->prepare('UPDATE e_file_inventory SET content_hash=? WHERE id=?');
        $count = 0;
        foreach ($quickGroups as $fingerprint => $candidates) {
            if (count($candidates) < 2) continue;
            foreach ($candidates as $file) {
                $update->execute([$fingerprint,$file['id']]);
            }
            $members = $this->members($scanId, 'content_hash=?', [$fingerprint]);
            $this->storeGroup($scanId,'exact',$fingerprint,$members,99,'Stessa dimensione e stessa impronta di inizio/fine file. Prima della cancellazione sarà eseguito l’hash completo.');
            $count++;
        }
        return $count;
    }

    private function quickHash(string $path, int $size): ?string
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) return null;
        $chunkSize = 262144;
        $first = fread($handle, $chunkSize) ?: '';
        $last = '';
        if ($size > $chunkSize) {
            fseek($handle, max(0, $size - $chunkSize));
            $last = fread($handle, $chunkSize) ?: '';
        }
        fclose($handle);
        return sha1($size . '|' . $first . '|' . $last);
    }

    private function createNormalizedGroups(int $scanId): int
    {
        $familySql = "CASE WHEN extension IN ('mp4','mkv','vob') THEN 'video' ELSE 'audio' END";
        $groups = $this->pdo->prepare("SELECT $familySql family,normalized_artist,normalized_title FROM e_file_inventory WHERE scan_id=? AND normalized_title<>'' GROUP BY family,normalized_artist,normalized_title HAVING COUNT(*)>1");
        $groups->execute([$scanId]);
        $count = 0;
        foreach ($groups->fetchAll() as $group) {
            $members = $this->members($scanId, "$familySql=? AND normalized_artist=? AND normalized_title=?", [$group['family'],$group['normalized_artist'],$group['normalized_title']]);
            $contentHashes = array_values(array_unique(array_filter(array_column($members, 'content_hash'))));
            if (count($contentHashes) === 1 && count(array_filter(array_column($members, 'content_hash'))) === count($members)) continue;
            $fingerprint = sha1($group['family'].'|'.$group['normalized_artist'].'|'.$group['normalized_title']);
            $versions = array_unique(array_filter(array_column($members,'version')));
            $reason = $versions ? 'Stesso artista e titolo normalizzati; verificare se le versioni sono realmente necessarie.' : 'Stesso artista e titolo normalizzati su più file.';
            $this->storeGroup($scanId,'normalized',$fingerprint,$members,85,$reason);
            $count++;
        }
        return $count;
    }

    private function members(int $scanId, string $condition, array $params): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM e_file_inventory WHERE scan_id=? AND $condition");
        $statement->execute(array_merge([$scanId],$params));
        return $statement->fetchAll();
    }

    private function storeGroup(int $scanId, string $type, string $fingerprint, array $members, int $confidence, string $reason): void
    {
        usort($members, fn(array $a,array $b) => $this->qualityScore($b) <=> $this->qualityScore($a));
        $recommended = $members[0];
        $label = trim(($recommended['artist']?:'Artista sconosciuto').' - '.$recommended['title']);
        $group = $this->pdo->prepare('INSERT INTO e_duplicate_groups(scan_id,type,fingerprint,label,confidence,reason,recommended_file_id) VALUES(?,?,?,?,?,?,?)');
        $group->execute([$scanId,$type,$fingerprint,$label,$confidence,$reason.' Consigliato: '.$this->recommendationReason($recommended),$recommended['id']]);
        $groupId = (int) $this->pdo->lastInsertId();
        $item = $this->pdo->prepare('INSERT INTO e_duplicate_group_items(group_id,file_id,is_recommended) VALUES(?,?,?)');
        foreach ($members as $member) $item->execute([$groupId,$member['id'],$member['id']===$recommended['id']?1:0]);
    }

    private function qualityScore(array $file): int
    {
        $extensionWeight = ['flac'=>9000,'wav'=>8500,'m4a'=>7000,'aac'=>6500,'mp3'=>6000,'ogg'=>5000,'opus'=>5000,'mp4'=>7000,'mkv'=>6500,'vob'=>6000];
        $definitive=str_starts_with(strtoupper(canonicalPath((string)$file['file_path'])),'E:\\LIBRERIA_DEFINITIVA\\')?1000000:0;
        $congruence=$this->folderGenreCongruence((string)$file['folder'],(string)($file['genre']??''))*100000;
        $spotify=((int)($file['spotify_complete']??0)*20000)+((int)($file['has_spotify']??0)*10000);
        return $definitive+$congruence+$spotify+($extensionWeight[strtolower((string)$file['extension'])]??0)+((int)$file['bitrate']*10)+min(999,(int)($file['file_size']/1000000));
    }

    public function recommendationScore(array $file): int { return $this->qualityScore($file); }

    private function folderGenreCongruence(string $folder,string $genre): int
    {
        $folder=normalizeText($folder);$genre=normalizeText($genre);if($genre==='')return 0;
        if(str_contains($folder,$genre))return 1;
        $groups=[
            ['salsa','timba','cubaton','caraibica'],['reggaeton','dembow','latin','caraibica'],
            ['house','edm','dance','commerciale'],['trap','rap','hip hop','urban'],['bachata','caraibica'],
        ];
        foreach($groups as $group){$genreMatch=false;foreach($group as $word)if(str_contains($genre,$word))$genreMatch=true;if($genreMatch)foreach($group as $word)if(str_contains($folder,$word))return 1;}
        return 0;
    }

    private function recommendationReason(array $file): string
    {
        $reasons=[];
        if(str_starts_with(strtoupper(canonicalPath((string)$file['file_path'])),'E:\\LIBRERIA_DEFINITIVA\\'))$reasons[]='libreria definitiva';
        if($this->folderGenreCongruence((string)$file['folder'],(string)($file['genre']??'')))$reasons[]='cartella congrua col genere';
        if((int)($file['spotify_complete']??0))$reasons[]='metriche Spotify complete';elseif((int)($file['has_spotify']??0))$reasons[]='Spotify ID presente';
        $reasons[]=strtoupper((string)$file['extension']);if(!empty($file['bitrate']))$reasons[]=(int)$file['bitrate'].' kbps';
        return implode(', ',$reasons).'.';
    }

    private function summary(int $scanId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM e_duplicate_scans WHERE id=?');
        $statement->execute([$scanId]);
        return $statement->fetch() ?: [];
    }

    private function artistTitleFromFilename(string $fileName): array
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $name, 2);
        return count($parts)===2 ? [trim($parts[0]),trim($parts[1])] : ['',trim($name)];
    }

    private function detectVersion(string $value): string
    {
        foreach (['Extended Mix','Radio Edit','Clean','Explicit','Intro','Remix','Mashup','Acapella','Instrumental'] as $version) if (stripos($value,$version)!==false) return $version;
        return '';
    }
}
