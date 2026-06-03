
//gatekeeper.js




function debugging()
{
    return true;
}

function xmlHandled(xmlDoc)
{
    if (!xmlDoc)
        return false;   //Handle as text...
        
    var cCommands = xmlDoc.getElementsByTagName("command");
    if (!cCommands.length)
        return false;
        
    //alert(cCommands.length+" commands");

    for (var n=0; n<cCommands.length; n++)
        handleCommand(cCommands[n]);
    return true;
}


function partnerscan()
{
    var cRes = document.getElementById("scanresult"); 
    cRes.innerHTML = "Scanning network... Please wait";

    request('partnerscan','');
    //alert("Parterscan will be performed");
}


//var szUpdateRoutine = "processName"; - Set this locally to initialize the process - then catch the processName in ajax.php

function myUpdaterFunction() {
  console.log('Routine ran at ' + new Date().toLocaleTimeString());
  // Add your desired code here
    
    if (typeof szUpdateRoutine !== 'undefined') {
        var cReportId;
        const table = document.getElementById(szUpdateRoutine+"Tbl");
        console.log("No "+szUpdateRoutine+"Tbl defined.");
    	const firstRow = table.rows[1];	//The header is row 0
        if (firstRow)   //Only true if there's table heading + one row
        {
    	    cReportId = firstRow.id.substr(2);
            if (!cReportId)
                cReportId = "NAN";
            console.log(szUpdateRoutine+ " ID: "+firstRow.id + ", reportid: "+cReportId);
        }
        else
        {
            cReportId = "NAN";
            console.log("No rows or no table header.. Can't read id of row 2 of "+szUpdateRoutine+"Tbl");
        }
    	request(szUpdateRoutine,"id="+cReportId);
    }

    request("tagStatus", "");
}

function initUpdater()
{
//	szUpdateRoutine = szRoutine;

	const intervalId = setInterval(myUpdaterFunction, 1000);

/*	document.addEventListener("DOMContentLoaded", function () {

    	const table = document.getElementById(szUpdateRoutine+"Tbl");

        if (!table)
            alert("Can't find table to work on");

    	const observer = new IntersectionObserver(entries => {
        	entries.forEach(entry => {
            	if (entry.isIntersecting) {
                	//tableVisible();
					const intervalId = setInterval(myUpdaterFunction, 1000);
        	    }
        	});
    	});

    	observer.observe(table);
	});
*/

}

function tagStatusClicked()
{
	//alert("Hey, you clicked..");
	var cDiv = document.getElementById("tagStatusExtra");
	cDiv.innerHTML = '<br><a href="index.php?f=tagStatus">See tag status</a>';
    cDiv.style.display = "block";    
}


