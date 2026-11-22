<?php
declare(strict_types=1);

/** Administrator-owned provider credentials and text-only production assistance. */
function az_upgrade_ai_schema(PDO $db): void
{
    if (az_schema_version($db) >= 13) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the AI settings upgrade.'); }
    try {
        if (az_schema_version($db) >= 13) { return; }
        if (az_schema_version($db) < 12) { throw new RuntimeException('The production editor must be upgraded before AI settings.'); }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS ai_settings (owner_id $uid PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, daily_limit INTEGER NOT NULL DEFAULT 20, shot_provider VARCHAR(16) NOT NULL DEFAULT 'openai', edit_provider VARCHAR(16) NOT NULL DEFAULT 'openai', revision INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS ai_provider_settings (owner_id $uid NOT NULL, provider VARCHAR(16) NOT NULL, model_id VARCHAR(120) NOT NULL DEFAULT '', key_ciphertext TEXT NOT NULL, workspace_id VARCHAR(120) NOT NULL DEFAULT '', connection_status VARCHAR(16) NOT NULL DEFAULT 'unknown', connection_message VARCHAR(500) NOT NULL DEFAULT '', checked_at VARCHAR(32) NOT NULL DEFAULT '', PRIMARY KEY (owner_id,provider), FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        // DDL is resumable on MySQL; the credential move and schema marker are atomic.
        $db->beginTransaction();
        try {
            foreach ($db->query('SELECT * FROM work_ai_settings')->fetchAll() as $old) {
                $q = $db->prepare('SELECT owner_id FROM ai_settings WHERE owner_id=?'); $q->execute([$old['owner_id']]);
                if (!$q->fetchColumn()) {
                    $db->prepare('INSERT INTO ai_settings (owner_id,enabled,daily_limit,shot_provider,edit_provider,revision,updated_at) VALUES (?,?,?,?,?,?,?)')->execute([$old['owner_id'], $old['enabled'], $old['daily_limit'], 'openai', 'openai', $old['revision'], $old['updated_at']]);
                }
                $q = $db->prepare("SELECT owner_id FROM ai_provider_settings WHERE owner_id=? AND provider='openai'"); $q->execute([$old['owner_id']]);
                if (!$q->fetchColumn()) {
                    $db->prepare('INSERT INTO ai_provider_settings (owner_id,provider,model_id,key_ciphertext) VALUES (?,?,?,?)')->execute([$old['owner_id'], 'openai', $old['model_id'], $old['key_ciphertext']]);
                }
                // Keep no second credential that survives a later explicit key removal.
                $db->prepare("UPDATE work_ai_settings SET enabled=0,key_ciphertext='' WHERE owner_id=?")->execute([$old['owner_id']]);
            }
            $db->exec('UPDATE studio_schema SET version=13 WHERE id=1');
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_ai_provider(mixed $value): string
{
    return az_enum($value, ['openai', 'anthropic'], 'AI provider');
}

function az_ai_provider_name(string $provider): string
{
    return az_ai_provider($provider) === 'openai' ? 'OpenAI' : 'Claude';
}

function az_ai_settings_row(int $owner): array
{
    $q = az_db()->prepare('SELECT * FROM ai_settings WHERE owner_id=?'); $q->execute([$owner]);
    return $q->fetch() ?: ['owner_id' => $owner, 'enabled' => 0, 'daily_limit' => 20, 'shot_provider' => 'openai', 'edit_provider' => 'openai', 'revision' => 0, 'updated_at' => ''];
}

function az_ai_provider_row(int $owner, string $provider): array
{
    $provider = az_ai_provider($provider);
    $q = az_db()->prepare('SELECT * FROM ai_provider_settings WHERE owner_id=? AND provider=?'); $q->execute([$owner, $provider]);
    return $q->fetch() ?: ['owner_id' => $owner, 'provider' => $provider, 'model_id' => '', 'key_ciphertext' => '', 'workspace_id' => '', 'connection_status' => 'unknown', 'connection_message' => '', 'checked_at' => ''];
}

function az_ai_settings(int $owner): array
{
    $row = az_ai_settings_row($owner); $providers = [];
    foreach (['openai', 'anthropic'] as $provider) {
        $r = az_ai_provider_row($owner, $provider);
        $providers[$provider] = ['hasKey' => $r['key_ciphertext'] !== '', 'model' => $r['model_id'], 'workspaceId' => $r['workspace_id'], 'connection' => ['status' => $r['connection_status'], 'message' => $r['connection_message'], 'checkedAt' => $r['checked_at']]];
    }
    return ['revision' => (int)$row['revision'], 'enabled' => (bool)$row['enabled'], 'dailyLimit' => (int)$row['daily_limit'], 'shotProvider' => $row['shot_provider'], 'editProvider' => $row['edit_provider'], 'providers' => $providers, 'usage' => az_work_ai_usage($owner)];
}

/** Internal only: contains encrypted credentials; never serialize this result to an API. */
function az_ai_task_settings(int $owner, string $task): array
{
    $field = match ($task) { 'shotPlanner' => 'shot_provider', 'editComposer' => 'edit_provider', default => throw new InvalidArgumentException('Unknown assistant task.') };
    $row = az_ai_settings_row($owner); $provider = az_ai_provider_row($owner, $row[$field]);
    return ['enabled' => (bool)$row['enabled'], 'configured' => $provider['key_ciphertext'] !== '' && $provider['model_id'] !== '', 'hasKey' => $provider['key_ciphertext'] !== '', 'provider' => $row[$field], 'model' => $provider['model_id'], 'model_id' => $provider['model_id'], 'key_ciphertext' => $provider['key_ciphertext'], 'workspaceId' => $provider['workspace_id'], 'daily_limit' => (int)$row['daily_limit'], 'dailyLimit' => (int)$row['daily_limit'], 'revision' => (int)$row['revision']];
}

function az_ai_object(mixed $value, string $field): array
{
    if ($value instanceof stdClass) { return get_object_vars($value); }
    if (is_array($value) && ($value === [] || !array_is_list($value))) { return $value; }
    throw new AzHttpError(400, $field . ' must be an object.', 'invalid_field');
}

function az_ai_model(mixed $value): string
{
    $model = az_text($value, 120, 'Model ID');
    if ($model !== '' && !preg_match('/\A[a-zA-Z0-9_.:\/-]+\z/D', $model)) { throw new AzHttpError(400, 'Choose a valid provider model.', 'invalid_model'); }
    return $model;
}

function az_ai_workspace(mixed $value): string
{
    $workspace = az_text($value, 120, 'Claude workspace ID');
    if ($workspace !== '' && !preg_match('/\Awrkspc_[a-zA-Z0-9_-]+\z/D', $workspace)) { throw new AzHttpError(400, 'Enter a Claude workspace ID beginning with wrkspc_, or leave it blank for a workspace-scoped key.', 'invalid_workspace'); }
    return $workspace;
}

/** Called under the community lock. No raw provider credentials enter a response. */
function az_ai_save_settings(array $body, array $user): array
{
    $owner = (int)$user['id']; $old = az_ai_settings_row($owner);
    if (az_integer($body['revision'] ?? -1, 0, PHP_INT_MAX, 'Settings revision') !== (int)$old['revision']) { throw new AzHttpError(409, 'AI settings changed in another window. Reload before saving.', 'revision_conflict'); }
    $enabled = az_bool($body['enabled'] ?? false, 'AI assistance enabled');
    $limit = az_integer($body['dailyLimit'] ?? 20, 1, 100, 'Daily request limit');
    $shot = az_ai_provider($body['shotProvider'] ?? $old['shot_provider']);
    $edit = az_ai_provider($body['editProvider'] ?? $old['edit_provider']);
    $inputs = az_ai_object($body['providers'] ?? [], 'Providers'); $next = [];
    foreach (['openai', 'anthropic'] as $provider) {
        $r = az_ai_provider_row($owner, $provider); $input = az_ai_object($inputs[$provider] ?? [], 'Provider settings');
        $model = az_ai_model($input['model'] ?? $r['model_id']);
        $workspace = $provider === 'anthropic' ? az_ai_workspace($input['workspaceId'] ?? $r['workspace_id']) : '';
        $keyValue = $input['apiKey'] ?? '';
        // Validate before text normalization, so newline/header injection is rejected outright.
        if (!is_string($keyValue) || strlen($keyValue) > 500 || ($keyValue !== '' && preg_match('/[\s\x00-\x1f\x7f]/', $keyValue))) { throw new AzHttpError(400, 'The API key must be text without spaces or line breaks.', 'invalid_key'); }
        $remove = az_bool($input['removeKey'] ?? false, 'Remove API key');
        $cipher = $remove ? '' : ($keyValue !== '' ? az_work_planner_encrypt($keyValue) : $r['key_ciphertext']);
        $changed = $cipher !== $r['key_ciphertext'] || $workspace !== $r['workspace_id'];
        $next[$provider] = $r + [];
        $next[$provider]['model_id'] = $model; $next[$provider]['key_ciphertext'] = $cipher; $next[$provider]['workspace_id'] = $workspace;
        if ($changed) { $next[$provider]['connection_status'] = 'unknown'; $next[$provider]['connection_message'] = ''; $next[$provider]['checked_at'] = ''; }
    }
    if ($enabled) {
        foreach (array_unique([$shot, $edit]) as $provider) {
            if ($next[$provider]['key_ciphertext'] === '' || $next[$provider]['model_id'] === '') { throw new AzHttpError(400, 'Each selected provider needs a saved key and model. Disable AI assistance before removing a selected key, or choose another configured provider.', 'ai_configuration'); }
        }
    }
    $db = az_db(); $db->beginTransaction();
    try {
        if ((int)$old['revision'] === 0) {
            $db->prepare('INSERT INTO ai_settings (owner_id,enabled,daily_limit,shot_provider,edit_provider,revision,updated_at) VALUES (?,?,?,?,?,1,?)')->execute([$owner, $enabled ? 1 : 0, $limit, $shot, $edit, az_now()]);
        } else {
            $q = $db->prepare('UPDATE ai_settings SET enabled=?,daily_limit=?,shot_provider=?,edit_provider=?,revision=revision+1,updated_at=? WHERE owner_id=? AND revision=?');
            $q->execute([$enabled ? 1 : 0, $limit, $shot, $edit, az_now(), $owner, $old['revision']]);
            if ($q->rowCount() !== 1) { throw new AzHttpError(409, 'AI settings changed in another window. Reload before saving.', 'revision_conflict'); }
        }
        foreach ($next as $provider => $r) {
            $db->prepare('DELETE FROM ai_provider_settings WHERE owner_id=? AND provider=?')->execute([$owner, $provider]);
            $db->prepare('INSERT INTO ai_provider_settings (owner_id,provider,model_id,key_ciphertext,workspace_id,connection_status,connection_message,checked_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$owner, $provider, $r['model_id'], $r['key_ciphertext'], $r['workspace_id'], $r['connection_status'], $r['connection_message'], $r['checked_at']]);
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
    return az_ai_settings($owner);
}

/** Keep older installed clients functional while preserving their current task routing. */
function az_ai_save_legacy_settings(array $body, array $user): array
{
    $settings = az_ai_settings((int)$user['id']);
    az_ai_save_settings(['revision' => $body['revision'] ?? -1, 'enabled' => $body['enabled'] ?? false, 'dailyLimit' => $body['dailyLimit'] ?? 20, 'shotProvider' => $settings['shotProvider'], 'editProvider' => $settings['editProvider'], 'providers' => ['openai' => ['model' => $body['model'] ?? '', 'apiKey' => $body['apiKey'] ?? '', 'removeKey' => $body['removeKey'] ?? false]]], $user);
    return az_work_planner_settings((int)$user['id']);
}

function az_ai_request_body(array $job, string $instructions, string $input, array $schema, string $name, int $maxTokens): array
{
    $provider = az_ai_provider($job['provider'] ?? 'openai');
    if ($provider === 'anthropic') {
        return ['model' => $job['model'], 'max_tokens' => $maxTokens, 'system' => $instructions, 'messages' => [['role' => 'user', 'content' => $input]], 'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]]];
    }
    return ['model' => $job['model'], 'store' => false, 'max_output_tokens' => $maxTokens, 'instructions' => $instructions, 'input' => $input, 'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'strict' => true, 'schema' => $schema]]];
}

/** Fixed provider endpoints only. Never log transport contexts, keys or provider errors. */
function az_ai_http(string $provider, string $key, string $method, string $path, ?array $body = null, string $workspaceId = ''): array
{
    $provider = az_ai_provider($provider); $name = az_ai_provider_name($provider);
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) { throw new AzHttpError(503, 'Your host must allow outbound HTTPS requests for AI assistance.', 'ai_transport'); }
    if ($key === '' || strlen($key) > 500 || preg_match('/[\s\x00-\x1f\x7f]/', $key)) { throw new AzHttpError(503, 'Save the provider API key again in Admin → AI settings.', 'ai_configuration'); }
    $workspaceId = az_ai_workspace($workspaceId);
    $allowed = ($method === 'POST' && $path === ($provider === 'openai' ? '/v1/responses' : '/v1/messages')) || ($method === 'GET' && preg_match('/\A\/v1\/models(?:\?limit=1000(?:&after_id=[a-zA-Z0-9_.%:-]+)?)?\z/D', $path));
    if (!$allowed) { throw new InvalidArgumentException('Unsupported AI provider endpoint.'); }
    $header = "Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n";
    if ($provider === 'anthropic') { $header .= "anthropic-version: 2026-10-21\r\n"; if ($workspaceId !== '') { $header .= 'anthropic-workspace-id: ' . $workspaceId . "\r\n"; } }
    $options = ['method' => $method, 'header' => $header, 'timeout' => $method === 'GET' ? 15 : 45, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0];
    if ($body !== null) { $options['content'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    $ctx = stream_context_create(['http' => $options, 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $url = ($provider === 'openai' ? 'https://api.openai.com' : 'https://api.anthropic.com') . $path;
    $stream = @fopen($url, 'rb', false, $ctx);
    if (!$stream) { throw new AzHttpError(502, 'The server could not reach ' . $name . '. Check your host connection and provider settings.', 'ai_connection'); }
    try { $raw = stream_get_contents($stream, 1048577); $meta = stream_get_meta_data($stream); } finally { fclose($stream); }
    $status = 0; foreach (($http_response_header ?? []) as $line) { if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) { $status = (int)$m[1]; } }
    if (($meta['timed_out'] ?? false) || $raw === false) { throw new AzHttpError(504, $name . ' timed out. Check the saved proposal before starting another generation.', 'ai_timeout'); }
    if (strlen($raw) > 1048576) { throw new AzHttpError(502, 'The provider response exceeded the supported size.', 'ai_response'); }
    if ($status < 200 || $status >= 300) {
        $message = match ($status) { 401, 403 => $name . ' did not accept the key, workspace, or requested access. Check Admin → AI settings.', 429 => $name . ' could not accept the request because of account limits. Check your provider account.', 400, 404 => $method === 'GET' ? $name . ' could not list models for this account. Check the key and workspace settings.' : $name . ' could not use this model for structured proposals. Choose a compatible text model in Admin → AI settings.', default => $name . ' could not complete the request. Your existing production work is unchanged.' };
        throw new AzHttpError(502, $message, 'ai_provider_error');
    }
    try { $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new AzHttpError(502, 'The provider returned an unreadable response.', 'ai_response'); }
    if (!is_array($decoded) || array_is_list($decoded) || !empty($decoded['error'])) { throw new AzHttpError(502, 'The provider returned an invalid response.', 'ai_response'); }
    return $decoded;
}

function az_ai_transport(string $provider, string $key, array $body, string $workspaceId = ''): array
{
    return az_ai_http($provider, $key, 'POST', $provider === 'openai' ? '/v1/responses' : '/v1/messages', $body, $workspaceId);
}

/** Normalize only successful, complete Claude messages; business validation stays in each assistant. */
function az_ai_normalize_response(string $provider, array $response): array
{
    if (az_ai_provider($provider) === 'openai') { return $response; }
    if (!empty($response['error']) || ($response['type'] ?? '') !== 'message' || ($response['role'] ?? '') !== 'assistant') { throw new AzHttpError(502, 'Claude returned an invalid response.', 'ai_response'); }
    if (($response['stop_reason'] ?? '') === 'refusal') { throw new AzHttpError(422, 'Claude could not draft this request. Revise the brief or continue manually.', 'ai_refusal'); }
    if (($response['stop_reason'] ?? '') !== 'end_turn') { throw new AzHttpError(502, 'Claude did not complete the proposal. No production changes were applied.', 'ai_incomplete'); }
    if (!is_array($response['content'] ?? null) || !array_is_list($response['content']) || !$response['content']) { throw new AzHttpError(502, 'Claude returned invalid response content.', 'ai_response'); }
    $parts = [];
    foreach ($response['content'] as $part) {
        if (!is_array($part) || !is_string($part['type'] ?? null)) { throw new AzHttpError(502, 'Claude returned invalid response content.', 'ai_response'); }
        if ($part['type'] === 'text') {
            if (!is_string($part['text'] ?? null)) { throw new AzHttpError(502, 'Claude returned invalid response text.', 'ai_response'); }
            $parts[] = ['type' => 'output_text', 'text' => $part['text']];
        } elseif (!in_array($part['type'], ['thinking', 'redacted_thinking'], true)) { throw new AzHttpError(502, 'Claude returned unsupported response content.', 'ai_response'); }
    }
    if (!$parts) { throw new AzHttpError(502, 'Claude returned no proposal text.', 'ai_response'); }
    return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => $parts]]];
}

/** Model discovery is an access check, not a paid test of structured generation. */
function az_ai_model_candidates(string $provider, array $response): array
{
    $provider = az_ai_provider($provider);
    if (!is_array($response['data'] ?? null) || !array_is_list($response['data']) || count($response['data']) > 10000) { throw new AzHttpError(502, 'The provider returned an invalid model list.', 'ai_response'); }
    $models = [];
    foreach ($response['data'] as $r) {
        if (!is_array($r) || !is_string($r['id'] ?? null) || !preg_match('/\A[a-zA-Z0-9_.:\/-]{1,120}\z/D', $r['id'])) { continue; }
        $id = $r['id']; $compatibility = 'unverified';
        if ($provider === 'openai') {
            // The Models endpoint does not publish structured-output capability flags.
            // These are text-model candidates; successful generation confirms compatibility.
            if (!preg_match('/\A(?:gpt-(?:4o|4\.1|[5-9](?:[.-]|$)|[1-9][0-9])|o[1-9](?:-|$))/', $id) || preg_match('/(?:audio|realtime|transcrib|tts|image|search|embedding|moderation|codex|computer-use|deep-research)/i', $id) || $id === 'gpt-4o-2024-05-13') { continue; }
            if (!empty($r['shutdown_date']) && is_string($r['shutdown_date']) && $r['shutdown_date'] <= gmdate('Y-m-d')) { continue; }
        } else {
            if (!str_starts_with($id, 'claude-') || ($r['lifecycle'] ?? '') === 'retired') { continue; }
            $capability = $r['capabilities']['structured_outputs']['supported'] ?? null;
            if ($capability === false) { continue; }
            if ($capability === true) { $compatibility = 'provider-reported'; }
        }
        $display = $provider === 'anthropic' && is_string($r['display_name'] ?? null) ? trim($r['display_name']) : $id;
        $models[$id] = ['id' => $id, 'name' => strlen($display) <= 200 && $display !== '' ? $display : $id, 'compatibility' => $compatibility];
    }
    $models = array_values($models); usort($models, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $models;
}

function az_ai_list_models(string $provider, string $key, string $workspaceId = ''): array
{
    $provider = az_ai_provider($provider); $models = []; $cursor = ''; $seen = [];
    for ($page = 0; $page < 5; $page++) {
        $path = '/v1/models' . ($provider === 'anthropic' ? '?limit=1000' . ($cursor !== '' ? '&after_id=' . rawurlencode($cursor) : '') : '');
        $response = az_ai_http($provider, $key, 'GET', $path, null, $workspaceId);
        foreach (az_ai_model_candidates($provider, $response) as $model) { $models[$model['id']] = $model; }
        if ($provider === 'openai' || empty($response['has_more'])) { $result = array_values($models); usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name'])); return $result; }
        $cursor = $response['last_id'] ?? '';
        if (!is_string($cursor) || !preg_match('/\A[a-zA-Z0-9_.:-]{1,120}\z/D', $cursor) || isset($seen[$cursor])) { throw new AzHttpError(502, 'The provider returned an invalid model-list cursor.', 'ai_response'); }
        $seen[$cursor] = true;
    }
    throw new AzHttpError(502, 'The provider model list exceeded the supported page limit. Your settings are unchanged.', 'ai_response');
}

function az_ai_check_job(array $body, array $user): array
{
    $owner = (int)$user['id']; $settings = az_ai_settings_row($owner);
    if (isset($body['revision']) && az_integer($body['revision'], 0, PHP_INT_MAX, 'Settings revision') !== (int)$settings['revision']) { throw new AzHttpError(409, 'AI settings changed. Reload before checking this connection.', 'revision_conflict'); }
    $provider = az_ai_provider($body['provider'] ?? ''); $row = az_ai_provider_row($owner, $provider);
    if ($row['key_ciphertext'] === '') { throw new AzHttpError(400, 'Save this provider API key before checking the connection.', 'ai_configuration'); }
    return ['owner' => $owner, 'revision' => (int)$settings['revision'], 'provider' => $provider, 'key' => az_work_planner_decrypt($row['key_ciphertext']), 'workspaceId' => $row['workspace_id']];
}

function az_ai_check_finish(array $job, array $user, ?array $models, ?AzHttpError $failure): array
{
    $owner = (int)$user['id']; $row = az_ai_settings_row($owner);
    if ($owner !== (int)$job['owner'] || (int)$row['revision'] !== (int)$job['revision']) { throw new AzHttpError(409, 'AI settings changed during the connection check. Check the saved connection again.', 'revision_conflict'); }
    $message = $failure ? $failure->getMessage() : 'The key can list models. This checks provider access only; generation compatibility and billing have not been tested.';
    az_db()->prepare('UPDATE ai_provider_settings SET connection_status=?,connection_message=?,checked_at=? WHERE owner_id=? AND provider=?')->execute([$failure ? 'failed' : 'success', $message, az_now(), $owner, $job['provider']]);
    if ($failure) { throw $failure; }
    return ['provider' => $job['provider'], 'ok' => true, 'models' => $models ?? [], 'message' => $message, 'settings' => az_ai_settings($owner)];
}
