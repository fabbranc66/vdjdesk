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
        $groupId=max(0,(int)($data['group_id']??0));
        if($groupId>0){$check=$this->pdo->prepare('SELECT COUNT(*) FROM quiz_groups WHERE id=?');$check->execute([$groupId]);if(!(int)$check->fetchColumn())throw new RuntimeException('Gruppo quiz non valido.');}
        $orderStatement=$this->pdo->prepare($groupId>0?'SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE group_id=?':'SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE group_id IS NULL');
        $orderStatement->execute($groupId>0?[$groupId]:[]);$sortOrder=(int)$orderStatement->fetchColumn();
        $duration=min(120,max(5,(int)($data['duration_seconds']??20)));
        $statement=$this->pdo->prepare('INSERT INTO quiz_questions(track_id,group_id,question_text,option_a,option_b,option_c,option_d,correct_option,duration_seconds,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$trackId?:null,$groupId?:null,$question,$options['A'],$options['B'],$options['C'],$options['D'],$correct,$duration,$sortOrder]);
        return ['ok'=>true,'question'=>$this->question((int)$this->pdo->lastInsertId(),true)];
    }

    public function launch(int $id): array
    {
        if(!$this->question($id,true))throw new RuntimeException('Domanda non trovata.');
        $this->pdo->exec("UPDATE quiz_questions SET status='closed' WHERE status IN ('open','revealed')");
        $statement=$this->pdo->prepare("UPDATE quiz_questions SET status='open',opened_at=NOW(),closes_at=DATE_ADD(NOW(),INTERVAL duration_seconds SECOND),revealed_at=NULL WHERE id=?");$statement->execute([$id]);
        return ['ok'=>true,'question'=>$this->question($id,true)];
    }

    public function setStatus(int $id,string $status): array
    {
        if(!in_array($status,['closed','revealed'],true))throw new RuntimeException('Stato quiz non valido.');
        $sql="UPDATE quiz_questions SET status='revealed',closes_at=LEAST(COALESCE(closes_at,NOW()),NOW()),revealed_at=NOW() WHERE id=?";
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
        $question=$this->pdo->query("SELECT q.*,t.artist,t.title,t.genre,t.bpm,t.camelot FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id ORDER BY CASE WHEN q.status='open' THEN 0 WHEN q.status='revealed' THEN 1 WHEN q.opened_at IS NOT NULL THEN 2 ELSE 3 END,COALESCE(q.opened_at,q.created_at) DESC,q.id DESC LIMIT 1")->fetch()?:null;
        $participant=$this->participant($token);$answered=false;$selected='';
        if($question&&$participant){$statement=$this->pdo->prepare('SELECT selected_option FROM quiz_answers WHERE question_id=? AND participant_id=?');$statement->execute([$question['id'],$participant['id']]);$selected=(string)($statement->fetchColumn()?:'');$answered=$selected!=='';}
        $payload=$question?$this->formatQuestion($question,$control):null;
        if($payload){$payload['answered']=$answered;$payload['selected_option']=$selected;}
        if($participant && (string)($participant['status']??'active')!=='active')$payload=null;
        return ['question'=>$payload,'participant'=>$participant?:null,'participants'=>$control?$this->participants($question?(int)$question['id']:0):[],'leaderboard'=>$this->leaderboard($question&&!empty($question['group_id'])?(int)$question['group_id']:null),'server_time_ms'=>(int)round(microtime(true)*1000)];
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

    public function groups(): array
    {
        $items=$this->pdo->query("SELECT g.*,COUNT(q.id) question_count FROM quiz_groups g LEFT JOIN quiz_questions q ON q.group_id=g.id GROUP BY g.id ORDER BY (g.status='active') DESC,g.event_date IS NULL,g.event_date DESC,g.id DESC")->fetchAll();
        $ungrouped=(int)$this->pdo->query('SELECT COUNT(*) FROM quiz_questions WHERE group_id IS NULL')->fetchColumn();
        return ['items'=>array_map(static fn(array $item): array=>['id'=>(int)$item['id'],'name'=>(string)$item['name'],'event_date'=>(string)($item['event_date']??''),'description'=>(string)$item['description'],'status'=>(string)$item['status'],'question_count'=>(int)$item['question_count']],$items),'ungrouped_count'=>$ungrouped];
    }

    public function createGroup(string $name,string $eventDate='',string $description=''): array
    {
        $name=mb_substr(trim($name),0,150);if($name==='')throw new RuntimeException('Inserisci il nome della serata.');
        $eventDate=trim($eventDate);if($eventDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$eventDate))throw new RuntimeException('Data serata non valida.');
        $active=(int)$this->pdo->query("SELECT COUNT(*) FROM quiz_groups WHERE status='active'")->fetchColumn();
        $statement=$this->pdo->prepare('INSERT INTO quiz_groups(name,event_date,description,status) VALUES(?,?,?,?)');$statement->execute([$name,$eventDate?:null,mb_substr(trim($description),0,500),$active?'planned':'active']);
        return ['ok'=>true,'id'=>(int)$this->pdo->lastInsertId()];
    }

    public function activateGroup(int $id): array
    {
        if($id<1)throw new RuntimeException('Gruppo quiz non valido.');
        $this->pdo->beginTransaction();
        try{
            $check=$this->pdo->prepare('SELECT COUNT(*) FROM quiz_groups WHERE id=?');$check->execute([$id]);if(!(int)$check->fetchColumn())throw new RuntimeException('Gruppo quiz non trovato.');
            $this->pdo->exec("UPDATE quiz_groups SET status='planned' WHERE status='active'");
            $this->pdo->prepare("UPDATE quiz_groups SET status='active' WHERE id=?")->execute([$id]);
            $this->pdo->prepare('DELETE a FROM quiz_answers a INNER JOIN quiz_questions q ON q.id=a.question_id WHERE q.group_id=?')->execute([$id]);
            $reset=$this->pdo->prepare("UPDATE quiz_questions SET status='draft',opened_at=NULL,closes_at=NULL,revealed_at=NULL WHERE group_id=?");$reset->execute([$id]);
            $this->pdo->commit();
        }
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        return ['ok'=>true,'id'=>$id,'questions'=>$reset->rowCount()];
    }

    public function duplicateGroup(int $id,string $name,string $eventDate=''): array
    {
        $source=$this->pdo->prepare('SELECT * FROM quiz_groups WHERE id=?');$source->execute([$id]);$group=$source->fetch();if(!$group)throw new RuntimeException('Gruppo quiz non trovato.');
        $name=mb_substr(trim($name),0,150);if($name==='')$name=(string)$group['name'].' - copia';
        $eventDate=trim($eventDate);if($eventDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$eventDate))throw new RuntimeException('Data serata non valida.');
        $this->pdo->beginTransaction();
        try{
            $insert=$this->pdo->prepare("INSERT INTO quiz_groups(name,event_date,description,status) VALUES(?,?,?,'planned')");$insert->execute([$name,$eventDate?:null,(string)$group['description']]);$newId=(int)$this->pdo->lastInsertId();
            $copy=$this->pdo->prepare("INSERT INTO quiz_questions(track_id,group_id,question_text,option_a,option_b,option_c,option_d,correct_option,duration_seconds,status,sort_order) SELECT track_id,?,question_text,option_a,option_b,option_c,option_d,correct_option,duration_seconds,'draft',sort_order FROM quiz_questions WHERE group_id=? ORDER BY sort_order,id");$copy->execute([$newId,$id]);
            $this->pdo->commit();return ['ok'=>true,'id'=>$newId,'questions'=>$copy->rowCount()];
        }catch(Throwable $error){$this->pdo->rollBack();throw $error;}
    }

    public function reorderGroup(int $groupId,array $ids): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id): bool=>$id>0)));if(!$ids)throw new RuntimeException('Ordine domande vuoto.');
        $placeholders=implode(',',array_fill(0,count($ids),'?'));$sql='SELECT COUNT(*) FROM quiz_questions WHERE id IN ('.$placeholders.') AND '.($groupId>0?'group_id=?':'group_id IS NULL');
        $check=$this->pdo->prepare($sql);$params=$groupId>0?[...$ids,$groupId]:$ids;$check->execute($params);if((int)$check->fetchColumn()!==count($ids))throw new RuntimeException('Le domande non appartengono tutte al gruppo selezionato.');
        $this->pdo->beginTransaction();try{$update=$this->pdo->prepare('UPDATE quiz_questions SET sort_order=? WHERE id=?');foreach($ids as $index=>$id)$update->execute([$index+1,$id]);$this->pdo->commit();}catch(Throwable $error){$this->pdo->rollBack();throw $error;}
        return ['ok'=>true,'questions'=>count($ids)];
    }

    public function history(int $limit=30,?int $groupId=null): array
    {
        $limit=min(100,max(1,$limit));$where='';$params=[];$order='q.id DESC';
        if($groupId!==null){$where=$groupId>0?'WHERE q.group_id=?':'WHERE q.group_id IS NULL';$params=$groupId>0?[$groupId]:[];$order='q.sort_order,q.id';}
        $statement=$this->pdo->prepare("SELECT q.*,t.artist,t.title FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id $where ORDER BY $order LIMIT $limit");$statement->execute($params);$rows=$statement->fetchAll();
        return array_map(fn(array $row)=>$this->formatQuestion($row,true),$rows);
    }

    private function question(int $id,bool $control): ?array{$statement=$this->pdo->prepare('SELECT q.*,t.artist,t.title,t.genre,t.bpm,t.camelot FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id WHERE q.id=?');$statement->execute([$id]);$row=$statement->fetch();return $row?$this->formatQuestion($row,$control):null;}
    private function formatQuestion(array $row,bool $control): array{$status=(string)$row['status'];$showCorrect=$control||$status==='revealed';$closesAtMs=!empty($row['closes_at'])?strtotime((string)$row['closes_at'])*1000:null;$revealedUntilMs=!empty($row['revealed_at'])?(strtotime((string)$row['revealed_at'])+10)*1000:null;$targetMs=$status==='revealed'?$revealedUntilMs:$closesAtMs;$remaining=$targetMs?max(0,(int)ceil(($targetMs-(microtime(true)*1000))/1000)):0;return ['id'=>(int)$row['id'],'track_id'=>$row['track_id']?(int)$row['track_id']:null,'group_id'=>!empty($row['group_id'])?(int)$row['group_id']:null,'sort_order'=>(int)($row['sort_order']??0),'artist'=>(string)($row['artist']??''),'title'=>(string)($row['title']??''),'genre'=>(string)($row['genre']??''),'question'=>(string)$row['question_text'],'options'=>['A'=>$row['option_a'],'B'=>$row['option_b'],'C'=>$row['option_c'],'D'=>$row['option_d']],'correct_option'=>$showCorrect?(string)$row['correct_option']:null,'duration_seconds'=>(int)$row['duration_seconds'],'remaining_seconds'=>$remaining,'closes_at_ms'=>$closesAtMs,'revealed_until_ms'=>$revealedUntilMs,'status'=>$status,'opened_at'=>$row['opened_at'],'closes_at'=>$row['closes_at'],'answers_count'=>$this->answerCount((int)$row['id'])];}
    private function advanceState(): void{$this->pdo->exec("UPDATE quiz_questions SET status='revealed',revealed_at=NOW() WHERE status='open' AND NOW()>=closes_at");$this->pdo->exec("UPDATE quiz_questions SET status='closed' WHERE status='revealed' AND revealed_at IS NOT NULL AND NOW()>=DATE_ADD(revealed_at,INTERVAL 10 SECOND)");}
    private function participant(string $token): ?array{if(!preg_match('/^[a-f0-9-]{36}$/i',$token))return null;$statement=$this->pdo->prepare('SELECT id,public_token,display_name,is_online,left_at,status,rejoin_requested_at FROM quiz_participants WHERE public_token=?');$statement->execute([$token]);$row=$statement->fetch();return $row?:null;}
    private function answerCount(int $questionId): int{$statement=$this->pdo->prepare('SELECT COUNT(*) FROM quiz_answers WHERE question_id=?');$statement->execute([$questionId]);return (int)$statement->fetchColumn();}
    private function leaderboard(?int $groupId): array{$where=$groupId?'q.group_id=?':'q.group_id IS NULL';$statement=$this->pdo->prepare("SELECT p.id,p.display_name,COALESCE(SUM(a.points),0) points,SUM(a.is_correct) correct_answers,COUNT(a.id) answers FROM quiz_participants p INNER JOIN quiz_answers a ON a.participant_id=p.id INNER JOIN quiz_questions q ON q.id=a.question_id WHERE p.status<>'removed' AND $where GROUP BY p.id,p.display_name ORDER BY points DESC,correct_answers DESC,p.display_name LIMIT 20");$statement->execute($groupId?[$groupId]:[]);return $statement->fetchAll();}
    private function participants(int $questionId): array{$statement=$this->pdo->prepare("SELECT p.id,p.display_name,p.status,p.rejoin_requested_at,IF(p.status='active' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 SECOND),1,0) online,p.last_seen_at,p.left_at,a.selected_option,COALESCE(a.is_correct,0) is_correct,COALESCE(a.points,0) points FROM quiz_participants p LEFT JOIN quiz_answers a ON a.participant_id=p.id AND a.question_id=? WHERE p.status<>'removed' ORDER BY (p.status='pending') DESC,online DESC,(a.id IS NOT NULL) DESC,p.display_name");$statement->execute([$questionId]);return $statement->fetchAll();}
    private function uuid(): string{$data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));}
}
