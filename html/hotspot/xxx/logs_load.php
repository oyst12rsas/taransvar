<?php

function logs_load()
{
	$pDb = new CDb;
	$cFlds = array();
	$szRows = tr(th("Average load per minute",4)).tr(th("Log time").th("1 min").th("5 min").th("10 min"));

	print h1("Load average:");
	
	$szDesc = "<br><br><p>The server load tells how many tasks are waiting to be processed. A server load less than 10 is normally considered acceptable.</p><p>The 3 columns give the load average for last 1, 5 and 10 minutes respectively.</p><p>This snapshot is taken immediately after the access is updated.</p>";

	while ($cFetched = $pDb->fetchNext("select * from loadavg order by logtime desc limit 20", $cFlds))
		$szRows .= tr(td(substr($cFetched["logtime"],0,16)).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));


	//Max per hour last 24 hour
	

//	The code below requires that hotspot/perl/ (/root/wifi/perl/)  syssnapshot.pl runs in crontab... which is't currently not...
	
	while ($cFetched = $pDb->fetchNext("select * from loadavg order by logtime desc limit 20", $cFlds))
		$szRows .= tr(td(substr($cFetched["logtime"],0,16)).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));

	$pDb->pStmt = NULL;
	
	$szMaxLast24h = tr(th("Max load per hour",4)).tr(th("Time").th("1 min").th("5 min").th("10 min"));
	$szSQL = "select left(logtime,13) as logtime, max(min1) as min1, max(min5) as min5, max(min10) as min10 from loadavg where logtime > DATE_SUB(NOW(), INTERVAL 24 hour) group by left(logtime,13) order by left(logtime,13) desc";
	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
		$szMaxLast24h .= tr(td($cFetched["logtime"]).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));
	
	$pDb->pStmt = NULL;
	$szMaxLastMonth = tr(th("Max load per month",4)).tr(th("Time").th("1 min").th("5 min").th("10 min"));
	$szSQL = "select left(logtime,10) as logtime, max(min1) as min1, max(min5) as min5, max(min10) as min10 from loadavg where logtime > DATE_SUB(NOW(), INTERVAL 1 month) group by left(logtime,10) order by left(logtime,10) desc";
	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
		$szMaxLastMonth .= tr(td($cFetched["logtime"]).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));

	print table(tr(td(table($szRows).table($szMaxLastMonth),1,'width="60%"').td($szDesc.table($szMaxLast24h),1,'valign="top"')));

}

?>
