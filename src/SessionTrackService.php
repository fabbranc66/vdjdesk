<?php
declare(strict_types=1);

final class SessionTrackService
{
    public const SCHEMA = 'krdesk.session_tracks';
    public const VERSION = 1;

    public function __construct(private PDO $pdo) {}

    public function export(array $session, string $outputPath): array
    {
        $tracks = $this->exportTracks((string)($session['id'] ?? 'kr-session'));
        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'source' => [
                'app' => 'KR DJ Desk',
                'environment' => 'studio-local',
                'database' => DB_NAME,
                'library_root' => 'E:',
                'export_profile' => 'public-request-search',
            ],
            'session' => [
                'id' => (string)($session['id'] ?? 'kr-session'),
                'name' => (string)($session['name'] ?? 'KR Session'),
                'event_date' => (string)($session['event_date'] ?? date('Y-m-d')),
                'venue' => (string)($session['venue'] ?? ''),
                'notes' => (string)($session['notes'] ?? ''),
            ],
            'stats' => [
                'tracks' => count($tracks),
                'available' => count(array_filter($tracks, fn(array $track): bool => (bool)$track['available'])),
                'unavailable' => count(array_filter($tracks, fn(array $track): bool => !(bool)$track['available'])),
            ],
            'tracks' => $tracks,
        ];
        $this->validatePayload($payload);
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cartella export non creata: ' . $dir);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($outputPath, $json . PHP_EOL) === false) {
            throw new RuntimeException('Export JSON sessione non salvato.');
        }
        return ['ok' => true, 'path' => $outputPath, 'tracks' => count($tracks), 'stats' => $payload['stats']];
    }

    public function publicSearch(string $query, int $limit = 50, ?string $path = null): array
    {
        $payload = $this->load($path ?? APP_ROOT . '/storage/session/krdesk_session_tracks.json');
        $tokens = $this->tokens($query);
        $items = [];
        foreach ($payload['tracks'] as $track) {
            if (empty($track['public']) || empty($track['requestable']) || empty($track['available'])) continue;
            $score = $this->matchScore($track, $tokens);
            if ($tokens && $score < 1) continue;
            $items[] = [
                'id' => (int)$track['track_id'],
                'artist' => (string)$track['artist'],
                'title' => (string)$track['title'],
                'genre' => (string)($track['genre'] ?? ''),
                'year' => isset($track['year']) ? (int)$track['year'] : null,
                '_score' => $score,
                '_priority' => (int)($track['priority'] ?? 0),
            ];
        }
        usort($items, fn(array $left, array $right): int => [$right['_score'], $right['_priority'], $left['artist'], $left['title']] <=> [$left['_score'], $left['_priority'], $right['artist'], $right['title']]);
        $items = array_slice($items, 0, max(1, min(200, $limit)));
        return array_map(function(array $item): array {
            unset($item['_score'], $item['_priority']);
            return $item;
        }, $items);
    }

    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('JSON sessione non caricato: ' . $path);
        }
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new RuntimeException('JSON sessione non valido.');
        }
        $this->validatePayload($payload);
        return $payload;
    }

    private function exportTracks(string $sessionId): array
    {
        $localFilter = appUsesLocalFiles() ? 'file_exists=1 AND ' : '';
        $statement = $this->pdo->query("
            SELECT id,artist,title,normalized_artist,normalized_title,genre,year,bpm,musical_key,camelot,tags,auto_tags,version,rating,play_count,risk
            FROM tracks
            WHERE {$localFilter}UPPER(file_path) LIKE 'E:%'
              AND TRIM(COALESCE(artist,'')) <> ''
              AND TRIM(COALESCE(title,'')) <> ''
            ORDER BY rating DESC, play_count DESC, artist, title, id
        ");
        $tracks = [];
        foreach ($statement->fetchAll() as $row) {
            $tags = array_values(array_unique(array_merge(trackTags($row), autoTrackTags($row))));
            $priority = $this->priority($row, $tags);
            $searchText = $this->searchText($row, $tags);
            $tracks[] = [
                'track_id' => (int)$row['id'],
                'artist' => (string)$row['artist'],
                'title' => (string)$row['title'],
                'artist_normalized' => (string)$row['normalized_artist'],
                'title_normalized' => (string)$row['normalized_title'],
                'search_text' => $searchText,
                'genre' => (string)$row['genre'],
                'macro_genre' => '',
                'subgenre' => (string)$row['genre'],
                'year' => $row['year'] === null ? null : (int)$row['year'],
                'bpm' => $row['bpm'] === null ? null : (float)$row['bpm'],
                'musical_key' => (string)$row['musical_key'],
                'camelot' => (string)$row['camelot'],
                'tags' => $tags,
                'version' => (string)$row['version'],
                'priority' => $priority,
                'available' => true,
                'public' => true,
                'requestable' => true,
                'session' => ['id' => $sessionId, 'bucket' => 'main', 'note' => ''],
            ];
        }
        return $tracks;
    }

    private function priority(array $row, array $tags): int
    {
        $priority = 50;
        if ((int)($row['rating'] ?? 0) >= 4) $priority += 20;
        if ((int)($row['play_count'] ?? 0) > 0) $priority += 10;
        if (array_intersect($tags, ['PISTA', 'URLANTE', 'SUCCESSO', 'POPOLARE'])) $priority += 10;
        if ((int)($row['risk'] ?? 0) >= 4) $priority -= 20;
        return max(0, min(100, $priority));
    }

    private function searchText(array $row, array $tags): string
    {
        return preg_replace('/\s+/', ' ', trim(strtolower(implode(' ', array_filter([
            $row['artist'] ?? '',
            $row['title'] ?? '',
            $row['normalized_artist'] ?? '',
            $row['normalized_title'] ?? '',
            $row['genre'] ?? '',
            $row['year'] ?? '',
            implode(' ', $tags),
            $row['version'] ?? '',
        ]))))) ?? '';
    }

    private function tokens(string $query): array
    {
        $parts = preg_split('/[^\pL\pN]+/u', strtolower(trim($query))) ?: [];
        return array_values(array_filter(array_unique($parts), fn(string $token): bool => strlen($token) >= 2));
    }

    private function matchScore(array $track, array $tokens): int
    {
        if (!$tokens) return 1;
        $haystack = ' ' . (string)$track['search_text'] . ' ';
        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) $score++;
        }
        return $score;
    }

    private function validatePayload(array $payload): void
    {
        if (($payload['schema'] ?? '') !== self::SCHEMA || (int)($payload['schema_version'] ?? 0) !== self::VERSION) {
            throw new RuntimeException('Schema JSON sessione non supportato.');
        }
        if (!isset($payload['tracks']) || !is_array($payload['tracks'])) {
            throw new RuntimeException('JSON sessione senza tracks.');
        }
        foreach ($payload['tracks'] as $track) {
            foreach (['track_id', 'artist', 'title', 'search_text', 'priority', 'available', 'public', 'requestable'] as $field) {
                if (!array_key_exists($field, $track)) throw new RuntimeException('Track sessione senza campo: ' . $field);
            }
            $encoded = json_encode($track, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && preg_match('/[A-Z]:\\\\/i', $encoded)) {
                throw new RuntimeException('JSON sessione contiene path Windows.');
            }
        }
    }
}
