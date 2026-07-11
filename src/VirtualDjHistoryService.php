<?php
declare(strict_types=1);

final class VirtualDjHistoryService
{
    public function __construct(private PDO $pdo) {}

    public function snapshot(): array
    {
        $historyFile = $this->latestHistoryFile();
        if ($historyFile === null) {
            return ['current'=>null,'recent'=>[],'total'=>0,'started_at'=>null,'source_file'=>null,'modified_at'=>null];
        }

        $entries = $this->continuousBlock($this->parse($historyFile));
        $recentEntries = array_slice(array_reverse($entries), 0, 10);
        $recent = array_map(fn(array $entry): array => $this->enrich($entry), $recentEntries);
        $startedAt = null;
        foreach ($entries as $entry) {
            if (!empty($entry['played_at'])) {
                $startedAt = $entry['played_at'];
                break;
            }
        }

        return [
            'current' => $recent[0] ?? null,
            'recent' => $recent,
            'total' => count($entries),
            'started_at' => $startedAt,
            'source_file' => $historyFile,
            'modified_at' => date('Y-m-d H:i:s', (int) filemtime($historyFile)),
        ];
    }

    public function recentEntries(int $limit): array
    {
        $historyFile = $this->latestHistoryFile();
        if ($historyFile === null) return [];
        return array_slice(array_reverse($this->continuousBlock($this->parse($historyFile))), 0, max(1, $limit));
    }

    public function entriesSinceMinutes(int $minutes): array
    {
        $historyFile=$this->latestHistoryFile();if($historyFile===null)return [];
        $threshold=time()-(max(1,$minutes)*60);
        return array_values(array_filter(array_reverse($this->parse($historyFile)),static function(array $entry)use($threshold): bool{
            $playedAt=!empty($entry['played_at'])?strtotime((string)$entry['played_at']):false;
            return $playedAt!==false&&$playedAt>=$threshold;
        }));
    }

    private function latestHistoryFile(): ?string
    {
        $database = setting('vdj_database', '');
        $historyDirectory = dirname($database) . DIRECTORY_SEPARATOR . 'History';
        if (!is_dir($historyDirectory)) return null;
        $files = glob($historyDirectory . DIRECTORY_SEPARATOR . '*.m3u') ?: [];
        usort($files, static function(string $left, string $right): int {
            $leftDate = pathinfo($left, PATHINFO_FILENAME);
            $rightDate = pathinfo($right, PATHINFO_FILENAME);
            $leftValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $leftDate) === 1;
            $rightValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rightDate) === 1;
            if ($leftValid && $rightValid) return strcmp($rightDate, $leftDate);
            if ($leftValid !== $rightValid) return $leftValid ? -1 : 1;
            return filemtime($right) <=> filemtime($left);
        });
        return $files[0] ?? null;
    }

    private function continuousBlock(array $entries): array
    {
        if (count($entries) < 2) return $entries;
        $start = 0;
        for ($index = 1, $count = count($entries); $index < $count; $index++) {
            $previous = !empty($entries[$index - 1]['played_at']) ? strtotime((string) $entries[$index - 1]['played_at']) : false;
            $current = !empty($entries[$index]['played_at']) ? strtotime((string) $entries[$index]['played_at']) : false;
            if ($previous !== false && $current !== false && ($current - $previous) > 1800) $start = $index;
        }
        return array_slice($entries, $start);
    }

    private function parse(string $historyFile): array
    {
        $lines = file($historyFile, FILE_IGNORE_NEW_LINES) ?: [];
        $entries = [];
        $metadata = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_starts_with($line, '#EXTVDJ:')) {
                $metadata = $this->parseMetadata(substr($line, 8));
                continue;
            }
            if (str_starts_with($line, '#')) continue;
            $path = canonicalPath($line);
            $playedAt = !empty($metadata['lastplaytime']) ? date('Y-m-d H:i:s', (int) $metadata['lastplaytime']) : null;
            if ($playedAt === null && !empty($metadata['time'])) {
                $fileDate = pathinfo($historyFile, PATHINFO_FILENAME);
                $playedAt = $fileDate . ' ' . $metadata['time'] . ':00';
            }
            [$fallbackArtist, $fallbackTitle] = $this->artistTitleFromFilename(basename($path));
            $entries[] = [
                'file_path' => $path,
                'artist' => trim((string) ($metadata['artist'] ?? '')) ?: $fallbackArtist,
                'title' => trim((string) ($metadata['title'] ?? '')) ?: $fallbackTitle,
                'duration' => isset($metadata['songlength']) ? (int) round((float) $metadata['songlength']) : null,
                'played_at' => $playedAt,
            ];
            $metadata = [];
        }
        return $entries;
    }

    private function parseMetadata(string $xmlFragment): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string('<entry>' . $xmlFragment . '</entry>', SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (!$xml) return [];
        $result = [];
        foreach ($xml->children() as $name => $value) $result[$name] = (string) $value;
        return $result;
    }

    private function enrich(array $entry): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tracks WHERE file_path=? LIMIT 1');
        $statement->execute([$entry['file_path']]);
        $track = $statement->fetch() ?: [];
        $result = array_merge($entry, $track, ['played_at'=>$entry['played_at'], 'file_path'=>$entry['file_path']]);
        $result['artist'] = trim((string) ($track['artist'] ?? '')) ?: $entry['artist'];
        $result['title'] = trim((string) ($track['title'] ?? '')) ?: $entry['title'];
        $result['tags'] = json_decode((string) ($track['tags'] ?? '[]'), true) ?: [];
        $result['energy'] = (int) ($track['energy'] ?? 3);
        return $result;
    }

    private function artistTitleFromFilename(string $fileName): array
    {
        $name = preg_replace('/^\s*\d+\s*-\s*/', '', pathinfo($fileName, PATHINFO_FILENAME)) ?? pathinfo($fileName, PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $name, 2);
        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($name)];
    }
}
