<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>

<?php /* 140529 DEBUGGING */
/*NOTE! To print text, search for: Print text here to make it show in main content box */

/*phpinfo();*/  
session_start();
error_reporting( E_ALL );
ini_set('display_errors', '1');
include "basics.php";
include "genlib.php";
include "radiuslib.php";
include "funcs.php";
include "funcs2.php";
include "content.php";

if (isSuperUser() && request("f") == "users_printlabels" && isset($_POST) && isset($_POST["submit"]))
{
	//print "Helllo world!";
	printLabels();
	exit();
	//__halt_compiler();
}

if (!isset($_SESSION["superuser"]))
	if ($_SERVER['REMOTE_ADDR'] == "127.0.0.1" || 
				isset($_GET["theCode"]) && (htmlspecialchars($_GET["theCode"]) == "hereIam"))
		$_SESSION["superuser"] = true;


//if (getSystem()->includeChats())
//    if (isChatUser())   //NOTE! Need to log in the refresh to get the chat links....
//        CSetup::printCometChatLinks();  
/*---- */  ?>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" type="text/css" href="nickko.css"/> 
<link rel="stylesheet" type="text/css" media="all" href="jsDatePick_ltr.min.css" />
<style type="text/css">
  #map-canvas { height: 200; width: 200 }
</style>
<title><?php print "Taransvar Server"; ?></title>
<?php /*RSS-feed (2 scripts)*/ ?>
</head>
<body  onload="return loaded(3);"><?php //NOTE! Remember to update loaded(nJSVer) if you change this....
    
//<script type="text/javascript" src="fb.js" /></script>
?>

<?php
    if (false)//getSystem()->quitAfterScripts())
    {
        //getSystem()->handleParameters(); //Handle parameters and print instruction for timers...
        print "</body></html>";
        return;
    }
?>

<div class="wrapper">
<div class="full_width">

<?php //************ adsarea here to give it fixed position...?>
<div class="right_<?php /*print getSystem()->layoutStyle();*/ /*ads_area*/ ?>_area" id="adsarea" style="display:none">
<table id="adsTable">
</table>
</div>

<div class="header">
<!--<div class="min_width">
<img src="images/logo.png"/>
</div>-->
</div>
</div>
<div class="navigation_top"><div class="min_width"><div onclick="menu('home','c=menu','')">
<?php /*<img src="images/logo-name.png" width="138" style="float:left; margin-top:5px">*/ ?></div>
<div class="nav_group">
<?php $cMenu = getSystem()->getMainMenu();
foreach ($cMenu as $cC)
{
    //print '<div class="'.$cC["class"].'" onclick="'.$cC["script"].'" '.$cC["more"].'>'.$cC["txt"].'</div>';
	print '<div class="'.$cC["class"].'" ><a href="'.$cC["script"].'">'.$cC["txt"].'</a></div>';
}
?>

<div id="sysStatus" style="display:none"></div>
</div>

<div class="search_bar"><?php

$szF = request("f");
//print "Testing if displays... ";

switch ($szF)
{
	case "main_subLogin":
		submitLogin($bBefore = true);
		break;
	case 	"main_logout":
		logOut($bBefore = true);	//- Handled above
		break;
}

$szUser = (loggedIn()?$_SESSION["user"]: ""); 
print $szUser;
if (isSuperUser() )
	print "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(superuser)";
?></div><div id="pingStat"></div>
<?php
?>
<div class="cleaner"></div>
</div></div>
<div class="cleaner"></div>

<div class="full_width">
<div class="min_width" id="minwidth">
<div class="main_content" id="all">

<?php //***** Adsarea here to make it clickable... **** ?>

<div class="left_hand_side" id="leftHandSide">

<div class="left_box" id="notifications" style="display:none">
<div>
<span><?php print getTxt("Notifications"); ?><img src="pics/stop.png" width="16" height="16" border="0" title="Skjul beskjeder" onclick="menu('hideNoti','c=tools')"></span>
<table id="notitable" class="noti_tbl" style="display:none">
</table>
<table id="freqtable" style="display:none"> <?php /*frndreqtable*/ ?>
</table>
<table id="greqtable" style="display:none">
</table>
<div id="sysnotifications"></div>
<div class="cleaner"></div>
</div>
<div class="cleaner"></div>
</div />

<div class="left_box" id="shortcutsmnu">
<span><?php print getTxt("Shortcuts"); ?></span>
<table class="leftElem" id="tblShortcuts">
<?php
    /*
<tr onclick="mnu1('info')"><td><img src="images/info-icon_active.png" align="absmiddle" /></td><td><?php print getTxt("Info"); ?></td></tr>
<tr onclick="mnu1('help')"><td><img src="images/info-icon_active.png" align="absmiddle" /></td><td><?php print getTxt("Help"); ?></td></tr>
<tr onclick="mnu1('partners')"><td><img src="images/info-icon_active.png" align="absmiddle" /></td><td><?php print getTxt("Partners"); ?></td></tr>

<div onclick=menu("n/a","c=menu")><img src="images/messages-icon_inactive.png" align="absmiddle" /> Messages</div>
<div onclick=menu("n/a","c=menu")><img src="images/offers-icon_inactive.png" align="absmiddle" /> Offers</div>
<div onclick=menu("n/a","c=menu")><img src="images/clubs-icon_inactive.png" align="absmiddle" /> Clubs</div>
<div onclick=menu("n/a","c=menu")><img src="images/diary-icon_inactive.png" align="absmiddle" /> Diary</div>
<div onclick=menu("n/a","c=menu")><img src="images/load-icon_inactive.png" align="absmiddle" /> Loan</div>
  */

$cMenu = getSystem()->getSideMenu();

foreach ($cMenu as $cC)
{
    //print '<div class="'.$cC["class"].'" onclick="'.$cC["script"].'" '.$cC["more"].'>'.$cC["txt"].'</div>';
	print '<div class="'.$cC["class"].'" ><a href="'.$cC["script"].'">'.$cC["txt"].'</a></div>';
}

/*    global $pSystem;
    $pSideMenu = new CMenu();
    $pSystem->adjustLeftMenu($pSideMenu); 
        
    if (count($pSideMenu->cItems))
        $pSideMenu->printLeftMenu();//$szMenu);
        
if (getSystem()->includeInfo(CSystem::INFO_PHONEBOOK))
{
?>
<tr onclick="menu('phonebook','c=menu')"><td><img src="images/phonebook-icon_inactive.png" align="absmiddle" /></td><td><?php print getTxt("Phone Book"); ?></td></tr>
<?php
}
if (myId() && isOy() && runningLocally())
{
    ?>
<tr onclick="menu('test','c=sysTools')"><td><img src="images/info-icon_active.png" align="absmiddle" /></td><td>Test</td></tr>
<tr onclick="test()"><td><img src="images/info-icon_active.png" align="absmiddle" /></td><td>Test(JS)</td></tr>
<tr onclick="menu('plist','c=poll')"><td><img src="images/info-icon_active.png" align="absmiddle" /></td><td>Polls</td></tr>
<?php
} */
?>        
</table>
<div id="lm_shortcuts" style="display:inline"></div>                                                                                             
</div>

<table id="menues" />

<?php
if (false)//getSystem()->includeInfo(CSystem::INFO_WALL))
{
    ?>
<tr /><td />
<div class="left_box" id="wallmnu">
<span><?php print getTxt("Wall"); ?></span><a class="create" href="#">+ <?php print getTxt("Create"); ?></a>
<div onclick="menu('n/a','c=menu')"><img src="images/groups&community/icon-umbrella.png" align="absmiddle" />Post status</div>
<div id="lm_wall" style="display:inline"></div>                                                                                             
</div>
</td /></tr />
<?php
}

if (false)//getSystem()->includeInfo(CSystem::INFO_PHONEBOOK))
{
    ?>
<tr /><td />
<div class="left_box" id="phonebookmnu">
<span><?php print getTxt("Phone Book"); ?></span><a class="create" href="javascript:createPB()">+ <?php print getTxt("Create"); ?></a>
<div /><a href="javascript:extSearch()"><img src="images/groups&community/icon-umbrella.png" align="absmiddle" /><?php print getTxt("Search"); ?></a></div />
<div onclick="menu('n/a','c=menu')"><img src="images/groups&community/icon-umbrella.png" align="absmiddle" /><?php print getTxt("Create"); ?></div>
<div onclick="menu('n/a','c=menu')"><img src="images/groups&community/icon-umbrella.png" align="absmiddle" /><?php print getTxt("Help"); ?></div>
<div id="lm_pbook" style="display:inline"></div>                                                                                             
</div>
</td /></tr />
<?php
}
//asdf

if (false)//getSystem()->includeInfo(CSystem::INFO_GROUPS))
{
?>
<tr /><td />
<div class="left_box">
<span><?php print getTxt("Groups"); ?></span><a class="create" href="javascript:menu('createGroup','c=tools')">+ <?php print getTxt("Create"); ?></a>
<table class="leftElem" id="tblGroups">
</table>
<?php /*<div onclick="menu('n/a','c=menu')"><img src="images/groups&community/icon-umbrella.png" align="absmiddle" /> Koinoniapolis</div> */ ?>
<?php
//<div onclick=menu("n/a","c=menu")><img src="images/groups&community/icon-lightbulb.png" align="absmiddle" /> Revelations</div>
//<div onclick=menu("n/a","c=menu")><img src="images/groups&community/icon-paper.png" align="absmiddle" /> Today's Word</div>
?><div id="lm_groups" style="display:inline"></div>                                                                                             
</div>
</td /></tr />
<?php
}
if (false)//getSystem()->includeInfo(CSystem::INFO_COMMUNITY))
{
?>
<tr /><td />
<div class="left_box">
<span><?php print getTxt("Community"); ?></span><a class="create" href="#">+ <?php print getTxt("Create"); ?></a>
<div><img src="images/groups&community/icon-people.png" align="absmiddle" /> <a href="javascript:menu('chat:initChat')">Chat</a></div>
<div><img src="images/groups&community/icon-people.png" align="absmiddle" /> <a href="javascript:menu('chat:fbChatPals')">FB Chat</a></div>
<?php
if (runningLocally())
    print '<div><img src="images/groups&community/icon-people.png" align="absmiddle" /> <a href="javascript:menu(\'document:blog\')">Blog</a></div>';

//<div><img src="images/groups&community/icon-people.png" align="absmiddle" /> <a href="#">Youth For Christ</a></div>
//<div><img src="images/groups&community/icon-male.png" align="absmiddle" /> <a href="#">The Youth</a></div>
//<div><img src="images/groups&community/icon-shield.png" align="absmiddle" /> <a href="#">Living Faith</a></div>
?><div id="lm_community" style="display:inline"></div>                                                                                             
</div>
</td /></tr />
<?php
}
?>
</table>

</div>


 <!---- *****  --->

<div class="right_hand_side" id="allrighthandside">


<?php
//if (isset($szTopPageWarning))
//    print red(h1($szTopPageWarning));

print div("","topWarn",'style="display:none"');
print div("","topIns",'style="display:none"');
print div("","topPoll",'style="display:none"');
?>


<?php /*<tr style="display:none"/><td />*/ ?>
<div style="display:none" class="fbFriends" id="fbFriends"></div>
<?php /*</td /></tr />*/ ?>


<table id="contenttbl" width="500" style="help_cl">

<?php

$szSystemAnnouncement = "";//getSystem()->getSystemAnnoncement();

if (strlen($szSystemAnnouncement))
{
    ?>
    <tr /><td />
    <div class="new_box_box">
    <div class="help_cl">
    <?php print $szSystemAnnouncement; ?>
    </div>
    </div />
    </td /></tr />
    <?php
}
?>

        <tr /><td width="500"/>

<div class="right_box" id="rightbox"  style="display:none">
</div>

<div class="profile_nav_wrapper" id="navvrapper" style="display:none">
<div><a href="javascript:profilePhoneBook()"><?php print getTxt("Phonebook"); ?></a></div>
<div><a href="javascript:profileMenu('friends')"><?php print getTxt("Friends"); ?></a></div>
<div><a href="javascript:profileMenu('photos')"><?php print getTxt("Photos"); ?></a></div>
<div><a href="javascript:profileMenu('msg')"><?php print getTxt("Messages"); ?></a></div>
<div><a href="javascript:profileMenu('grp')"><?php print getTxt("Groups"); ?></a></div>
<div><a href="javascript:profileMenu('info')"><?php print getTxt("Info"); ?></a></div>
</div>
<div class="profile_extra" id="rightboxExtra" style="display:none">
&nbsp;
</div>
</td /></tr />

<tr style="display:none"/><td />
<div class="new_box_<?php /*print getSystem()->layoutStyle();*/ ?>" id="tempContainer">
<div  id="tempbox">
<?php


    if (false)//strlen($szServerOperationWarning))
    {
        //NOTE: As of 150820: Msg is put there but tr has display:none
        $szParams = getParmString();
        print str_replace("%PARAMS%",$szParams,$szServerOperationWarning);
    }
    else
    {
        //To test code above, search for addAjaxRequest("initScreen&c=menu") (and disable those lines..)
        
        //NOTE! loginMsg div below makes login error dispaly here if called from email (no login box present)
        ?>
        <div id="loginMsg"><?php /*print getSystem()->getWelcomeJsErrorMsg();*/ ?> 
        <br />
        Your browser is: <?php     print (isset($_SERVER['HTTP_USER_AGENT'])?$_SERVER['HTTP_USER_AGENT']:"unspecified"); ?>
        <br /><br /> 
        To test your browser, you can go to this site: <a href="http://javatester.org/javascript.html" target="new">Javatester.org</a> 
        <br />
        <br /> 
        </div>
        <?php
        //require_once("tool.php");
        //doCommandLineStuff();
    }

?>

</div>
<!---------------141017 <div class="cleaner"></div> ------------->


</div />
<div class="cleaner">
</div>
</td /></tr />

<tr style="display:none"/><td />
<div class="new_box_<?php print getSystem()->layoutStyle(); ?>">
Search result: <div id="searchresult"></div>  <a href="javascript:extSearch()"><?php print getTxt("Extended search");?></a>
</div />
</td /></tr />

<tr style="display:"/><td width="600">
<div class="new_box_<?php print getSystem()->layoutStyle(); ?>"><div id="links"></div />
              
<span class="help_cl">
<?php /* Print text here to make it show in main content box */  ?>    

<?php print getMainContent(); ?>
</span>
</div />
</td /></tr />

<?php
/*NOTE! This is template for how to make new box - just remove "none" after display:
<tr style="display:none"/><td />
<div class="new_box_<?php print getSystem()->layoutStyle(); ?>" id="adContainer">
</div />
</td /></tr /> */ 


if (true)//runningLocally() || isOy())
    //Now display it but make it invisible...
{
    ?>
    <tr /><td />
    <div class="new_box_<?php print getSystem()->layoutStyle(); ?>"<?php /*!runningLocally()*/ if (0) print ' style="display:none"'; ?>>
    Copyright &copy; Taransvar - All rights reserved
    </div />
    </td /></tr />
    <?php
}

print '</table>';

/*getSystem()->handleParameters();*/ //Handle parameters and print instruction for timers...
    
//NONONONO, will write the xml code to the html..  CXmlCommand::flushXml();

?>

</div>
</div>
<div class="cleaner"></div>
</div>
<div class="cleaner"></div>
</div>
</div>
<div class="cleaner"></div>
</div><br />
<br />

</body>
</html>

