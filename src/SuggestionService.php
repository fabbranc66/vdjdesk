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

        $candidates = $this->pdo->query("
            SELECT *
            FROM tracks
            WHERE id <> " . (int) $currentId . "
              AND file_exists=1
              AND UPPER(file_path) LIKE 'E:%'
            ORDER BY COALESCE(popularity,0) DESC, rating DESC, id DESC
            LIMIT 12000
        ")->fetchAll();

        $seenFingerprints = [];
        $results = [];
        foreach ($candidates as $candidate) {
            $fingerprint = $candidate['normalized_artist'] . '|' . $candidate['normalized_title'];
            if (isset($recentPaths[strtolower(canonicalPath((string) $candidate['file_path']))]) || isset($recentFingerprints[$fingerprint])) continue;
            if ($candidate['normalized_artist'] === $current['normalized_artist']) continue;
            if (isset($seenFingerprints[$fingerprint])) continue;
            $seenFingerprints[$fingerprint] = true;

            $context = $this->context($current, $candidate);
            if (!$this->matchesMode($current, $candidate, $mode, $context)) continue;

            [$score, $reasons, $badges] = $this->score($current, $candidate, $mode, $context);
            if ($requiredTag !== '' && !$this->hasBadge($badges, $requiredTag)) continue;

            $candidate['tags'] = trackTags($candidate);
            $candidate['auto_tags'] = autoTrackTags($candidate);
            $candidate['score'] = max(1, min(100, (int) round($score)));
            $candidate['reasons'] = array_values(array_slice(array_unique($reasons), 0, 4));
            $candidate['badges'] = array_values(array_slice(array_unique($badges), 0, 5));
            $candidate['kr_genre_change_safe'] = $context['safe_change'];
            $results[] = $candidate;
        }

        usort($results, fn(array $a, array $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, 20);
    }

    private function context(array $current, array $candidate): array
    {
        $currentBpm = (float) ($current['bpm'] ?? 0);
        $candidateBpm = (float) ($candidate['bpm'] ?? 0);
        $bpmDiff = ($currentBpm > 0 && $candidateBpm > 0) ? abs($currentBpm - $candidateBpm) : 99.0;
        $halfDiff = ($currentBpm > 0 && $candidateBpm > 0) ? min(abs($currentBpm * 2 - $candidateBpm), abs($currentBpm - $candidateBpm * 2)) : 99.0;
        $mixDiff = min($bpmDiff, $halfDiff);
        $currentGenre = normalizeText((string) $current['genre']);
        $candidateGenre = normalizeText((string) $candidate['genre']);
        $currentFamily = $this->genreFamily((string) $current['genre']);
        $candidateFamily = $this->genreFamily((string) $candidate['genre']);
        $sameGenre = $currentGenre !== '' && $currentGenre === $candidateGenre;
        $sameFamily = $currentFamily !== '' && $currentFamily === $candidateFamily;
        $energyDelta = $this->metric($candidate, 'kr_energy', 'energy') - $this->metric($current, 'kr_energy', 'energy');
        $keyCompatible = $this->keyCompatible((string) $current['camelot'], (string) $candidate['camelot']);
        $safeChange = $this->safeGenreChange($current, $candidate, $mixDiff, $sameGenre, $sameFamily, $keyCompatible);

        return [
            'mixDiff'=>$mixDiff,
            'sameGenre'=>$sameGenre,
            'sameFamily'=>$sameFamily,
            'energyDelta'=>$energyDelta,
            'keyCompatible'=>$keyCompatible,
            'safe_change'=>$safeChange,
            'currentFamily'=>$currentFamily,
            'candidateFamily'=>$candidateFamily,
        ];
    }

    private function score(array $current, array $candidate, string $mode, array $context): array
    {
        $score = 18.0;
        $reasons = [];
        $badges = [];

        $mixDiff = (float) $context['mixDiff'];
        if ($mixDiff <= 3) { $score += 18; $reasons[] = 'BPM molto vicino'; }
        elseif ($mixDiff <= (float) setting('bpm_range', '8')) { $score += 12; $reasons[] = 'BPM mixabile'; }
        elseif ($mixDiff <= 14) { $score += 4; $reasons[] = 'BPM da transizione'; }
        else { $score -= min(22, $mixDiff); }

        if ($context['keyCompatible']) { $score += 12; $reasons[] = 'Key Camelot compatibile'; }

        if ($context['sameGenre']) { $score += 10; $reasons[] = 'Stesso genere'; }
        elseif ($context['sameFamily']) { $score += 7; $reasons[] = 'Famiglia musicale compatibile'; }

        $score += $this->modeScore($candidate, $mode, $context, $reasons, $badges);
        $score += $this->qualityScore($candidate, $mode, $badges);

        foreach (allTrackTags($candidate) as $tag) {
            $labels = ['URBAN'=>'Urban','REGGAETON'=>'Reggaeton','DEMBOW'=>'Dembow','COMMERCIALE'=>'Commerciale','CHIUSURA'=>'Chiusura','RECUPERO PISTA'=>'Recupero pista','CANTO'=>'Canto','BALLI DI GRUPPO'=>'Balli di gruppo'];
            if (isset($labels[$tag])) $badges[] = $labels[$tag];
            if ($mode === 'sing' && $tag === 'CANTO') $score += 8;
            if ($mode === 'recover' && $tag === 'RECUPERO PISTA') $score += 10;
            if ($mode === 'peak' && in_array($tag, ['PICCO','URLANTE','ALTA ENERGIA'], true)) $score += 12;
        }

        if ($mode === 'genre' && !$context['sameGenre']) $badges[] = 'Cambio genere';
        if ($score >= 72) $badges[] = 'Sicura';
        return [$score, $reasons, $badges];
    }

    private function modeScore(array $candidate, string $mode, array $context, array &$reasons, array &$badges): float
    {
        $energy = $this->metric($candidate, 'kr_energy', 'energy');
        $floor = $this->metric($candidate, 'kr_floor_power', 'danceability');
        $singability = $this->metric($candidate, 'kr_singability', 'singability');
        $familiarity = $this->metric($candidate, 'kr_familiarity', 'familiarity');
        $risk = $this->metric($candidate, 'kr_risk', 'risk');
        $peak = (int) ($candidate['kr_peak'] ?? 0);
        $recovery = (int) ($candidate['kr_recovery'] ?? 0);
        $popularity = (int) ($candidate['popularity'] ?? 0);
        $energyDelta = (int) $context['energyDelta'];

        return match ($mode) {
            'same' => $this->sameScore($context, $energyDelta, $reasons, $badges),
            'up' => $this->upScore($energyDelta, $floor, $peak, $reasons, $badges),
            'down' => $this->downScore($energyDelta, $risk, $familiarity, $reasons),
            'genre' => $this->genreScore($context, $risk, $familiarity, $reasons, $badges),
            'sing' => $this->singScore($singability, $popularity, $reasons, $badges),
            'recover' => $this->recoverScore($recovery, $familiarity, $floor, $risk, $reasons, $badges),
            'peak' => $this->peakScore($peak, $energy, $floor, $popularity, $energyDelta, $reasons, $badges),
            'fresh' => $this->freshScore($candidate, $risk, $popularity, $reasons, $badges),
            default => 0.0,
        };
    }

    private function sameScore(array $context, int $energyDelta, array &$reasons, array &$badges): float
    {
        $score = $context['sameGenre'] ? 24 : ($context['sameFamily'] ? 14 : -20);
        $score += abs($energyDelta) <= 10 ? 14 : -min(18, abs($energyDelta) * .45);
        $reasons[] = 'Continuità di vibe';
        $badges[] = 'Stessa vibe';
        return $score;
    }

    private function upScore(int $energyDelta, int $floor, int $peak, array &$reasons, array &$badges): float
    {
        $score = $energyDelta >= 10 ? min(30, $energyDelta * .9) : -22;
        $score += $floor >= 70 ? 10 : 0;
        $score += $peak >= 70 ? 8 : 0;
        $reasons[] = 'Alza energia';
        $badges[] = 'Salita energia';
        return $score;
    }

    private function downScore(int $energyDelta, int $risk, int $familiarity, array &$reasons): float
    {
        $score = $energyDelta <= -8 ? min(28, abs($energyDelta) * .85) : -20;
        $score += $risk <= 45 ? 8 : -8;
        $score += $familiarity >= 65 ? 6 : 0;
        $reasons[] = 'Fa respirare la pista';
        return $score;
    }

    private function genreScore(array $context, int $risk, int $familiarity, array &$reasons, array &$badges): float
    {
        $score = !$context['sameGenre'] ? 24 : -40;
        $score += $context['sameFamily'] ? 10 : 0;
        $score += $context['keyCompatible'] ? 8 : 0;
        $score += (float) $context['mixDiff'] <= 14 ? 8 : -12;
        $score += $risk <= 55 ? 6 : -8;
        $score += $familiarity >= 55 ? 5 : 0;
        $reasons[] = 'Cambio genere controllato';
        $badges[] = 'Cambio genere sicuro';
        return $score;
    }

    private function singScore(int $singability, int $popularity, array &$reasons, array &$badges): float
    {
        $score = $singability >= 70 ? min(32, ($singability - 55) * .85) : -20;
        $score += $popularity >= 60 ? 8 : 0;
        $reasons[] = 'Alta cantabilità';
        $badges[] = 'Canto';
        return $score;
    }

    private function recoverScore(int $recovery, int $familiarity, int $floor, int $risk, array &$reasons, array &$badges): float
    {
        $score = $recovery >= 70 ? 24 : -16;
        $score += $familiarity >= 75 ? 10 : 0;
        $score += $floor >= 70 ? 8 : 0;
        $score += $risk <= 35 ? 10 : -10;
        $reasons[] = 'Recupero pista';
        $badges[] = 'Recupero pista';
        return $score;
    }

    private function peakScore(int $peak, int $energy, int $floor, int $popularity, int $energyDelta, array &$reasons, array &$badges): float
    {
        $score = $peak >= 70 ? 24 : 0;
        $score += $energy >= 82 ? 10 : 0;
        $score += $floor >= 75 ? 8 : 0;
        $score += $popularity >= 70 ? 8 : 0;
        $score += $energyDelta >= 6 ? 6 : -8;
        $reasons[] = 'Momento picco';
        $badges[] = 'Picco';
        return $score;
    }

    private function freshScore(array $candidate, int $risk, int $popularity, array &$reasons, array &$badges): float
    {
        $year = (int) ($candidate['year'] ?? 0);
        $score = $year >= (int) date('Y') - 3 ? 18 : 0;
        $score += $popularity >= 70 ? 12 : 0;
        $score += $risk <= 65 ? 6 : -8;
        $score -= (int) ($candidate['play_count'] ?? 0) > 3 ? 8 : 0;
        $reasons[] = 'Materiale fresco';
        $badges[] = 'Fresh';
        return $score;
    }

    private function qualityScore(array $candidate, string $mode, array &$badges): float
    {
        $popularity = max(0, min(100, (int) ($candidate['popularity'] ?? 0)));
        $score = $popularity * ($mode === 'fresh' ? .16 : .10);
        if ($popularity >= 75) $badges[] = 'Hit';
        $score += ((int)($candidate['kr_floor_power'] ?? 50) * .05);
        $score += ((int)($candidate['kr_familiarity'] ?? 50) * .05);
        $score -= ((int)($candidate['kr_risk'] ?? 50) * .04);
        return $score;
    }

    private function matchesMode(array $current, array $candidate, string $mode, array $context): bool
    {
        $energyDelta = (int) $context['energyDelta'];
        return match($mode) {
            'same' => $context['sameGenre'] || ($context['sameFamily'] && abs($energyDelta) <= 18),
            'up' => ($energyDelta >= 10 && $energyDelta <= 45)
                || ($this->metric($current, 'kr_energy', 'energy') >= 82
                    && $this->metric($candidate, 'kr_energy', 'energy') >= 82
                    && $this->metric($candidate, 'kr_floor_power', 'danceability') >= 72
                    && $energyDelta >= -8),
            'down' => $energyDelta <= -8,
            'genre' => !$context['sameGenre'] && (int) $context['safe_change'] >= 52,
            'sing' => $this->metric($candidate, 'kr_singability', 'singability') >= 70,
            'recover' => (int)($candidate['kr_recovery'] ?? 0) >= 68
                && $this->metric($candidate, 'kr_familiarity', 'familiarity') >= 68
                && $this->metric($candidate, 'kr_floor_power', 'danceability') >= 65
                && $this->metric($candidate, 'kr_risk', 'risk') <= 42,
            'peak' => (int)($candidate['kr_peak'] ?? 0) >= 68 || $this->metric($candidate, 'kr_energy', 'energy') >= 82,
            'fresh' => ((int)($candidate['year'] ?? 0) >= (int)date('Y') - 3 || (int)($candidate['popularity'] ?? 0) >= 70)
                && $this->metric($candidate, 'kr_risk', 'risk') <= 70,
            default => true,
        };
    }

    private function safeGenreChange(array $current, array $candidate, float $mixDiff, bool $sameGenre, bool $sameFamily, bool $keyCompatible): int
    {
        $score = $mixDiff <= 3 ? 28 : ($mixDiff <= (float) setting('bpm_range', '8') ? 22 : ($mixDiff <= 14 ? 14 : 2));
        if ($keyCompatible) $score += 18;
        $score += $sameGenre ? 0 : ($sameFamily ? 22 : 12);
        $score += $this->metric($candidate, 'kr_familiarity', 'familiarity') * .12;
        $score += (100 - $this->metric($candidate, 'kr_risk', 'risk')) * .08;
        $score += $this->metric($candidate, 'kr_floor_power', 'danceability') * .06;
        return max(0, min(100, (int) round($score)));
    }

    private function hasBadge(array $badges, string $requiredTag): bool
    {
        $requiredTag = normalizeText($requiredTag);
        foreach ($badges as $badge) {
            if (normalizeText((string) $badge) === $requiredTag) return true;
        }
        return false;
    }

    private function metric(array $track, string $krField, string $manualField): int
    {
        if (isset($track[$krField]) && $track[$krField] !== null && $track[$krField] !== '') return max(0, min(100, (int) round((float) $track[$krField])));
        return max(0, min(100, (int)($track[$manualField] ?? 3) * 20));
    }

    private function keyCompatible(string $a, string $b): bool
    {
        $a = strtoupper(trim($a)); $b = strtoupper(trim($b));
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        if (!preg_match('/^(\d{1,2})([AB])$/', $a, $one) || !preg_match('/^(\d{1,2})([AB])$/', $b, $two)) return false;
        $distance = abs((int) $one[1] - (int) $two[1]);
        $wheelDistance = min($distance, 12 - $distance);
        return ($one[2] === $two[2] && $wheelDistance <= 1) || ($one[1] === $two[1] && $one[2] !== $two[2]);
    }

    private function genreFamily(string $genre): string
    {
        $genre = normalizeText($genre);
        if ($genre === '') return '';
        $families = [
            'latin'=>['reggaeton','dembow','latin','latino','cubat','salsa','timba','bachata','merengue','caraibica'],
            'urban'=>['urban','hip hop','rap','trap','r b','rnb','afro'],
            'dance'=>['dance','house','edm','commerciale','club','techno','trance'],
            'pop'=>['pop','italiano','italiana','hit','top','radio'],
            'rock'=>['rock','punk','metal','indie'],
            'revival'=>['70','80','90','2000','revival','disco'],
        ];
        foreach ($families as $family => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($genre, $needle)) return $family;
            }
        }
        return $genre;
    }
}
