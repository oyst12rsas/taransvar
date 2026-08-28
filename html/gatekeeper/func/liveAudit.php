<?php

function liveAudit()
{
    if (!isAdmin())
        return;

    $nInfectionId = isset($_GET["infectionId"]) ? intval($_GET["infectionId"]) : 0;
    $nReportId = isset($_GET["reportId"]) ? intval($_GET["reportId"]) : 0;

    $szScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $szHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $szGatekeeperBase = $szHost ? $szScheme.'://'.$szHost : '';

    $szServerA = isset($_GET['serverA']) ? trim($_GET['serverA']) : '';
    $szServerB = isset($_GET['serverB']) ? trim($_GET['serverB']) : '';

    $normaliseHost = function($value) {
        $value = trim($value);
        if ($value === '')
            return '';
        if (!preg_match('#^https?://#i', $value))
            $value = 'http://'.$value;
        return rtrim($value, '/');
    };

    $szServerABase = $normaliseHost($szServerA);
    $szServerBBase = $normaliseHost($szServerB);

    $cDemoNodes = array(
        'Tomato' => 'http://100.68.22.33',
        'Roquefort' => 'http://100.68.176.110',
        'Camembert' => 'http://100.68.149.164',
        'Gouda' => 'http://100.68.51.247'
    );

    print '<h2>Live TaraSec audit</h2>';
    print '<p>Observer page for Demo 1 and Demo 2. Watch infection/hackReport records here and use the link panel below to open the relevant gateway, node and API views.</p>';

    print '<form method="get" action="index.php">';
    print '<input type="hidden" name="f" value="liveAudit">';
    print '<table>';
    print '<tr><th>Record / host</th><th>Value</th><th>&nbsp;</th></tr>';
    print '<tr><td>internalInfections</td><td><input name="infectionId" value="'.htmlspecialchars($nInfectionId ?: '').'" size="12"></td><td rowspan="4"><input type="submit" value="Update observer"></td></tr>';
    print '<tr><td>hackReport</td><td><input name="reportId" value="'.htmlspecialchars($nReportId ?: '').'" size="12"></td></tr>';
    print '<tr><td>Demo 2 Server A</td><td><input name="serverA" value="'.htmlspecialchars($szServerA).'" size="28" placeholder="IP or URL"></td></tr>';
    print '<tr><td>Demo 2 Server B</td><td><input name="serverB" value="'.htmlspecialchars($szServerB).'" size="28" placeholder="IP or URL"></td></tr>';
    print '</table>';
    print '</form>';

    print '<div id="liveAuditStatus">Waiting for live update…</div>';
    print '<div id="liveAuditCurrent"></div>';
    print '<div id="liveAuditReport"></div>';
    print '<div id="liveAuditRelated"></div>';

    print '<h3>Demo observer links</h3>';
    print '<p>Use <b>Copy URL</b> to move a link to another device, or <b>Open</b> to launch it in a new browser tab/window.</p>';

    $printLink = function($label, $url, $note = '') {
        if (!$url)
            return;
        $id = 'u'.substr(md5($label.$url), 0, 10);
        print '<tr>';
        print '<td><b>'.htmlspecialchars($label).'</b>'.($note ? '<br><small>'.htmlspecialchars($note).'</small>' : '').'</td>';
        print '<td><input id="'.$id.'" value="'.htmlspecialchars($url).'" size="64" readonly></td>';
        print '<td><button type="button" onclick="copyDemoUrl(\''.$id.'\', this)">Copy URL</button></td>';
        print '<td><button type="button" onclick="openDemoUrl(\''.$id.'\')">Open</button></td>';
        print '</tr>';
    };

    print '<table border="1" cellspacing="0" cellpadding="5">';
    print '<tr><th>View</th><th>URL</th><th>Clipboard</th><th>Browser</th></tr>';

    if ($szGatekeeperBase) {
        $printLink('This Gatekeeper · live audit', $szGatekeeperBase.'/gatekeeper/index.php?f=liveAudit', 'Central observer page');
        $printLink('This Gatekeeper · internal infections', $szGatekeeperBase.'/gatekeeper/index.php?f=infections', 'Demo 1 current infection state');
        $printLink('This Gatekeeper · hackReports', $szGatekeeperBase.'/gatekeeper/index.php?f=hackReports', 'Demo 2 evidence ledger');
        $printLink('This gateway · phone infection API', $szGatekeeperBase.'/script/appLocalInfection.php', 'What the app reads for this phone');
        $printLink('This gateway · propagated threat API', $szGatekeeperBase.'/script/appInfection.php', 'Threat/tag state visible to receivers');
        $printLink('This gateway · identity API', $szGatekeeperBase.'/script/appNode.php', 'Gateway/node identity');
    }

    foreach ($cDemoNodes as $name => $base) {
        $printLink('Demo node · '.$name.' · Gatekeeper', $base.'/gatekeeper/index.php?f=liveAudit', $name.' observer');
        $printLink('Demo node · '.$name.' · threat API', $base.'/script/appInfection.php', $name.' observed TaraSec state');
        $printLink('Demo node · '.$name.' · identity API', $base.'/script/appNode.php', $name.' identity/reachability');
    }

    if ($szServerABase) {
        $printLink('Demo 2 · Server A · Gatekeeper', $szServerABase.'/gatekeeper/index.php?f=liveAudit', 'Rejecting SSH server');
        $printLink('Demo 2 · Server A · hackReports', $szServerABase.'/gatekeeper/index.php?f=hackReports', 'Initial rejection evidence');
        $printLink('Demo 2 · Server A · threat API', $szServerABase.'/script/appInfection.php', 'Server A observed state');
    }

    if ($szServerBBase) {
        $printLink('Demo 2 · Server B · Gatekeeper', $szServerBBase.'/gatekeeper/index.php?f=liveAudit', 'Accepting SSH server / contradictory evidence');
        $printLink('Demo 2 · Server B · hackReports', $szServerBBase.'/gatekeeper/index.php?f=hackReports', 'Accepted tagged-traffic evidence');
        $printLink('Demo 2 · Server B · threat API', $szServerBBase.'/script/appInfection.php', 'Server B observed state');
    }

    print '</table>';

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
    print 'function copyDemoUrl(id,button){';
    print ' var el=document.getElementById(id); if(!el)return; var value=el.value;';
    print ' if(navigator.clipboard && window.isSecureContext){navigator.clipboard.writeText(value).then(function(){demoCopied(button);}).catch(function(){legacyCopyDemoUrl(el,button);});}';
    print ' else legacyCopyDemoUrl(el,button);';
    print '}';
    print 'function legacyCopyDemoUrl(el,button){el.focus();el.select();el.setSelectionRange(0,99999);try{document.execCommand("copy");demoCopied(button);}catch(e){window.prompt("Copy URL:",el.value);}}';
    print 'function demoCopied(button){var old=button.innerText;button.innerText="Copied";setTimeout(function(){button.innerText=old;},1200);}';
    print 'function openDemoUrl(id){var el=document.getElementById(id);if(el&&el.value)window.open(el.value,"_blank","noopener");}';
    print '</script>';

    print '<p><a href="index.php?f=infections">Back to infections</a> · <a href="index.php?f=hackReports">hackReports</a></p>';
}

?>
