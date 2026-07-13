<?php
declare(strict_types=1);

final class InboxService
{
    private const ROOT = 'E:\\LIBRERIA_DEFINITIVA\\01_INBOX';

    public function __construct(private PDO $pdo) {}

    public function status(int $limit = 200): array
    {
        $items = [];
        $rows = $this->rows(max(1, min(500, $limit)));
        foreach ($rows as $row) {
            $items[] = $this->decorate($row);
        }
        return [
            'root' => self::ROOT,
            'summary' => $this->summary(),
            'items' => $items,
            'limit' => max(1, min(500, $limit)),
        ];
    }

    private function rows(int $limit): array
    {
        $statement = $this->pdo->query("
            SELECT id,artist,title,normalized_artist,normalized_title,file_path,folder,genre,genre_manual,year,bpm,musical_key,camelot,tags,auto_tags,version,
                   rating,play_count,spotify_id,spotify_features_status,spotify_features_updated_at,spotify_features_error,metadata_source,dj_scores_manual,
                   energy,singability,danceability,familiarity,risk,updated_at
            FROM tracks
            WHERE UPPER(file_path) LIKE 'E:\\\\\\\\LIBRERIA_DEFINITIVA\\\\\\\\01_INBOX\\\\\\\\%'
               OR UPPER(file_path) LIKE 'E:\\\\\\\\LIBRERIA_DEFINITIVA\\\\\\\\%'
            ORDER BY
                CASE WHEN UPPER(file_path) LIKE 'E:\\\\\\\\LIBRERIA_DEFINITIVA\\\\\\\\01_INBOX\\\\\\\\%' THEN 0 ELSE 1 END,
                updated_at DESC,
                artist,
                title
            LIMIT {$limit}
        ");
        return $statement->fetchAll();
    }

    private function summary(): array
    {
        $summary = array_fill_keys($this->states(), 0);
        $statement = $this->pdo->query("
            SELECT id,artist,title,normalized_artist,normalized_title,file_path,folder,genre,genre_manual,year,bpm,musical_key,camelot,tags,auto_tags,version,
                   rating,play_count,spotify_id,spotify_features_status,spotify_features_updated_at,spotify_features_error,metadata_source,dj_scores_manual,
                   energy,singability,danceability,familiarity,risk,updated_at
            FROM tracks
            WHERE UPPER(file_path) LIKE 'E:\\\\\\\\LIBRERIA_DEFINITIVA\\\\\\\\01_INBOX\\\\\\\\%'
        ");
        foreach ($statement->fetchAll() as $row) {
            $summary[$this->state($row)]++;
        }
        return $summary;
    }

    private function decorate(array $row): array
    {
        $state = $this->state($row);
        return [
            'id' => (int)$row['id'],
            'artist' => (string)$row['artist'],
            'title' => (string)$row['title'],
            'file_path' => (string)$row['file_path'],
            'folder' => (string)$row['folder'],
            'genre' => (string)$row['genre'],
            'state' => $state,
            'metrics_completeness' => $this->metricsCompleteness($row),
            'match_confidence' => $this->matchConfidence($row),
            'genre_source' => $this->genreSource($row),
            'classification_consistency' => $this->classificationConsistency($row),
            'suggested_action' => $this->suggestedAction($state),
            'spotify_features_status' => (string)$row['spotify_features_status'],
            'metadata_source' => (string)$row['metadata_source'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    private function state(array $row): string
    {
        $path = strtoupper((string)$row['file_path']);
        if (str_contains($path, '\\01_INBOX\\DA_CANCELLARE\\')) return 'DA_CANCELLARE';
        if (str_contains($path, '\\01_INBOX\\SOSTITUZIONI\\')) return 'DA_ASCOLTARE';
        if (!str_contains($path, '\\01_INBOX\\') && str_starts_with($path, 'E:\\LIBRERIA_DEFINITIVA\\')) return 'ARCHIVIATO';
        if (trim((string)$row['artist']) === '' || trim((string)$row['title']) === '') return 'DA_CATALOGARE';
        if ((string)$row['spotify_features_status'] === 'error' || $this->matchConfidence($row) < 40) return 'CONFLITTO';
        if (trim((string)$row['genre']) === '' || $this->classificationConsistency($row) < 45) return 'DA_RICLASSIFICARE';
        if ($this->metricsCompleteness($row) < 70) return 'DUBBIO';
        if (((int)$row['dj_scores_manual'] === 1 || (int)$row['genre_manual'] === 1) && $this->metricsCompleteness($row) >= 85 && $this->classificationConsistency($row) >= 70) return 'PRONTO_ARCHIVIAZIONE';
        if ((int)$row['dj_scores_manual'] === 1 || (int)$row['genre_manual'] === 1) return 'CATALOGATO_CONFERMATO';
        if ($this->metricsCompleteness($row) >= 85 && $this->classificationConsistency($row) >= 70) return 'CATALOGATO_DA_CONFERMARE';
        return 'DA_ASCOLTARE';
    }

    private function metricsCompleteness(array $row): int
    {
        $checks = [
            trim((string)$row['spotify_id']) !== '',
            in_array((string)$row['spotify_features_status'], ['complete', 'partial'], true) || $row['spotify_features_updated_at'] !== null,
            (float)($row['bpm'] ?? 0) > 0,
            trim((string)$row['musical_key']) !== '' || trim((string)$row['camelot']) !== '',
            trim((string)$row['genre']) !== '',
            (int)($row['year'] ?? 0) > 0,
        ];
        return (int)round((count(array_filter($checks)) / count($checks)) * 100);
    }

    private function matchConfidence(array $row): int
    {
        $score = 30;
        if (trim((string)$row['spotify_id']) !== '') $score += 35;
        if (trim((string)$row['metadata_source']) !== '') $score += 15;
        if (trim((string)$row['normalized_artist']) !== '' && trim((string)$row['normalized_title']) !== '') $score += 15;
        if ((string)$row['spotify_features_status'] === 'error') $score -= 30;
        return max(0, min(100, $score));
    }

    private function genreSource(array $row): string
    {
        if (trim((string)$row['genre']) === '') return 'mancante';
        if ((int)$row['genre_manual'] === 1) return 'manuale';
        return match ((string)$row['metadata_source']) {
            'sortlee' => 'sortlee',
            'spotify', 'spotify_json' => 'spotify',
            'virtualdj' => 'virtualdj',
            default => (string)$row['metadata_source'] !== '' ? (string)$row['metadata_source'] : 'non_confermata',
        };
    }

    private function classificationConsistency(array $row): int
    {
        $score = 40;
        if ((int)$row['dj_scores_manual'] === 1) $score += 20;
        if (trim((string)$row['genre']) !== '') $score += 15;
        if (count(trackTags($row)) > 0 || count(autoTrackTags($row)) > 0) $score += 10;
        if ((int)$row['energy'] >= 1 && (int)$row['danceability'] >= 1 && (int)$row['risk'] >= 1) $score += 10;
        if ((int)$row['risk'] >= 4 && (int)$row['familiarity'] <= 2) $score -= 15;
        return max(0, min(100, $score));
    }

    private function suggestedAction(string $state): string
    {
        return match ($state) {
            'DA_CATALOGARE' => 'Compila metadati base e cerca Spotify ID',
            'CATALOGATO_DA_CONFERMARE' => 'Conferma genere, tag e punteggi',
            'CATALOGATO_CONFERMATO' => 'Valuta proposta archiviazione',
            'DA_ASCOLTARE' => 'Aggiungi a lista ascolto',
            'DUBBIO' => 'Completa metriche mancanti',
            'CONFLITTO' => 'Risolvi match o errore manualmente',
            'DA_RICLASSIFICARE' => 'Correggi genere, tag o punteggi',
            'DA_CANCELLARE' => 'Verifica prima della cancellazione finale',
            'PRONTO_ARCHIVIAZIONE' => 'Proponi destinazione, senza spostare',
            'ARCHIVIATO', 'SCARTATO' => 'Nessuna azione automatica',
            default => 'Revisione manuale',
        };
    }

    private function states(): array
    {
        return ['DA_CATALOGARE','CATALOGATO_DA_CONFERMARE','CATALOGATO_CONFERMATO','DA_ASCOLTARE','DUBBIO','CONFLITTO','DA_RICLASSIFICARE','DA_CANCELLARE','PRONTO_ARCHIVIAZIONE','ARCHIVIATO','SCARTATO'];
    }
}
