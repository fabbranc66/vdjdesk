<?php
declare(strict_types=1);

final class AudioReplacementService
{
    private const EXTENSIONS=['mp3','m4a','aac','ogg','opus','flac','wav'];
    private const ARCHIVE='E:\\LIBRERIA_DEFINITIVA\\01_INBOX\\Sostituzioni';

    public function __construct(private PDO $pdo,private LibraryService $library){}

    public function start(int $trackId): array
    {
        $track=$this->library->find($trackId);
        if(!$track||!is_file((string)$track['file_path']))throw new RuntimeException('File originale non disponibile.');
        if(!in_array(strtolower(pathinfo((string)$track['file_path'],PATHINFO_EXTENSION)),self::EXTENSIONS,true))throw new RuntimeException('La sostituzione è consentita solo audio → audio.');
        $statement=$this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $statement->execute(['replacement_track_id',(string)$trackId]);$statement->execute(['replacement_started_at',(string)time()]);
        return ['ok'=>true,'track_id'=>$trackId,'message'=>'Monitoraggio download avviato.'];
    }

    public function status(): array
    {
        $settings=$this->pdo->query("SELECT `key`,value FROM settings WHERE `key` IN ('replacement_track_id','replacement_started_at')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $trackId=(int)($settings['replacement_track_id']??0);$started=(int)($settings['replacement_started_at']??0);
        if($trackId<1||$started<1)return ['pending'=>false];
        if($started<time()-900){$this->clear();return ['pending'=>false,'expired'=>true];}
        $download=$this->latestDownload($started);
        if($download===null)return ['pending'=>true];
        return $this->replace($trackId,$download);
    }

    private function latestDownload(int $started): ?string
    {
        $directory=(string)(getenv('USERPROFILE')?:'').'\\Downloads';if(!is_dir($directory))return null;$matches=[];
        foreach(new DirectoryIterator($directory) as $file){if(!$file->isFile())continue;$extension=strtolower($file->getExtension());if(!in_array($extension,self::EXTENSIONS,true))continue;if($file->getMTime()<$started||$file->getSize()<500000||$file->getMTime()>time()-3)continue;$matches[]=$file->getPathname();}
        usort($matches,fn(string $left,string $right)=>filemtime($right)<=>filemtime($left));return $matches[0]??null;
    }

    private function replace(int $trackId,string $download): array
    {
        $track=$this->library->find($trackId);if(!$track)throw new RuntimeException('Brano target non trovato.');
        $old=canonicalPath((string)$track['file_path']);if(!is_file($old))throw new RuntimeException('File originale non più presente.');
        $newExtension=strtolower(pathinfo($download,PATHINFO_EXTENSION));if(!in_array($newExtension,self::EXTENSIONS,true))throw new RuntimeException('Download non audio.');
        $oldExtension=strtolower(pathinfo($old,PATHINFO_EXTENSION));$target=$oldExtension===$newExtension?$old:dirname($old).'\\'.pathinfo($old,PATHINFO_FILENAME).'.'.$newExtension;
        if($target!==$old&&file_exists($target))throw new RuntimeException('Esiste già un file con il nuovo formato nella cartella originale.');
        if(!is_dir(self::ARCHIVE)&&!mkdir(self::ARCHIVE,0777,true)&&!is_dir(self::ARCHIVE))throw new RuntimeException('Impossibile creare Inbox/Sostituzioni.');
        $backup=self::ARCHIVE.'\\'.basename($old);if(file_exists($backup))$backup=self::ARCHIVE.'\\'.pathinfo($old,PATHINFO_FILENAME).'_'.date('Ymd_His').'.'.$oldExtension;
        if(!rename($old,$backup))throw new RuntimeException('Impossibile archiviare il file precedente.');
        try{$this->moveVerified($download,$target);}catch(Throwable $error){@rename($backup,$old);throw $error;}
        try{
            $audio=$this->inspectAudio($target);
            $statement=$this->pdo->prepare("UPDATE tracks SET file_path=?,file_name=?,folder=?,file_size=?,bitrate=COALESCE(?,bitrate),duration=COALESCE(?,duration),file_exists=1,source='manual',updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $statement->execute([$target,basename($target),dirname($target),(int)filesize($target),$audio['bitrate'],$audio['duration'],$trackId]);$this->clear();
        }catch(Throwable $error){@rename($target,$download);@rename($backup,$old);throw $error;}
        $spotifyUpdated=false;$spotifyError='';
        try{
            (new SpotifyAudioFeaturesService($this->pdo))->refreshReplacementMetadata($trackId);
            $spotifyUpdated=true;
        }catch(Throwable $error){$spotifyError=$error->getMessage();}
        $updatedTrack=$this->library->find($trackId);$playlistUpdate=['files'=>0,'references'=>0];$playlistError='';
        try{$playlistUpdate=(new PlaylistService())->replaceTrackReference($old,$target,(string)($updatedTrack['artist']??''),(string)($updatedTrack['title']??''));}catch(Throwable $error){$playlistError=$error->getMessage();}
        return ['pending'=>false,'completed'=>true,'track'=>$updatedTrack,'installed'=>$target,'archived'=>$backup,'spotify_updated'=>$spotifyUpdated,'spotify_error'=>$spotifyError,'playlist_updated'=>$playlistUpdate,'playlist_error'=>$playlistError];
    }

    private function moveVerified(string $source,string $target): void
    {
        $size=(int)filesize($source);if(@rename($source,$target))return;
        if(!@copy($source,$target)||!is_file($target)||(int)filesize($target)!==$size){@unlink($target);throw new RuntimeException('Copia del nuovo audio non riuscita.');}
        if(!@unlink($source)){@unlink($target);throw new RuntimeException('Impossibile completare lo spostamento del download.');}
    }

    private function inspectAudio(string $path): array
    {
        $command=['ffprobe','-v','error','-select_streams','a:0','-show_entries','stream=bit_rate:format=duration,bit_rate','-of','json',$path];
        $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);$output='';
        if(is_resource($process)){$output=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
        $data=$output!==''?json_decode($output,true):null;$format=(array)($data['format']??[]);$stream=(array)($data['streams'][0]??[]);
        $rawBitrate=$stream['bit_rate']??$format['bit_rate']??null;$bitrate=$rawBitrate!==null?(int)round(((float)$rawBitrate)/1000):null;
        $duration=isset($format['duration'])?(int)round((float)$format['duration']):null;
        return ['bitrate'=>$bitrate&&$bitrate>0?$bitrate:null,'duration'=>$duration&&$duration>0?$duration:null];
    }

    private function clear(): void{$this->pdo->exec("DELETE FROM settings WHERE `key` IN ('replacement_track_id','replacement_started_at')");}
}
