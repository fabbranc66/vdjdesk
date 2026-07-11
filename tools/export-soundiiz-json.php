<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if ($argc < 3) {
    fwrite(STDERR, "Uso: php tools/export-soundiiz-json.php <cartella> <output.json>\n");
    exit(1);
}

$folder = rtrim($argv[1], "\\/");
$output = $argv[2];
if (!is_dir($folder)) {
    fwrite(STDERR, "Cartella non trovata: $folder\n");
    exit(1);
}

$metadata = db()->prepare("SELECT artist,title FROM tracks WHERE file_path=? ORDER BY CASE WHEN metadata_source='sortlee' THEN 0 ELSE 1 END,updated_at DESC,id DESC LIMIT 1");
$items = [];
$files = new DirectoryIterator($folder);
foreach ($files as $file) {
    if (!$file->isFile() || !preg_match('/\.(mp3|mp4|m4a|wav|flac|aac|ogg)$/i', $file->getFilename())) continue;
    $metadata->execute([$file->getPathname()]);
    $track = $metadata->fetch() ?: [];
    $artist = trim((string) ($track['artist'] ?? ''));
    $title = trim((string) ($track['title'] ?? ''));
    if ($title === '') {
        $base = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $base, 2);
        $artist = count($parts) === 2 ? trim($parts[0]) : $artist;
        $title = trim($parts[1] ?? $base);
    }
    $items[] = ['type' => 'track', 'title' => $title, 'artist' => $artist];
}

usort($items, static fn(array $left, array $right): int => strnatcasecmp($left['artist'] . ' ' . $left['title'], $right['artist'] . ' ' . $right['title']));
file_put_contents($output, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
printf("Creato: %s\nRecord: %d\n", $output, count($items));
