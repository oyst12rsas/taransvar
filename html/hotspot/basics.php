<?php

function getClassFileName($class)
{
        $szSub = "class/";    //150725 
        $szFile = $szSub.substr($class, 1) . '.class.php';
        
        //NOTE! file_exists() may return false on CXmlCommand when called from shutdownFunction() 
        if (file_exists($szFile))
            return $szFile;
        else
        {
            $szFile = $szSub . $class . '.class.php';
            if (file_exists($szFile))
            {
                //reportHacking("Class file opened based on old file name for: $class ($szFile)");
                return $szFile;
            }
        }
    return false;
}

function my_autoloader($class) 
{
    $szClassFileName = getClassFileName($class);
    
    if ($szClassFileName === false)
    {
        //reportHacking("Couldn't open class file for class : $class");
        exit("Fatal error! Aborting. Class: $class");
    }
    else
        include $szClassFileName;
}

spl_autoload_register('my_autoloader');
  
function myId() {return 0;}
  
?>
