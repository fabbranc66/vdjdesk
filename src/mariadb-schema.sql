CREATE TABLE IF NOT EXISTS tracks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    artist VARCHAR(500) NOT NULL DEFAULT '', title VARCHAR(700) NOT NULL DEFAULT '',
    normalized_artist VARCHAR(500) NOT NULL DEFAULT '', normalized_title VARCHAR(700) NOT NULL DEFAULT '',
    file_path VARCHAR(700) NOT NULL, file_name VARCHAR(500) NOT NULL DEFAULT '', folder VARCHAR(700) NOT NULL DEFAULT '',
    genre VARCHAR(500) NOT NULL DEFAULT '', archive_area VARCHAR(50) NOT NULL DEFAULT '', macro_genre VARCHAR(100) NOT NULL DEFAULT '', folder_genre VARCHAR(150) NOT NULL DEFAULT '',
    genre_manual TINYINT NOT NULL DEFAULT 0, year SMALLINT NULL, year_manual TINYINT NOT NULL DEFAULT 0, bpm DECIMAL(8,3) NULL,
    musical_key VARCHAR(30) NOT NULL DEFAULT '', camelot VARCHAR(10) NOT NULL DEFAULT '', duration INT NULL,
    rating INT NOT NULL DEFAULT 0, play_count INT NOT NULL DEFAULT 0, last_played DATETIME NULL,
    tags LONGTEXT NOT NULL, auto_tags LONGTEXT NULL, auto_tag_overrides LONGTEXT NULL, version VARCHAR(100) NOT NULL DEFAULT '', album VARCHAR(700) NOT NULL DEFAULT '',
    release_date VARCHAR(30) NOT NULL DEFAULT '', spotify_id VARCHAR(100) NOT NULL DEFAULT '', spotify_url VARCHAR(700) NOT NULL DEFAULT '',
    isrc VARCHAR(100) NOT NULL DEFAULT '', popularity INT NULL, metadata_source VARCHAR(50) NOT NULL DEFAULT '', metadata_updated_at DATETIME NULL,
    spotify_energy DECIMAL(6,5) NULL, spotify_danceability DECIMAL(6,5) NULL, spotify_valence DECIMAL(6,5) NULL,
    spotify_acousticness DECIMAL(8,7) NULL, spotify_instrumentalness DECIMAL(8,7) NULL, spotify_speechiness DECIMAL(8,7) NULL,
    spotify_liveness DECIMAL(8,7) NULL, spotify_loudness DECIMAL(7,3) NULL, spotify_tempo DECIMAL(8,3) NULL,
    spotify_key TINYINT NULL, spotify_mode TINYINT NULL, spotify_features_updated_at DATETIME NULL,
    spotify_features_status VARCHAR(20) NOT NULL DEFAULT 'never', spotify_features_checked_at DATETIME NULL,
    spotify_features_error VARCHAR(500) NOT NULL DEFAULT '', spotify_genre VARCHAR(255) NOT NULL DEFAULT '',
    kr_energy TINYINT NULL, kr_singability TINYINT NULL, kr_floor_power TINYINT NULL, kr_familiarity TINYINT NULL,
    kr_risk TINYINT NULL, kr_peak TINYINT NULL, kr_recovery TINYINT NULL,
    energy TINYINT NOT NULL DEFAULT 3, singability TINYINT NOT NULL DEFAULT 3, danceability TINYINT NOT NULL DEFAULT 3,
    familiarity TINYINT NOT NULL DEFAULT 3, risk TINYINT NOT NULL DEFAULT 3, dj_scores_manual TINYINT NOT NULL DEFAULT 0,
    bitrate INT NULL, file_size BIGINT NULL,
    file_exists TINYINT NOT NULL DEFAULT 1, source VARCHAR(50) NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tracks_file_path (file_path),
    KEY idx_tracks_normalized (normalized_artist, normalized_title(191)), KEY idx_tracks_bpm (bpm), KEY idx_tracks_genre (genre(191)),
    KEY idx_tracks_taxonomy (archive_area, macro_genre, folder_genre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, track_id BIGINT UNSIGNED NOT NULL,
    played_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, session_date DATE NOT NULL,
    KEY idx_history_track (track_id), CONSTRAINT fk_history_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, guest_name VARCHAR(255) NOT NULL DEFAULT '', query VARCHAR(700) NOT NULL,
    track_id BIGINT UNSIGNED NULL, status VARCHAR(30) NOT NULL DEFAULT 'new', note VARCHAR(1000) NOT NULL DEFAULT '',
    public_token CHAR(36) NULL, client_token VARCHAR(80) NOT NULL DEFAULT '', client_ip VARCHAR(64) NOT NULL DEFAULT '',
    estimated_play_at DATETIME NULL, estimated_wait_minutes INT NULL, queue_position INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_requests_track (track_id), KEY idx_requests_public_token(public_token),
    CONSTRAINT fk_requests_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_token CHAR(36) NOT NULL UNIQUE,
    display_name VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, is_online TINYINT NOT NULL DEFAULT 1,
    left_at DATETIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', rejoin_requested_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, track_id BIGINT UNSIGNED NULL,
    question_text VARCHAR(500) NOT NULL, option_a VARCHAR(255) NOT NULL, option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL, option_d VARCHAR(255) NOT NULL, correct_option CHAR(1) NOT NULL,
    duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 20, status VARCHAR(20) NOT NULL DEFAULT 'draft',
    opened_at DATETIME NULL, closes_at DATETIME NULL, revealed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_quiz_questions_status(status),
    CONSTRAINT fk_quiz_question_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL, selected_option CHAR(1) NOT NULL,
    is_correct TINYINT NOT NULL DEFAULT 0, response_ms INT UNSIGNED NOT NULL DEFAULT 0,
    points INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_answer(question_id,participant_id), KEY idx_quiz_answers_participant(participant_id),
    CONSTRAINT fk_quiz_answer_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answer_participant FOREIGN KEY(participant_id) REFERENCES quiz_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, track_id BIGINT UNSIGNED NOT NULL, source VARCHAR(30) NOT NULL DEFAULT 'dj',
    position INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_queue_track (track_id), CONSTRAINT fk_queue_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(100) PRIMARY KEY, value LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS duplicate_decisions (
    fingerprint VARCHAR(100) PRIMARY KEY, decision VARCHAR(50) NOT NULL, note VARCHAR(1000) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS library_databases (
    path VARCHAR(700) PRIMARY KEY, label VARCHAR(255) NOT NULL, drive_letter VARCHAR(5) NOT NULL DEFAULT '', file_modified_at BIGINT NOT NULL DEFAULT 0,
    file_size BIGINT NOT NULL DEFAULT 0, imported_modified_at BIGINT NOT NULL DEFAULT 0, imported_size BIGINT NOT NULL DEFAULT 0,
    record_count INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'pending', message VARCHAR(1000) NOT NULL DEFAULT '',
    last_synced_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS track_sources (
    track_id BIGINT UNSIGNED NOT NULL, database_path VARCHAR(350) NOT NULL, source_file_path VARCHAR(350) NOT NULL,
    sync_token VARCHAR(100) NOT NULL, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(database_path, source_file_path), KEY idx_track_sources_track(track_id),
    CONSTRAINT fk_track_sources_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE,
    CONSTRAINT fk_track_sources_database FOREIGN KEY(database_path) REFERENCES library_databases(path) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS e_duplicate_scans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, root_path VARCHAR(700) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL, files_scanned INT NOT NULL DEFAULT 0,
    exact_groups INT NOT NULL DEFAULT 0, normalized_groups INT NOT NULL DEFAULT 0, message VARCHAR(1000) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS e_file_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scan_id BIGINT UNSIGNED NOT NULL, file_path VARCHAR(700) NOT NULL,
    file_name VARCHAR(500) NOT NULL, folder VARCHAR(700) NOT NULL, file_size BIGINT NOT NULL DEFAULT 0, modified_at BIGINT NOT NULL DEFAULT 0,
    extension VARCHAR(20) NOT NULL DEFAULT '', artist VARCHAR(500) NOT NULL DEFAULT '', title VARCHAR(700) NOT NULL DEFAULT '',
    normalized_artist VARCHAR(500) NOT NULL DEFAULT '', normalized_title VARCHAR(700) NOT NULL DEFAULT '', version VARCHAR(100) NOT NULL DEFAULT '',
    bitrate INT NULL, rating INT NOT NULL DEFAULT 0, play_count INT NOT NULL DEFAULT 0, genre VARCHAR(255) NOT NULL DEFAULT '',
    has_spotify TINYINT NOT NULL DEFAULT 0, spotify_complete TINYINT NOT NULL DEFAULT 0, content_hash VARCHAR(100) NOT NULL DEFAULT '',
    UNIQUE KEY uq_inventory_scan_path(scan_id,file_path), KEY idx_inventory_normalized(scan_id,normalized_artist,normalized_title(191)), KEY idx_inventory_size(scan_id,file_size),
    CONSTRAINT fk_inventory_scan FOREIGN KEY(scan_id) REFERENCES e_duplicate_scans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS e_duplicate_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scan_id BIGINT UNSIGNED NOT NULL, type VARCHAR(30) NOT NULL, fingerprint VARCHAR(100) NOT NULL,
    label VARCHAR(1000) NOT NULL, confidence INT NOT NULL DEFAULT 0, reason LONGTEXT NOT NULL, recommended_file_id BIGINT UNSIGNED NULL,
    decision VARCHAR(30) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_duplicate_group(scan_id,type,fingerprint),
    CONSTRAINT fk_group_scan FOREIGN KEY(scan_id) REFERENCES e_duplicate_scans(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_recommended FOREIGN KEY(recommended_file_id) REFERENCES e_file_inventory(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS e_duplicate_group_items (
    group_id BIGINT UNSIGNED NOT NULL, file_id BIGINT UNSIGNED NOT NULL, is_recommended TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY(group_id,file_id), CONSTRAINT fk_group_item_group FOREIGN KEY(group_id) REFERENCES e_duplicate_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_item_file FOREIGN KEY(file_id) REFERENCES e_file_inventory(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS deletion_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_path VARCHAR(700) NOT NULL, source_folder VARCHAR(700) NOT NULL,
    source_name VARCHAR(500) NOT NULL, source_size BIGINT NOT NULL DEFAULT 0, e_file_path VARCHAR(700) NOT NULL,
    e_file_name VARCHAR(500) NOT NULL, e_file_size BIGINT NOT NULL DEFAULT 0, match_type VARCHAR(30) NOT NULL,
    confidence INT NOT NULL DEFAULT 0, reason LONGTEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'marked',
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decision_note VARCHAR(1000) NOT NULL DEFAULT '', approved_at DATETIME NULL, last_vdj_search_at DATETIME NULL,
    moved_to_path VARCHAR(700) NULL, moved_at DATETIME NULL, UNIQUE KEY uq_candidate_source(source_path), KEY idx_candidate_folder(source_folder,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
