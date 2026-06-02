
function myUpdaterFunction() {
	console.log('Routine ran at ' + new Date().toLocaleTimeString());
	// Add your desired code here
    
	var node1 = document.getElementById("node1");
	var node2 = document.getElementById("node2");
	var router = document.getElementById("router");

	if (typeof node1 === 'undefined' || typeof node2 === 'undefined' || typeof router === 'undefined')
	{
		alert("Fields not found..");
	}

	node1.innerHTML = "IP er: "+node1.name;
	node2.innerHTML = "IP er: "+node2.name;

    request("tagStatus", "");
}
