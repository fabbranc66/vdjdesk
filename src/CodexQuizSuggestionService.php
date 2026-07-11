<?php
declare(strict_types=1);

final class CodexQuizSuggestionService
{
    public function __construct(private PDO $pdo) {}

    public function suggest(int $trackId,string $currentQuestion=''): array
    {
        $statement=$this->pdo->prepare('SELECT id,artist,title,year,genre,album FROM tracks WHERE id=?');$statement->execute([$trackId]);$track=$statement->fetch();
        if(!$track)throw new RuntimeException('Brano non trovato per il suggerimento.');
        $historyStatement=$this->pdo->prepare('SELECT question_text FROM quiz_questions WHERE track_id=? ORDER BY id DESC LIMIT 20');$historyStatement->execute([$trackId]);$previous=$historyStatement->fetchAll(PDO::FETCH_COLUMN);
        $lastKey='quiz_codex_last_'.$trackId;$lastStatement=$this->pdo->prepare('SELECT value FROM settings WHERE `key`=?');$lastStatement->execute([$lastKey]);$last=json_decode((string)($lastStatement->fetchColumn()?:''),true);
        if(is_array($last)&&!empty($last['question']))$previous[]=(string)$last['question'];
        if(trim($currentQuestion)!=='')$previous[]=trim($currentQuestion);
        $prompt="Sei l'assistente quiz musicale di un DJ. Genera UNA domanda a scelta multipla in italiano sul brano indicato. La domanda deve essere fattualmente verificabile, adatta al pubblico di una serata e non ambigua. Cerca sul web una fonte autorevole. Fornisci quattro risposte plausibili, una sola corretta. Non usare una domanda gia proposta. Rispondi esclusivamente nel JSON richiesto.\n\nBRANO:\nArtista: {$track['artist']}\nTitolo: {$track['title']}\nAnno archivio: ".($track['year']?:'non disponibile')."\nGenere: ".($track['genre']?:'non disponibile')."\nAlbum: ".($track['album']?:'non disponibile')."\n\nDOMANDE DA NON RIPETERE:\n- ".($previous?implode("\n- ",array_unique($previous)):'nessuna');
        $output=APP_ROOT.'/storage/codex-quiz-'.bin2hex(random_bytes(8)).'.json';$schema=APP_ROOT.'/storage/quiz-suggestion-schema.json';$codex=$this->codexExecutable();$model=trim((string)setting('quiz_codex_model','gpt-5.5'))?:'gpt-5.5';
        $stderr=$this->runCodex($codex,$schema,$output,$prompt,$model);
        if(!is_file($output)&&str_contains($stderr,'model is not supported')){
            $stderr=$this->runCodex($codex,$schema,$output,$prompt,'gpt-5.5');
        }
        $json=is_file($output)?file_get_contents($output):false;@unlink($output);$result=is_string($json)?json_decode($json,true):null;
        if(!is_array($result)){
            if(str_contains($stderr,"You've hit your usage limit"))throw new RuntimeException('Limite Codex raggiunto: nuove domande disponibili al prossimo rinnovo dei crediti.');
            throw new RuntimeException('Risposta Codex non valida'.($stderr!==''?': '.mb_substr(trim($stderr),-600):'.'));
        }
        foreach(['question','option_a','option_b','option_c','option_d','correct_option','source_url'] as $key)if(trim((string)($result[$key]??''))==='')throw new RuntimeException('Codex ha restituito una domanda incompleta.');
        $result['correct_option']=strtoupper((string)$result['correct_option']);$result['track_id']=$trackId;$result['artist']=$track['artist'];$result['title']=$track['title'];$result['duration_seconds']=20;
        $save=$this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$save->execute([$lastKey,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        return ['ok'=>true,'suggestion'=>$result];
    }

    private function runCodex(string $codex,string $schema,string $output,string $prompt,string $model): string
    {
        @unlink($output);
        $command=[$codex,'exec','--model',$model,'--ephemeral','--skip-git-repo-check','--ignore-rules','--sandbox','read-only','--color','never','--output-schema',$schema,'--output-last-message',$output,'-'];
        $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Impossibile avviare Codex CLI.');
        fwrite($pipes[0],$prompt);fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$started=microtime(true);$stderr='';
        while(true){$status=proc_get_status($process);$stderr.=stream_get_contents($pipes[2]);stream_get_contents($pipes[1]);if(!$status['running'])break;if(microtime(true)-$started>90){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);@unlink($output);throw new RuntimeException('Codex non ha risposto entro 90 secondi.');}usleep(100000);}
        foreach([1,2] as $index)if(is_resource($pipes[$index]))fclose($pipes[$index]);proc_close($process);
        return $stderr;
    }

    private function codexExecutable(): string
    {
        $paths=glob('C:\\Users\\fabbr\\.vscode\\extensions\\openai.chatgpt-*-win32-x64\\bin\\windows-x86_64\\codex.exe')?:[];
        usort($paths,fn(string $left,string $right)=>filemtime($right)<=>filemtime($left));
        if($paths&&is_file($paths[0]))return $paths[0];
        throw new RuntimeException('Codex CLI non trovato sul PC.');
    }
}
