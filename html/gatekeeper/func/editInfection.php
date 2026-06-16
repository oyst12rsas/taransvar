<?php

function editInfection()
{
    //print "Should edit infection...";  
	$conn = getConnection();

    if (isset($_GET["subm"]))
    {
        if (isAdmin())
        {
            $szSQL = "update internalInfections set infoSharePartners = ?, severity = ?, handled = b'0' where infectionId = ?";
    		$stmt = $conn->prepare($szSQL);
    		$stmt->bind_param("sii", $_GET["info"], $_GET["sev"], $_GET["id"]); 
        }
        else
        {
            $szSQL = "update internalInfections set infoSharePartners = ?, handled = b'0' where infectionId = ?";
    		$stmt = $conn->prepare($szSQL);
    		$stmt->bind_param("si", $_GET["info"], $_GET["id"]); 
        }

        $stmt->execute();
        print "Infection info saved..";
        return;
    }

	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description, severity, botnetId, infoSharePartner, infoSharePartners from internalInfections I left outer join unit u on u.unitId = I.unitId where infectionId = ?";

   	$stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $_GET["id"]); 
    $stmt->execute();
    $result = $stmt->get_result(); // get the mysqli result
    $nCount = 0;

    if (!$result || !($row = $result->fetch_assoc()))
	{
        print "Error looking up the threat";
        return;
    }

    $szValue = (isset($_GET["subm"])?$_GET["info"]:$row["infoSharePartners"]);

    ?>
    <form action="index.php">
    <table>
        <tr><td>ID</td><td><?php print $row["ip"]; ?></td></tr>
        <tr><td>Last seen:</td><td><?php print $row["lastSeen"]; ?></td></tr>
        <tr><td>Info:</td><td><input name="info" value="<?php print $szValue; ?>"></td></tr>
        <?php
            if (isAdmin())
                print '<tr><td>Severity:</td><td><input name="sev" value="'.(isset($_GET["sev"])?$_GET["sev"]:$row["severity"]).'"></td></tr>';

        ?>
        <tr><td colspan="2"><input type="submit" value="Submit" name="subm"><input type="hidden" name="f" value="editInfection"><input type="hidden" name="id" value="<?php print intval($_GET["id"]); ?>"></td></tr>
    </table>
    </form>


    <?php
}

?>