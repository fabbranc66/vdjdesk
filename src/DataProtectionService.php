<?php
declare(strict_types=1);

final class DataProtectionService
{
    private const SNAPSHOT_PREFIX = 'tracks_guard_';

    public function __construct(private PDO $pdo) {}

    public function snapshot(string $reason): array
    {
        $safeReason = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($reason)) ?: 'manual';
        $safeReason = trim(substr($safeReason, 0, 36), '_') ?: 'manual';
        $table = self::SNAPSHOT_PREFIX . date('Ymd_His') . '_' . $safeReason;
        $this->pdo->exec("CREATE TABLE `$table` AS SELECT * FROM tracks");
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $this->pdo->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')
            ->execute(['last_tracks_snapshot', $table]);
        return ['table' => $table, 'tracks' => $count, 'reason' => $reason];
    }

    public function renamePathPrefix(string $oldRoot, string $newRoot, string $reason = 'path_rename'): array
    {
        $oldRoot = canonicalPath($oldRoot);
        $newRoot = canonicalPath($newRoot);
        if ($oldRoot === '' || $newRoot === '' || strcasecmp($oldRoot, $newRoot) === 0) {
            throw new RuntimeException('Root sorgente/destinazione non valida.');
        }
        $snapshot = $this->snapshot($reason);
        $rows = $this->pdo->query('SELECT id,file_path FROM tracks')->fetchAll();
        $update = $this->pdo->prepare('UPDATE tracks SET file_path=?,file_name=?,folder=?,archive_area=?,macro_genre=?,folder_genre=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $updated = 0;
        foreach ($rows as $row) {
            $path = canonicalPath((string)$row['file_path']);
            if (!str_starts_with(strtoupper($path), strtoupper($oldRoot . '\\'))) continue;
            $newPath = $newRoot . substr($path, strlen($oldRoot));
            $taxonomy = trackTaxonomyFromPath($newPath);
            $update->execute([$newPath, basename($newPath), dirname($newPath), $taxonomy['archive_area'], $taxonomy['macro_genre'], $taxonomy['folder_genre'], (int)$row['id']]);
            $updated += $update->rowCount();
        }
        return ['snapshot' => $snapshot, 'updated' => $updated, 'old_root' => $oldRoot, 'new_root' => $newRoot];
    }

    public function guardTrackLoss(int $beforeTracks, int $afterTracks, string $operation, int $allowedLoss = 0): void
    {
        $loss = $beforeTracks - $afterTracks;
        if ($loss > $allowedLoss) {
            throw new RuntimeException("Protezione dati: operazione {$operation} bloccata, perdita record {$loss} > {$allowedLoss}.");
        }
    }

    public function guardMetadataLoss(array $before, array $after, string $operation): void
    {
        foreach (['spotify_id', 'spotify_features', 'kr_scores', 'genre_year'] as $key) {
            $lost = (int)($before[$key] ?? 0) - (int)($after[$key] ?? 0);
            if ($lost > 0) {
                throw new RuntimeException("Protezione dati: operazione {$operation} bloccata, perdita {$key}: {$lost}.");
            }
        }
    }

    public function metricsSnapshot(string $condition = '1=1'): array
    {
        return [
            'tracks' => (int)$this->pdo->query("SELECT COUNT(*) FROM tracks WHERE {$condition}")->fetchColumn(),
            'spotify_id' => (int)$this->pdo->query("SELECT COUNT(*) FROM tracks WHERE {$condition} AND TRIM(COALESCE(spotify_id,''))<>''")->fetchColumn(),
            'spotify_features' => (int)$this->pdo->query("SELECT COUNT(*) FROM tracks WHERE {$condition} AND spotify_energy IS NOT NULL AND spotify_danceability IS NOT NULL AND spotify_valence IS NOT NULL")->fetchColumn(),
            'kr_scores' => (int)$this->pdo->query("SELECT COUNT(*) FROM tracks WHERE {$condition} AND kr_energy IS NOT NULL AND kr_singability IS NOT NULL AND kr_floor_power IS NOT NULL AND kr_familiarity IS NOT NULL AND kr_risk IS NOT NULL AND kr_peak IS NOT NULL AND kr_recovery IS NOT NULL")->fetchColumn(),
            'genre_year' => (int)$this->pdo->query("SELECT COUNT(*) FROM tracks WHERE {$condition} AND TRIM(COALESCE(genre,''))<>'' AND year IS NOT NULL AND year<>0")->fetchColumn(),
        ];
    }

    public function completePhysicalTrackData(string $condition): array
    {
        $snapshot = $this->snapshot('before_complete_physical_data');
        $updated = 0;
        $updated += $this->mergeByPath();
        $updated += $this->mergeByFingerprint($condition);
        return ['snapshot' => $snapshot, 'updated' => $updated, 'after' => $this->metricsSnapshot($condition)];
    }

    private function mergeByPath(): int
    {
        $sql = $this->metadataUpdateSql('old.file_path = cur.file_path AND old.file_exists=0');
        return (int)$this->pdo->exec($sql);
    }

    private function mergeByFingerprint(string $condition): int
    {
        $aliasedCondition = str_replace(['file_exists', 'file_path'], ['cur.file_exists', 'cur.file_path'], $condition);
        $sql = $this->metadataUpdateSql("
            old.id <> cur.id
            AND old.normalized_artist = cur.normalized_artist
            AND old.normalized_title = cur.normalized_title
            AND old.normalized_artist <> ''
            AND old.normalized_title <> ''
            AND old.file_exists=0
            AND (cur.duration IS NULL OR old.duration IS NULL OR ABS(COALESCE(cur.duration,0) - COALESCE(old.duration,0)) <= 3)
        ", $aliasedCondition);
        return (int)$this->pdo->exec($sql);
    }

    private function metadataUpdateSql(string $joinCondition, string $currentCondition = '1=1'): string
    {
        return "
            UPDATE tracks cur
            JOIN tracks old ON {$joinCondition}
            SET
                cur.spotify_id = IF(TRIM(COALESCE(cur.spotify_id,''))='' AND TRIM(COALESCE(old.spotify_id,''))<>'', old.spotify_id, cur.spotify_id),
                cur.spotify_url = IF(TRIM(COALESCE(cur.spotify_url,''))='' AND TRIM(COALESCE(old.spotify_url,''))<>'', old.spotify_url, cur.spotify_url),
                cur.isrc = IF(TRIM(COALESCE(cur.isrc,''))='' AND TRIM(COALESCE(old.isrc,''))<>'', old.isrc, cur.isrc),
                cur.album = IF(TRIM(COALESCE(cur.album,''))='' AND TRIM(COALESCE(old.album,''))<>'', old.album, cur.album),
                cur.release_date = IF(TRIM(COALESCE(cur.release_date,''))='' AND TRIM(COALESCE(old.release_date,''))<>'', old.release_date, cur.release_date),
                cur.popularity = IF(cur.popularity IS NULL AND old.popularity IS NOT NULL, old.popularity, cur.popularity),
                cur.metadata_source = IF(TRIM(COALESCE(cur.metadata_source,''))='' AND TRIM(COALESCE(old.metadata_source,''))<>'', old.metadata_source, cur.metadata_source),
                cur.metadata_updated_at = IF(cur.metadata_updated_at IS NULL AND old.metadata_updated_at IS NOT NULL, old.metadata_updated_at, cur.metadata_updated_at),
                cur.spotify_energy = IF(cur.spotify_energy IS NULL AND old.spotify_energy IS NOT NULL, old.spotify_energy, cur.spotify_energy),
                cur.spotify_danceability = IF(cur.spotify_danceability IS NULL AND old.spotify_danceability IS NOT NULL, old.spotify_danceability, cur.spotify_danceability),
                cur.spotify_valence = IF(cur.spotify_valence IS NULL AND old.spotify_valence IS NOT NULL, old.spotify_valence, cur.spotify_valence),
                cur.spotify_acousticness = IF(cur.spotify_acousticness IS NULL AND old.spotify_acousticness IS NOT NULL, old.spotify_acousticness, cur.spotify_acousticness),
                cur.spotify_instrumentalness = IF(cur.spotify_instrumentalness IS NULL AND old.spotify_instrumentalness IS NOT NULL, old.spotify_instrumentalness, cur.spotify_instrumentalness),
                cur.spotify_speechiness = IF(cur.spotify_speechiness IS NULL AND old.spotify_speechiness IS NOT NULL, old.spotify_speechiness, cur.spotify_speechiness),
                cur.spotify_liveness = IF(cur.spotify_liveness IS NULL AND old.spotify_liveness IS NOT NULL, old.spotify_liveness, cur.spotify_liveness),
                cur.spotify_loudness = IF(cur.spotify_loudness IS NULL AND old.spotify_loudness IS NOT NULL, old.spotify_loudness, cur.spotify_loudness),
                cur.spotify_tempo = IF(cur.spotify_tempo IS NULL AND old.spotify_tempo IS NOT NULL, old.spotify_tempo, cur.spotify_tempo),
                cur.spotify_key = IF(cur.spotify_key IS NULL AND old.spotify_key IS NOT NULL, old.spotify_key, cur.spotify_key),
                cur.spotify_mode = IF(cur.spotify_mode IS NULL AND old.spotify_mode IS NOT NULL, old.spotify_mode, cur.spotify_mode),
                cur.spotify_features_updated_at = IF(cur.spotify_features_updated_at IS NULL AND old.spotify_features_updated_at IS NOT NULL, old.spotify_features_updated_at, cur.spotify_features_updated_at),
                cur.spotify_features_status = IF((cur.spotify_features_status='' OR cur.spotify_features_status='never') AND old.spotify_features_status IN ('complete','partial'), old.spotify_features_status, cur.spotify_features_status),
                cur.spotify_features_error = IF(TRIM(COALESCE(cur.spotify_features_error,''))='' AND TRIM(COALESCE(old.spotify_features_error,''))<>'', old.spotify_features_error, cur.spotify_features_error),
                cur.spotify_genre = IF(TRIM(COALESCE(cur.spotify_genre,''))='' AND TRIM(COALESCE(old.spotify_genre,''))<>'', old.spotify_genre, cur.spotify_genre),
                cur.kr_energy = IF(cur.kr_energy IS NULL AND old.kr_energy IS NOT NULL, old.kr_energy, cur.kr_energy),
                cur.kr_singability = IF(cur.kr_singability IS NULL AND old.kr_singability IS NOT NULL, old.kr_singability, cur.kr_singability),
                cur.kr_floor_power = IF(cur.kr_floor_power IS NULL AND old.kr_floor_power IS NOT NULL, old.kr_floor_power, cur.kr_floor_power),
                cur.kr_familiarity = IF(cur.kr_familiarity IS NULL AND old.kr_familiarity IS NOT NULL, old.kr_familiarity, cur.kr_familiarity),
                cur.kr_risk = IF(cur.kr_risk IS NULL AND old.kr_risk IS NOT NULL, old.kr_risk, cur.kr_risk),
                cur.kr_peak = IF(cur.kr_peak IS NULL AND old.kr_peak IS NOT NULL, old.kr_peak, cur.kr_peak),
                cur.kr_recovery = IF(cur.kr_recovery IS NULL AND old.kr_recovery IS NOT NULL, old.kr_recovery, cur.kr_recovery),
                cur.auto_tags = IF((cur.auto_tags IS NULL OR cur.auto_tags='' OR cur.auto_tags='[]') AND old.auto_tags IS NOT NULL AND old.auto_tags<>'' AND old.auto_tags<>'[]', old.auto_tags, cur.auto_tags),
                cur.genre = IF(TRIM(COALESCE(cur.genre,''))='' AND TRIM(COALESCE(old.genre,''))<>'', old.genre, cur.genre),
                cur.year = IF((cur.year IS NULL OR cur.year=0) AND old.year IS NOT NULL AND old.year<>0, old.year, cur.year),
                cur.updated_at = CURRENT_TIMESTAMP
            WHERE cur.file_exists=1
              AND {$currentCondition}
              AND old.id <> cur.id
              AND (
                (TRIM(COALESCE(cur.spotify_id,''))='' AND TRIM(COALESCE(old.spotify_id,''))<>'')
                OR (cur.spotify_energy IS NULL AND old.spotify_energy IS NOT NULL)
                OR (cur.kr_energy IS NULL AND old.kr_energy IS NOT NULL)
                OR (TRIM(COALESCE(cur.genre,''))='' AND TRIM(COALESCE(old.genre,''))<>'')
                OR ((cur.year IS NULL OR cur.year=0) AND old.year IS NOT NULL AND old.year<>0)
              )
        ";
    }
}

