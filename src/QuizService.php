<?php
declare(strict_types=1);

final class QuizService
{
    private const FASTEST_CORRECT_BONUS = 250;

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
        $question=$this->question($id,true);if(!$question)throw new RuntimeException('Domanda non trovata.');
        if($question['status']!=='betting')throw new RuntimeException('Apri prima la fase puntate.');
        foreach($this->pdo->query("SELECT id FROM quiz_questions WHERE status IN ('open','revealed')")->fetchAll(PDO::FETCH_COLUMN) as $questionId)$this->finalizeQuestionScores((int)$questionId);
        $this->pdo->exec("UPDATE quiz_questions SET status='closed' WHERE status IN ('open','revealed')");
        $statement=$this->pdo->prepare("UPDATE quiz_questions SET status='open',opened_at=NOW(),closes_at=DATE_ADD(NOW(),INTERVAL duration_seconds SECOND),revealed_at=NULL WHERE id=?");$statement->execute([$id]);
        return ['ok'=>true,'question'=>$this->question($id,true)];
    }

    public function openBetting(int $id): array
    {
        $question=$this->question($id,true);if(!$question||!in_array($question['status'],['draft','betting'],true))throw new RuntimeException('Domanda non disponibile per le puntate.');
        $this->pdo->prepare("UPDATE quiz_questions SET status='draft' WHERE status='betting' AND id<>?")->execute([$id]);
        $this->pdo->prepare("UPDATE quiz_questions SET status='betting' WHERE id=?")->execute([$id]);
        return ['ok'=>true,'question'=>$this->question($id,true)];
    }

    public function placeBet(int $questionId,string $token,string $mode): array
    {
        if(!in_array($mode,['half','all_in'],true))throw new RuntimeException('Puntata non valida.');
        $participant=$this->participant($token);if(!$participant||(string)($participant['status']??'')!=='active')throw new RuntimeException('Partecipante non riconosciuto.');
        $statement=$this->pdo->prepare("SELECT id,group_id FROM quiz_questions WHERE id=? AND status='betting'");$statement->execute([$questionId]);$question=$statement->fetch();if(!$question)throw new RuntimeException('La fase puntate non è attiva.');
        $score=$this->participantScore((int)$participant['id'],!empty($question['group_id'])?(int)$question['group_id']:null);if($score<1)throw new RuntimeException('Non hai ancora punti da puntare.');
        $stake=$mode==='all_in'?$score:max(1,intdiv($score,2));
        $save=$this->pdo->prepare("INSERT INTO quiz_bets(question_id,participant_id,mode,stake_points,status,result_points,settled_at) VALUES(?,?,?,?,'pending',0,NULL) ON DUPLICATE KEY UPDATE mode=VALUES(mode),stake_points=VALUES(stake_points),status='pending',result_points=0,settled_at=NULL");
        $save->execute([$questionId,$participant['id'],$mode,$stake]);
        return ['ok'=>true,'mode'=>$mode,'stake_points'=>$stake,'score'=>$score];
    }

    public function setStatus(int $id,string $status): array
    {
        if(!in_array($status,['closed','revealed'],true))throw new RuntimeException('Stato quiz non valido.');
        $this->finalizeQuestionScores($id);
        $sql="UPDATE quiz_questions SET status='revealed',closes_at=LEAST(COALESCE(closes_at,NOW()),NOW()),revealed_at=NOW() WHERE id=?";
        $statement=$this->pdo->prepare($sql);$statement->execute([$id]);
        if(!$statement->rowCount())throw new RuntimeException('Domanda non trovata.');
        return ['ok'=>true,'question'=>$this->question($id,true)];
    }

    public function join(string $name,string $token='',string $localIdentifier=''): array
    {
        $name=mb_substr(trim($name),0,80);if($name==='')throw new RuntimeException('Inserisci il tuo nome o quello della squadra.');
        $localIdentifier=preg_match('/^[a-f0-9-]{36}$/i',$localIdentifier)?strtolower($localIdentifier):'';
        $participant=$this->participant($token);
        if($participant&&$localIdentifier!=='')$this->pdo->prepare('UPDATE quiz_participants SET local_identifier=COALESCE(local_identifier,?) WHERE id=?')->execute([$localIdentifier,$participant['id']]);
        if(!$participant&&$localIdentifier!=='')$participant=$this->participantByLocalIdentifier($localIdentifier);
        $this->assertDisplayNameAvailable($name,(int)($participant['id']??0));
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
        $token=$this->uuid();$statement=$this->pdo->prepare("INSERT INTO quiz_participants(public_token,local_identifier,display_name,is_online,status) VALUES(?,?,?,1,'active')");$statement->execute([$token,$localIdentifier?:null,$name]);
        return ['ok'=>true,'participant'=>['id'=>(int)$this->pdo->lastInsertId(),'public_token'=>$token,'display_name'=>$name,'status'=>'active']];
    }

    public function answer(int $questionId,string $token,string $option): array
    {
        $option=strtoupper(trim($option));if(!in_array($option,['A','B','C','D'],true))throw new RuntimeException('Risposta non valida.');
        $participant=$this->participant($token);if(!$participant)throw new RuntimeException('Partecipa al quiz prima di rispondere.');
        $statement=$this->pdo->prepare("SELECT *,TIMESTAMPDIFF(MICROSECOND,opened_at,NOW()) DIV 1000 elapsed_ms FROM quiz_questions WHERE id=? AND status='open' AND NOW()<closes_at");$statement->execute([$questionId]);$question=$statement->fetch();if(!$question)throw new RuntimeException('Tempo scaduto o domanda non attiva.');
        $elapsed=max(0,(int)$question['elapsed_ms']);$correct=$option===$question['correct_option'];
        try{$insert=$this->pdo->prepare('INSERT INTO quiz_answers(question_id,participant_id,selected_option,is_correct,response_ms,points) VALUES(?,?,?,?,?,0)');$insert->execute([$questionId,$participant['id'],$option,$correct?1:0,$elapsed]);}catch(PDOException $error){if((string)$error->getCode()==='23000')throw new RuntimeException('Hai già risposto a questa domanda.');throw $error;}
        return ['ok'=>true,'accepted'=>true];
    }

    public function state(string $token='',bool $control=false): array
    {
        $this->advanceState();
        $question=$this->pdo->query("SELECT q.*,t.artist,t.title,t.genre,t.bpm,t.camelot FROM quiz_questions q LEFT JOIN tracks t ON t.id=q.track_id ORDER BY CASE WHEN q.status='betting' THEN 0 WHEN q.status='open' THEN 1 WHEN q.status='revealed' THEN 2 WHEN q.opened_at IS NOT NULL THEN 3 ELSE 4 END,COALESCE(q.opened_at,q.created_at) DESC,q.id DESC LIMIT 1")->fetch()?:null;
        $participant=$this->participant($token);$answered=false;$selected='';
        if($question&&$participant){$statement=$this->pdo->prepare('SELECT selected_option FROM quiz_answers WHERE question_id=? AND participant_id=?');$statement->execute([$question['id'],$participant['id']]);$selected=(string)($statement->fetchColumn()?:'');$answered=$selected!=='';}
        $payload=$question?$this->formatQuestion($question,$control):null;
        if($payload){$payload['answered']=$answered;$payload['selected_option']=$selected;if(!$control&&$payload['status']==='betting'){$payload['question']='';$payload['options']=[];$payload['correct_option']=null;}}
        if($participant){$groupId=$question&&!empty($question['group_id'])?(int)$question['group_id']:null;$participant['points']=$this->participantScore((int)$participant['id'],$groupId);if($question){$bet=$this->participantBet((int)$question['id'],(int)$participant['id']);if($payload)$payload['bet']=$bet;}}
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
            $this->pdo->prepare('DELETE b FROM quiz_bets b INNER JOIN quiz_questions q ON q.id=b.question_id WHERE q.group_id=?')->execute([$id]);
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
    private function formatQuestion(array $row,bool $control): array{$status=(string)$row['status'];$showCorrect=$control||$status==='revealed';$closesAtMs=!empty($row['closes_at'])?strtotime((string)$row['closes_at'])*1000:null;$revealedUntilMs=!empty($row['revealed_at'])?(strtotime((string)$row['revealed_at'])+10)*1000:null;$targetMs=$status==='revealed'?$revealedUntilMs:$closesAtMs;$remaining=$targetMs?max(0,(int)ceil(($targetMs-(microtime(true)*1000))/1000)):0;return ['id'=>(int)$row['id'],'track_id'=>$row['track_id']?(int)$row['track_id']:null,'group_id'=>!empty($row['group_id'])?(int)$row['group_id']:null,'sort_order'=>(int)($row['sort_order']??0),'artist'=>(string)($row['artist']??''),'title'=>(string)($row['title']??''),'genre'=>(string)($row['genre']??''),'question'=>(string)$row['question_text'],'options'=>['A'=>$row['option_a'],'B'=>$row['option_b'],'C'=>$row['option_c'],'D'=>$row['option_d']],'correct_option'=>$showCorrect?(string)$row['correct_option']:null,'duration_seconds'=>(int)$row['duration_seconds'],'remaining_seconds'=>$remaining,'closes_at_ms'=>$closesAtMs,'revealed_until_ms'=>$revealedUntilMs,'status'=>$status,'opened_at'=>$row['opened_at'],'closes_at'=>$row['closes_at'],'answers_count'=>$this->answerCount((int)$row['id']),'bets_count'=>$this->betCount((int)$row['id'])];}
    private function advanceState(): void{$expired=$this->pdo->query("SELECT id FROM quiz_questions WHERE status='open' AND NOW()>=closes_at")->fetchAll(PDO::FETCH_COLUMN);foreach($expired as $questionId)$this->finalizeQuestionScores((int)$questionId);$this->pdo->exec("UPDATE quiz_questions SET status='revealed',revealed_at=NOW() WHERE status='open' AND NOW()>=closes_at");$this->pdo->exec("UPDATE quiz_questions SET status='closed' WHERE status='revealed' AND revealed_at IS NOT NULL AND NOW()>=DATE_ADD(revealed_at,INTERVAL 10 SECOND)");}
    private function finalizeQuestionScores(int $questionId): void{$statement=$this->pdo->prepare('UPDATE quiz_answers a INNER JOIN quiz_questions q ON q.id=a.question_id SET a.points=IF(a.is_correct=1,500+ROUND(GREATEST(0,1-LEAST(1,a.response_ms/GREATEST(1,q.duration_seconds*1000)))*500),0) WHERE q.id=?');$statement->execute([$questionId]);$fastest=$this->pdo->prepare('SELECT id FROM quiz_answers WHERE question_id=? AND is_correct=1 ORDER BY response_ms,id LIMIT 1');$fastest->execute([$questionId]);$fastestId=(int)($fastest->fetchColumn()?:0);if($fastestId>0)$this->pdo->prepare('UPDATE quiz_answers SET points=points+? WHERE id=?')->execute([self::FASTEST_CORRECT_BONUS,$fastestId]);$settle=$this->pdo->prepare("UPDATE quiz_bets b LEFT JOIN quiz_answers a ON a.question_id=b.question_id AND a.participant_id=b.participant_id SET b.result_points=IF(COALESCE(a.is_correct,0)=1,b.stake_points,-b.stake_points),b.status='settled',b.settled_at=NOW() WHERE b.question_id=?");$settle->execute([$questionId]);}
    private function participant(string $token): ?array{if(!preg_match('/^[a-f0-9-]{36}$/i',$token))return null;$statement=$this->pdo->prepare('SELECT id,public_token,display_name,is_online,left_at,status,rejoin_requested_at FROM quiz_participants WHERE public_token=?');$statement->execute([$token]);$row=$statement->fetch();return $row?:null;}
    private function participantByLocalIdentifier(string $localIdentifier): ?array{$statement=$this->pdo->prepare('SELECT id,public_token,display_name,is_online,left_at,status,rejoin_requested_at FROM quiz_participants WHERE local_identifier=? LIMIT 1');$statement->execute([$localIdentifier]);$row=$statement->fetch();return $row?:null;}
    private function assertDisplayNameAvailable(string $name,int $participantId=0): void
    {
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM quiz_participants WHERE LOWER(TRIM(display_name))=LOWER(?) AND status<>'removed' AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 12 HOUR) AND id<>?");
        $check->execute([$name,$participantId]);
        if(!(int)$check->fetchColumn())return;
        for($suffix=2;$suffix<100;$suffix++){
            $alias=mb_substr($name,0,77-mb_strlen((string)$suffix)).' '.$suffix;
            $check->execute([$alias,$participantId]);
            if(!(int)$check->fetchColumn())throw new RuntimeException("Nome già utilizzato. Prova con: $alias");
        }
        throw new RuntimeException('Nome già utilizzato. Scegli un alias diverso.');
    }
    private function answerCount(int $questionId): int{$statement=$this->pdo->prepare('SELECT COUNT(*) FROM quiz_answers WHERE question_id=?');$statement->execute([$questionId]);return (int)$statement->fetchColumn();}
    private function betCount(int $questionId): int{$statement=$this->pdo->prepare('SELECT COUNT(*) FROM quiz_bets WHERE question_id=?');$statement->execute([$questionId]);return (int)$statement->fetchColumn();}
    private function participantBet(int $questionId,int $participantId): ?array{$statement=$this->pdo->prepare('SELECT mode,stake_points,status,result_points FROM quiz_bets WHERE question_id=? AND participant_id=?');$statement->execute([$questionId,$participantId]);$row=$statement->fetch();return $row?:null;}
    private function participantScore(int $participantId,?int $groupId): int{$where=$groupId?'q.group_id=?':'q.group_id IS NULL';$answer=$this->pdo->prepare("SELECT COALESCE(SUM(a.points),0) FROM quiz_answers a INNER JOIN quiz_questions q ON q.id=a.question_id WHERE a.participant_id=? AND q.status<>'open' AND $where");$answer->execute($groupId?[$participantId,$groupId]:[$participantId]);$bets=$this->pdo->prepare("SELECT COALESCE(SUM(b.result_points),0) FROM quiz_bets b INNER JOIN quiz_questions q ON q.id=b.question_id WHERE b.participant_id=? AND b.status='settled' AND $where");$bets->execute($groupId?[$participantId,$groupId]:[$participantId]);return max(0,(int)$answer->fetchColumn()+(int)$bets->fetchColumn());}
    private function leaderboard(?int $groupId): array{$where=$groupId?'q.group_id=?':'q.group_id IS NULL';$sql="SELECT p.id,p.display_name,GREATEST(0,SUM(s.points)) points,SUM(s.correct_answers) correct_answers,SUM(s.answers) answers FROM quiz_participants p INNER JOIN (SELECT a.participant_id,SUM(a.points) points,SUM(a.is_correct) correct_answers,COUNT(a.id) answers FROM quiz_answers a INNER JOIN quiz_questions q ON q.id=a.question_id WHERE q.status<>'open' AND $where GROUP BY a.participant_id UNION ALL SELECT b.participant_id,SUM(b.result_points) points,0 correct_answers,0 answers FROM quiz_bets b INNER JOIN quiz_questions q ON q.id=b.question_id WHERE b.status='settled' AND $where GROUP BY b.participant_id) s ON s.participant_id=p.id WHERE p.status<>'removed' GROUP BY p.id,p.display_name ORDER BY points DESC,correct_answers DESC,p.display_name LIMIT 20";$statement=$this->pdo->prepare($sql);$params=$groupId?[$groupId,$groupId]:[];$statement->execute($params);return $statement->fetchAll();}
    private function participants(int $questionId): array{$statement=$this->pdo->prepare("SELECT p.id,p.display_name,p.status,p.rejoin_requested_at,IF(p.status='active' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 SECOND),1,0) online,p.last_seen_at,p.left_at,a.selected_option,COALESCE(a.is_correct,0) is_correct,COALESCE(a.points,0) points FROM quiz_participants p LEFT JOIN quiz_answers a ON a.participant_id=p.id AND a.question_id=? WHERE p.status<>'removed' ORDER BY (p.status='pending') DESC,online DESC,(a.id IS NOT NULL) DESC,p.display_name");$statement->execute([$questionId]);return $statement->fetchAll();}
    private function uuid(): string{$data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));}
}
