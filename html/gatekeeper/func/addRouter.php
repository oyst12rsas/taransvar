<?php

function addRouter()
{
    if (!isAdmin())
        return;

    $partnerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $ip = isset($_GET['ip']) ? trim($_GET['ip']) : "";
    $netmask = isset($_GET['nett']) ? trim($_GET['nett']) : "";
    $taggedRoute = isset($_GET['taggedRoute']) ? trim($_GET['taggedRoute']) : "";

    if (isset($_GET["submit"]))
    {
        $validDestination = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $validNetmask = filter_var($netmask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $validTaggedRoute = $taggedRoute === "" ||
            filter_var($taggedRoute, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($partnerId > 0 && $validDestination && $validNetmask && $validTaggedRoute)
        {
            $conn = getConnection();
            $sql = "insert into partnerRouter " .
                "(partnerId, ip, nettmask, taggedTrafficRoute, taggedTrafficRouteUpdated) " .
                "values (?, INET_ATON(?), INET_ATON(?), INET_ATON(NULLIF(?,'')), " .
                "case when ?='' then null else now() end)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issss", $partnerId, $ip, $netmask, $taggedRoute, $taggedRoute);
            $ok = $stmt->execute();
            $stmt->close();
            $conn->close();

            if ($ok)
            {
                print "Router saved.<br><br>";
                print '<a href="index.php?f=partner&id='.$partnerId.'">Back to partner</a>';
                return;
            }
            print "***** SQL FAILED ******<br><br>";
        }
        else
        {
            print "Invalid partner, destination IP, netmask or tagged-traffic route.<br>";
        }
    }
?>
<form action="index.php"><table>
<tr><td>Public destination IP</td><td><input name="ip" value="<?php print htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>"></td></tr>
<tr><td>Netmask</td><td><input name="nett" value="<?php print htmlspecialchars($netmask, ENT_QUOTES, 'UTF-8'); ?>"></td></tr>
<tr><td>Tagged traffic route</td><td><input name="taggedRoute" value="<?php print htmlspecialchars($taggedRoute, ENT_QUOTES, 'UTF-8'); ?>" placeholder="NetBird IPv4, blank for normal route"></td></tr>
<tr><td colspan="2">Demo 4 uses this NetBird next hop only for tagged traffic to this registered destination. Blank disables special routing.</td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addRouter"><input type="submit" name="submit" value="Submit"><input type="hidden" name="id" value="<?php print $partnerId; ?>"></td></tr>
</table></form>
<?php
}

?>
