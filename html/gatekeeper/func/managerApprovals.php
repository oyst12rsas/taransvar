<?php
function managerApprovals()
{
    if (!loggedIn() || !isAdmin()) {
        print '<h3>Administrator access required.</h3>';
        return;
    }

    $conn = getConnection();

    if (empty($_SESSION['managerApprovalCsrf'])) {
        $_SESSION['managerApprovalCsrf'] = bin2hex(random_bytes(24));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (!hash_equals((string)$_SESSION['managerApprovalCsrf'], $csrf)) {
            print '<p><font color="red">Invalid request token.</font></p>';
        } else {
            $id = (int)($_POST['requestId'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $userId = (int)($_SESSION['userid'] ?? 0);

            if ($id > 0 && $decision === 'approve') {
                $stmt = $conn->prepare("UPDATE managerRequest SET gatewayApprovedTime=COALESCE(gatewayApprovedTime,NOW()), approvedByUserId=?, rejectedTime=NULL, active=IF(credentialHash IS NOT NULL,b'1',active) WHERE managerRequestId=? AND emailVerifiedTime IS NOT NULL AND rejectedTime IS NULL");
                $stmt->bind_param('ii', $userId, $id);
                $stmt->execute();
                print $stmt->affected_rows > 0
                    ? '<p><font color="green">Manager request approved by this gateway.</font></p>'
                    : '<p><font color="red">The email address must be confirmed before gateway approval.</font></p>';
                $stmt->close();
            } elseif ($id > 0 && $decision === 'reject') {
                $stmt = $conn->prepare("UPDATE managerRequest SET rejectedTime=NOW(), active=b'0' WHERE managerRequestId=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                print '<p>Manager request rejected.</p>';
            } elseif ($id > 0 && $decision === 'revoke') {
                // Revocation is intentionally different from rejection: the existing
                // verified request and credential remain known, but every manager API
                // immediately rejects it because active=0.
                $stmt = $conn->prepare("UPDATE managerRequest SET active=b'0' WHERE managerRequestId=? AND rejectedTime IS NULL");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                print '<p><font color="orange">App manager authorization revoked. Existing App credentials can no longer access this installation.</font></p>';
            } elseif ($id > 0 && $decision === 'reenable') {
                // Re-enable only an already fully verified and credentialled manager.
                $stmt = $conn->prepare("UPDATE managerRequest SET active=b'1', approvedByUserId=? WHERE managerRequestId=? AND rejectedTime IS NULL AND emailVerifiedTime IS NOT NULL AND gatewayApprovedTime IS NOT NULL AND credentialHash IS NOT NULL");
                $stmt->bind_param('ii', $userId, $id);
                $stmt->execute();
                print $stmt->affected_rows > 0
                    ? '<p><font color="green">App manager authorization re-enabled.</font></p>'
                    : '<p><font color="red">This manager cannot be re-enabled because verification/approval/credential state is incomplete.</font></p>';
                $stmt->close();
            }
        }
    }

    print '<h2>Manager access approvals</h2>';
    print '<p>An app becomes an active manager only after both the email address and a gateway administrator have confirmed the request. Active managers can be revoked immediately from this page.</p>';

    $mailStatusFile = '/run/tarasec-mail-relay-status.json';
    $mailStatus = null;
    if (is_readable($mailStatusFile)) {
        $decoded = json_decode((string)file_get_contents($mailStatusFile), true);
        if (is_array($decoded)) $mailStatus = $decoded;
    }
    if ($mailStatus === null) {
        print '<p><b>Email sending:</b> <font color="orange">No health check has run yet.</font> Run manager_requests.pl or wait for its scheduled run.</p>';
    } else {
        $configured = !empty($mailStatus['configured']);
        $relay = !empty($mailStatus['relayReachable']);
        $sender = !empty($mailStatus['sendingService']);
        $checked = htmlspecialchars((string)($mailStatus['checkedAt'] ?? 'unknown'));
        print '<p><b>Email sending status:</b> ';
        print 'Configuration: '.($configured ? '<font color="green">OK</font>' : '<font color="red">missing</font>').' &nbsp; ';
        print 'Mail relay: '.($relay ? '<font color="green">UP</font>' : '<font color="red">DOWN</font>').' &nbsp; ';
        print 'Sending service: '.($sender ? '<font color="green">UP</font>' : '<font color="red">DOWN</font>');
        print ' <small>(checked '.$checked.')</small>';
        if (!empty($mailStatus['error'])) print '<br><small>'.htmlspecialchars((string)$mailStatus['error']).'</small>';
        print '</p>';
    }

    $sql = "SELECT managerRequestId,created,email,credentialCreatedTime,emailVerifiedTime,gatewayApprovedTime,rejectedTime,CAST(active AS UNSIGNED) active,lastUsedTime,expires FROM managerRequest ORDER BY managerRequestId DESC LIMIT 20";
    $result = $conn->query($sql);
    print '<table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>Email</th><th>Created</th><th>Email</th><th>Gateway admin</th><th>Credential</th><th>Status</th><th>Last used</th><th>Action</th></tr>';
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['managerRequestId'];
        $active = (int)$row['active'] === 1;
        $emailState = $row['emailVerifiedTime'] ? 'Confirmed' : 'Waiting';
        $gatewayState = $row['gatewayApprovedTime'] ? 'Confirmed' : 'Waiting';
        $credentialState = $row['credentialCreatedTime'] ? 'Ready' : 'Generating';
        $fullyApproved = $row['emailVerifiedTime'] && $row['gatewayApprovedTime'] && $row['credentialCreatedTime'];
        $status = $row['rejectedTime'] ? 'Rejected' : ($active ? 'ACTIVE' : ($fullyApproved ? 'REVOKED' : 'Pending'));

        print '<tr>';
        print '<td>'.$id.'</td><td>'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['created']).'</td>';
        print '<td>'.$emailState.'</td><td>'.$gatewayState.'</td><td>'.$credentialState.'</td><td><b>'.$status.'</b></td>';
        print '<td>'.htmlspecialchars((string)($row['lastUsedTime'] ?? '')).'</td><td>';

        if (!$row['rejectedTime']) {
            print '<form method="post" style="display:inline"><input type="hidden" name="f" value="main"><input type="hidden" name="requestId" value="'.$id.'"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['managerApprovalCsrf']).'">';
            if ($active) {
                print '<button name="decision" value="revoke" onclick="return confirm(\'Revoke this App manager immediately?\')">Revoke</button>';
            } elseif ($fullyApproved) {
                print '<button name="decision" value="reenable">Re-enable</button>';
            } elseif (!$row['gatewayApprovedTime']) {
                if ($row['emailVerifiedTime']) print '<button name="decision" value="approve">Approve</button> ';
                else print '<span title="Email confirmation is required before approval">Awaiting email</span> ';
                print '<button name="decision" value="reject">Reject</button>';
            }
            print '</form>';
        } else {
            print '&nbsp;';
        }
        print '</td></tr>';
    }
    print '</table>';
    $result->free();
    $conn->close();
}
