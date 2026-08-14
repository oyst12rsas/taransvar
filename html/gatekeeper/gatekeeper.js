
//gatekeeper.js



function updateCountdown() {
    // Target date/time
    var cTargetTime = document.getElementById("targetTime");

    if (!cTargetTime)
        return;

    const targetDate = new Date(cTargetTime.innerHTML);//

    const now = new Date();
    const diff = targetDate - now;

    if (diff <= 0) {
        document.getElementById("countdown").innerHTML = "Time's up!";
        clearInterval(timer);
        return;
    }

    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
    const minutes = Math.floor((diff / (1000 * 60)) % 60);
    const seconds = Math.floor((diff / 1000) % 60);

    var cDiv = document.getElementById("countdown");

    if (cDiv)
        cDiv.innerHTML = `${days}d ${hours}h ${minutes}m ${seconds}s`;
}

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

		if (!table)
		{
			console.log(szUpdateRoutine+"Tbl table not defined. Aborting background update function.");
			return;
		}

        console.log("No "+szUpdateRoutine+"Tbl defined.");
    	const firstRow = table.rows[1];	//The header is row 0
        if (firstRow)   //Only true if there's table heading + one row
        {
    	    cReportId = firstRow.id.substr(2);
            if (!cReportId)
                cReportId = "NAN";
            //console.log(szUpdateRoutine+ " ID: "+firstRow.id + ", reportid: "+cReportId);
        }
        else
        {
            cReportId = "NAN";
            //console.log("No rows or no table header.. Can't read id of row 2 of "+szUpdateRoutine+"Tbl");
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


/*
 * Gatekeeper navigation
 *
 * Keep the old PHP URLs intact, but present submenus as dropdowns instead of
 * rendering another menu table below the main navigation.
 */
function initGatekeeperMenus()
{
    if (!document.getElementById("gatekeeper-menu-style"))
    {
        const style = document.createElement("style");
        style.id = "gatekeeper-menu-style";
        style.textContent = `
            .gk-dropdown { position: relative; display: inline-block; }
            .gk-dropdown-button {
                background: transparent;
                border: 0;
                padding: 0;
                margin: 0;
                font: inherit;
                color: inherit;
                cursor: pointer;
                text-decoration: underline;
            }
            .gk-dropdown-content {
                display: none;
                position: absolute;
                left: 0;
                top: calc(100% + 8px);
                min-width: 190px;
                background: white;
                border: 1px solid #7a3f3f;
                box-shadow: 0 6px 18px rgba(0,0,0,.20);
                z-index: 10000;
                text-align: left;
            }
            .gk-dropdown-content a {
                display: block;
                padding: 9px 12px;
                color: #111;
                text-decoration: none;
                white-space: nowrap;
            }
            .gk-dropdown-content a:hover,
            .gk-dropdown-content a:focus {
                background: #e8f1f7;
            }
            .gk-dropdown.open > .gk-dropdown-content,
            .gk-dropdown:hover > .gk-dropdown-content,
            .gk-dropdown:focus-within > .gk-dropdown-content {
                display: block;
            }
            .gk-local-menu {
                position: relative;
                display: inline-block;
                margin: 8px 0 14px 0;
            }
            .gk-local-menu .gk-dropdown-button {
                background: white;
                border: 1px solid #7a3f3f;
                padding: 8px 12px;
                text-decoration: none;
            }
        `;
        document.head.appendChild(style);
    }

    const setupLink = document.querySelector('a[href="index.php?f=setupMenu"]');
    if (setupLink && !setupLink.closest(".gk-dropdown"))
    {
        const holder = document.createElement("div");
        holder.className = "gk-dropdown";

        const button = document.createElement("button");
        button.type = "button";
        button.className = "gk-dropdown-button";
        button.textContent = "Setup ▾";
        button.setAttribute("aria-haspopup", "true");
        button.setAttribute("aria-expanded", "false");

        const menu = document.createElement("div");
        menu.className = "gk-dropdown-content";
        menu.innerHTML = `
            <a href="index.php?f=partners">Partners</a>
            <a href="index.php?f=servers">Servers</a>
            <a href="index.php?f=domains">Domains</a>
            <a href="index.php?f=colorListings">W/B list</a>
            <a href="index.php?f=inspections">Inspections</a>
            <a href="index.php?f=assistance">Assistance</a>
            <a href="index.php?f=honey">Honey</a>
            <a href="index.php?f=setup">Setup</a>
            ${document.querySelector('a[href="index.php?f=users"]') ? '' : '<a href="index.php?f=users">Users</a>'}
        `;

        setupLink.replaceWith(holder);
        holder.appendChild(button);
        holder.appendChild(menu);

        button.addEventListener("click", function (event) {
            event.stopPropagation();
            const open = holder.classList.toggle("open");
            button.setAttribute("aria-expanded", open ? "true" : "false");
        });
    }

    document.querySelectorAll(".gk-local-menu .gk-dropdown-button").forEach(function(button) {
        if (button.dataset.gkBound)
            return;
        button.dataset.gkBound = "1";
        button.addEventListener("click", function(event) {
            event.stopPropagation();
            const holder = button.closest(".gk-dropdown");
            if (!holder)
                return;
            const open = holder.classList.toggle("open");
            button.setAttribute("aria-expanded", open ? "true" : "false");
        });
    });

    document.addEventListener("click", function() {
        document.querySelectorAll(".gk-dropdown.open").forEach(function(menu) {
            menu.classList.remove("open");
            const button = menu.querySelector(".gk-dropdown-button");
            if (button)
                button.setAttribute("aria-expanded", "false");
        });
    });
}

document.addEventListener("DOMContentLoaded", initGatekeeperMenus);
