<script>
var szUpdateRoutine = "assistance";	
</script>
<?php

function assistance()
{
	// output data of each row  
	print '<h2>Assistance Requests:</h2><table id="assistanceTbl"><tr><th>Id</td><th>Created</th><th>IP</th><th>Port</th><th>Category</th><th>Comment</th><th>Quality</th><th>Purpose</th><th>Handled</th></tr>';
	print "</table>";
}

print '<a href="index.php?f=addassreq">Request assistance from partnert (against DDos attack)</a>';

?>
