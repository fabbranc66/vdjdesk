<?php
declare(strict_types=1);

final class EComparisonService
{
    private const AUDIO_EXTENSIONS = ['mp3','m4a','flac','wav','ogg','opus','aac','mp4','mkv','vob'];

    public function __construct(private PDO $pdo) {}

    public function compare(string $folder): array
    {
        if (!is_dir($folder)) throw new RuntimeException('Cartella da confrontare non trovata.');
        $folder = canonicalPath($folder);
        if (str_starts_with(strtoupper($folder), 'E:\\')) throw new RuntimeException('Per una cartella E: usare il controllo doppioni interno.');
        $this->mergeCaseInsensitiveDuplicates($folder);
        $this->purgeCrossFamilyCandidates();

        $duplicateService = new EDuplicateService($this->pdo);
        $scan = $duplicateService->latestCompleted() ?? $duplicateService->scan();
        $scanId = (int) $scan['id'];
        $metadataQuery = $this->pdo->prepare("SELECT file_path,artist,title FROM tracks WHERE folder=? OR folder LIKE ? ORDER BY CASE WHEN metadata_source='sortlee' THEN 0 ELSE 1 END,updated_at DESC,id DESC");
        $metadataQuery->execute([$folder, $folder . '\\%']);
        $metadataByPath = [];
        foreach ($metadataQuery->fetchAll() as $track) {
            $metadataByPath[strtolower(canonicalPath((string) $track['file_path']))] ??= $track;
        }
        $allInventory = $this->pdo->query(<<<'SQL'
            SELECT id,file_path,file_name,folder,COALESCE(file_size,0) file_size,
                   LOWER(SUBSTRING_INDEX(file_path,'.',-1)) extension,artist,title,normalized_artist,normalized_title,
                   version,bitrate,rating,play_count,genre,
                   IF(spotify_id<>'',1,0) has_spotify,IF(spotify_features_status='complete',1,0) spotify_complete
            FROM tracks
            WHERE LEFT(file_path,2)='E:' AND file_exists=1
        SQL);
        $inventory = array_values(array_filter($allInventory->fetchAll(),fn(array $item): bool=>is_file(canonicalPath((string)$item['file_path']))));
        usort($inventory,fn(array $left,array $right): int=>$duplicateService->recommendationScore($right)<=>$duplicateService->recommendationScore($left));
        $inventoryByPrefix = [];
        $inventoryBySize = [];
        $inventoryByNormalized = [];
        $inventoryByTitle = [];
        foreach ($inventory as $candidate) {
            $family = $this->mediaFamily((string) $candidate['extension']);
            $prefix = substr((string) $candidate['normalized_title'], 0, 4);
            if ($prefix !== '') $inventoryByPrefix[$family . '|' . $prefix][] = $candidate;
            $inventoryBySize[$family . '|' . (int) $candidate['file_size']][] = $candidate;
            $normalizedKey = $family . '|' . $candidate['normalized_artist'] . "\x1f" . $candidate['normalized_title'];
            $inventoryByNormalized[$normalizedKey] ??= $candidate;
            $inventoryByTitle[$family . '|' . (string) $candidate['normalized_title']][] = $candidate;
        }
        $update = $this->pdo->prepare(<<<'SQL'
            UPDATE deletion_candidates
            SET source_path=?,source_folder=?,source_name=?,source_size=?,e_file_path=?,e_file_name=?,e_file_size=?,match_type=?,confidence=?,reason=?,last_seen_at=CURRENT_TIMESTAMP,
                status=CASE WHEN status IN ('marked','approved') THEN status ELSE 'candidate' END
            WHERE source_path = ?
        SQL);
        $insert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO deletion_candidates(source_path,source_folder,source_name,source_size,e_file_path,e_file_name,e_file_size,match_type,confidence,reason,status)
            VALUES(?,?,?,?,?,?,?,?,?,?,'candidate')
        SQL);

        $eHashCache = [];
        $matchedSourcePaths = [];
        $scanned = $exact = $similar = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true)) continue;
            $scanned++;
            $sourcePath = canonicalPath($file->getPathname());
            $family = $this->mediaFamily($file->getExtension());
            $track = $metadataByPath[strtolower($sourcePath)] ?? [];
            [$fallbackArtist,$fallbackTitle] = $this->artistTitleFromFilename($file->getFilename());
            $artist = trim((string)($track['artist'] ?? '')) ?: $fallbackArtist;
            $title = trim((string)($track['title'] ?? '')) ?: $fallbackTitle;
            $artistOptions = array_values(array_unique(array_filter([$artist,$fallbackArtist])));
            $titleOptions = array_values(array_unique(array_filter([$title,$fallbackTitle])));
            $match = null;
            $matchType = '';
            $confidence = 0;
            $reason = '';

            $sizeCandidates = $inventoryBySize[$family . '|' . $file->getSize()] ?? [];
            if ($sizeCandidates) {
                $sourceHash = $this->quickHash($sourcePath, $file->getSize());
                foreach ($sizeCandidates as $candidate) {
                    $candidateHash = $eHashCache[$candidate['file_path']] ??= $this->quickHash($candidate['file_path'], (int) $candidate['file_size']);
                    if ($sourceHash && $sourceHash === $candidateHash) {
                        $match = $candidate;
                        $matchType = 'exact';
                        $confidence = 99;
                        $reason = 'Copia su E: con stessa dimensione e stessa impronta del contenuto.';
                        $exact++;
                        break;
                    }
                }
            }

            if (!$match) {
                foreach ($artistOptions as $artistOption) {
                    foreach ($titleOptions as $titleOption) {
                        $normalizedKey = $family . '|' . normalizeText($artistOption) . "\x1f" . normalizeTitle($titleOption);
                        $match = $inventoryByNormalized[$normalizedKey] ?? null;
                        if ($match) break 2;
                    }
                }
                if ($match) {
                    $matchType = 'normalized';
                    $confidence = 85;
                    $reason = 'Copia su E: con stesso artista e titolo normalizzati; richiede revisione.';
                    $similar++;
                }
            }

            if (!$match) {
                $best = null;
                $bestArtistScore = -1.0;
                foreach ($titleOptions as $titleOption) {
                    foreach ($inventoryByTitle[$family . '|' . normalizeTitle($titleOption)] ?? [] as $candidate) {
                        foreach ($artistOptions as $artistOption) {
                            $score = $this->artistSimilarity($artistOption, (string) $candidate['artist']);
                            if ($score > $bestArtistScore) {
                                $best = $candidate;
                                $bestArtistScore = $score;
                            }
                        }
                    }
                }
                if ($best && $bestArtistScore >= 0.5) {
                    $match = $best;
                    $matchType = 'title_artist';
                    $confidence = 82;
                    $reason = 'Titolo uguale su E: e artista compatibile; richiede revisione.';
                    $similar++;
                }
            }

            if (!$match) {
                $best = null;
                $bestScore = 0.0;
                $candidatePool = [];
                foreach ($titleOptions as $titleOption) {
                    $prefix = substr(normalizeTitle($titleOption), 0, 4);
                    foreach ($inventoryByPrefix[$family . '|' . $prefix] ?? [] as $candidate) $candidatePool[(int) $candidate['id']] = $candidate;
                }
                foreach ($candidatePool as $candidate) {
                    foreach ($titleOptions as $titleOption) {
                        $titleScore = $this->titleSimilarity($titleOption, (string) $candidate['title']);
                        if ($titleScore < 0.88) continue;
                        foreach ($artistOptions as $artistOption) {
                            $artistScore = $this->artistSimilarity($artistOption, (string) $candidate['artist']);
                            if ($artistScore < 0.5) continue;
                            $score = ($titleScore * 0.75) + ($artistScore * 0.25);
                            if ($score > $bestScore) {
                                $best = $candidate;
                                $bestScore = $score;
                            }
                        }
                    }
                }
                if ($best) {
                    $match = $best;
                    $matchType = 'fuzzy_title';
                    $confidence = 78;
                    $reason = 'Titolo quasi uguale e artista compatibile su E:; verificare manualmente.';
                    $similar++;
                }
            }

            if ($match) {
                $matchedSourcePaths[strtolower($sourcePath)] = true;
                $this->saveCandidate($update, $insert, [
                    $sourcePath,
                    $folder,
                    $file->getFilename(),
                    $file->getSize(),
                    canonicalPath((string) $match['file_path']),
                    (string) $match['file_name'],
                    (int) $match['file_size'],
                    $matchType,
                    $confidence,
                    $reason,
                ]);
            }
        }

        $this->removeUnmatchedCandidates($folder, $matchedSourcePaths);

        return [
            'folder' => $folder,
            'scanned' => $scanned,
            'exact' => $exact,
            'normalized' => $similar,
            'marked' => $exact + $similar,
            'items' => $this->candidates($folder),
        ];
    }

    private function removeUnmatchedCandidates(string $folder, array $matchedSourcePaths): void
    {
        $statement = $this->pdo->prepare("SELECT id,source_path FROM deletion_candidates WHERE (source_folder=? OR source_path LIKE ?) AND status IN ('candidate','marked','approved')");
        $statement->execute([$folder, $folder . '\\%']);
        $delete = $this->pdo->prepare('DELETE FROM deletion_candidates WHERE id=?');
        foreach ($statement->fetchAll() as $candidate) {
            if (!isset($matchedSourcePaths[strtolower(canonicalPath((string) $candidate['source_path']))])) {
                $delete->execute([(int) $candidate['id']]);
            }
        }
    }

    private function mediaFamily(string $extension): string
    {
        return 'media';
    }

    private function purgeCrossFamilyCandidates(): void
    {
        return;
    }

    public function candidates(string $folder = '', string $status = ''): array
    {
        $this->reconcileActiveFiles();
        $where = ['1=1'];
        $params = [];
        if ($folder !== '') {
            $folder = canonicalPath($folder);
            $this->mergeCaseInsensitiveDuplicates($folder);
            $where[] = '(source_folder = ? OR source_path LIKE ?)';
            $params[] = $folder;
            $params[] = $folder . '\\%';
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        } elseif ($folder !== '') {
            $where[] = "status IN ('candidate','marked','approved')";
        }
        $statement = $this->pdo->prepare('SELECT * FROM deletion_candidates WHERE ' . implode(' AND ', $where) . ' ORDER BY CASE status WHEN \'marked\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END,confidence DESC,source_name LIMIT 500');
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function decide(int $id, string $status, string $note = ''): void
    {
        if (!in_array($status, ['candidate','marked','ignored','approved','moved','deleted'], true)) throw new RuntimeException('Stato candidato non valido.');
        $statement = $this->pdo->prepare("UPDATE deletion_candidates SET status=?,decision_note=?,last_seen_at=CURRENT_TIMESTAMP,approved_at=CASE WHEN ?='approved' THEN CURRENT_TIMESTAMP ELSE approved_at END WHERE id=?");
        $statement->execute([$status,$note,$status,$id]);
    }

    public function markAll(string $folder): int
    {
        if ($folder === '') throw new RuntimeException('Cartella non selezionata.');
        $root = canonicalPath($folder);
        if (str_starts_with(strtoupper($root), 'E:\\')) throw new RuntimeException('La marcatura massiva è disponibile solo per cartelle esterne a E:.');
        $statement = $this->pdo->prepare("UPDATE deletion_candidates SET status='marked',decision_note='',last_seen_at=CURRENT_TIMESTAMP WHERE source_folder = ? OR source_path LIKE ?");
        $statement->execute([$root, $root . '\\%']);
        return $statement->rowCount();
    }

    public function approveAllMarked(): int
    {
        $statement = $this->pdo->prepare("UPDATE deletion_candidates SET status='approved',approved_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP WHERE status='marked'");
        $statement->execute();
        return $statement->rowCount();
    }

    public function approvedFolderSummary(): array
    {
        $this->reconcileActiveFiles();
        return $this->pdo->query("SELECT source_folder,COUNT(*) total,MIN(approved_at) first_approved_at,MAX(approved_at) last_approved_at FROM deletion_candidates WHERE status='approved' GROUP BY source_folder ORDER BY total DESC,source_folder")->fetchAll();
    }

    private function reconcileActiveFiles(): void
    {
        $rows = $this->pdo->query("SELECT id,source_path,moved_to_path,status FROM deletion_candidates WHERE status IN ('candidate','marked','approved')")->fetchAll();
        $moved = $this->pdo->prepare("UPDATE deletion_candidates SET status='moved',moved_at=COALESCE(moved_at,CURRENT_TIMESTAMP) WHERE id=?");
        $deleted = $this->pdo->prepare("UPDATE deletion_candidates SET status='deleted',decision_note='File sorgente non più presente',last_seen_at=CURRENT_TIMESTAMP WHERE id=?");
        foreach ($rows as $row) {
            if (is_file($row['source_path'])) continue;
            if (!empty($row['moved_to_path']) && is_file($row['moved_to_path'])) {
                $moved->execute([$row['id']]);
                continue;
            }
            $deleted->execute([$row['id']]);
        }
    }

    private function saveCandidate(PDOStatement $update, PDOStatement $insert, array $values): void
    {
        $update->execute([...$values, $values[0]]);
        if ($update->rowCount() > 0) return;
        $insert->execute($values);
    }

    private function mergeCaseInsensitiveDuplicates(string $folder): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT LOWER(source_path) AS path_key, GROUP_CONCAT(id) AS ids
            FROM deletion_candidates
            WHERE source_folder = ? OR source_path LIKE ?
            GROUP BY LOWER(source_path)
            HAVING COUNT(*) > 1
        SQL);
        $statement->execute([$folder, $folder . '\\%']);
        $groups = $statement->fetchAll();
        if (!$groups) return;

        $statusWeight = ['approved'=>5,'marked'=>4,'candidate'=>3,'ignored'=>2,'moved'=>1,'deleted'=>0];
        $delete = $this->pdo->prepare('DELETE FROM deletion_candidates WHERE id=?');
        $update = $this->pdo->prepare('UPDATE deletion_candidates SET source_path=?,source_folder=?,source_name=?,e_file_path=?,e_file_name=? WHERE id=?');

        foreach ($groups as $group) {
            $ids = array_map('intval', array_filter(explode(',', (string) $group['ids'])));
            if (count($ids) < 2) continue;
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->pdo->prepare("SELECT id,source_path,source_folder,e_file_path,status FROM deletion_candidates WHERE id IN ($placeholders)");
            $rows->execute($ids);
            $items = $rows->fetchAll();
            if (count($items) < 2) continue;

            usort(
                $items,
                fn(array $a, array $b) =>
                    (($statusWeight[$b['status']] ?? -1) <=> ($statusWeight[$a['status']] ?? -1))
                    ?: ((int) $b['id'] <=> (int) $a['id'])
            );

            foreach (array_slice($items, 1) as $item) {
                $delete->execute([(int) $item['id']]);
            }

            $keep = $items[0];
            $canonicalSource = canonicalPath((string) $keep['source_path']);
            $canonicalFolder = canonicalPath((string) $keep['source_folder']);
            $canonicalEPath = canonicalPath((string) $keep['e_file_path']);
            $update->execute([$canonicalSource, $canonicalFolder, basename($canonicalSource), $canonicalEPath, basename($canonicalEPath), (int) $keep['id']]);
        }
    }

    private function quickHash(string $path, int $size): ?string
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) return null;
        $chunkSize = 262144;
        $first = fread($handle, $chunkSize) ?: '';
        $last = '';
        if ($size > $chunkSize) {
            fseek($handle, max(0, $size - $chunkSize));
            $last = fread($handle, $chunkSize) ?: '';
        }
        fclose($handle);
        return sha1($size . '|' . $first . '|' . $last);
    }

    private function artistTitleFromFilename(string $fileName): array
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $parts = preg_split('/\s+-\s+/', $name, 2);
        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($name)];
    }

    private function artistSimilarity(string $left, string $right): float
    {
        $noise = ['topic','official','orchestra','orquesta','the','y','su','con'];
        $leftTokens = array_values(array_diff(array_filter(explode(' ', normalizeText($left))), $noise));
        $rightTokens = array_values(array_diff(array_filter(explode(' ', normalizeText($right))), $noise));
        if (!$leftTokens || !$rightTokens) return 0.0;
        return count(array_intersect($leftTokens, $rightTokens)) / min(count($leftTokens), count($rightTokens));
    }

    private function titleSimilarity(string $left, string $right): float
    {
        $left = normalizeTitle($left);
        $right = normalizeTitle($right);
        $length = max(strlen($left), strlen($right));
        if ($length === 0) return 0.0;
        return max(0.0, 1 - (levenshtein($left, $right) / $length));
    }
}
