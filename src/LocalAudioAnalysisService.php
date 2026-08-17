<?php
declare(strict_types=1);

final class LocalAudioAnalysisService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function tracks(string $query = '', int $limit = 120): array
    {
        $limit = max(1, min(300, $limit));
        $where = "file_exists=1 AND " . definitiveMusicSqlCondition();
        $params = [];
        if ($query !== '') {
            $where .= ' AND (artist LIKE ? OR title LIKE ? OR file_name LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like];
        }
        $statement = $this->pdo->prepare(
            "SELECT id,artist,title,file_path,duration,bpm,camelot
             FROM tracks
             WHERE $where
             ORDER BY artist,title
             LIMIT $limit"
        );
        $statement->execute($params);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $stored = $this->readStored((int)$item['id']);
            $item['analysis_status'] = $stored['status'] ?? null;
            $item['analyzed_at'] = $stored['analyzed_at'] ?? null;
        }
        unset($item);
        return ['items' => $items];
    }

    public function statuses(array $trackIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $trackIds), static fn(int $id): bool => $id > 0))), 0, 500);
        $items = [];
        foreach ($ids as $trackId) {
            $stored = $this->readStored($trackId);
            $items[(string)$trackId] = [
                'exists' => $stored !== null,
                'status' => $stored['status'] ?? null,
            ];
        }
        return ['items' => $items];
    }

    public function result(int $trackId): array
    {
        $stored = $this->readStored($trackId);
        $track = $this->trackReference($trackId);
        $vdjCues = $this->virtualDjNamedCues($trackId, (string)($track['file_path'] ?? ''));
        $vdjBpm = $this->virtualDjBpm($trackId, (string)($track['file_path'] ?? ''));
        $vdjGridPhase = $this->virtualDjGridPhase($trackId, (string)($track['file_path'] ?? ''));
        $vdjBeatInterval = $this->virtualDjBeatInterval($trackId, (string)($track['file_path'] ?? ''));
        if (!$stored) {
            return array_merge($track, [
                'track_id' => $trackId,
                'status' => 'not_analyzed',
                'vdj_cues' => $vdjCues,
            ]);
        }
        if ($vdjBpm !== null) {
            $stored['detected_bpm'] = $stored['detected_bpm'] ?? $stored['bpm'] ?? null;
            $stored['bpm'] = $vdjBpm;
            $stored['bpm_source'] = 'virtualdj';
        }
        if ($vdjGridPhase !== null) $stored['vdj_grid_phase_seconds'] = $vdjGridPhase;
        if ($vdjBeatInterval !== null) $stored['vdj_beat_interval_seconds'] = $vdjBeatInterval;
        return array_merge($stored, $track, [
            'vdj_cues' => $vdjCues,
        ]);
    }

    public function exportCuesToVirtualDj(int $trackId): array
    {
        $stored=$this->readStored($trackId);
        if(!$stored)throw new RuntimeException('Analisi audio non disponibile.');
        $colors=[];
        foreach((array)($stored['stem_waveforms']??[]) as $stem){
            if(is_array($stem))$colors[(string)($stem['key']??'')]=(string)($stem['color']??'#FFFFFF');
        }
        $cues=[];$labelCounts=[];
        foreach((array)($stored['layer_sections']??[]) as $layer=>$sections){
            foreach(array_slice(array_values((array)$sections),1) as $section){
                if(!is_array($section))continue;
                $label=strtoupper(trim((string)($section['label']??'CUE')));
                $labelCounts[$label]=($labelCounts[$label]??0)+1;
                $cues[]=['time'=>(float)($section['start']??0),'name'=>$label.' '.$labelCounts[$label],'color'=>$colors[(string)$layer]??'#FFFFFF'];
            }
        }
        usort($cues,static fn(array $left,array $right): int=>((float)$left['time'])<=>((float)$right['time']));
        return (new VirtualDjControlService($this->pdo))->replaceTrackCues($trackId,$cues);
    }

    public function autoDetectCues(int $trackId): array
    {
        $stored=$this->readStored($trackId);
        if(!$stored)throw new RuntimeException('Analisi audio non disponibile.');
        $track=$this->trackReference($trackId);
        $macroGenre=trim((string)($track['macro_genre']??''));
        if(strcasecmp($macroGenre,'Commerciale')!==0)throw new RuntimeException('Regole automatiche disponibili solo per il macrogenere Commerciale.');
        $process=proc_open(['python',APP_ROOT.'/tools/audio_cue_detector.py',$this->storagePath($trackId),APP_ROOT.'/tools/audio_analysis_rules.json','Commerciale',(string)((int)($track['year']??0))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT);
        if(!is_resource($process))throw new RuntimeException('Avvio riconoscimento automatico non riuscito.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exitCode=proc_close($process);
        $payload=json_decode(trim((string)$stdout),true);
        if($exitCode!==0||!is_array($payload)||empty($payload['ok']))throw new RuntimeException(trim((string)($payload['error']??$stderr))?:'Riconoscimento automatico non riuscito.');
        foreach((array)($payload['layer_sections']??[]) as $layer=>$sections)$stored['layer_sections'][(string)$layer]=array_values((array)$sections);
        $stored['automatic_cues_at']=date(DATE_ATOM);
        $stored['automatic_cues_macro_genre']='Commerciale';
        $stored['automatic_cues_profile']=$payload['profile']??'commerciale_standard';
        $stored['automatic_cue_events']=$payload['events']??[];
        $this->writeStored($trackId,$stored);
        return $this->result($trackId);
    }

    public function resetCues(int $trackId): array
    {
        $stored=$this->readStored($trackId);
        if(!$stored)throw new RuntimeException('Analisi audio non disponibile.');
        $duration=max(0.1,(float)($stored['duration_seconds']??0));
        $initial=['vocal'=>'VOX','hihat'=>'HIHAT','bass'=>'BASS','instruments'=>'INSTRUMENTS','kick'=>'KICK'];
        foreach($initial as $layer=>$label)$stored['layer_sections'][$layer]=[['start'=>0.0,'end'=>round($duration,3),'time'=>0.0,'label'=>$label,'manual'=>true]];
        unset($stored['automatic_cues_at'],$stored['automatic_cues_macro_genre'],$stored['automatic_cue_events']);
        $this->writeStored($trackId,$stored);
        return $this->result($trackId);
    }

    public function analyzePhraseKeys(int $trackId): array
    {
        set_time_limit(0);
        $stored=$this->readStored($trackId);
        if(!$stored)throw new RuntimeException('Analisi audio non disponibile.');
        $track=$this->trackReference($trackId);
        $path=(string)($track['file_path']??'');
        $bpm=max(1.0,(float)($stored['bpm']??$track['db_bpm']??0));
        $offset=(float)($stored['grid_offset_seconds']??0);
        $process=proc_open(['python',APP_ROOT.'/tools/audio_phrase_key_analyzer.py','base64:'.base64_encode($path),(string)$bpm,(string)$offset,'16'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT);
        if(!is_resource($process))throw new RuntimeException('Avvio analisi key per frase non riuscito.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exitCode=proc_close($process);
        $payload=json_decode(trim((string)$stdout),true);
        if($exitCode!==0||!is_array($payload)||empty($payload['ok']))throw new RuntimeException(trim((string)($payload['error']??$stderr))?:'Analisi key per frase non riuscita.');
        $stored['phrase_key_beats']=(int)($payload['phrase_beats']??16);
        $stored['phrase_keys']=array_values((array)($payload['phrases']??[]));
        $stored['phrase_keys_analyzed_at']=date(DATE_ATOM);
        $this->writeStored($trackId,$stored);
        return $this->result($trackId);
    }

    public function analyze(int $trackId): array
    {
        set_time_limit(0);
        $track = $this->trackReference($trackId);
        $path = (string)($track['file_path'] ?? '');
        if ($path === '' || !is_file($path)) throw new RuntimeException('File fisico del brano non disponibile.');

        $script = APP_ROOT . '/tools/audio_analyzer.py';
        $vdjBpm = $this->virtualDjBpm($trackId, $path);
        $command = ['python', $script, 'base64:'.base64_encode($path)];
        if ($vdjBpm !== null) $command[] = (string)$vdjBpm;
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            APP_ROOT
        );
        if (!is_resource($process)) throw new RuntimeException('Avvio analizzatore Python non riuscito.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $payload = json_decode(trim((string)$stdout), true);
        if ($exitCode !== 0 || !is_array($payload) || empty($payload['ok'])) {
            $error = trim((string)($payload['error'] ?? '')) ?: trim((string)$stderr) ?: ('Analisi audio fallita (exit '.$exitCode.'): '.mb_substr(trim((string)$stdout),0,500));
            $this->writeStored($trackId, [
                'track_id' => $trackId,
                'status' => 'error',
                'file_path' => $path,
                'error_message' => mb_substr($error, 0, 1000),
                'analyzed_at' => date('Y-m-d H:i:s'),
            ]);
            throw new RuntimeException($error);
        }

        $duration = max(0.0, (float)($payload['duration_seconds'] ?? 0));
        $bpm = max(1.0, (float)($vdjBpm ?? $track['db_bpm'] ?? $payload['bpm'] ?? 120));
        $overviewWaveform = array_values((array)($payload['overview_waveform'] ?? []));
        $cuePoints = array_values((array)($payload['drops'] ?? []));

        $stored = [
            'track_id' => $trackId,
            'status' => 'complete',
            'file_path' => $path,
            'path_signature' => sha1($path . '|' . (string)filesize($path) . '|' . (string)filemtime($path)),
            'analyzer_version' => (string)($payload['analyzer_version'] ?? 'audio-local-0.1'),
            'duration_seconds' => $payload['duration_seconds'] ?? null,
            'bpm' => $bpm,
            'detected_bpm' => $payload['detected_bpm'] ?? $payload['bpm'] ?? null,
            'bpm_source' => $vdjBpm !== null ? 'virtualdj' : (($track['db_bpm'] ?? null) ? 'database' : 'local_analysis'),
            'bpm_confidence' => $payload['bpm_confidence'] ?? null,
            'musical_key' => (string)($payload['musical_key'] ?? ''),
            'camelot' => (string)($payload['camelot'] ?? ''),
            'key_confidence' => $payload['key_confidence'] ?? null,
            'energy_score' => $payload['energy_score'] ?? null,
            'integrated_lufs' => $payload['integrated_lufs'] ?? null,
            'loudness_range_lu' => $payload['loudness_range_lu'] ?? null,
            'true_peak_dbfs' => $payload['true_peak_dbfs'] ?? null,
            'intro_end_seconds' => $payload['intro_end'] ?? null,
            'outro_start_seconds' => $payload['outro_start'] ?? null,
            'build_ups' => array_values((array)($payload['build_ups'] ?? [])),
            'drops' => $cuePoints,
            'cue_source' => 'automatic_audio',
            'loops' => array_values((array)($payload['loops'] ?? [])),
            'overview_waveform' => $overviewWaveform,
            'stem_source' => (string)($payload['stem_source'] ?? ''),
            'stem_waveforms' => array_values((array)($payload['stem_waveforms'] ?? [])),
            'sections' => array_values((array)($payload['sections'] ?? [])),
            'error_message' => '',
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
        $this->writeStored($trackId, $stored);
        return $this->analyzePhraseKeys($trackId);
    }

    public function updateDrop(int $trackId, int $index, float $start, float $end, bool $delete = false): array
    {
        $stored = $this->readStored($trackId);
        if (!$stored) throw new RuntimeException('Analisi audio non disponibile per il brano selezionato.');
        $drops = array_values((array)($stored['drops'] ?? []));
        if ($delete) {
            if (!isset($drops[$index])) throw new RuntimeException('Cue point non trovato.');
            array_splice($drops, $index, 1);
        } else {
            $duration = max(0.0, (float)($stored['duration_seconds'] ?? 0));
            if ($start < 0 || $end <= $start || ($duration > 0 && $end > $duration + 0.1)) throw new RuntimeException('Intervallo cue point non valido.');
            $track = $this->trackReference($trackId);
            $waveformStart = max(0, $start - 5);
            $waveformEnd = min((float)($track['db_duration'] ?? $end + 5), $end + 5);
            $waveform = $this->waveform((string)$track['file_path'], $waveformStart, $waveformEnd);
            $bpm = max(1, (float)($stored['bpm'] ?? $track['db_bpm'] ?? 120));
            $drop = [
                'start' => round($start, 2),
                'end' => round($end, 2),
                'waveform_start' => round($waveformStart, 2),
                'waveform_end' => round($waveformEnd, 2),
                'beats' => max(1, (int)round(($end - $start) / (60 / $bpm))),
                'detected_end' => round($end, 2),
                'score' => 100,
                'manual' => true,
                'source' => 'manual',
                'waveform' => $waveform,
            ];
            if ($index >= 0 && isset($drops[$index])) $drops[$index] = $drop;
            else $drops[] = $drop;
            usort($drops, static fn(array $left, array $right): int => ((float)($left['start'] ?? 0)) <=> ((float)($right['start'] ?? 0)));
        }
        $stored['drops'] = array_values($drops);
        $stored['manual_cue_updated_at'] = date('Y-m-d H:i:s');
        $this->writeStored($trackId, $stored);
        return array_merge($stored, $this->trackReference($trackId));
    }

    public function updateLayout(int $trackId, array $sections, array $buildUps, array $drops, array $layerSections = [], string $selectedLayer = '', float $gridOffset = 0.0): array
    {
        $stored = $this->readStored($trackId);
        if (!$stored) throw new RuntimeException('Analisi audio non disponibile per il brano selezionato.');
        $duration = max(0.1, (float)($stored['duration_seconds'] ?? 0));
        $stored['sections'] = $this->mergeLayoutItems((array)($stored['sections'] ?? []), $sections, $duration, true);
        $stored['build_ups'] = $this->mergeLayoutItems((array)($stored['build_ups'] ?? []), $buildUps, $duration, false);
        $stored['drops'] = $this->mergeLayoutItems((array)($stored['drops'] ?? []), $drops, $duration, false);
        $allowedLayers = ['vocal','hihat','bass','instruments','kick'];
        $storedLayerSections = is_array($stored['layer_sections'] ?? null) ? $stored['layer_sections'] : [];
        foreach ($allowedLayers as $layer) {
            if (!array_key_exists($layer, $layerSections) || !is_array($layerSections[$layer])) continue;
            $storedLayerSections[$layer] = $this->mergeLayoutItems((array)($storedLayerSections[$layer] ?? []), $layerSections[$layer], $duration, true);
        }
        $stored['layer_sections'] = $storedLayerSections;
        $stored['selected_stem_layer'] = in_array($selectedLayer, $allowedLayers, true) ? $selectedLayer : '';
        $newGridOffset=round(max(-$duration,min($duration,$gridOffset)),4);
        if(abs($newGridOffset-(float)($stored['grid_offset_seconds']??0))>0.0001)unset($stored['phrase_keys'],$stored['phrase_keys_analyzed_at']);
        $stored['grid_offset_seconds'] = $newGridOffset;
        $stored['manual_layout_updated_at'] = date('Y-m-d H:i:s');
        $this->writeStored($trackId, $stored);
        return array_merge($stored, $this->trackReference($trackId));
    }

    public function streamPath(int $trackId, array $requestedStems): string
    {
        $track = $this->trackReference($trackId);
        $masterPath = (string)($track['file_path'] ?? '');
        $stems = array_values(array_unique(array_intersect(['vocal','hihat','bass','instruments','kick'], array_map(static fn($value): string => strtolower(trim((string)$value)), $requestedStems))));
        if (!$stems) return $masterPath;
        $stored = $this->readStored($trackId);
        $stemPath = canonicalPath((string)($stored['stem_source'] ?? ''));
        if ($stemPath === '' || !is_file($stemPath)) throw new RuntimeException('Stem VirtualDJ non disponibile per il preascolto.');
        $probe = proc_open(['ffprobe','-v','error','-show_entries','stream=index,codec_type:stream_tags=title','-of','json',$stemPath],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT);
        if (!is_resource($probe)) throw new RuntimeException('Lettura stream VirtualDJ non riuscita.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exitCode=proc_close($probe);
        $payload=json_decode((string)$stdout,true);if($exitCode!==0||!is_array($payload))throw new RuntimeException(trim((string)$stderr)?:'Lettura stream VirtualDJ non riuscita.');
        $streamIndexes=[];foreach((array)($payload['streams']??[]) as $stream){$title=strtolower(trim((string)($stream['tags']['title']??'')));if(($stream['codec_type']??'')==='audio'&&in_array($title,$stems,true))$streamIndexes[$title]=(int)$stream['index'];}
        $indexes=[];foreach($stems as $stem){if(isset($streamIndexes[$stem]))$indexes[]=$streamIndexes[$stem];}
        if(!$indexes)throw new RuntimeException('Nessuno degli stem attivi è disponibile nel file VirtualDJ.');
        $cacheDirectory=APP_ROOT.'/storage/audio_analysis/stream_cache';if(!is_dir($cacheDirectory)&&!mkdir($cacheDirectory,0775,true)&&!is_dir($cacheDirectory))throw new RuntimeException('Cache preascolto stem non disponibile.');
        $signature=sha1('seek-cbr-v1|'.$stemPath.'|'.filesize($stemPath).'|'.filemtime($stemPath).'|'.implode(',',$stems));$output=$cacheDirectory.'/'.$trackId.'-'.$signature.'.mp3';
        if(is_file($output)&&filesize($output)>1000)return $output;
        $command=['ffmpeg','-y','-v','error','-i',$stemPath];
        if(count($indexes)===1){array_push($command,'-map','0:'.$indexes[0]);}
        else{$inputs=implode('',array_map(static fn(int $index): string=>'[0:'.$index.']',$indexes));$filter=$inputs.'amix=inputs='.count($indexes).':duration=longest:normalize=0,alimiter=limit=0.95[mix]';array_push($command,'-filter_complex',$filter,'-map','[mix]');}
        array_push($command,'-vn','-codec:a','libmp3lame','-b:a','192k',$output);
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT);if(!is_resource($process))throw new RuntimeException('Creazione preascolto stem non riuscita.');
        fclose($pipes[0]);stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exitCode=proc_close($process);
        if($exitCode!==0||!is_file($output)||filesize($output)<1000){@unlink($output);throw new RuntimeException(trim((string)$stderr)?:'Creazione preascolto stem non riuscita.');}
        return $output;
    }

    private function mergeLayoutItems(array $storedItems, array $submittedItems, float $duration, bool $sections): array
    {
        $items = [];
        foreach (array_slice(array_values($submittedItems), 0, 64) as $index => $submitted) {
            if (!is_array($submitted)) continue;
            $start = max(0.0, min($duration, (float)($submitted['start'] ?? 0)));
            $end = max($start + 0.05, min($duration, (float)($submitted['end'] ?? $start)));
            if ($end > $duration) $end = $duration;
            if ($end <= $start) continue;
            $base = is_array($storedItems[$index] ?? null) ? $storedItems[$index] : [];
            $base['start'] = round($start, 3);
            $base['end'] = round($end, 3);
            $base['time'] = round($start, 3);
            $name = trim(mb_substr((string)($submitted['name'] ?? $submitted['label'] ?? ''), 0, 40));
            if ($sections) $base['label'] = $name !== '' ? $name : 'Sezione';
            elseif ($name !== '') $base['name'] = $name;
            $base['manual'] = true;
            $items[] = $base;
        }
        usort($items, static fn(array $left, array $right): int => ((float)$left['start']) <=> ((float)$right['start']));
        return $items;
    }

    private function virtualDjEnergyCues(int $trackId, string $trackPath): array
    {
        foreach ($this->virtualDjSongEntries($trackId, $trackPath) as $song) {
            $cues = [];
            foreach ($song->Poi as $poi) {
                $type = strtolower(trim((string)$poi['Type']));
                $name = trim((string)$poi['Name']);
                if ($type !== 'cue' || !preg_match('/^Energy\s+(\d+)/i', $name, $match)) continue;
                $position = max(0.0, (float)$poi['Pos']);
                $cues[] = [
                    'position' => $position,
                    'name' => $name,
                    'energy' => (int)$match[1],
                    'number' => (int)$poi['Num'],
                ];
            }
            usort($cues, static fn(array $left, array $right): int => $left['position'] <=> $right['position']);
            return $cues;
        }
        return [];
    }

    private function virtualDjNamedCues(int $trackId, string $trackPath): array
    {
        foreach ($this->virtualDjSongEntries($trackId, $trackPath) as $song) {
            $cues = [];
            foreach ($song->Poi as $poi) {
                $type = strtolower(trim((string)$poi['Type']));
                if ($type !== 'cue') continue;
                $name = trim((string)$poi['Name']);
                $position = max(0.0, (float)$poi['Pos']);
                if ($name === '' && $position <= 0.0) continue;
                $cues[] = [
                    'position' => round($position, 3),
                    'name' => $name !== '' ? $name : 'Cue',
                    'number' => (int)$poi['Num'],
                    'color' => trim((string)$poi['Color']),
                    'type' => $this->virtualDjCueType($name),
                ];
            }
            usort($cues, static fn(array $left, array $right): int => $left['position'] <=> $right['position']);
            return $cues;
        }
        return [];
    }

    private function virtualDjBpm(int $trackId, string $trackPath): ?float
    {
        foreach ($this->virtualDjSongEntries($trackId, $trackPath) as $song) {
            $raw = (float)($song->Tags['Bpm'] ?? $song->Scan['Bpm'] ?? 0);
            if ($raw <= 0) return null;
            return round($raw < 2 ? 60 / $raw : $raw, 9);
        }
        return null;
    }

    private function virtualDjGridPhase(int $trackId, string $trackPath): ?float
    {
        foreach ($this->virtualDjSongEntries($trackId, $trackPath) as $song) {
            if (!isset($song->Scan['Phase'])) return null;
            return round((float)$song->Scan['Phase'], 6);
        }
        return null;
    }

    private function virtualDjBeatInterval(int $trackId, string $trackPath): ?float
    {
        foreach ($this->virtualDjSongEntries($trackId, $trackPath) as $song) {
            $raw = (float)($song->Scan['Bpm'] ?? 0);
            if ($raw <= 0) return null;
            return round($raw < 2 ? $raw : 60 / $raw, 9);
        }
        return null;
    }

    private function virtualDjSongEntries(int $trackId, string $trackPath): array
    {
        $statement = $this->pdo->prepare('SELECT database_path FROM track_sources WHERE track_id=? ORDER BY database_path');
        $statement->execute([$trackId]);
        $databasePaths = array_values(array_unique(array_filter(array_map('canonicalPath', $statement->fetchAll(PDO::FETCH_COLUMN)))));
        foreach ([setting('vdj_database', ''), 'E:\\VirtualDJ\\database.xml'] as $fallback) {
            $fallback = canonicalPath((string)$fallback);
            if ($fallback !== '' && !in_array($fallback, $databasePaths, true)) $databasePaths[] = $fallback;
        }
        $canonicalTrackPath = canonicalPath($trackPath);
        foreach ($databasePaths as $databasePath) {
            if (!is_file($databasePath)) continue;
            $xml = @simplexml_load_file($databasePath, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if (!$xml) continue;
            $matches = [];
            foreach ($xml->Song as $song) {
                if (strcasecmp(canonicalPath((string)$song['FilePath']), $canonicalTrackPath) !== 0) continue;
                $matches[] = $song;
            }
            if (!$matches) continue;
            usort($matches, static function (SimpleXMLElement $left, SimpleXMLElement $right): int {
                $score = static function (SimpleXMLElement $song): array {
                    return [
                        isset($song->Scan['Bpm']) ? 1 : 0,
                        trim((string)($song->Scan['BeatGrid'] ?? '')) !== '' ? 1 : 0,
                        (int)($song->Infos['LastModified'] ?? 0),
                    ];
                };
                return $score($right) <=> $score($left);
            });
            return $matches;
        }
        return [];
    }

    private function virtualDjCueType(string $name): string
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '') return 'generic';
        if (str_starts_with($normalized, 'energy ')) return 'energy';
        if (str_contains($normalized, 'fine parlato')) return 'vocal-end';
        if (str_contains($normalized, 'inizio parlato')) return 'vocal-start';
        if (str_contains($normalized, 'build up')) return 'build-up';
        if (str_contains($normalized, 'drop')) return 'drop';
        if (str_contains($normalized, 'break')) return 'break';
        return 'generic';
    }

    private function energyCueSegments(array $cues, float $duration, float $bpm, array $overviewWaveform): array
    {
        $segments = [];
        foreach ($cues as $index => $cue) {
            $start = max(0.0, min($duration, (float)$cue['position']));
            $end = isset($cues[$index + 1])
                ? max($start + 0.1, min($duration, (float)$cues[$index + 1]['position']))
                : $duration;
            if ($end <= $start) continue;
            $waveformStart = max(0.0, $start - 5);
            $waveformEnd = min($duration, $end + 5);
            $segments[] = [
                'start' => round($start, 3),
                'end' => round($end, 3),
                'time' => round($start, 3),
                'waveform_start' => round($waveformStart, 3),
                'waveform_end' => round($waveformEnd, 3),
                'beats' => max(1, (int)round(($end - $start) / (60 / $bpm))),
                'detected_end' => round($end, 3),
                'score' => 100,
                'manual' => false,
                'source' => 'virtualdj_energy',
                'name' => (string)$cue['name'],
                'energy' => (int)$cue['energy'],
                'vdj_number' => (int)$cue['number'],
                'waveform' => $this->overviewWaveformSlice($overviewWaveform, $duration, $waveformStart, $waveformEnd),
            ];
        }
        return $segments;
    }

    private function overviewWaveformSlice(array $waveform, float $duration, float $start, float $end): array
    {
        $count = count($waveform);
        if ($count < 2 || $duration <= 0) return array_values($waveform);
        $first = max(0, min($count - 1, (int)floor($start / $duration * $count)));
        $last = max($first + 2, min($count, (int)ceil($end / $duration * $count) + 1));
        return array_values(array_slice($waveform, $first, $last - $first));
    }

    private function trackReference(int $trackId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id track_id,artist,title,file_path,macro_genre,year,
                    duration db_duration,bpm db_bpm,musical_key db_musical_key,
                    camelot db_camelot,energy db_energy,kr_energy,
                    spotify_energy,spotify_tempo,spotify_loudness,
                    spotify_key,spotify_mode
             FROM tracks
             WHERE id=? AND file_exists=1
             LIMIT 1'
        );
        $statement->execute([$trackId]);
        $track = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$track) throw new RuntimeException('Brano non disponibile nella Libreria Musicale.');
        return $track;
    }

    private function readStored(int $trackId): ?array
    {
        $path = $this->storagePath($trackId);
        if (!is_file($path)) return null;
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return null;
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return null;
        }
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $payload = json_decode((string)$contents, true);
        return is_array($payload) ? $payload : null;
    }

    private function writeStored(int $trackId, array $payload): void
    {
        $directory = APP_ROOT . '/storage/audio_analysis';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cartella risultati analisi non disponibile.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) throw new RuntimeException('Salvataggio risultato analisi non riuscito.');
        $path = $this->storagePath($trackId);
        if (file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('Scrittura risultato analisi non riuscita.');
    }

    private function waveform(string $path, float $start, float $end): array
    {
        $process = proc_open(
            ['python', APP_ROOT . '/tools/audio_analyzer.py', '--waveform', 'base64:'.base64_encode($path), (string)$start, (string)$end],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            APP_ROOT
        );
        if (!is_resource($process)) throw new RuntimeException('Rigenerazione waveform non riuscita.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $payload = json_decode(trim((string)$stdout), true);
        if ($exitCode !== 0 || !is_array($payload) || empty($payload['ok'])) {
            throw new RuntimeException(trim((string)($payload['error'] ?? $stderr ?? 'Rigenerazione waveform non riuscita.')));
        }
        return array_values(array_map('intval', (array)($payload['waveform'] ?? [])));
    }

    private function storagePath(int $trackId): string
    {
        return APP_ROOT . '/storage/audio_analysis/' . $trackId . '.json';
    }
}
