<?php

function users()
{
    if (!isAdmin()) {
        http_response_code(403);
        print '<h2>Access denied</h2><p>Administrator access is required.</p>';
        return;
    }

    if (!isset($_SESSION['csrf_user_admin'])) {
        $_SESSION['csrf_user_admin'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['csrf_user_admin'];

    $conn = getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedCsrf = $_POST['csrf'] ?? '';
        if (!hash_equals($csrf, $postedCsrf)) {
            http_response_code(400);
            print '<h3>Invalid request token.</h3>';
            $conn->close();
            return;
        }

        $action = $_POST['action'] ?? '';
        $userId = filter_input(INPUT_POST, 'userId', FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            print '<h3>Invalid user ID.</h3>';
            $conn->close();
            return;
        }

        $currentUserId = isset($_SESSION['userid']) ? (int)$_SESSION['userid'] : 0;

        if ($action === 'set_admin') {
            $isAdmin = isset($_POST['isAdmin']) && $_POST['isAdmin'] === '1' ? 1 : 0;

            if ($userId === $currentUserId && !$isAdmin) {
                print '<h3>You cannot remove your own administrator rights.</h3>';
            } else {
                $stmt = $conn->prepare("update user set isAdmin = ? where userId = ?");
                $stmt->bind_param('ii', $isAdmin, $userId);
                $stmt->execute();
                $stmt->close();
                print '<p>User administrator status updated.</p>';
            }
        }
        elseif ($action === 'disable') {
            if ($userId === $currentUserId) {
                print '<h3>You cannot disable your own account.</h3>';
            } else {
                $stmt = $conn->prepare("update user set suspendedUntil = '2099-12-31 23:59:59' where userId = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
                print '<p>User disabled.</p>';
            }
        }
        elseif ($action === 'enable') {
            $stmt = $conn->prepare("update user set suspendedUntil = null where userId = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            print '<p>User enabled.</p>';
        }
        elseif ($action === 'reset_failures') {
            $stmt = $conn->prepare("update user set loginFailsSinceSuccess = 0, loginFailReportedTime = null where userId = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            print '<p>Login failure state cleared.</p>';
        }
        elseif ($action === 'delete') {
            if ($userId === $currentUserId) {
                print '<h3>You cannot delete your own account.</h3>';
            } else {
                $stmt = $conn->prepare("select CAST(isAdmin AS UNSIGNED) as isAdmin from user where userId = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $target = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                $canDelete = true;
                if ($target && (int)$target['isAdmin'] === 1) {
                    $result = $conn->query("select count(*) as cnt from user where CAST(isAdmin AS UNSIGNED) = 1");
                    $row = $result ? $result->fetch_assoc() : null;
                    if ($result) $result->free();
                    if (!$row || (int)$row['cnt'] <= 1) {
                        $canDelete = false;
                        print '<h3>The last administrator account cannot be deleted.</h3>';
                    }
                }

                if ($canDelete) {
                    $stmt = $conn->prepare("delete from user where userId = ?");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                    print '<p>User deleted.</p>';
                }
            }
        }
    }

    $sql = "select userId, username, inserted, lastLogin, inet_ntoa(lastLoginIp) as lastLoginIp, " .
           "loginFailsSinceSuccess, loginFailReportedTime, suspendedUntil, " .
           "CAST(isAdmin AS UNSIGNED) as isAdmin " .
           "from user order by username";
    $result = $conn->query($sql);

    print '<h2>User administration</h2>';
    print '<p>Manage Gatekeeper accounts. Password management will be added with the login password-hashing migration so passwords are never stored or displayed in plaintext.</p>';

    if (!$result) {
        print '<p>Unable to read user table.</p>';
        $conn->close();
        return;
    }

    print '<table>';
    print '<tr><td>ID</td><td>User</td><td>Admin</td><td>Created</td><td>Last login</td><td>Last IP</td><td>Failures</td><td>Status</td><td>Actions</td></tr>';

    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['userId'];
        $name = htmlspecialchars($row['username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isAdmin = (int)$row['isAdmin'] === 1;
        $disabled = !empty($row['suspendedUntil']) && strtotime($row['suspendedUntil']) > time();
        $created = htmlspecialchars($row['inserted'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lastLogin = htmlspecialchars($row['lastLogin'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lastIp = htmlspecialchars($row['lastLoginIp'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fails = (int)($row['loginFailsSinceSuccess'] ?? 0);
        $status = $disabled ? 'Disabled until '.htmlspecialchars($row['suspendedUntil'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Enabled';

        print '<tr>';
        print '<td>'.$id.'</td><td>'.$name.'</td><td>'.($isAdmin ? 'Yes' : 'No').'</td>';
        print '<td>'.$created.'</td><td>'.$lastLogin.'</td><td>'.$lastIp.'</td><td>'.$fails.'</td><td>'.$status.'</td><td>';

        print '<form method="post" action="index.php?f=users" style="display:inline">';
        print '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
        print '<input type="hidden" name="userId" value="'.$id.'">';
        print '<input type="hidden" name="action" value="set_admin">';
        print '<input type="hidden" name="isAdmin" value="'.($isAdmin ? '0' : '1').'">';
        print '<button type="submit">'.($isAdmin ? 'Remove admin' : 'Make admin').'</button></form> ';

        print '<form method="post" action="index.php?f=users" style="display:inline">';
        print '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
        print '<input type="hidden" name="userId" value="'.$id.'">';
        print '<input type="hidden" name="action" value="'.($disabled ? 'enable' : 'disable').'">';
        print '<button type="submit">'.($disabled ? 'Enable' : 'Disable').'</button></form> ';

        if ($fails > 0 || !empty($row['loginFailReportedTime'])) {
            print '<form method="post" action="index.php?f=users" style="display:inline">';
            print '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
            print '<input type="hidden" name="userId" value="'.$id.'">';
            print '<input type="hidden" name="action" value="reset_failures">';
            print '<button type="submit">Clear failures</button></form> ';
        }

        if ($id !== (int)($_SESSION['userid'] ?? 0)) {
            print '<form method="post" action="index.php?f=users" style="display:inline" onsubmit="return confirm(\'Delete this user?\');">';
            print '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
            print '<input type="hidden" name="userId" value="'.$id.'">';
            print '<input type="hidden" name="action" value="delete">';
            print '<button type="submit">Delete</button></form>';
        }

        print '</td></tr>';
    }

    print '</table>';
    $result->free();
    $conn->close();
}

?>
