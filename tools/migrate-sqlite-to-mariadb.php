<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$source = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$target = db();
$target->exec('SET FOREIGN_KEY_CHECKS=0');

$tables = [
    'e_duplicate_group_items','e_duplicate_groups','e_file_inventory','e_duplicate_scans',
    'track_sources','history','requests','queue','deletion_candidates','duplicate_decisions',
    'library_databases','settings','tracks',
];
foreach ($tables as $table) $target->exec("TRUNCATE TABLE `$table`");

function targetColumns(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $statement->execute([DB_NAME, $table]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function insertRow(PDO $pdo, string $table, array $row, bool $ignore = false): void
{
    if (!$row) return;
    $columns = array_keys($row);
    $quoted = implode(',', array_map(static fn(string $column): string => "`$column`", $columns));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $verb = $ignore ? 'INSERT IGNORE' : 'INSERT';
    $statement = $pdo->prepare("$verb INTO `$table` ($quoted) VALUES ($placeholders)");
    $statement->execute(array_values($row));
}

$trackColumns = array_values(array_filter(targetColumns($target, 'tracks'), static fn(string $column): bool => $column !== 'id'));
$order = "CASE WHEN metadata_source='sortlee' THEN 0 ELSE 1 END, file_exists DESC, updated_at DESC, id DESC";
$tracks = $source->query("SELECT * FROM tracks ORDER BY $order");
$trackIds = [];
$findTrack = $target->prepare('SELECT id FROM tracks WHERE file_path=? LIMIT 1');
$inserted = 0;
while ($row = $tracks->fetch()) {
    $oldId = (int) $row['id'];
    $data = array_intersect_key($row, array_flip($trackColumns));
    $data['file_exists'] = is_file((string) $row['file_path']) ? 1 : 0;
    insertRow($target, 'tracks', $data, true);
    $findTrack->execute([$row['file_path']]);
    $newId = (int) $findTrack->fetchColumn();
    if ($newId <= 0) throw new RuntimeException('Brano non importato: ' . $row['file_path']);
    $trackIds[$oldId] = $newId;
    if ($target->lastInsertId() !== '0') $inserted++;
}

foreach (['settings','duplicate_decisions','library_databases','deletion_candidates'] as $table) {
    $allowed = array_flip(targetColumns($target, $table));
    foreach ($source->query("SELECT * FROM `$table`") as $row) insertRow($target, $table, array_intersect_key($row, $allowed), true);
}

foreach (['history','requests','queue'] as $table) {
    $allowed = array_flip(targetColumns($target, $table));
    foreach ($source->query("SELECT * FROM `$table`") as $row) {
        if ($row['track_id'] !== null) $row['track_id'] = $trackIds[(int) $row['track_id']] ?? null;
        if ($table !== 'requests' && empty($row['track_id'])) continue;
        insertRow($target, $table, array_intersect_key($row, $allowed), true);
    }
}

$allowed = array_flip(targetColumns($target, 'track_sources'));
foreach ($source->query('SELECT * FROM track_sources') as $row) {
    $row['track_id'] = $trackIds[(int) $row['track_id']] ?? 0;
    if (!$row['track_id']) continue;
    insertRow($target, 'track_sources', array_intersect_key($row, $allowed), true);
}

foreach (['e_duplicate_scans','e_file_inventory','e_duplicate_groups','e_duplicate_group_items'] as $table) {
    $allowed = array_flip(targetColumns($target, $table));
    foreach ($source->query("SELECT * FROM `$table`") as $row) insertRow($target, $table, array_intersect_key($row, $allowed), true);
}

$target->exec('SET FOREIGN_KEY_CHECKS=1');
$total = (int) $target->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
$duplicatePaths = (int) $target->query('SELECT COUNT(*) FROM (SELECT 1 FROM tracks GROUP BY file_path HAVING COUNT(*)>1) duplicates')->fetchColumn();
printf("Importati: %d\nTotale MariaDB: %d\nPercorsi duplicati: %d\n", $inserted, $total, $duplicatePaths);
