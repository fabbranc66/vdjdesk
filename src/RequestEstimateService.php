<?php
declare(strict_types=1);

final class RequestEstimateService
{
    public function __construct(private PDO $pdo) {}

    public function token(): string
    {
        $data=random_bytes(16);
        $data[6]=chr((ord($data[6])&0x0f)|0x40);
        $data[8]=chr((ord($data[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
    }

    public function recalculate(): void
    {
        $automix=$this->automixItems();
        $currentPath=$this->currentVirtualDjPath();
        $currentRemaining=$this->currentRemainingSeconds();
        $items=$this->pdo->query(<<<'SQL'
            SELECT r.id,r.status,r.track_id,t.duration,t.file_path
            FROM requests r
            LEFT JOIN tracks t ON t.id=r.track_id
            WHERE r.status IN ('next','queued')
            ORDER BY CASE r.status WHEN 'next' THEN 0 ELSE 1 END,r.updated_at,r.id
        SQL)->fetchAll();
        $reset=$this->pdo->prepare("UPDATE requests SET estimated_play_at=NULL,estimated_wait_minutes=NULL,queue_position=NULL WHERE status NOT IN ('next','queued')");
        $reset->execute();
        $update=$this->pdo->prepare('UPDATE requests SET estimated_play_at=DATE_ADD(NOW(),INTERVAL ? SECOND),estimated_wait_minutes=?,queue_position=? WHERE id=?');
        $position=1;
        foreach($items as $item){
            $elapsed=$this->estimateSecondsFromAutomix((string)($item['file_path']??''),$automix,$currentPath,$currentRemaining);
            if($elapsed===null)continue;
            $waitMinutes=max(1,(int)ceil($elapsed/60));
            $update->execute([$elapsed,$waitMinutes,$position,(int)$item['id']]);
            $position++;
        }
    }

    public function debugAutomix(): array
    {
        $items=$this->automixItems();
        $currentPath=strtolower(canonicalPath($this->currentVirtualDjPath()));
        $currentRemaining=$this->currentRemainingSeconds();
        $startIndex=0;
        foreach($items as $index=>$item){
            if($currentPath!==''&&$item['path']===$currentPath){$startIndex=$index;break;}
        }
        $elapsed=0.0;
        $rows=[];
        foreach($items as $index=>$item){
            $secondsFromOnAir=null;
            if($index===$startIndex)$secondsFromOnAir=0;
            if($index>$startIndex){
                if($elapsed===0.0)$elapsed=max(0.0,(float)($currentRemaining??$items[$startIndex]['duration']));
                $secondsFromOnAir=(int)round($elapsed);
                $elapsed+=(float)$item['duration'];
            }
            $rows[]=[
                'idx'=>$item['idx'],
                'is_on_air'=>$index===$startIndex,
                'seconds_from_on_air'=>$secondsFromOnAir,
                'eta'=>$secondsFromOnAir!==null?date('H:i',time()+$secondsFromOnAir):'',
                'duration'=>$item['duration'],
                'path'=>$item['path'],
            ];
        }
        return [
            'on_air_path'=>$currentPath,
            'start_index'=>$startIndex,
            'current_remaining_seconds'=>$currentRemaining,
            'rows'=>$rows,
        ];
    }

    public function status(string $token): array
    {
        if(!preg_match('/^[a-f0-9-]{36}$/i',$token))return ['ok'=>false,'error'=>'Richiesta non riconosciuta.','request'=>null];
        $statement=$this->pdo->prepare(<<<'SQL'
            SELECT r.id,r.guest_name,r.query,r.status,r.note,r.estimated_play_at,r.estimated_wait_minutes,r.queue_position,r.created_at,r.updated_at,
                   t.artist,t.title,t.genre,t.duration
            FROM requests r
            LEFT JOIN tracks t ON t.id=r.track_id
            WHERE r.public_token=?
        SQL);
        $statement->execute([$token]);
        $row=$statement->fetch();
        if(!$row)return ['ok'=>false,'error'=>'Richiesta non trovata.','request'=>null];
        return ['ok'=>true,'request'=>$this->format($row)];
    }

    public function format(array $row): array
    {
        $estimatedAt=(string)($row['estimated_play_at']??'');
        return [
            'id'=>(int)$row['id'],
            'guest_name'=>(string)($row['guest_name']??''),
            'query'=>(string)$row['query'],
            'status'=>(string)$row['status'],
            'status_label'=>$this->statusLabel((string)$row['status']),
            'note'=>(string)($row['note']??''),
            'artist'=>(string)($row['artist']??''),
            'title'=>(string)($row['title']??''),
            'genre'=>(string)($row['genre']??''),
            'queue_position'=>$row['queue_position']!==null?(int)$row['queue_position']:null,
            'estimated_wait_minutes'=>$row['estimated_wait_minutes']!==null?(int)$row['estimated_wait_minutes']:null,
            'estimated_play_at'=>$estimatedAt,
            'estimated_play_label'=>$estimatedAt!==''?date('H:i',strtotime($estimatedAt)):'',
            'created_at'=>(string)$row['created_at'],
            'updated_at'=>(string)$row['updated_at'],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match($status){
            'approved'=>'Richiesta approvata',
            'queued'=>'Richiesta in Automix',
            'next'=>'Richiesta in programma',
            'rejected'=>'Richiesta non accettata',
            'played'=>'Brano già riprodotto',
            default=>'Richiesta inviata al DJ',
        };
    }

    private function automixItems(): array
    {
        $path=(string)(getenv('USERPROFILE')?:'C:\\Users\\fabbr').'\\AppData\\Local\\VirtualDJ\\Sideview\\automix.vdjfolder';
        if(!is_file($path))return [];
        libxml_use_internal_errors(true);
        $xml=@simplexml_load_file($path,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT);
        if(!$xml)return [];
        $items=[];
        foreach($xml->song as $song){
            $songPath=canonicalPath((string)$song['path']);
            if($songPath==='')continue;
            $items[]=[
                'path'=>strtolower($songPath),
                'duration'=>max(30,(float)($song['songlength']??0)?:210.0),
                'idx'=>(int)($song['idx']??count($items)),
            ];
        }
        usort($items,fn(array $a,array $b): int=>$a['idx']<=>$b['idx']);
        return $items;
    }

    private function estimateSecondsFromAutomix(string $trackPath,array $items,string $currentPath='',?float $currentRemaining=null): ?int
    {
        $target=strtolower(canonicalPath($trackPath));
        if($target===''||!$items)return null;
        $startIndex=0;
        $elapsed=0.0;
        $current=strtolower(canonicalPath($currentPath));
        if($current!==''){
            foreach($items as $index=>$item){
                if($item['path']===$current){
                    if($item['path']===$target)return 0;
                    $startIndex=$index+1;
                    $elapsed=max(0.0,(float)($currentRemaining??$item['duration']));
                    break;
                }
            }
        }
        foreach(array_slice($items,$startIndex) as $item){
            if($item['path']===$target)return max(0,(int)round($elapsed));
            $elapsed+=(float)$item['duration'];
        }
        return null;
    }

    private function currentVirtualDjPath(): string
    {
        $host=setting('vdj_network_host','127.0.0.1');
        $port=min(65535,max(1,(int)setting('vdj_network_port','9665')));
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: text/plain\r\nConnection: close\r\n",'content'=>'deck active get_filepath','timeout'=>2,'ignore_errors'=>true]]);
        $response=@file_get_contents("http://$host:$port/query",false,$context);
        if($response===false)return '';
        $path=canonicalPath(trim($response));
        return str_starts_with(strtolower($path),'error:')?'':$path;
    }

    private function currentRemainingSeconds(): ?float
    {
        $host=setting('vdj_network_host','127.0.0.1');
        $port=min(65535,max(1,(int)setting('vdj_network_port','9665')));
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: text/plain\r\nConnection: close\r\n",'content'=>'deck active get_time remain','timeout'=>2,'ignore_errors'=>true]]);
        $response=@file_get_contents("http://$host:$port/query",false,$context);
        if($response===false)return null;
        $milliseconds=(float)trim($response);
        return $milliseconds>0?$milliseconds/1000:null;
    }

}
