<?php
declare(strict_types=1);

function az_upgrade_publication_schema(PDO $db): void
{
    // Accounts and scheduling finish first; partial development copies cannot
    // advance past a migration whose source has not yet been installed.
    if (az_schema_version($db) < 5 || az_schema_version($db) >= 7) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the publication upgrade.'); }
    try {
        if (az_schema_version($db) >= 7) { return; }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $statement = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $statement->execute(['finished_videos']); $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
        } else { $columns = array_column($db->query('PRAGMA table_info(finished_videos)')->fetchAll(), 'name'); }
        foreach (['fan_published_at' => 'VARCHAR(32) NULL', 'fan_updated_at' => 'VARCHAR(32) NULL', 'fan_title' => 'VARCHAR(200) NULL', 'fan_description' => 'TEXT NULL', 'fan_credit' => 'VARCHAR(200) NULL',
            'cover_storage_name' => 'VARCHAR(80) NULL', 'cover_mime_type' => 'VARCHAR(80) NULL', 'cover_file_name' => 'VARCHAR(250) NULL'] as $column => $definition) {
            if (!in_array($column, $columns, true)) { $db->exec("ALTER TABLE finished_videos ADD COLUMN $column $definition"); }
        }
        $db->exec('UPDATE studio_schema SET version = 7 WHERE id = 1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_publication_fields(array $row): array
{
    $published = !empty($row['fan_published_at']);
    return ['fanPublished' => $published, 'fanPublishedAt' => $row['fan_published_at'] ?? null,
        'fanUpdatedAt' => $row['fan_updated_at'] ?? null,
        'fanWatchUrl' => $published ? az_url('watch.php?id=' . $row['id']) : null,
        'fanTitle' => $row['fan_title'] ?? null, 'fanDescription' => $row['fan_description'] ?? null,
        'fanCredit' => $row['fan_credit'] ?? null];
}

function az_video_cover_media(array $row): array
{
    if (empty($row['cover_storage_name'])) { throw new AzHttpError(404, 'This video has no cover image.', 'media_missing'); }
    return ['storage_name' => $row['cover_storage_name'], 'mime_type' => $row['cover_mime_type'], 'file_name' => $row['cover_file_name']];
}

function az_video_media_files(array $row): array
{
    return empty($row['cover_storage_name']) ? [$row] : [$row, az_video_cover_media($row)];
}

function az_video_cover_revision(array $body, array $row): void
{
    az_integer($body['revision'] ?? null, 1, PHP_INT_MAX, 'Revision');
    az_revision_check($body, $row, 'This finished video');
}

function az_upload_video_cover(array $body, int $ownerId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Finished video ID');
    $row = az_video_row($id, $ownerId);
    az_video_cover_revision($body, $row);
    $file = az_uploaded_file('cover');
    $size = filesize($file['tmp_name']);
    if ($size === false || $size < 1 || $size > 5242880) {
        throw new AzHttpError(413, 'Choose a cover image up to 5 MB.', 'cover_too_large');
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40000000) {
        throw new AzHttpError(415, 'Choose a JPG, PNG, or WebP image up to 12,000 pixels per side and 40 megapixels.', 'unsupported_cover');
    }
    $storageName = az_id() . '.bin';
    $path = az_media_path(['storage_name' => $storageName]);
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new AzHttpError(507, 'The server could not save this cover image.', 'storage_unavailable');
    }
    @chmod($path, 0640);
    try {
        $now = az_now();
        $statement = az_db()->prepare('UPDATE finished_videos SET cover_storage_name = ?, cover_mime_type = ?, cover_file_name = ?, fan_updated_at = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
        $statement->execute([$storageName, $mime, 'video-cover', $row['fan_published_at'] ? $now : null, $now, $id, $ownerId]);
    } catch (Throwable $exception) {
        @unlink($path);
        throw $exception;
    }
    if (!empty($row['cover_storage_name'])) { @unlink(az_media_path(az_video_cover_media($row))); }
    return az_video_data(az_video_row($id, $ownerId));
}

function az_remove_video_cover(array $body, int $ownerId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Finished video ID');
    $row = az_video_row($id, $ownerId);
    az_video_cover_revision($body, $row);
    if (empty($row['cover_storage_name'])) { return az_video_data($row); }
    az_remove_recordings([az_video_cover_media($row)], [], static function (PDO $db) use ($id, $ownerId, $row): void {
        $now = az_now();
        $statement = $db->prepare('UPDATE finished_videos SET cover_storage_name = NULL, cover_mime_type = NULL, cover_file_name = NULL, fan_updated_at = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
        $statement->execute([$row['fan_published_at'] ? $now : null, $now, $id, $ownerId]);
    });
    return az_video_data(az_video_row($id, $ownerId));
}

function az_set_video_publication(array $body, int $ownerId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Finished video ID');
    $row = az_video_row($id, $ownerId);
    az_revision_check($body, $row, 'This finished video');
    $publish = az_bool($body['published'] ?? false, 'Published to fans');
    if ($publish) {
        $interview = az_interview_row($row['interview_id'], $ownerId);
        if ($row['archived_at'] || $interview['status'] !== 'active') {
            throw new AzHttpError(409, 'Restore the interview and finished video before publishing.', 'video_archived');
        }
        if (!empty($interview['project_id']) && function_exists('az_work_validate_export')) {
            az_work_validate_export($interview, json_decode($row['source_edit_json'], true, 64, JSON_THROW_ON_ERROR), az_account_user($ownerId));
        }
        if (!str_starts_with($row['mime_type'], 'video/')) {
            throw new AzHttpError(400, 'The fan feed accepts finished video files.', 'video_required');
        }
        if (!is_file(az_media_path($row))) { throw new AzHttpError(409, 'The finished video file is unavailable.', 'media_missing'); }
    }
    $now = az_now();
    $statement = az_db()->prepare('UPDATE finished_videos SET fan_published_at = ?, fan_updated_at = ?, fan_title = ?, fan_description = ?, fan_credit = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
    $statement->execute([$publish ? ($row['fan_published_at'] ?: $now) : null, $publish ? $now : null,
        $publish ? $row['title'] : null, $publish ? $row['description'] : null,
        $publish ? $row['participant_name'] : null, $now, $id, $ownerId]);
    return az_video_data(az_video_row($id, $ownerId));
}

function az_unpublish_interview(string $interviewId): void
{
    if (az_schema_version(az_db()) < 6) { return; }
    $statement = az_db()->prepare('UPDATE finished_videos SET fan_published_at = NULL, fan_updated_at = NULL, fan_title = NULL, fan_description = NULL, fan_credit = NULL, revision = revision + 1 WHERE interview_id = ? AND fan_published_at IS NOT NULL');
    $statement->execute([$interviewId]);
}

function az_public_video_conditions(): string
{
    return "v.fan_published_at IS NOT NULL AND v.archived_at IS NULL AND i.status = 'active' AND u.status = 'active'";
}

function az_public_video_row(string $id): array
{
    if (!az_valid_id($id)) { throw new AzHttpError(404, 'This video is not available.', 'not_found'); }
    $statement = az_db()->prepare('SELECT v.* FROM finished_videos v JOIN interviews i ON i.id = v.interview_id JOIN users u ON u.id = v.owner_id WHERE v.id = ? AND ' . az_public_video_conditions());
    $statement->execute([$id]); $row = $statement->fetch();
    if (!$row) { throw new AzHttpError(404, 'This video is not available.', 'not_found'); }
    return $row;
}

function az_public_video_data(array $row): array
{
    // Deliberate allowlist: public readers receive no source edit, owner ID,
    // interview ID, original filename, casting notes or private profile fields.
    return ['id' => $row['id'], 'title' => $row['fan_title'], 'description' => $row['fan_description'],
        'modelName' => $row['fan_credit'], 'publishedAt' => $row['fan_published_at'], 'updatedAt' => $row['fan_updated_at'],
        'durationMs' => (int)$row['duration_ms'], 'mimeType' => $row['mime_type'],
        'watchUrl' => az_url('watch.php?id=' . $row['id']), 'mediaUrl' => az_url('public-media.php?id=' . $row['id']),
        'coverUrl' => empty($row['cover_storage_name']) ? null : az_url('public-media.php?type=cover&id=' . $row['id'] . '&v=' . $row['revision'])];
}

function az_public_feed(array $query): array
{
    $search = az_text($query['search'] ?? '', 120, 'Search');
    $page = isset($query['page']) ? az_integer($query['page'], 1, 100000, 'Page') : 1;
    $where = az_public_video_conditions(); $params = [];
    if ($search !== '') { $where .= ' AND (v.fan_title LIKE ? OR v.fan_description LIKE ? OR v.fan_credit LIKE ?)'; $params = array_fill(0, 3, '%' . $search . '%'); }
    $base = ' FROM finished_videos v JOIN interviews i ON i.id = v.interview_id JOIN users u ON u.id = v.owner_id WHERE ' . $where;
    $count = az_db()->prepare('SELECT COUNT(*)' . $base); $count->execute($params); $total = (int)$count->fetchColumn();
    $offset = ($page - 1) * 24;
    $statement = az_db()->prepare('SELECT v.*' . $base . ' ORDER BY v.fan_published_at DESC, v.id DESC LIMIT 24 OFFSET ' . $offset);
    $statement->execute($params);
    return ['videos' => array_map('az_public_video_data', $statement->fetchAll()), 'page' => $page, 'total' => $total, 'hasMore' => $offset + 24 < $total];
}
