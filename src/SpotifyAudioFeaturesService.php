<?php
declare(strict_types=1);

final class SpotifyAudioFeaturesService
{
    private ?array $cachedTokens = null;

    public function __construct(private PDO $pdo) {}

    public function enrich(int $trackId, ?array $spotifyTrack = null): array
    {
        $statement = $this->pdo->prepare('SELECT spotify_id,popularity FROM tracks WHERE id=?');
        $statement->execute([$trackId]);
        $track = $statement->fetch();
        $spotifyId = trim((string) ($track['spotify_id'] ?? ''));
        if ($spotifyId === '') throw new RuntimeException('Spotify ID non disponibile per questo brano.');
        $spotifyTrack ??= $this->apiRequest('/tracks/' . rawurlencode($spotifyId));
        $genre = $this->genreForTrack($spotifyTrack);
        $releaseDate = (string)($spotifyTrack['album']['release_date']??'');
        $year = preg_match('/^(\d{4})/', $releaseDate, $yearMatch) ? (int)$yearMatch[1] : null;
        $album = (string)($spotifyTrack['album']['name']??'');
        $this->pdo->prepare("UPDATE tracks SET spotify_genre=?,genre=IF(?<>'',?,genre),popularity=COALESCE(?,popularity),album=IF(?<>'',?,album),release_date=IF(?<>'',?,release_date),year=COALESCE(?,year),metadata_updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$genre,$genre,$genre,$spotifyTrack['popularity']??null,$album,$album,$releaseDate,$releaseDate,$year,$trackId]);
        try {
            $features = $this->request($spotifyId);
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'non disponibili') ? 'unavailable' : 'error';
            $this->pdo->prepare('UPDATE tracks SET spotify_features_status=?,spotify_features_checked_at=CURRENT_TIMESTAMP,spotify_features_error=? WHERE id=?')->execute([$status,mb_substr($exception->getMessage(),0,500),$trackId]);
            throw $exception;
        }
        $required = ['energy','danceability','valence','acousticness','instrumentalness','speechiness','liveness','loudness','tempo','key','mode'];
        $complete = count(array_filter($required, fn(string $key): bool => array_key_exists($key,$features) && $features[$key] !== null)) === count($required) && $genre !== '';
        $status = $complete ? 'complete' : 'partial';
        $canScore = count(array_filter(['energy','danceability','valence','instrumentalness','speechiness'], fn(string $key): bool => isset($features[$key]))) === 5;
        $popularity = isset($spotifyTrack['popularity']) ? (int)$spotifyTrack['popularity'] : (isset($track['popularity'])?(int)$track['popularity']:null);
        $scores = $canScore ? $this->deriveDjScores($features, $popularity, $genre) : ['energy'=>3,'singability'=>3,'danceability'=>3,'familiarity'=>3,'risk'=>3,'kr_energy'=>null,'kr_singability'=>null,'kr_floor_power'=>null,'kr_familiarity'=>null,'kr_risk'=>null,'kr_peak'=>null,'kr_recovery'=>null];
        [$musicalKey,$camelot]=$this->keyLabels(isset($features['key'])?(int)$features['key']:null,isset($features['mode'])?(int)$features['mode']:null);
        $update = $this->pdo->prepare("UPDATE tracks SET spotify_energy=?,spotify_danceability=?,spotify_valence=?,spotify_acousticness=?,spotify_instrumentalness=?,spotify_speechiness=?,spotify_liveness=?,spotify_loudness=?,spotify_tempo=?,spotify_key=?,spotify_mode=?,musical_key=IF(?<>'',?,musical_key),camelot=IF(?<>'',?,camelot),kr_energy=?,kr_singability=?,kr_floor_power=?,kr_familiarity=?,kr_risk=?,kr_peak=?,kr_recovery=?,energy=IF(dj_scores_manual=0 AND ?=1,?,energy),singability=IF(dj_scores_manual=0 AND ?=1,?,singability),danceability=IF(dj_scores_manual=0 AND ?=1,?,danceability),familiarity=IF(dj_scores_manual=0 AND ?=1,?,familiarity),risk=IF(dj_scores_manual=0 AND ?=1,?,risk),spotify_features_status=?,spotify_features_checked_at=CURRENT_TIMESTAMP,spotify_features_error='',spotify_features_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $update->execute([$features['energy']??null,$features['danceability']??null,$features['valence']??null,$features['acousticness']??null,$features['instrumentalness']??null,$features['speechiness']??null,$features['liveness']??null,$features['loudness']??null,$features['tempo']??null,$features['key']??null,$features['mode']??null,$musicalKey,$musicalKey,$camelot,$camelot,$scores['kr_energy'],$scores['kr_singability'],$scores['kr_floor_power'],$scores['kr_familiarity'],$scores['kr_risk'],$scores['kr_peak'],$scores['kr_recovery'],$canScore?1:0,$scores['energy'],$canScore?1:0,$scores['singability'],$canScore?1:0,$scores['danceability'],$canScore?1:0,$scores['familiarity'],$canScore?1:0,$scores['risk'],$status,$trackId]);
        if($canScore)$this->saveAutoTags($trackId,$this->deriveAutoTags($scores,$genre,$popularity));
        return $features;
    }

    private function deriveDjScores(array $features, ?int $popularity, string $genre): array
    {
        $weights=$this->formulaWeights();
        $spotifyEnergy=(float)$features['energy']*100; $dance=(float)$features['danceability']*100;
        $valence=(float)$features['valence']*100; $vocal=(1-(float)$features['instrumentalness'])*100;
        $speech=(float)$features['speechiness']*100; $pop=(float)($popularity??40);
        $loudness=$this->clamp((((float)$features['loudness']+14)/10)*100);
        $tempo=$this->tempoIntensity((float)$features['tempo'],$genre);
        $krEnergy=$this->clamp($weights['energy']['spotify_energy']*$spotifyEnergy+$weights['energy']['loudness']*$loudness+$weights['energy']['dance']*$dance+$weights['energy']['tempo']*$tempo);
        $krSingability=$this->clamp($weights['singability']['popularity']*$pop+$weights['singability']['vocal']*$vocal+$weights['singability']['valence']*$valence-($speech>33?$weights['singability']['speech_penalty']:0));
        $krFloorPower=$this->clamp($weights['floor']['dance']*$dance+$weights['floor']['spotify_energy']*$spotifyEnergy+$weights['floor']['loudness']*$loudness+$weights['floor']['tempo']*$tempo+$weights['floor']['valence']*$valence);
        $krFamiliarity=$this->clamp($weights['familiarity']['popularity']*$pop);
        $krRisk=$this->clamp(100-($weights['risk']['familiarity']*$krFamiliarity+$weights['risk']['floor']*$krFloorPower+$weights['risk']['singability']*$krSingability)+($speech>35?$weights['risk']['speech_penalty']:0)+(((float)$features['instrumentalness']*100)>50?$weights['risk']['instrumental_penalty']:0));
        $krPeak=$this->clamp($weights['peak']['spotify_energy']*$spotifyEnergy+$weights['peak']['loudness']*$loudness+$weights['peak']['dance']*$dance+$weights['peak']['popularity']*$pop);
        $krRecovery=$this->clamp($weights['recovery']['familiarity']*$krFamiliarity+$weights['recovery']['floor']*$krFloorPower+$weights['recovery']['singability']*$krSingability+$weights['recovery']['valence']*$valence+$weights['recovery']['inverse_risk']*(100-$krRisk));
        return [
            'energy'=>$this->score($krEnergy,[20,40,60,80]), 'singability'=>$this->score($krSingability,[20,40,60,80]),
            'danceability'=>$this->score($krFloorPower,[20,40,60,80]), 'familiarity'=>$this->score($krFamiliarity,[20,40,60,80]),
            'risk'=>$this->score($krRisk,[20,40,60,80]), 'kr_energy'=>(int)round($krEnergy),
            'kr_singability'=>(int)round($krSingability), 'kr_floor_power'=>(int)round($krFloorPower),
            'kr_familiarity'=>(int)round($krFamiliarity), 'kr_risk'=>(int)round($krRisk),
            'kr_peak'=>(int)round($krPeak), 'kr_recovery'=>(int)round($krRecovery),
        ];
    }

    private function formulaWeights(): array
    {
        $defaults=[
            'energy'=>['spotify_energy'=>.55,'loudness'=>.20,'dance'=>.15,'tempo'=>.10],
            'singability'=>['popularity'=>.45,'vocal'=>.35,'valence'=>.20,'speech_penalty'=>20],
            'floor'=>['dance'=>.40,'spotify_energy'=>.25,'loudness'=>.15,'tempo'=>.10,'valence'=>.10],
            'familiarity'=>['popularity'=>1],
            'risk'=>['familiarity'=>.45,'floor'=>.35,'singability'=>.20,'speech_penalty'=>10,'instrumental_penalty'=>10],
            'peak'=>['spotify_energy'=>.40,'loudness'=>.25,'dance'=>.20,'popularity'=>.15],
            'recovery'=>['familiarity'=>.30,'floor'=>.25,'singability'=>.20,'valence'=>.15,'inverse_risk'=>.10]
        ];
        $saved=json_decode((string)setting('kr_formula_weights','{}'),true);
        if(!is_array($saved))return $defaults;
        foreach($defaults as $group=>$values)foreach($values as $key=>$value)if(isset($saved[$group][$key])&&is_numeric($saved[$group][$key]))$defaults[$group][$key]=(float)$saved[$group][$key];
        return $defaults;
    }

    private function tempoIntensity(float $tempo, string $genre): float
    {
        $genre=normalizeText($genre);
        if (str_contains($genre,'trap')||str_contains($genre,'hip hop')||str_contains($genre,'rap')) {
            if ($tempo>115) $tempo/=2;
            return $this->clamp(($tempo-55)/45*100);
        }
        if ($tempo<75) $tempo*=2;
        return $this->clamp(($tempo-80)/60*100);
    }

    private function clamp(float $value): float { return max(0,min(100,$value)); }

    private function deriveAutoTags(array $scores,string $genre,?int $popularity): array
    {
        $tags=[];
        if($scores['kr_floor_power']>=70)$tags[]='PISTA';
        if($scores['kr_energy']>=80)$tags[]='ALTA ENERGIA';
        if($scores['kr_singability']>=75)$tags[]='CANTO';
        if($scores['kr_peak']>=82)$tags[]='PICCO';
        if($scores['kr_recovery']>=80&&$scores['kr_risk']<=25)$tags[]='RECUPERO PISTA';
        if(($popularity??0)>=75)$tags[]='SUCCESSO';
        if($scores['kr_familiarity']>=70)$tags[]='POPOLARE';
        $normalized=normalizeText($genre);
        $genreTags=['reggaeton'=>'REGGAETON','dembow'=>'DEMBOW','bachata'=>'BACHATA','salsa'=>'SALSA','timba'=>'TIMBA','cubaton'=>'CUBATON','trap'=>'URBAN','hip hop'=>'URBAN','rap'=>'URBAN','house'=>'EDM','edm'=>'EDM'];
        foreach($genreTags as $needle=>$tag)if(str_contains($normalized,$needle))$tags[]=$tag;
        return array_values(array_unique($tags));
    }

    private function saveAutoTags(int $trackId,array $tags): void
    {
        $statement=$this->pdo->prepare('UPDATE tracks SET auto_tags=? WHERE id=?');$statement->execute([json_encode($tags,JSON_UNESCAPED_UNICODE),$trackId]);
    }

    private function keyLabels(?int $key,?int $mode): array
    {
        if($key===null||$mode===null||$key<0||$key>11)return ['',''];
        $names=['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        $major=['8B','3B','10B','5B','12B','7B','2B','9B','4B','11B','6B','1B'];
        $minor=['5A','12A','7A','2A','9A','4A','11A','6A','1A','8A','3A','10A'];
        return [$names[$key].($mode===0?'m':''),$mode===1?$major[$key]:$minor[$key]];
    }

    private function score(float $value, array $thresholds): int
    {
        foreach ($thresholds as $index=>$threshold) if ($value < $threshold) return $index+1;
        return 5;
    }

    public function identifyAndEnrich(int $trackId, bool $forceIdentify = false): array
    {
        $statement = $this->pdo->prepare('SELECT id,artist,title,duration,spotify_id FROM tracks WHERE id=?');
        $statement->execute([$trackId]);
        $track = $statement->fetch();
        if (!$track) throw new RuntimeException('Brano non trovato.');
        if ($forceIdentify || trim((string) $track['spotify_id']) === '') {
            $query = trim((string) $track['artist'] . ' ' . (string) $track['title']);
            $result = $this->apiRequest('/search?' . http_build_query(['q'=>$query,'type'=>'track','limit'=>10]));
            $match = $this->bestMatch($track, (array) ($result['tracks']['items'] ?? []));
            if (!$match || $match['confidence'] < 74 || $match['title_score'] < 72) {
                $message='Nessuna corrispondenza Spotify sicura'.($match?' ('.$match['confidence'].'%, titolo '.$match['title_score'].'%)':'').'.';
                $this->pdo->prepare("UPDATE tracks SET spotify_features_status='error',spotify_features_error=?,spotify_features_checked_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$message,$trackId]);
                throw new RuntimeException($message);
            }
            $spotifyTrack = $match['track'];
            $artist=trim(implode(', ',array_column((array)($spotifyTrack['artists']??[]),'name')));
            $title=trim((string)($spotifyTrack['name']??''));
            $releaseDate = (string) ($spotifyTrack['album']['release_date'] ?? '');
            $year = preg_match('/^(\d{4})/', $releaseDate, $yearMatch) ? (int) $yearMatch[1] : null;
            $update = $this->pdo->prepare("UPDATE tracks SET artist=?,title=?,normalized_artist=?,normalized_title=?,spotify_id=?,spotify_url=?,popularity=?,album=?,release_date=?,year=COALESCE(?,year),metadata_source='spotify',metadata_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $update->execute([$artist,$title,normalizeText($artist),normalizeTitle($title),(string)$spotifyTrack['id'],(string)($spotifyTrack['external_urls']['spotify']??''),$spotifyTrack['popularity']??null,(string)($spotifyTrack['album']['name']??''),$releaseDate,$year,$trackId]);
        }
        $features = $this->enrich($trackId, $spotifyTrack ?? null);
        return ['features'=>$features,'confidence'=>$match['confidence']??100];
    }

    public function identifyOnly(int $trackId, bool $forceIdentify = false): array
    {
        $statement = $this->pdo->prepare('SELECT id,artist,title,duration,spotify_id FROM tracks WHERE id=?');
        $statement->execute([$trackId]);
        $track = $statement->fetch();
        if (!$track) throw new RuntimeException('Brano non trovato.');
        if (!$forceIdentify && trim((string) $track['spotify_id']) !== '') return ['confidence'=>100,'already_present'=>true];
        $query = trim((string) $track['artist'] . ' ' . (string) $track['title']);
        $result = $this->apiRequest('/search?' . http_build_query(['q'=>$query,'type'=>'track','limit'=>10]));
        $match = $this->bestMatch($track, (array) ($result['tracks']['items'] ?? []));
        if (!$match || $match['confidence'] < 74 || $match['title_score'] < 72) {
            $message='Nessuna corrispondenza Spotify sicura'.($match?' ('.$match['confidence'].'%, titolo '.$match['title_score'].'%)':'').'.';
            $this->pdo->prepare("UPDATE tracks SET spotify_features_status='error',spotify_features_error=?,spotify_features_checked_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$message,$trackId]);
            throw new RuntimeException($message);
        }
        $spotifyTrack = $match['track'];
        $artist=trim(implode(', ',array_column((array)($spotifyTrack['artists']??[]),'name')));
        $title=trim((string)($spotifyTrack['name']??''));
        $releaseDate = (string) ($spotifyTrack['album']['release_date'] ?? '');
        $year = preg_match('/^(\d{4})/', $releaseDate, $yearMatch) ? (int) $yearMatch[1] : null;
        $update = $this->pdo->prepare("UPDATE tracks SET artist=?,title=?,normalized_artist=?,normalized_title=?,spotify_id=?,spotify_url=?,popularity=?,album=?,release_date=?,year=COALESCE(?,year),spotify_features_status='never',spotify_features_error='',metadata_source='spotify',metadata_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $update->execute([$artist,$title,normalizeText($artist),normalizeTitle($title),(string)$spotifyTrack['id'],(string)($spotifyTrack['external_urls']['spotify']??''),$spotifyTrack['popularity']??null,(string)($spotifyTrack['album']['name']??''),$releaseDate,$year,$trackId]);
        return ['confidence'=>$match['confidence'],'already_present'=>false];
    }

    public function refreshReplacementMetadata(int $trackId): array
    {
        $statement=$this->pdo->prepare('SELECT spotify_id FROM tracks WHERE id=?');
        $statement->execute([$trackId]);
        $spotifyId=trim((string)$statement->fetchColumn());
        if($spotifyId==='')throw new RuntimeException('Spotify ID non disponibile per il brano sostituito.');
        $spotifyTrack=$this->apiRequest('/tracks/'.rawurlencode($spotifyId));
        $artist=trim(implode(', ',array_column((array)($spotifyTrack['artists']??[]),'name')));
        $title=trim((string)($spotifyTrack['name']??''));
        if($artist===''||$title==='')throw new RuntimeException('Artista o titolo Spotify non disponibili.');
        $this->pdo->prepare('UPDATE tracks SET artist=?,title=?,normalized_artist=?,normalized_title=?,metadata_source=\'spotify\',metadata_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$artist,$title,normalizeText($artist),normalizeTitle($title),$trackId]);
        $features=$this->enrich($trackId,$spotifyTrack);
        return ['artist'=>$artist,'title'=>$title,'features'=>$features];
    }

    public function searchCandidates(int $trackId): array
    {
        $statement=$this->pdo->prepare('SELECT artist,title,duration FROM tracks WHERE id=?');$statement->execute([$trackId]);$track=$statement->fetch();
        if(!$track)throw new RuntimeException('Brano non trovato.');
        $result=$this->apiRequest('/search?'.http_build_query(['q'=>trim((string)$track['artist'].' '.(string)$track['title']),'type'=>'track','limit'=>10]));
        $items=[];
        foreach((array)($result['tracks']['items']??[]) as $item){$match=$this->bestMatch($track,[$item]);$items[]=['spotify_id'=>$item['id']??'','artist'=>implode(', ',array_column((array)($item['artists']??[]),'name')),'title'=>$item['name']??'','duration'=>round(((int)($item['duration_ms']??0))/1000),'popularity'=>$item['popularity']??null,'confidence'=>$match['confidence']??0];}
        usort($items,fn(array $a,array $b)=>$b['confidence']<=>$a['confidence']);return $items;
    }

    public function recalculateStoredMetrics(): int
    {
        $rows=$this->pdo->query("SELECT id,popularity,genre,spotify_energy energy,spotify_danceability danceability,spotify_valence valence,spotify_instrumentalness instrumentalness,spotify_speechiness speechiness,spotify_loudness loudness,spotify_tempo tempo FROM tracks WHERE spotify_energy IS NOT NULL AND spotify_danceability IS NOT NULL AND spotify_valence IS NOT NULL AND spotify_instrumentalness IS NOT NULL AND spotify_speechiness IS NOT NULL AND spotify_loudness IS NOT NULL AND spotify_tempo IS NOT NULL")->fetchAll();
        $update=$this->pdo->prepare("UPDATE tracks SET kr_energy=?,kr_singability=?,kr_floor_power=?,kr_familiarity=?,kr_risk=?,kr_peak=?,kr_recovery=?,auto_tags=?,energy=IF(dj_scores_manual=0,?,energy),singability=IF(dj_scores_manual=0,?,singability),danceability=IF(dj_scores_manual=0,?,danceability),familiarity=IF(dj_scores_manual=0,?,familiarity),risk=IF(dj_scores_manual=0,?,risk) WHERE id=?");
        foreach($rows as $row){
            $scores=$this->deriveDjScores($row,isset($row['popularity'])?(int)$row['popularity']:null,(string)$row['genre']);
            $autoTags=$this->deriveAutoTags($scores,(string)$row['genre'],isset($row['popularity'])?(int)$row['popularity']:null);
            $update->execute([$scores['kr_energy'],$scores['kr_singability'],$scores['kr_floor_power'],$scores['kr_familiarity'],$scores['kr_risk'],$scores['kr_peak'],$scores['kr_recovery'],json_encode($autoTags,JSON_UNESCAPED_UNICODE),$scores['energy'],$scores['singability'],$scores['danceability'],$scores['familiarity'],$scores['risk'],$row['id']]);
        }
        return count($rows);
    }

    private function genreForTrack(array $spotifyTrack): string
    {
        $artistId = (string)($spotifyTrack['artists'][0]['id']??'');
        if ($artistId === '') return '';
        try {
            $artists = $this->apiRequest('/artists?ids=' . rawurlencode($artistId));
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(),'non disponibili')) return '';
            throw $exception;
        }
        $genres = array_values(array_filter((array)($artists['artists'][0]['genres']??[]),'is_string'));
        return trim((string)($genres[0]??''));
    }

    private function request(string $spotifyId): array
    {
        return $this->apiRequest('/audio-features/' . rawurlencode($spotifyId));
    }

    private function apiRequest(string $path): array
    {
        foreach (array_merge($this->tokens(),$this->refreshedTokens()) as $token) {
            [$status,$body]=$this->requestWithToken($path,$token);
            if ($status === 429) throw new RuntimeException('Limite Spotify raggiunto. Attendo 30 secondi prima di riprovare.');
            if ($status === 404) throw new RuntimeException('Metriche Spotify non disponibili per questo brano.');
            if ($status === 200 && is_string($body)) {
                $data = json_decode($body, true);
                if (is_array($data)) return $data;
            }
        }
        throw new RuntimeException('Token Spotify scaduto. Apri Sortlee e accedi nuovamente a Spotify.');
    }

    private function requestWithToken(string $path,string $token): array
    {
        $curl=curl_init('https://api.spotify.com/v1'.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        return [$status,$body];
    }

    private function refreshedTokens(): array
    {
        $files=$this->tokenFiles();
        $refresh=[];
        $savedRefresh=setting('spotify_refresh_token','');if($savedRefresh!=='')$refresh[$savedRefresh]=true;
        foreach($files as $file){$content=@file_get_contents($file);if(!is_string($content))continue;preg_match_all('/"refresh_token":"([^"]+)"/',$content,$matches);foreach($matches[1] as $token)$refresh[$token]=true;}
        $access=[];
        foreach(array_keys($refresh) as $refreshToken){
            $curl=curl_init('https://accounts.spotify.com/api/token');
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>'1f6c5ac1591b4163b655d1a4b9965c38','grant_type'=>'refresh_token','refresh_token'=>$refreshToken]),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
            $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($status===200&&is_string($body)){$data=json_decode($body,true);if(!empty($data['access_token'])){$accessToken=(string)$data['access_token'];$access[]=$accessToken;$this->saveSetting('spotify_access_token',$accessToken);}if(!empty($data['refresh_token']))$this->saveSetting('spotify_refresh_token',(string)$data['refresh_token']);}
        }
        return $access;
    }

    private function saveSetting(string $key,string $value): void
    {
        $statement=$this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$statement->execute([$key,$value]);
    }

    private function bestMatch(array $localTrack, array $items): ?array
    {
        $best = null;
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) continue;
            $artists = implode(' ', array_column((array)($item['artists']??[]), 'name'));
            similar_text(normalizeTitle((string)$localTrack['title']), normalizeTitle((string)($item['name']??'')), $titleScore);
            similar_text(normalizeText((string)$localTrack['artist']), normalizeText($artists), $artistScore);
            $durationScore = 0;
            if (!empty($localTrack['duration']) && !empty($item['duration_ms'])) {
                $difference = abs((int)$localTrack['duration'] - ((int)$item['duration_ms']/1000));
                $durationScore = $difference <= 4 ? 10 : ($difference <= 10 ? 5 : 0);
            }
            $confidence = ($titleScore * .55) + ($artistScore * .35) + $durationScore;
            if ($best === null || $confidence > $best['confidence']) $best = ['track'=>$item,'confidence'=>round($confidence,1),'title_score'=>round($titleScore,1),'artist_score'=>round($artistScore,1)];
        }
        return $best;
    }

    private function tokens(): array
    {
        if ($this->cachedTokens !== null) return $this->cachedTokens;
        $files = $this->tokenFiles();
        usort($files, fn(string $left,string $right): int => filemtime($right) <=> filemtime($left));
        $tokens = [];
        $savedAccess=setting('spotify_access_token','');if($savedAccess!=='')$tokens[$savedAccess]=true;
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content)) continue;
            $position = strpos($content, 'spotify-sdk:AuthorizationCodeWithPKCEStrategy:token');
            if ($position === false) continue;
            preg_match_all('/[A-Za-z0-9_-]{100,}/', substr($content,$position,4000), $matches);
            foreach ($matches[0] as $token) $tokens[$token] = true;
        }
        if (!$tokens) throw new RuntimeException('Token Spotify di Sortlee non trovato in Edge.');
        return $this->cachedTokens = array_keys($tokens);
    }

    private function tokenFiles(): array
    {
        $localAppData=getenv('LOCALAPPDATA')?:'C:\\Users\\fabbr\\AppData\\Local';
        return array_values(array_unique(array_merge(
            glob($localAppData.'\\Microsoft\\Edge\\User Data\\*\\Local Storage\\leveldb\\*.{ldb,log}',GLOB_BRACE)?:[],
            glob($localAppData.'\\VDJDeskSpotify\\*\\Local Storage\\leveldb\\*.{ldb,log}',GLOB_BRACE)?:[]
        )));
    }
}
