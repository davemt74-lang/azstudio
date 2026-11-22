<?php
declare(strict_types=1);

/**
 * Installs the historical AZHottie questionnaires as NORMAL, fully editable
 * owner-scoped questionnaire rows. A persistent installation ledger ensures
 * that archived, edited or deliberately deleted presets are NEVER reset.
 */
function az_seed_editable_questionnaires(int $ownerId): void
{
    if ($ownerId <= 0) { return; }
    $db = az_db();
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $ownerType = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS questionnaire_preset_installs (owner_id $ownerType NOT NULL, preset_key VARCHAR(80) NOT NULL, installed_at VARCHAR(32) NOT NULL, PRIMARY KEY(owner_id, preset_key))$suffix");
    $count = $db->prepare('SELECT COUNT(*) FROM questionnaire_preset_installs WHERE owner_id = ?');
    $count->execute([$ownerId]);
    if ((int)$count->fetchColumn() >= 6) { return; }

    $json = file_get_contents(__DIR__ . '/data/azhottie-questionnaires.json');
    if ($json === false) { throw new RuntimeException('The AZHottie questionnaire presets are missing.'); }
    $presets = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($presets) || count($presets) !== 6) { throw new RuntimeException('Invalid AZHottie questionnaire preset pack.'); }

    foreach ($presets as $preset) {
        $key = (string)$preset['key'];
        $name = (string)$preset['name'];
        $questions = az_questions($preset['questions']); // same validation as user-edited templates
        $encoded = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($db->inTransaction()) { throw new RuntimeException('Questionnaire presets require an independent transaction.'); }
        $db->beginTransaction();
        try {
            $now = az_now();
            $mark = $db->prepare($mysql
                ? 'INSERT IGNORE INTO questionnaire_preset_installs (owner_id, preset_key, installed_at) VALUES (?,?,?)'
                : 'INSERT OR IGNORE INTO questionnaire_preset_installs (owner_id, preset_key, installed_at) VALUES (?,?,?)');
            $mark->execute([$ownerId, $key, $now]);
            if ($mark->rowCount() === 1) {
                // Respect existing independently created/editable questionnaires with an identical title.
                $existing = $db->prepare('SELECT id FROM questionnaires WHERE owner_id = ? AND name = ? LIMIT 1');
                $existing->execute([$ownerId, $name]);
                if (!$existing->fetchColumn()) {
                    $insert = $db->prepare('INSERT INTO questionnaires (id, owner_id, name, description, questions_json, status, revision, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
                    $insert->execute([substr(hash('sha256', 'azhottie/presets/v1/' . $ownerId . '/' . $key), 0, 32), $ownerId, $name, (string)$preset['description'], $encoded, 'active', 1, $now, $now]);
                }
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $exception;
        }
    }
}
