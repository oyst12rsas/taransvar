<?php
error_reporting( E_ALL );
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1); 

$szLib = "XmlCommand.class.php";
if (file_exists($szLib))
	require_once $szLib;	//So that can test call the php file directly for debugging
else
	require_once("../".$szLib);	


function colorMark($szSubject, $szColorInstructions)
{
	//E.g: 10.0.0.106(green)^no tagging(blue)
	$records = explode("^", $szColorInstructions);

	foreach ($records as $rec)
	{
		//If instruction is "-<IP>", remove line is contains the Ip 
		if (substr($rec, 0,1) == "-") 
		{
			return "REMOVE: ".substr($rec,1);
			
			if (str_contains($szSubject, substr($rec,1), $szSubject))
				return "[deleted] $szSubject";
		}

	    if (preg_match('/^(.*)\(([^)]*)\)$/', $rec, $m))
		{
	        $text  = $m[1];
   			$color = $m[2];

	        //echo "TEXT: $text\n";
	        //echo "COLOR: $color\n\n";

			$szSubject = str_replace($text,"<b><font color=\"".$color."\">".$text."</font></b>",$szSubject);
		}
	}
	return $szSubject;
}

function dmesg()
{
	$nLastId = $_GET["id"];
    CXmlCommand::setInnerHTML("log", "", "ID: $nLastId");//, $cMoreParamsArr = array())

	$szSQL = "select dmesgId, txt from dmesg where dmesgId > ? order by dmesgId limit 100";
	$dbh = getConnection();
	$row = 0;
	
	$stmt = $dbh->prepare($szSQL);
	$stmt->bind_param("i", $nLastId); 
    $stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		$nCount=0;
		while ($row = $result->fetch_assoc()) 
		{
			$nRowId = "ms".$row["dmesgId"];
	        $cArr = array($row["txt"]); 

        	CXmlCommand::addTableRow("dmesgTbl", "top", $nRowId, $cArr, "", $nRowId);//$szHTML)
		}
	}
}

function dmesgOld()
{
	
	if (isset($_GET["srch"]))
	{
		$szSearch = $_GET["srch"];
		$_SESSION["srch"] = $_GET["srch"];
	}
	else
		$szSearch = isset($_SESSION["srch"])?$_SESSION["srch"]:"";

	if (isset($_GET["filter"]))
		$szFilter = $_SESSION["filter"] = $_GET["filter"];
	else	
		$szFilter = isset($_SESSION["filter"])?$_SESSION["filter"]:"";

	if (isset($_GET["hold"]))
		$szHold = $_SESSION["hold"] = $_GET["hold"];
	else	
		$szHold = isset($_SESSION["hold"])?$_SESSION["hold"]:"";

	if ((int)$szHold)
		return;

	//$szDebug = "Search: $szSearch, filter: $szFilter";
	//CXmlCommand::setInnerHTML("debug", "", $szDebug);

	//CXmlCommand::alert("About to read..");
	$conn = getConnection();
	$szSQL = "select inet_ntoa(adminIp) as ip, dmesg, unix_timestamp(now())-unix_timestamp(dmesgUpdated) as secsAgo from setup";
	$row = 0;
	
	$stmt = $dbh->prepare($szSQL);
	$stmt->bind_param("i", $szIp); 
    $stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		$nCount=0;
		if($row = $result->fetch_assoc()) 
		{
			//Lines come with newest at bottom.. rearrange to newest first
			$lines = explode("\n", ($row["dmesg"]?$row["dmesg"]:""));
			$lines = array_reverse($lines);

			if ((int)$szFilter)
			{
				//Remove lines that didn't contain any text to be colored
				for ($n = 0; $n < sizeof($lines); $n++)
				{
					$szColored = colorMark($lines[$n], $szSearch);
					if ($szColored == $lines[$n])
					 	$lines[$n] = "";
					else
						$lines[$n] = $szColored;
				}

				$lines = array_filter($lines, function($l) {
							    return trim($l) !== '';
							});
				$text_reversed = implode("\n", $lines);
			}
			else
			{
				//Keep all lines.. just do the coloring
				$text_reversed = implode("\n", $lines);
				$text_reversed = colorMark($text_reversed, $szSearch);
			}

				$replaced = str_replace("\n","<br>",$text_reversed);

			//$ip = getSenderIp();
			//$replaced = str_replace($ip,"<b><font color=\"red\">".$ip."</font></b>",$replaced);


			$replaced = colorMark($replaced, $szSearch);

            CXmlCommand::setInnerHTML("logHere", "", $replaced);//, $cMoreParamsArr = array())
        }
    }
}


?>
