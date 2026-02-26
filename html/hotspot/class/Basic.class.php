<?php

class CBasic
{                                  
	public $nSessionVarId = 0;
    
    
	/* Functions to define in child classes */
public function getHeadTags() {}
public function mayAccessAnonymously() {return false;}
public function getId() 
{
    herIfOy(get_class($this)." doesn't have getId() function..");
    return -1;
}

public function getSessionVarId()
{      //150111
	if (!$this->nSessionVarId)
	{
		if (!isset($_SESSION["classes"]))
		{
			$_SESSION["classes"] = array();
			$_SESSION["class_token"] = array();
		}
			
		$this->nSessionVarId = sizeof($_SESSION["classes"])+1;
		$_SESSION["classes"][] = true;
		//$_SESSION["class_token"][] = true;
        $_SESSION["class_token"][$this->nSessionVarId - 1] = rand_string(20);
	}

    $this->serialize();        

	//her("Serialized: ".$this->nSessionVarId);
	//return $this->nSessionVarId;
	return $_SESSION["class_token"][$this->nSessionVarId - 1];
}

public function serialize()
{
    $_SESSION["classes"][$this->nSessionVarId - 1] = serialize($this);
}


public function getLink($szFunc="", $szLinkTxt="") 
{
    //return '<a href="'.getPath().'?cl='.$this->getSessionVarId().(strlen($szFunc)?"&f=".$szFunc:"").'">'.(strlen($szLinkTxt)?$szLinkTxt:$this->getName()).'</a>'; 
    return '<a href="javascript:cl(\''.$this->getSessionVarId().'\',\''.(strlen($szFunc)?"f=".$szFunc:"").'\')">'.(strlen($szLinkTxt)?$szLinkTxt:$this->getName()).'</a>'; 
}

static public function getClassLink($szClass, $szFunc, $szParams, $szTxt)
{
    //return '<a href="'.getPath().'?c='.$szClass.'&f='.$szFunc.'">'.$szTxt.'</a>';
    return '<a href="javascript:menu(\''.$szFunc.'\',\'&c='.$szClass.'&'.$szParams.'\')">'.$szTxt.'</a>';
}

public function functionRequiresLogin($szFunc)
{
    return true;    //All functions requires login by default....    
}   
    
public function submitted() 
{
    $this->printMenu(); 
    her("Not learned to save yet..."); 
    return true;
}

static public function staticDispatch() {} //To be redefined...
public function dispatch() 
{
	//herIfOy("CBasic::dispatch()");
	if (isset($_POST["Submit"]))
    {
		if ($this->submitted())
			return;
        else
            $this->edit();
    }
    else
		$this->show();
}
public function getName() {return "Undefined class function"; }
public function mayAccess($nLevel, $bMenuPrinted, $szTechInfo, $bReportIfNoAccess = true) {/*Redefined*/	}

public function getClass()
{
	her("Should not be called...");
/*		$nSavedClassNo = get("cl")+0;
	her("Saved: $nSavedClassNo");
	
	if ($nSavedClassNo)
	{
		if (isset($_SESSION["classes"]) and sizeof($_SESSION["classes"]) > $nSavedClassNo)
			$pActivity = $_SESSION["classes"][$nSavedClassNo-1];
		else
			saveHackingReportToDb($szMsg, "Activity", -1, "CActivity::dispatch(): Unknown class");

	}*/
}

public function printAjaxRequest()
{
    //addJavaRequest("cl=".$this->getSessionVarId()); //."&clasname=".get_class($this)
    CXmlCommand::ajaxRequest("cl=".$this->getSessionVarId());
}

/*static function display($szTxt, $bAdd = false)
{
    CXmlCommand::setInnerHTML("tempbox","",$szTxt);
    CXmlCommand::moveToTop("tempbox");
} */
        
static function initForm()        
{
    self::display(""); //Empty the form
}

static function addToForm($szTxt)        
{
    self::display($szTxt, true); //true = add to the form
}


        
static function display($szTxt, $bAdd = false, $bMoveToTop = true)
{
    if ($bAdd)
        CXmlCommand::addToInnerHTML("tempbox","",$szTxt);
    else
        CXmlCommand::setInnerHTML("tempbox","",$szTxt);

    if ($bMoveToTop)        
        CXmlCommand::moveToTop("tempbox");
}

/*static public function getClassName()
{
    return "CBasic";    //according to help page.. get_called_class(); is available from php mer 5.3
} */   
    
static public function getJavascriptLink($szClassName = "")
{
    //$szClassName = get_called_class(); //is available from php mer 5.3
    //Not working for static calls... : $szClassName = CBasic::getClassName();
    
    $szFilename = 'classjs/'.$szClassName.'.js';//get_class($this);
    if (file_exists($szFilename))
        return '<script type="text/javascript" src="'.$szFilename.'" /></script>';

    reportHacking("Class JS not found: $szFilename");
    return "";
}


public function addAjaxClassRequestWithParams($szFunc, $szMoreParams = "")
{
    
    $szRequest = "cl=".$this->getSessionVarId()."&f=".$szFunc.(strlen($szMoreParams)?"&".$szMoreParams:"");
    global $pSystem;
    $pSystem->addAjaxRequest($szRequest);
}


public function printAjaxReplyPrefix() {print $this->getSessionVarId()."|"; }
public function memberFunctionIsDefined($szFunc) {return false;}
public function handleAjaxRequest()
{
    $szFunc = getFunc("func");
    
    if (!strlen($szFunc))
        $szFunc = get("f");

    if ($this::memberFunctionIsDefined($szFunc))
    {
       if (!method_exists($this,$szFunc))
            reportHacking("Tool with unknown function ($szFunc) filed");
       else
       {
            $this->$szFunc();
            return true;
       }
    }
    else
        reportHacking("Class function not defined: $szFunc");
    
    herIfOy("Unhandled Ajax request... (".get_class($this).")");
    return false;
}

public function getAjaxReplyContainer()
{
    return '<div id="'.$this->getSessionVarId().'" style="display:inline"></div>';
}

public function printAjaxReplyContainer()
{
    print $this->getAjaxReplyContainer();
}

public function adjustMainMenu($pMenu) {}
public function adjustLeftMenu($pMenu) {}

public function submit() {herIfOy("Unhandled submit..."); } //Should be redefined to do some action.
public function action() {herIfOy("Unhandled action..."); } //Should be redefined to do some action. Called from menu system
public function edit()
{
    //Redefine to implement editing...
    $this->show();
}
public function show()
{
//	$this->printMenu();
	$this->printForm();
}

public function printJS($szScript)
{
    ?>
     <script type="text/javascript" src="<?php print $szScript; ?>" /></script>
     <?php
}

public function getFormMain()
{
	return (isOy()?"Main form not redefined...(use getMainForm instead of printMainForm in deriving class)":"");	//should print <tr>s (already in table...) 2 colums are recommended unless you also redefine printFormButtonsRow()
	//her($this->getSessionVarId());
}

public function printFormMain()
{
    print getFormMain();
}

public function getFormButtensRowExtraFlds(){return "";} //To be redefined...


public function getClassInfo()
{
    //$pSystem->postAjaxRequests();
    $nId = $this->getSessionVarId();
    return '<input type="hidden" name="cl" id="cl" value="'.$nId.'"> ';
    //serialize ( mixed $value )
    //her("id:".$this->nSessionVarId); 
}

public function printClassInfo()
{
    print $this->getClassInfo();
}

public function getFormButtonsRow($szFunc = "")
{
	$szTxt = '<tr>
		<td>&nbsp;</td>
		<td>
		<input type="submit" id="Submit" name="Submit" value="'.getTxt("Submit").'">';
        
        if (strlen($szFunc))
        {
            $cArr = explode("=", $szFunc);
            if (count($cArr)>1)
            {
                $szFunc = $cArr[1];
                $szFName = $cArr[0];
            }
            else
                $szFName = "func";
                
            $szTxt .= '<input type="hidden" id="'.$szFName.'" name="'.$szFName.'" value="'.$szFunc.'">';
        }
            
        $szTxt .= $this->getClassInfo();
        $szTxt .= $this->getFormButtensRowExtraFlds(); 
        $szTxt .= '</td>
        	</tr>';
	return $szTxt;
}

public function printFormButtonsRow()
{
    print $this->getFormButtonsRow();
}

public function getSubmitFieldList($szFormName = "")
{
    return "";  //Redefine to list of fields to send back from JS when submitting (typically edit fields in the form..       'field
}

public function getFormSubmitParams($szFunc)
{
    return "f=".$szFunc;
}

public function getFormStart($szFunc = "")
{
    //130427: Form id var foer: "form1"
    //$szScript = 'return clsubmit(this, \''.$this->getSubmitFieldList($szFormName).'\')';
    //$szScript = 'javascript:cl(\''.$this->getSessionVarId().'\',\''.$szFunc.'\',\''.$this->getSubmitFieldList($szFormName).'\')';
    //NOTE! THis works with onsubmit and not action (sending action to javascript is undocumentet according to resources...
    $szScript = 'return clsubmit(this, \''.$this->getFormSubmitParams($szFunc).'\',\''.$this->getSubmitFieldList($szFunc).'\')';
    
    return '<form id="'.$this->getSessionVarId().'" name="'.$this->getSessionVarId().'" method="post" onsubmit="'.$szScript.'">
    <table width="100%" border="0">';                                                                                                                                                
}
    

public function getFormEnd()
{
        return '</table>
        </form> ';       
}

    
public function printForm()
{
    $this->initForm();
    $szTxt = "";//"<h1>First..</h1>";
    $szTxt .= $this->getFormStart();
//    $szTxt .= '<tr><td colspan="2">etter getFormStart()</td></tr>';
    $szTxt .= $this->getFormMain();
//$szTxt .= '<tr><td colspan="2">etter getFormMain()</td></tr>';
	$szTxt .= $this->getFormButtonsRow();
//    $szTxt .= '<tr><td colspan="2">etter getFormButtonsRow()</td></tr>';
    $szTxt .= $this->getFormEnd();
//    $szTxt .= "Etter getFormEnd()";
    $this->addToForm($szTxt);
}


public function printMenu($szSideMenu = false, $szProfile = 0)
{
    if (isNickkoVersion())
        return;
    
	global $const_colors, $pSystem;
    
    if ($pSystem->bMenuPrinted)
        return;
    else
        $pSystem->bMenuPrinted = true; 
	
	$const_colors["color_bg"] = $const_colors["CellBG"] = $const_colors["color_frame"]= "#91B4FF";
	$const_colors["FontColor"] = "black";
	$const_colors["color_heading"] = "#dd#dd#dd";
	$const_colors["Border"] = 0;
/*body bg color */
//$color_bg = "#91B4FF"; //1A6DFF;	//	//D1D1D1	//d0e4fe;

/**** Meny colors **** */
//$bBorder = 0;
//$szCellBG = $color_bg; //"#0000ff";//"#d0d0ff";
//$szFontColor = "black";
//$color_frame = $color_bg;//"#00#00#dd";
//$color_heading = "#dd#dd#dd";	//$color_bg; //
	
$szTxt = '<table width="800" align = "center" valign="top">
<tr>
<td>
<table>
<tr>
<td colspan="2"> 
	<table border="0" bgcolor="'.$const_colors["color_heading"].'" align="left" width="800">

		<tr>
		<td>';
        
        if (isNickkoVersion())
        {
            print  $szTxt;
		    printSearchForm();  
        }

		$szTxt = '</td>
		<td>
		<table align="right" border="'.$const_colors["Border"].'" bgcolor="'.$const_colors["color_frame"]; /*NOTE!!! tyui nothing is printed!!!!*/ 
        $szTxt .= '"><tr>';

		//$cMenuArray = array(getTxt("Home").'^home', getTxt("Profile").'^profile', getTxt("Annonse").'^ad', getTxt("Calenders").'^calendermenu=calenders');

		$pMenu = new CMenu;
		$pMenu->add(getTxt("Home"), "home");
		$pMenu->add(getTxt("Profile"), "profile");
		$pMenu->add(getTxt("Annonse"), "ad");
		$pMenu->add(getTxt("Calenders"), "calendermenu=calenders");

		if (strtolower(getCountryCode()) == "ph")
		{
			//$cMenuArray[] = getTxt("Loan").'^loan';
			$pMenu->add(getTxt("Loan"), "loan");
		}

		//$cMenuArray[] = (myId()>0?getTxt("Logout").'^logout':getTxt("Login").'^logout');
		$pMenu->add(myId()>0?getTxt("Logout"):getTxt("Login"), 'logout');
		
		//$cMenuArray[] = getTxt("Help").'^func=help&top='.(isset($_GET["func"])?$_GET["func"]:"");
		$pMenu->add(getTxt("Help"), 'func=help&top='.(isset($_GET["func"])?$_GET["func"]:""));
		
		$this->adjustMainMenu($pMenu);//$cMenuArray);

        if (!isNickkoVersion())
        {        
            global $pSystem;
            if ($pSystem)
                $pSystem->adjustMainMenu($pMenu);
            else
                her("No system!");
    /*		foreach ($cMenuArray as $szElement)
		    {
			    $cFlds = explode("^", $szElement);
			    
			    if (strpos($cFlds[1], "="))
				    $szFuncParam = $cFlds[1];
			    else
				    $szFuncParam = 'func='.$cFlds[1];
				    
			    ?>
			    <td bgcolor="<?php print $const_colors["CellBG"]; ?>" width=85 align=middle>
			    <a href="<?php print getPath().'?'.$szFuncParam.'"><font color="'.$const_colors["FontColor"].'">'.$cFlds[0]; ?></font></a>
			    </td>
			    <?php
		    } 
    */		
		    $pMenu->printTopMenu();
		}
		
        $szTxt .= '</tr>
	</table>

</tr>
</table>
</td>
</tr>

<tr>';
 /*------------ Row with everything below the top menu... Left menu is one column and rest is other column. ----------*/ 
    $szTxt .= '<td><table>
<td valign="top">';

    print $szTxt;

	//Left hand menu 	
	//her("Left hand");

	if ($szSideMenu === false)
		$szSideMenu = "Home";
		
	$cSideMenu = false;
	$szMenu = "leftmenu";	//Lets code oerride default 
	$pSideMenu = new CMenu();

	switch($szSideMenu)
	{
		case "Ad":
			if (myId())
				printProfilePic(myId());	//If logged in.
			
			//$cAdMenu = array(getTxt("Overview").'^admenu=overview', getTxt("New ad").'^admenu=placeAd', getTxt("Dagens").'^admenu=adbatch', getTxt("Announcement").'^admenu=listanno', getTxt("Sales").'^admenu=salesrpt', getTxt("Keywords").'^admenu=keywords', getTxt("Search").'^admenu=search');
			$cAdMenu = array(new CMenuItem(getTxt("Overview"), 'admenu=overview'), 
								new CMenuItem(getTxt("New ad"), 'admenu=placeAd'), 
								new CMenuItem(getTxt("Dagens"), 'admenu=adbatch'), 
								new CMenuItem(getTxt("Announcement"), 'admenu=listanno'), 
								new CMenuItem(getTxt("Sales"), 'admenu=salesrpt'), 
								new CMenuItem(getTxt("Keywords"), 'admenu=keywords'), 
								new CMenuItem(getTxt("Search"), 'admenu=search'));
			
			//$cSideMenu = array_merge($cAdMenu, getMainMenuArray());
			$pSideMenu->cItems = array_merge($cAdMenu, getMainMenuArray());
			break;
		
		case "Secretariat":	//Gives separat onload js call
		case "Calender":
		case "Home":
			if (myId())
				printProfilePic(myId());	//If logged in.
				
			if ($cSideMenu== "Calender")
				//$cPreCalender = array(getTxt("All Calenders").'^calendermenu=listcalenders', getTxt("Add Calendar").'^calendermenu=addcalender', getTxt("M&oslash;ned").'^calendermenu=monthly');
				$cPreCalender = array(new CMenuItem(getTxt("All Calenders"), 'calendar:listCalendars'), 
								new CMenuItem(getTxt("Add Calendar"), 'calendara:addcalender'),
								new CMenuItem(getTxt("Måned"), 'calendar:monthly'));
			else
				$cPreCalender = array();
		
			//$cSideMenu = array_merge($cPreCalender, getMainMenuArray());
			$pSideMenu->cItems= array_merge($cPreCalender, getMainMenuArray());
			
			//$cSideMenu[] = getTxt("Phone Book").'^show';
			$pSideMenu->add(getTxt("Phone Book"), 'show');
			
			if (runningLocally()) 	//localhost..
			{
				//$cSideMenu[] = getTxt("Accounts").'^Accounts';
				//$cSideMenu[] = getTxt("Wiki").'^wiki';
				$pSideMenu->add(getTxt("Accounts"), 'Accounts');
				$pSideMenu->add(getTxt("Wiki"), 'wiki');
			}
			
			//printLeftMenu($cSideMenu);

			if (myId())
				printGroupList();	
				// tyityiu
			break;
		
		case "Profile":
            if ($szProfile+0)
            {
			    printProfilePic($szProfile);
			    //printLeftMenu(explode("#", getTxt("TX MENU Profile")));

			    //$cSideMenu = array(getTxt("Info").'^Info&id='.$szProfile, getTxt("Images").'^images&id='.$szProfile, getTxt("Suggest").'^suggest&id='.$szProfile, getTxt("Paid Chat").'^paidchat&id='.$szProfile, getTxt("Private msg").'^privMsg&id='.$szProfile);
			    $pSideMenu->cItems = array(new CMenuItem(getTxt("Info"), 'Info&id='.$szProfile), 
							new CMenuItem(getTxt("Images"), 'images&id='.$szProfile), 
							new CMenuItem(getTxt("Suggest"), 'suggest&id='.$szProfile), 
							new CMenuItem(getTxt("Paid Chat"), 'paidchat&id='.$szProfile), 
							new CMenuItem(getTxt("Private msg"), 'privMsg&id='.$szProfile));
            }
            else
                her("No profile");
			break;

		case "Group":
			//$cSideMenu = array(getTxt("Grp Info").'^showGroup&gid='.$szProfile, getTxt("Grp Status").'^grpstatus&gid='.$szProfile, getTxt("My grp status").'^mygrpstat&gid='.$szProfile, getTxt("Members").'^showgroupmem&gid='.$szProfile);
			$pSideMenu->cItems = array(new CMenuItem(getTxt("Grp Info"), 'showGroup&gid='.$szProfile), 
							new CMenuItem(getTxt("Grp Status"), 'grpstatus&gid='.$szProfile), 
							new CMenuItem(getTxt("My grp status"), 'mygrpstat&gid='.$szProfile), 
							new CMenuItem(getTxt("Members"), 'showgroupmem&gid='.$szProfile));
			break;

		case "PhoneBook":
			//$cSideMenu = array("Menu^show", "List^crm=list", "Relation^relation", "Forget All^forgetAll", "New^new", "Search^search");
			$pSideMenu->cItems = array(new CMenuItem("Menu", "show"), 
							new CMenuItem("List", "crm=list"), 
							new CMenuItem("Relation", "relation"), 
							new CMenuItem("Forget All", "forgetAll"), 
							new CMenuItem("New", "new"),
							new CMenuItem("Search", "search"));
			break;

		case "Help":
			//$cSideMenu = array(getTxt("System").'^func=system');
			$pSideMenu->cItems = array(new CMenuItem(getTxt("System"), 'func=system'));
			
			if (isWebMaster())
			{
				//$cSideMenu[] = "WM:Users^listusers";
				$pSideMenu->cItems[] = new CMenuItem("WM:Users", "listusers");
				//$cSideMenu[] = "WM:Lang Control^tool=langcntrl";
				$pSideMenu->cItems[] = new CMenuItem("WM:Lang Control", "tool=langcntrl");
				//$cSideMenu[] = "WM:Warnings^tool=syswarnings";
				$pSideMenu->cItems[] = new CMenuItem("WM:Warnings","tool=syswarnings");
				//$cSideMenu[] = "WM:PhpInfo^tool=phpinfo";
				$pSideMenu->cItems[] = new CMenuItem("WM:PhpInfo","tool=phpinfo");
				//$cSideMenu[] = "WM:Test^tool=test";
				$pSideMenu->cItems[] = new CMenuItem("WM:Test","tool=test");
                $pSideMenu->cItems[] = new CMenuItem("WM:Session obj","tool=sesobj");
			}
				
			//printLeftMenu($cSideMenu);
			break;

		case "Exchange":
			//$cSideMenu = array(getTxt("Menu").'^menu', getTxt("Search").'^search', getTxt("Requests").'^requests', getTxt("Add title").'^add', 
			//				getTxt("Authors").'^authors', getTxt("Titles").'^titles', getTxt("Top 10").'^top10', getTxt("Keywords").'^keywords');
							
			$pSideMenu->cItems = array(new CMenuItem(getTxt("Menu"), 'menu'), 
								new CMenuItem(getTxt("Search"), 'search'), 
								new CMenuItem(getTxt("Requests"), 'requests'), 
								new CMenuItem(getTxt("Add title"), 'add'), 
								new CMenuItem(getTxt("Authors"), 'authors'), 
								new CMenuItem(getTxt("Titles"), 'titles'), 
								new CMenuItem(getTxt("Top 10"), 'top10'), 
								new CMenuItem(getTxt("Keywords"), 'keywords'));
			$szMenu = "exchange";
			break;
			
		default:
			if (is_array($szSideMenu))
				//$cSideMenu = $szSideMenu;
				$pSideMenu->cItems = $szSideMenu;
	}
	
	$this->adjustLeftMenu($pSideMenu);//$cSideMenu);

	/*if (is_array($pSideMenu))
		printLeftMenu($pSideMenu, $szMenu);*/

        
    global $pSystem;
    $pSystem->adjustLeftMenu($pSideMenu); 
		
	if (count($pSideMenu->cItems))
		$pSideMenu->printLeftMenu($szMenu);
	else
		print "&nbsp;";	//NOTE! Should not happen anymore.... tested before switch. and set to "Home"..
	
	//her("Left hand finished.");

	?>
	
</td>
<td valign="top" align="left">
<?php //Main contents starts here...  

	//List instant access records..
	if (isset($_SESSION["instantAccess"]))
		foreach($_SESSION["instantAccess"] as $szAccess)
		{
			//print $szAccess."<br>";
			//examble: clubsmenu#secretariat#Go to secretariat page for ongoing match#id=10
			$cFlds = explode("#", $szAccess);
			if (sizeof($cFlds) == 4)
				print '<font size="+1"><b><a href="'.getPath().'?'.$cFlds[0].'='.$cFlds[1].'&'.$cFlds[3].'">'.$cFlds[2].'</a></b></font>&nbsp;
				<a href="'.getPath().'?tool=remInstAccess&f='.$cFlds[1].'&'.$cFlds[3].'">[remove]</a><br>'; 
		}

    //Listing friends requests is moved to new layout....
	
	//print 'URL: '.curPageUrl();
	//print ' Server: '. $_SERVER["SERVER_NAME"].'<br>';

	if (isWebMaster())
	{
		$szSQL = "select count(SystemMessageId) from SystemMessage where Handled = b'0';";
		if ($nCount = getString($szSQL)+0)
			her('<a href="'.getPath().'?tool=syswarnings">There are '.$nCount.' unhandled system warning(s). Show them</a>');
	}

//  Moved to printPageFooter()    
//	$szRequest = getJavaRequest();
//	if (strlen($szRequest))
//print '<div id="request" style="display:none">'.$szRequest.'</div>';

	print '<div id="promo">&nbsp;</div>';
	print '<h3><font color="red"><div id="warning"></div></font></h3>';

	if (isPendingWarning())
	{
		print '<h2><font color="red">'.getWarning().'</font></h>';
		resetWarning();
	}
	//else
	//	her("No warning...");

}	
  
static function receiptMessage($szMsg)
{
    $szHtml = '<div class="help_cl"><table width="100%"><tr><td>'.$szMsg.'</td></tr></table></div>';
    CBasic::display($szHtml);
    
}

}

?>