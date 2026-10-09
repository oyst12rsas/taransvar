<?php
declare(strict_types=1);
require_once __DIR__.'/../html/script/managerActiveUnits.php';
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function client(string $ip,string $seen,string $name=''): array {
    return ['lastIp'=>$ip,'lastSeen'=>$seen,'hostname'=>$name,'mac'=>'','vendor'=>''];
}
$phone=client('10.100.0.150','2026-10-09 10:55:06');
expect(mergeManagerActiveUnits([],[$phone])===[$phone],'VPN unit appears without DHCP');
$lease=client('192.168.1.2','2026-10-09 10:50:00','laptop');
$lease['mac']='aa:bb:cc:dd:ee:ff'; $lease['vendor']='DHCP vendor';
$units=mergeManagerActiveUnits([$lease],[client('192.168.1.2','2026-10-09 10:56:00'),$phone]);
expect(count($units)===2,'Merge duplicates by current IPv4 address');
expect($units[0]['mac']===$lease['mac'] && $units[0]['hostname']==='laptop','Preserve DHCP details');
expect($units[0]['lastSeen']==='2026-10-09 10:56:00','Use newer traffic observation');
expect(mergeManagerActiveUnits([],[client('','2026-10-09 10:55:00'),client('0.0.0.0','2026-10-09 10:55:00')])===[],'Omit unidentified addresses');
$many=[]; for ($i=1;$i<=120;$i++) $many[]=client('10.100.0.'.$i,sprintf('2026-10-09 10:%02d:%02d',intdiv($i,60),$i%60));
$limited=mergeManagerActiveUnits([],$many);
expect(count($limited)===100 && $limited[0]['lastIp']==='10.100.0.120','Sort and limit combined clients');
echo "Manager VPN visibility, DHCP deduplication, observation ordering and bounded list passed\n";

if (in_array('--database',$argv,true)) {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli('127.0.0.1','root','test','',3306);
    $db->query('CREATE DATABASE manager_active_test');
    try {
        $db->select_db('manager_active_test');
        $db->query('CREATE TABLE unit (unitId INT PRIMARY KEY,hostname VARCHAR(200),ipAddress INT UNSIGNED,lastSeen DATETIME)');
        $db->query('CREATE TABLE dhcpClientState (clientMac VARCHAR(30),currentIp INT UNSIGNED,hostname VARCHAR(200),vendorClass VARCHAR(100),lastSeen DATETIME)');
        $db->query('CREATE TABLE traffic (ipFrom INT UNSIGNED,created DATETIME,lastSeen DATETIME,KEY (lastSeen,ipFrom),KEY (created,ipFrom))');
        $db->query("INSERT INTO unit VALUES
            (1,NULL,INET_ATON('10.100.0.150'),NOW()-INTERVAL 30 MINUTE),
            (2,'laptop',INET_ATON('192.168.1.2'),NOW()-INTERVAL 3 MINUTE),
            (3,'stale',INET_ATON('10.100.0.4'),NOW()-INTERVAL 30 MINUTE),
            (4,'new flow',INET_ATON('10.100.0.151'),NOW()-INTERVAL 30 MINUTE)");
        $db->query("INSERT INTO dhcpClientState VALUES
            ('aa:bb:cc:dd:ee:ff',INET_ATON('192.168.1.2'),'laptop','DHCP vendor',NOW()-INTERVAL 4 MINUTE),
            ('00:11:22:33:44:55',INET_ATON('192.168.1.3'),'expired lease','',NOW()-INTERVAL 30 MINUTE)");
        $db->query("INSERT INTO traffic VALUES
            (INET_ATON('10.100.0.150'),NOW()-INTERVAL 2 HOUR,NOW()-INTERVAL 3 SECOND),
            (INET_ATON('10.100.0.150'),NOW()-INTERVAL 30 MINUTE,NOW()-INTERVAL 10 SECOND),
            (INET_ATON('10.100.0.4'),NOW()-INTERVAL 1 HOUR,NOW()-INTERVAL 30 MINUTE),
            (INET_ATON('10.100.0.151'),NOW()-INTERVAL 5 SECOND,NULL),
            (INET_ATON('203.0.113.10'),NOW(),NOW())");
        $units=managerActiveUnits($db);
        expect(count($units)===3,'Include recent known VPN/static/DHCP clients, exclude stale and external sources');
        expect($units[0]['lastIp']==='10.100.0.150','Latest traffic beats stale canonical unit timestamp');
        $latest=$db->query("SELECT DATE_FORMAT(NOW()-INTERVAL 3 SECOND,'%Y-%m-%d %H:%i:%s')")->fetch_row()[0];
        expect(abs(strtotime($units[0]['lastSeen'])-strtotime($latest))<=2,'Use latest observation across phone flows');
        expect($units[2]['mac']==='aa:bb:cc:dd:ee:ff','Deduplicate DHCP/canonical observations and preserve hardware details');
        echo "Database recent-traffic refresh, NULL timestamp fallback, stale/external exclusion and DHCP merge passed\n";
    } finally { $db->query('DROP DATABASE manager_active_test'); $db->close(); }
}
