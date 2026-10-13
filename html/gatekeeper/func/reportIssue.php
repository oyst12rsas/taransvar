<?php
require_once __DIR__.'/manualIssues.php';
require_once __DIR__.'/agentStatusView.php';
require_once __DIR__.'/operationsStatusView.php';

function reportIssue()
{
    renderLocalIssues(true);
}

function renderLocalIssues(bool $newReport = false)
{
    if (!isAdmin()) { print '<p>Administrator login required.</p>'; return; }
    $conn = getConnection();
    $_SESSION['manualIssueCsrf'] ??= bin2hex(random_bytes(32));
    try {
        $server = manualIssueServer($conn);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $message = manualIssueSubmit($conn, isAdmin(), $_SESSION, $_POST);
            $_SESSION['manualIssueCsrf'] = bin2hex(random_bytes(32));
            print '<p>'.agentStatusEscape($message).'</p>';
            $newReport = false;
        }
        $showAll = (($_GET['history'] ?? '') === 'all');
        $page = max(0, min(1000000, (int)($_GET['page'] ?? 0)));
        $listUrl = 'index.php?f=issues'.($showAll ? '&amp;history=all' : '');
        print '<h1>'.($newReport ? 'Report a new issue' : ($showAll ? 'Full issue history' : 'Open issues')).'</h1><p>Server: '.agentStatusEscape($server['nickname']).' ('.agentStatusEscape($server['ip']).')</p>';
        print '<p><a href="index.php?f=issues">Open issues</a> · <a href="index.php?f=issues&amp;history=all">Full history, including solved issues</a> · <a href="index.php?f=reportIssue">Report a new issue</a></p>';
        if ($newReport) {
            print '<p>Describe what happened and what you expected. Avoid passwords, tokens and private data. Reports stay open until a fresh check verifies resolution. Repairs follow this server’s existing permissions.</p>';
            print '<form method="post" action="index.php?f=reportIssue"><input type="hidden" name="csrf" value="'.agentStatusEscape($_SESSION['manualIssueCsrf']).'">';
            print '<label>Problem and expected behaviour<br><textarea name="description" rows="6" cols="70" maxlength="4000" required></textarea></label><br><button name="action" value="report">Report issue</button></form>';
            $conn->close();
            return;
        }
        $issues = operationsStoredIssues($conn, $server['ip'], $showAll, $page * 64);
        $more = count($issues) > 64;
        print operationsMaintenanceDetails(['issues'=>array_slice($issues, 0, 64)]);
        foreach (array_slice($issues, 0, 64) as $issue) {
            if ($issue['verification'] !== 'manual' || $issue['status'] === 'solved') continue;
            print '<details><summary>Verify resolution: '.agentStatusEscape($issue['description']).'</summary><form method="post" action="'.$listUrl.'">';
            print '<input type="hidden" name="csrf" value="'.agentStatusEscape($_SESSION['manualIssueCsrf']).'"><input type="hidden" name="key" value="'.agentStatusEscape($issue['key']).'">';
            print '<label>Fresh check performed and observed result<br><textarea name="description" rows="3" cols="70" maxlength="4000" required></textarea></label><br><button name="action" value="verify">Confirm verified resolution</button></form></details>';
        }
        if ($page) print '<p><a href="'.$listUrl.'&amp;page='.($page-1).'">Previous issues</a></p>';
        if ($more) print '<p><a href="'.$listUrl.'&amp;page='.($page+1).'">Next issues</a></p>';
    } catch (Throwable $e) { print '<p>'.agentStatusEscape($e->getMessage()).'</p>'; }
    $conn->close();
}
