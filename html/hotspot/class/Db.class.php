<?php 
/*$szDBHost = "localhost";
$szDBDBName = "radius";
$szDBUserName = "radiuser9";
$szDBPass = "pass4638radiJFK";*/
$global_PBO_connection = false;

//Should implement: http://stackoverflow.com/questions/23064698/simple-search-feature-mysql-prepared-statement-issue
class CDb extends CBasic
{
    private $dbh = null;
    public $pStmt = null;
    public $bRewind = false;
    public $bBigSelects = false;    //Set false when error that checks too many rows.. Runs SET SQL_BIG_SELECTS=1

    const const_match = 0x1;
    const const_teamActivityPerson = 0x2;
    const const_recurring = 0x4;
    const const_family = 0x8;
    
    const const_assignedWorkingbee = 0x10;//16;
    const const_assignedWorkingbeeActivity = 0x20;//32;
    const const_freeShifts = 0x40;//64;
    const const_shiftsAssignedKid = 0x80;//128;
    const const_shiftsAssignedSomeParent = 0x100;//256;
    const const_calendarSubscriptions = 0x200;//512;
    const const_shiftOnKidNoParent = 0x400;//1024;
    const const_freeShiftOnKidNoParent = 0x800;//2048;
    const const_pendingAccounting = 0x1000; //4096;
    const const_listedTeamsOnWB = 0x2000; //8192;
    const const_next = 0x4000;    //???;
    //16384
    //32768    

    const const_all = 0x3FFF;
    
    static function allAssigned() 
        {return CDb::const_all - CDb::const_freeShifts - CDb::const_freeShiftOnKidNoParent;}
    static function myAssignedWorkingBees() 
        {return CDb::const_assignedWorkingbee + CDb::const_assignedWorkingbeeActivity + 
                CDb::const_shiftsAssignedKid + CDb::const_shiftsAssignedSomeParent + CDb::const_shiftOnKidNoParent;
        }
    
    public function __construct()
    {
        global $szDBHost, $szDBDBName, $szDBUserName, $szDBPass, $global_PBO_connection;
        
        //if (!runningLocally())
        //print "Trying to log in: "
        //charset=UTF8 generates problem on server... 140319
        //$this->dbh = new PDO('mysql:host=localhost;charset=UTF8;dbname='.$szDBDBName, $szDBUserName, $szDBPass);
        
        if (!$global_PBO_connection)
        {
		if (file_exists("system.txt"))
		{
		$szDBHost = "localhost";
		$szDBDBName = "cyberrehab_org";
		$szDBUserName = "cyberrehab_org";
		$szDBPass = "yzPVwtnd";
		}
		else
		{
		$szDBHost = "localhost";
		$szDBDBName = "taransvar";
		$szDBUserName = "scriptUsrAces3f3";
		$szDBPass = "rErte8Oi98e-2_#";//"rErte8Oi98!%&e";
		}
		
            try {
            $global_PBO_connection = new PDO('mysql:host='.$szDBHost.';dbname='.$szDBDBName.";charset=utf8", $szDBUserName, $szDBPass);
            }
             catch(PDOException $e){
                reportHacking("Error connecting to mysql:". $e->getMessage());
                die();
            }
                
            $global_PBO_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            //150223: No need anymore... parateter to login... $pStmt = $global_PBO_connection->prepare("SET NAMES 'utf8'");
            //150223: No need anymore  $pStmt->execute($pParams = array());
        }
        
        $this->dbh = $global_PBO_connection;
        $this->pStmt = null; //execute() above sets pStmt..
    }

public function memberFunctionIsDefined($szFunc) 
{
    return in_array($szFunc, array("checkDbVer","setDbVer"));
}
    
    static function get()
    {
        return new CDb();
    }
    
    static function doExec($szSQL, $cParamArr)
    {
        CDb::get()->execute($szSQL, $cParamArr);
    }
    
    public function prepareAndExecute($szSQL, &$cParamArr)
    {
        if ($this->bBigSelects) //150211
            $this->dbh->query("SET SQL_BIG_SELECTS=1"); //NOTE! This is never being tured off again... Didn't check if that's a problem..
        
        $this->pStmt = $this->dbh->prepare($szSQL);
/*        foreach($cParamArr as $cParam)
        {
            $nFld = $cParam[0];
            $szVal = $cParam[1];
            $this->pStmt->bindParam($nFld, $szVal, PDO::PARAM_INT);//, $cParam[2]);
        }
*/        
        if (isset($cParamArr) and !is_array($cParamArr))
        {
            reportHacking("Not an array in prepareAndExecute().. ");
            return false;    
        }
        
        try {
            $this->pStmt->execute($cParamArr);    
        }
        catch (PDOException $e) {
            giveSqlFailedErrorThenDie($szSQL, $e);
        }
        return $this->pStmt;
    }

    public function execute($szSQL, $cParamArr)
    {
        $this->prepareAndExecute($szSQL, $cParamArr);
    }
   
    public function multiExecute($szSQL)
    {
        //140919
        try {
        $nCount = $this->dbh->exec($szSQL);
        }
        catch (PDOException $e) {
            giveSqlFailedErrorThenDie($szSQL, $e);
            $nCount = false;
        }
        return $nCount;
        
/*        $this->pStmt = $this->dbh->prepare($szSQL);
        try {
            $this->pStmt->execute($cParamArr);    
        }
        catch (PDOException $e) {
            giveSqlFailedErrorThenDie($szSQL, $e);
        }
        return $this->pStmt;
        */
    }   

    
    //public function fetchAll($szSQL, &$cParamArr)
    public function fetchAll($szSQL, $cParamArr, $nFetchStyle = PDO::FETCH_BOTH)
    {
        $this->pStmt = $this->dbh->prepare($szSQL);                            //150127
        $bOk = false;
        
        try {                                        
            $bOk = $this->pStmt->execute($cParamArr);
        }
        
        catch(PDOException $e) 
        {    
            //******************** NOTE! Not working in debugger... stops on the execute() above..
             //140611  n executeSqlOk()
             giveSqlFailedErrorThenDie($szSQL);
             //Never gets here...
        }
        if ($bOk)
            return  $this->pStmt->fetchAll($nFetchStyle);
        else
            return false;

/*        $cStmt = $this->prepareAndExecute($szSQL, $cParamArr);
        $result = $cStmt->fetchAll();
        return $result; */
    }
    
    public function fetch($szSQL, $cParamArr, $nFetchStyle = PDO::FETCH_BOTH)   //= PDO::FETCH_ASSOC | PDO::FETCH_NUM
    {
        if ($this->bBigSelects) //150211
            $this->dbh->query("SET SQL_BIG_SELECTS=1"); //NOTE! This is never being tured off again... Didn't check if that's a problem..
        
        $this->pStmt = $this->dbh->prepare($szSQL);
        
        try {
            $bRes = $this->pStmt->execute($cParamArr);
        }
        catch (PDOException $e) {
            giveSqlFailedError($szSQL, $e, $bWarnUser = false);
            $bRes = false;
            //giveSqlFailedErrorThenDie($szSQL, $e);
        }
        
        if ($bRes)
            return  $this->pStmt->fetch($nFetchStyle);
        else
            return false;   //Never gets here....
    }
    
    public function fetchNext($szSQL, $cParamArr, $bBigSelects = false)
    {
        if (!$this->pStmt)
        {
            if ($bBigSelects || $this->bBigSelects)
                //NOTE! This is never being tured off again... Didn't check if that's a problem..
                $this->dbh->query("SET SQL_BIG_SELECTS=1");
            
            $this->pStmt = $this->dbh->prepare($szSQL);
            if (!$this->pStmt->execute($cParamArr))
                return false;
        }
        
        return $this->pStmt->fetch();
    }

    public function fetchNxt($nFetchStyle = PDO::FETCH_BOTH)
    {
        //Note! First call prepareAndExecute.. then use this to traverse..
        if (!$this->pStmt)
            return false;   //Should never happen...
        
        //$nFetchStyle = PDO::FETCH_BOTH; //PDO::FETCH_ASSOC
        $nFetchWhat = PDO::FETCH_ORI_NEXT;//Only next supported by mysql.. ($this->bRewind?PDO::FETCH_ORI_FIRST:PDO::FETCH_ORI_NEXT);

        $cFound = $this->pStmt->fetch($nFetchStyle, $nFetchWhat);
        $this->bRewind = false;
        return $cFound;
    }
    
    
    public function getInt($szSQL, $cParamArr)
    {
        $cArr = $this->fetch($szSQL, $cParamArr);
        if ($cArr === false)
            return 0;
        return $cArr[0]+0;
    }

    public function getHtml($szSQL, $cParamArr)
    {
        $nFound = 0;
        $szHtml = "";
        
        while ($cRec = $this->fetchNext($szSQL, $cParamArr))
        {
            if (!$nFound++)
            {
                $szHtml = '<table><tr>';
                foreach($cRec as $szFld => $szVal)
                    $szHtml .= '<th>'.$szFld.'</th>';
                    
                $szHtml .= "</tr>";
            }
            
            $szHtml .= "<tr>";
                foreach($cRec as $szFld => $szVal)
                    $szHtml .= '<th>'.$szVal.'</th>';
            $szHtml .= "</tr>";
        }

        if ($nFound++)
        {
            $szHtml .= '</table>';
        }
        
        return $szHtml;
    }

    static function getString($szSQL, $cFldArray)
    {
        $cFound = CDb::get()->fetch($szSQL, $cFldArray);
        if ($cFound)
            return $cFound[0];
        else
            return false;
    }
    
    static function fet($szSQL, $cFldArray, $nFetchStyle = PDO::FETCH_BOTH)
    {
        return CDb::get()->fetch($szSQL, $cFldArray, $nFetchStyle);
    }
    
    public function PDONumb ($Var)
    {
        $sth = $this->dbh->prepare("{$Var}");
        $sth->execute();
        $count = $sth->rowCount();
        return $count;
    }
    // Other methods here

static function setDbVer()
{
    if (!isSupervisor())
    {
        reportHacking("Non-supervisor in setDbVer()");
        return;    
    }
    
    $nCurrentVer = CDb::getString("select DBVersion from Setup",array())+0;
    
    if (isset($_GET["txt"]))
    {
        $nNewDbVer = get("txt")+0;
        
        if ($nNewDbVer > $nCurrentVer + 5 || $nNewDbVer < $nCurrentVer - 15)
        {
            reportHackingToUserAndDb("New version number is out of range","Setup",0,false,"Valid range is current-10 to current");
            return;
        }
        $szSQL = "update Setup set DBVersion = :new";
        CDb::get()->execute($szSQL, array(":new"=>$nNewDbVer));
        alert("DB version number is changed.");
    }
    else
        CXmlCommand::prompt("New DB version:", $nCurrentVer, "db:setDbVer",array());
}    
  
function lastInsertId() {return $this->getInt("SELECT LAST_INSERT_ID();",$cTmp=array());}
   
            
static function checkDbVer()
{
    CDb::checkDBVersion();   //Webmaster menu..
}    

//static function vKidsActivitySql()
//{
//}

static function compareResults($cSet1, $cSet2)
{
    $nDifferences = 0;
    $szHtml = "";
    if (($nSet1 = sizeof($cSet1))!= ($nSet2 = sizeof($cSet2)))
    {
        $szHtml .= red("Size differs: Set1: $nSet1, Set2: $nSet2")."<br>";
        $nSize = ($nSet1 < $nSet2?$nSet1:$nSet2);
        $nDifferences++;
    }
    else
        $nSize = $nSet1;
        
    for ($n = 0; $n < $nSize; $n++)
    {
        $bRecChanged = false;
        $szFld = "";
        if (($nRec1 = sizeof($cSet1[$n]))!= ($nRec2 = sizeof($cSet2[$n])))
        {
            $szFld .= red("Num of fields differs: Rec1: $nRec1, Rec2: $nRec2")."<br>";
            $bRecChanged = true;
            $nFlds = ($nRec1 < $nRec2?$nRec1:$nRec2);
            $nDifferences++;
        }
        else
            $nFlds = $nRec1;
            
        for ($m = 0; $m < $nFlds/2; $m++)
        {
            $b1Set = isset($cSet1[$n][$m]);
            $b2Set = isset($cSet2[$n][$m]);
            
            if ($b1Set != $b2Set)
                $szFld .= "Only one field blank<br>";
            else
                if ($cSet1[$n][$m] != $cSet2[$n][$m])
                {
                    $szFld .= "Rec1: ".$cSet1[$n][$m].", rec2: ".$cSet2[$n][$m]."<br>";
                    $bRecChanged = true;
                    $nDifferences++;
                }
        }
        
        if ($bRecChanged)
            $szHtml .= "Record $n: ".$szFld."<br>";
    }    
    
    $szHtml .= "<br>Number of differences: $nDifferences";
    
    CBasic::display($szHtml);
}

    
static public function checkDBVersion()
{ //131026
    CSysTools::checkDBVersion();
}
  
public function rewindCurser()
{
    //$this->bRewind = true;
    reportHacking("Rewind not supported by mysql..");
}
  
static function getPreparedSqlSample($szSQL, $cArr)
{
    
    foreach($cArr as $szKey => $szVal)
    
    {
        $szSQL = str_replace($szKey, $szVal, $szSQL);
    }
    return $szSQL;    
}
  
static function promptIfOy($szSQL, $cArr)
{
    promptIfOy(CDb::getPreparedSqlSample($szSQL, $cArr));
}
  
static function convertWikies()
{
}  
    
}
?>
