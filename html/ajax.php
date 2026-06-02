<?php
session_start();

error_reporting( E_ALL );
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1); 

require_once("gatekeeper/XmlCommand.class.php");

include "gatekeeper/genlib.php";
require_once "gatekeeper/Basic.class.php";
include "gatekeeper/System.class.php";
include "gatekeeper/dbfunc.php";

$pSystem = new CSystem;

if (!function_exists("isAjax"))
{
    function isAjax() {return true;}
}

function reportHacking() {}

function experiencingDbConnectionTrouble()
{
	//print "<bR>DB-Error has been detected... don't know what may cause this...<bR><bR>";
	return false;
}
function myId() 
{
    return 0;
}

function getSystem()
{
    global $pSystem;
    return $pSystem;
}

function tagStatus()
{

}

$szFile = "ajax/".$_GET["func"].".php";

if (file_exists($szFile))
{
    require_once($szFile);
    //print "Calling the func..";
    $_GET["func"]();
}
else
    switch ($_GET["func"])
    {
        case "tagStatus":
            tagStatus();
            break;
        default:
            print "unknown function : ".$_GET["func"];

    }

//CXmlCommand::alert("Ajax called");
CXmlCommand::setInnerHTML("me", "", "Here now");

CXmlCommand::flushXml();


?>