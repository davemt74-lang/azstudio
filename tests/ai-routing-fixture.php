<?php
declare(strict_types=1);

/** CLI-only provider fixtures. No provider request or media transfer occurs. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require $argv[1] . '/includes/bootstrap.php';
$case = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$user = az_account_user((int)($case['ownerId'] ?? 1));
$lock = az_community_lock();

function az_fixture_job(array $case, array $user): array
{
    $provider = $case['provider'] ?? 'openai';
    $model = $provider === 'anthropic' ? 'claude-fixture-model' : 'gpt-fixture-model';
    if (($case['task'] ?? 'shotPlanner') === 'shotPlanner') {
        $snapshot = az_work_planner_snapshot(az_work_project_row($case['projectId'], $user, true));
        $job = ['provider' => $provider, 'model' => $model, 'snapshot' => $snapshot, 'count' => 1, 'direction' => 'Portrait launch'];
    } else {
        $snapshot = az_director_snapshot($case['projectId'], $user);
        $base = az_director_propose(['projectId' => $case['projectId'], 'targetDurationSeconds' => 0], $user);
        $job = ['provider' => $provider, 'model' => $model, 'snapshot' => [
            'project' => $snapshot['project'], 'requirements' => $snapshot['requirements'],
            'coverage' => $snapshot['coverage'], 'candidates' => az_edit_composer_candidates($snapshot),
            'settings' => $base['edit']['settings'], 'warnings' => $base['warnings'],
            'request' => ['targetDurationSeconds' => 60, 'direction' => 'Use producer notes.'],
        ]];
    }
    if (!empty($case['taint'])) {
        $job['key'] = 'credential-sentinel-never-send-in-body';
        $job['snapshot']['project'] += ['email' => 'private-sentinel@example.invalid', 'owner_id' => 991, 'password_hash' => 'password-sentinel'];
        foreach ($job['snapshot']['shots'] ?? [] as $i => $shot) {
            $job['snapshot']['shots'][$i]['mediaUrl'] = 'https://private-sentinel.invalid/video.webm';
            $job['snapshot']['shots'][$i]['brief'] = 'private-existing-shot-brief-sentinel';
        }
        foreach ($job['snapshot']['candidates'] ?? [] as $id => $candidate) {
            $job['snapshot']['candidates'][$id] += ['storage_name' => 'storage-sentinel.bin', 'mediaUrl' => 'https://private-sentinel.invalid/video.webm', 'contributorName' => 'private-contributor-sentinel'];
        }
    }
    return $job;
}

function az_fixture_value(string $task, array $job): array
{
    if ($task === 'shotPlanner') {
        return ['summary' => 'A practical fixture shot list.', 'shots' => [[
            'title' => 'Product close-up', 'brief' => 'Frame the label and show the product texture under soft light.',
            'orientation' => 'portrait', 'targetDurationSeconds' => 15, 'takesRequested' => 2,
        ]]];
    }
    $candidate = reset($job['snapshot']['candidates']);
    return ['summary' => 'Open with the approved introduction.', 'clips' => [[
        'candidateId' => $candidate['candidateId'], 'startMs' => $candidate['startMs'], 'endMs' => $candidate['endMs'],
        'title' => 'Opening', 'showCard' => false, 'reason' => 'Producer-labeled introduction.',
    ]], 'missingCoverage' => []];
}

function az_fixture_response(string $provider, array $value): array
{
    $text = json_encode($value, JSON_THROW_ON_ERROR);
    return $provider === 'anthropic'
        ? ['type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]]
        : ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
}

try {
    $task = $case['task'] ?? 'shotPlanner';
    $operation = $case['operation'];
    if ($operation === 'payload' || $operation === 'parse') {
        $job = az_fixture_job($case, $user);
        if ($operation === 'payload') {
            $data = $task === 'shotPlanner' ? az_work_planner_request_body($job) : az_edit_composer_request_body($job);
        } else {
            $value = az_fixture_value($task, $job);
            if (isset($case['changes'])) {
                $field = $task === 'shotPlanner' ? 'shots' : 'clips';
                $value[$field][0] = array_replace($value[$field][0], $case['changes']);
            }
            if (isset($case['value'])) { $value = $case['value']; }
            $response = $case['response'] ?? az_fixture_response($job['provider'], $value);
            $data = $task === 'shotPlanner'
                ? az_work_planner_parse_response($response, $job['provider'])
                : az_edit_composer_parse_response($response, $job);
        }
    } elseif (in_array($operation, ['start', 'prepare', 'retry'], true)) {
        $body = ['projectId' => $case['projectId'], 'requestToken' => $case['token'], 'direction' => $case['direction'] ?? ''];
        $body += $task === 'shotPlanner' ? ['shotCount' => 1] : ['targetDurationSeconds' => 60];
        $job = $task === 'shotPlanner' ? az_work_planner_start($body, $user) : az_edit_composer_start($body, $user);
        if (isset($job['existing'])) { $data = ['existing' => $job['existing']]; }
        elseif ($operation === 'prepare') {
            $value = az_fixture_value($task, $job);
            $response = az_fixture_response($job['provider'], $value);
            $proposal = $task === 'shotPlanner' ? az_work_planner_parse_response($response, $job['provider']) : az_edit_composer_parse_response($response, $job);
            $data = $task === 'shotPlanner' ? az_work_planner_finish($job['id'], $user, $proposal, null) : az_edit_composer_finish($job['id'], $user, $proposal, null);
        } else {
            $data = ['id' => $job['id'], 'provider' => $job['provider'], 'model' => $job['model'],
                'payload' => $task === 'shotPlanner' ? az_work_planner_request_body($job) : az_edit_composer_request_body($job)];
        }
    } elseif ($operation === 'finishSaved') {
        $row = $task === 'shotPlanner' ? az_work_planner_proposal_row($case['id'], $user) : az_edit_composer_row($case['id'], $user);
        $snapshot = json_decode($row['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
        $job = ['model' => $row['model_id'], 'provider' => $snapshot['ai']['provider'] ?? 'openai', 'snapshot' => $snapshot];
        $value = az_fixture_value($task, $job);
        $response = az_fixture_response($job['provider'], $value);
        $proposal = $task === 'shotPlanner' ? az_work_planner_parse_response($response, $job['provider']) : az_edit_composer_parse_response($response, $job);
        $data = $task === 'shotPlanner' ? az_work_planner_finish($row['id'], $user, $proposal, null) : az_edit_composer_finish($row['id'], $user, $proposal, null);
    } elseif ($operation === 'failSaved') {
        $failure = new AzHttpError(502, 'Fixture provider unavailable. No fallback requested.', 'ai_provider_error');
        $data = $task === 'shotPlanner' ? az_work_planner_finish($case['id'], $user, null, $failure) : az_edit_composer_finish($case['id'], $user, null, $failure);
    } else { throw new RuntimeException('Unknown routing fixture.'); }
    echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR);
} catch (AzHttpError $error) {
    echo json_encode(['ok' => false, 'status' => $error->status, 'code' => $error->errorCode, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
