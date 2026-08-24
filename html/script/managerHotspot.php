<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function startManagerSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
function tableExists(mysqli $conn, string $name): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $stmt->bind_param('s',$name); $stmt->execute();
    $ok=(bool)$stmt->get_result()->fetch_row(); $stmt->close(); return $ok;
}
function serviceActive(string $name): bool {
    if (!in_array($name, ['opennds','netbird','wazuh-agent'], true)) return false;
    exec('/bin/systemctl is-active --quiet '.escapeshellarg($name), $out, $code);
    return $code===0;
}

startManagerSession();
if (empty($_SESSION['tarasec_manager_authenticated'])) reply(401,['ok'=>false,'error'=>'manager_session_required']);

try {
    $conn=getConnection();
    $requestId=(int)($_SESSION['tarasec_manager_request_id']??0);
    $stmt=$conn->prepare("SELECT 1 FROM managerRequest WHERE managerRequestId=? AND active=b'1' AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
    $stmt->bind_param('i',$requestId); $stmt->execute();
    $active=(bool)$stmt->get_result()->fetch_row(); $stmt->close();
    if(!$active){$conn->close();reply(401,['ok'=>false,'error'=>'manager_access_no_longer_active']);}

    $result=$conn->query("SELECT CAST(hotspot AS UNSIGNED) AS hotspot, COALESCE(ssid,'') AS ssid, COALESCE(nickname,'Gateway') AS name FROM setup LIMIT 1");
    $setup=$result->fetch_assoc()?:[]; $result->free();
    if((int)($setup['hotspot']??0)!==1){$conn->close();reply(404,['ok'=>false,'error'=>'hotspot_not_enabled','capability'=>false]);}

    $details=['ssid'=>(string)($setup['ssid']??''),'name'=>(string)($setup['name']??'Gateway')];
    if(tableExists($conn,'hotspotSetup')){
        $r=$conn->query("SELECT COALESCE(SSID,'') AS legacySsid, COALESCE(installationPopularName,'') AS siteName, COALESCE(location,'') AS location, lastAccessUpdate, lastAccessUpdatePoll FROM hotspotSetup LIMIT 1");
        if($row=$r->fetch_assoc()){
            if($details['ssid']==='' && $row['legacySsid']!=='')$details['ssid']=(string)$row['legacySsid'];
            $details['siteName']=(string)$row['siteName'];
            $details['location']=(string)$row['location'];
            $details['lastAccessUpdate']=$row['lastAccessUpdate'];
            $details['lastAccessPoll']=$row['lastAccessUpdatePoll'];
        }
        $r->free();
    }

    $clients=[];
    if(tableExists($conn,'dhcpClientState')){
        $r=$conn->query("SELECT clientMac, INET_NTOA(currentIp) AS currentIp, COALESCE(hostname,'') AS hostname, COALESCE(vendorClass,'') AS vendorClass, lastSeen FROM dhcpClientState WHERE lastSeen>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) ORDER BY lastSeen DESC LIMIT 100");
        while($row=$r->fetch_assoc())$clients[]=['hostname'=>(string)$row['hostname'],'vendor'=>(string)$row['vendorClass'],'mac'=>(string)$row['clientMac'],'ip'=>(string)($row['currentIp']??''),'lastSeen'=>(string)$row['lastSeen']];
        $r->free();
    }

    $authorized=0;
    if(tableExists($conn,'access')){
        $r=$conn->query("SELECT COUNT(*) FROM access WHERE hasaccess<>0");
        $authorized=(int)($r->fetch_row()[0]??0); $r->free();
    }
    $plans=0;
    if(tableExists($conn,'plans')){
        $r=$conn->query("SELECT COUNT(*) FROM plans"); $plans=(int)($r->fetch_row()[0]??0); $r->free();
    }
    $payment=['available'=>false,'configured'=>false];
    if(is_readable('/etc/tarasec-managed.conf')){
        $c=parse_ini_file('/etc/tarasec-managed.conf',false,INI_SCANNER_RAW);
        if(is_array($c)){
            $payment['available']=in_array(strtolower((string)($c['PAYMENT_AVAILABLE']??'0')),['1','true','yes'],true);
            $payment['configured']=in_array(strtolower((string)($c['PAYMENT_CONFIGURED']??'0')),['1','true','yes'],true);
        }
    }
    $conn->close();

    reply(200,[
        'ok'=>true,
        'capability'=>true,
        'hotspot'=>$details,
        'summary'=>['clientsOnline'=>count($clients),'authorizedClients'=>$authorized,'plans'=>$plans],
        'services'=>['opennds'=>serviceActive('opennds'),'managementVpn'=>serviceActive('netbird'),'soc'=>serviceActive('wazuh-agent')],
        'payment'=>$payment,
        'clients'=>$clients,
        'links'=>['poster'=>'/hotspot-poster.php','managedServices'=>'/managed-services.php'],
        'server_time'=>gmdate('c')
    ]);
} catch(Throwable $e){
    error_log('managerHotspot.php: '.$e->getMessage());
    reply(503,['ok'=>false,'error'=>'manager_hotspot_unavailable']);
}
