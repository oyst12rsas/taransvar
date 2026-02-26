<?php


function main_ticketlayout()
{
	if (!isSuperUser())
		return;

    $szLabelFile = "labelTemplate.html";

	if (request("submit") == "1")
	{
		$szLayout = request("layout");
        $myfile = fopen($szLabelFile, "w") or die("Unable to open file!");
        fwrite($myfile, $szLayout);
        fclose($myfile);

		print "New ticket layout should have been saved. This is what it should look like:<br><br>";
        print $szLayout;

        print "<br><br>".a("Not happy. Change it again", "index.php?f=main_ticketlayout");
	} 
    else
    {
	
	    $cRec = getSetup();
	    $szLayout = file_get_contents($szLabelFile);

	    $szRows = tr(td("Ticket layout",2)).
			    tr(td('<textarea name="layout" rows="12" cols="75">'.$szLayout."</textarea>",2));
	
	    $szRows .= tr(td('<button type="submit">Submit</button>',2));					
	    $szRows .= tr(td("Valid fields are: [username], [password], [priceinfo], [URL], [subscriptioninfo]",2));
	
	    print '<form  action="index.php?f=main_ticketlayout&submit=1" method="post">'.table($szRows).'</form>';
    }


    print "<br><br>".a("Admin setup", "index.php?f=main_setup");
    print "<br><br>".a("Technical setup", "index.php?f=main_tech");
	
}

//chown www-data:www-data /var/www/html/labelTemplate.html

?>
