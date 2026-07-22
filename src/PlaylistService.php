<?php
declare(strict_types=1);

final class PlaylistService
{
    public function replaceInPlaylist(string $relative,string $oldPath,string $newPath): array
    {
        $root=$this->root();$path=canonicalPath($root.'\\'.str_replace(['/','..'],['\\',''],$relative));
        if(!str_starts_with(strtoupper($path),strtoupper($root.'\\'))||!is_file($path))throw new RuntimeException('Playlist non valida.');
        $oldPath=canonicalPath($oldPath);$newPath=canonicalPath($newPath);
        if($oldPath===''||$newPath===''||!is_file($newPath))throw new RuntimeException('Sostituzione non valida.');
        $tracks=$this->read($path);$changed=0;
        foreach($tracks as &$track){if(strcasecmp(canonicalPath((string)$track['file_path']),$oldPath)===0){$track['file_path']=$newPath;$changed++;}}
        unset($track);
        if(!$changed)throw new RuntimeException('Brano da sostituire non trovato nella playlist.');
        return $this->saveOrder($relative,array_map(fn(array $track): string=>(string)$track['file_path'],$tracks))+['replaced'=>$changed,'old_path'=>$oldPath,'new_path'=>$newPath];
    }

    public function replaceAllMissingFromLibrary(string $relative): array
    {
        $root=$this->root();$path=canonicalPath($root.'\\'.str_replace(['/','..'],['\\',''],$relative));
        if(!str_starts_with(strtoupper($path),strtoupper($root.'\\'))||!is_file($path))throw new RuntimeException('Playlist non valida.');
        $tracks=$this->read($path);$rows=db()->query("SELECT file_path,spotify_id,file_name,bitrate FROM tracks WHERE file_exists=1 AND spotify_id REGEXP '^[A-Za-z0-9]{22}$' AND ".workbenchLibrarySqlCondition()." ORDER BY (LOWER(SUBSTRING_INDEX(file_name,'.',-1))='mp3') DESC,COALESCE(bitrate,0) DESC,file_path")->fetchAll();
        $bySpotify=[];foreach($rows as $row){$spotifyId=trim((string)$row['spotify_id']);if(!isset($bySpotify[$spotifyId]))$bySpotify[$spotifyId]=$row;}
        $replaced=0;
        foreach($tracks as &$track){
            if(!empty($track['_playlist_exists']))continue;
            $reference=(string)($track['file_path']??'');$spotifyId=trim((string)($track['spotify_id']??''));if($spotifyId===''&&preg_match('~^KRDESK://external/\\d+/([A-Za-z0-9]{22})$~i',$reference,$match))$spotifyId=$match[1];
            $candidate=$spotifyId!==''?($bySpotify[$spotifyId]??null):null;
            $newPath=canonicalPath((string)($candidate['file_path']??''));if($newPath===''||!is_file($newPath))continue;
            $track['file_path']=$newPath;$track['_playlist_exists']=true;$replaced++;
        }
        unset($track);
        if($replaced>0)$this->saveOrder($relative,array_map(fn(array $track): string=>(string)$track['file_path'],$tracks));
        $remaining=count(array_filter($tracks,fn(array $track): bool=>empty($track['_playlist_exists'])));
        return ['ok'=>true,'replaced'=>$replaced,'remaining'=>$remaining,'matched_spotify_id'=>$replaced,'tracks'=>count($tracks)];
    }

    public function removeFromPlaylist(string $relative,int $index,string $trackPath=''): array
    {
        $root=$this->root();$path=canonicalPath($root.'\\'.str_replace(['/','..'],['\\',''],$relative));
        if(!str_starts_with(strtoupper($path),strtoupper($root.'\\'))||!is_file($path))throw new RuntimeException('Playlist non valida.');
        $tracks=$this->read($path);
        if(!isset($tracks[$index]))throw new RuntimeException('Riga playlist non valida.');
        $expected=canonicalPath($trackPath);
        $current=canonicalPath((string)$tracks[$index]['file_path']);
        if($expected!==''&&strcasecmp($expected,$current)!==0)throw new RuntimeException('La playlist è cambiata: ricarica e riprova.');
        array_splice($tracks,$index,1);
        if(!$tracks){
            $extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
            $content=$extension==='vdjfolder'
                ? "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<VirtualFolder noDuplicates=\"no\">\r\n</VirtualFolder>\r\n"
                : "#EXTM3U\r\n";
            if(file_put_contents($path,$content)===false)throw new RuntimeException('Salvataggio playlist VirtualDJ non riuscito.');
            return ['ok'=>true,'tracks'=>0,'removed'=>1,'path'=>$path];
        }
        return $this->saveOrder($relative,array_map(fn(array $track): string=>(string)$track['file_path'],$tracks))+['removed'=>1];
    }

    public function replaceTrackReference(string $oldPath,string $newPath,string $artist='',string $title=''): array
    {
        $oldPath=canonicalPath($oldPath);$newPath=canonicalPath($newPath);
        if($oldPath===''||$newPath===''||!is_file($newPath))throw new RuntimeException('Riferimento playlist non aggiornabile.');
        $files=0;$references=0;$label=trim($artist.' - '.$title,' -');
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root(),FilesystemIterator::SKIP_DOTS)) as $file){
            if(!$file->isFile()||!in_array(strtolower($file->getExtension()),['m3u','m3u8'],true))continue;
            $path=$file->getPathname();$lines=file($path,FILE_IGNORE_NEW_LINES);if($lines===false)continue;
            $changed=0;
            foreach($lines as $index=>$line){
                $candidate=trim(str_replace("\xEF\xBB\xBF",'',trim($line)));
                if($candidate===''||str_starts_with($candidate,'#'))continue;
                $resolved=canonicalPath(str_replace('/','\\',$candidate));
                if(strcasecmp($resolved,$oldPath)!==0)continue;
                $lines[$index]=$newPath;$changed++;
                if($label!==''&&$index>0&&str_starts_with(trim($lines[$index-1]),'#EXTINF:')){
                    $duration=str_contains($lines[$index-1],',')?strstr($lines[$index-1],',',true):'#EXTINF:-1';
                    $lines[$index-1]=$duration.','.$label;
                }
            }
            if(!$changed)continue;
            $temporary=$path.'.tmp';if(file_put_contents($temporary,implode("\r\n",$lines)."\r\n")===false||!@rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Aggiornamento playlist non riuscito: '.basename($path));}
            $files++;$references+=$changed;
        }
        return ['files'=>$files,'references'=>$references];
    }

    public function root(): string
    {
        $database=(string)setting('vdj_database','');
        $configured=(string)setting('playlist_folder','');
        $databaseMyLists=$database!==''?dirname($database).'\\MyLists':'';
        if(trim($configured)!==''&&is_dir(canonicalPath($configured)))$configured=canonicalPath($configured);
        elseif($databaseMyLists!==''&&is_dir($databaseMyLists))$configured=$databaseMyLists;
        elseif(is_dir('C:\\VirtualDJ\\MyLists'))$configured='C:\\VirtualDJ\\MyLists';
        elseif(is_dir('E:\\VirtualDJ\\MyLists'))$configured='E:\\VirtualDJ\\MyLists';
        if(trim($configured)===''){
            $configured=$database!==''?dirname($database).'\\Playlists':((string)(getenv('LOCALAPPDATA')?:'C:\\Users\\fabbr\\AppData\\Local')).'\\VirtualDJ\\Playlists';
        }
        $root=canonicalPath($configured);
        if(!is_dir($root)&&!mkdir($root,0777,true)&&!is_dir($root))throw new RuntimeException('Cartella playlist VirtualDJ non disponibile.');
        return $root;
    }

    public function playlists(): array
    {
        $root=$this->root();$items=[];
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
            if(!$file->isFile()||!in_array(strtolower($file->getExtension()),['m3u','m3u8','xspf','vdjfolder'],true))continue;
            $tracks=$this->read($file->getPathname());$present=count(array_filter($tracks,fn(array $track): bool=>(bool)$track['_playlist_exists']));$definitive=count(array_filter($tracks,fn(array $track): bool=>(bool)$track['_playlist_definitive']));
            $items[]=['name'=>$file->getBasename('.'.$file->getExtension()),'file'=>$file->getFilename(),'relative'=>ltrim(substr($file->getPathname(),strlen($root)),'\\'),'format'=>strtoupper($file->getExtension()),'tracks'=>count($tracks),'present'=>$present,'missing'=>count($tracks)-$present,'definitive'=>$definitive,'modified_at'=>date('Y-m-d H:i:s',$file->getMTime())];
        }
        usort($items,fn(array $a,array $b)=>strcmp($b['modified_at'],$a['modified_at']));return $items;
    }

    public function create(string $name): array
    {
        $name=trim(preg_replace('/[<>:"\/\\|?*\x00-\x1F]+/u',' ',pathinfo(trim($name),PATHINFO_FILENAME))??'');$name=trim(preg_replace('/\s+/u',' ',$name)??$name);
        if($name==='')throw new RuntimeException('Inserisci un nome valido per la playlist.');
        $file=$name.'.vdjfolder';$path=$this->root().'\\'.$file;if(file_exists($path))throw new RuntimeException('Esiste gia una playlist con questo nome.');
        if(file_put_contents($path,"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<VirtualFolder noDuplicates=\"no\">\r\n</VirtualFolder>\r\n")===false)throw new RuntimeException('Creazione playlist non riuscita.');
        return ['ok'=>true,'file'=>$file,'relative'=>$file,'path'=>$path,'tracks'=>0,'format'=>'VDJFOLDER'];
    }

    public function detail(string $relative): array
    {
        $root=$this->root();$path=canonicalPath($root.'\\'.str_replace(['/','..'],['\\',''],$relative));
        if(!str_starts_with(strtoupper($path),strtoupper($root.'\\'))||!is_file($path))throw new RuntimeException('Playlist non valida.');
        return ['file'=>basename($path),'path'=>$path,'items'=>$this->read($path)];
    }

    public function candidates(string $relative,array $filters): array
    {
        $existing=$this->detail($relative)['items'];$paths=array_values(array_filter(array_map(fn(array $track): string=>(string)($track['file_path']??''),$existing)));$existingFingerprints=[];foreach($existing as $track){$fingerprint=normalizeText((string)($track['artist']??'')).'|'.normalizeTitle((string)($track['title']??''));if($fingerprint!=='|')$existingFingerprints[$fingerprint]=true;}
        $where=['file_exists=1',definitiveMusicSqlCondition()];$params=[];$audio="LOWER(SUBSTRING_INDEX(file_name,'.',-1)) IN ('mp3','m4a','aac','ogg','opus','wma','flac','wav','aiff','aif','alac')";$where[]=$audio;
        if($paths){$marks=implode(',',array_fill(0,count($paths),'?'));$where[]="file_path NOT IN ($marks)";$params=array_merge($params,$paths);}
        foreach(['macro_genre','folder_genre'] as $taxonomyFilter){$values=$this->filterValues($filters,$taxonomyFilter);if($values){$where[]=$taxonomyFilter.' IN ('.implode(',',array_fill(0,count($values),'?')).')';$params=array_merge($params,$values);}}
        $genres=$this->filterValues($filters,'genre');if($genres){$where[]='LOWER(TRIM(genre)) IN ('.implode(',',array_fill(0,count($genres),'?')).')';foreach($genres as $genre)$params[]=mb_strtolower($genre,'UTF-8');}
        $tags=$this->filterValues($filters,'tag');if($tags){$parts=[];foreach($tags as $tag){$parts[]="CONCAT(COALESCE(tags,''),' ',COALESCE(auto_tags,'')) LIKE ?";$params[]='%'.$tag.'%';}$where[]='('.implode(' OR ',$parts).')';}
        foreach(['bpm_min'=>['bpm','>='],'bpm_max'=>['bpm','<='],'year_min'=>['year','>='],'year_max'=>['year','<=']] as $key=>[$field,$operator])if(($filters[$key]??'')!==''){$where[]="$field $operator ?";$params[]=(float)$filters[$key];}
        $includeUnknownSpotify=!empty($filters['include_unknown_spotify'])||!empty($filters['include_unknown_popularity']);
        $energyMin=$this->minimumNumericFilter($filters,'energy_min');if($energyMin!==null){$where[]=$includeUnknownSpotify?'(energy>=? OR spotify_energy IS NULL)':'energy>=?';$params[]=$energyMin;}
        $danceMin=$this->minimumNumericFilter($filters,'dance_min');if($danceMin!==null){$where[]=$includeUnknownSpotify?'(danceability>=? OR spotify_danceability IS NULL)':'danceability>=?';$params[]=$danceMin;}
        $popularityMin=$this->minimumNumericFilter($filters,'popularity_min');if($popularityMin!==null){$where[]=$includeUnknownSpotify?'(popularity>=? OR popularity IS NULL)':'popularity>=?';$params[]=$popularityMin;}
        $camelotValues=$this->filterValues($filters,'camelot');$camelotKeys=[];foreach($camelotValues as $camelot){$camelot=strtoupper(trim((string)$camelot));if(preg_match('/^(1[0-2]|[1-9])([AB])$/',$camelot,$match))$camelotKeys=array_merge($camelotKeys,$this->compatibleCamelot((int)$match[1],$match[2]));}$camelotKeys=array_values(array_unique($camelotKeys));if($camelotKeys){$where[]='UPPER(camelot) IN ('.implode(',',array_fill(0,count($camelotKeys),'?')).')';$params=array_merge($params,$camelotKeys);}
        if(!empty($filters['mp3_320']))$where[]="((LOWER(SUBSTRING_INDEX(file_name,'.',-1))='mp3' AND COALESCE(bitrate,0)>=320) OR LOWER(SUBSTRING_INDEX(file_name,'.',-1)) IN ('flac','wav','aiff','aif','alac'))";
        $desired=max(1,(int)($filters['desired']??$filters['limit']??10));$score="COALESCE(popularity,40)*0.35+COALESCE(energy,3)*8+COALESCE(danceability,3)*8+COALESCE(familiarity,3)*6";
        $countStatement=db()->prepare('SELECT COUNT(*) FROM tracks WHERE '.implode(' AND ',$where));$countStatement->execute($params);$totalAvailable=(int)$countStatement->fetchColumn();
        $statement=db()->prepare("SELECT tracks.*,EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id) AS vdj_linked,$score AS candidate_score FROM tracks WHERE ".implode(' AND ',$where)." ORDER BY candidate_score DESC,popularity IS NULL,popularity DESC,TRIM(COALESCE(genre,''))='',LOWER(TRIM(genre)),artist,title");$statement->execute($params);$library=new LibraryService(db());$items=[];
        $seen=$existingFingerprints;foreach($statement->fetchAll() as $row){$fingerprint=(string)$row['normalized_artist'].'|'.(string)$row['normalized_title'];if($fingerprint!=='|'&&isset($seen[$fingerprint]))continue;if($fingerprint!=='|')$seen[$fingerprint]=true;$candidateScore=$row['candidate_score'];unset($row['candidate_score']);$track=$library->hydrateTrack($row);$reasons=[];if(!empty($filters['macro_genre']))$reasons[]='macro '.(string)$track['macro_genre'];if(!empty($filters['folder_genre']))$reasons[]='cartella '.(string)$track['folder_genre'];if($genres)$reasons[]='microgenere '.(string)$track['genre'];if($tags)$reasons[]='tag compatibile';if($camelotKeys)$reasons[]='Camelot compatibile';if(!empty($filters['mp3_320']))$reasons[]='qualità alta '.$track['bitrate'].' kbps';if($track['popularity']!==null)$reasons[]='popolarità '.$track['popularity'];$track['candidate_score']=(int)round((float)$candidateScore);$track['candidate_reason']=implode(' · ',$reasons)?:'miglior punteggio complessivo';$items[]=$track;}
        return ['items'=>$items,'desired'=>$desired,'total_available'=>count($items),'excluded_existing'=>count($paths),'duplicates_removed'=>$totalAvailable-count($items),'criteria'=>$filters];
    }

    public function compareExternalSpotifyList(array $items,bool $deduplicate=true): array
    {
        $library=new LibraryService(db());
        $bySpotify=[];$byIsrc=[];$byFingerprint=[];$byTitle=[];
        $rows=db()->query("SELECT tracks.*,EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id) AS vdj_linked FROM tracks WHERE file_exists=1 AND " . definitiveMusicSqlCondition())->fetchAll();
        foreach($rows as $row){
            $track=$library->hydrateTrack($row);$spotify=trim((string)($track['spotify_id']??''));$isrc=strtoupper(trim((string)($track['isrc']??'')));
            $fingerprint=(string)$row['normalized_artist'].'|'.(string)$row['normalized_title'];$title=(string)$row['normalized_title'];
            if($spotify!=='')$bySpotify[$spotify][]=$track;if($isrc!=='')$byIsrc[$isrc][]=$track;if($fingerprint!=='|')$byFingerprint[$fingerprint][]=$track;if($title!=='')$byTitle[$title][]=$track;
        }
        $present=[];$missing=[];$doubtful=[];$seen=[];
        foreach($items as $index=>$raw){
            if(!is_array($raw))continue;
            $entry=$this->normalizeExternalSpotifyEntry($raw,$index+1);
            $dedupeKey=$entry['spotify_id']!==''?'spotify:'.$entry['spotify_id']:'fp:'.$entry['normalized_artist'].'|'.$entry['normalized_title'].'|'.$entry['duration'];
            if($deduplicate&&isset($seen[$dedupeKey]))continue;$seen[$dedupeKey]=true;
            $matches=[];$reason='';
            if($entry['spotify_id']!==''&&!empty($bySpotify[$entry['spotify_id']])){$matches=$bySpotify[$entry['spotify_id']];$reason='Spotify ID uguale';}
            elseif($entry['isrc']!==''&&!empty($byIsrc[$entry['isrc']])){$matches=$byIsrc[$entry['isrc']];$reason='ISRC uguale';}
            elseif($entry['normalized_artist']!==''&&$entry['normalized_title']!==''&&!empty($byFingerprint[$entry['normalized_artist'].'|'.$entry['normalized_title']])){$matches=$byFingerprint[$entry['normalized_artist'].'|'.$entry['normalized_title']];$reason='Artista + titolo normalizzati uguali';}
            if($matches){$entry['status']='present';$entry['reason']=$reason;$entry['matches']=array_slice($matches,0,5);$present[]=$entry;continue;}
            $weak=[];$weakReason='';
            if($entry['spotify_id']===''&&$entry['normalized_title']!==''&&!empty($byTitle[$entry['normalized_title']])){$weak=$byTitle[$entry['normalized_title']];$weakReason='Titolo uguale, artista diverso o incompleto';}
            if($weak){$entry['status']='doubtful';$entry['reason']=$weakReason;$entry['matches']=array_slice($weak,0,5);$doubtful[]=$entry;continue;}
            $entry['status']='missing';$entry['reason']='Non trovato nella libreria musicale';$entry['matches']=[];$missing[]=$entry;
        }
        return ['total'=>count($present)+count($missing)+count($doubtful),'present'=>count($present),'missing'=>count($missing),'doubtful'=>count($doubtful),'items'=>['present'=>$present,'missing'=>$missing,'doubtful'=>$doubtful]];
    }

    public function createFromExternal(string $name,array $items): array
    {
        if(!$items)throw new RuntimeException('JSON importato vuoto.');
        $comparison=$this->compareExternalSpotifyList($items,false);
        $all=array_merge($comparison['items']['present']??[],$comparison['items']['doubtful']??[],$comparison['items']['missing']??[]);
        usort($all,fn(array $left,array $right): int=>(int)($left['position']??0)<=>(int)($right['position']??0));
        $playlist=$this->create($name);
        $lines=['<?xml version="1.0" encoding="UTF-8"?>','<VirtualFolder noDuplicates="no">'];$available=0;
        foreach($all as $index=>$entry){
            $physicalPath=canonicalPath((string)($entry['matches'][0]['file_path']??''));$exists=$physicalPath!==''&&is_file($physicalPath);
            $spotifyId=trim((string)($entry['spotify_id']??''));$reference=$exists?$physicalPath:'KRDESK://external/'.($index+1).'/'.($spotifyId!==''?$spotifyId:sha1((string)($entry['artist']??'').'|'.(string)($entry['title']??'')));
            if($exists)$available++;
            $attributes=['path'=>$reference,'idx'=>(string)$index,'artist'=>(string)($entry['artist']??''),'title'=>(string)($entry['title']??'')];
            if((int)($entry['duration']??0)>0)$attributes['songlength']=number_format((float)$entry['duration'],1,'.','');
            $attributeText='';foreach($attributes as $key=>$value)$attributeText.=' '.$key.'="'.htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8').'"';
            $lines[]="\t<song$attributeText />";
        }
        $lines[]='</VirtualFolder>';
        if(file_put_contents((string)$playlist['path'],implode("\r\n",$lines)."\r\n")===false){@unlink((string)$playlist['path']);throw new RuntimeException('Creazione playlist completa non riuscita.');}
        return ['ok'=>true,'tracks'=>count($all),'path'=>$playlist['path'],'format'=>'VDJFOLDER']+[
            'relative'=>$playlist['relative'],
            'file'=>$playlist['file'],
            'present'=>(int)$comparison['present'],
            'missing'=>(int)$comparison['missing'],
            'doubtful'=>(int)$comparison['doubtful'],
            'total'=>(int)$comparison['total'],
            'unavailable'=>(int)$comparison['total']-$available,
            'duplicates_skipped'=>0,
        ];
    }

    public function matchExternalListToFolder(array $items,string $folder): array
    {
        $folder=canonicalPath($folder);if($folder===''||!is_dir($folder))throw new RuntimeException('Cartella non valida.');
        $prefix=strtolower(rtrim($folder,'\\').'\\');$statement=db()->prepare("SELECT tracks.*,EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id) AS vdj_linked FROM tracks WHERE file_exists=1 AND " . definitiveMusicSqlCondition() . " AND (folder=? OR LOWER(file_path) LIKE ?) ORDER BY file_path");
        $statement->execute([$folder,$prefix.'%']);$library=new LibraryService(db());$tracks=array_map(fn(array $row): array=>$library->hydrateTrack($row),$statement->fetchAll());
        $bySpotify=[];$byIsrc=[];$byFingerprint=[];$byTitle=[];foreach($tracks as $track){$spotify=trim((string)($track['spotify_id']??''));$isrc=strtoupper(trim((string)($track['isrc']??'')));$fingerprint=normalizeText((string)$track['artist']).'|'.normalizeTitle((string)$track['title']);$title=normalizeTitle((string)$track['title']);if($spotify!=='')$bySpotify[$spotify][]=$track;if($isrc!=='')$byIsrc[$isrc][]=$track;if($fingerprint!=='|')$byFingerprint[$fingerprint][]=$track;if($title!=='')$byTitle[$title][]=$track;}
        $safe=[];$doubtful=[];$unmatched=[];$seen=[];
        foreach($items as $index=>$raw){if(!is_array($raw))continue;$entry=$this->normalizeExternalSpotifyEntry($raw,$index+1);$key=$entry['spotify_id']!==''?'spotify:'.$entry['spotify_id']:($entry['normalized_artist']!==''||$entry['normalized_title']!==''?'fp:'.$entry['normalized_artist'].'|'.$entry['normalized_title'].'|'.$entry['duration']:'position:'.$entry['position']);if(isset($seen[$key]))$key.=':'.$entry['position'];$seen[$key]=true;$result=$this->bestExternalFolderMatch($entry,$bySpotify,$byIsrc,$byFingerprint,$byTitle);if($result['status']==='safe'){$safe[]=$result+['entry'=>$entry];continue;}if($result['status']==='doubtful'){$doubtful[]=$result+['entry'=>$entry];continue;}$unmatched[]=['status'=>'unmatched','reason'=>'Nessun brano corrispondente nella cartella','entry'=>$entry,'track'=>null,'confidence'=>0];}
        return ['folder'=>$folder,'tracks_in_folder'=>count($tracks),'total'=>count($safe)+count($doubtful)+count($unmatched),'safe'=>count($safe),'doubtful'=>count($doubtful),'unmatched'=>count($unmatched),'items'=>['safe'=>$safe,'doubtful'=>$doubtful,'unmatched'=>$unmatched]];
    }

    public function applyExternalMetadata(array $matches): array
    {
        $update=$this->pdo()->prepare("UPDATE tracks SET spotify_id=?,spotify_url=?,isrc=IF(?<>'',?,isrc),album=IF(?<>'',?,album),duration=COALESCE(?,duration),spotify_features_status=IF(spotify_id<>?, 'never', spotify_features_status),spotify_features_error='',metadata_source='spotify_json',metadata_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND file_exists=1");
        $applied=0;$skipped=0;$errors=[];
        foreach($matches as $match){$trackId=(int)($match['track_id']??$match['track']['id']??0);$entry=is_array($match['entry']??null)?$match['entry']:(is_array($match['raw']??null)?$this->normalizeExternalSpotifyEntry($match['raw'],0):$match);$spotifyId=trim((string)($entry['spotify_id']??$entry['id']??''));$trackLink=trim((string)($entry['trackLink']??$entry['spotify_url']??''));if($spotifyId===''&&preg_match('~open\.spotify\.com/(?:intl-[a-z]+/)?track/([A-Za-z0-9]{22})~i',$trackLink,$idMatch))$spotifyId=$idMatch[1];if(!preg_match('/^[A-Za-z0-9]{22}$/',$spotifyId)||$trackId<1){$skipped++;continue;}if($trackLink==='')$trackLink='https://open.spotify.com/track/'.$spotifyId;$duration=(int)($entry['duration']??0)?:null;$isrc=strtoupper(trim((string)($entry['isrc']??'')));$album=trim((string)($entry['album']??''));try{$update->execute([$spotifyId,$trackLink,$isrc,$isrc,$album,$album,$duration,$spotifyId,$trackId]);$applied+=$update->rowCount()>0?1:0;}catch(Throwable $error){$skipped++;if(count($errors)<5)$errors[]=$error->getMessage();}}
        return ['ok'=>true,'applied'=>$applied,'skipped'=>$skipped,'errors'=>$errors];
    }

    private function bestExternalFolderMatch(array $entry,array $bySpotify,array $byIsrc,array $byFingerprint,array $byTitle): array
    {
        $candidates=[];$reason='';
        if($entry['spotify_id']!==''&&!empty($bySpotify[$entry['spotify_id']])){$candidates=$bySpotify[$entry['spotify_id']];$reason='Spotify ID uguale';}
        elseif($entry['isrc']!==''&&!empty($byIsrc[$entry['isrc']])){$candidates=$byIsrc[$entry['isrc']];$reason='ISRC uguale';}
        elseif($entry['normalized_artist']!==''&&$entry['normalized_title']!==''&&!empty($byFingerprint[$entry['normalized_artist'].'|'.$entry['normalized_title']])){$candidates=$byFingerprint[$entry['normalized_artist'].'|'.$entry['normalized_title']];$reason='Artista + titolo normalizzati uguali';}
        if($candidates){
            $ranked=$this->rankExternalCandidates($entry,$candidates,$reason);$best=$ranked[0];if($best['safe'])return ['status'=>'safe','track'=>$best['track'],'reason'=>$best['reason'],'confidence'=>$best['confidence'],'duration_diff'=>$best['duration_diff']];
            return ['status'=>'doubtful','track'=>$best['track'],'reason'=>$best['reason'],'confidence'=>$best['confidence'],'duration_diff'=>$best['duration_diff'],'alternatives'=>array_slice(array_column($ranked,'track'),0,5)];
        }
        if($entry['normalized_title']!==''&&!empty($byTitle[$entry['normalized_title']])){$ranked=$this->rankExternalCandidates($entry,$byTitle[$entry['normalized_title']],'Titolo uguale, artista diverso o incompleto');$best=$ranked[0];return ['status'=>'doubtful','track'=>$best['track'],'reason'=>$best['reason'],'confidence'=>$best['confidence'],'duration_diff'=>$best['duration_diff'],'alternatives'=>array_slice(array_column($ranked,'track'),0,5)];}
        return ['status'=>'unmatched'];
    }

    private function rankExternalCandidates(array $entry,array $candidates,string $baseReason): array
    {
        $ranked=[];foreach($candidates as $track){$durationDiff=null;$durationOk=true;if((int)$entry['duration']>0&&(int)($track['duration']??0)>0){$durationDiff=abs((int)$entry['duration']-(int)$track['duration']);$durationOk=$durationDiff<=8;}$existingSpotify=trim((string)($track['spotify_id']??''));$spotifyConflict=$existingSpotify!==''&&$entry['spotify_id']!==''&&$existingSpotify!==$entry['spotify_id'];$confidence=95;if($durationDiff!==null)$confidence-=$durationOk?0:min(30,$durationDiff*3);if($spotifyConflict)$confidence-=60;if(count($candidates)>1)$confidence-=10;$safe=$confidence>=85&&$durationOk&&!$spotifyConflict&&count($candidates)===1;$reason=$baseReason.($durationDiff!==null?' · durata Δ'.$durationDiff.'s':'').($spotifyConflict?' · Spotify ID diverso già presente':'').(count($candidates)>1?' · match multiplo':'');$ranked[]=['track'=>$track,'confidence'=>$confidence,'safe'=>$safe,'duration_diff'=>$durationDiff,'reason'=>$reason];}
        usort($ranked,fn(array $a,array $b)=>$b['confidence']<=>$a['confidence']);return $ranked;
    }

    private function filterValues(array $filters,string $key): array
    {
        $raw=$filters[$key]??[];
        $items=is_array($raw)?$raw:explode(',',(string)$raw);
        $values=[];
        foreach($items as $item){
            $value=trim((string)$item);
            if($value!=='')$values[$value]=$value;
        }
        return array_values($values);
    }

    private function minimumNumericFilter(array $filters,string $key): ?int
    {
        $values=array_values(array_filter(array_map(fn(string $value): int=>(int)$value,$this->filterValues($filters,$key)),fn(int $value): bool=>$value>0));
        return $values?min($values):null;
    }

    private function pdo(): PDO { return db(); }

    private function normalizeExternalSpotifyEntry(array $raw,int $position): array
    {
        $spotifyId=trim((string)($raw['spotify_id']??$raw['id']??''));
        $trackLink=trim((string)($raw['trackLink']??$raw['track_url']??$raw['spotify_url']??''));
        if($spotifyId===''&&preg_match('~open\.spotify\.com/(?:intl-[a-z]+/)?track/([A-Za-z0-9]{22})~i',$trackLink,$match))$spotifyId=$match[1];
        if($trackLink===''&&preg_match('~^[A-Za-z0-9]{22}$~',$spotifyId))$trackLink='https://open.spotify.com/track/'.$spotifyId;
        $artistValue=$raw['artist']??$raw['artists']??$raw['artistName']??'';$artist=is_array($artistValue)?implode(', ',array_map('strval',$artistValue)):trim((string)$artistValue);
        $title=trim((string)($raw['title']??$raw['name']??$raw['track']??''));
        $path=trim((string)($raw['path']??$raw['file_path']??$raw['file']??$raw['location']??''));
        if(($artist===''||$title==='')&&$path!==''){$base=pathinfo(str_replace('\\','/',$path),PATHINFO_FILENAME);if(str_contains($base,' - ')){[$pathArtist,$pathTitle]=array_map('trim',explode(' - ',$base,2));if($artist==='')$artist=$pathArtist;if($title==='')$title=$pathTitle;}elseif($title==='')$title=$base;}
        $duration=(int)preg_replace('/[^0-9]/','',(string)($raw['duration']??$raw['duration_ms']??0));if($duration>10000)$duration=(int)round($duration/1000);
        return ['position'=>$position,'platform'=>(string)($raw['platform']??''),'spotify_id'=>$spotifyId,'trackLink'=>$trackLink,'title'=>$title,'artist'=>$artist,'album'=>(string)($raw['album']??''),'isrc'=>strtoupper(trim((string)($raw['isrc']??''))),'duration'=>$duration,'addedDate'=>$raw['addedDate']??null,'normalized_artist'=>normalizeText($artist),'normalized_title'=>normalizeTitle($title),'raw'=>$raw];
    }

    private function compatibleCamelot(int $number,string $letter): array
    {
        $previous=$number===1?12:$number-1;$next=$number===12?1:$number+1;$opposite=$letter==='A'?'B':'A';return [$number.$letter,$previous.$letter,$next.$letter,$number.$opposite];
    }

    public function saveOrder(string $relative,array $paths): array
    {
        $root=$this->root();$path=canonicalPath($root.'\\'.str_replace(['/','..'],['\\',''],$relative));
        if(!str_starts_with(strtoupper($path),strtoupper($root.'\\'))||!is_file($path))throw new RuntimeException('Playlist non valida.');
        if(!in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['m3u','m3u8','vdjfolder'],true))throw new RuntimeException('Il riordino e disponibile per playlist M3U/M3U8/VDJFolder.');
        $clean=[];foreach($paths as $trackPath){$raw=(string)$trackPath;$external=str_starts_with(strtoupper($raw),'KRDESK://');$trackPath=$external?$raw:canonicalPath($raw);if($trackPath!==''&&($external||is_file($trackPath)))$clean[]=$trackPath;}
        if(!$clean)throw new RuntimeException('Nessun brano valido da salvare.');
        $metadata=db()->prepare('SELECT artist,title,duration,bpm,musical_key,file_name FROM tracks WHERE file_path=? ORDER BY file_exists DESC LIMIT 1');
        $existing=[];foreach($this->read($path) as $item)$existing[(string)$item['file_path']][]=$item;
        if(strtolower(pathinfo($path,PATHINFO_EXTENSION))==='vdjfolder'){
            $lines=['<?xml version="1.0" encoding="UTF-8"?>','<VirtualFolder noDuplicates="no">'];
            foreach($clean as $index=>$trackPath){
                $metadata->execute([$trackPath]);$track=$metadata->fetch();
                if(!$track){$stored=$existing[$trackPath]??[];$track=array_shift($stored)?:[];$existing[$trackPath]=$stored;}
                $attrs=['path'=>$trackPath,'idx'=>(string)$index];
                $externalReference=str_starts_with(strtoupper($trackPath),'KRDESK://');$size=!$externalReference&&is_file($trackPath)?filesize($trackPath):0;if($size>0)$attrs['size']=(string)$size;
                if((int)($track['duration']??0)>0)$attrs['songlength']=number_format((float)$track['duration'],1,'.','');
                if((float)($track['bpm']??0)>0)$attrs['bpm']=number_format((float)$track['bpm'],3,'.','');
                if(trim((string)($track['musical_key']??''))!=='')$attrs['key']=(string)$track['musical_key'];
                if(trim((string)($track['artist']??''))!=='')$attrs['artist']=(string)$track['artist'];
                if(trim((string)($track['title']??''))!=='')$attrs['title']=(string)$track['title'];
                $attributeText='';foreach($attrs as $key=>$value)$attributeText.=' '.$key.'="'.htmlspecialchars((string)$value,ENT_QUOTES|ENT_XML1,'UTF-8').'"';
                $lines[]="\t<song$attributeText />";
            }
            $lines[]='</VirtualFolder>';
            if(file_put_contents($path,implode("\r\n",$lines)."\r\n")===false)throw new RuntimeException('Salvataggio playlist VirtualDJ non riuscito.');
            return ['ok'=>true,'tracks'=>count($clean),'path'=>$path,'format'=>'VDJFOLDER'];
        }
        $lines=['#EXTM3U'];
        foreach($clean as $trackPath){$metadata->execute([$trackPath]);$track=$metadata->fetch();if(!$track){$stored=$existing[$trackPath]??[];$track=array_shift($stored)?:[];$existing[$trackPath]=$stored;}$label=trim((string)($track['artist']??'').' - '.(string)($track['title']??pathinfo($trackPath,PATHINFO_FILENAME)),' -');$lines[]='#EXTINF:'.(int)($track['duration']??-1).','.$label;$lines[]=$trackPath;}
        if(file_put_contents($path,implode("\r\n",$lines)."\r\n")===false)throw new RuntimeException('Salvataggio playlist non riuscito.');
        return ['ok'=>true,'tracks'=>count($clean),'path'=>$path,'format'=>'M3U'];
    }

    private function read(string $path): array
    {
        $extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));$entries=[];
        if($extension==='xspf'){
            $xml=@simplexml_load_file($path);if($xml){$xml->registerXPathNamespace('x','http://xspf.org/ns/0/');foreach($xml->xpath('//x:track/x:location')?:[] as $location)$entries[]=['path'=>rawurldecode(preg_replace('~^file:///~','',(string)$location)??''),'artist'=>'','title'=>''];}
        }elseif($extension==='vdjfolder'){
            $xml=@simplexml_load_file($path);if($xml){$position=0;foreach($xml->xpath('//song')?:[] as $song){$songPath=html_entity_decode((string)($song['path']??''),ENT_QUOTES|ENT_XML1,'UTF-8');if($songPath==='')continue;$title=html_entity_decode((string)($song['title']??''),ENT_QUOTES|ENT_XML1,'UTF-8');$remix=html_entity_decode((string)($song['remix']??''),ENT_QUOTES|ENT_XML1,'UTF-8');if($title!==''&&$remix!=='')$title.=' ('.$remix.')';$idx=(string)($song['idx']??'');$entries[]=['path'=>$songPath,'artist'=>html_entity_decode((string)($song['artist']??''),ENT_QUOTES|ENT_XML1,'UTF-8'),'title'=>$title,'_idx'=>$idx!==''?(int)$idx:null,'_pos'=>$position++];}usort($entries,fn(array $a,array $b): int=>($a['_idx']??$a['_pos'])<=>($b['_idx']??$b['_pos']) ?: ($a['_pos']<=>$b['_pos']));}
        }else foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim(str_replace("\xEF\xBB\xBF",'',trim($line)));if($line!==''&&!str_starts_with($line,'#')&&!str_contains(strtoupper($line),'#EXTVDJ:'))$entries[]=['path'=>$line,'artist'=>'','title'=>''];}
        $metadata=db()->prepare('SELECT id FROM tracks WHERE file_path=? ORDER BY file_exists DESC LIMIT 1');$library=new LibraryService(db());
        $musicRootPrefix=strtoupper(definitiveMusicRoot().'\\');$items=[];foreach($entries as $entry){$rawTrackPath=(string)$entry['path'];$external=str_starts_with(strtoupper($rawTrackPath),'KRDESK://');$trackPath=$external?$rawTrackPath:str_replace('/','\\',$rawTrackPath);if(!$external&&!preg_match('/^[A-Za-z]:\\\\/',$trackPath)){$relative=$trackPath;$desktop=(string)(getenv('USERPROFILE')?:'C:\\Users\\fabbr').'\\Desktop';$candidates=[canonicalPath(dirname($path).'\\'.$relative),canonicalPath($desktop.'\\'.$relative),canonicalPath((string)(getenv('USERPROFILE')?:'C:\\Users\\fabbr').'\\'.$relative)];$existing=array_values(array_filter($candidates,'is_file'));$trackPath=$existing[0]??$candidates[1];}$exists=!$external&&is_file($trackPath);$metadata->execute([$trackPath]);$id=(int)($metadata->fetchColumn()?:0);$track=$id?$library->find($id):null;if(!$track){$fallbackArtist=trim((string)$entry['artist']);$fallbackTitle=trim((string)$entry['title']);if($fallbackTitle===''||$fallbackArtist===''){$base=pathinfo($trackPath,PATHINFO_FILENAME);if(str_contains($base,' - ')){[$left,$right]=array_map('trim',explode(' - ',$base,2));if($fallbackArtist==='')$fallbackArtist=$left;if($fallbackTitle==='')$fallbackTitle=$right;}elseif($fallbackTitle==='')$fallbackTitle=$base;}$track=['id'=>0,'artist'=>$fallbackArtist,'title'=>$fallbackTitle,'file_path'=>$trackPath,'file_name'=>basename($trackPath),'folder'=>dirname($trackPath),'bpm'=>null,'camelot'=>'','musical_key'=>'','duration'=>null,'genre'=>'','year'=>null,'bitrate'=>null,'tags'=>[],'version'=>'','spotify_mode'=>null];}$items[]=array_merge($track,['_playlist_exists'=>$exists,'_playlist_definitive'=>$exists&&str_starts_with(strtoupper(canonicalPath($trackPath)),$musicRootPrefix),'_playlist_path'=>$trackPath]);}
        return $items;
    }

}
