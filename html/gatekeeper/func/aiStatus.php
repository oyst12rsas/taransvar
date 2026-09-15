<?php

function aiStatusScalar($value)
{
    if ($value === null || $value === '') {
        return 'unknown';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return (string)$value;
}

function aiStatusIssues($secondsSince, $status)
{
    $issues = array();

    if ($secondsSince === null || $secondsSince > 200) {
        $issues[] = 'ERROR: status report is stale (' . aiStatusScalar($secondsSince) . ' seconds old)';
    } elseif ($secondsSince > 130) {
        $issues[] = 'WARNING: status report is delayed (' . $secondsSince . ' seconds old)';
    }

    if (!is_array($status)) {
        $issues[] = 'ERROR: status JSON is missing or invalid';
        return $issues;
    }

    foreach (array('knl' => 'tarakernel', 'lnk' => 'taralink', 'cron' => 'status cron') as $field => $label) {
        if (!array_key_exists($field, $status)) {
            $issues[] = 'WARNING: ' . $label . ' state is not reported';
        } elseif ((string)$status[$field] !== '1') {
            $issues[] = 'ERROR: ' . $label . ' is not running';
        }
    }

    if (isset($status['dmesg']) && (int)$status['dmesg'] >= 130) {
        $issues[] = 'ERROR: dmesg data is ' . (int)$status['dmesg'] . ' seconds old';
    }

    if (isset($status['trfc']) && (int)$status['trfc'] >= 130) {
        $issues[] = 'NOTICE: traffic data is ' . (int)$status['trfc'] . ' seconds old; this may be normal when there are no users';
    }

    if (isset($status['sqlThrds']) && (int)$status['sqlThrds'] >= 25) {
        $issues[] = 'ERROR: high MariaDB connection count (' . (int)$status['sqlThrds'] . ')';
    } elseif (isset($status['sqlThrds']) && (int)$status['sqlThrds'] > 12) {
        $issues[] = 'WARNING: elevated MariaDB connection count (' . (int)$status['sqlThrds'] . ')';
    }

    if (!empty($status['bootReq'])) {
        $issues[] = 'WARNING: reboot required';
    }

    if (!empty($status['srvcNtOk'])) {
        $issues[] = 'ERROR: services not running: ' . $status['srvcNtOk'];
    }

    if (isset($status['ld'])) {
        $loads = preg_split('/\s+/', trim((string)$status['ld']));
        $recentLoads = array_slice(array_map('floatval', $loads), 0, 2);
        if ($recentLoads && max($recentLoads) >= 2) {
            $issues[] = 'ERROR: high recent load (' . $status['ld'] . ')';
        } elseif ($recentLoads && max($recentLoads) > 0.7) {
            $issues[] = 'WARNING: elevated recent load (' . $status['ld'] . ')';
        }
    }

    if (isset($status['rsyslog'])) {
        $parts = array();
        foreach (explode(',', $status['rsyslog']) as $item) {
            $pair = explode(':', $item, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        if (($parts['rsyslog'] ?? '') !== 'active') {
            $issues[] = 'ERROR: rsyslog is not active';
        }
        if (empty($parts['setup'])) {
            $issues[] = 'ERROR: rsyslog has no forwarding destination';
        }
        if (($parts['log'] ?? '') === '0') {
            $issues[] = 'ERROR: expected firewall LOG rule is missing';
        }
    }

    if (!empty($status['err'])) {
        $severity = isset($status['errSev']) ? $status['errSev'] : 'unknown';
        $issues[] = 'REPORTED ERROR (severity ' . $severity . '): ' . $status['err'];
    }

    if (!$issues) {
        $issues[] = 'OK: no issue detected by the dashboard thresholds';
    }

    return $issues;
}

function aiStatusAppendServer(&$lines, $name, $ip, $reportedAt, $secondsSince, $rawStatus, $kind)
{
    $status = json_decode((string)$rawStatus, true);

    $lines[] = '';
    $lines[] = '============================================================';
    $lines[] = 'SERVER: ' . ($name !== '' ? $name : 'Unnamed');
    $lines[] = 'ROLE: ' . $kind;
    $lines[] = 'IP: ' . ($ip !== '' ? $ip : 'local/not reported');
    $lines[] = 'REPORTED_AT: ' . aiStatusScalar($reportedAt);
    $lines[] = 'SECONDS_SINCE_REPORT: ' . aiStatusScalar($secondsSince);
    $lines[] = 'ASSESSMENT:';

    foreach (aiStatusIssues($secondsSince === null ? null : (int)$secondsSince, $status) as $issue) {
        $lines[] = '- ' . $issue;
    }

    $lines[] = 'STATUS_FIELDS:';
    if (is_array($status)) {
        ksort($status);
        foreach ($status as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
            $lines[] = $key . '=' . aiStatusScalar($value);
        }
    } else {
        $lines[] = 'invalid_or_missing_status_json';
    }

    $lines[] = 'RAW_STATUS_JSON:';
    $lines[] = is_array($status)
        ? json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : aiStatusScalar($rawStatus);
}

function aiStatus()
{
    if (!isAdmin()) {
        http_response_code(403);
        print '<h2>Administrator access required</h2>';
        print '<p>This report contains infrastructure status for every registered server.</p>';
        return;
    }

    $conn = getConnection();
    $lines = array(
        'TARASEC AI STATUS REPORT',
        'GENERATED_UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
        'SOURCE: TaraSec Gatekeeper administrator dashboard',
        'REPOSITORY: https://github.com/oyst12rsas/taransvar',
        '',
        'AI ANALYSIS REQUEST:',
        'Review every server below. Prioritize current operational failures, distinguish stale or historical counters from active incidents, identify correlated failures, and propose safe verification commands before destructive changes.',
        '',
        'FIELD NOTES:',
        '- knl, lnk and cron: 1 means running.',
        '- dmesg and trfc: seconds since newest local record.',
        '- sqlThrds: current MariaDB connected threads.',
        '- ld: 1, 5 and 15 minute load averages.',
        '- updates: total;security updates.',
        '- log:n/a means a firewall LOG rule is not applicable to that node role.',
        '- Absent traffic can be normal on an idle node.',
    );

    $localSql = "SELECT COALESCE(nickname,'Local server') AS name,
                        COALESCE(INET_NTOA(adminIP),'') AS ip,
                        networkStatusChecked AS reported_at,
                        TIMESTAMPDIFF(SECOND,networkStatusChecked,NOW()) AS seconds_since,
                        networkStatus AS status
                 FROM setup
                 LIMIT 1";
    $localResult = $conn->query($localSql);
    if ($localResult && ($row = $localResult->fetch_assoc())) {
        aiStatusAppendServer(
            $lines,
            (string)$row['name'],
            (string)$row['ip'],
            $row['reported_at'],
            $row['seconds_since'],
            $row['status'],
            'local server'
        );
        $localResult->free();
    }

    $partnerSql = "SELECT R.routerId,
                          COALESCE(P.name,CONCAT('Router ',R.routerId)) AS name,
                          COALESCE(INET_NTOA(R.ip),'') AS ip,
                          R.partnerStatusReceived AS reported_at,
                          TIMESTAMPDIFF(SECOND,R.partnerStatusReceived,NOW()) AS seconds_since,
                          R.status
                   FROM partnerRouter R
                   LEFT JOIN partner P ON P.partnerId=R.partnerId
                   ORDER BY P.name,R.routerId";
    $partnerResult = $conn->query($partnerSql);
    if ($partnerResult) {
        while ($row = $partnerResult->fetch_assoc()) {
            aiStatusAppendServer(
                $lines,
                (string)$row['name'],
                (string)$row['ip'],
                $row['reported_at'],
                $row['seconds_since'],
                $row['status'],
                'partner router'
            );
        }
        $partnerResult->free();
    }

    $conn->close();
    $report = implode("\n", $lines);
    ?>
    <style>
    .ai-status-wrap { max-width: 1100px; margin: 20px auto; text-align: left; }
    .ai-status-actions { margin: 10px 0; }
    #aiStatusReport { width: 100%; min-height: 650px; box-sizing: border-box; font-family: monospace; white-space: pre; }
    #aiStatusCopyResult { margin-left: 10px; }
    </style>
    <div class="ai-status-wrap">
        <h2>AI status report</h2>
        <p>This administrator-only report combines the latest status received from the local server and every registered partner. Copy it into an AI conversation when requesting operational help.</p>
        <div class="ai-status-actions">
            <button type="button" onclick="copyAiStatusReport()">Copy complete report</button>
            <span id="aiStatusCopyResult"></span>
        </div>
        <textarea id="aiStatusReport" readonly><?php
            print htmlspecialchars($report, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?></textarea>
    </div>
    <script>
    async function copyAiStatusReport() {
        const report = document.getElementById('aiStatusReport');
        const result = document.getElementById('aiStatusCopyResult');
        try {
            await navigator.clipboard.writeText(report.value);
            result.textContent = 'Copied';
        } catch (error) {
            report.focus();
            report.select();
            result.textContent = 'Select and copy the highlighted report';
        }
    }
    </script>
    <?php
}
