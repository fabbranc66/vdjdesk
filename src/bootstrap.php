<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const DB_PATH = APP_ROOT . '/storage/kr-dj-desk.sqlite';
const DB_NAME = 'vdjdesk';

function appConfig(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }

    $config = [
        'database' => [
            'host' => getenv('KR_DESK_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('KR_DESK_DB_PORT') ?: '',
            'name' => getenv('KR_DESK_DB_NAME') ?: DB_NAME,
            'user' => getenv('KR_DESK_DB_USER') ?: 'root',
            'password' => getenv('KR_DESK_DB_PASSWORD') ?: '',
            'charset' => getenv('KR_DESK_DB_CHARSET') ?: 'utf8mb4',
            'create_database' => (getenv('KR_DESK_DB_CREATE') ?: '1') !== '0',
        ],
    ];

    $localConfig = APP_ROOT . '/config.php';
    if (is_file($localConfig)) {
        $loaded = require $localConfig;
        if (is_array($loaded)) {
            $config = array_replace_recursive($config, $loaded);
        }
    }

    return $config;
}

function appUsesLocalFiles(): bool
{
    $database = appConfig()['database'] ?? [];
    $host = strtolower((string) ($database['host'] ?? '127.0.0.1'));
    return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = appConfig()['database'];
    $charset = (string) ($database['charset'] ?? 'utf8mb4');
    $host = (string) ($database['host'] ?? '127.0.0.1');
    $port = (string) ($database['port'] ?? '');
    $name = (string) ($database['name'] ?? DB_NAME);
    $user = (string) ($database['user'] ?? 'root');
    $password = (string) ($database['password'] ?? '');
    $portPart = $port !== '' ? ';port=' . $port : '';

    if (!empty($database['create_database'])) {
        $server = new PDO("mysql:host={$host}{$portPart};charset={$charset}", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . "` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
    }

    $pdo = new PDO("mysql:host={$host}{$portPart};dbname={$name};charset={$charset}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    migrate($pdo);
    seed($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        migrateMariaDb($pdo);
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tracks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            artist TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            normalized_artist TEXT NOT NULL DEFAULT '', normalized_title TEXT NOT NULL DEFAULT '',
            file_path TEXT NOT NULL UNIQUE, file_name TEXT NOT NULL DEFAULT '', folder TEXT NOT NULL DEFAULT '',
            genre TEXT NOT NULL DEFAULT '', archive_area TEXT NOT NULL DEFAULT '', macro_genre TEXT NOT NULL DEFAULT '', folder_genre TEXT NOT NULL DEFAULT '',
            year INTEGER, bpm REAL, musical_key TEXT NOT NULL DEFAULT '', camelot TEXT NOT NULL DEFAULT '',
            duration INTEGER, rating INTEGER NOT NULL DEFAULT 0, play_count INTEGER NOT NULL DEFAULT 0,
            last_played TEXT, tags TEXT NOT NULL DEFAULT '[]', version TEXT NOT NULL DEFAULT '',
            album TEXT NOT NULL DEFAULT '', release_date TEXT NOT NULL DEFAULT '',
            spotify_id TEXT NOT NULL DEFAULT '', spotify_url TEXT NOT NULL DEFAULT '',
            isrc TEXT NOT NULL DEFAULT '', popularity INTEGER,
            metadata_source TEXT NOT NULL DEFAULT '', metadata_updated_at TEXT,
            energy INTEGER NOT NULL DEFAULT 3, singability INTEGER NOT NULL DEFAULT 3,
            danceability INTEGER NOT NULL DEFAULT 3, familiarity INTEGER NOT NULL DEFAULT 3, risk INTEGER NOT NULL DEFAULT 3,
            bitrate INTEGER, file_size INTEGER, file_exists INTEGER NOT NULL DEFAULT 1,
            source TEXT NOT NULL DEFAULT 'manual', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_tracks_normalized ON tracks(normalized_artist, normalized_title);
        CREATE INDEX IF NOT EXISTS idx_tracks_bpm ON tracks(bpm);
        CREATE INDEX IF NOT EXISTS idx_tracks_genre ON tracks(genre);
        CREATE INDEX IF NOT EXISTS idx_tracks_taxonomy ON tracks(archive_area, macro_genre, folder_genre);
        CREATE INDEX IF NOT EXISTS idx_tracks_file_path_nocase ON tracks(file_path COLLATE NOCASE);
        CREATE TABLE IF NOT EXISTS history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, track_id INTEGER NOT NULL,
            played_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, session_date TEXT NOT NULL,
            FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT, guest_name TEXT NOT NULL DEFAULT '', query TEXT NOT NULL,
            track_id INTEGER, status TEXT NOT NULL DEFAULT 'new', note TEXT NOT NULL DEFAULT '',
            client_token TEXT NOT NULL DEFAULT '', client_ip TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT, track_id INTEGER NOT NULL, source TEXT NOT NULL DEFAULT 'dj',
            position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS duplicate_decisions (
            fingerprint TEXT PRIMARY KEY, decision TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS library_databases (
            path TEXT PRIMARY KEY, label TEXT NOT NULL, drive_letter TEXT NOT NULL DEFAULT '',
            file_modified_at INTEGER NOT NULL DEFAULT 0, file_size INTEGER NOT NULL DEFAULT 0,
            imported_modified_at INTEGER NOT NULL DEFAULT 0, imported_size INTEGER NOT NULL DEFAULT 0,
            record_count INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending',
            message TEXT NOT NULL DEFAULT '', last_synced_at TEXT
        );
        CREATE TABLE IF NOT EXISTS track_sources (
            track_id INTEGER NOT NULL, database_path TEXT NOT NULL, source_file_path TEXT NOT NULL,
            sync_token TEXT NOT NULL, last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(database_path, source_file_path),
            FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE,
            FOREIGN KEY(database_path) REFERENCES library_databases(path) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_track_sources_track ON track_sources(track_id);
        CREATE TABLE IF NOT EXISTS e_duplicate_scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'running', started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT, files_scanned INTEGER NOT NULL DEFAULT 0,
            exact_groups INTEGER NOT NULL DEFAULT 0, normalized_groups INTEGER NOT NULL DEFAULT 0,
            message TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS e_file_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT, scan_id INTEGER NOT NULL,
            file_path TEXT NOT NULL, file_name TEXT NOT NULL, folder TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0, modified_at INTEGER NOT NULL DEFAULT 0,
            extension TEXT NOT NULL DEFAULT '', artist TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            normalized_artist TEXT NOT NULL DEFAULT '', normalized_title TEXT NOT NULL DEFAULT '',
            version TEXT NOT NULL DEFAULT '', bitrate INTEGER, rating INTEGER NOT NULL DEFAULT 0,
            play_count INTEGER NOT NULL DEFAULT 0, content_hash TEXT NOT NULL DEFAULT '',
            UNIQUE(scan_id,file_path), FOREIGN KEY(scan_id) REFERENCES e_duplicate_scans(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_e_inventory_normalized ON e_file_inventory(scan_id,normalized_artist,normalized_title);
        CREATE INDEX IF NOT EXISTS idx_e_inventory_size ON e_file_inventory(scan_id,file_size);
        CREATE TABLE IF NOT EXISTS e_duplicate_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT, scan_id INTEGER NOT NULL,
            type TEXT NOT NULL, fingerprint TEXT NOT NULL, label TEXT NOT NULL,
            confidence INTEGER NOT NULL DEFAULT 0, reason TEXT NOT NULL,
            recommended_file_id INTEGER, decision TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(scan_id,type,fingerprint),
            FOREIGN KEY(scan_id) REFERENCES e_duplicate_scans(id) ON DELETE CASCADE,
            FOREIGN KEY(recommended_file_id) REFERENCES e_file_inventory(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS e_duplicate_group_items (
            group_id INTEGER NOT NULL, file_id INTEGER NOT NULL, is_recommended INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(group_id,file_id),
            FOREIGN KEY(group_id) REFERENCES e_duplicate_groups(id) ON DELETE CASCADE,
            FOREIGN KEY(file_id) REFERENCES e_file_inventory(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS deletion_candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_path TEXT NOT NULL UNIQUE, source_folder TEXT NOT NULL, source_name TEXT NOT NULL,
            source_size INTEGER NOT NULL DEFAULT 0, e_file_path TEXT NOT NULL, e_file_name TEXT NOT NULL,
            e_file_size INTEGER NOT NULL DEFAULT 0, match_type TEXT NOT NULL,
            confidence INTEGER NOT NULL DEFAULT 0, reason TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'marked', first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, decision_note TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_deletion_candidates_folder ON deletion_candidates(source_folder,status);
    SQL);
    $trackColumns = array_column($pdo->query('PRAGMA table_info(tracks)')->fetchAll(), 'name');
    $trackMetadataColumns = [
        'album' => "TEXT NOT NULL DEFAULT ''",
        'release_date' => "TEXT NOT NULL DEFAULT ''",
        'spotify_id' => "TEXT NOT NULL DEFAULT ''",
        'spotify_url' => "TEXT NOT NULL DEFAULT ''",
        'isrc' => "TEXT NOT NULL DEFAULT ''",
        'popularity' => 'INTEGER',
        'metadata_source' => "TEXT NOT NULL DEFAULT ''",
        'metadata_updated_at' => 'TEXT',
        'archive_area' => "TEXT NOT NULL DEFAULT ''",
        'macro_genre' => "TEXT NOT NULL DEFAULT ''",
        'folder_genre' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($trackMetadataColumns as $column => $definition) {
        if (!in_array($column, $trackColumns, true)) {
            $pdo->exec("ALTER TABLE tracks ADD COLUMN $column $definition");
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tracks_taxonomy ON tracks(archive_area, macro_genre, folder_genre)');
    $candidateColumns = array_column($pdo->query('PRAGMA table_info(deletion_candidates)')->fetchAll(), 'name');
    if (!in_array('approved_at', $candidateColumns, true)) {
        $pdo->exec('ALTER TABLE deletion_candidates ADD COLUMN approved_at TEXT');
    }
    if (!in_array('last_vdj_search_at', $candidateColumns, true)) {
        $pdo->exec('ALTER TABLE deletion_candidates ADD COLUMN last_vdj_search_at TEXT');
    }
    if (!in_array('moved_to_path', $candidateColumns, true)) {
        $pdo->exec('ALTER TABLE deletion_candidates ADD COLUMN moved_to_path TEXT');
    }
    if (!in_array('moved_at', $candidateColumns, true)) {
        $pdo->exec('ALTER TABLE deletion_candidates ADD COLUMN moved_at TEXT');
    }
    $pdo->exec("UPDATE deletion_candidates SET approved_at=COALESCE(approved_at,last_seen_at,first_seen_at) WHERE status='approved' AND approved_at IS NULL");
}

function migrateMariaDb(PDO $pdo): void
{
    $schema = file_get_contents(APP_ROOT . '/src/mariadb-schema.sql');
    if ($schema === false) throw new RuntimeException('Schema MariaDB non trovato.');
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        $pdo->exec($statement);
    }
    $columns = array_column($pdo->query('SHOW COLUMNS FROM tracks')->fetchAll(), 'Field');
    $spotifyColumns = [
        'spotify_energy' => 'DECIMAL(6,5) NULL', 'spotify_danceability' => 'DECIMAL(6,5) NULL',
        'spotify_valence' => 'DECIMAL(6,5) NULL', 'spotify_acousticness' => 'DECIMAL(8,7) NULL',
        'spotify_instrumentalness' => 'DECIMAL(8,7) NULL', 'spotify_speechiness' => 'DECIMAL(8,7) NULL',
        'spotify_liveness' => 'DECIMAL(8,7) NULL', 'spotify_loudness' => 'DECIMAL(7,3) NULL',
        'spotify_tempo' => 'DECIMAL(8,3) NULL', 'spotify_key' => 'TINYINT NULL',
        'spotify_mode' => 'TINYINT NULL', 'spotify_features_updated_at' => 'DATETIME NULL',
        'spotify_features_status' => "VARCHAR(20) NOT NULL DEFAULT 'never'",
        'spotify_features_checked_at' => 'DATETIME NULL', 'spotify_features_error' => "VARCHAR(500) NOT NULL DEFAULT ''",
        'spotify_genre' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'kr_energy' => 'TINYINT NULL', 'kr_singability' => 'TINYINT NULL', 'kr_floor_power' => 'TINYINT NULL',
        'kr_familiarity' => 'TINYINT NULL', 'kr_risk' => 'TINYINT NULL', 'kr_peak' => 'TINYINT NULL',
        'kr_recovery' => 'TINYINT NULL',
        'auto_tags' => 'LONGTEXT NULL',
        'auto_tag_overrides' => 'LONGTEXT NULL',
        'genre_manual' => 'TINYINT NOT NULL DEFAULT 0',
        'year_manual' => 'TINYINT NOT NULL DEFAULT 0',
        'dj_scores_manual' => 'TINYINT NOT NULL DEFAULT 0',
        'archive_area' => "VARCHAR(50) NOT NULL DEFAULT ''",
        'macro_genre' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'folder_genre' => "VARCHAR(150) NOT NULL DEFAULT ''",
    ];
    foreach ($spotifyColumns as $column => $definition) {
        if (!in_array($column, $columns, true)) $pdo->exec("ALTER TABLE tracks ADD COLUMN $column $definition");
    }
    $indexes=array_column($pdo->query("SHOW INDEX FROM tracks WHERE Key_name='idx_tracks_taxonomy'")->fetchAll(),'Key_name');
    if(!$indexes)$pdo->exec('CREATE INDEX idx_tracks_taxonomy ON tracks(archive_area, macro_genre, folder_genre)');
    if (!in_array('dj_scores_manual', $columns, true)) {
        $pdo->exec('UPDATE tracks SET dj_scores_manual=1 WHERE energy<>3 OR singability<>3 OR danceability<>3 OR familiarity<>3 OR risk<>3');
    }
    if (!in_array('spotify_features_status', $columns, true)) {
        $pdo->exec("UPDATE tracks SET spotify_features_status='complete',spotify_features_checked_at=spotify_features_updated_at WHERE spotify_features_updated_at IS NOT NULL");
    }
    $inventoryColumns=array_column($pdo->query('SHOW COLUMNS FROM e_file_inventory')->fetchAll(),'Field');
    foreach(['genre'=>"VARCHAR(255) NOT NULL DEFAULT ''",'has_spotify'=>'TINYINT NOT NULL DEFAULT 0','spotify_complete'=>'TINYINT NOT NULL DEFAULT 0'] as $column=>$definition){
        if(!in_array($column,$inventoryColumns,true))$pdo->exec("ALTER TABLE e_file_inventory ADD COLUMN $column $definition");
    }
    $participantColumns=array_column($pdo->query('SHOW COLUMNS FROM quiz_participants')->fetchAll(),'Field');
    if(!in_array('is_online',$participantColumns,true))$pdo->exec('ALTER TABLE quiz_participants ADD COLUMN is_online TINYINT NOT NULL DEFAULT 1');
    if(!in_array('left_at',$participantColumns,true))$pdo->exec('ALTER TABLE quiz_participants ADD COLUMN left_at DATETIME NULL');
    if(!in_array('status',$participantColumns,true))$pdo->exec("ALTER TABLE quiz_participants ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
    if(!in_array('rejoin_requested_at',$participantColumns,true))$pdo->exec('ALTER TABLE quiz_participants ADD COLUMN rejoin_requested_at DATETIME NULL');
    $requestColumns=array_column($pdo->query('SHOW COLUMNS FROM requests')->fetchAll(),'Field');
    if(!in_array('public_token',$requestColumns,true))$pdo->exec('ALTER TABLE requests ADD COLUMN public_token CHAR(36) NULL');
    if(!in_array('client_token',$requestColumns,true))$pdo->exec("ALTER TABLE requests ADD COLUMN client_token VARCHAR(80) NOT NULL DEFAULT ''");
    if(!in_array('client_ip',$requestColumns,true))$pdo->exec("ALTER TABLE requests ADD COLUMN client_ip VARCHAR(64) NOT NULL DEFAULT ''");
    if(!in_array('estimated_play_at',$requestColumns,true))$pdo->exec('ALTER TABLE requests ADD COLUMN estimated_play_at DATETIME NULL');
    if(!in_array('estimated_wait_minutes',$requestColumns,true))$pdo->exec('ALTER TABLE requests ADD COLUMN estimated_wait_minutes INT NULL');
    if(!in_array('queue_position',$requestColumns,true))$pdo->exec('ALTER TABLE requests ADD COLUMN queue_position INT NULL');
    $indexes=array_column($pdo->query("SHOW INDEX FROM requests WHERE Key_name='idx_requests_public_token'")->fetchAll(),'Key_name');
    if(!$indexes)$pdo->exec('CREATE INDEX idx_requests_public_token ON requests(public_token)');
}

function seed(PDO $pdo): void
{
    $defaults = [
        'music_root' => 'D:\\Musica',
        'vdj_database' => 'D:\\VirtualDJ\\database.xml',
        'playlist_folder' => 'E:\\VirtualDJ\\MyLists',
        'definitive_playlist_folder' => 'E:\\LIBRERIA_DEFINITIVA\\PLAYLIST',
        'spotmate_download_folder' => 'E:\\LIBRERIA_DEFINITIVA\\01_INBOX\\Da_classificare',
        'duplicate_threshold' => '88',
        'recent_exclusion' => '20',
        'bpm_range' => '8',
        'key_mode' => 'camelot',
        'vdj_network_host' => '127.0.0.1',
        'vdj_network_port' => '9665',
        'quiz_codex_model' => 'gpt-5-mini',
        'event_started_at' => date('Y-m-d H:i:s'),
    ];
    $statement = $pdo->prepare('INSERT IGNORE INTO settings(`key`, value) VALUES(?, ?)');
    foreach ($defaults as $key => $value) {
        $statement->execute([$key, $value]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn() > 0) {
        return;
    }

    $demo = [
        ['The Weeknd','Blinding Lights',171,'8A','Pop Dance',2020,5,['COMMERCIALE','CANTO','PISTA'],4,5,5,5,1,'Radio Edit'],
        ['Dua Lipa','Levitating',103,'11B','Pop Dance',2020,5,['COMMERCIALE','DONNE','PISTA'],4,5,5,5,1,''],
        ['Black Eyed Peas','I Gotta Feeling',128,'2B','Commerciale',2009,5,['URLANTE','CANTO','RECUPERO PISTA'],5,5,5,5,1,'Extended Mix'],
        ['Raffaella Carrà','Pedro',128,'9A','Italiano',1980,5,['CANTO','URLANTE','COMMERCIALE'],4,5,5,5,1,''],
        ['Raffaella Carra','Pedro (Official Video)',128,'9A','Italiano',1980,3,['CANTO'],4,5,5,5,2,'Radio Edit'],
        ['Bad Bunny','Tití Me Preguntó',107,'9A','Reggaeton',2022,5,['REGGAETON','URBAN','PISTA'],5,3,5,5,2,'Explicit'],
        ['Daddy Yankee','Gasolina',96,'4A','Reggaeton',2004,5,['REGGAETON','URLANTE','RECUPERO PISTA'],5,5,5,5,1,''],
        ['Don Omar','Danza Kuduro',130,'8A','Reggaeton',2010,5,['REGGAETON','CANTO','URLANTE'],5,5,5,5,1,'Extended'],
        ['Karol G','Provenza',111,'8A','Reggaeton',2022,4,['REGGAETON','DONNE','PISTA'],4,4,5,4,2,'Clean'],
        ['El Alfa','La Mamá de la Mamá',110,'6A','Dembow',2021,4,['DEMBOW','URBAN','PICCO'],5,2,5,4,3,''],
        ['Gigi D’Agostino','L’Amour Toujours',139,'1B','Dance',1999,5,['URLANTE','CANTO','CHIUSURA'],5,5,5,5,1,''],
        ['Gala','Freed From Desire',130,'8A','Dance 90',1996,5,['URLANTE','CANTO','RECUPERO PISTA'],5,5,5,5,1,''],
        ['Corona','The Rhythm of the Night',128,'9A','Dance 90',1993,5,['DONNE','CANTO','PISTA'],4,5,5,5,1,''],
        ['Pitbull','Give Me Everything',129,'6A','Commerciale',2011,5,['COMMERCIALE','URLANTE','PICCO'],5,5,5,5,1,'Clean'],
        ['Beyoncé','Crazy in Love',99,'2A','R&B',2003,5,['DONNE','URBAN','CANTO'],4,4,5,5,2,''],
        ['50 Cent','In Da Club',90,'6A','Hip Hop',2003,5,['URBAN','RAP IT','CANTO'],4,4,5,5,2,'Clean'],
        ['Jovanotti','L’Ombelico del Mondo',104,'10A','Italiano',1995,4,['CANTO','COMMERCIALE','CAMBIO GENERE SICURO'],4,5,5,5,1,''],
        ['883','Gli Anni',95,'7B','Italiano',1995,5,['CANTO','CHIUSURA','UOMINI'],3,5,3,5,1,''],
        ['Shakira','Waka Waka',128,'11B','Latin Pop',2010,5,['DONNE','CANTO','RECUPERO PISTA'],5,5,5,5,1,''],
        ['Bob Sinclar','Love Generation',128,'8B','House',2005,4,['APERITIVO','WARMUP','CANTO'],3,4,4,4,1,'Extended Mix'],
    ];
    $insert = $pdo->prepare('INSERT INTO tracks(artist,title,normalized_artist,normalized_title,file_path,file_name,folder,genre,year,bpm,musical_key,camelot,duration,rating,play_count,last_played,tags,version,energy,singability,danceability,familiarity,risk,bitrate,file_size,file_exists,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($demo as $index => $track) {
        [$artist,$title,$bpm,$camelot,$genre,$year,$rating,$tags,$energy,$sing,$dance,$familiarity,$risk,$version] = $track;
        $fileName = preg_replace('/[^\pL\pN]+/u', '_', "$artist - $title") . '.mp3';
        $path = "D:\\Musica\\Demo\\$fileName";
        $lastPlayed = $index < 5 ? date('Y-m-d H:i:s', time() - (($index + 1) * 900)) : null;
        $insert->execute([$artist,$title,normalizeText($artist),normalizeTitle($title),$path,$fileName,'D:\\Musica\\Demo',$genre,$year,$bpm,'',$camelot,210 + $index * 3,$rating,max(0,20-$index),$lastPlayed,json_encode($tags, JSON_UNESCAPED_UNICODE),$version,$energy,$sing,$dance,$familiarity,$risk,320,7000000 + $index * 150000,0,'demo']);
    }
    $ids = $pdo->query('SELECT id FROM tracks ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
    $history = $pdo->prepare('INSERT INTO history(track_id, played_at, session_date) VALUES(?, ?, ?)');
    foreach ($ids as $offset => $id) {
        $playedAt = date('Y-m-d H:i:s', time() - (($offset + 1) * 900));
        $history->execute([$id, $playedAt, date('Y-m-d')]);
    }
}

function normalizeText(string $value): string
{
    $value = mb_strtolower(trim($value));
    $map = ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','’'=>"'",'`'=>"'"];
    $value = strtr($value, $map);
    $value = preg_replace('/\b(feat|ft|featuring)\.?\s+.*$/u', '', $value) ?? $value;
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value);
}

function normalizeTitle(string $value): string
{
    $value = mb_strtolower($value);
    $noise = ['official music video','official video','official audio','lyrics video','lyric video','lyrics','audio','extended mix','extended version','radio edit','clean version','clean','explicit version','explicit','original mix','remastered'];
    $value = str_replace($noise, ' ', $value);
    $value = preg_replace('/\([^)]*\)|\[[^]]*\]/u', ' ', $value) ?? $value;
    return normalizeText($value);
}

function canonicalPath(string $path): string
{
    $path = str_replace('/', '\\', trim($path));
    $real = realpath($path);
    if (is_string($real) && $real !== '') {
        $path = str_replace('/', '\\', $real);
    }
    if (preg_match('/^[a-z]:\\\\/i', $path)) {
        $path = strtoupper($path[0]) . substr($path, 1);
    }
    return rtrim($path, '\\');
}

function trackTaxonomyFromPath(string $path): array
{
    $path = canonicalPath($path);
    $parts = explode('\\', $path);
    $rootIndex = array_search('LIBRERIA_DEFINITIVA', $parts, true);
    $rootFolder = $rootIndex === false ? '' : (string)($parts[$rootIndex + 1] ?? '');
    $childFolder = $rootIndex === false ? '' : (string)($parts[$rootIndex + 2] ?? '');
    $macroMap = [
        '10_Latin' => 'Latin',
        '20_Urban' => 'Urban',
        '30_Commerciale' => 'Commerciale',
        '40_Rock_PopRock' => 'Rock_PopRock',
        '50_Italiana' => 'Italiana',
        '80_Karaoke' => 'Karaoke',
        '90_Tematiche' => 'Tematiche',
    ];
    $areaMap = [
        '01_INBOX' => 'INBOX',
        '02_DJ_TOOLS' => 'DJ_TOOLS',
        'PLAYLIST' => 'PLAYLIST',
        '80_Karaoke' => 'KARAOKE',
        '90_Tematiche' => 'TEMATICHE',
    ];
    return [
        'archive_area' => $areaMap[$rootFolder] ?? ($rootFolder !== '' ? 'LIBRERIA' : ''),
        'macro_genre' => $macroMap[$rootFolder] ?? '',
        'folder_genre' => $childFolder,
    ];
}

function setting(string $key, ?string $fallback = null): ?string
{
    $statement = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $fallback : (string) $value;
}

function localNetworkIp(): string
{
    if(PHP_OS_FAMILY==='Windows'){
        $command="Get-NetIPConfiguration | Where-Object { \$_.IPv4DefaultGateway -ne \$null } | Sort-Object { (Get-NetIPInterface -InterfaceIndex \$_.InterfaceIndex -AddressFamily IPv4).InterfaceMetric } | ForEach-Object { \$_.IPv4Address.IPAddress } | Where-Object { \$_ -and \$_ -notlike '127.*' -and \$_ -notlike '169.254.*' } | Select-Object -First 1";
        $ip=trim((string)@shell_exec('powershell.exe -NoProfile -Command "'.str_replace('"','\\"',$command).'"'));
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))return $ip;
    }
    $ip=gethostbyname(gethostname());
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)?$ip:'127.0.0.1';
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

function trackTags(array $track): array
{
    $tags = json_decode((string) ($track['tags'] ?? '[]'), true);
    return is_array($tags) ? $tags : [];
}

function autoTrackTags(array $track): array
{
    $tags=json_decode((string)($track['auto_tags']??'[]'),true);$tags=is_array($tags)?$tags:[];
    foreach(autoTagOverrides($track) as $tag=>$enabled){if($enabled&&!in_array($tag,$tags,true))$tags[]=$tag;if(!$enabled)$tags=array_values(array_diff($tags,[$tag]));}
    return array_values(array_unique($tags));
}

function autoTagOverrides(array $track): array
{
    $overrides=json_decode((string)($track['auto_tag_overrides']??'{}'),true);return is_array($overrides)?$overrides:[];
}

function allTrackTags(array $track): array
{
    return array_values(array_unique(array_merge(trackTags($track),autoTrackTags($track))));
}
