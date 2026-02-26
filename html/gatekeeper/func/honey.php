<?php

function honey()
{
	$conn = getConnection();

	$sql = "select port, description, handling from honeyport";//handling: enum('block','normal','ssh','mysql','SQL-server','samba'),

	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		print "<h2>Registered honeyports:</h2><table><tr><td>port</td><td>Handling</td><td>Description</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["port"]."</td><td>".$row["handling"]."</td><td>".$row["description"]."</td>".$row["description"]."</td><td><a href=\"index.php?f=delHoney&port=".$row["port"]."\">[Delete]</h></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No honeyports registered<br>";
	}
	$conn->close();
	print '<br><a href="index.php?f=addHoney">Add honeyport</a>';
}

?>
