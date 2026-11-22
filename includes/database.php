<?php
declare(strict_types=1);

function az_connect_database(array $configuration): PDO
{
    $driver = $configuration['driver'] ?? 'sqlite';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($driver === 'sqlite') {
        $database = new PDO('sqlite:' . AZ_STORAGE . '/interviews.sqlite', null, null, $options);
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('PRAGMA busy_timeout = 10000');
        $database->exec('PRAGMA journal_mode = WAL');
        return $database;
    }
    if ($driver !== 'mysql') {
        throw new RuntimeException('Unsupported database driver.');
    }
    $host = (string)($configuration['host'] ?? 'localhost');
    $name = (string)($configuration['name'] ?? '');
    $port = (int)($configuration['port'] ?? 3306);
    if (strpbrk($host . $name, ";\r\n\0") !== false) {
        throw new RuntimeException('The database host or name is invalid.');
    }
    return new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', (string)($configuration['user'] ?? ''), (string)($configuration['password'] ?? ''), $options);
}

function az_db(): PDO
{
    if (isset($GLOBALS['az_database'])) {
        return $GLOBALS['az_database'];
    }
    $config = az_config();
    if (!isset($config['database'])) {
        throw new RuntimeException('AZHottie Interview Studio is not installed.');
    }
    $GLOBALS['az_database'] = az_connect_database($config['database']);
    az_upgrade_schema($GLOBALS['az_database']);
    if (function_exists('az_create_phone_schema')) { az_create_phone_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_accounts_schema')) { az_upgrade_accounts_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_schedule_schema')) { az_upgrade_schedule_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_publication_schema')) { az_upgrade_publication_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_work_schema')) { az_upgrade_work_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_work_planner_schema') && az_schema_version($GLOBALS['az_database']) >= 8) { az_upgrade_work_planner_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_director_schema') && az_schema_version($GLOBALS['az_database']) >= 9) { az_upgrade_director_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_edit_versions_schema') && az_schema_version($GLOBALS['az_database']) >= 10) { az_upgrade_edit_versions_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_edit_composer_schema') && az_schema_version($GLOBALS['az_database']) >= 11) { az_upgrade_edit_composer_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_ai_schema') && az_schema_version($GLOBALS['az_database']) >= 12) { az_upgrade_ai_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_work_roster_schema') && az_schema_version($GLOBALS['az_database']) >= 13) { az_upgrade_work_roster_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_production_plan_schema') && az_schema_version($GLOBALS['az_database']) >= 14) { az_upgrade_production_plan_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_work_threads_schema') && az_schema_version($GLOBALS['az_database']) >= 15) { az_upgrade_work_threads_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_casting_schema') && az_schema_version($GLOBALS['az_database']) >= 16) { az_upgrade_casting_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_personal_media_schema') && az_schema_version($GLOBALS['az_database']) >= 17) { az_upgrade_personal_media_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_personal_edits_schema') && az_schema_version($GLOBALS['az_database']) >= 18) { az_upgrade_personal_edits_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_personal_phone_schema') && az_schema_version($GLOBALS['az_database']) >= 19) { az_upgrade_personal_phone_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_social_schema') && az_schema_version($GLOBALS['az_database']) >= 20) { az_upgrade_social_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_agent_chat_schema') && az_schema_version($GLOBALS['az_database']) >= 21) { az_upgrade_agent_chat_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_live_schema') && az_schema_version($GLOBALS['az_database']) >= 22) { az_upgrade_live_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_live_archive_schema') && az_schema_version($GLOBALS['az_database']) >= 23) { az_upgrade_live_archive_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_live_public_schema') && az_schema_version($GLOBALS['az_database']) >= 24) { az_upgrade_live_public_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_model_videos_schema') && az_schema_version($GLOBALS['az_database']) >= 25) { az_upgrade_model_videos_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_member_dashboard_schema') && az_schema_version($GLOBALS['az_database']) >= 26) { az_upgrade_member_dashboard_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_member_engagement_schema') && az_schema_version($GLOBALS['az_database']) >= 27) { az_upgrade_member_engagement_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_agent_workspace_schema') && az_schema_version($GLOBALS['az_database']) >= 28) { az_upgrade_agent_workspace_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_agent_followthrough_schema') && az_schema_version($GLOBALS['az_database']) >= 29) { az_upgrade_agent_followthrough_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_live_agent_schema') && az_schema_version($GLOBALS['az_database']) >= 30) { az_upgrade_live_agent_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_agent_casting_schema') && az_schema_version($GLOBALS['az_database']) >= 31) { az_upgrade_agent_casting_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_agent_scheduling_schema') && az_schema_version($GLOBALS['az_database']) >= 32) { az_upgrade_agent_scheduling_schema($GLOBALS['az_database']); }
    if (function_exists('az_upgrade_agent_video_schema') && az_schema_version($GLOBALS['az_database']) >= 33) { az_upgrade_agent_video_schema($GLOBALS['az_database']); }
    return $GLOBALS['az_database'];
}

function az_create_schema(PDO $db): void
{
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $userId = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ownerId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $longText = $mysql ? 'LONGTEXT' : 'TEXT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (id $userId, name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at VARCHAR(32) NOT NULL, last_login_at VARCHAR(32) NULL)$suffix",
        "CREATE TABLE IF NOT EXISTS interviews (id CHAR(32) PRIMARY KEY, owner_id $ownerId NOT NULL, title VARCHAR(200) NOT NULL, participant_name VARCHAR(200) NOT NULL, notes TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', state_json $longText NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, archived_at VARCHAR(32) NULL, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
        "CREATE TABLE IF NOT EXISTS takes (id CHAR(32) PRIMARY KEY, interview_id CHAR(32) NOT NULL, owner_id $ownerId NOT NULL, question_id VARCHAR(160) NOT NULL, question_text TEXT NOT NULL, label VARCHAR(200) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_name VARCHAR(250) NOT NULL, storage_name VARCHAR(80) NOT NULL UNIQUE, size_bytes BIGINT NOT NULL, duration_ms BIGINT NOT NULL, answer_start_ms BIGINT NULL, is_private INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
        "CREATE TABLE IF NOT EXISTS uploads (id CHAR(32) PRIMARY KEY, interview_id CHAR(32) NOT NULL, owner_id $ownerId NOT NULL, question_id VARCHAR(160) NOT NULL, question_text TEXT NOT NULL, label VARCHAR(200) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_name VARCHAR(250) NOT NULL, total_bytes BIGINT NOT NULL, received_bytes BIGINT NOT NULL DEFAULT 0, next_index INTEGER NOT NULL DEFAULT 0, chunk_bytes INTEGER NOT NULL, duration_ms BIGINT NOT NULL, answer_start_ms BIGINT NULL, is_private INTEGER NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending', take_id CHAR(32) NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
        "CREATE TABLE IF NOT EXISTS upload_chunks (upload_id CHAR(32) NOT NULL, chunk_index INTEGER NOT NULL, size_bytes INTEGER NOT NULL, sha256 CHAR(64) NOT NULL, PRIMARY KEY (upload_id, chunk_index), FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS auth_attempts (attempt_key CHAR(64) PRIMARY KEY, attempt_count INTEGER NOT NULL, window_started BIGINT NOT NULL)$suffix",
    ];
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    // Portable index creation; MySQL does not support CREATE INDEX IF NOT EXISTS.
    foreach ([['interviews_owner_status', 'interviews', 'owner_id, status, updated_at'], ['takes_interview', 'takes', 'interview_id, owner_id'], ['uploads_owner', 'uploads', 'owner_id, status']] as [$name, $table, $columns]) {
        if ($mysql) {
            $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $statement->execute([$table, $name]);
            if ((int)$statement->fetchColumn() > 0) {
                continue;
            }
            $db->exec("CREATE INDEX $name ON $table ($columns)");
        } else {
            $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
        }
    }
    az_upgrade_schema($db);
    if (function_exists('az_create_phone_schema')) { az_create_phone_schema($db); }
    if (function_exists('az_upgrade_accounts_schema')) { az_upgrade_accounts_schema($db); }
    if (function_exists('az_upgrade_schedule_schema')) { az_upgrade_schedule_schema($db); }
    if (function_exists('az_upgrade_publication_schema')) { az_upgrade_publication_schema($db); }
    if (function_exists('az_upgrade_work_schema')) { az_upgrade_work_schema($db); }
    if (function_exists('az_upgrade_work_planner_schema') && az_schema_version($db) >= 8) { az_upgrade_work_planner_schema($db); }
    if (function_exists('az_upgrade_director_schema') && az_schema_version($db) >= 9) { az_upgrade_director_schema($db); }
    if (function_exists('az_upgrade_edit_versions_schema') && az_schema_version($db) >= 10) { az_upgrade_edit_versions_schema($db); }
    if (function_exists('az_upgrade_edit_composer_schema') && az_schema_version($db) >= 11) { az_upgrade_edit_composer_schema($db); }
    if (function_exists('az_upgrade_ai_schema') && az_schema_version($db) >= 12) { az_upgrade_ai_schema($db); }
    if (function_exists('az_upgrade_work_roster_schema') && az_schema_version($db) >= 13) { az_upgrade_work_roster_schema($db); }
    if (function_exists('az_upgrade_production_plan_schema') && az_schema_version($db) >= 14) { az_upgrade_production_plan_schema($db); }
    if (function_exists('az_upgrade_work_threads_schema') && az_schema_version($db) >= 15) { az_upgrade_work_threads_schema($db); }
    if (function_exists('az_upgrade_casting_schema') && az_schema_version($db) >= 16) { az_upgrade_casting_schema($db); }
    if (function_exists('az_upgrade_personal_media_schema') && az_schema_version($db) >= 17) { az_upgrade_personal_media_schema($db); }
    if (function_exists('az_upgrade_personal_edits_schema') && az_schema_version($db) >= 18) { az_upgrade_personal_edits_schema($db); }
    if (function_exists('az_upgrade_personal_phone_schema') && az_schema_version($db) >= 19) { az_upgrade_personal_phone_schema($db); }
    if (function_exists('az_upgrade_social_schema') && az_schema_version($db) >= 20) { az_upgrade_social_schema($db); }
    if (function_exists('az_upgrade_agent_chat_schema') && az_schema_version($db) >= 21) { az_upgrade_agent_chat_schema($db); }
    if (function_exists('az_upgrade_live_schema') && az_schema_version($db) >= 22) { az_upgrade_live_schema($db); }
    if (function_exists('az_upgrade_live_archive_schema') && az_schema_version($db) >= 23) { az_upgrade_live_archive_schema($db); }
    if (function_exists('az_upgrade_live_public_schema') && az_schema_version($db) >= 24) { az_upgrade_live_public_schema($db); }
    if (function_exists('az_upgrade_model_videos_schema') && az_schema_version($db) >= 25) { az_upgrade_model_videos_schema($db); }
    if (function_exists('az_upgrade_member_dashboard_schema') && az_schema_version($db) >= 26) { az_upgrade_member_dashboard_schema($db); }
    if (function_exists('az_upgrade_member_engagement_schema') && az_schema_version($db) >= 27) { az_upgrade_member_engagement_schema($db); }
    if (function_exists('az_upgrade_agent_workspace_schema') && az_schema_version($db) >= 28) { az_upgrade_agent_workspace_schema($db); }
    if (function_exists('az_upgrade_agent_followthrough_schema') && az_schema_version($db) >= 29) { az_upgrade_agent_followthrough_schema($db); }
    if (function_exists('az_upgrade_live_agent_schema') && az_schema_version($db) >= 30) { az_upgrade_live_agent_schema($db); }
    if (function_exists('az_upgrade_agent_casting_schema') && az_schema_version($db) >= 31) { az_upgrade_agent_casting_schema($db); }
    if (function_exists('az_upgrade_agent_scheduling_schema') && az_schema_version($db) >= 32) { az_upgrade_agent_scheduling_schema($db); }
    if (function_exists('az_upgrade_agent_video_schema') && az_schema_version($db) >= 33) { az_upgrade_agent_video_schema($db); }
}



function az_schema_version(PDO $db): int
{
    try {
        return (int)$db->query('SELECT version FROM studio_schema WHERE id = 1')->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

function az_upgrade_schema(PDO $db): void
{
    if (az_schema_version($db) >= 2) {
        return;
    }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the studio database upgrade.');
    }
    try {
        if (az_schema_version($db) >= 2) {
            return;
        }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $ownerId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $longText = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        // Each step can be safely resumed after interruption; MySQL DDL implicitly commits.
        $db->exec("CREATE TABLE IF NOT EXISTS studio_schema (id INTEGER PRIMARY KEY, version INTEGER NOT NULL)$suffix");
        $additions = [
            'interviews' => [
                'model_id' => 'CHAR(32) NULL',
                'questionnaire_id' => 'CHAR(32) NULL',
                'questionnaire_snapshot_json' => "$longText NULL",
                'export_sequence' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'takes' => [
                'is_favorite' => 'INTEGER NOT NULL DEFAULT 0',
                'review_status' => "VARCHAR(20) NOT NULL DEFAULT 'unreviewed'",
            ],
            'uploads' => [
                'purpose' => "VARCHAR(16) NOT NULL DEFAULT 'take'",
                'meta_json' => "$longText NULL",
                'video_id' => 'CHAR(32) NULL',
            ],
        ];
        foreach ($additions as $table => $columns) {
            if ($mysql) {
                $statement = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $statement->execute([$table]);
                $existing = $statement->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $existing = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            }
            foreach ($columns as $name => $definition) {
                if (!in_array($name, $existing, true)) {
                    $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
                }
            }
        }
        $statements = [
            "CREATE TABLE IF NOT EXISTS model_profiles (id CHAR(32) PRIMARY KEY, owner_id $ownerId NOT NULL, stage_name VARCHAR(200) NOT NULL, public_bio TEXT NOT NULL, social_handles TEXT NOT NULL, interests TEXT NOT NULL, availability TEXT NOT NULL, private_notes $longText NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', photo_storage_name VARCHAR(80) NULL, photo_mime_type VARCHAR(80) NULL, photo_file_name VARCHAR(250) NULL, revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS questionnaires (id CHAR(32) PRIMARY KEY, owner_id $ownerId NOT NULL, name VARCHAR(200) NOT NULL, description TEXT NOT NULL, questions_json $longText NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS take_notes (id CHAR(32) PRIMARY KEY, take_id CHAR(32) NOT NULL, owner_id $ownerId NOT NULL, at_ms BIGINT NOT NULL, note_text TEXT NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (take_id) REFERENCES takes(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS finished_videos (id CHAR(32) PRIMARY KEY, interview_id CHAR(32) NOT NULL, owner_id $ownerId NOT NULL, model_id CHAR(32) NULL, participant_name VARCHAR(200) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft', published_url TEXT NOT NULL, version INTEGER NOT NULL, source_edit_json $longText NOT NULL, mime_type VARCHAR(120) NOT NULL, file_name VARCHAR(250) NOT NULL, storage_name VARCHAR(80) NOT NULL UNIQUE, size_bytes BIGINT NOT NULL, duration_ms BIGINT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, archived_at VARCHAR(32) NULL, FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id), UNIQUE (interview_id, version))$suffix",
        ];
        foreach ($statements as $statement) {
            $db->exec($statement);
        }
        foreach ([['profiles_owner_status', 'model_profiles', 'owner_id, status, updated_at'], ['questionnaires_owner_status', 'questionnaires', 'owner_id, status, updated_at'], ['take_notes_take', 'take_notes', 'take_id, at_ms'], ['videos_owner_status', 'finished_videos', 'owner_id, status, updated_at'], ['videos_interview', 'finished_videos', 'interview_id, owner_id'], ['interviews_model', 'interviews', 'model_id, owner_id']] as [$name, $table, $columns]) {
            if ($mysql) {
                $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
                $statement->execute([$table, $name]);
                if (!(int)$statement->fetchColumn()) {
                    $db->exec("CREATE INDEX $name ON $table ($columns)");
                }
            } else {
                $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
            }
        }
        $db->exec($mysql ? 'INSERT INTO studio_schema (id, version) VALUES (1, 2) ON DUPLICATE KEY UPDATE version = 2' : 'INSERT INTO studio_schema (id, version) VALUES (1, 2) ON CONFLICT(id) DO UPDATE SET version = 2');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
