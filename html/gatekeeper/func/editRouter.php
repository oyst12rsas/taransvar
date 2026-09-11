<?php

function editRouter()
{
    if (!isAdmin())
        return;

    $routerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($routerId <= 0)
    {
        print "Invalid router.";
        return;
    }

    $conn = getConnection();

    if (isset($_GET['submit']))
    {
        $route = isset($_GET['taggedRoute']) ? trim($_GET['taggedRoute']) : "";
        if ($route !== "" && !filter_var($route, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        {
            print "Invalid tagged-traffic route IPv4 address.<br><br>";
        }
        else
        {
            $sql = "update partnerRouter set taggedTrafficRoute=INET_ATON(NULLIF(?,'')), " .
                "taggedTrafficRouteUpdated=case when ?='' then null else now() end, handled=b'0' " .
                "where routerId=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $route, $route, $routerId);
            $stmt->execute();
            $stmt->close();
            print "Tagged-traffic route updated.<br><br>";
        }
    }

    $sql = "select partnerId, INET_NTOA(ip) destinationIp, INET_NTOA(nettmask) netmask, " .
        "COALESCE(INET_NTOA(taggedTrafficRoute),'') taggedRoute, taggedTrafficRouteUpdated " .
        "from partnerRouter where routerId=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $routerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row)
    {
        print "Router not found.";
        return;
    }
?>
<form action="index.php"><table>
<tr><td>Public destination</td><td><?php print htmlspecialchars($row['destinationIp'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
<tr><td>Netmask</td><td><?php print htmlspecialchars($row['netmask'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
<tr><td>Tagged traffic route</td><td><input name="taggedRoute" value="<?php print htmlspecialchars($row['taggedRoute'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="NetBird IPv4, blank to disable"></td></tr>
<tr><td>Last changed</td><td><?php print htmlspecialchars($row['taggedTrafficRouteUpdated'] ?: "-", ENT_QUOTES, 'UTF-8'); ?></td></tr>
<tr><td colspan="2">Only tagged traffic to this registered destination may use this next hop.</td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="editRouter"><input name="id" type="hidden" value="<?php print $routerId; ?>"><input type="submit" name="submit" value="Save"></td></tr>
</table></form>
<a href="index.php?f=partner&id=<?php print (int)$row['partnerId']; ?>">Back to partner</a>
<?php
}

?>
