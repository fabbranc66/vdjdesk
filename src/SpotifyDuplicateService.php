<?php
declare(strict_types=1);

final class SpotifyDuplicateService
{
    public function __construct(private PDO $pdo) {}

    public function groups(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sameSpotifyId = $this->sameSpotifyId($limit);
        $sameIsrc = [];
        $sameArtistTitleDifferentSpotify = $this->sameTitleSharedArtistDifferentSpotify($limit);
        $groups = array_merge($sameSpotifyId, $sameArtistTitleDifferentSpotify);
        usort($groups, fn(array $left, array $right): int => [$right['confidence'], $right['total']] <=> [$left['confidence'], $left['total']]);
        $shown = array_slice($groups, 0, $limit);

        return [
            'summary' => [
                'groups' => count($groups),
                'shown' => count($shown),
                'same_spotify_id' => count($sameSpotifyId),
                'same_isrc' => count($sameIsrc),
                'same_artist_title_different_spotify' => count($sameArtistTitleDifferentSpotify),
            ],
            'items' => $shown,
        ];
    }

    public function writeCsv(int $limit = 500): array
    {
        $data = $this->groups($limit);
        $dir = APP_ROOT . '/storage/reports';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cartella report non creata.');
        }
        $path = $dir . '/spotify_duplicates_' . date('Ymd_His') . '.csv';
        $handle = fopen($path, 'wb');
        if (!$handle) throw new RuntimeException('Report doppioni Spotify non creato.');

        fputcsv($handle, ['group_type','confidence','label','recommendation','track_id','keep','artist','title','spotify_id','isrc','bitrate','rating','play_count','file_path']);
        foreach ($data['items'] as $group) {
            foreach ($group['items'] as $track) {
                fputcsv($handle, [
                    $group['type'],
                    $group['confidence'],
                    $group['label'],
                    $group['recommended_id'],
                    $track['id'],
                    (int)$track['is_recommended'],
                    $track['artist'],
                    $track['title'],
                    $track['spotify_id'],
                    $track['isrc'],
                    $track['bitrate'],
                    $track['rating'],
                    $track['play_count'],
                    $track['file_path'],
                ]);
            }
        }
        fclose($handle);

        return ['ok' => true, 'path' => $path, 'groups' => count($data['items'])];
    }

    public function markNonRecommended(int $limit = 500): array
    {
        $data = $this->groups($limit);
        $upsert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO deletion_candidates(
                source_path, source_folder, source_name, source_size,
                e_file_path, e_file_name, e_file_size,
                match_type, confidence, reason, status, decision_note
            ) VALUES(?,?,?,?,?,?,?,?,?,?,'marked',?)
            ON DUPLICATE KEY UPDATE
                source_folder=VALUES(source_folder),
                source_name=VALUES(source_name),
                source_size=VALUES(source_size),
                e_file_path=VALUES(e_file_path),
                e_file_name=VALUES(e_file_name),
                e_file_size=VALUES(e_file_size),
                match_type=VALUES(match_type),
                confidence=VALUES(confidence),
                reason=VALUES(reason),
                status=CASE WHEN status IN ('approved','moved','deleted') THEN status ELSE 'marked' END,
                decision_note=CASE WHEN status IN ('approved','moved','deleted') THEN decision_note ELSE VALUES(decision_note) END,
                last_seen_at=CURRENT_TIMESTAMP
        SQL);

        $marked = 0;
        foreach ($data['items'] as $group) {
            if ((int)$group['confidence'] < 95) continue;
            $recommended = null;
            foreach ($group['items'] as $item) {
                if (!empty($item['is_recommended'])) {
                    $recommended = $item;
                    break;
                }
            }
            if (!$recommended) continue;
            foreach ($group['items'] as $item) {
                if (!empty($item['is_recommended'])) continue;
                $sourcePath = canonicalPath((string)$item['file_path']);
                $targetPath = canonicalPath((string)$recommended['file_path']);
                if ($sourcePath === '' || $targetPath === '' || strcasecmp($sourcePath, $targetPath) === 0) continue;
                if (!is_file($sourcePath)) continue;
                $reason = $group['reason'] . ' Consigliato da tenere: ' . $recommended['artist'] . ' - ' . $recommended['title'] . ' [' . $targetPath . '].';
                $upsert->execute([
                    $sourcePath,
                    dirname($sourcePath),
                    basename($sourcePath),
                    (int)($item['file_size'] ?? 0),
                    $targetPath,
                    basename($targetPath),
                    (int)($recommended['file_size'] ?? 0),
                    (string)$group['type'],
                    (int)$group['confidence'],
                    $reason,
                    'Marcato da report Doppioni Spotify',
                ]);
                $marked++;
            }
        }

        return ['ok' => true, 'groups' => count($data['items']), 'marked' => $marked];
    }

    private function sameSpotifyId(int $limit): array
    {
        $statement = $this->pdo->query("
            SELECT spotify_id, normalized_artist, normalized_title
            FROM tracks
            WHERE file_exists=1
              AND " . definitiveMusicSqlCondition() . "
              AND EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id)
              AND TRIM(COALESCE(spotify_id,'')) <> ''
              AND normalized_artist <> ''
              AND normalized_title <> ''
            GROUP BY spotify_id, normalized_artist, normalized_title
            HAVING COUNT(*) > 1
            ORDER BY COUNT(*) DESC
            LIMIT {$limit}
        ");
        $groups = [];
        $fetch = $this->pdo->prepare($this->trackSelectSql('spotify_id = ? AND normalized_artist = ? AND normalized_title = ?'));
        foreach ($statement->fetchAll() as $row) {
            $fetch->execute([(string)$row['spotify_id'], (string)$row['normalized_artist'], (string)$row['normalized_title']]);
            $groups[] = $this->group('same_spotify_id', 100, 'Stesso Spotify ID/artista/titolo: ' . (string)$row['spotify_id'] . ' / ' . (string)$row['normalized_artist'] . ' / ' . (string)$row['normalized_title'], $fetch->fetchAll());
        }
        return $groups;
    }

    private function sameTitleSharedArtistDifferentSpotify(int $limit): array
    {
        $statement = $this->pdo->query("
            SELECT id,artist,title,normalized_artist,normalized_title,spotify_id
            FROM tracks
            WHERE file_exists=1
              AND " . definitiveMusicSqlCondition() . "
              AND EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id)
              AND TRIM(COALESCE(spotify_id,'')) <> ''
              AND normalized_artist <> ''
              AND normalized_title <> ''
            ORDER BY normalized_title, normalized_artist, id
        ");
        $byTitle = [];
        foreach ($statement->fetchAll() as $row) {
            $byTitle[(string)$row['normalized_title']][] = $row;
        }

        $groups = [];
        foreach ($byTitle as $title => $rows) {
            if (count($rows) < 2) continue;
            $components = $this->artistOverlapComponents($rows);
            foreach ($components as $ids) {
                if (count($ids) < 2) continue;
                $idList = implode(',', array_map('intval', $ids));
                $tracksStatement = $this->pdo->query(str_replace('%s', $idList, $this->trackSelectSql('id IN (%s)')));
                $tracks = $tracksStatement->fetchAll();
                $spotifyIds = array_values(array_unique(array_filter(array_map(fn(array $track): string => (string)$track['spotify_id'], $tracks))));
                if (count($tracks) < 2 || count($spotifyIds) < 2) continue;
                $artists = array_values(array_unique(array_map(fn(array $track): string => (string)$track['artist'], $tracks)));
                $groups[] = $this->group(
                    'same_title_shared_artist_different_spotify',
                    70,
                    'Stesso titolo, artista in comune, Spotify ID diversi: ' . $title . ' / ' . implode(' | ', array_slice($artists, 0, 3)),
                    $tracks
                );
                if (count($groups) >= $limit) return $groups;
            }
        }
        return $groups;
    }

    private function artistOverlapComponents(array $rows): array
    {
        $parent = [];
        foreach ($rows as $row) $parent[(int)$row['id']] = (int)$row['id'];
        $find = function(int $id) use (&$parent, &$find): int {
            if ($parent[$id] !== $id) $parent[$id] = $find($parent[$id]);
            return $parent[$id];
        };
        $union = function(int $left, int $right) use (&$parent, $find): void {
            $leftRoot = $find($left);
            $rightRoot = $find($right);
            if ($leftRoot !== $rightRoot) $parent[$rightRoot] = $leftRoot;
        };

        $count = count($rows);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if ((string)$rows[$left]['spotify_id'] === (string)$rows[$right]['spotify_id']) continue;
                if (!$this->shareArtistToken((string)$rows[$left]['normalized_artist'], (string)$rows[$right]['normalized_artist'])) continue;
                $union((int)$rows[$left]['id'], (int)$rows[$right]['id']);
            }
        }

        $components = [];
        foreach ($rows as $row) $components[$find((int)$row['id'])][] = (int)$row['id'];
        return array_values($components);
    }

    private function shareArtistToken(string $left, string $right): bool
    {
        $leftTokens = $this->artistTokens($left);
        if (!$leftTokens) return false;
        $rightTokens = array_flip($this->artistTokens($right));
        foreach ($leftTokens as $token) {
            if (isset($rightTokens[$token])) return true;
        }
        return false;
    }

    private function artistTokens(string $artist): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($artist)) ?: [];
        $stop = ['dj'=>true,'mc'=>true,'feat'=>true,'featuring'=>true,'ft'=>true,'the'=>true,'la'=>true,'el'=>true,'los'=>true,'las'=>true,'y'=>true,'and'=>true];
        return array_values(array_unique(array_filter($tokens, fn(string $token): bool => strlen($token) >= 3 && !isset($stop[$token]))));
    }

    private function trackSelectSql(string $where): string
    {
        return "
            SELECT id,artist,title,file_path,file_name,genre,year,bpm,camelot,version,spotify_id,isrc,bitrate,rating,play_count,file_exists,metadata_source,spotify_features_status,file_size,updated_at
            FROM tracks
            WHERE file_exists=1 AND " . definitiveMusicSqlCondition() . " AND EXISTS(SELECT 1 FROM track_sources WHERE track_sources.track_id=tracks.id) AND {$where}
            ORDER BY bitrate DESC, rating DESC, play_count DESC, file_size DESC, id
        ";
    }

    private function group(string $type, int $confidence, string $label, array $tracks): array
    {
        $recommended = $this->recommended($tracks);
        $items = array_map(function(array $track) use ($recommended): array {
            $track['id'] = (int)$track['id'];
            $track['bitrate'] = $track['bitrate'] === null ? null : (int)$track['bitrate'];
            $track['rating'] = (int)$track['rating'];
            $track['play_count'] = (int)$track['play_count'];
            $track['file_exists'] = (int)$track['file_exists'];
            $track['file_size'] = $track['file_size'] === null ? null : (int)$track['file_size'];
            $track['is_recommended'] = (int)$track['id'] === (int)$recommended['id'];
            return $track;
        }, $tracks);
        return [
            'type' => $type,
            'confidence' => $confidence,
            'label' => $label,
            'total' => count($items),
            'recommended_id' => (int)$recommended['id'],
            'reason' => $this->reason($type),
            'items' => $items,
        ];
    }

    private function recommended(array $tracks): array
    {
        usort($tracks, function(array $left, array $right): int {
            return [
                (int)$right['file_exists'],
                (int)$right['bitrate'],
                (int)$right['rating'],
                (int)$right['play_count'],
                (int)$right['file_size'],
                (string)$right['spotify_features_status'] === 'complete' ? 1 : 0,
            ] <=> [
                (int)$left['file_exists'],
                (int)$left['bitrate'],
                (int)$left['rating'],
                (int)$left['play_count'],
                (int)$left['file_size'],
                (string)$left['spotify_features_status'] === 'complete' ? 1 : 0,
            ];
        });
        return $tracks[0] ?? ['id' => 0];
    }

    private function reason(string $type): string
    {
        return match ($type) {
            'same_spotify_id' => 'Doppione molto probabile: stesso Spotify ID.',
            'same_isrc' => 'Doppione molto probabile: stesso ISRC.',
            'same_title_shared_artist_different_spotify' => 'Controllo manuale: stesso titolo e almeno un artista in comune, ma Spotify ID diverso.',
            default => 'Possibile doppione o versione alternativa: artista/titolo uguali ma Spotify ID diverso.',
        };
    }
}
