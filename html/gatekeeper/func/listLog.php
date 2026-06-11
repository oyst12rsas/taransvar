
<style>
.tableFrame {
    border: 1px solid black;
    padding: 5px;
    display: inline-block;
}
.compactTable {
    border-collapse: collapse;
	border: none;
}

.compactTable tr,
.compactTable td {
    padding: 0;
    margin: 0;
	border: none;
	text-align: left;
}

.compactTable h1,
.compactTable h2,
.compactTable h3,
.compactTable p,
.compactTable div {
    margin: 0;
    padding: 0;
}

.compactTable tr,
.compactTable td {
    border: none;
}
</style>
<script>
var szUpdateRoutine = "dmesg";	

function logSrch()
{
	var szSrch = document.getElementById("crit");
	var bFilter = document.getElementById("filter");
	var bHold = document.getElementById("hold");

	//alert("Filter/mark: "+szSrch.value);

	request("dmesg","srch="+szSrch.value+"&filter="+(bFilter.checked?1:0)+"&hold="+(bHold.checked?1:0));
	return false;
}

</script>
<?php 

function listLog()
{//asdf
	unset($_SESSION["hold"]);

			$szSearch = (isset($_SESSION["srch"]) && strlen($_SESSION["srch"]))?$_SESSION["srch"]:"10.0.0.16(green)^TAG:(red)";
			$szChecked = isset($_SESSION["filter"])&&$_SESSION["filter"]?" checked":"";
			$szHold = isset($_SESSION["hold"])&&$_SESSION["hold"]?" checked":"";

			print '<form onsubmit="logSrch(); return false;">
			<label for="crit">Mark/filter:</label><input id="crit" value="'.$szSearch.'" size="60"> <label for="filter">Filter:</label><input type="checkbox"'.$szChecked.' id="filter"> <label for="hold">Hold:</label><input type="checkbox"'.$szHold.' id="hold">
			<input type="submit"></form>';



	$szAdminIp = 0;
	$conn = getConnection();
	$szSQL = "select inet_ntoa(adminIp) as adminIp from setup";
	$result = $conn->query($szSQL);
	if ($result) 
	{
		if ($result->num_rows > 0 && $row = $result->fetch_assoc()) 
		{
			if (isset($row["adminIp"]))
				$szAdminIp = $row["adminIp"];
		}
		$result->free();
	}

	$nLastId = 0;

	$szSQL = "select dmesgId, unix_timestamp(now())-unix_timestamp(created) as secsAgo, txt from dmesg order by dmesgId desc limit 100";
	$result = $conn->query($szSQL);
	if (!$result)
	{
    	die($conn->error);
	}	
	print "<div class=\"tableFrame\"><table id=\"dmesgTbl\" class=\"compactTable\"><tr style=\"display:none\"><th>ago</th><th>dmesg</th></tr>";
	if ($result && $result->num_rows > 0) 
	{
		$szDmsg = "";
		$szMsg = "";
		$szRows = "";
		while ($row = $result->fetch_assoc()) 
		{
			if (!$nLastId)
			{
				$nLastId = $row["dmesgId"]+0;
				//if ($row["secsAgo"]+0 > 10)
				//	print '<tr><td colspan="3"><font color="rd">Data are not updated!</font></td></tr>';
			}
			$szMsg = $row["txt"];//."<br>".$szMsg;
			//$szNextRow = "<tr id=\"ms".$row["dmesgId"]."\"><td>".$row["secsAgo"]."</td><td>&nbsp;&nbsp;</td><td style=\"text-align: left;\">".$szMsg."</td></tr>";
			$szNextRow = "<tr id=\"ms".$row["dmesgId"]."\"><td style=\"text-align: left;\">".$szMsg."</td></tr>";
			$szRows = $szRows.$szNextRow;	//Insert the new row on top..
		}
		print $szRows;
	}
	else
		print "Unable to read dmesg records..";

	print "</table></div>";
	print "Log <div id=\"log\"></div>";
}

function listLogOld()
{//asdf
	$conn = getConnection();
	$szSQL = "select inet_ntoa(adminIp) as ip, dmesg, unix_timestamp(now())-unix_timestamp(dmesgUpdated) as secsAgo from setup";
	$result = $conn->query($szSQL);
	$row = 0;

	if ($result && $result->num_rows > 0) 
	{
		if ($row = $result->fetch_assoc()) 
		{
			if (!isset($row["secsAgo"]) || (int)$row["secsAgo"] > 20)
				print "<b><font color=\"red\">This content is supposed to be updated every 10 seconds but misc/crontasks.pl seems not to be set up properly</font></b>";
			//else
			//	print "<b>NOTE! This contents was updated ".$row["secsAgo"]." seconds ago</b>. For updated content, ssh ".$row["ip"]." and run sudo dmesg -w</b>";

			$szSearch = (isset($_SESSION["srch"]) && strlen($_SESSION["srch"]))?$_SESSION["srch"]:"10.0.0.16(green)^TAG:(red)";
			$szChecked = isset($_SESSION["filter"])&&$_SESSION["filter"]?" checked":"";
			$szHold = isset($_SESSION["hold"])&&$_SESSION["hold"]?" checked":"";

			print '<form onsubmit="logSrch(); return false;">
			<label for="crit">Mark/filter:</label><input id="crit" value="'.$szSearch.'" size="60"> <label for="filter">Filter:</label><input type="checkbox"'.$szChecked.' id="filter"> <label for="hold">Hold:</label><input type="checkbox"'.$szChecked.' id="hold">
			<input type="submit"></form>';
		//</form><br><br><div id="debug">Debug info here</div>

		//<input id="crit" onkeyup="logSrch()">
			
			print '<table id="dmesgTbl"><tr><td id="lg1"><div id="logHere" align="left">Please wait for content to read (if it\'s set up properly)</div></td></tr></table>';
		}
	}
	if (!$row)
		print "Setup not found!";

	/*$sql = "select lineId, batchId, timeSearch, theTime, theText from dmesgLine order by lineId desc limit 100";
	
	$result = $conn->query($sql);

	if ($result && $result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Log:</h2><table><tr><td>Id</td><td>Time, Text</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
    		print "<tr><td>".$row["lineId"]."</td><td>".$row["theTime"]."</td><td>".$row["theText"]."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No log entries<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	print '<br><a href="index.php?f=addpartner">Add partner</a>';*/
}

?>
