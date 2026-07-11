<?php
declare(strict_types=1);

final class SuggestionService
{
    public function __construct(private PDO $pdo, private LibraryService $library, private VirtualDjHistoryService $history) {}

    public function suggest(int $currentId, string $mode = 'same', string $requiredTag = ''): array
    {
        $current = $this->library->find($currentId);
        if (!$current) throw new RuntimeException('Brano attuale non trovato.');
        $recentPaths = [];
        $recentFingerprints = [];
        foreach ($this->history->entriesSinceMinutes(90) as $recent) {
            $recentPaths[strtolower(canonicalPath((string) $recent['file_path']))] = true;
            $recentFingerprints[normalizeText((string) $recent['artist']) . '|' . normalizeTitle((string) $recent['title'])] = true;
        }
        $candidates = $this->pdo->query("SELECT * FROM tracks WHERE id <> " . (int) $currentId . " AND file_exists=1 AND UPPER(file_path) LIKE 'E:%' ORDER BY rating DESC,play_count DESC LIMIT 10000")->fetchAll();
        $seenFingerprints = [];
        $results = [];
        foreach ($candidates as $candidate) {
            $fingerprint = $candidate['normalized_artist'] . '|' . $candidate['normalized_title'];
            if (isset($recentPaths[strtolower(canonicalPath((string) $candidate['file_path']))]) || isset($recentFingerprints[$fingerprint])) continue;
            if ($candidate['normalized_artist'] === $current['normalized_artist']) continue;
            if (isset($seenFingerprints[$fingerprint])) continue;
            $seenFingerprints[$fingerprint] = true;
            [$score, $reasons, $badges, $safeChange] = $this->score($current, $candidate, $mode);
            if (!$this->matchesMode($current,$candidate,$mode,$safeChange)) continue;
            if ($requiredTag !== '' && !$this->hasBadge($badges, $requiredTag)) continue;
            $candidate['tags'] = trackTags($candidate);
            $candidate['auto_tags'] = autoTrackTags($candidate);
            $candidate['score'] = round($score);
            $candidate['reasons'] = $reasons;
            $candidate['badges'] = array_values(array_unique($badges));
            $candidate['kr_genre_change_safe'] = $safeChange;
            $results[] = $candidate;
        }
        usort($results, fn(array $a, array $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, 20);
    }

    private function hasBadge(array $badges, string $requiredTag): bool
    {
        $requiredTag = normalizeText($requiredTag);
        foreach ($badges as $badge) {
            if (normalizeText((string) $badge) === $requiredTag) return true;
        }
        return false;
    }

    private function score(array $current, array $candidate, string $mode): array
    {
        $score = 30.0;
        $reasons = [];
        $badges = [];
        $bpmDiff = abs((float) $current['bpm'] - (float) $candidate['bpm']);
        $halfDiff = min(abs((float) $current['bpm'] * 2 - (float) $candidate['bpm']), abs((float) $current['bpm'] - (float) $candidate['bpm'] * 2));
        $mixDiff = min($bpmDiff, $halfDiff);
        if ($mixDiff <= 3) { $score += 25; $reasons[] = 'BPM molto vicino'; }
        elseif ($mixDiff <= (float) setting('bpm_range', '8')) { $score += 15; $reasons[] = 'BPM facilmente mixabile'; }
        else { $score -= min(20, $mixDiff); }

        if ($this->keyCompatible((string) $current['camelot'], (string) $candidate['camelot'])) {
            $score += 18; $reasons[] = 'Key Camelot compatibile';
        }
        if (normalizeText((string) $current['genre']) === normalizeText((string) $candidate['genre'])) {
            $score += 15; $reasons[] = 'Stesso genere';
        } elseif ($this->genreCompatible((string) $current['genre'], (string) $candidate['genre'])) {
            $score += 9; $reasons[] = 'Cambio genere sicuro'; $badges[] = 'Cambio genere';
        }
        $energyDelta = (int)($candidate['kr_energy']??((int)$candidate['energy']*20))-(int)($current['kr_energy']??((int)$current['energy']*20));
        if ($mode === 'up' && $energyDelta > 0) { $score += 20; $reasons[] = 'Aumenta energia'; $badges[] = 'Salita energia'; }
        if ($mode === 'down' && $energyDelta < 0) { $score += 20; $reasons[] = 'Riduce energia'; }
        if ($mode === 'sing' && (int)($candidate['kr_singability']??0) >= 70) { $score += 22; $reasons[] = 'Alta cantabilità KR'; $badges[] = 'Canto'; }
        if ($mode === 'recover' && (int)($candidate['kr_recovery']??0) >= 70) { $score += 25; $reasons[] = 'Recupero pista KR'; $badges[] = 'Recupero pista'; }
        $safeChange=$this->safeGenreChange($current,$candidate,$mixDiff);
        if ($mode === 'genre' && normalizeText((string)$current['genre'])!==normalizeText((string)$candidate['genre']) && $safeChange>=65) { $score+=20; $reasons[]='Cambio genere KR '.$safeChange; $badges[]='Cambio genere sicuro'; }
        $popularity = isset($candidate['popularity']) ? max(0, min(100, (int) $candidate['popularity'])) : 0;
        if ($popularity > 0) {
            $score += $popularity * 0.15;
            if ($popularity >= 75) { $reasons[] = 'Alta popolarità Spotify'; $badges[] = 'Hit'; }
            elseif ($popularity >= 55) { $reasons[] = 'Popolarità Spotify solida'; }
        }
        $score += ((int)$candidate['rating']*2)+((int)($candidate['kr_floor_power']??50)*.08)+((int)($candidate['kr_familiarity']??50)*.08)-((int)($candidate['kr_risk']??50)*.06);
        foreach (allTrackTags($candidate) as $tag) {
            $labels = ['URBAN'=>'Urban','REGGAETON'=>'Reggaeton','DEMBOW'=>'Dembow','COMMERCIALE'=>'Commerciale','CHIUSURA'=>'Chiusura','RECUPERO PISTA'=>'Recupero pista','CANTO'=>'Canto','BALLI DI GRUPPO'=>'Balli di gruppo'];
            if (isset($labels[$tag])) $badges[] = $labels[$tag];
            if($tag==='PISTA')$score+=4;
            if($tag==='SUCCESSO')$score+=6;
            if($mode==='up'&&in_array($tag,['ALTA ENERGIA','PICCO'],true))$score+=7;
            if($mode==='sing'&&$tag==='CANTO')$score+=10;
            if($mode==='recover'&&$tag==='RECUPERO PISTA')$score+=12;
        }
        if ($score >= 75) $badges[] = 'Sicura';
        return [$score, array_slice($reasons, 0, 3), array_slice($badges, 0, 4), $safeChange];
    }

    private function safeGenreChange(array $current,array $candidate,float $mixDiff): int
    {
        $score=$mixDiff<=3?35:($mixDiff<=(float)setting('bpm_range','8')?25:5);
        if($this->keyCompatible((string)$current['camelot'],(string)$candidate['camelot']))$score+=25;
        $same=normalizeText((string)$current['genre'])===normalizeText((string)$candidate['genre']);
        $score+=$same?20:($this->genreCompatible((string)$current['genre'],(string)$candidate['genre'])?25:8);
        $score+=((int)($candidate['kr_familiarity']??50))*.12;
        $score+=(100-(int)($candidate['kr_risk']??50))*.06;
        return max(0,min(100,(int)round($score)));
    }

    private function matchesMode(array $current,array $candidate,string $mode,int $safeChange): bool
    {
        $currentGenre=normalizeText((string)$current['genre']);$candidateGenre=normalizeText((string)$candidate['genre']);
        $currentEnergy=(int)($current['kr_energy']??((int)$current['energy']*20));
        $candidateEnergy=(int)($candidate['kr_energy']??((int)$candidate['energy']*20));
        return match($mode){
            'same'=>$currentGenre!==''&&$candidateGenre===$currentGenre,
            'up'=>$candidateEnergy>=$currentEnergy+8,
            'down'=>$candidateEnergy<=$currentEnergy-8,
            'genre'=>$candidateGenre!==''&&$candidateGenre!==$currentGenre&&$this->genreCompatible((string)$current['genre'],(string)$candidate['genre'])&&$safeChange>=65,
            'sing'=>(int)($candidate['kr_singability']??((int)$candidate['singability']*20))>=70,
            'recover'=>(int)($candidate['kr_recovery']??0)>=70
                && (int)($candidate['kr_familiarity']??((int)$candidate['familiarity']*20))>=75
                && (int)($candidate['kr_floor_power']??((int)$candidate['danceability']*20))>=70
                && (int)($candidate['kr_risk']??((int)$candidate['risk']*20))<=30
                && ((int)$candidate['year']===0||(int)$candidate['year']<=(int)date('Y')-2),
            default=>true,
        };
    }

    private function keyCompatible(string $a, string $b): bool
    {
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        if (!preg_match('/^(\d{1,2})([AB])$/', $a, $one) || !preg_match('/^(\d{1,2})([AB])$/', $b, $two)) return false;
        $distance = abs((int) $one[1] - (int) $two[1]);
        $wheelDistance = min($distance, 12 - $distance);
        return ($one[2] === $two[2] && $wheelDistance <= 1) || ($one[1] === $two[1] && $one[2] !== $two[2]);
    }

    private function genreCompatible(string $a, string $b): bool
    {
        $groups = [
            ['reggaeton','dembow','latin pop','cubatón','cubaton'],
            ['dance','dance 90','house','edm','commerciale','pop dance'],
            ['hip hop','r&b','urban','rap it'],
            ['italiano','commerciale','pop dance'],
            ['salsa','timba','bachata'],
        ];
        $a = normalizeText($a); $b = normalizeText($b);
        foreach ($groups as $group) if (in_array($a, $group, true) && in_array($b, $group, true)) return true;
        return false;
    }
}
