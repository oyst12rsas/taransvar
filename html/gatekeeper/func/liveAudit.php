<?php

function liveAudit()
{
    if (!isAdmin())
        return;

    $nInfectionId = isset($_GET["infectionId"]) ? intval($_GET["infectionId"]) : 0;
    $nReportId = isset($_GET["reportId"]) ? intval($_GET["reportId"]) : 0;

    print '<h2>Live TaraSec audit</h2>';
    print '<p>Watch the selected current infection state and the hackReport evidence feeding it. The page refreshes the selected records every second using the existing Gatekeeper AJAX command mechanism.</p>';

    print '<form method="get" action="index.php">';
    print '<input type="hidden" name="f" value="liveAudit">';
    print '<table><tr><th>Record</th><th>ID</th><th>&nbsp;</th></tr>';
    print '<tr><td>internalInfections</td><td><input name="infectionId" value="'.($nInfectionId ?: '').'" size="10"></td><td rowspan="2"><input type="submit" value="Watch live"></td></tr>';
    print '<tr><td>hackReport</td><td><input name="reportId" value="'.($nReportId ?: '').'" size="10"></td></tr></table>';
    print '</form>';

    print '<div id="liveAuditStatus">Waiting for live update…</div>';
    print '<div id="liveAuditCurrent"></div>';
    print '<div id="liveAuditReport"></div>';
    print '<div id="liveAuditRelated"></div>';

    $cParams = array();
    if ($nInfectionId)
        $cParams[] = 'infectionId='.$nInfectionId;
    if ($nReportId)
        $cParams[] = 'reportId='.$nReportId;
    $szParams = implode('&', $cParams);

    print '<script>';
    print 'var liveAuditParams = '.json_encode($szParams).';';
    print 'function liveAuditUpdater(){ request("liveAudit", liveAuditParams); }';
    print 'setTimeout(liveAuditUpdater, 100);';
    print 'setInterval(liveAuditUpdater, 1000);';
    print '</script>';

    print '<p><a href="index.php?f=infections">Back to infections</a> · <a href="index.php?f=hackReports">hackReports</a></p>';
}

?>
