<?php
declare(strict_types=1);

final class LibraryStandardService
{
    private string $schemaPath;
    private string $mappingPath;

    public function __construct()
    {
        $this->schemaPath = APP_ROOT . '/standards/DJ_LIBRARY_SCHEMA.json';
        $this->mappingPath = APP_ROOT . '/standards/GENRE_MAPPING_SPOTIFY_TO_DJ.csv';
    }

    public function validate(): array
    {
        $schema = $this->schema();
        $mapping = $this->mappingRows();
        $allowedMacro = $schema['fields']['macro_area']['allowed'] ?? [];
        $allowedGenres = $schema['fields']['main_genre']['allowed'] ?? [];
        $errors = [];

        foreach ($mapping as $index => $row) {
            if (!in_array($row['macro_area'], $allowedMacro, true)) {
                $errors[] = 'Mapping riga ' . ($index + 2) . ': macro area non valida: ' . $row['macro_area'];
            }
            if (!in_array($row['main_genre'], $allowedGenres, true)) {
                $errors[] = 'Mapping riga ' . ($index + 2) . ': genere non valido: ' . $row['main_genre'];
            }
        }

        return [
            'ok' => $errors === [],
            'schema_version' => (string)($schema['version'] ?? 'unknown'),
            'fields' => count($schema['fields'] ?? []),
            'mapping_rules' => count($mapping),
            'errors' => $errors,
        ];
    }

    public function test(array $input): array
    {
        $genres = $this->parseGenres((string)($input['genres'] ?? $input['spotify_genres'] ?? ''));
        $releaseDate = trim((string)($input['release_date'] ?? ''));
        $popularity = $this->nullableInt($input['popularity'] ?? null);
        $bpm = $this->nullableFloat($input['bpm'] ?? null);
        $mapping = $this->mapGenres($genres);

        return [
            'ok' => true,
            'schema_version' => (string)($this->schema()['version'] ?? 'unknown'),
            'input' => [
                'spotify_genres' => $genres,
                'release_date' => $releaseDate,
                'popularity' => $popularity,
                'bpm' => $bpm,
            ],
            'classification' => [
                'macro_area' => $mapping['macro_area'],
                'main_genre' => $mapping['main_genre'],
                'mapping_rule' => $mapping['rule'],
                'mapping_confidence' => $mapping['confidence'],
                'popularity_class' => $this->popularityClass($popularity),
                'era' => $this->era($releaseDate),
                'bpm_class' => $this->bpmClass($bpm),
                'found' => false,
                'match_confidence' => $mapping['confidence'],
                'notes' => $mapping['notes'],
            ],
        ];
    }

    private function schema(): array
    {
        $raw = file_get_contents($this->schemaPath);
        if ($raw === false) throw new RuntimeException('Schema libreria non trovato.');
        $schema = json_decode($raw, true);
        if (!is_array($schema)) throw new RuntimeException('Schema libreria non valido: ' . json_last_error_msg());
        return $schema;
    }

    private function mappingRows(): array
    {
        $handle = fopen($this->mappingPath, 'rb');
        if (!$handle) throw new RuntimeException('Mapping generi non leggibile.');
        $headers = fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn($value) => trim((string)$value) !== '')) === 0) continue;
            $rows[] = array_combine($headers, array_pad($row, count($headers), ''));
        }
        fclose($handle);
        return $rows;
    }

    private function mapGenres(array $genres): array
    {
        $haystack = mb_strtolower(implode(' | ', $genres));
        $best = null;
        foreach ($this->mappingRows() as $row) {
            $needle = mb_strtolower(trim((string)$row['spotify_contains']));
            if ($needle === '' || !str_contains($haystack, $needle)) continue;
            if ($best === null || (int)$row['priority'] > (int)$best['priority']) $best = $row;
        }
        if ($best === null) {
            return [
                'macro_area' => 'Da classificare',
                'main_genre' => 'Da classificare',
                'rule' => '',
                'confidence' => 'low',
                'notes' => $genres ? 'Genere Spotify non mappato: ' . implode(', ', $genres) : 'Nessun genere Spotify indicato.',
            ];
        }
        return [
            'macro_area' => (string)$best['macro_area'],
            'main_genre' => (string)$best['main_genre'],
            'rule' => (string)$best['spotify_contains'],
            'confidence' => (int)$best['priority'] >= 90 ? 'high' : 'medium',
            'notes' => (string)$best['notes'],
        ];
    }

    private function parseGenres(string $raw): array
    {
        $items = preg_split('/[,;|]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn($item) => $item !== ''));
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }

    private function popularityClass(?int $popularity): string
    {
        if ($popularity === null) return 'Non disponibile';
        if ($popularity <= 30) return 'Nicchia / poco riconosciuto';
        if ($popularity <= 50) return 'Medio';
        if ($popularity <= 70) return 'Conosciuto';
        if ($popularity <= 85) return 'Hit forte';
        return 'Super hit';
    }

    private function era(string $releaseDate): string
    {
        if (!preg_match('/^\d{4}/', $releaseDate, $match)) return 'Non disponibile';
        $year = (int)$match[0];
        if ($year >= 1990 && $year <= 1999) return '90s';
        if ($year >= 2000 && $year <= 2003) return 'Early 2000';
        if ($year >= 2004 && $year <= 2006) return 'Mid 2000';
        if ($year >= 2007 && $year <= 2009) return 'Late 2000';
        if ($year >= 2010 && $year <= 2015) return '2010s';
        if ($year >= 2016 && $year <= 2019) return 'Late 2010s';
        if ($year >= 2020) return 'Current / 2020s';
        return 'Pre 90s';
    }

    private function bpmClass(?float $bpm): string
    {
        if ($bpm === null) return 'Non disponibile';
        if ($bpm < 80) return 'Slow';
        if ($bpm <= 100) return 'Groove';
        if ($bpm <= 115) return 'Mid';
        if ($bpm <= 128) return 'Danceable';
        if ($bpm <= 135) return 'Club';
        return 'Fast';
    }
}
