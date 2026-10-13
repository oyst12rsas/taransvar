<?php
require_once __DIR__.'/reportIssue.php';

function issue()
{
    if (!is_string($_GET['key'] ?? null) || $_GET['key'] === '') {
        print '<p>Choose an issue from <a href="index.php?f=issues">Open issues</a>.</p>';
        return;
    }
    renderLocalIssues();
}
