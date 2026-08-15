<?php

// Backward compatibility for older TaraSec/Taralink nodes.  Keep the historic
// config_update.php URLs working, but let the current validated endpoints do
// the actual work.  Dispatch before loading this file's libraries because the
// endpoints load their own dependencies and terminate the request themselves.
if (isset($_GET["f"])) {
    if ($_GET["f"] === "report") {
        require __DIR__ . "/report.php";
        exit;
    }
    if ($_GET["f"] === "confession") {
        require __DIR__ . "/confession.php";
        exit;
    }
}

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
//config_update.php
//Takes report from partners... 
//config_update.php?ip=<hexip>:<port>&f=hack


//Put this directly into database and process later... e.g in 10 minutes when dhsp leases and conntrack is loaded.... 
//

//require_once "getSenderIp.php";

include "../dbfunc.php";
include "../taraLib.php";
include "../script/tagged.php";

$szFromIp = getSenderIp();
//if (strlen($szFromIp)<7)	#was <10... 10.0.0.16 is <10 yet normal address...
	//$szFromIp = "127.0.0.1";
//	$szFromIp = "192.168.39.160";

//print "F = ".$_GET["f"]."<br>";

$nFromPort = $_SERVER['REMOTE_PORT'];

if (isset($_GET["f"]))
{
	switch ($_GET["f"])
	{
		case "confession":
			//Legacy implementation retained below for reference/backward source compatibility.
			//Runtime requests are dispatched to confession.php at the top of this file.
			if (!isset($_GET["ourid"])){
				echo "(missing params)";
				exit;
			}
			$nOurId = (int)$_GET["ourid"];
			$szSQL = "select reportId, coalesce(remoteUnitId, 0) as remoteUnitId from hackReport where inet_aton(ip) = ? and port = ? order by reportId desc limit 1";
			$conn = getConnection();
			$stmt = $conn->prepare($szSQL);
            $nPort = (int)$_GET["port"];
            $stmt->bind_param("si", $szFromIp, $nPort); 
            $stmt->execute();
			$result = $stmt->get_result();
			if ($result && $row = $result->fetch_assoc())
			{
                $nReportId = (int)$row["reportId"];

				if ($row["remoteUnitId"]+0)
		            addWarningRecord("Received confession from ".$szFromIp.", but remoteUnitId was already set to ".$row["remoteUnitId"]." for hack report ".$nReportId); 

                print "Confession received regarding hack report %s (%s:%u). Setting remoteUnitId = $nOurId%s\n";
				$szSQL = "update hackReport set remoteUnitId = ? where reportId = ?";
	            $stmt = $conn->prepare($szSQL);
	            $stmt->bind_param("ii", $nOurId, $nReportId); 
	            $stmt->execute();
            }
            else
                print "Confession received from $szFromIp, but unable to find hackReport regarding $szFromIp:$nPort\n";
            
			exit;
                        
        case "report":
			//Legacy implementation retained below for reference/backward source compatibility.
			//Runtime requests are dispatched to report.php at the top of this file.
    		{
				if (!isset($_GET["ip"]) || !isset($_GET["port"]) || (strlen($_GET["ip"]) < 7 && strcmp($_GET["ip"],"::1"))){
					echo "(missing params)";
                    exit;
                }
    
				if(!filter_var($_GET["ip"], FILTER_VALIDATE_IP)){
                    echo '(invalid ip: '.$_GET["ip"].')';                    
					exit;
                }

				if(!filter_var($szFromIp, FILTER_VALIDATE_IP) || $szFromIp == '::1'){
                    $szFromIp = '127.0.0.1';
                }

				$szCode = (!isset($_GET["code"]) || !strlen($_GET["code"]) ? "other" : $_GET["code"]);
				$szWhat = (isset($_GET["wt"])?$_GET["wt"]: "hack");
				$conn = getConnection();

				$ip       = $_GET["ip"];
				$port     = (int)$_GET["port"];
				$what     = isset($_GET["wt"]) ? $_GET["wt"] : "hack";
				$ourid    = isset($_GET["ourid"]) ? (int)$_GET["ourid"] : 0;
				$fromPort = (int)$nFromPort;
				logMsg("Hackreport: $ip:$port, $what");

                $szSQL = "select reportId, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from hackReport where ip = inet_aton(?) and port = ? and code = ? and why = ? order by coalesce(lastSeen, created) desc limit 1";
            	$stmt = $conn->prepare($szSQL);
	            $stmt->bind_param("sis", $ip, $port, $code, $what);
	            $stmt->execute();
	            $result = $stmt->get_result();
                $nSeconds = 1000;

            	if ($result) 
				{
					if($row = $result->fetch_assoc()) 
					{
						$nSeconds = $row["seconds_since"]+0;
						$nReportId = $row["reportId"];
					}
					$result->close();
				}

                if ($nSeconds < 30)
                {
					logMsg("Updateing hackReport. id: $nReportId");

                    $szSQL = "update hackReport set count = count + 1 where reportId = ?";
                    $stmt = $conn->prepare($szSQL);
                    $stmt->bind_param("i", $nReportId);
                    $stmt->execute();
					logMsg("Updated..");
                }
				else
				{
					logMsg("Inserting..");

	                $szSQL = "insert into hackReport
						(ip, port, partnerIp, partnerPort, code, why, sentByIp, ipOwnerId)
						values (inet_aton(?), ?, inet_aton(?), ?, ?, ?, inet_aton(?), ?)";

					$stmt = $conn->prepare($szSQL);
					$stmt->bind_param(
						"sisissi",
						$ip,
						$port,
						$szFromIp,
                	    $fromPort,
						$code,
                    	$what,
	                    $szFromIp,
    	                $ourid
        	        );
            	    $stmt->execute();
					logMsg("Inserted..");
				}

                print "ok";
				exit;
			}

        case "ping":
        	{
                if (isset($_GET["status"]))
                    $szStatus = $_GET["status"];
                else
                    $szStatus = "??";

                if (isset($_GET["nick"]))
                    $szNick = $_GET["nick"];
                else
                    $szNick = "??";

                $conn = getConnection();
                $sql = "insert into ping (ip, info, nickName) values (inet_aton(?), ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $szFromIp, $szStatus, $szNick); 
                $stmt->execute();
                print "ok";
                exit;
            }

        case "demo":
            {
            /*	if (isset($_GET["iam"]))
                {
                	$szIam = $_GET["iam"];
                    $sql = "update demo set ".$szIam."Checked = now(), ".$szIam."Status = ?"; 

	                $conn = getConnection();
	                print "$sql";
	                $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $szStatus); 
                    $stmt->execute();
                    print "ok";
                    exit;
                }
                else
                {
                    print "iam parameter not set..";
                    print "ok";
                    exit;
                } */
                        
                $conn = getConnection();
			$szSQL = "update demo set botHostStatus = ? where ipBotHost = inet_aton(?) and activeDemo = b'1';";
                        $stmt = $conn->prepare($szSQL);
                       	$stmt->bind_param("ss", $szStatus, $szFromIp); 
                       	$stmt->execute();
			
			$szSQL = "update demo set targetHostStatus = ? where ipTargetHost = inet_aton(?) and activeDemo = b'1';";
                        $stmt = $conn->prepare($szSQL);
                       	$stmt->bind_param("ss", $szStatus, $szFromIp); 
                       	$stmt->execute();

			$szSQL = "update partnerRouter set demoStatusReceived = now() where ip = inet_aton(?);";
            $stmt = $conn->prepare($szSQL);
            $stmt->bind_param("s", $szFromIp); 
            $stmt->execute();
                       	
            print "ok";
            exit;
        }

		case "requestdmesg":
        {
            #E.g: http://localhost/config_update.php?f=requestdmesg&ip=192.168.1.9
            if (isset($_GET["ip"]))
            {
                $sql = "insert into requestDmesg(ip) values(inet_aton(?))"; 
	        	$conn = getConnection();
	            $stmt = $conn->prepare($sql);
                $szGetIp = $_GET["ip"];
                $stmt->bind_param("s", $szGetIp); 
                $stmt->execute();
                print "ok";
                exit;
            }
            else
            {
                print "ip parameter not set..";
                print "ok";
                exit;
            }
        }
                
        case "partner":
        {
        	$conn = getConnection();
                        
            //Check if this is registered partner..
            $conn = getConnection();
			$szSQL = "select routerId from partnerRouter where ip = inet_aton(?);";
            $stmt = $conn->prepare($szSQL);
            $stmt->bind_param("s", $szFromIp); 
            $stmt->execute();
			$result = $stmt->get_result();
			if ($result && $row = $result->fetch_assoc())
			{
				$szSQL = "update partnerRouter set partnerStatusReceived = now() where routerId = ?";
	            $stmt = $conn->prepare($szSQL);
	            $stmt->bind_param("d", $row["routerId"]); 
	            $stmt->execute();
			}
			else 
			{
				print "Unknown partner: $szFromIp<br>"; 
	            addWarningRecord("**** WARNING **** Received partner status from IP that is not registered as partner: $szFromIp"); 
			}

            print "ok";
            exit;
        }
        case "workshop":
        {
//config_update.php?id=1&me=192.168.100.45&role=router/partner
            $conn = getConnection();
            $szMe = $_GET["me"];
            $szWorkshopId = $_GET["id"]+0;
            $szRole = $_GET["role"];
			$szSQL = "insert into workshop (workshopId, ip, publicIp, role) values (?, inet_aton(?), inet_aton(?), ?) on duplicate key update role = ?, lastseen = now();";
            $stmt = $conn->prepare($szSQL);
            $stmt->bind_param("dssss", $szWorkshopId, $szMe, $szFromIp, $szRole, $szRole); 
            $stmt->execute();

			$szSQL = "select inet_ntoa(publicIp) as publicIp, inet_ntoa(ip) as ip, role from workshop where workshopId = ? and ip <> inet_aton(?) and date(lastseen) = date(now())";
            $stmt = $conn->prepare($szSQL);
            $stmt->bind_param("ds", $szWorkshopId, $szMe);
            $stmt->execute();
			$result = $stmt->get_result();
			$nFound = 0;
			while ($result && $row = $result->fetch_assoc())
			{
				print $row["ip"]."^".$row["role"]."|";
				$nFound++;
			}
			
			if (!$nFound)
				print "NONE";

			exit;                	
        }
		case "unitIp":
			if (!isset($_GET["ip"]) || !isset($_GET["port"]))
			{
				print "Insufficient parameters";
				exit;
			}

			$szIp = $_GET["ip"];
			$nPort = $_GET["port"];
			$conn = getConnection();
			$szSQL = "select inet_ntoa(ipAddress) as ip, TIMESTAMPDIFF(SECOND, lastSeen, NOW()) AS seconds_since, unitId, nickname from unitPort join setup where port = ? order by lastSeen desc limit 1";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("d", $nPort);
			$stmt->execute();
			$result = $stmt->get_result();
			$data = [];
			if ($result && $row = $result->fetch_assoc())
			{
				$data["nickname"] = $row["nickname"];	//260714
				$data["ip"] = $row["ip"];
				$data["sec"] = $row["seconds_since"];
			}
			else
			{
				$data["error"] = "1";
				$data["found"] = "-1";
				$data["message"] = "Searched for $nPort";

				$szSQL = "select TIMESTAMPDIFF(SECOND, lastSeen, NOW()) AS seconds_since from unitPort order by lastSeen desc limit 1;";
				$stmt = $conn->prepare($szSQL);
				$stmt->execute();
				$result = $stmt->get_result();
				if ($result && $row = $result->fetch_assoc())
				{
					if ($row["seconds_since"]+0 < 90)
						$data["updated"] = "1";
					else
						$data["updated"] = "0";
				}
				$data["sec"] = $row["seconds_since"];

			}

			$json = json_encode($data);
			echo $json;				
			exit;

		default:
        	print "Unknown parameter: ".$_GET["f"];
            exit;
                
    }
}

print "(error in parameters)";
// print "<br>Your ip is: ".$ip.", port: ".$nPort."<br>";
?>