<?php
class CSystem extends CBasic
//class CSystem extends CDebug
{
    public $szAjaxRequests;
    public $bMenuPrinted = false;
    public $pXml;
    public $bXmlFlushed = false;    //To prevent that there's two xmls in action....
    public $cCommands = array();    //XML commands used upon Ajax requests...
    public $cPostProcessingCommands = array();    //Commands to be handled after those in cCommands
    public $szReportedSystemStatus;
    public $szNewSystemStatus;
    
    //Handling call back that prints directly to stream....
    public $szXmlCallbackFunc;
    public $cXmlCallbackParams = array();

    const INFO_RSS = 0x1;
    const INFO_CHATS = 0x2;
    const INFO_PHONEBOOK = 0x4;
    const INFO_WALL = 0x8;
    const INFO_TIMERS = 0x10;
    
public function __construct()    
{
    $this->cCommands = array(); //Don't know if this is necessary
}

public function trace($szTxt)
{
    if (is_subclass_of($this, "CDebug"))
        parent::trace($szTxt);
        
}

public function menu()
{
    //requestData("menu", "c=sys&cat="+szFunc);
    $szCat = get("cat");
    getSystem()->trace("IN SYS:menu() $szCat");
    require_once("class.php");
    $_GET["func"] = $szCat;
    getSystem()->ajaxHandled("menu", $szCat);
}
public function getIncludeModule($szModule)
{
    //Redefine to make a way to determine if modules should be loaded... Like chat, phone book or whatever.. used for several cases for Foreldrekontakten...
    //Should be implemented by nameing fields like UsingModuleChat bit(1) not null default b'0' and then calling with "Chat" as param
}


public function handleAjaxRequest()
{
    //Redefine to handle system spesific Ajax requests...
    
    $szFunc = get("func");
    
    if (in_array($szFunc, array("makeGroup","initsmall","profile","menu","showRecord")))
    {
       if (!method_exists($this,$szFunc))
            reportHacking("Tool with system function ($szFunc) filed");
       else
            $this->$szFunc();
       return true;     //Handled..
    }
    
    switch ($szFunc)
    {
        case "testXML":
            $cXML = new CXmlCommand();
            //$cXML->alert("This is a test...");
            //$cXML->addTableRow("tabellen", "top", "", "<td>flt1</td><td>flt2</td>");
            $cXML->setValue("tekstboksen", "", "ny verdi....");
            $cXML->send();
            return true;    //Handled
            
        case "niceQueries":
            $this->niceQueries();
            break;                            
            
        case "emailLogin":
            reportHacking("System::handleAjaxRequest() called for emailLogin...");
            CTools::handleAjaxRequest();
            return true;
            
        case "currentJsVer":
            CXmlCommand::sendJsVersion();
            return true;
            
        default:
            return parent::handleAjaxRequest();                    //Unhandled...
    }
    
    return true;    //Handed by this func..
}

public function getLanguageCode()
{
    //return "NOR";   //140602 - Always returning NOR 
    //global $_SESSION, $pSystem; //$default_language_code;
    return ((isset($_SESSION["lang_code"]) and strlen($_SESSION["lang_code"])) ? $_SESSION["lang_code"] : getSystem()->getDefaultLanguageCode());
}

public function functionRequiresLogin($szFunction)
{
    if (in_array($szFunction, array("initsmall")))
        return false;
    else
        return parent::functionRequiresLogin($szFunction);
}

public function initsmall()
{
    //JS discovered small screen. No necessary additional adjustments (menu is already hidden).
    //NOTE! Not yet logged in... may only save width and height sizes and handle the rest later...
    $nX = get("w")+0;
    $nY = get("h")+0;
}

public function beforeLogin()
{
    //First thing to be callen when screen is opened or refreshed no matter if logged in or not
}

public function defaultAcCategory() {return "2n hand";}

public function receivedSystemStatus($szSystemStatus)
{
    $this->szReportedSystemStatus = $szSystemStatus;
}

public function getWelcomeMsg()
{
    return 'Welcome to Taransvar chat site';
}

public function getSystemStatus()
{
    return $szReportedSystemStatus;
}

public function setSystemStatus($szSystemStatus)
{
    $this->szNewSystemStatus = $szSystemStatus;
    CXmlCommand::setInnerHTML("sysStatus","",$szSystemStatus);
}

public function readSystemStatus()
{
    $szSystemStatus = get("ss");
    if (strlen($szSystemStatus))
        $this->receivedSystemStatus($szSystemStatus);
        
    //debug("Received system status: $szSystemStatus<br>");
}

public function login()
{
    $pLogin = new CLogin();
    $pLogin->login();
}

public function getTitle() 
{ 
   // return "Koinoniapolis - Online Community the way it should be";
   return $_SERVER["SERVER_NAME"];
}

public function getLoginURL($nProfileId, $szClass="", $szFunc="", $szParams="")   //Keyword: getLoginLink
{
//    $szEmail = getString("select Email from Profile where ProfileId = $nProfileId");    
//    $szTempPass = CTools::getTempPassword($szEmail);
//    $szURL = getUrlWithScript(true).'?func=start&id='.$nProfileId.'&pass='.$szTempPass; //true = $bChangeContentToIndexPhp
    
    
    $szLoginCode = CLogin::getLoginCode($nProfileId+0, $szCreated="");
    $szPar = (strlen($szClass)?"c=$szClass&":"");
    
    if (strlen($szFunc))
        $szPar .= "func=$szFunc&";
        
    if (strlen($szParams))
        $szPar .= "$szParams&";
    
    $szURL = getSystem()->getUrlWithScript($bChangeContentToIndexPhp = true).'?'.$szPar.'id='.$nProfileId.'&logincode='.$szLoginCode; //true = $bChangeContentToIndexPhp
    return $szURL;  
}

public function getStartURL($nProfileId)
{
    $nProfileId += 0;
//    $szEmail = getString("select Email from Profile where ProfileId = $nProfileId");    
//    $szTempPass = CTools::getTempPassword($szEmail);
//    $szURL = getUrlWithScript(true).'?func=start&id='.$nProfileId.'&pass='.$szTempPass; //true = $bChangeContentToIndexPhp

    $szLoginCode = CLogin::getLoginCode($nProfileId);
    $szURL = getSystem()->getUrlWithScript($bChangeContentToIndexPhp = true).'?func=start&id='.$nProfileId.'&logincode='.$szLoginCode; //true = $bChangeContentToIndexPhp
    return $szURL; 
}

public function getSiteName() {return siteName();}

public function getUrlWithScript($bChangeContentToIndexPhp = false)
{
    //NOTE! Subsystems (domains with cloaking forwarding needs to alter the parameters.. (remove the index.php part)
    return getUrlWithScript($bChangeContentToIndexPhp);
}

public function emailLogin()
{
    //alert("Email login");
    $pLogin = new CLogin("emailInvite");
    $pLogin->login();
}

public function accessLevel($szTryingToAccessWhat, $szId, $nRequiredLevel = 1)  //NOTE! Shouldn't use $nRequiredLevel, it's not impolemented for all tables..
{
    //return accessLevel($szWhat, $nId);
    global $_global_denial_reason;
    $_global_denial_reason = "";
    $nId = $szId+0;
    unset($szId); //To prevent it from being used.....
    //her ($szTryingToAccessWhat.' '.$szId.' '.getUserId());
    
    $nMe = myId(); //getUserId() 
    if (!in_array($szTryingToAccessWhat, array("WritePrj")))
        if (!$nMe)
            return 0;
    
    if (isWebmaster() || isSupervisor())
        return 99;
    
    if ($szTryingToAccessWhat == "Poll")
    {
        noop();
    }
    
    $szAccessFuncName = "accessLevel_".$szTryingToAccessWhat;
    
    if (function_exists($szAccessFuncName))
    {
        try {
            return $szAccessFuncName($nId, $nRequiredLevel, "dummy");
        }
        
        catch (Exception $e) {
            reportHacking("Error calling accessLevel function: $szAccessFuncName");
            return false;    
        }
    }
    
    switch ($szTryingToAccessWhat)
    {
        case "TeamActivityChange":
        case "Activity": 
            $szSQL = "select TeamId from TeamActivity where TeamActivityId = $nId";
            return accessLevel_Team(getString($szSQL),$nRequiredLevel);
            
        case "Comment": 
            $nId = getString("select Id from Comment where KommId = $nId")+0;
            //Proceed to person.....
        
        case "Person":
            //$szSQL = "select BelongsToProfileId from Person P join CRM C on C.CRMId = P.CRMId where Id = ".$szId." and BelongsToProfileId = ".myId();
            $szSQL = "select C.CRMId from Person P join CRM C on C.CRMId = P.CRMId left outer join GroupMember GM on (GM.GroupId = BelongsToGroupId) where Id = $nId and (BelongsToProfileId = $nMe or GM.ProfileId = $nMe)";
            //her($szSQL);
            $nFound = getString($szSQL)+0;
            return ($nFound>0);

        case "Profile":
            if ($nId == ($nMe+ 0))
                return 99;    //Full access to yourself...
                
            //Also full access to kids..
            $szSQL = "select ParentId from Parent where KidId = :kid and ParentId = :me";
            if (getString($szSQL,array(":kid"=>$nId,":me"=>$nMe))+0)
                return 99;
                
            //Check if have access rights to any of this persons teams..
            $szSQL = "(select TeamId from TeamMember where MemberId = :id)
            union 
            (select TeamId from Parent P join TeamMember TM on (TM.MemberId = KidId) where ParentId = :id)";
            //herIfOy($szSQL);
            
            $result = prepareQuery($szSQL,array(":id"=>$nId));
            $nRight = 0;

            //140326 Before returned 1 if has access to team.. Now return same access level as on team... (note may be abused if create new team and add inn the people...
            while ($line = fetchRow($result))
            {
                $nLevel = accessLevel_Team($line[0], $nRequiredLevel);
                $nRight = max($nRight, $nLevel);
            }
                
            if ($nRight)
                return $nRight;

            //Team leader may change name and birth date of other team leaders (for match report)
            //150124
            $szSQL = "select max(AccessLevel) from TeamRole Me join TeamRole TheOther on TheOther.TeamId = Me.TeamId 
                        join RoleCategory RC on RC.RoleCategoryId = Me.RoleCategoryId
                    where (TheOther.PersonId = :id and Me.PersonId = :me)";
            $nMaxRight = CDb::getString($szSQL, array(":me"=>myId(), ":id"=>$nId))+0;            
                
            //Team leader also have access to parents of kids unless that's blocked by the person... 
            
            //First check if has kid in team that we manage..
            $szSQL = "select KidId from Parent Par 
                join TeamMember TM on MemberId = KidId
                join TeamRole TR on TR.TeamId = TM.TeamId
                where ParentId = :id and PersonId = :me";
                
            $nKidInTeamIManage = getString($szSQL,array(":id"=>$nId,":me"=>myId()))+0;

            $szSQL = "select CAST(TeamAndGroupLeaderMayChange as UNSIGNED), CAST(TeamAndGroupMemberMaySee as UNSIGNED) from Profile where ProfileId = :id";
            getString2Ok($szSQL, $bLeadersMayEdit, $bMembersMayView,array(":id"=>$nId));
                
            if ($nKidInTeamIManage)
            {
                if ($bLeadersMayEdit)
                    return 3;   //I'm team leader of one of the kids and may edit also parents
                else
                {
                    $_global_denial_reason = "Har satt i instillingene sine at ledere ikke kan se persondata.";
                    $nMaxRight = max($nMaxRight, 1);   //I'm team laader and may see partent address info
                }
                //NOTE! Aslo applies to kids and parents of both the object and the subject...
            }                
            
            //NOTE! Should also check group....
            //NOTE! Should also check if kids in same team and if so, check $bMembersMayView
            return $nMaxRight;

        case "TemporaryTeam":
            $szSQL = "select TeamId from TemporaryTeam where TemporaryTeamId = :id";
            return accessLevel_Team(getString($szSQL,array(":id"=>$nId))+0, $nRequiredLevel);

        case "Tournament":
            $szSQL = "select ClubId, Status from Tournament where TournamentId = :id";
            //her($szSQL);
            getString2Ok($szSQL, $szClubId, $szStatus, array(":id"=>$nId));
            $nClubAccess = ($szClubId?accessLevel("Club", $szClubId):0);
            //reportHacking("Tournament $nId: Club access to club $szClubId: $nClubAccess (status: $szStatus)");
            
            switch ($szStatus)
            {
                case "Public":
                    return max(1,$nClubAccess);
                    
                case "Internal";
                    return $nClubAccess;
                    
                case "Private":
                    return ($nClubAccess > 1?$nClubAccess:0);
                
                case "":
                    reportHackingToUserAndDb("Unknown tournament status when trying to check if has access.","Tournament", $nId, false, "Tournament status is blank");
                    break;
                
                default:
                    reportHackingToUserAndDb("No access to this tournament","Tournament", $nId, false, "Unknown tournament status: $szStatus");
                    return 0;
            }

        case "TournamentGroup":
            $szSQL = "select TournamentId from TournamentGroup where TournamentGroupId = $nId";
            $nId = getString($szSQL);
            return accessLevel("Tournament", $nId);
            
        case "TournamentTeam":
            $szSQL = "select if (coalesce(TT.TournamentId,0)= 0, TG.TournamentId,TT.TournamentId) from TournamentTeam TT 
                        left outer join TournamentGroup TG on TG.TournamentGroupId = TT.TournamentGroupId where TournamentTeamId = $nId";
            //promptIfOy($szSQL);
            $nId = getString($szSQL);
            return accessLevel("Tournament", $nId);
            
        case "WorkingBeeActivityShift":
            getString3Ok("select WorkingBeeActivityId, SelKidId, ProfileId from WorkingBeeActivityShift where WorkingBeeActivityShiftId = :id",$nId, $nSelKidId, $nProfileId,array(":id"=>$nId));
            
            if ($nSelKidId+0)
                //Access if shift assigned to me or kid
                if ($nSelKidId == myId() || isChild($nSelKidId))
                    return 2;

            if ($nProfileId +0)
                if ($nProfileId == myId())
                    return 2;
            
            $nId += 0;
            //Proceed to WorkingBeeActivity below...
            
        case "WorkingBeeActivity":
            $nId = getString("select WorkingBeeId from WorkingBeeActivity where WorkingBeeActivityId = :id",array(":id"=>$nId))+0;
            return accessLevel_WorkingBee($nId, $nRequiredLevel);
            
        case "Team":
            return accessLevel_Team($nId, $nRequiredLevel);

            //131219: Removed access based on team because anybody with kids on one team would get access to all teams...
            //return accessLevel("Club", $nClubId);    //NOTE! Don't understand why this is not infinite loop bcoz mayAccess("Club") calls mayAccess("Team")

        case "TeamActivity":
            $szSQL = "select TeamId from TeamActivity where TeamActivityId = :id";
            $nTeamId = getString($szSQL,array(":id"=>$nId));
            return accessLevel("Team",$nTeamId);            
            
        case "RequestForOffer":    //NOTE! Works on any table where there's Id field and ProfileId that should be me....
            $szIdFld = $szTryingToAccessWhat.'Id';
            $szSQL = "select ProfileId from $szTryingToAccessWhat where $szIdFld = :id";
            return (getString($szSQL,array(":id"=>$nId)) == myId()?3:1);

        //case "Club":  accessLevel_Club() made

        case "Matches":
        case "MatchG":
        case "MatchH":
            //May change if has role on club level
            $szSQL = "select MatchId from Matches M join ClubRole R on R.ClubId = HomeClubId or R.ClubId = GuestClubId where MatchId = :id";
            if (getString($szSQL,array(":id"=>$nId))>0)
                return 3;
                
            //For now, may change if has kids in one of the teams...
            $szSQL = "select count(MemberId) from TeamMember TM join Matches M on (HomeTeamId = TM.TeamId or GuestTeamId = TM.TeamId) join Parent P on P.KidId = MemberId where M.MatchId = :id and ParentId = :me";
            return (getString($szSQL,array(":id"=>$nId,":me"=>$nMe))+0 > 0?3:1);
            
        case "Groups":
            $szSQL = "select ProfileId, CAST(Activated AS UNSIGNED), CAST(Administrator AS UNSIGNED), 1 from GroupMember where GroupId = :group and ProfileId = :me";
            getString4Ok($szSQL, $nProfileId, $bActivated, $bAdministrator, $szDummy,array(":group"=>$nId,":me"=>$nMe));

            return ($bAdministrator?3:($nProfileId?1:0));

        case "CRM":
            $nMe = myId();
            $szSQL = "select CRMId from CRM C left outer join GroupMember GM on (GM.GroupId = C.BelongsToGroupId) where CRMId = $nId and (BelongsToProfileId = :me or ProfileId = :me)";
            return (getString($szSQL,array(":me"=>$nMe))+0 == $nId?3:0);

        case "EmailOutbox":
            $szSQL = "select GroupMessageId from EmailOutbox where EmailId = :id";
            $nId = getString($szSQL,array(":id"=>$nId))+0;
            //Proceed to GroupMessage;    
        
        case 'GroupMessage':
            $szSQL = "select PostedBy, Category, GroupId, 0 from GroupMessage where GroupMessageId = :id";
            getString4Ok($szSQL, $nPostedBy, $szCategory, $nGroupId, $szDummy, array(":id"=>$nId));
            
            if ($nPostedBy == myId())
                return 99;
            
            switch ($szCategory)
            {
                case "Group":
                case "Team":
                case "Club":
                    return accessLevel($szCategory, $nGroupId);    //Club, Team or Group... Same as table directly....
            }
            reportHackingToUserAndDb("Sorry but there has been an error. This has been reported to the support center.","GroupMessage",$nId,false,"Unknown category for group messages: $szCategory");
            return 0;

        case 'ActivityTransportation':
            $szSQL = "select DriverId, PassengerId from ActivityTransportation where ActivityTransportationId = :id";
            getString2Ok($szSQL, $nDriver, $nPassenger,array(":id"=>$nId));
            if ($nDriver+0 == myId())
                return 99;
            if (isRelated($nPassenger+0, myId()))
                return 99;
            else
                return 0;
                
        case "Location":
            $szSQL = "select LocationId from Location where LocationId = :id";
            return (getString($szSQL,array(":id"=>$nId))+0?1:0);
                
        case 'Loan':
            return (CLoan::mayAccess($nId, myId(), $nRequiredLevel)?1:0);

        case "Municipality":
            return (isWebmaster()?3:1);            
         
        case "Wiki":
            return CWiki::getAccessLevel($nId);
            
        case "AccountStatement":
            $nId = getString("select AccountingId from AccountStatement where AccountStatementId = :id",array(":id"=>$nId))+0;

        case "Accounting":
            $szSQL = "select Category, RegId from Accounting where AccountingId = :id";
            getString2Ok($szSQL, $szCategory, $nRegId, array(":id"=>$nId));
            switch ($szCategory)
            {
                case "Team":
                    return accessLevel($szCategory, $nRegId);
                default: 
                    saveHackingReportToDb("Unknown category for Accounting in accessLevel(): $szCategory", "", 0, "");
            }
            return 0;              
        case 'Points':
            $nTeamId = getString("select TeamId from Points where PointsId = :id",array(":id"=>$nId))+0;
            return accessLevel("Team", $nTeamId);
            
        case "Calender":
        case "Calendar":
            $nMe = myId();
            $szSQL = "select CalenderId, OwnerId, CAST(Searchable AS UNSIGNED), CAST(RequiresApproval AS UNSIGNED) from Calender where CalenderId = :id";
            getString4Ok($szSQL, $nCalId, $nOwner, $nSearchable, $nRequiresApproval,array(":id"=>$nId));
            if (!$nCalId)
                return 0;   //Calender not found.

            if ($nOwner == $nMe)
                return 99;
            
            $szSQL = "select CAST(Approved AS UNSIGNED) from CalenderSubscription where CalenderId = :id and ProfileId = :me";
            $nApproved = getString($szSQL,array(":id"=>$nId,":me"=>$nMe)); 

            if ($nApproved || $nSearchable || !$nRequiresApproval)
                return 1;
            //CXmlCommand::prompt("SQL",$szSQL);

            return 0;

        case "FamilyActivity":
            $nOwner = getString("select ParentId from FamilyActivity where FamilyActivityId = :id",array(":id"=>$nId));
            
            if ($nOwner == myId())
                return 99;
                
            if (isRelated($nOwner, myId()))
                return 1;
                
            return 0;                
            
        case "CalenderEntry":            
        case "CalendarEntry":            
            $szSQL = "select CE.CalenderId, AccessLevelToChangeEntries from CalenderEntry CE join Calender C on C.CalenderId = CE.CalenderId where CE.CalenderEntryId = :id";
            getString2Ok($szSQL, $nCalenderId, $nRequiredAccessLevelForChange,array(":id"=>$nId));

            $nCalendarAccess = accessLevel("Calendar",$nCalenderId);
            switch ($nCalendarAccess)
            {
                case 0:
                    return 0;
                case 1:
                case 2:
                    if ($nCalendarAccess >= $nRequiredAccessLevelForChange)
                        return 3;
                default:
                    return $nCalendarAccess;
            }
            alert("Never gets here...");

        case "TransportCoop":
            getString2Ok("select TeamId, CreatedBy from TransportCoop where TransportCoopId = :id", $nTeamId, $nCreatedBy, array(":id"=>$nId));
            
            if ($nCreatedBy == myId())
                return 99;          //Created by me... full access
                
            $nTeamAccess = accessLevel("Team", $nTeamId);
            
            if ($nTeamAccess >= 3)
                return $nTeamAccess;
            
            //Check if member in this coop..
            $cMyCoops = CTransportCoop::myCoops();
            if (in_array($nId, $cMyCoops))
                return 3;
                
            //Read access only if coop is connecte to team where I have kids.
            $cMyTeams = CClub::myTeams($bIncludeWithKids = true, $bIdOnly = true);
            return (in_array($nTeamId, $cMyTeams)?1:0);
            
        case "TransportCoopMember":
            if (isRelated($nId, myId()))
                return 99;
                
            //Sjekk på samme tranport coop
            $cMyCoops = CTransportCoop::myCoops();
            if (sizeof($cMyCoops))
            {
                $szCoopList = implode(",",$cMyCoops);
                $szSQL = "select TransportCoopMember where MemberId = :id and TransportCoopId in ($szCoopList)";
                $nSame = getString($szSQL, array(":id"=>$nId))+0;
                return ($nSame > 0);
            }
            return 0;
    
        case "TransportLog":
            $szSQL = "select CreatedBy, TeamId, ProfileId, TT.TransportCoopId from TransportLog TL join TransportTrip TT on TT.TransportTripId = TL.TransportTripId join TransportCoop TC on TC.TransportCoopId = TT.TransportCoopId where TL.TransportLogId = :id";                                    
            //promptIfOy($szSQL);
            //return 3;
            $cRec = getArray($szSQL,array(":id"=>$nId));
            if ($cRec["CreatedBy"] == myId())
                return 99;
                
            if (isRelated($cRec["ProfileId"], myId()))
                return 99;  //Full control over own kids.
                
            //Full control if has kids on same TransportCoop
            $szKids = myKids($bArray=false);
            if (strlen($szKids))
            {
                $szSQL = "select MemberId from TransportCoopMember where TransportCoopId = :id and MemberId in (".$szKids.")";
                if (getString($szSQL,array(":id"=>$cRec["TransportCoopId"]))+0)
                    return 99;
            }
                
            return accessLevel("Team", $cRec["TeamId"]);

        case "TransportTrip":
            $szSQL = "select TeamId from TransportTrip TT join TransportCoop TC on TC.TransportCoopId = TT.TransportCoopId where TransportTripId = :id";
            return accessLevel("Team", getString($szSQL,array(":id"=>$nId)));
            
        case "Event":
            $szSQL = "select Category, Id from Event where EventId = :id";
            getString2Ok($szSQL, $szCategory, $nActId,array(":id"=>$nId));
            switch ($szCategory)
            {
                case "Activity":
                    $szSQL = "select TeamId from TeamActivity where TeamActivityId = :id";
                    $nTeamId = getString($szSQL,array(":id"=>$nActId));
                    $nAccessLevel = accessLevel("Team", $nTeamId);
                    //reportHacking("Checking Team $nTeamId for event $nId. Access level: $nAccessLevel");
                    return $nAccessLevel;
                    
                case "Match":
                case "MatchG":
                case "MatchH":
                    $szSQL = "select HomeTeamId, GuestTeamId, HomeClubId, GuestClubId from Matches where MatchId = :id";
                    getString4Ok($szSQL, $nHomeTeamId, $nGuestTeamId, $nHomeClubId, $nGuestClubId,array(":id"=>$nActId));
                    return max(accessLevel("Team",$nHomeTeamId), accessLevel("Team",$nGuestTeamId), accessLevel("Club",$nHomeClubId), accessLevel("Club",$nGuestClubId), 1);
                    


                case "Calnd":
                    return accessLevel("CalendarEntry", $nActId); //140209
                    
                default:
                    reportHacking("Unknown event category in accessLevel for event $nId: $szCategory, activity id: $nActId");
                    return 0;
            }
            return 0;
            
        case 'Ad':
            //Anyone may see ad.... so only relevant if asking for access level 3... (or 2???)
            if (getString("select OwnerId from Ad where AdId = $nId") == myId())
                return 3;
            else
                return 1;
        
        //******************** Exercise module **********************
        case "ProfileExercisePlan":
        case "ProfileExerciseLog":
            $szKeyFld = $szTryingToAccessWhat."Id";
            $nOwner = getString("select ProfileId from $szTryingToAccessWhat where $szKeyFld = $nId");
            if ($nOwner == myId())
                return 99;
            return 0;
            
                
        default:
            reportHackingToUserAndDb("Unknown info in accessLevel..".(isOy()?"Oy, so: ".$szTryingToAccessWhat:""), $szTryingToAccessWhat, $nId, false, "Trying to access \"$szTryingToAccessWhat\"");
            return 0;
            
    }
    
}

public function copyClassVariablesTo($pNewSystem)
{
    $pNewSystem->szAjaxRequests = $this->szAjaxRequests;
    $pNewSystem->bMenuPrinted = $this->bMenuPrinted;
    $pNewSystem->pXml         = $this->pXml;
    $pNewSystem->bXmlFlushed   = $this->bXmlFlushed;    //To prevent that there's two xmls in action....
    $pNewSystem->cCommands     = $this->cCommands;    //XML commands used upon Ajax requests...
    
}
    
static public function classDispatch($szClass)
{
    printMenu();
    her("Class dispatch not finalized");
}
    
public function adjustMainMenu($pMenu)
{
        
}

public function getJavascriptsArray()
{
    return array(); //Redefine to add javascripts... (NOTE!Exclude .js)
}

public function includeJavascripts()
{
    $cJavascriptsArray = $this->getJavascriptsArray();
    foreach($cJavascriptsArray as $szScript)
        print '<script type="text/javascript" src="'.$szScript.'.js" /></script>';
    //Redefine to include module spesific java scripts. 
}

public function javascriptIsIncluded($szScript)
{
    return in_array($szScript, $this->getJavascriptsArray());
}

public function getLeftMenuShortcuts(&$cMenuArray)
{
    //Redefine to print shortcuts...
    return "";
}

static public function quickLogin()
{
    return;
    //Test: http://localhost/sos/index.php?func=logins&email=post@taransvar.no&pass=88ce79f5b7cacc7e6c2a2b03dfd96c10
/*    $szUser = get("email");
    $szPass = get("pass");
    $szWarning = "Receipt";
    
    if (strlen($szUser) and strlen($szPass))
    {
        $szSQL = "select ProfileId from Profile where Email = '$szUser' and TempPass = '$szPass'";
        $nUserId = getString($szSQL)+0;
        
        if ($nUserId)
        {
            $_SESSION['sess_adm_userid'] = $nUserId;
            printMenu();
            print '<div id="content">';
            CChat::initChatWindow();
            print '</div>';
            return;
        }
        else
        {
              $szWarning = "Wrong user name or password.. Please try again.";
              getSystem()->logLoginFailure(NULL, $szUser, $szFailureCategory = 'Invalid password');
        }
    }
    else
        if (strlen($szUser) or strlen($szPass))
            $szWarning = "Email or password specified (but not both). Try again";
           print "NB! Brukern nå submitted fremfor sendLoginEmlBtn()"
    //her("Username: $szUser, pass: $szPass");
    ?><div id="content"><table width="650" align="center"><tr>
            <td><font size="30">1</font></td><td colspan="5" align="center"><div class="login">Email: <input type="text" id="email"></div></td></tr>
            <tr>
                <td><font size="30">2</font></td>
                <td align="center"><div class="action"><form>Password: <input type="password" id="password"> <input type="submit" onclick="return loginBtn()" value="Login"></form></div></td>
                <td width="25" align="center"><font size="20">or</font></td>
                <td align="center"><div class="action"><input type="button" onclick="sendLoginEmlBtn()" value="Send Invitation Email"><br>(No password required)</div></td>
                <td width="25" align="center"><font size="20">or</font></td>
                <td align="center"><div class="action">
                        <a href="<?php print getPath(); ?>?func=reg"><?php print getTxt("I'm new. Register Account"); ?></a><br>
                        or<br>
                        <a href="<?php print getPath(); ?>?func=forg"><?php print getTxt("Forgotten password"); ?></a>
                    </div></td>
            </tr>
            <tr><td><font size="30">3</font></td><td colspan="5" align="center"><div class="login" id="loginMsg" name="loginMsg"><?php print $szWarning; ?></div></td></tr>
            </table></div>
    <?php
    */
}
    
public function getHtmlTitle()
{
    return $_SERVER["SERVER_NAME"];
}

    
public function defaultMenuChoice()
{
        printMenu("Home");
        herIfOy("No function specified....");
}   

public function addAjaxRequest($szRequest)
{
    
    $this->szAjaxRequests .= (strlen($this->szAjaxRequests)?"#":"").$szRequest;
}

public function getClassName($szClass)
{
    switch ($szClass)
    {
        case 'sys':
            return "CSystem";
        case 'pb':
            return "CPhonebook";
        case "municipal":
            return "CMunicipalInfo";
        case "inline":
            return "CEditInline";
        case "noti":
            return "CNotifications";
        case "wb":
            return "CWorkingBee";
        case "wba":
            return "CWorkingBeeActivity";
        case "tc":
            return "CTransportCoop";
        default: 
            return "C".strtoupper(substr($szClass, 0,1)).substr($szClass, 1);   
    }
}

public function getDefaultChatStatus($nProfileId = 0)
{
    //NOTE! This function is called for various reasons... Among others when finding chat status of other persons....
    if ($nProfileId)
        $nProfileId = myId();

    $szSQL = "select count(*) from Friend where ConfirmerId = $nProfileId or RequesterId = $nProfileId and ConfirmedTime is not NULL";
    if (getString($szSQL)+0 > 0)
        $szChatStatus = "Friends";      //Got friends.. Show friends...
    else
        //$szChatStatus = "Available"     //No friends.. Make me available to others....
        $szChatStatus = "Searching";    //Better: make me see who else is online and available...
        
    return $szChatStatus;
}

public function postAjaxRequests($bForcePrint = false)
{
    if (strlen($this->szAjaxRequests))
    {
        //herIfOy("<br>Ajax requests: ".$this->szAjaxRequests.'<br>');
        if (!$bForcePrint && isNickkoVersion())
            debug("Unhandled obsolete ajax requests: ".$this->szAjaxRequests);
        else
            print '<div id="postRequests" style="display:none">'.$this->szAjaxRequests.'</div>';
            
        $this->szAjaxRequests = ""; //To avoid posting doubly...
    }
    else
        debug("No postAjaxRequests in postAjaxRequests");
}

public function getMyEmailAddress()
{
    $szSQL = "select Email from Profile where ProfileId = ".myId();
    return getString($szSQL);
}

public function getXml()
{
    //NOTE! No longer any need for this function bcoz CXmlCommand functions are all static and commands are in CSystem class..
    if (!isset($this->pXml))
        $this->pXml = new CXmlCommand();
        
    return $this->pXml;
}

public function updateMenuShortcuts(&$cMenuArray)
{
    if (!myId())
        return;

    //141011 - check init cMenuArray...
    getSystem()->getLeftMenuShortcuts($cMenuArray);

    $bIsWebMaster = isWebmaster();
    //140213            
    if ($bIsWebMaster)      //NB! *** 2 webmaster links on server...
    {
        //141011 - fix this...
        //$szTxt .= getShortcutMenuChoice('javascript:tool(\'webmaster\')', "Webmaster");

        //NB! *** 2 webmaster links on server...
        //141011 - Change this....
        //$szCode = "<div onclick=\"menu('webmaster', 'c=menu', '')\")><img src=\"images/info-icon_active.png\" align=\"absmiddle\" /> Webmaster</div>";
        //CXmlCommand::addToInnerHTML("shortcutsmnu", "", $szCode);
        $cMenuArray[] = array("tblShortcuts", $szImage = "",$szURL="menu('webmaster', 'c=menu', '')", "Webmaster");
    }
        
    //$szSQL = "SHOW TABLES LIKE 'Shortcut'";
    //$nFound = getString($szSQL);
    //alert("Show talbes: $nFound");
    $pParser = new CParser("SHOW TABLES LIKE 'Shortcut'", array());
    $nRows = $pParser->pStmt->rowCount();
    if($nRows == 1)//mysql_num_rows(mysql_query("SHOW TABLES LIKE 'Shortcut'"))==1) 
    //if (strlen($nFound))
    {        
        $szSQL = "select `Title`, `Func`, `Params`, Category from Shortcut where ProfileId = ".myId();        
        //print $szSQL;
        $cSQL = new CSql();
        while ($cRec = $cSQL->parseSql($szSQL))
        {
            switch ($cRec[3])
            {
                case "Standard Menu Option":
                    //$szTxt .= getShortcutMenuChoice('javascript:menu(\''.$cRec[1].'\',\''.$cRec[2].'\')', $cRec[0]);
                    //141011
                    $cMenuArray[] = array("tblGroups", $szImage = "",$szURL="menu('".$cRec[1]."','".$cRec[2]."')'", $cRec[0]);
                    break;
                    
                case "Menu Group":
                    //NOTE! Moved to Module table.. Se CModuleMarket
                    //CXmlCommand::javascript("requestData",array("makeGroup","c=sys&tit=".$cRec[0]."&fu=".$cRec[1]."&par=".$cRec[2]));
                    break;         //140702
                default:
                    reportHacking("Unhandled Shortcut category: ".$cRec[3]);
            }
            
        }
    }
    else
        alert("Shortcut table not found..");
        
    //Load modules.
    CModule::loadModules();                    
}        


public function updateLeftMenuSection($szMenuSection)
{
    $cMenuArray = array();
    //141011 - fix this....
    $szId = "lm_$szMenuSection";
    $szTxt = "";

    switch($szMenuSection)
    {
        case "groups":
            $cArr = getArrayOfGroupsFor(myId());
            //141011 - fix...
            foreach($cArr as $cGroup)
                //$szTxt .= getMenuItemLink("menu('showGroup','c=group&id=".$cGroup[0]."')", $cGroup[1]);
                $cMenuArray[] = array("tblGroups", $szImage = "groups&community/icon-umbrella",$szURL="menu('showGroup','c=group&id=".$cGroup[0]."')", $cGroup[1]);
            break;        
            
        case "shortcuts":
            $this->updateMenuShortcuts($cMenuArray);
            break;
        case "wall":
        case "pbook":
        case "community":
            break;
    }

    //if (strlen($szTxt))         NOTE! If not set then delete remove current...
        CXmlCommand::setInnerHTML($szId, "", $szTxt);
        

    if (sizeof($cMenuArray))
        CXmlCommand::javascript("updateMenu",array(CXmlCommand::encoded(json_encode($cMenuArray))));
        
}



 public function getProfileSectionRight($nId)
 {
    $szCode = '<span>'.getTxt('Tools').'</span>';
    
    if ($nId != myId())
    {
        $nMe = myId();
        $szSQL = "select 1 as Found, ConfirmedTime from Friend where (RequesterId = $nId and ConfirmerId = $nMe) or (RequesterId = $nMe and ConfirmerId = $nId)";
        getString2Ok($szSQL, $bFound, $szConfirmed);
        
        if (!$bFound)
            $szLink = a(getTxt("Request friendship"),"javascript:menu('requestfriends','c=menu&id=$nId')");
            //'<a href="javascript:menu(\'requestfriends\',\'c=menu&id='.$nId.'\',\'\')">Request friendship</a>';
        else
            if (!strlen($szConfirmed))
                $szLink = getTxt("Your friendship request is not confirmed");
            else
                $szLink = getTxt("You're friends");
                
        $szCode .= '<div><img src="images/groups&community/icon-people.png" align="absmiddle" /> '.$szLink.'</div>';
    }

    $szStatusCode = CChat::getChatStatusFor($nId);
    
    if ($nId == myId())
    {
  //      $szChatStatus = "";
        $szStatusCode = CChat::getChatStatusDrop($szStatusCode);
        
    }
//    else   
//        $szStatusCode = CChat::getChatStatusFor($nId);
    
    if ($nId == myId())
    {
        $szCode .= div(getTxt('Chat status').': '.$szStatusCode);
        //140509
        $szSQL = "select CAST(TeamAndGroupLeaderMayChange as UNSIGNED), CAST(TeamAndGroupMemberMaySee as UNSIGNED) from Profile where ProfileId = :id";
        getString2Ok($szSQL, $nLeaderMayChange, $nMembersMayRead,array(":id"=>$nId));

        $szCode .= div('<input type="checkbox" id="leadEdit" '.($nLeaderMayChange?"checked":"").' onchange="selected(this,\'mayChange\',\'c=profile&id='.$nId.'\')">'.getTxt("Leaders may change").info("", getTxt("Team and grop leaders may change address info")).div("","changeVal"));
        $szCode .= div('<input type="checkbox" id="memRead" '.($nMembersMayRead?"checked":"").' onchange="selected(this,\'mayRead\',\'c=profile&id='.$nId.'\')">'.getTxt("Members may see").info("", getTxt("Team and group members may see address info")));

        //Displa modules setup
        $szCode .= getTxt("Modules").":<br>";
        $szCode .= CModule::getCurrentModuleSetup();
    }
//$szCode .= '<div><img src="images/groups&community/icon-male.png" align="absmiddle" /><a href="#">The Youth</a></div>';
//$szCode .= '<div><img src="images/groups&community/icon-male.png" align="absmiddle" /><a href="#">The Youth</a></div>';
//$szCode .= '<div><img src="images/groups&community/icon-shield.png" align="absmiddle" /> <a href="#">Living Faith</a></div>
    return $szCode;
}

public function getProfileSectionCode($nId, $szSection, $szCategory = "standard", $bIncludeTableTags = true)
{
    switch ($szSection)
    {
        case "Left":
            $szHtml = ($bIncludeTableTags?'<table width="250">':'');
            $cFlds = CProfile::getProfileInfo($nId, "?");
            $szHtml .= '<tr><td>EMail:</td><td>'.$cFlds[1].'</td></tr>'.
                    ($bIncludeTableTags?'</table>':'');
            return $szHtml;

        case "Right":
            return $this->getProfileSectionRight($nId);                    
            
        default:
            break;
    }
    return "";
}
    



public function flushXml()
{
    //CXmlCommand::doSend();
    CXmlCommand::flushXml();
}

public function getSiteIntro()
{
    //Redefine in deriving class to print other site intro...
    $szTxt = '<h3>Welcome to Taransvar Social Network site</h3>'.

"This site is owned by Taransvar, wich is a Norwegian non-profit organization aiming to eradicate poverty by utilizing Internet technology. As you'll see, this site is inspired by Facebook  and similar sites.  As you'll also see, it\'s far from finalized and this version is just for demo purposes.

The functionality we\'ll implement in this program is:

<ul>
<li>Online local market place where anybody can post free ads.</li>
<li>Free local announcements. Lost/found, arrangements or whatever.</li>
<li>Calender where sports clubs and any organization can post entries that will become visible on the member\'s calender.</li>
<li>Tool for sports clubs, voluntary organization and others to arrange working bees, tution and other activities and arrange transportation of their kids.</li>
<li>Matrix for setting up sports tournaments with various tasks to be handled in each location and distributing the tasks to individual teams.</li>";
// <li>Loan module where anybody can register their private loans. Our hope is that this will make it easier for poor yet capable people to get loan at reasonable rates and also poor people who are willing to lend to their relatives and neighbors should have a tool. Later we also hope to offer loans in partnership with NGO or commercial partners. </li> 
$szTxt .= '<li>Cooperative tool (based on the lending functionality) for oranizing their internal loans</li>
<li>Personal "phone book" where you can post any comment on the entries.</li>';
//<li>Paid chat module that keeps track on the time spent on chatting and lets one party pay for the chat expenses of the other party. Read more <a href="<?php print getPath(); ?func=help&top=paidchat">here</a>.</li> 
$szTxt .= '</ul>

<p><font color="red">NOTE! Posting information here is the sole risk of the poster. We have not put any effort in security here... So you should not post anything sensitive....</font>
</p>
<p>Looking for job? Read <a href="<?php print getPath(); ?>?func=help&top=work">here</a>.
</p>
<p>Read more about:
</p>
<ul>
<li><a href="<?php print getPath(); ?>?c=chat">Chat</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=NGO">How to finance a NGO</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=investor">How to find business investors</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=helppoor">How to help poor people</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=coop">Cooperatives</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=showGroup">Groups</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=loan">Loans</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=pawn">Pawn (collaterals)</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=show">Phone Book</a></li>
<li><a href="<?php print getPath(); ?>?func=help&top=paidchat">Paid Chats</a></li>
</ul>

<a href="<?php print getPath(); ?>?func=help&top=franschise">.</a><br>';
//<a href="<?php print getPath(); ?func=help&top=paidchat">.</a><br>
return $szTxt;
}


static function showHelpPage()
{
    $szTxt = '<div class="help_cl"><h2>Core site help</h2>
    <p>
        This site is not yet ready for use.. So if you explore it, you\'ll find many functions that are not yet working... However, we\'ll here give you some introduction to some of the functions that are actually working
    </p>
    <h3>Setting the chat name of your chat partner</h3>
    <p>
    On the bottom of the screen, you have the chat bar with "Who\'s online" to the right.. Initially, potential chat pals are presented as "Set name in Phonebox".. This means that everybody here is by default anonymous.. You need to figure out yourself what\'s their name by asking them in the chat..
    </p>
    <p>
    Once you know their name, you can put their name in the phonebook once for all (thus the default name). To set the name, click the link "Set name in Phonebox" to open the profile of your chat pal.. This profile is probably pretty empty (unless your chat pal is sharing his info publically). 
    You can then specify your personal information about this person by clicking the "Phonebook" tab just below the picture (or where the picture is supposed to be). Then a new box opens just below the tab with a few lines where you can put any information about this person.. This could be your summary from the chat or whatever information. 
    For now the layout here is a mess, but we\'ll fix that later...
    To set the name, click "Show all info" link and put the name of your chat pal in the Name field and click Submit.
    </p>
    <p>
    It may take some time and you may need to refresh the browser, but the name of your chat pal should now display.
    </p>
    <h3>Phone Book</h3>
    <p>
    One of the powerfull features in this chat program is the phone book. If you followed the instructions above, then you\'ve already created your first entry in the phone book.
    </p>
    <p>
    To give it more meaning, we should first make another entry. Click the "+ Create" link in the "Phone Book" menu group on the left side menu (the "Phone Book" text is very bright and may be hard to read..). This will open the phone book in the main window and display a new emply record together with the record you already created for your chat pal. You can also always put the Phonebook on the top of the main section by pressing "Phonebook" link in the top or left menues.
    </p>
    <h4>Free text comments</h4>
    The text field in the Phone book is meant for any notes regarding your chats or anything else with the given person. Text you put here will be added to the current date. You can also highlight or make the text gray (or smaller) by clicking the + and - signs to alter the importance. All thet for the given date will be given the same importance. You can also make hypertext links to cross reference to other phone book cards by putting the search text (normally the name or email) to easy navigation to other phone book card.
    <h4>Searching in the phone book</h4>
    <p>
    You use the search field in upper right corner to search. As you type text in the search field, it will automatically check what entries in your phone book matches your criterias (checking all the various email and name fields) and hide those who don\'t match. Therefore you will see movement in the phonebook list as you type.
    </p>
    <p>
    The phone book can contain thousands of records, but your on-screen phonebook will only contain the up to 75 last active entries.. To search among all entries, you click the "search" button to the right of the edit field. This will present a list of those entries that matches your search criterias from the whole phone book list.. By clicking it you open it and make it part of the most resent selection from your phone book, thus pushing one out of the active list (if your phone book contains more than 75 entries).
    </p></div>';

    CXmlCommand::setInnerHTML("tempbox", "", $szTxt);      
    CXmlCommand::moveToTop("tempbox");
}


static function partners()
{
    $szTxt = '<div class="help_cl"><h2>Koinoniapolis Partner info</h2>
    <p>
    Koinoniapolis site is owned by a Norwegian charity organization aiming to alleviate poverty by generating jobs in poor areas.
    </p>
    <br>
    <p>
    To be able to do this, we need professional partners. Our contribution will be the platform and enthusiastic users. Turning this into profitable business will be the job for our partners. However we\'ll strive to support you by offering contact with motivated and skilled workforce who have proved their skills by rising despite lack of resouces most people take for granted.
    </p>
    <br>
    <p>
    As this is a new site, it\'s not yet settled what you as a partner can and can\'t do here.. We\'ll protect the privacy of our users, but at the same time, as we control the platform, there\'s few limitations. <a href="javascript:mnu1(\'contactus\')">Contact us</a> if you have any question or comments.
    </p>
    
    ';
        
    CXmlCommand::setInnerHTML("tempbox", "", $szTxt);      
    CXmlCommand::moveToTop("tempbox");
}


public function getAboutText()    
{
    //This info is displayed below the login fields... And elsewhere?  Shouldn't bee too long.. 
    return "";
}

public function printSiteIntro()
{
    print $this->getSiteIntro();
}

public function getDefaultLanguageCode()
{
    //Use this instead of $default_language_code
    return "ENG";
}

public function getDefaultCountryCode()
{
    //Use this instead of $default_country_code
    return "US";    //Don't know why this should be set....
}

public function commandLineHandled()
{
    //To be redefined in deriving classes...
    //alert("Welcome to our chat site......");
    
    switch (get("func"))
    {
        case "emailLogin":
            reportHacking("System::commandLineHandled() called for emailLogin...");
            $this->emailLogin();
            return true;
        
        case "start":
            //$this->showStartSite();   Don't do this.. will be called by CMenu::initScreen() anyway..
            return true;

        default:
            return false;
            //alert("Velkommen til foreldrekontakten.... Ukjent parameter");
    }
    
    
    return false;
}

public function initScreen()
{
    //Refreshed or logged in... Redefine...
}

public function showStartSite()
{
    global $_GLOBAL_CHAT_MODUL_ENABLED;
    if ($_GLOBAL_CHAT_MODUL_ENABLED)
        CChat::initChat();
    else
        alert("No system start page defined yet...");
    return;
    //getSystem()->initScreen();
}


public function adjustWebmastermenu(&$cWebmasterMenu)
{
    CSysTools::adjustWebmastermenu($cWebmasterMenu);
}

public function systemNotifications()
{
    //Redefine to handle system notifications
}

public function getWelcomeJsErrorMsg() 
{
    return 'Welcome to '.siteName().' chat program. It seems like our program is not working properly on your unit. This is probably due to some javascript related problem.<br />';
}
    
public function printHomeIntro()
{
    $szTxt = '<div class="help_cl"><h2>Welcome to Koinoniapolis Info page</h2>
    <p>
    Koinonia means friendship and polis means city in Greek.. The name indicates that this is meant to be a social network site.
    </p>
    <br>
    <p>
    What makes this site special is that it\'s owned by a charity organization that\'s aiming to alleviate poverty by creating jobs. Maybe you think that the purpose of most social network sites is to get online friends or play games or something like that.. Think twice. The purpose of almost every online site is to make it\'s owners rich. And in some successful cases, it made the owners stinking rich.. So wouldn\'t it be better if those pages is owned by a charity org? Well, most people don\'t care. But if people who care unite to create such page, then many others will follow them.. 
    </p>
    <br>
    <p>
    So prove that you\'re one of those who care by joining this site.. 
    </p>
    <br>
    <p>
    As you\'ve probably already noticed, this site is far from finalized... Obvious error will be correcte the next few weeks, but if you have suggestions or would like to get involved somehow, then we\'re happy if you '.CMenu::contactUsLink().'.
    </p>
    
    ';
    CXmlCommand::setInnerHTML("tempbox", "", $szTxt);      
    CXmlCommand::moveToTop("tempbox");
}

static function makeGroup()
{
//    alert("Er her nå....");
    $szTitle = get("tit");
    $szFuncs = get("fu");
    $szParams = get("par");

    switch ($szFuncs)
    {
        default:
            reportHacking("Not yet learned how to handle menu group category: $szFuncs in makeGroup()");
            //CXmlCommand::makeMenuGroup("dynAddMenGrp", "Testgruppe", "alert('Trykket på testgruppe')", "+ Annonser", "menu('ad:ad','')", array(array("Test","alert('Enda en test')")));
    }
}

static function getSetup($szField)
{
    //Check if bit field...
    if (in_array($szField, array("MayImportClubData")))
        $szField = "CAST($szField AS UNSIGNED)";
        
    $szSQL = "select $szField from Setup";
    return getString($szSQL);
}

static function get_client_ip() 
{
    $ipaddress = '';
    if (runningLocally())
        return "LOCALLY";
        
    if (isset($_SERVER['HTTP_CLIENT_IP']) && $_SERVER['HTTP_CLIENT_IP'])
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if($_SERVER['HTTP_X_FORWARDED_FOR'])
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if($_SERVER['HTTP_X_FORWARDED'])
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if($_SERVER['HTTP_FORWARDED_FOR'])
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if($_SERVER['HTTP_FORWARDED'])
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if($_SERVER['REMOTE_ADDR'])
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}

static function logLoginFailure($nProfileId, $szEmail, $szFailureCategory)
{
    $szSQL = "insert into LoginFailure (ProfileId, Email, FailureCategory, IP) 
            values (:profileId, :email, :cat, :ip)";
    
    $szIp = CSystem::get_client_ip();
    CDb::get()->execute($szSQL, $cFlds = array(":profileId"=>$nProfileId, ":email"=>$szEmail, ":cat"=>$szFailureCategory,":ip"=>$szIp));
    CSosFirewall::getFirewallObject()->loginFailure($nProfileId);
}

static function tooManyLoginFailures(&$szErrorMsg)
{
    $szSQL = "select count(LoginFailureId) from LoginFailure where Timestamp >= NOW() - INTERVAL 10 MINUTE";
    $nFailures = CDb::getString($szSQL,array());
    if ($nFailures >= 20)
    {
        $szErrorMsg = "Too many login failures last 10 minutes.. The system is currently locked for security reasons. Kindly wait and try again in few minutes.";
        reportHacking("User locked out bcoz $nFailures login failures last 10 min");
        return true;
    }
    return false;
}


public function niceQueries()
{
    CSysTools::niceQueries();
}

    
public function profile($nId = 0)   
{
    //Redefine to override...

    /*if (!mayAccess("Person", $nId, 1))
    {
        CXmlCommand::alert("You don't have access rights for this profile");
    } */
    
    if (!$nId)
        $nId = myId();
//ok    
    //xcvbxcvb ProfilePic
    $szPicUrl = getProfilePic($nId);
    $cFlds = CProfile::getProfileInfo($nId, "?");
    
    $szCode = '<input type="hidden" id="openProfileId" value="'.$nId.'">
    <div class="profile_pic">'.$szPicUrl.'</div>

    <div class="profile_maininfo">
    <h2 class="profile_name">'.CEditInline::getContents($nId, "ProfileName", (strlen($cFlds[0])?$cFlds[0]:"?"), $szExtraParam = "").//(strlen($cFlds[0])?$cFlds[0]:"?").
    '</h2>';
    
    //<span>Member since July 7 2000</span>
    //<br /><br /><span>Personal Info:</span><br />
    //Birthday: August 16, 1990<br />
    //Age: 21<br />
    //Gender: Male<br />
    //Status: Single<br />
    //Profession: Web Design

    //$szCode .= 'EMail: '.$cFlds[1];
    
    global $pSystem;
    $szLeft= $pSystem->getProfileSectionCode($nId, "Left");
    //debug($szLeft);
    $szCode .= $szLeft;
    $szCode .= '</div>';

    $szCode .= '<div class="profile_maininfo_gc">';
    $szRight = $pSystem->getProfileSectionCode($nId, "Right", $szCategory="", false /*withTableTage*/);
    $szCode .= $szRight.'</div>';

/*    $szCode .= '<div class="profile_maininfo_gc"><span>Groups</span>
<div><img src="images/groups&community/icon-umbrella.png" align="absmiddle" /> <a href="#">Christianopolis</a></div>
<div><img src="images/groups&community/icon-lightbulb.png" align="absmiddle" /> <a href="#">Revelations</a></div>
<div><img src="images/groups&community/icon-paper.png" align="absmiddle" /> <a href="#">Today\'s Word</a>('.$nId.')</div>
</div>';
*/
    $szCode .= '<div class="cleaner"></div>';
    
    CMenu::displayProfileBox();
    CXmlCommand::setInnerHTML("rightbox","", $szCode);        
    
    if (strlen(get("topic")))
    {
        //alert("About to fill topic box...");
        CMenu::fillTopicBox();
    }

    $nProfilePicId = CDb::getString("select ProfilePictureId from Profile where ProfileId = :id",array(":id"=>$nId))+0;
    if ($nProfilePicId)
    {
        CXmlCommand::adjustImg("fl$nProfilePicId","W",177);
        //CXmlCommand::moveLastEntryToEnd();
    }
}   

public function initSpecialChatRooms()
{
    //Assuming any chat room with category set is a special chat room to surveil. (will put link in notifications..)
    CChatRoom::initSpecialChatRooms();
}

static public function modifyMostActiveUsersList($cRecord, $nFldNo, $szFldName, $bHeading)
{
    if ($bHeading)
        return th("MinutesAgo").th("User description").th("&nbsp;");   //Default handling...
    
    $szDescription = CProfile::getProfileSysInfo($cRecord[0]);
    return td($cRecord[$nFldNo]).td($szDescription).td(getGenericLink("menu('tools:showRecord','tbl=Profile&key=".$cRecord[0]."')","[Show all values]"));
}

public function getAdsParser($szConcept)
{
    $szSQL = "select AdId, ExternalId, AdUploadId, ExternalUrl, ShortDescription, Description 
            from Ad where Concept = :concept and Active = b'1' order by AdId desc";
    return new CParser($szSQL, array(":concept"=>$szConcept));
}

public function includeInfo($nWhat)
{
    switch ($nWhat)         
    {
        case CSystem::INFO_RSS:
        case CSystem::INFO_CHATS:
        case CSystem::INFO_TIMERS:
            if (runningLocally())
                return true;    //Keyword: KEYWORD_TIMERS
            else
                return true;
        
        case CSystem::INFO_PHONEBOOK:
            if (!runningLocally())
                return false;
        case CSystem::INFO_WALL:

    }
    return true;
}
    
public function includeChats()    
{
    return $this->includeInfo(CSystem::INFO_CHATS);
}

static function layoutStyle()
{
    //Used to assemble class name for ads_area (right_box_area or right_ads_area)
    return "ads";    
}

function senderEmail()
{
    return "post@foreldrekontakten.no";
}


function getChildrensActivityList($szParentId)
{
    return '<table></table>';
}//

function getLegalSenderEmailAddress($szSenderEmail)
{
    if (stristr($szSenderEmail, getRootDomain())===false)   //150930: Used to be $_SERVER["SERVER_NAME"]
    //if (stristr($szSenderEmail, $_SERVER["SERVER_NAME"])===false)   //150930: Used to be 
        return $this->getDefaultSenderEmailAddress();
        
    if (!isLegalEmail($szSenderEmail))
        return $this->getDefaultSenderEmailAddress();

    return $szSenderEmail;
}

function getDefaultSenderEmailAddress()
{
    return "post@foreldrekontakten.no";
}

function indexCalled()
{
    //Dummy.. used by CSystemCyberrehab class
}

function adjustPersonField($nPersonId, $szFldName, $szFldVal)
{
    //Dummy.. used by CSystemCyberrehab class
    return $szFldVal;
}

static function cronJobDay()
{
}
static function cronJobHour()
{
}
static function cronJobMinute($szCategory)
{
}

static function includeEmailIdInSubject()
{
    return true;
}
   
static function getReplyToEmailFor($nGroupMessageId, $nRecipientId)
{
    return getSystem()->getDefaultSenderEmailAddress();//"post@foreldrekontakten.no";
}   

static function getSystemAnnoncement()
{
    //Content here will be put on main screen below login box.. redefine for other systems..
    //return h3("This is an announcement..").
    //        "The system should now be running... good luck!";
    return ""; //Excludes the box for announcements. For empty box, return " ";
}
   
static function mayLogin($nId, &$szRefusalExplanation)
{
    return true;    
}

static function showRecord($bAddToDiv = false, $bConfirmedMayAccess = false)
{
    CTools::showRecord($bAddToDiv, $bConfirmedMayAccess);
}

public function ajaxHandled($szClass, $szFunc, $nOverrideMayCal = 0)
{
    $pObj = false;
    //NOTE! This is very risky... Classes should have a flag (function??) that enables this functionality...
    
    global $pSystem;
    
    if ($szClass == "sys")
        $pObj = getSystem();
    else
        $pObj = false;
    
    $szClassName = $pSystem->getClassName($szClass);
    
    //$szClassName = "C".strtoupper(substr($szClass, 0,1)).substr($szClass, 1);
    //herIfOy("($szClassName)");

 //   print "debug|<h1>Class name: $szClassName</h1>";
    if (class_exists($szClassName))
    {
        if (!myId() && method_exists($szClassName, "handleAjaxRequest"))
        {     
            //alert("ajaxHandled(), id = ".get("id"));
            if (!$pObj)
                $pObj = getObject($szClassName, get("id"));                           
            //$pObj = new $szClassName();
            $szFunc = get("func");
            
            if (!method_exists($szClassName, "functionRequiresLogin"))
            {
                reportHackingToUserAndDb("An error has occurred. This incidence has been reported to our support center.", "", 0, false, "functionRequiresLogin() not implemented for $szClassName. Should extend CBasic");
                return;
            }

            if ($nOverrideMayCal == 0)
                $bMayCall = !$pObj->functionRequiresLogin($szFunc);
            else
                $bMayCall = ($nOverrideMayCal == 1);
                
            if (!$bMayCall)
            {
                $szExtra = (isOy()?"($szFunc) ($szClassName)":"");
                CXmlCommand::alert("You are not logged in and can't access this function $szExtra");
                getSystem()->login();
                return true;
            }
        }
        
   //     print "Class exists<br>";
        if (method_exists($szClassName, "handleAjaxRequest"))
        {        
            if (!$pObj)                        
            {
                //alert("ajaxHandled(), id = ".get("id"));
                $pObj = getObject($szClassName, get("id"));                           
            }
                //$pObj = new $szClassName();
               
                
            $pObj->handleAjaxRequest();
                
            //NOTE! This is better but only valid from PHP ver 5.3
            //$szClassName::handleAjaxRequest();
            return true;
        }

    }
    return false;
}

public function contactFormEmailAddress()
{
    reportHacking("Contact form message received.. but probably lacks redefinition of CSystem::contactFormEmailAddress()");
    return "ot@taransvar.no";
}

public function bccEmailAddress()
{
    return false;
}

public function test()
{
    return false;   //Redefine for testing purposes (only for supervisor??)
}   

public function morePersonInfo($nPersonId, &$szHTML)
{
}

public function getTableKeyField($szTableName)
{
    //151010
    switch ($szTableName)
    {
        case "Setup":
            return "";
        case "EmailOutbox":
            return "EmailId";
        default:
            return $szTableName."Id";
    }
}

}  
?>
