
<html>
<style>

.center {
	margin-left:auto;
	margin-right:auto;
	margin-top:auto;
	margin-bottom:auto;
}
body {
	background-image: url('server.jpg');
}

h1 {
	color: white;
  	text-align: center;
}

table {
  border:1px solid black;
	border-collapse: collapse;
  margin-top: 20px;
  margin-bottom: 20px;
  margin-right: 20px;
  margin-left: 20px;
}

td {
	color: black;
}


.menu-table {
	border: 0px solid black;
	border-collapse: collapse;
	background:#7fb5da;
  margin-top: 20px;
  margin-bottom: 20px;
  margin-right: 20px;
  margin-left: 20px;
}

.menu-table-td td {
	background:#7fb5da;
}

        td {
            border: 1px solid #7a3f3f;
            padding: 20px;
            text-align: center;
		border-collapse: collapse;
        }

.orange-text { 
    color: white; 
    font-weight: bold; 
    } 

</style>
<head>
<title>AB Gatekeeper Dashboard</title>
</head>
<body>
<table class="center"><tr><td bgcolor="#AAB396">
<?php

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

include "dbfunc.php";



function showMenu()
{ ?>
<h1>AB Gatekeeper Dashboard</h1>
<table>
<tr>
<td bgcolor="white"><a href="userpt.php?f=usage">Usage</a></td>
</tr>
</table>
<?php
}

function getString($szSQL)
{
	$conn = getConnection();
//asdfasdf
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Last requests:</h2><table>";
		if ($row = $result->fetch_row()) 
		{
			$conn->close();
			print "<br>getString() returned ".$row[0]."<br>";
			return $row[0];
	  	}
	} 
	return false;
	$conn->close();
}


function listRpt()
{
	$conn = getConnection();

	$sql = "SELECT received, name, nickName, inet_ntoa(P.ip) as ip, info from ping P left outer join owner O on O.ip = P.ip order by pingId desc limit 200";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered partners:</h2><table>
			<tr><td>Id</td><td>Name</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["name"]. "</td><td>".$row["nickName"]. "</td><td>".$row["received"]. "</td><td>".$row["ip"]. "</td><td>".$row["info"]. "</td>";
	    		//print '<td><a href="index.php?f=delpartner&ip='.$row["partnerId"].'">[Delete]</a></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "<tr><td colspan=\"2\">No registrations found!<br></td></tr>";
		print "</table>";
	} 
	else 
	{
	  echo "No partners registered<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	//print '<br><a href="index.php?f=addpartner">Add partner</a>';
	//print '<br><br><a href="index.php?f=listrouters">List all routers</a>';
	
}


showMenu();
//showLog();
if (isset($_GET['f']))
switch($_GET['f'])
{
        case 'usage':
                listRpt();
                break;
	default:
		print 'Unknown menu choice';
}

?> 
</td></tr></table>

</body>
<html>
