<?php
declare(strict_types=1);

final class ChillReelService
{
    public function __construct(private PDO $pdo) {}

    public function state(?int $gameId = null): array
    {
        if ($gameId) {
            $statement=$this->pdo->prepare('SELECT * FROM chill_reel_games WHERE id=?');
            $statement->execute([$gameId]);
            $game=$statement->fetch();
        } else {
            $game=$this->pdo->query("SELECT * FROM chill_reel_games ORDER BY (status IN ('booking','active')) DESC,id DESC LIMIT 1")->fetch();
        }
        $games=$this->pdo->query('SELECT id,name,status,updated_at FROM chill_reel_games ORDER BY id DESC')->fetchAll();
        if (!$game) return ['game'=>null,'games'=>$games,'tables'=>[],'puzzles'=>[],'active_game'=>setting('active_quiz_game','none'),'sound_event'=>null];
        $tables=$this->pdo->prepare("SELECT *,IF(status='active' AND is_online=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 SECOND),1,0) online FROM chill_reel_tables WHERE game_id=? ORDER BY registration_order,id");
        $tables->execute([(int)$game['id']]);
        $puzzles=$this->pdo->prepare('SELECT * FROM chill_reel_puzzles WHERE game_id=? ORDER BY sort_order,id');
        $puzzles->execute([(int)$game['id']]);
        $soundEvent=json_decode(setting('chill_reel_sound_event',''),true);
        return ['game'=>$game,'games'=>$games,'tables'=>$tables->fetchAll(),'puzzles'=>$puzzles->fetchAll(),'active_game'=>setting('active_quiz_game','none'),'sound_event'=>is_array($soundEvent)?$soundEvent:null];
    }

    public function create(array $data): array
    {
        $name=mb_substr(trim((string)($data['name']??'')),0,150);
        $tables=array_values(array_filter(array_map('trim',(array)($data['tables']??[]))));
        $puzzles=(array)($data['puzzles']??[]);
        if ($name==='') throw new RuntimeException('Inserisci il nome della manche.');
        if (count($tables)<2) throw new RuntimeException('Inserisci almeno due tavoli.');
        if (!$puzzles) throw new RuntimeException('Inserisci almeno una frase.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO chill_reel_games(name) VALUES(?)')->execute([$name]);
            $gameId=(int)$this->pdo->lastInsertId();
            $insertTable=$this->pdo->prepare('INSERT INTO chill_reel_tables(game_id,name,registration_order) VALUES(?,?,?)');
            foreach ($tables as $index=>$table) $insertTable->execute([$gameId,mb_substr($table,0,80),$index+1]);
            $insertPuzzle=$this->pdo->prepare('INSERT INTO chill_reel_puzzles(game_id,category,solution,sort_order) VALUES(?,?,?,?)');
            foreach ($puzzles as $index=>$puzzle) {
                $solution=mb_strtoupper(trim((string)($puzzle['solution']??'')));
                if ($solution==='') continue;
                $insertPuzzle->execute([$gameId,mb_substr(trim((string)($puzzle['category']??'')),0,100),mb_substr($solution,0,500),$index+1]);
            }
            $this->pdo->commit();
            if (setting('active_quiz_game','none')==='chill_reel') return $this->activate($gameId);
            return $this->state($gameId);
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function update(int $gameId, array $data): array
    {
        $this->requireGame($gameId);
        $name=mb_substr(trim((string)($data['name']??'')),0,150);
        $tables=array_values(array_filter(array_map('trim',(array)($data['tables']??[]))));
        $puzzles=array_values((array)($data['puzzles']??[]));
        if ($name==='') throw new RuntimeException('Inserisci il nome della manche.');
        if (count($tables)<2) throw new RuntimeException('Inserisci almeno due tavoli.');
        if (!$puzzles) throw new RuntimeException('Inserisci almeno una frase.');
        $existingTables=$this->pdo->prepare('SELECT id FROM chill_reel_tables WHERE game_id=? ORDER BY registration_order,id');
        $existingTables->execute([$gameId]);
        $tableIds=array_map('intval',$existingTables->fetchAll(PDO::FETCH_COLUMN));
        $existingPuzzles=$this->pdo->prepare('SELECT id FROM chill_reel_puzzles WHERE game_id=? ORDER BY sort_order,id');
        $existingPuzzles->execute([$gameId]);
        $puzzleIds=array_map('intval',$existingPuzzles->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE chill_reel_games SET name=? WHERE id=?')->execute([$name,$gameId]);
            $updateTable=$this->pdo->prepare('UPDATE chill_reel_tables SET name=?,registration_order=? WHERE id=? AND game_id=?');
            $insertTable=$this->pdo->prepare('INSERT INTO chill_reel_tables(game_id,name,registration_order) VALUES(?,?,?)');
            foreach ($tables as $index=>$table) {
                if (isset($tableIds[$index])) $updateTable->execute([mb_substr($table,0,80),$index+1,$tableIds[$index],$gameId]);
                else $insertTable->execute([$gameId,mb_substr($table,0,80),$index+1]);
            }
            $updatePuzzle=$this->pdo->prepare('UPDATE chill_reel_puzzles SET category=?,solution=?,sort_order=? WHERE id=? AND game_id=?');
            $insertPuzzle=$this->pdo->prepare('INSERT INTO chill_reel_puzzles(game_id,category,solution,sort_order) VALUES(?,?,?,?)');
            foreach ($puzzles as $index=>$puzzle) {
                $solution=mb_strtoupper(trim((string)($puzzle['solution']??'')));
                if ($solution==='') continue;
                $category=mb_substr(trim((string)($puzzle['category']??'')),0,100);
                if (isset($puzzleIds[$index])) $updatePuzzle->execute([$category,mb_substr($solution,0,500),$index+1,$puzzleIds[$index],$gameId]);
                else $insertPuzzle->execute([$gameId,$category,mb_substr($solution,0,500),$index+1]);
            }
            $this->pdo->commit();
            return $this->state($gameId);
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function activate(int $gameId): array
    {
        $this->requireGame($gameId);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE chill_reel_games SET status='draft' WHERE status IN ('booking','active')");
            $this->pdo->prepare("UPDATE chill_reel_games SET status='booking',current_puzzle_id=NULL,current_table_id=NULL,starter_table_id=NULL,solve_enabled_table_id=NULL WHERE id=?")->execute([$gameId]);
            $this->pdo->prepare('UPDATE chill_reel_tables SET booked_at=NULL WHERE game_id=?')->execute([$gameId]);
            $this->pdo->prepare("UPDATE chill_reel_puzzles SET status='draft',revealed_letters='',winner_table_id=NULL WHERE game_id=?")->execute([$gameId]);
            $settings=$this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $settings->execute(['active_quiz_game','chill_reel']);
            $settings->execute(['public_quiz_enabled','0']);
            $this->pdo->commit();
            return $this->state($gameId);
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function deactivate(int $gameId): array
    {
        $this->requireGame($gameId);
        $this->pdo->prepare("UPDATE chill_reel_games SET status='draft' WHERE id=?")->execute([$gameId]);
        $this->pdo->prepare("INSERT INTO settings(`key`,value) VALUES('active_quiz_game','none') ON DUPLICATE KEY UPDATE value='none'")->execute();
        return $this->state($gameId);
    }

    public function setStarter(int $gameId, int $tableId): array
    {
        $this->requireTable($gameId,$tableId);
        $first=$this->pdo->prepare('SELECT id FROM chill_reel_puzzles WHERE game_id=? ORDER BY sort_order,id LIMIT 1');
        $first->execute([$gameId]);
        $puzzleId=(int)($first->fetchColumn()?:0);
        if (!$puzzleId) throw new RuntimeException('La manche non contiene frasi.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE chill_reel_tables SET booked_at=IF(id=?,NOW(),NULL) WHERE game_id=?')->execute([$tableId,$gameId]);
            $this->pdo->prepare("UPDATE chill_reel_puzzles SET status=IF(id=?,'active','draft') WHERE game_id=?")->execute([$puzzleId,$gameId]);
            $this->pdo->prepare("UPDATE chill_reel_games SET status='active',starter_table_id=?,current_table_id=?,current_puzzle_id=?,solve_enabled_table_id=NULL WHERE id=?")->execute([$tableId,$tableId,$puzzleId,$gameId]);
            $this->pdo->commit();
            return $this->state($gameId);
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function nextTurn(int $gameId): array
    {
        $state=$this->state($gameId);
        if (!$state['game'] || !(int)$state['game']['current_table_id']) throw new RuntimeException('Nessun turno attivo.');
        $ids=array_map(fn(array $row): int=>(int)$row['id'],array_values(array_filter($state['tables'],static fn(array $row): bool=>(string)($row['status']??'active')==='active'&&!empty($row['public_token']))));
        if (!$ids) throw new RuntimeException('Nessun giocatore collegato.');
        $currentIndex=array_search((int)$state['game']['current_table_id'],$ids,true);
        $next=$ids[((int)$currentIndex+1)%count($ids)];
        $this->pdo->prepare('UPDATE chill_reel_games SET current_table_id=?,solve_enabled_table_id=NULL WHERE id=?')->execute([$next,$gameId]);
        return $this->state($gameId);
    }

    public function startWheel(int $gameId): array
    {
        $this->requireGame($gameId);
        $this->pdo->prepare("UPDATE chill_reel_games SET wheel_result='',wheel_spinning=1,wheel_spin_token=wheel_spin_token+1 WHERE id=? AND wheel_spinning=0")->execute([$gameId]);
        return $this->state($gameId);
    }

    public function spinWheel(int $gameId): array
    {
        $this->requireGame($gameId);
        $segments=['100','500','200','JOLLY','300','700','150','RADDOPPIA','400','250','600','PASSA','100','800','350','JOLLY','200','500','300','BANCAROTTA','1000','400','250','PASSA'];
        $result=$segments[random_int(0,count($segments)-1)];
        $this->pdo->prepare('UPDATE chill_reel_games SET wheel_result=?,wheel_spinning=2,wheel_spin_token=wheel_spin_token+1,wheel_spun_at=NOW() WHERE id=? AND wheel_spinning=1')->execute([$result,$gameId]);
        return $this->state($gameId);
    }

    public function finishWheel(int $gameId): array
    {
        $this->pdo->beginTransaction();
        try {
            $claim=$this->pdo->prepare('UPDATE chill_reel_games SET wheel_spinning=3 WHERE id=? AND wheel_spinning=2');
            $claim->execute([$gameId]);
            if ($claim->rowCount()===0) {
                $this->pdo->rollBack();
                return $this->state($gameId);
            }
            $state=$this->state($gameId);
            $tableId=(int)($state['game']['current_table_id']??0);
            $result=(string)($state['game']['wheel_result']??'');
            if (!$tableId) throw new RuntimeException('Nessun giocatore di turno.');
            $nextId=$this->nextTableId($state);
            if ($result==='PASSA') {
                $this->pdo->prepare('UPDATE chill_reel_games SET current_table_id=?,solve_enabled_table_id=NULL WHERE id=?')->execute([$nextId,$gameId]);
            } elseif (in_array($result,['ZERO','BANCAROTTA'],true)) {
                $this->pdo->prepare('UPDATE chill_reel_tables SET score=0 WHERE id=? AND game_id=?')->execute([$tableId,$gameId]);
                $this->pdo->prepare('UPDATE chill_reel_games SET current_table_id=?,solve_enabled_table_id=NULL WHERE id=?')->execute([$nextId,$gameId]);
            } elseif ($result==='RADDOPPIA') {
                $this->pdo->prepare('UPDATE chill_reel_tables SET score=score*2 WHERE id=? AND game_id=?')->execute([$tableId,$gameId]);
            }
            $this->pdo->prepare('UPDATE chill_reel_games SET wheel_spinning=0 WHERE id=?')->execute([$gameId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return $this->state($gameId);
    }

    public function startPlayerWheel(int $gameId, string $token): array
    {
        $this->requirePlayerTurn($gameId,$token);
        $this->startWheel($gameId);
        return $this->publicState($token);
    }

    public function spinPlayerWheel(int $gameId, string $token): array
    {
        $this->requirePlayerTurn($gameId,$token);
        $this->spinWheel($gameId);
        return $this->publicState($token);
    }

    public function finishPlayerWheel(int $gameId, string $token): array
    {
        $this->requirePlayerTurn($gameId,$token);
        $this->finishWheel($gameId);
        return $this->publicState($token);
    }

    public function join(string $name, string $token='', string $localIdentifier=''): array
    {
        $name=mb_substr(trim($name),0,80);
        if ($name==='') throw new RuntimeException('Inserisci il nome del tavolo o della squadra.');
        $localIdentifier=preg_match('/^[a-f0-9-]{36}$/i',$localIdentifier)?strtolower($localIdentifier):'';
        $state=$this->state();
        $game=$state['game'];
        if (!$game || $state['active_game']!=='chill_reel' || !in_array((string)$game['status'],['booking','active'],true)) throw new RuntimeException('Chill Reel non attivo.');
        $gameId=(int)$game['id'];
        $player=$this->player($token,$gameId);
        if (!$player && $localIdentifier!=='') $player=$this->playerByLocalIdentifier($localIdentifier,$gameId);
        $this->assertPlayerNameAvailable($name,$gameId,(int)($player['id']??0));
        if ($player) {
            $requiresApproval=(string)($player['status']??'active')!=='active'||(int)($player['is_online']??0)===0||!empty($player['left_at']);
            if ($requiresApproval) {
                $this->pdo->prepare("UPDATE chill_reel_tables SET name=?,status='pending',is_online=0,rejoin_requested_at=NOW(),last_seen_at=NOW() WHERE id=?")->execute([$name,$player['id']]);
                return ['ok'=>true,'pending'=>true,'player'=>$this->player((string)$player['public_token'],$gameId)];
            }
            $this->pdo->prepare("UPDATE chill_reel_tables SET name=?,local_identifier=COALESCE(local_identifier,?),last_seen_at=NOW(),is_online=1,left_at=NULL,status='active',rejoin_requested_at=NULL WHERE id=?")->execute([$name,$localIdentifier?:null,$player['id']]);
            return ['ok'=>true,'player'=>$this->player((string)$player['public_token'],$gameId)];
        }
        $available=$this->pdo->prepare("SELECT * FROM chill_reel_tables WHERE game_id=? AND public_token IS NULL AND LOWER(TRIM(name))=LOWER(?) ORDER BY registration_order,id LIMIT 1");
        $available->execute([$gameId,$name]);
        $player=$available->fetch()?:null;
        $publicToken=$this->uuid();
        if ($player) {
            $this->pdo->prepare("UPDATE chill_reel_tables SET public_token=?,local_identifier=?,last_seen_at=NOW(),is_online=1,left_at=NULL,status='active',rejoin_requested_at=NULL WHERE id=?")->execute([$publicToken,$localIdentifier?:null,$player['id']]);
            $playerId=(int)$player['id'];
        } else {
            $order=(int)$this->pdo->query('SELECT COALESCE(MAX(registration_order),0)+1 FROM chill_reel_tables WHERE game_id='.(int)$gameId)->fetchColumn();
            $this->pdo->prepare("INSERT INTO chill_reel_tables(game_id,name,registration_order,public_token,local_identifier,is_online,status) VALUES(?,?,?,?,?,1,'active')")->execute([$gameId,$name,$order,$publicToken,$localIdentifier?:null]);
            $playerId=(int)$this->pdo->lastInsertId();
        }
        return ['ok'=>true,'player'=>$this->player($publicToken,$gameId),'id'=>$playerId];
    }

    public function heartbeat(string $token, bool $online=true): array
    {
        $player=$this->player($token);
        if (!$player) throw new RuntimeException('Giocatore non riconosciuto.');
        if (!$online) {
            $this->pdo->prepare("UPDATE chill_reel_tables SET is_online=0,left_at=NOW() WHERE id=? AND status='active'")->execute([$player['id']]);
            return ['ok'=>true,'online'=>false];
        }
        if ((string)$player['status']!=='active' || !empty($player['left_at']) || (int)$player['is_online']===0) {
            $this->pdo->prepare("UPDATE chill_reel_tables SET status='pending',is_online=0,rejoin_requested_at=COALESCE(rejoin_requested_at,NOW()),last_seen_at=NOW() WHERE id=?")->execute([$player['id']]);
            return ['ok'=>true,'online'=>false,'pending'=>true];
        }
        $this->pdo->prepare('UPDATE chill_reel_tables SET last_seen_at=NOW(),is_online=1 WHERE id=?')->execute([$player['id']]);
        return ['ok'=>true,'online'=>true];
    }

    public function playerAction(int $id, string $action): array
    {
        if ($id<=0) throw new RuntimeException('Giocatore non valido.');
        if ($action==='accept') $sql="UPDATE chill_reel_tables SET status='active',is_online=1,left_at=NULL,rejoin_requested_at=NULL,last_seen_at=NOW() WHERE id=?";
        elseif ($action==='disconnect') $sql='UPDATE chill_reel_tables SET is_online=0,left_at=NOW() WHERE id=?';
        elseif ($action==='remove') $sql="UPDATE chill_reel_tables SET status='removed',is_online=0,left_at=NOW() WHERE id=?";
        elseif ($action==='delete') {$this->pdo->prepare('DELETE FROM chill_reel_tables WHERE id=?')->execute([$id]);return ['ok'=>true,'action'=>$action];}
        else throw new RuntimeException('Azione giocatore non valida.');
        $this->pdo->prepare($sql)->execute([$id]);
        return ['ok'=>true,'action'=>$action];
    }

    public function publicState(string $token=''): array
    {
        $state=$this->state();
        $game=$state['game'];
        if (!$game || $state['active_game']!=='chill_reel') return ['active'=>false,'game'=>null,'players'=>[],'player'=>null];
        $player=$this->player($token,(int)$game['id']);
        $activePuzzle=null;
        foreach ($state['puzzles'] as $puzzle) if ((int)$puzzle['id']===(int)($game['current_puzzle_id']??0)) $activePuzzle=$puzzle;
        $ownTurn=$player && (int)$player['id']===(int)($game['current_table_id']??0) && (string)$game['status']==='active';
        $numericResult=ctype_digit((string)($game['wheel_result']??'')) && (int)$game['wheel_spinning']===0;
        return [
            'active'=>true,
            'game'=>['id'=>(int)$game['id'],'name'=>(string)$game['name'],'status'=>(string)$game['status'],'wheel_result'=>(string)$game['wheel_result'],'wheel_spinning'=>(int)$game['wheel_spinning'],'current_table_id'=>(int)($game['current_table_id']??0)],
            'players'=>array_values(array_map(static fn(array $table): array=>['id'=>(int)$table['id'],'name'=>(string)$table['name'],'score'=>(int)$table['score'],'current'=>(int)$table['id']===(int)($game['current_table_id']??0)],array_filter($state['tables'],static fn(array $table): bool=>(string)($table['status']??'active')!=='removed'))),
            'player'=>$player?['id'=>(int)$player['id'],'name'=>(string)$player['name'],'score'=>(int)$player['score'],'own_turn'=>$ownTurn,'status'=>(string)$player['status'],'public_token'=>(string)$player['public_token']]:null,
            'puzzle'=>$activePuzzle?['id'=>(int)$activePuzzle['id'],'category'=>(string)$activePuzzle['category'],'revealed_letters'=>(string)$activePuzzle['revealed_letters']]:null,
            'can_spin'=>$ownTurn && (int)$game['wheel_spinning']===0 && !$numericResult,
            'can_choose_letter'=>$ownTurn && $numericResult,
            'can_solve'=>$ownTurn && (int)$game['wheel_spinning']===0 && $activePuzzle!==null && (int)($game['solve_enabled_table_id']??0)===(int)$player['id'],
        ];
    }

    public function chooseLetter(int $gameId, string $token, string $letter): array
    {
        $tableId=$this->requirePlayerTurn($gameId,$token);
        $letter=mb_strtoupper(trim($letter));
        if (!preg_match('/^[A-ZÀ-ÖØ-Ý]$/u',$letter)) throw new RuntimeException('Scegli una lettera.');
        $isVowel=(bool)preg_match('/^[AEIOUÀÈÉÌÒÙ]$/u',$letter);
        $state=$this->state($gameId);
        if (!$state['game'] || (int)$state['game']['current_table_id']!==$tableId) throw new RuntimeException('Non è il tuo turno.');
        $value=(string)($state['game']['wheel_result']??'');
        if (!ctype_digit($value) || (int)$state['game']['wheel_spinning']!==0) throw new RuntimeException('Gira prima la ruota.');
        $puzzleId=(int)($state['game']['current_puzzle_id']??0);
        $puzzle=current(array_filter($state['puzzles'],static fn(array $row): bool=>(int)$row['id']===$puzzleId));
        if (!$puzzle) throw new RuntimeException('Nessuna frase attiva.');
        $letters=(string)($puzzle['revealed_letters']??'');
        if (str_contains($letters,$letter)) throw new RuntimeException('Lettera già chiamata.');
        $occurrences=substr_count(mb_strtoupper((string)$puzzle['solution']),$letter);
        preg_match_all('/[B-DF-HJ-NP-TV-Z]/',mb_strtoupper((string)$puzzle['solution']),$consonantMatches);
        $solutionConsonants=array_values(array_unique($consonantMatches[0]??[]));
        $remainingBefore=array_filter($solutionConsonants,static fn(string $consonant): bool=>!str_contains($letters,$consonant));
        $remainingAfter=array_filter($solutionConsonants,static fn(string $consonant): bool=>!str_contains($letters.$letter,$consonant));
        $consonantsFinished=!$isVowel
            && $occurrences>0
            && count($remainingBefore)===1
            && in_array($letter,$remainingBefore,true)
            && count($remainingAfter)===0;
        $tableScore=0;
        foreach ($state['tables'] as $table) if ((int)$table['id']===$tableId) $tableScore=(int)$table['score'];
        if ($isVowel && $tableScore<100) throw new RuntimeException('Servono almeno 100 punti per scegliere una vocale.');
        $nextId=$this->nextTableId($state);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE chill_reel_puzzles SET revealed_letters=? WHERE id=?')->execute([$letters.$letter,$puzzleId]);
            if ($isVowel) {
                $this->pdo->prepare('UPDATE chill_reel_tables SET score=score-100 WHERE id=? AND game_id=?')->execute([$tableId,$gameId]);
                if ($occurrences>0) $this->pdo->prepare('UPDATE chill_reel_games SET solve_enabled_table_id=? WHERE id=?')->execute([$tableId,$gameId]);
                else $this->pdo->prepare('UPDATE chill_reel_games SET current_table_id=?,solve_enabled_table_id=NULL WHERE id=?')->execute([$nextId,$gameId]);
            } elseif ($occurrences>0) {
                $this->pdo->prepare('UPDATE chill_reel_tables SET score=score+? WHERE id=? AND game_id=?')->execute([(int)$value*$occurrences,$tableId,$gameId]);
                $this->pdo->prepare('UPDATE chill_reel_games SET solve_enabled_table_id=? WHERE id=?')->execute([$tableId,$gameId]);
            } else $this->pdo->prepare('UPDATE chill_reel_games SET current_table_id=?,solve_enabled_table_id=NULL WHERE id=?')->execute([$nextId,$gameId]);
            $this->pdo->prepare("UPDATE chill_reel_games SET wheel_result='' WHERE id=?")->execute([$gameId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        if ($occurrences===0) $this->publishSoundEvent($gameId,'no_letter');
        elseif ($consonantsFinished) $this->publishSoundEvent($gameId,'no_consonants');
        $payload=$this->publicState($token);
        $payload['letter_result']=['letter'=>$letter,'occurrences'=>$occurrences,'points'=>$isVowel?-100:(int)$value*$occurrences,'vowel'=>$isVowel];
        return $payload;
    }

    public function solveFromPlayer(int $gameId, string $token, string $answer): array
    {
        $tableId=$this->requirePlayerTurn($gameId,$token);
        $state=$this->state($gameId);
        if (!$state['game'] || (int)$state['game']['current_table_id']!==$tableId) throw new RuntimeException('Non è il tuo turno.');
        $puzzleId=(int)($state['game']['current_puzzle_id']??0);
        $puzzle=current(array_filter($state['puzzles'],static fn(array $row): bool=>(int)$row['id']===$puzzleId));
        if (!$puzzle) throw new RuntimeException('Nessuna frase attiva.');
        $normalize=static fn(string $value): string=>preg_replace('/[^A-ZÀ-ÖØ-Ý0-9]+/u','',mb_strtoupper(trim($value)))??'';
        $correct=$normalize($answer)!=='' && $normalize($answer)===$normalize((string)$puzzle['solution']);
        if ($correct) $this->nextPuzzle($gameId,$tableId);
        else $this->pdo->prepare('UPDATE chill_reel_games SET current_table_id=?,solve_enabled_table_id=NULL WHERE id=?')->execute([$this->nextTableId($state),$gameId]);
        $this->publishSoundEvent($gameId,$correct?'correct':'wrong');
        $payload=$this->publicState($token);
        $payload['solve_correct']=$correct;
        return $payload;
    }

    public function revealLetter(int $gameId, string $letter): array
    {
        $letter=mb_strtoupper(trim($letter));
        if (!preg_match('/^[A-ZÀ-ÖØ-Ý]$/u',$letter)) throw new RuntimeException('Inserisci una sola lettera.');
        $state=$this->state($gameId);
        $puzzleId=(int)($state['game']['current_puzzle_id']??0);
        if (!$puzzleId) throw new RuntimeException('Nessuna frase attiva.');
        $puzzle=current(array_filter($state['puzzles'],fn(array $row): bool=>(int)$row['id']===$puzzleId));
        $letters=(string)($puzzle['revealed_letters']??'');
        if (!str_contains($letters,$letter)) $letters.=$letter;
        $this->pdo->prepare('UPDATE chill_reel_puzzles SET revealed_letters=? WHERE id=?')->execute([$letters,$puzzleId]);
        return $this->state($gameId);
    }

    public function nextPuzzle(int $gameId, int $winnerTableId): array
    {
        $state=$this->state($gameId);
        $currentId=(int)($state['game']['current_puzzle_id']??0);
        if (!$currentId) throw new RuntimeException('Nessuna frase attiva.');
        if ($winnerTableId) $this->requireTable($gameId,$winnerTableId);
        $nextId=0;
        foreach ($state['puzzles'] as $index=>$puzzle) if ((int)$puzzle['id']===$currentId) $nextId=(int)($state['puzzles'][$index+1]['id']??0);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE chill_reel_puzzles SET status='solved',winner_table_id=? WHERE id=?")->execute([$winnerTableId?:null,$currentId]);
            if ($nextId) {
                $this->pdo->prepare("UPDATE chill_reel_puzzles SET status='active' WHERE id=?")->execute([$nextId]);
                $this->pdo->prepare('UPDATE chill_reel_games SET current_puzzle_id=?,current_table_id=COALESCE(?,current_table_id),solve_enabled_table_id=NULL WHERE id=?')->execute([$nextId,$winnerTableId?:null,$gameId]);
            } else {
                $this->pdo->prepare("UPDATE chill_reel_games SET status='completed',current_puzzle_id=NULL,solve_enabled_table_id=NULL WHERE id=?")->execute([$gameId]);
            }
            $this->pdo->commit();
            return $this->state($gameId);
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function requireGame(int $gameId): void
    {
        $check=$this->pdo->prepare('SELECT COUNT(*) FROM chill_reel_games WHERE id=?');$check->execute([$gameId]);
        if (!(int)$check->fetchColumn()) throw new RuntimeException('Manche Chill Reel non trovata.');
    }

    private function publishSoundEvent(int $gameId, string $type): void
    {
        $event=json_encode(['id'=>sprintf('%d-%d',hrtime(true),random_int(1000,9999)),'game_id'=>$gameId,'type'=>$type],JSON_THROW_ON_ERROR);
        $this->pdo->prepare("INSERT INTO settings(`key`,value) VALUES('chill_reel_sound_event',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$event]);
    }

    private function requireTable(int $gameId, int $tableId): void
    {
        $check=$this->pdo->prepare('SELECT COUNT(*) FROM chill_reel_tables WHERE id=? AND game_id=?');$check->execute([$tableId,$gameId]);
        if (!(int)$check->fetchColumn()) throw new RuntimeException('Tavolo non valido.');
    }

    private function requirePlayerTurn(int $gameId, string $token): int
    {
        $player=$this->player($token,$gameId);
        if (!$player || (string)$player['status']!=='active') throw new RuntimeException('Giocatore non riconosciuto.');
        $game=$this->pdo->prepare("SELECT current_table_id,status FROM chill_reel_games WHERE id=?");
        $game->execute([$gameId]);
        $row=$game->fetch();
        if (!$row || (string)$row['status']!=='active' || (int)$row['current_table_id']!==(int)$player['id']) throw new RuntimeException('Non è il tuo turno.');
        return (int)$player['id'];
    }

    private function player(string $token, ?int $gameId=null): ?array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i',$token)) return null;
        $sql='SELECT * FROM chill_reel_tables WHERE public_token=?'.($gameId?' AND game_id=?':'').' LIMIT 1';
        $statement=$this->pdo->prepare($sql);
        $statement->execute($gameId?[$token,$gameId]:[$token]);
        $row=$statement->fetch();
        return $row?:null;
    }

    private function playerByLocalIdentifier(string $localIdentifier, int $gameId): ?array
    {
        $statement=$this->pdo->prepare('SELECT * FROM chill_reel_tables WHERE local_identifier=? AND game_id=? LIMIT 1');
        $statement->execute([$localIdentifier,$gameId]);
        $row=$statement->fetch();
        return $row?:null;
    }

    private function assertPlayerNameAvailable(string $name, int $gameId, int $playerId=0): void
    {
        $statement=$this->pdo->prepare("SELECT COUNT(*) FROM chill_reel_tables WHERE game_id=? AND LOWER(TRIM(name))=LOWER(?) AND status<>'removed' AND public_token IS NOT NULL AND id<>?");
        $statement->execute([$gameId,$name,$playerId]);
        if ((int)$statement->fetchColumn()>0) throw new RuntimeException('Nome già utilizzato in questa manche.');
    }

    private function uuid(): string
    {
        $data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
    }

    private function nextTableId(array $state): int
    {
        $ids=array_map(static fn(array $row): int=>(int)$row['id'],array_values(array_filter($state['tables'],static fn(array $row): bool=>(string)($row['status']??'active')==='active'&&!empty($row['public_token']))));
        if (!$ids) throw new RuntimeException('Nessun giocatore disponibile.');
        $currentIndex=array_search((int)($state['game']['current_table_id']??0),$ids,true);
        return $ids[((int)($currentIndex===false?-1:$currentIndex)+1)%count($ids)];
    }
}
