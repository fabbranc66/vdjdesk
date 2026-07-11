<?php
declare(strict_types=1);

final class QuizService
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $question=trim((string)($data['question']??''));$options=[];
        foreach(['A','B','C','D'] as $letter)$options[$letter]=trim((string)($data['option_'.strtolower($letter)]??''));
        $correct=strtoupper(trim((string)($data['correct_option']??'')));
        if($question===''||in_array('',array_values($options),true)||!isset($options[$correct]))throw new RuntimeException('Compila domanda, quattro risposte e soluzione corretta.');
        $trackId=max(0,(int)($data['track_id']??0));
        if($trackId>0){$check=$this->pdo->prepare('SELECT COUNT(*) FROM tracks WHERE id=?');$check->execute([$trackId]);if(!(int)$check->fetchColumn())$trackId=0;}
        $duration=min(120,max(5,(int)($data['duration_seconds']??20)));
        $statement=$this->pdo->prepare('INSERT INTO quiz_questions(track_id,question_text,option_a,option_b,option_c,option_d,correct_option,duration_seconds) VALUES(?,?,?,?,?,?,?,?)');
        $statement->execute([$trackId?:null,$question,$options['A'],$options['B'],$options['C'],$options['D'],$correct,$duration]);
        return ['ok'=>true,'question'=>$this->question((int)$this->pdo->lastInsertId(),true)];
    }

    public function launch(int $id): array
    {
        if(!$this->question($id,true))throw new RuntimeException('Domanda non trovata.');
        $this->pdo->exec("UPDATE quiz_questions SET status='closed' WHERE status='open'");
        $statement=$this->pdo->prepare("UPDATE quiz_questions SET status='open',opened_at=NOW(),closes_at=DATE_ADD(NOW(),INTERVAL duration_seconds SECOND),revealed_at=NULL WHERE id=?");$statement->execute([$id]);
        return ['ok'=>true,'question'=>$this->question($id,true)];
    }

    public function setStatus(int $id,string $status): array
    {
        if(!in_array($status,['closed','revealed'],true))throw new RuntimeException('Stato quiz non valido.');
        $sql=$status==='revealed'?"UPDATE quiz_questions SET status='revealed',revealed_at=NOW() WHERE id=?":"UPDATE quiz_questions SET status='closed',closes_at=LEAST(COALESCE(closes_at,NOW()),NOW()) WHERE id=?";
        $statement=$this->pdo->prepare($sql);$statement->execute([$id]);
        if(!$statement->rowCount())throw new RuntimeException('Domanda non trovata.');
        return ['ok'=>true,'question'=>$this->question($id,true)];
    }

    public function join(string $name,string $token=''): array
    {
        $name=mb_substr(trim($name),0,80);if($name==='')throw new RuntimeException('Inserisci il tuo nome o quello della squadra.');
        $participant=$this->participant($token);
        if($participant){
            $requiresApproval=((string)($participant['status']??'active')!=='active')||(int)($participant['is_online']??0)===0||!empty($participant['left_at']);
            if($requiresApproval){
                $this->pdo->prepare("UPDATE quiz_participants SET display_name=?,status='pending',is_online=0,rejoin_requested_at=NOW(),last_seen_at=NOW() WHERE id=?")->execute([$name,$participant['id']]);
                $participant=$this->participant($token);
                return ['ok'=>true,'pending'=>true,'participant'=>$participant];
            }
            $this->pdo->prepare("UPDATE quiz_participants SET display_name=?,last_seen_at=NOW(),is_online=1,left_at=NULL,status='active',rejoin_requested_at=NULL WHERE id=?")->execute([$name,$participant['id']]);
            $participant['display_name']=$name;$participant['status']='active';
            return ['ok'=>true,'participant'=>$participant];
        }
        $token=$this->uuid();$statement=$this->pdo->prepare("INSERT INTO quiz_participants(public_token,display_name,is_online,status) VALUES(?,?,1,'active')");$statement->execute([$token,$name]);
        return ['ok'=>true,'participant'=>['id'=>(int)$this->pdo->lastInsertId(),'public_token'=>$token,'display_name'=>$name,'status'=>'active']];
    }

    public function answer(int $questionId,string $token,string $option): array
    {
        $option=strtoupper(trim($option));if(!in_array($option,['A','B','C','D'],true))throw new RuntimeException('Risposta non valida.');
        $participant=$this->participant($token);if(!$participant)throw new RuntimeException('Partecipa al quiz prima di rispondere.');
        $statement=$this->pdo->prepare("SELECT *,TIMESTAMPDIFF(MICROSECOND,opened_at,NOW()) DIV 1000 elapsed_ms FROM quiz_questions WHERE id=? AND status='open' AND NOW()<closes_at");$statement->execute([$questionId]);$question=$statement->fetch();if(!$question)throw new RuntimeException('Tempo scaduto o domanda non attiva.');
        $elapsed=max(0,(int)$question['elapsed_ms']);$duration=(int)$question['duration_seconds']*1000;$correct=$option===$question['correct_option'];$speed=max(0,1-min(1,$elapsed/max(1,$duration)));$points=$correct?500+(int)round($speed*500):0;
        try{$insert=$this->pdo->prepare('INSERT INTO quiz_answers(question_id,participant_id,selected_option,is_correct,response_ms,points) VALUES(?,?,?,?,?,?)');$insert->execute([$questionId,$participant['id'],$option,$correct?1:0,$elapsed,$points]);}catch(PDOException $error){if((string)$error->getCode()==='23000')throw new RuntimeException('Hai già risposto a questa domanda.');throw $error;}
        return ['ok'=>true,'accepted'=>true];
    }

    public function state(string $token='',bool $control=false): array
    {
        $this->advanceState();
        $question=$this->pdo->query("SELECT q.*,t.artist,t.title,t.genre,t.bpm,t.camelot FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id ORDER BY (q.status='open') DESC,q.id DESC LIMIT 1")->fetch()?:null;
        $participant=$this->participant($token);$answered=false;$selected='';
        if($question&&$participant){$statement=$this->pdo->prepare('SELECT selected_option FROM quiz_answers WHERE question_id=? AND participant_id=?');$statement->execute([$question['id'],$participant['id']]);$selected=(string)($statement->fetchColumn()?:'');$answered=$selected!=='';}
        $payload=$question?$this->formatQuestion($question,$control):null;
        if($payload){$payload['answered']=$answered;$payload['selected_option']=$selected;}
        if($participant && (string)($participant['status']??'active')!=='active')$payload=null;
        return ['question'=>$payload,'participant'=>$participant?:null,'participants'=>$control?$this->participants($question?(int)$question['id']:0):[],'leaderboard'=>$this->leaderboard(),'server_time_ms'=>(int)round(microtime(true)*1000)];
    }

    public function heartbeat(string $token,bool $online=true): array
    {
        $participant=$this->participant($token);if(!$participant)throw new RuntimeException('Partecipante non riconosciuto.');
        if(!$online){$statement=$this->pdo->prepare("UPDATE quiz_participants SET is_online=0,left_at=NOW() WHERE id=? AND status='active'");$statement->execute([$participant['id']]);return ['ok'=>true,'online'=>false];}
        if((string)($participant['status']??'active')!=='active'){$this->pdo->prepare('UPDATE quiz_participants SET last_seen_at=NOW(),rejoin_requested_at=COALESCE(rejoin_requested_at,NOW()) WHERE id=?')->execute([$participant['id']]);return ['ok'=>true,'online'=>false,'pending'=>true];}
        if(!empty($participant['left_at'])||(int)($participant['is_online']??0)===0){
            $this->pdo->prepare("UPDATE quiz_participants SET status='pending',is_online=0,rejoin_requested_at=NOW(),last_seen_at=NOW() WHERE id=?")->execute([$participant['id']]);
            return ['ok'=>true,'online'=>false,'pending'=>true];
        }
        $statement=$this->pdo->prepare("UPDATE quiz_participants SET last_seen_at=NOW(),is_online=1,left_at=NULL,status='active',rejoin_requested_at=NULL WHERE id=?");$statement->execute([$participant['id']]);
        return ['ok'=>true,'online'=>$online];
    }

    public function participantAction(int $id,string $action): array
    {
        if($id<=0)throw new RuntimeException('Partecipante non valido.');
        if($action==='accept'){
            $statement=$this->pdo->prepare("UPDATE quiz_participants SET status='active',is_online=1,left_at=NULL,rejoin_requested_at=NULL,last_seen_at=NOW() WHERE id=?");
            $statement->execute([$id]);
            return ['ok'=>true,'action'=>$action];
        }
        if($action==='disconnect'){
            $statement=$this->pdo->prepare("UPDATE quiz_participants SET is_online=0,left_at=NOW() WHERE id=?");
            $statement->execute([$id]);
            return ['ok'=>true,'action'=>$action];
        }
        if($action==='delete'){
            $this->pdo->beginTransaction();
            try{
                $this->pdo->prepare('DELETE FROM quiz_answers WHERE participant_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM quiz_participants WHERE id=?')->execute([$id]);
                $this->pdo->commit();
            }catch(Throwable $error){$this->pdo->rollBack();throw $error;}
            return ['ok'=>true,'action'=>$action];
        }
        if($action==='remove'){
            $statement=$this->pdo->prepare("UPDATE quiz_participants SET status='removed',is_online=0,left_at=NOW() WHERE id=?");
            $statement->execute([$id]);
            return ['ok'=>true,'action'=>$action];
        }
        throw new RuntimeException('Azione partecipante non valida.');
    }

    public function history(int $limit=30): array
    {
        $rows=$this->pdo->query('SELECT q.*,t.artist,t.title FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id ORDER BY q.id DESC LIMIT '.min(100,max(1,$limit)))->fetchAll();
        return array_map(fn(array $row)=>$this->formatQuestion($row,true),$rows);
    }

    private function question(int $id,bool $control): ?array{$statement=$this->pdo->prepare('SELECT q.*,t.artist,t.title,t.genre,t.bpm,t.camelot FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id WHERE q.id=?');$statement->execute([$id]);$row=$statement->fetch();return $row?$this->formatQuestion($row,$control):null;}
    private function formatQuestion(array $row,bool $control): array{$status=(string)$row['status'];$showCorrect=$control||$status==='revealed';$closesAtMs=!empty($row['closes_at'])?strtotime((string)$row['closes_at'])*1000:null;$revealedUntilMs=!empty($row['revealed_at'])?(strtotime((string)$row['revealed_at'])+20)*1000:null;$targetMs=$status==='revealed'?$revealedUntilMs:$closesAtMs;$remaining=$targetMs?max(0,(int)ceil(($targetMs-(microtime(true)*1000))/1000)):0;return ['id'=>(int)$row['id'],'track_id'=>$row['track_id']?(int)$row['track_id']:null,'artist'=>(string)($row['artist']??''),'title'=>(string)($row['title']??''),'genre'=>(string)($row['genre']??''),'question'=>(string)$row['question_text'],'options'=>['A'=>$row['option_a'],'B'=>$row['option_b'],'C'=>$row['option_c'],'D'=>$row['option_d']],'correct_option'=>$showCorrect?(string)$row['correct_option']:null,'duration_seconds'=>(int)$row['duration_seconds'],'remaining_seconds'=>$remaining,'closes_at_ms'=>$closesAtMs,'revealed_until_ms'=>$revealedUntilMs,'status'=>$status,'opened_at'=>$row['opened_at'],'closes_at'=>$row['closes_at'],'answers_count'=>$this->answerCount((int)$row['id'])];}
    private function advanceState(): void{$this->pdo->exec("UPDATE quiz_questions SET status='revealed',revealed_at=NOW() WHERE status='open' AND NOW()>=closes_at");$this->pdo->exec("UPDATE quiz_questions SET status='closed' WHERE status='revealed' AND revealed_at IS NOT NULL AND NOW()>=DATE_ADD(revealed_at,INTERVAL 20 SECOND)");}
    private function participant(string $token): ?array{if(!preg_match('/^[a-f0-9-]{36}$/i',$token))return null;$statement=$this->pdo->prepare('SELECT id,public_token,display_name,is_online,left_at,status,rejoin_requested_at FROM quiz_participants WHERE public_token=?');$statement->execute([$token]);$row=$statement->fetch();return $row?:null;}
    private function answerCount(int $questionId): int{$statement=$this->pdo->prepare('SELECT COUNT(*) FROM quiz_answers WHERE question_id=?');$statement->execute([$questionId]);return (int)$statement->fetchColumn();}
    private function leaderboard(): array{return $this->pdo->query("SELECT p.id,p.display_name,COALESCE(SUM(a.points),0) points,SUM(a.is_correct) correct_answers,COUNT(a.id) answers FROM quiz_participants p LEFT JOIN quiz_answers a ON a.participant_id=p.id WHERE p.status<>'removed' GROUP BY p.id,p.display_name HAVING answers>0 ORDER BY points DESC,correct_answers DESC,p.display_name LIMIT 20")->fetchAll();}
    private function participants(int $questionId): array{$statement=$this->pdo->prepare("SELECT p.id,p.display_name,p.status,p.rejoin_requested_at,IF(p.status='active' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 SECOND),1,0) online,p.last_seen_at,p.left_at,a.selected_option,COALESCE(a.is_correct,0) is_correct,COALESCE(a.points,0) points FROM quiz_participants p LEFT JOIN quiz_answers a ON a.participant_id=p.id AND a.question_id=? WHERE p.status<>'removed' ORDER BY (p.status='pending') DESC,online DESC,(a.id IS NOT NULL) DESC,p.display_name");$statement->execute([$questionId]);return $statement->fetchAll();}
    private function uuid(): string{$data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));}
}
