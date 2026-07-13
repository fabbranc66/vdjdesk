<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/SessionTrackService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Questo tool va eseguito da CLI.\n");
    exit(1);
}

$options = getopt('', ['id::', 'name::', 'date::', 'venue::', 'notes::', 'output::']);
$session = [
    'id' => (string)($options['id'] ?? 'kr-' . date('Y-m-d') . '-sessione'),
    'name' => (string)($options['name'] ?? 'KR Session'),
    'event_date' => (string)($options['date'] ?? date('Y-m-d')),
    'venue' => (string)($options['venue'] ?? ''),
    'notes' => (string)($options['notes'] ?? ''),
];
$output = (string)($options['output'] ?? APP_ROOT . '/storage/exports/krdesk_session_tracks.json');

try {
    $result = (new SessionTrackService(db()))->export($session, $output);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
