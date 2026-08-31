<?php

function users_active()
{
    if (!isSuperUser()) {
        print "Access denied";
        return;
    }

    if (empty($_SESSION['hotspot_access_csrf'])) {
        $_SESSION['hotspot_access_csrf'] = bin2hex(random_bytes(16));
    }
    $csrf = $_SESSION['hotspot_access_csrf'];
    $msg = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke') {
        $postedCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
        $ip = isset($_POST['ip']) ? trim((string)$_POST['ip']) : '';

        if (!hash_equals($csrf, $postedCsrf)) {
            $msg = '<p style="color:red"><b>Request rejected:</b> invalid form token.</p>';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $msg = '<p style="color:red"><b>Request rejected:</b> invalid IPv4 address.</p>';
        } else {
            $pDb = new CDb;
            $params = array(':ip' => $ip);

            // Persist the revocation in the legacy session model so db.pl does not
            // recreate the access row on its next pass.
            $pDb->execute(
                "update session set active=0, logouttime=coalesce(logouttime, now()) where ip=:ip and active=1",
                $params
            );
            $pDb->execute("delete from access where ip=:ip", $params);

            $msg = '<p style="color:green"><b>Access revoked for '.htmlspecialchars($ip).'.</b> '
                 . 'openNDS enforcement will remove the live client on the next hotspot health/access cycle (normally within one minute).</p>';
        }
    }

    print h1("Users with access");
    print $msg;
    print '<p>This page shows the current <code>access</code> table used by the TaraSec/openNDS access gate. '
        . 'Access is normally granted through the existing user/session/subscription system. Revoking here ends the active session and removes the access row so it stays revoked.</p>';

    $pDb = new CDb;
    $cFlds = array();
    $rows = tr(th("IP").th("Has access").th("Updated").th("Active user/session").th("Action"));
    $found = false;

    $sql = "select a.ip, a.hasaccess, a.updated, "
         . "coalesce((select s.username from session s where s.ip=a.ip and s.active=1 order by s.sessionid desc limit 1),'') as username "
         . "from access a order by a.updated desc, a.ip";

    while ($rec = $pDb->fetchNext($sql, $cFlds)) {
        $found = true;
        $ip = (string)$rec['ip'];
        $user = (string)$rec['username'];
        $action = '<form method="post" action="index.php?f=users_active" style="margin:0">'
                . '<input type="hidden" name="action" value="revoke">'
                . '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES).'">'
                . '<input type="hidden" name="ip" value="'.htmlspecialchars($ip, ENT_QUOTES).'">'
                . '<button type="submit" onclick="return confirm(\'Revoke Internet access for '.htmlspecialchars($ip, ENT_QUOTES).'?\')">Revoke</button>'
                . '</form>';

        $rows .= tr(
            td(htmlspecialchars($ip)).
            td((int)$rec['hasaccess']).
            td(htmlspecialchars((string)$rec['updated'])).
            td($user !== '' ? htmlspecialchars($user) : '<i>not linked to active session</i>').
            td($action)
        );
    }

    if (!$found) {
        $rows .= tr(td('<i>No clients currently have access.</i>', 5));
    }

    print table($rows);
    print '<p><b>To grant access:</b> create/activate the user and assign valid quota/expiry using the existing Users pages. '
        . 'The hotspot access updater then creates the corresponding <code>access</code> row, and the captive portal can authorize the client.</p>';
}

?>
