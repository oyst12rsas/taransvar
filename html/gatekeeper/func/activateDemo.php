<?php

function activateDemo()
{
	$conn = getConnection();
	$szSQL = "update demo set activeDemo = b'1'";
	$stmt = $conn->prepare($szSQL);
        $stmt->execute();
        print "Demo activated... <a href=\"index.php?f=demo\">Press here to reload..</a>";
	
}

?>
