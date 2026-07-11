<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if ($argc < 4) {
    fwrite(STDERR, "Uso: php tools/enrich-sortlee-folder.php <cartella> <metriche.json> <database.sqlite> [--apply]\n");
    exit(1);
}

[$script, $folder, $metricsPath, $databasePath] = $argv;
$apply = in_array('--apply', $argv, true);

if (!is_dir($folder) || !is_file($metricsPath)) {
    fwrite(STDERR, "Cartella o metriche non trovate.\n");
    exit(1);
}

function repairText(string $value): string
{
    if (preg_match('/(?:Ã.|Â.|â.)/u', $value)) {
        $fixed = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        if ($fixed !== '') {
            return $fixed;
        }
    }
    return $value;
}

function normalized(string $value): string
{
    $value = repairText(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['&', '+'], ' and ', $value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii === false ? $value : $ascii;
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function normalizedArtist(string $value): string
{
    $value = preg_replace('/\b(feat|ft|featuring)\.?\s+.*$/iu', '', repairText($value)) ?? repairText($value);
    return normalized($value);
}

function coreTitle(string $value): string
{
    $value = repairText($value);
    $value = (string) preg_replace('/\s*[\(\[].*?[\)\]]/u', ' ', $value);
    $value = (string) preg_replace('/\b(feat|ft)\.?\s+.*$/iu', ' ', $value);
    $value = (string) preg_replace('/\b(radio edit|extended mix|extended remix|original mix|club mix|full vocal mix|remix edit|remix)\b/iu', ' ', $value);
    $value = (string) preg_replace('/^\s*\d+\s*-\s*/u', '', $value);
    $value = str_replace([' camarn', ' mara ', ' corazn', ' qu ', ' p ', ' chn ', ' acab ', ' cuc', ' cafs', ' lzaro ', ' traicion ', ' paro'], [' camaron', ' maria ', ' corazon', ' que ', ' pu ', ' chan ', ' acabo ', ' cucu', ' cafes', ' lazaro ', ' traiciono ', ' paro'], $value);
    return normalized($value);
}

function detectVersion(string $value): string
{
    foreach (['Extended Mix','Radio Edit','Clean','Explicit','Intro','Remix','Mashup','Acapella','Instrumental'] as $version) {
        if (stripos($value, $version) !== false) {
            return $version;
        }
    }
    return '';
}

function extractPosition(string $fileName): ?int
{
    if (preg_match('/^\s*(\d{1,3})\s*-\s*/', $fileName, $matches)) {
        return (int) $matches[1];
    }
    return null;
}

function decodeTextFrame(int $encoding, string $payload): string
{
    if ($payload === '') {
        return '';
    }
    return match ($encoding) {
        0 => mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1'),
        1 => mb_convert_encoding($payload, 'UTF-8', 'UTF-16'),
        2 => mb_convert_encoding($payload, 'UTF-8', 'UTF-16BE'),
        3 => $payload,
        default => $payload,
    };
}

function syncSafeToInt(string $bytes): int
{
    $parts = array_map('ord', str_split($bytes));
    return (($parts[0] & 0x7F) << 21) | (($parts[1] & 0x7F) << 14) | (($parts[2] & 0x7F) << 7) | ($parts[3] & 0x7F);
}

function readId3Tags(string $path): array
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return ['artist' => '', 'title' => ''];
    }
    $header = fread($handle, 10);
    if (strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
        fclose($handle);
        return ['artist' => '', 'title' => ''];
    }
    $version = ord($header[3]);
    $size = syncSafeToInt(substr($header, 6, 4));
    $body = $size > 0 ? fread($handle, $size) : '';
    fclose($handle);

    $result = ['artist' => '', 'title' => ''];
    $offset = 0;
    while ($offset + 10 <= strlen($body)) {
        $frameId = substr($body, $offset, 4);
        if ($frameId === "\0\0\0\0" || trim($frameId) === '') {
            break;
        }
        $sizeBytes = substr($body, $offset + 4, 4);
        $frameSize = $version >= 4 ? syncSafeToInt($sizeBytes) : unpack('N', $sizeBytes)[1];
        if ($frameSize <= 0 || $offset + 10 + $frameSize > strlen($body)) {
            break;
        }
        $frameData = substr($body, $offset + 10, $frameSize);
        $encoding = ord($frameData[0] ?? "\0");
        $text = trim(repairText(decodeTextFrame($encoding, substr($frameData, 1))), " \t\n\r\0\x0B\xEF\xBB\xBF");
        if ($frameId === 'TPE1' && $result['artist'] === '') {
            $result['artist'] = $text;
        } elseif ($frameId === 'TIT2' && $result['title'] === '') {
            $result['title'] = $text;
        }
        if ($result['artist'] !== '' && $result['title'] !== '') {
            break;
        }
        $offset += 10 + $frameSize;
    }

    return $result;
}

function camelotFromCircle(string $value): string
{
    if ($value === '' || $value === '-') {
        return '';
    }
    $raw = (float) str_replace(',', '.', $value);
    $number = ((int) floor($raw) + 7) % 12 + 1;
    $letter = abs($raw - floor($raw) - 0.5) < 0.01 ? 'A' : 'B';
    return $number . $letter;
}

function musicalKey(string $value): string
{
    $value = repairText(trim($value));
    if ($value === '' || $value === '-') {
        return '';
    }
    $value = str_replace(['♯', '♭'], ['#', 'b'], $value);
    $isMinor = preg_match('/^[a-g]/', $value) === 1 || preg_match('/m$/', $value) === 1;
    $root = strtoupper(substr($value, 0, 1)) . substr($value, 1);
    return $isMinor && !str_ends_with($root, 'm') ? $root . 'm' : $root;
}

$decoded = json_decode((string) file_get_contents($metricsPath), true, 512, JSON_THROW_ON_ERROR);
$metricGroups = $decoded['metrics'] ?? [];
$tracks = [];
foreach ($metricGroups as $metricName => $metricGroup) {
    foreach (($metricGroup['rows'] ?? []) as $row) {
        $spotifyId = (string) ($row['spotify_id'] ?? '');
        if ($spotifyId === '') {
            continue;
        }
        $tracks[$spotifyId] ??= [
            'spotify_id' => $spotifyId,
            'spotify_url' => (string) ($row['spotify_url'] ?? ''),
            'title' => repairText((string) ($row['title'] ?? '')),
            'position' => isset($row['position']) ? (int) $row['position'] : null,
        ];
        $tracks[$spotifyId][$metricName] = repairText((string) ($row['value'] ?? ''));
    }
}
$tracksByPosition = [];
foreach ($tracks as $spotifyId => $track) {
    if (!empty($track['position'])) {
        $tracksByPosition[(int) $track['position']] = $spotifyId;
    }
}

$explicit = [
    'Alex Gaudino, Crystal Waters - Destination Calabria.mp3' => '5TmFTHZp7HjBXjjsFvCY6h',
    'Masove, Tess Burrstone, Niteblue - Destination Calabria.mp3' => '3VtgKy06wkxOLoxxe0lqXa',
    'Bakermat - Baian.mp3' => '780be5fB7823aHG06mwTat',
    'Bakermat - Baianá.mp3' => '780be5fB7823aHG06mwTat',
    'Rogerson - Baianá.mp3' => '32ERIp4nITuLWytf9gmLRe',
    'Kato, Jon - Turn The Lights Off.mp3' => '3ssyED50WJpisKCW7n6rND',
    'Justė, Jaxstyle, Jon - Turn The Lights Off.mp3' => '5TV7JnCTbwPD6yIVVFJrLb',
    'Shouse - Love Tonight (Edit).mp3' => '6OufwUcCqo81guU2jAlDVP',
    'Shouse, David Guetta - Love Tonight (David Guetta Remix Edit).mp3' => '1u73tmG4xQschbK8cXxSD9',
    'Octave One - Blackwater Full Strings Vocal Mix.mp3' => '66IbYJvnP9XhmRjXMF7nI2',
    'R3hab, A Touch Of Class - All Around The World (La La La).mp3' => '7CvOnbFdnIoXMQ4eFCo5lB',
    'Bob Sinclar - Someone Who Needs Me.mp3' => '134AvAs3jzkk963xbTYWUo',
    "Imany, Filatov & Karas - Don't Be So Shy (Filatov & Karas Remix).mp3" => '4bNqSJ92afDvDZiYgpckVf',
    'Bizarrap, Quevedo - Bzrp Music Sessions Vol 52.mp3' => '2tTmW7RDtMQtBk7m2rYeSw',
    'Quevedo - Bzrp Music Sessions, Vol. 52.mp3' => '2tTmW7RDtMQtBk7m2rYeSw',
    'Gotye, Fisher, Chris Lake, Kimbra, Sante Sansone - Somebody 2024.mp3' => '0agQ9vIV7NP4dntGKLcCXO',
    'La Plena - W Sound 05.mp3' => '6xOEgzkMSZJKz6qtCJsQL5',
    'W Sound, Beéle, Ovy On The Drums - La Plena.mp3' => '6xOEgzkMSZJKz6qtCJsQL5',
    'Morgan Seatree, Florence + The Machine - Say My Name (Remix).mp3' => '2o4jEBE8I1hk3BGkzcBGJP',
    'Mëstiza, Las Ketchup - Asereje.mp3' => '7yiZOX1floHlpZEskbbZnk',
    'Raffaella Carrà, Mark Ronson, Marlon Hoffstadt - Rumore (Remix).mp3' => '4ozkNCaitVMxT5zLiLPfX8',
    'Joel Corry, Jax Jones, Charli Xcx, Saweetie Feat. Charli Xcx & Saweetie - Out Out.mp3' => '6Dy1jexKYriXAVG6evyUTJ',
    'Out Out Feat. Charli Xcx - Saweetie.mp3' => '6Dy1jexKYriXAVG6evyUTJ',
    '186 - Agua with J Balvin - Music From Sponge On The Run Movie.mp3' => '6V66mPTxmYiepiUKRpsqdd',
];

$pdo = db();
$metadata = $pdo->prepare("SELECT artist,title FROM tracks WHERE file_path = ? ORDER BY CASE WHEN metadata_source='sortlee' THEN 0 ELSE 1 END, updated_at DESC, id DESC LIMIT 1");
$insertTrack = $pdo->prepare("INSERT IGNORE INTO tracks(artist,title,normalized_artist,normalized_title,file_path,file_name,folder,file_size,file_exists,source,version,tags) VALUES(?,?,?,?,?,?,?,?,1,?,?,'[]')");
$files = [];
$iterator = new DirectoryIterator($folder);
foreach ($iterator as $file) {
    if (!$file->isFile() || !preg_match('/\.(mp3|mp4|m4a|wav|flac|aac|ogg)$/i', $file->getFilename())) {
        continue;
    }
    $path = $file->getPathname();
    $metadata->execute([$path]);
    $row = $metadata->fetch(PDO::FETCH_ASSOC) ?: [];
    $metadata->closeCursor();
    $artist = trim((string) ($row['artist'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));
    if ($title === '' || $artist === '') {
        $id3 = preg_match('/\.mp3$/i', $file->getFilename()) ? readId3Tags($path) : ['artist' => '', 'title' => ''];
        $artist = $artist !== '' ? $artist : trim((string) ($id3['artist'] ?? ''));
        $title = $title !== '' ? $title : trim((string) ($id3['title'] ?? ''));
    }
    if ($title === '') {
        $base = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $base, 2);
        $title = $parts[1] ?? $base;
    }
    if ($artist === '') {
        $base = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $base, 2);
        $artist = count($parts) === 2 ? trim((string) $parts[0]) : '';
    }
    $insertTrack->execute([
        $artist,
        $title,
        normalizedArtist($artist),
        coreTitle($title),
        $path,
        $file->getFilename(),
        dirname($path),
        filesize($path),
        'scan',
        detectVersion($file->getFilename()),
    ]);
    $files[] = [
        'path' => $path,
        'name' => $file->getFilename(),
        'artist' => $artist,
        'title' => $title,
        'position' => extractPosition($file->getFilename()),
    ];
}

$assignments = [];
$unmatched = [];
foreach ($files as $file) {
    $spotifyId = $explicit[$file['name']] ?? '';
    $score = $spotifyId !== '' ? 100.0 : 0.0;
    if ($spotifyId === '') {
        if ($file['position'] !== null && isset($tracksByPosition[$file['position']])) {
            $candidateId = $tracksByPosition[$file['position']];
            $candidate = $tracks[$candidateId];
            similar_text(coreTitle($file['title']), coreTitle($candidate['title']), $positionScore);
            if ($positionScore >= 45.0) {
                $spotifyId = $candidateId;
                $score = max(95.0, $positionScore);
            }
        }
    }
    if ($spotifyId === '') {
        $localFull = normalized($file['title']);
        $localCore = coreTitle($file['title']);
        $best = [];
        foreach ($tracks as $candidateId => $track) {
            similar_text($localFull, normalized($track['title']), $fullScore);
            similar_text($localCore, coreTitle($track['title']), $coreScore);
            $candidateScore = max($fullScore, $coreScore * 0.97);
            $best[] = [$candidateScore, $candidateId];
        }
        usort($best, static fn(array $left, array $right): int => $right[0] <=> $left[0]);
        [$score, $spotifyId] = $best[0] ?? [0.0, ''];
        $runnerUp = $best[1][0] ?? 0.0;
        if ($score < 72.0 || ($score - $runnerUp < 1.5 && $score < 99.9)) {
            $spotifyId = '';
        }
    }
    if ($spotifyId === '' || !isset($tracks[$spotifyId])) {
        $unmatched[] = $file;
        continue;
    }
    $assignments[] = $file + ['score' => $score, 'track' => $tracks[$spotifyId]];
}

printf("File fisici: %d\nMetriche Sortlee: %d righe, %d brani Spotify unici\nAbbinati: %d\nNon abbinati: %d\n", count($files), (int) ($metricGroups['release_date']['total'] ?? 0), count($tracks), count($assignments), count($unmatched));
foreach ($unmatched as $file) {
    printf("NON ABBINATO: %s | %s\n", $file['name'], $file['title']);
}

$checkNames = ['Beauty And A Beat', 'Justin Bieber - Company', 'Love Yourself', 'What Do You Mean', "Can't Stop The Feeling", 'Shout Out To My Ex'];
foreach ($assignments as $assignment) {
    foreach ($checkNames as $checkName) {
        if (stripos($assignment['name'], $checkName) !== false) {
            printf("VERIFICA: %s => %s | %s | %.1f%%\n", $assignment['name'], $assignment['track']['spotify_id'], $assignment['track']['title'], $assignment['score']);
        }
    }
}

if (!$apply) {
    exit(count($unmatched) > 2 ? 2 : 0);
}

$timestamp = date('Ymd_His');
$slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', basename($folder)), '-');
$backupPath = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'kr-dj-desk_before_sortlee_' . ($slug !== '' ? strtolower($slug) : 'folder') . '_' . $timestamp . '.sql';
$dump = dirname(dirname(APP_ROOT)) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe';
$command = escapeshellarg($dump) . ' -u root --single-transaction --routines --triggers ' . escapeshellarg(DB_NAME) . ' > ' . escapeshellarg($backupPath);
exec($command, $backupOutput, $backupStatus);
if ($backupStatus !== 0 || !is_file($backupPath)) throw new RuntimeException('Backup MariaDB non riuscito.');

$update = $pdo->prepare("UPDATE tracks SET bpm=:bpm, musical_key=:musical_key, camelot=:camelot, genre=:genre, year=:year, release_date=:release_date, popularity=:popularity, spotify_id=:spotify_id, spotify_url=:spotify_url, metadata_source='sortlee', metadata_updated_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE file_path=:file_path");
$pdo->beginTransaction();
$updatedRows = 0;
foreach ($assignments as $assignment) {
    $track = $assignment['track'];
    $releaseDate = ($track['release_date'] ?? '-') === '-' ? '' : (string) ($track['release_date'] ?? '');
    $genre = ($track['genre'] ?? '-') === '-' ? '' : (string) ($track['genre'] ?? '');
    $bpm = is_numeric($track['bpm'] ?? null) ? (float) $track['bpm'] : null;
    $popularity = is_numeric($track['popularity'] ?? null) ? (int) $track['popularity'] : null;
    $update->execute([
        ':bpm' => $bpm,
        ':musical_key' => musicalKey((string) ($track['key'] ?? '')),
        ':camelot' => camelotFromCircle((string) ($track['camelot'] ?? '')),
        ':genre' => $genre,
        ':year' => $releaseDate !== '' ? (int) substr($releaseDate, 0, 4) : null,
        ':release_date' => $releaseDate,
        ':popularity' => $popularity,
        ':spotify_id' => $track['spotify_id'],
        ':spotify_url' => $track['spotify_url'],
        ':file_path' => $assignment['path'],
    ]);
    $updatedRows += $update->rowCount();
}
$pdo->commit();

$genreCount = count(array_filter($assignments, static fn(array $assignment): bool => !in_array(($assignment['track']['genre'] ?? ''), ['', '-'], true)));
printf("Aggiornamento completato: %d file, %d righe database.\nGeneri valorizzati: %d file.\nBackup: %s\n", count($assignments), $updatedRows, $genreCount, $backupPath);
