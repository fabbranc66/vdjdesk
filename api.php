<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/LibraryService.php';
require __DIR__ . '/src/SuggestionService.php';
require __DIR__ . '/src/EDuplicateService.php';
require __DIR__ . '/src/EComparisonService.php';
require __DIR__ . '/src/VirtualDjControlService.php';
require __DIR__ . '/src/VirtualDjHistoryService.php';
require __DIR__ . '/src/SpotifyAudioFeaturesService.php';
require __DIR__ . '/src/AudioReplacementService.php';
require __DIR__ . '/src/TrackDeletionService.php';
require __DIR__ . '/src/PlaylistService.php';
require __DIR__ . '/src/QuizService.php';
require __DIR__ . '/src/CodexQuizSuggestionService.php';
require __DIR__ . '/src/LibraryStandardService.php';
require __DIR__ . '/src/RequestEstimateService.php';

try {
    $pdo = db();
    $library = new LibraryService($pdo);
    $virtualDjHistory = new VirtualDjHistoryService($pdo);
    $virtualDjControl = new VirtualDjControlService($pdo);
    $quiz = new QuizService($pdo);
    $requestEstimate = new RequestEstimateService($pdo);
    $action = (string) ($_GET['action'] ?? 'bootstrap');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data = requestData();

    if ($action === 'bootstrap') {
        $remoteBootstrap = appUsesLocalFiles() ? remoteApiFetch('bootstrap') : null;
        $live = $virtualDjHistory->snapshot();
        try { $live['current'] = $virtualDjControl->currentTrack() ?? $live['current']; } catch (Throwable) {}
        $settings = $pdo->query('SELECT `key`,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $suggestionStart = !empty($settings['suggestion_start_track_id']) ? $library->find((int) $settings['suggestion_start_track_id']) : null;
        $requestCounts = is_array($remoteBootstrap) ? (array) ($remoteBootstrap['request_counts'] ?? []) : $pdo->query('SELECT status,COUNT(*) total FROM requests GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $driveEExpression = appUsesLocalFiles() ? "SUM(file_exists=1 AND UPPER(file_path) LIKE 'E:%')" : "SUM(UPPER(file_path) LIKE 'E:%')";
        $trackStats = $pdo->query("
            SELECT
                COUNT(*) total,
                SUM(file_exists=1) present,
                SUM(file_exists=0) missing,
                $driveEExpression drive_e
            FROM tracks
            WHERE UPPER(file_path) LIKE 'E:%'
        ")->fetch();
        jsonResponse([
            'current' => $live['current'],
            'recent' => $live['recent'],
            'session_started_at' => $live['started_at'],
            'history_source' => $live['source_file'],
            'environment' => [
                'local_files' => appUsesLocalFiles(),
                'mode' => appUsesLocalFiles() ? 'local' : 'hosting',
            ],
            'suggestion_start' => $suggestionStart,
            'settings' => $settings,
            'request_counts' => $requestCounts,
            'stats' => [
                'tracks'=>(int) ($trackStats['drive_e'] ?? 0),
                'track_records'=>(int) ($trackStats['total'] ?? 0),
                'track_missing'=>(int) ($trackStats['missing'] ?? 0),
                'tracks_e'=>(int) ($trackStats['drive_e'] ?? 0),
                'duplicates'=>$library->duplicateGroupCount(),
                'requests'=>is_array($remoteBootstrap) ? (int) ($remoteBootstrap['stats']['requests'] ?? 0) : (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status='new'")->fetchColumn(),
                'played_today'=>$live['total'],
            ],
            'tags' => ['APERTURA','APERITIVO','CENA','WARMUP','URLANTE','CHIUSURA','DONNE','UOMINI','KARAOKE','BALLI DI GRUPPO','COMMERCIALE','RAP IT','CAMBIO GENERE SICURO'],
            'automatic_tags' => ['PISTA','ALTA ENERGIA','CANTO','PICCO','RECUPERO PISTA','SUCCESSO','POPOLARE','REGGAETON','DEMBOW','BACHATA','SALSA','TIMBA','CUBATON','URBAN','EDM'],
        ]);
    }
    if ($action === 'live') {
        $live = $virtualDjHistory->snapshot();
        try { $live['current'] = $virtualDjControl->currentTrack() ?? $live['current']; } catch (Throwable) {}
        $live['requests'] = (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status='new'")->fetchColumn();
        jsonResponse($live);
    }
    if ($action === 'tracks') {
        $items = $library->search($_GET);
        $total = $library->searchCount($_GET);
        $offset = max(0, (int)($_GET['offset']??0));
        $limit = min(200, max(1, (int)($_GET['limit']??60)));
        jsonResponse(['items'=>$items,'total'=>$total,'offset'=>$offset,'limit'=>$limit,'has_more'=>$offset+count($items)<$total]);
    }
    if ($action === 'studio-issues') {
        $extension = "LOWER(SUBSTRING_INDEX(file_name,'.',-1))";
        $audio = "$extension IN ('mp3','m4a','aac','ogg','opus','wma','flac','wav','aiff','aif','alac')";
        $standard = "(($extension='mp3' AND COALESCE(bitrate,0)>=320) OR $extension IN ('flac','wav','aiff','aif','alac'))";
        $base = (appUsesLocalFiles() ? "file_exists=1 AND " : "") . "UPPER(file_path) LIKE 'E:%'";
        $counts = [
            'below_standard' => "($base AND $audio AND NOT $standard)",
            'no_spotify_id' => "($base AND COALESCE(spotify_id,'')='')",
            'missing_metrics' => "($base AND COALESCE(spotify_id,'')<>'' AND (spotify_features_updated_at IS NULL OR spotify_features_status NOT IN ('complete','partial')))",
            'missing_genre' => "($base AND TRIM(COALESCE(genre,''))='')",
            'missing_year' => "($base AND (year IS NULL OR year=0))",
            'missing_key' => "($base AND TRIM(COALESCE(camelot,''))='' AND TRIM(COALESCE(musical_key,''))='')",
        ];
        $items = [];
        foreach ($counts as $key => $where) {
            $items[$key] = (int) $pdo->query("SELECT COUNT(*) FROM tracks WHERE $where")->fetchColumn();
        }
        jsonResponse(['items'=>$items]);
    }
    if ($action === 'track') {
        $track = $library->find((int) ($_GET['id'] ?? 0));
        $track ? jsonResponse($track) : jsonResponse(['error'=>'Brano non trovato'], 404);
    }
    if ($action === 'playlists') jsonResponse(['root'=>(new PlaylistService())->root(),'items'=>(new PlaylistService())->playlists()]);
    if ($action === 'playlist-create' && $method === 'POST') jsonResponse((new PlaylistService())->create((string)($data['name']??'')));
    if ($action === 'playlist-detail') jsonResponse((new PlaylistService())->detail((string)($_GET['file']??'')));
    if ($action === 'playlist-candidates') jsonResponse((new PlaylistService())->candidates((string)($_GET['file']??''),$_GET));
    if ($action === 'playlist-external-compare' && $method === 'POST') jsonResponse((new PlaylistService())->compareExternalSpotifyList((array)($data['items']??[])));
    if ($action === 'playlist-external-folder-match' && $method === 'POST') jsonResponse((new PlaylistService())->matchExternalListToFolder((array)($data['items']??[]),(string)($data['folder']??'')));
    if ($action === 'playlist-external-apply-metadata' && $method === 'POST') jsonResponse((new PlaylistService())->applyExternalMetadata((array)($data['matches']??[])));
    if ($action === 'playlist-save-order' && $method === 'POST') jsonResponse((new PlaylistService())->saveOrder((string)($data['file']??''),(array)($data['paths']??[])));
    if ($action === 'playlist-replace-track' && $method === 'POST') jsonResponse((new PlaylistService())->replaceInPlaylist((string)($data['file']??''),(string)($data['old_path']??''),(string)($data['new_path']??'')));
    if ($action === 'playlist-remove-track' && $method === 'POST') jsonResponse((new PlaylistService())->removeFromPlaylist((string)($data['file']??''),(int)($data['index']??-1),(string)($data['path']??'')));
    if ($action === 'duplicates') jsonResponse(['groups'=>$library->duplicates((int)($_GET['limit']??200)),'total_groups'=>$library->duplicateGroupCount(),'issues'=>$library->issues()]);
    if ($action === 'database-status') jsonResponse(['items'=>$library->virtualDjDatabaseStatus()]);
    if ($action === 'music-roots') jsonResponse(['items'=>$library->musicRootOptions()]);
    if ($action === 'comparison-folders') jsonResponse(['items'=>$library->comparisonFolderOptions()]);
    if ($action === 'definitive-library-folders') jsonResponse(['items'=>$library->definitiveLibraryFolderOptions()]);
    if ($action === 'e-duplicates-status') jsonResponse(['scan'=>(new EDuplicateService($pdo))->latest()]);
    if ($action === 'e-duplicates-refresh-recommendations' && $method === 'POST') jsonResponse(['ok'=>true,'updated'=>(new EDuplicateService($pdo))->refreshRecommendations()]);
    if ($action === 'e-duplicates') jsonResponse((new EDuplicateService($pdo))->groups((string)($_GET['type']??'all'),(int)($_GET['limit']??100),(int)($_GET['offset']??0),(string)($_GET['folder_root']??'')));
    if ($action === 'deletion-candidates') jsonResponse(['items'=>(new EComparisonService($pdo))->candidates((string)($_GET['folder']??''),(string)($_GET['status']??''))]);
    if ($action === 'approved-folder-summary') jsonResponse(['items'=>(new EComparisonService($pdo))->approvedFolderSummary()]);
    if ($action === 'vdj-control-status') jsonResponse($virtualDjControl->status());
    if ($action === 'suggestions') {
        $service = new SuggestionService($pdo, $library, $virtualDjHistory);
        jsonResponse(['items'=>$service->suggest(
            (int) ($_GET['current_id'] ?? 0),
            (string) ($_GET['mode'] ?? 'same'),
            (string) ($_GET['tag'] ?? '')
        )]);
    }
    if (appUsesLocalFiles() && shouldProxyToHosting($action)) {
        if ($action === 'request-update' && $method === 'POST' && (string)($data['status'] ?? '') === 'queued') {
            $trackId=(int)($data['track_id']??0);
            if($trackId<1)jsonResponse(['error'=>'Per inviare ad Automix serve un brano collegato alla libreria.'],422);
            $virtualDjControl->addTrackToAutomix($trackId);
            usleep(350000);
        }
        remoteApiPassthrough($action, $method, $data);
    }
    if ($action === 'requests' && $method === 'GET') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $requestEstimate->recalculate();
        $statement = $pdo->prepare("SELECT r.*,t.artist,t.title,t.file_path FROM requests r LEFT JOIN tracks t ON t.id=r.track_id WHERE (:q='' OR r.query LIKE :like OR r.guest_name LIKE :like) ORDER BY CASE r.status WHEN 'new' THEN 0 WHEN 'next' THEN 1 WHEN 'queued' THEN 2 WHEN 'approved' THEN 3 ELSE 4 END,r.created_at DESC");
        $statement->execute([':q'=>$query,':like'=>'%'.$query.'%']);
        jsonResponse(['items'=>$statement->fetchAll()]);
    }
    if ($action === 'public-search') {
        $items = $library->search(['q'=>$_GET['q'] ?? '', 'limit'=>50]);
        jsonResponse(['items'=>array_map(fn($track)=>['id'=>$track['id'],'artist'=>$track['artist'],'title'=>$track['title'],'genre'=>$track['genre'],'year'=>$track['year']], $items)]);
    }
    if ($action === 'request-create' && $method === 'POST') {
        $query = trim((string) ($data['query'] ?? ''));
        if ($query === '') jsonResponse(['error'=>'Scrivi un brano o scegli un risultato.'], 422);
        $limitMinutes=max(0,(int)setting('request_interval_minutes','5'));
        $clientToken=substr(trim((string)($data['client_token']??'')),0,80);
        $clientIp=(string)($_SERVER['REMOTE_ADDR']??'');
        if($limitMinutes>0){
            $check=$pdo->prepare("SELECT created_at FROM requests WHERE created_at>=DATE_SUB(NOW(),INTERVAL ? MINUTE) AND ((client_token<>'' AND client_token=?) OR client_ip=?) ORDER BY created_at DESC LIMIT 1");
            $check->execute([$limitMinutes,$clientToken,$clientIp]);
            if((string)($check->fetchColumn()?:'')!=='')jsonResponse(['error'=>'Puoi inviare una richiesta ogni '.$limitMinutes.' minuti.'],429);
        }
        $token=$requestEstimate->token();
        $statement = $pdo->prepare('INSERT INTO requests(guest_name,query,track_id,public_token,client_token,client_ip) VALUES(?,?,?,?,?,?)');
        $statement->execute([trim((string) ($data['guest_name'] ?? '')),$query,!empty($data['track_id'])?(int)$data['track_id']:null,$token,$clientToken,$clientIp]);
        jsonResponse(['ok'=>true,'token'=>$token,'message'=>'Richiesta inviata al DJ. Ti aggiorno qui appena viene messa in coda.'], 201);
    }
    if ($action === 'request-status') {
        jsonResponse($requestEstimate->status((string)($_GET['token']??'')));
    }
    if ($action === 'request-estimates-refresh') {
        $requestEstimate->recalculate();
        jsonResponse(['ok'=>true,'refreshed_at'=>date('Y-m-d H:i:s')]);
    }
    if ($action === 'request-automix-debug') {
        jsonResponse($requestEstimate->debugAutomix());
    }
    if ($action === 'request-update' && $method === 'POST') {
        $allowed = ['new','approved','rejected','next','queued','played'];
        $status = (string) ($data['status'] ?? 'new');
        if (!in_array($status, $allowed, true)) jsonResponse(['error'=>'Stato non valido'], 422);
        if ($status === 'queued' && appUsesLocalFiles()) {
            $trackId=(int)($data['track_id']??0);
            if($trackId<1)jsonResponse(['error'=>'Per inviare ad Automix serve un brano collegato alla libreria.'],422);
            $virtualDjControl->addTrackToAutomix($trackId);
            usleep(350000);
        }
        $statement = $pdo->prepare('UPDATE requests SET status=?,note=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $statement->execute([$status,trim((string)($data['note'] ?? '')),(int)($data['id'] ?? 0)]);
        $requestEstimate->recalculate();
        jsonResponse(['ok'=>true,'automix'=>$status==='queued']);
    }
    if ($action === 'request-delete' && $method === 'POST') {
        $requestId=(int)($data['id']??0);
        if($requestId<1)throw new RuntimeException('Richiesta non valida.');
        $statement=$pdo->prepare('DELETE FROM requests WHERE id=?');$statement->execute([$requestId]);
        jsonResponse(['ok'=>true,'deleted'=>$statement->rowCount()]);
    }
    if ($action === 'quiz-state') jsonResponse($quiz->state((string)($_GET['token']??''),(bool)($_GET['control']??false)));
    if ($action === 'quiz-history') jsonResponse(['items'=>$quiz->history((int)($_GET['limit']??30))]);
    if ($action === 'quiz-create' && $method === 'POST') jsonResponse($quiz->create($data),201);
    if ($action === 'quiz-launch' && $method === 'POST') jsonResponse($quiz->launch((int)($data['id']??0)));
    if ($action === 'quiz-close' && $method === 'POST') jsonResponse($quiz->setStatus((int)($data['id']??0),'closed'));
    if ($action === 'quiz-reveal' && $method === 'POST') jsonResponse($quiz->setStatus((int)($data['id']??0),'revealed'));
    if ($action === 'quiz-join' && $method === 'POST') jsonResponse($quiz->join((string)($data['name']??''),(string)($data['token']??'')));
    if ($action === 'quiz-answer' && $method === 'POST') jsonResponse($quiz->answer((int)($data['question_id']??0),(string)($data['token']??''),(string)($data['option']??'')));
    if ($action === 'quiz-codex-suggest' && $method === 'POST') jsonResponse((new CodexQuizSuggestionService($pdo))->suggest((int)($data['track_id']??0),(string)($data['current_question']??'')));
    if ($action === 'quiz-heartbeat' && $method === 'POST') jsonResponse($quiz->heartbeat((string)($data['token']??''),true));
    if ($action === 'quiz-leave' && $method === 'POST') jsonResponse($quiz->heartbeat((string)($data['token']??''),false));
    if ($action === 'quiz-participant-action' && $method === 'POST') jsonResponse($quiz->participantAction((int)($data['id']??0),(string)($data['action']??'')));
    if ($action === 'quiz-prefill' && $method === 'POST') {
        $payload=['nonce'=>microtime(true),'track_id'=>(int)($data['track_id']??0),'question'=>trim((string)($data['question']??'')),'option_a'=>trim((string)($data['option_a']??'')),'option_b'=>trim((string)($data['option_b']??'')),'option_c'=>trim((string)($data['option_c']??'')),'option_d'=>trim((string)($data['option_d']??'')),'correct_option'=>strtoupper(trim((string)($data['correct_option']??'A'))),'duration_seconds'=>(int)($data['duration_seconds']??20)];
        $statement=$pdo->prepare("INSERT INTO settings(`key`,value) VALUES('quiz_prefill',?) ON DUPLICATE KEY UPDATE value=VALUES(value)");$statement->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);jsonResponse(['ok'=>true,'prefill'=>$payload]);
    }
    if ($action === 'quiz-prefill') {$value=setting('quiz_prefill','');$payload=json_decode((string)$value,true);jsonResponse(['prefill'=>is_array($payload)?$payload:null]);}
    if ($action === 'network-info') {
        $ip=localNetworkIp();
        $base=appUsesLocalFiles()?'http://'.$ip.'/vdjdesk/':rtrim((string) setting('hosting_base_url', 'https://www.kr-solutions.it/vdjdesk'), '/').'/';
        jsonResponse(['ip'=>$ip,'public_url'=>$base.'request.php','screen_url'=>$base.'quiz-screen.php']);
    }
    if ($action === 'track-update' && $method === 'POST') {
        $trackId = (int) ($data['id'] ?? 0);
        $tags = array_values(array_unique(array_filter((array) ($data['tags'] ?? []), 'is_string')));
        $statement = $pdo->prepare('UPDATE tracks SET tags=?,energy=?,singability=?,danceability=?,familiarity=?,risk=?,dj_scores_manual=1,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $statement->execute([json_encode($tags,JSON_UNESCAPED_UNICODE),boundScore($data['energy']??3),boundScore($data['singability']??3),boundScore($data['danceability']??3),boundScore($data['familiarity']??3),boundScore($data['risk']??3),$trackId]);
        jsonResponse(['ok'=>true,'track'=>$library->find($trackId)]);
    }
    if ($action === 'bulk-track-tags' && $method === 'POST') {
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($data['ids']??[])),fn(int $id): bool=>$id>0)));
        $tags=array_values(array_unique(array_filter(array_map(fn($tag)=>trim((string)$tag),(array)($data['tags']??[])),fn(string $tag): bool=>$tag!=='')));
        $mode=(string)($data['mode']??'add');
        if(!$ids||!$tags||!in_array($mode,['add','remove','replace'],true))throw new RuntimeException('Operazione tag globale non valida.');
        $placeholders=implode(',',array_fill(0,count($ids),'?'));$select=$pdo->prepare("SELECT id,tags FROM tracks WHERE id IN ($placeholders) AND file_exists=1");$select->execute($ids);$rows=$select->fetchAll();
        $update=$pdo->prepare('UPDATE tracks SET tags=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$pdo->beginTransaction();
        try{foreach($rows as $row){$current=trackTags($row);if($mode==='replace')$next=$tags;elseif($mode==='remove')$next=array_values(array_diff($current,$tags));else $next=array_values(array_unique(array_merge($current,$tags)));$update->execute([json_encode($next,JSON_UNESCAPED_UNICODE),(int)$row['id']]);}$pdo->commit();}catch(Throwable $error){$pdo->rollBack();throw $error;}
        jsonResponse(['ok'=>true,'updated'=>count($rows),'mode'=>$mode,'tags'=>$tags]);
    }
    if ($action === 'bulk-vdj-metadata' && $method === 'POST') jsonResponse($library->importMetadataFromVirtualDj((array)($data['ids']??[])));
    if ($action === 'auto-tag-override' && $method === 'POST') {
        $trackId=(int)($data['id']??0);$tag=trim((string)($data['tag']??''));$enabled=(bool)($data['enabled']??false);
        if($trackId<1||$tag==='')throw new RuntimeException('Tag automatico non valido.');
        $statement=$pdo->prepare('SELECT auto_tag_overrides FROM tracks WHERE id=?');$statement->execute([$trackId]);$row=$statement->fetch();
        if(!$row)throw new RuntimeException('Brano non trovato.');
        $overrides=autoTagOverrides($row);$overrides[$tag]=$enabled;
        $pdo->prepare('UPDATE tracks SET auto_tag_overrides=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([json_encode($overrides,JSON_UNESCAPED_UNICODE),$trackId]);
        jsonResponse(['ok'=>true,'track'=>$library->find($trackId)]);
    }
    if ($action === 'spotify-audio-features' && $method === 'POST') {
        $trackId = (int) ($data['id'] ?? 0);
        $spotify = (new SpotifyAudioFeaturesService($pdo))->refreshReplacementMetadata($trackId);
        jsonResponse(['ok'=>true,'features'=>$spotify['features'],'artist'=>$spotify['artist'],'title'=>$spotify['title'],'track'=>$library->find($trackId)]);
    }
    if ($action === 'touch-tracks' && $method === 'POST') {
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($data['ids']??[])),fn(int $id): bool=>$id>0)));
        if(count($ids)>2000)throw new RuntimeException('Troppi brani da aggiornare in una sola operazione.');
        if(!$ids)jsonResponse(['ok'=>true,'updated'=>0,'missing'=>0]);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $statement=$pdo->prepare("SELECT id,file_path FROM tracks WHERE id IN ($placeholders) AND file_exists=1");$statement->execute($ids);
        $updated=0;$missing=0;$vdjUpdated=0;$vdjFailed=0;
        foreach($statement->fetchAll() as $track){$path=canonicalPath((string)$track['file_path']);if(is_file($path)&&@touch($path)){$updated++;try{$virtualDjControl->markTrackAsNew((int)$track['id'])?$vdjUpdated++:$vdjFailed++;}catch(Throwable){$vdjFailed++;}}else$missing++;}
        jsonResponse(['ok'=>true,'updated'=>$updated,'missing'=>$missing,'vdj_updated'=>$vdjUpdated,'vdj_failed'=>$vdjFailed]);
    }
    if ($action === 'touch-track-file' && $method === 'POST') {
        $trackId=(int)($data['id']??0);$track=$library->find($trackId);
        if(!$track)throw new RuntimeException('Brano KR Desk non trovato.');
        $path=canonicalPath((string)$track['file_path']);
        if(!is_file($path)||!@touch($path))throw new RuntimeException('Data del file non aggiornata.');
        jsonResponse(['ok'=>true,'id'=>$trackId,'path'=>$path]);
    }
    if ($action === 'spotify-link-update' && $method === 'POST') {
        $trackId=(int)($data['id']??0);$url=trim((string)($data['url']??''));$spotifyId='';
        if(preg_match('~open\.spotify\.com/(?:intl-[a-z]+/)?track/([A-Za-z0-9]{22})~i',$url,$match))$spotifyId=$match[1];
        elseif(preg_match('~^spotify:track:([A-Za-z0-9]{22})$~i',$url,$match))$spotifyId=$match[1];
        if($trackId<1||$spotifyId==='')throw new RuntimeException('Link Spotify traccia non valido.');
        $canonical='https://open.spotify.com/track/'.$spotifyId;
        $pdo->prepare("UPDATE tracks SET spotify_id=?,spotify_url=?,spotify_features_status='never',spotify_features_error='',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$spotifyId,$canonical,$trackId]);
        jsonResponse(['ok'=>true,'track'=>$library->find($trackId)]);
    }
    if ($action === 'spotify-clipboard-start' && $method === 'POST') {
        $trackId=(int)($data['id']??0);if(!$library->find($trackId))throw new RuntimeException('Brano non trovato.');
        if(!empty($data['force']))$pdo->prepare("UPDATE tracks SET spotify_id='',spotify_url='',spotify_features_status='never',spotify_features_error='',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$trackId]);
        @shell_exec('powershell.exe -NoProfile -Command "Set-Clipboard -Value \'\'"');
        $statement=$pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $statement->execute(['spotify_clipboard_track_id',(string)$trackId]);$statement->execute(['spotify_clipboard_started_at',(string)time()]);
        jsonResponse(['ok'=>true,'track_id'=>$trackId]);
    }
    if ($action === 'spotify-clipboard-status') {
        $settings=$pdo->query("SELECT `key`,value FROM settings WHERE `key` IN ('spotify_clipboard_track_id','spotify_clipboard_started_at')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $trackId=(int)($_GET['id']??($settings['spotify_clipboard_track_id']??0));$started=(int)($settings['spotify_clipboard_started_at']??time());
        if($trackId<1||$started<time()-120)jsonResponse(['pending'=>false]);
        $clipboard=trim((string)@shell_exec('powershell.exe -NoProfile -Command "Get-Clipboard -Raw"'));
        if(!preg_match('~https?://open\.spotify\.com/(?:intl-[a-z]+/)?track/([A-Za-z0-9]{22})~i',$clipboard,$match))jsonResponse(['pending'=>true]);
        $spotifyId=$match[1];$canonical='https://open.spotify.com/track/'.$spotifyId;
        $pdo->prepare("UPDATE tracks SET spotify_id=?,spotify_url=?,spotify_features_status='never',spotify_features_error='',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$spotifyId,$canonical,$trackId]);
        $pdo->prepare("DELETE FROM settings WHERE `key` IN ('spotify_clipboard_track_id','spotify_clipboard_started_at')")->execute();
        jsonResponse(['pending'=>false,'saved'=>true,'track'=>$library->find($trackId)]);
    }
    if ($action === 'replacement-watch-start' && $method === 'POST') jsonResponse((new AudioReplacementService($pdo,$library))->start((int)($data['id']??0)));
    if ($action === 'replacement-watch-status') jsonResponse((new AudioReplacementService($pdo,$library))->status());
    if ($action === 'track-delete' && $method === 'POST') jsonResponse((new TrackDeletionService($pdo,$library))->delete((int)($data['id']??0)));
    if ($action === 'track-move' && $method === 'POST') jsonResponse((new TrackDeletionService($pdo,$library))->move((int)($data['id']??0)));
    if ($action === 'spotify-identify-features' && $method === 'POST') {
        $trackId = (int) ($data['id'] ?? 0);
        $result = (new SpotifyAudioFeaturesService($pdo))->identifyAndEnrich($trackId, (bool)($data['force']??false));
        jsonResponse(['ok'=>true]+$result+['track'=>$library->find($trackId)]);
    }
    if ($action === 'spotify-identify' && $method === 'POST') {
        $trackId = (int) ($data['id'] ?? 0);
        $result = (new SpotifyAudioFeaturesService($pdo))->identifyOnly($trackId, (bool)($data['force']??false));
        jsonResponse(['ok'=>true]+$result+['track'=>$library->find($trackId)]);
    }
    if ($action === 'recalculate-kr' && $method === 'POST') {
        jsonResponse(['ok'=>true,'updated'=>(new SpotifyAudioFeaturesService($pdo))->recalculateStoredMetrics()]);
    }
    if ($action === 'spotify-candidates') jsonResponse(['items'=>(new SpotifyAudioFeaturesService($pdo))->searchCandidates((int)($_GET['id']??0))]);
    if ($action === 'played' && $method === 'POST') {
        $trackId = (int) ($data['id'] ?? 0);
        $pdo->prepare('INSERT INTO history(track_id,session_date) VALUES(?,CURRENT_DATE)')->execute([$trackId]);
        $pdo->prepare('UPDATE tracks SET play_count=play_count+1,last_played=CURRENT_TIMESTAMP WHERE id=?')->execute([$trackId]);
        jsonResponse(['ok'=>true]);
    }
    if ($action === 'queue' && $method === 'POST') {
        jsonResponse($virtualDjControl->addTrackToAutomix((int)($data['id']??0)));
    }
    if ($action === 'open-folder' && $method === 'POST') {
        $track = $library->find((int) ($data['id'] ?? 0));
        if (!$track) jsonResponse(['error'=>'Brano non trovato'], 404);
        if (PHP_OS_FAMILY !== 'Windows') jsonResponse(['error'=>'Apertura cartella disponibile solo sul PC Windows del DJ'], 422);
        $target = str_replace('"', '', (string) $track['file_path']);
        pclose(popen('start "" explorer.exe /select,"' . $target . '"', 'r'));
        jsonResponse(['ok'=>true]);
    }
    if ($action === 'settings' && $method === 'POST') {
        $allowed = ['music_root','vdj_database','playlist_folder','definitive_playlist_folder','duplicate_threshold','recent_exclusion','bpm_range','key_mode','vdj_network_port','kr_formula_weights','request_interval_minutes'];
        $statement = $pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        foreach ($allowed as $key) if (array_key_exists($key,$data)) $statement->execute([$key,(string)$data[$key]]);
        jsonResponse(['ok'=>true]);
    }
    if ($action === 'vdj-genre-stats') {
        $localFilter = appUsesLocalFiles() ? "file_exists=1 AND " : "";
        $items=$pdo->query("SELECT MIN(TRIM(genre)) genre,COUNT(*) total FROM tracks WHERE {$localFilter}UPPER(file_path) LIKE 'E:%' AND TRIM(COALESCE(genre,''))<>'' GROUP BY LOWER(TRIM(genre)) ORDER BY total DESC,genre")->fetchAll();
        jsonResponse(['items'=>$items,'total_genres'=>count($items),'total_tracks'=>array_sum(array_map(fn(array $item)=>(int)$item['total'],$items))]);
    }
    if ($action === 'library-standard-validate') jsonResponse((new LibraryStandardService())->validate());
    if ($action === 'library-standard-test') jsonResponse((new LibraryStandardService())->test($_GET + $data));
    if ($action === 'import-vdj' && $method === 'POST') jsonResponse($library->importVirtualDj((string)($data['path']??setting('vdj_database',''))));
    if ($action === 'sync-all' && $method === 'POST') jsonResponse($library->syncAllVirtualDjDatabases((bool)($data['force']??false)));
    if ($action === 'reconcile-vdj' && $method === 'POST') jsonResponse($library->reconcileVirtualDjDatabases());
    if ($action === 'prune-library' && $method === 'POST') jsonResponse(['ok'=>true] + $library->pruneToDefinitiveLibrary());
    if ($action === 'e-duplicates-scan' && $method === 'POST') jsonResponse((new EDuplicateService($pdo))->scan());
    if ($action === 'e-duplicates-decision' && $method === 'POST') {
        (new EDuplicateService($pdo))->decide((int)($data['id']??0),(string)($data['decision']??''));
        jsonResponse(['ok'=>true]);
    }
    if ($action === 'e-duplicates-mark-nonrecommended' && $method === 'POST') {
        $updated = (new EDuplicateService($pdo))->markNonRecommended((string)($data['folder']??''));
        jsonResponse(['ok'=>true,'updated'=>$updated]);
    }
    if ($action === 'compare-folder-e' && $method === 'POST') jsonResponse((new EComparisonService($pdo))->compare((string)($data['folder']??'')));
    if ($action === 'deletion-candidate-decision' && $method === 'POST') {
        (new EComparisonService($pdo))->decide((int)($data['id']??0),(string)($data['status']??''),(string)($data['note']??''));
        jsonResponse(['ok'=>true]);
    }
    if ($action === 'deletion-candidates-mark-all' && $method === 'POST') {
        $updated = (new EComparisonService($pdo))->markAll((string)($data['folder']??''));
        jsonResponse(['ok'=>true,'updated'=>$updated]);
    }
    if ($action === 'deletion-candidates-approve-all' && $method === 'POST') {
        $updated = (new EComparisonService($pdo))->approveAllMarked();
        jsonResponse(['ok'=>true,'updated'=>$updated]);
    }
    if ($action === 'deletion-candidates-clear' && $method === 'POST') {
        $total=(int)$pdo->query('SELECT COUNT(*) FROM deletion_candidates')->fetchColumn();
        $pdo->exec('DELETE FROM deletion_candidates');
        jsonResponse(['ok'=>true,'deleted'=>$total]);
    }
    if ($action === 'vdj-search-candidate' && $method === 'POST') jsonResponse((new VirtualDjControlService($pdo))->searchApprovedCandidate((int)($data['id']??0)));
    if ($action === 'vdj-align-artist-title' && $method === 'POST') jsonResponse($virtualDjControl->alignArtistTitle((int)($data['id']??0)));
    if ($action === 'vdj-automix-add' && $method === 'POST') jsonResponse((new VirtualDjControlService($pdo))->addTrackToAutomix((int)($data['id']??0)));
    if ($action === 'vdj-prelisten' && $method === 'POST') jsonResponse($virtualDjControl->prelistenTrack((int)($data['id']??0)));
    if ($action === 'vdj-prelisten-stop' && $method === 'POST') jsonResponse($virtualDjControl->stopPrelisten());
    if ($action === 'suggestion-start' && $method === 'POST') {
        $trackId = (int) ($data['id'] ?? 0);
        if (!$library->find($trackId)) jsonResponse(['error'=>'Brano di partenza non trovato'], 404);
        $pdo->prepare('INSERT INTO settings(`key`,value) VALUES(\'suggestion_start_track_id\',?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([(string) $trackId]);
        jsonResponse(['ok'=>true,'id'=>$trackId]);
    }
    if ($action === 'import-m3u' && $method === 'POST') jsonResponse($library->importM3u((string)($data['path']??'')));
    if ($action === 'scan' && $method === 'POST') jsonResponse($library->scan((string)($data['path']??setting('music_root',''))));
    if ($action === 'duplicate-decision' && $method === 'POST') {
        $statement = $pdo->prepare('INSERT INTO duplicate_decisions(fingerprint,decision,note) VALUES(?,?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision),note=VALUES(note),updated_at=CURRENT_TIMESTAMP');
        $statement->execute([(string)($data['fingerprint']??''),(string)($data['decision']??'ignore'),trim((string)($data['note']??''))]);
        jsonResponse(['ok'=>true]);
    }
    jsonResponse(['error'=>'Endpoint non trovato'], 404);
} catch (Throwable $error) {
    jsonResponse(['error'=>$error->getMessage()], 500);
}

function boundScore(mixed $value): int { return min(5,max(1,(int)$value)); }

function hostingApiUrl(string $action): string
{
    $base = rtrim((string) setting('hosting_base_url', 'https://www.kr-solutions.it/vdjdesk'), '/');
    $params = $_GET;
    $params['action'] = $action;
    return $base . '/api.php?' . http_build_query($params);
}

function shouldProxyToHosting(string $action): bool
{
    return in_array($action, [
        'requests',
        'request-create',
        'request-status',
        'request-estimates-refresh',
        'request-automix-debug',
        'request-update',
        'request-delete',
        'quiz-state',
        'quiz-history',
        'quiz-create',
        'quiz-launch',
        'quiz-close',
        'quiz-reveal',
        'quiz-join',
        'quiz-answer',
        'quiz-heartbeat',
        'quiz-leave',
        'quiz-participant-action',
        'quiz-prefill',
        'network-info',
    ], true);
}

function remoteApiFetch(string $action, string $method = 'GET', array $data = []): ?array
{
    try {
        $response = remoteApiRequest($action, $method, $data);
        return is_array($response['json'] ?? null) ? $response['json'] : null;
    } catch (Throwable) {
        return null;
    }
}

function remoteApiPassthrough(string $action, string $method, array $data): never
{
    try {
        $response = remoteApiRequest($action, $method, $data);
        http_response_code((int) $response['status']);
        header('Content-Type: application/json; charset=utf-8');
        echo $response['body'];
        exit;
    } catch (Throwable $error) {
        jsonResponse(['error'=>'Hosting non raggiungibile: '.$error->getMessage()], 502);
    }
}

function remoteApiRequest(string $action, string $method = 'GET', array $data = []): array
{
    $url = hostingApiUrl($action);
    $method = strtoupper($method);
    $headers = ['Accept: application/json'];
    $body = null;
    if ($method !== 'GET') {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($raw === false || $raw === '') throw new RuntimeException($error ?: 'risposta vuota');
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        $status = 200;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) $status = (int) $match[1];
        if ($raw === false || $raw === '') throw new RuntimeException('risposta vuota');
    }
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) throw new RuntimeException('risposta hosting non JSON');
    return ['status'=>$status ?: 200, 'body'=>(string) $raw, 'json'=>$json];
}
