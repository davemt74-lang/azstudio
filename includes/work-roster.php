<?php
declare(strict_types=1);

/** Production membership shares the existing community mutation lock. */
function az_upgrade_work_roster_schema(PDO $db): void
{
    if (az_schema_version($db) >= 14) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the production roster upgrade.'); }
    try {
        if (az_schema_version($db) >= 14) { return; }
        if (az_schema_version($db) < 13) { throw new RuntimeException('AI settings must be upgraded before production rosters.'); }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS production_rosters (project_id CHAR(32) PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS production_members (project_id CHAR(32) NOT NULL, user_id $uid NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (project_id,user_id), FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id))$suffix");
        if ($mysql) {
            $q = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $q->execute(['work_notifications']); $columns = $q->fetchAll(PDO::FETCH_COLUMN);
        } else { $columns = array_column($db->query('PRAGMA table_info(work_notifications)')->fetchAll(), 'name'); }
        if (!in_array('shot_id', $columns, true)) { $db->exec('ALTER TABLE work_notifications ADD COLUMN shot_id CHAR(32) NULL'); }
        if ($mysql) {
            $q = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute(['production_members', 'production_members_user']);
            if (!(int)$q->fetchColumn()) { $db->exec('CREATE INDEX production_members_user ON production_members(user_id,status,project_id)'); }
        } else { $db->exec('CREATE INDEX IF NOT EXISTS production_members_user ON production_members(user_id,status,project_id)'); }
        $db->beginTransaction();
        try {
            foreach ($db->query('SELECT id,owner_id,updated_at FROM production_projects')->fetchAll() as $project) {
                $q = $db->prepare('SELECT project_id FROM production_rosters WHERE project_id=?'); $q->execute([$project['id']]);
                if (!$q->fetchColumn()) { $db->prepare('INSERT INTO production_rosters(project_id,revision,updated_at) VALUES(?,1,?)')->execute([$project['id'], $project['updated_at']]); }
                $q = $db->prepare("SELECT DISTINCT a.user_id FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id WHERE s.project_id=? AND a.user_id<>? AND a.status NOT IN ('declined','cancelled')");
                $q->execute([$project['id'], $project['owner_id']]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                    $exists = $db->prepare('SELECT user_id FROM production_members WHERE project_id=? AND user_id=?'); $exists->execute([$project['id'], $userId]);
                    // Existing removed rows win during replay; never restore removed access.
                    if (!$exists->fetchColumn()) { $db->prepare("INSERT INTO production_members(project_id,user_id,status,created_at,updated_at) VALUES(?,?,'active',?,?)")->execute([$project['id'], $userId, $project['updated_at'], $project['updated_at']]); }
                }
            }
            $db->exec('UPDATE studio_schema SET version=14 WHERE id=1');
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_work_roster_initialize(string $projectId): void
{
    az_work_query('INSERT INTO production_rosters(project_id,revision,updated_at) VALUES(?,1,?)', [$projectId, az_now()]);
}

function az_work_roster_member(string $projectId, int $userId): bool
{
    return (bool)az_work_query("SELECT user_id FROM production_members WHERE project_id=? AND user_id=? AND status='active'", [$projectId, $userId])->fetchColumn();
}

function az_work_roster_row(string $projectId): array
{
    $row = az_work_query('SELECT * FROM production_rosters WHERE project_id=?', [$projectId])->fetch();
    if (!$row) { throw new AzHttpError(503, 'This production roster is unavailable. Reload the studio after its database upgrade completes.', 'roster_unavailable'); }
    return $row;
}

function az_work_roster_contributor(array $user): bool
{
    return $user['status'] === 'active' && (bool)array_intersect($user['roles'] ?? [], ['model', 'professional']);
}

function az_work_roster_data(string $projectId, array $user): array
{
    az_work_assert_admin($user);
    $project = az_work_project_row($projectId, $user, true); $roster = az_work_roster_row($projectId); $members = []; $memberIds = [];
    foreach (az_work_query("SELECT user_id FROM production_members WHERE project_id=? AND status='active' ORDER BY created_at,user_id", [$projectId])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $member = az_account_user((int)$id); if (!$member) { continue; }
        $counts = az_work_query("SELECT COUNT(*) AS total,SUM(CASE WHEN a.status NOT IN ('declined','cancelled','approved') THEN 1 ELSE 0 END) AS active_count FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id WHERE s.project_id=? AND a.user_id=?", [$projectId, $id])->fetch();
        $memberIds[(int)$id] = true;
        $members[] = ['userId' => (int)$id, 'name' => $member['name'], 'roles' => $member['roles'], 'accountStatus' => $member['status'], 'eligible' => az_work_roster_contributor($member), 'assignmentCount' => (int)$counts['total'], 'activeAssignmentCount' => (int)$counts['active_count']];
    }
    usort($members, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['userId'] <=> $b['userId']);
    $candidates = array_values(array_filter(az_work_users($user), static fn(array $candidate): bool => (int)$candidate['id'] !== (int)$project['owner_id'] && !isset($memberIds[(int)$candidate['id']])));
    return ['id' => $projectId, 'projectId' => $projectId, 'revision' => (int)$roster['revision'], 'members' => $members, 'candidates' => $candidates, 'canManage' => true, 'canEdit' => in_array($project['status'], ['draft', 'active'], true), 'maxMembers' => 200];
}

function az_work_roster_edit(array $body, array $user): array
{
    az_work_assert_admin($user); $project = az_work_project_row(az_work_id($body['projectId'] ?? null), $user, true);
    if (!in_array($project['status'], ['draft', 'active'], true)) { throw new AzHttpError(409, 'Reopen this production before changing its models.', 'work_closed'); }
    $roster = az_work_roster_row($project['id']);
    if (az_integer($body['revision'] ?? -1, 1, PHP_INT_MAX, 'Roster revision') !== (int)$roster['revision']) { throw new AzHttpError(409, 'The production models changed. Reload the roster before saving.', 'revision_conflict'); }
    return [$project, $roster];
}

function az_work_roster_changed(string $projectId, int $revision): void
{
    $q = az_work_query('UPDATE production_rosters SET revision=revision+1,updated_at=? WHERE project_id=? AND revision=?', [az_now(), $projectId, $revision]);
    if ($q->rowCount() !== 1) { throw new AzHttpError(409, 'The production models changed. Reload the roster before saving.', 'revision_conflict'); }
    az_work_query('UPDATE production_projects SET updated_at=? WHERE id=?', [az_now(), $projectId]);
}

function az_work_add_members(array $body, array $user): array
{
    [$project, $roster] = az_work_roster_edit($body, $user); $ids = $body['userIds'] ?? null;
    if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 200) { throw new AzHttpError(400, 'Choose between 1 and 200 models or professionals.', 'invalid_members'); }
    $add = [];
    foreach ($ids as $id) {
        $id = az_integer($id, 1, PHP_INT_MAX, 'Model account'); $member = az_account_user($id);
        if (!$member || !az_work_roster_contributor($member) || $id === (int)$project['owner_id']) { throw new AzHttpError(409, 'Choose active accounts with an approved model or professional role. The producer already has studio access.', 'contributor_unavailable'); }
        if (!az_work_roster_member($project['id'], $id)) { $add[$id] = $member; }
    }
    $count = (int)az_work_query("SELECT COUNT(*) FROM production_members WHERE project_id=? AND status='active'", [$project['id']])->fetchColumn();
    if ($add && $count + count($add) > 200) { throw new AzHttpError(409, 'A production can include up to 200 models and professionals.', 'roster_limit'); }
    if ($add) {
        az_work_roster_changed($project['id'], (int)$roster['revision']);
        foreach ($add as $id => $member) {
            $old = az_work_query('SELECT status FROM production_members WHERE project_id=? AND user_id=?', [$project['id'], $id])->fetchColumn();
            if ($old !== false) { az_work_query("UPDATE production_members SET status='active',updated_at=? WHERE project_id=? AND user_id=?", [az_now(), $project['id'], $id]); }
            else { az_work_query("INSERT INTO production_members(project_id,user_id,status,created_at,updated_at) VALUES(?,?,'active',?,?)", [$project['id'], $id, az_now(), az_now()]); }
            az_work_event($project['id'], (int)$user['id'], 'production_member_added', $member['name'] . ' added to the production.');
            if ($project['status'] === 'active') { az_work_notify($id, 'production_member_added', 'Added to a production', $project['title'] . ' is available in My work. Review its open shots and any assignments for you.', $project['id']); }
        }
    }
    return az_work_roster_data($project['id'], $user);
}

function az_work_remove_member(array $body, array $user): array
{
    [$project, $roster] = az_work_roster_edit($body, $user); $id = az_integer($body['userId'] ?? null, 1, PHP_INT_MAX, 'Model account');
    if (az_work_roster_member($project['id'], $id)) {
        az_work_roster_changed($project['id'], (int)$roster['revision']);
        az_work_query("UPDATE production_members SET status='removed',updated_at=? WHERE project_id=? AND user_id=?", [az_now(), $project['id'], $id]);
        $member = az_account_user($id);
        az_work_event($project['id'], (int)$user['id'], 'production_member_removed', ($member['name'] ?? 'Contributor') . ' removed from the production roster. Existing assignments are retained.');
    }
    return az_work_roster_data($project['id'], $user);
}

/** Only current, active, eligible roster members receive new open-shot updates. */
function az_work_roster_recipients(string $projectId, ?array $shot = null): array
{
    $out = [];
    foreach (az_work_query("SELECT user_id FROM production_members WHERE project_id=? AND status='active'", [$projectId])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $member = az_account_user((int)$id);
        if ($member && az_work_roster_contributor($member) && (!$shot || az_work_eligible($shot, $member))) { $out[] = (int)$id; }
    }
    return $out;
}
