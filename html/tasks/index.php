<?php
// TaraSec shared task board
// Public read-only board. Writes require the shared key stored outside the web root.

const TASKBOARD_DATA = '/var/lib/tarasec-taskboard/tasks.json';
const TASKBOARD_KEY  = '/etc/tarasec-taskboard.key';

$allowedStatuses = ['Pending', 'Working', 'PR ready', 'Merged', 'Testing', 'Verified'];

function initialTasks(): array
{
    return [
        ['id'=>'A2','group'=>'TaraSec App','description'=>'Establish TaraSec app project in GitHub with compilable code and setup.','status'=>'Pending','chatgpt'=>'Establish project/code.','oystein'=>'Test compile.'],
        ['id'=>'A3','group'=>'TaraSec App','description'=>'App connects to DB server and gateway. Start with configured IPs; later add smarter discovery. DB server can return the gateway IP it sees.','status'=>'Pending','chatgpt'=>'Establish in app project.','oystein'=>'Compile and give feedback.'],
        ['id'=>'A4','group'=>'TaraSec App','description'=>'Encrypted traffic between app, gateway and DB server.','status'=>'Pending','chatgpt'=>'','oystein'=>''],
        ['id'=>'A5','group'=>'TaraSec App','description'=>'','status'=>'Pending','chatgpt'=>'','oystein'=>''],
        ['id'=>'A6','group'=>'TaraSec App','description'=>'','status'=>'Pending','chatgpt'=>'','oystein'=>''],
        ['id'=>'B1','group'=>'TaraSec basic system and AI','description'=>'Message flow to DB server.','status'=>'Testing','chatgpt'=>'Continue fixing and verifying report/confession flow.','oystein'=>'Test live flow and provide feedback.'],
        ['id'=>'B2','group'=>'TaraSec basic system and AI','description'=>'Improve AI routines: verify TaraSec findings from hackReport, find new threats from collected data, and suggest additional data sources such as SSH login logs.','status'=>'Pending','chatgpt'=>'Prepare next AI improvements.','oystein'=>'Waiting for something to test.'],
        ['id'=>'B3','group'=>'TaraSec basic system and AI','description'=>'Investigate whether ChatGPT can test computers/services with less human intervention.','status'=>'Pending','chatgpt'=>'Investigate safe technical options.','oystein'=>'Await conclusion.'],
        ['id'=>'B4','group'=>'TaraSec basic system and AI','description'=>'Improve hackReport classification for units resolved through conntrack/VPN instead of DHCP; avoid "Unknown not via DHCP?" when identity is known another way.','status'=>'Pending','chatgpt'=>'','oystein'=>''],
        ['id'=>'B5','group'=>'TaraSec basic system and AI','description'=>'Move pendingWget handling toward a persistent worker/service; immediate send first, durable queued retry on failure.','status'=>'Pending','chatgpt'=>'','oystein'=>''],
    ];
}

function loadTasks(): array
{
    if (!is_file(TASKBOARD_DATA)) {
        return initialTasks();
    }
    $json = @file_get_contents(TASKBOARD_DATA);
    $data = $json !== false ? json_decode($json, true) : null;
    return is_array($data) ? $data : initialTasks();
}

function saveTasks(array $tasks): bool
{
    $dir = dirname(TASKBOARD_DATA);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $json = json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return file_put_contents(TASKBOARD_DATA, $json . "\n", LOCK_EX) !== false;
}

function suppliedKeyOk(): bool
{
    if (!is_file(TASKBOARD_KEY)) return false;
    $expected = trim((string)@file_get_contents(TASKBOARD_KEY));
    $supplied = isset($_GET['key']) ? (string)$_GET['key'] : '';
    return $expected !== '' && $supplied !== '' && hash_equals($expected, $supplied);
}

function findTaskIndex(array $tasks, string $id): int
{
    foreach ($tasks as $i => $task) {
        if (($task['id'] ?? '') === $id) return $i;
    }
    return -1;
}

function failText(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo "error: $message\n";
    exit;
}

$tasks = loadTasks();

// Simple secret-protected write API. GET is intentional here so humans and ChatGPT
// can update a task with a single URL. Use HTTPS and keep the key private.
if (isset($_GET['action'])) {
    if (!suppliedKeyOk()) failText(403, 'invalid key');

    $action = (string)$_GET['action'];
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') failText(400, 'missing id');

    $idx = findTaskIndex($tasks, $id);

    if ($action === 'status') {
        if ($idx < 0) failText(404, 'task not found');
        $status = trim((string)($_GET['status'] ?? ''));
        global $allowedStatuses;
        if (!in_array($status, $allowedStatuses, true)) failText(400, 'invalid status');
        $tasks[$idx]['status'] = $status;
    } elseif ($action === 'set') {
        if ($idx < 0) failText(404, 'task not found');
        $field = (string)($_GET['field'] ?? '');
        if (!in_array($field, ['description','chatgpt','oystein'], true)) failText(400, 'invalid field');
        $tasks[$idx][$field] = trim((string)($_GET['value'] ?? ''));
    } elseif ($action === 'add') {
        if ($idx >= 0) failText(409, 'task already exists');
        $group = trim((string)($_GET['group'] ?? ''));
        if ($group === '') failText(400, 'missing group');
        $tasks[] = [
            'id'=>$id,
            'group'=>$group,
            'description'=>trim((string)($_GET['description'] ?? '')),
            'status'=>'Pending',
            'chatgpt'=>'',
            'oystein'=>''
        ];
        $idx = count($tasks) - 1;
    } else {
        failText(400, 'unknown action');
    }

    $tasks[$idx]['updated'] = gmdate('c');
    $tasks[$idx]['updatedBy'] = substr(trim((string)($_GET['by'] ?? 'unknown')), 0, 40);

    if (!saveTasks($tasks)) failText(500, 'unable to write task data');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    exit;
}

$groups = [];
foreach ($tasks as $task) {
    $groups[$task['group'] ?? 'Other'][] = $task;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TaraSec development board</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;max-width:1400px;margin:24px auto;padding:0 16px;background:#f7f8fa;color:#20242a}
h1{margin-bottom:4px}.sub{color:#667085;margin-top:0}table{width:100%;border-collapse:collapse;background:white;margin:14px 0 32px;box-shadow:0 1px 3px #0001}th,td{border:1px solid #e5e7eb;padding:9px;vertical-align:top;text-align:left}th{background:#f0f2f5}.id{font-weight:700;white-space:nowrap}.status{font-weight:600;white-space:nowrap}.updated{font-size:.82rem;color:#667085;margin-top:5px}code{background:#eef1f4;padding:2px 4px;border-radius:3px}
</style>
</head>
<body>
<h1>TaraSec bug/development list</h1>
<p class="sub">Statuses: Pending · Working · PR ready · Merged · Testing · Verified</p>
<?php foreach ($groups as $group => $rows): ?>
<h2><?=h($group)?></h2>
<table>
<thead><tr><th>ID</th><th>Description</th><th>Status</th><th>ChatGPT / next action</th><th>Øystein / next action</th></tr></thead>
<tbody>
<?php foreach ($rows as $task): ?>
<tr>
<td class="id"><?=h($task['id'] ?? '')?></td>
<td><?=nl2br(h($task['description'] ?? ''))?></td>
<td class="status"><?=h($task['status'] ?? 'Pending')?><?php if (!empty($task['updated'])): ?><div class="updated"><?=h($task['updatedBy'] ?? '')?> · <?=h($task['updated'])?></div><?php endif; ?></td>
<td><?=nl2br(h($task['chatgpt'] ?? ''))?></td>
<td><?=nl2br(h($task['oystein'] ?? ''))?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endforeach; ?>
</body>
</html>
