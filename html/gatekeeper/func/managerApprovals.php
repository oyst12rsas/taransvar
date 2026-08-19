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
                // Email ownership must be confirmed before the gateway can
                // grant manager authority.
                $stmt = $conn->prepare("UPDATE managerRequest SET gatewayApprovedTime=COALESCE(gatewayApprovedTime,NOW()), approvedByUserId=?, rejectedTime=NULL, active=IF(credentialHash IS NOT NULL,b'1',active) WHERE managerRequestId=? AND emailVerifiedTime IS NOT NULL AND rejectedTime IS NULL");
                $stmt->bind_param('ii', $userId, $id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    print '<p><font color="green">Manager request approved by this gateway.</font></p>';
                } else {
                    print '<p><font color="red">The email address must be confirmed before gateway approval.</font></p>';
                }
                $stmt->close();
            } elseif ($id > 0 && $decision === 'reject') {
                $stmt = $conn->prepare("UPDATE managerRequest SET rejectedTime=NOW(), active=b'0' WHERE managerRequestId=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                print '<p>Manager request rejected.</p>';
            }
        }
    }

    print '<h2>Manager access approvals</h2>';
    print '<p>An app becomes an active manager only after both the email address and a gateway administrator have confirmed the request.</p>';

    $sql = "SELECT managerRequestId,created,email,credentialCreatedTime,emailVerifiedTime,gatewayApprovedTime,rejectedTime,CAST(active AS UNSIGNED) active,lastUsedTime,expires FROM managerRequest ORDER BY managerRequestId DESC LIMIT 10";
    $result = $conn->query($sql);
    print '<table border="1" cellpadding="6" cellspacing="0"><tr><th>ID</th><th>Email</th><th>Created</th><th>Email</th><th>Gateway admin</th><th>Credential</th><th>Status</th><th>Action</th></tr>';
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['managerRequestId'];
        $emailState = $row['emailVerifiedTime'] ? 'Confirmed' : 'Waiting';
        $gatewayState = $row['gatewayApprovedTime'] ? 'Confirmed' : 'Waiting';
        $credentialState = $row['credentialCreatedTime'] ? 'Ready' : 'Generating';
        $status = $row['rejectedTime'] ? 'Rejected' : (((int)$row['active'] === 1) ? 'ACTIVE' : 'Pending');
        print '<tr>';
        print '<td>'.$id.'</td><td>'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['created']).'</td>';
        print '<td>'.$emailState.'</td><td>'.$gatewayState.'</td><td>'.$credentialState.'</td><td><b>'.$status.'</b></td><td>';
        if (!$row['rejectedTime'] && !$row['gatewayApprovedTime']) {
            print '<form method="post" style="display:inline"><input type="hidden" name="f" value="main"><input type="hidden" name="requestId" value="'.$id.'"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['managerApprovalCsrf']).'">';
            if ($row['emailVerifiedTime']) {
                print '<button name="decision" value="approve">Approve</button> ';
            } else {
                print '<span title="Email confirmation is required before approval">Awaiting email</span> ';
            }
            print '<button name="decision" value="reject">Reject</button></form>';
        } else {
            print '&nbsp;';
        }
        print '</td></tr>';
    }
    print '</table>';
    $result->free();
    $conn->close();
}
