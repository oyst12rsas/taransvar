<?php
declare(strict_types=1);
require_once __DIR__.'/manager_ssh_worker.php';
function sshExpect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
foreach (['10.100.0.150','100.68.0.9','203.0.113.5','2001:db8::1'] as $ip) sshExpect(managerSshSource($ip),'Accept observed requester address for audit');
foreach (['10.100.evil','10.100.0.256','not-an-ip'] as $ip) sshExpect(!managerSshSource($ip),'Reject malformed audit address');
foreach ([300,600,900] as $s) sshExpect(managerSshDuration($s),'Accept bounded duration');
foreach ([0,-1,1,301,901,86400] as $s) sshExpect(!managerSshDuration($s),'Reject arbitrary duration');
$row=['active'=>1,'rejectedTime'=>null,'expires'=>null,'sourceIp'=>'10.100.0.150','createdAt'=>gmdate('Y-m-d H:i:s'),'action'=>'open','seconds'=>300];
sshExpect(sshRequestValid($row),'Current authorized request');
foreach ([['active'=>0],['rejectedTime'=>gmdate('c')],['expires'=>gmdate('c',time()-1)],['createdAt'=>gmdate('c',time()-31)],['createdAt'=>gmdate('c',time()+20)],['sourceIp'=>'invalid'],['seconds'=>86400],['action'=>'evil']] as $bad) sshExpect(!sshRequestValid(array_replace($row,$bad)),'Reject revoked/stale/forged requests');
sshExpect(sshRequestValid(array_replace($row,['action'=>'close','seconds'=>0])),'Authorized close');
sshExpect(sshAllowedSources(['SSH_ALLOWED_SOURCES'=>'10.100.0.150, 100.68.2.6/16'])===['10.100.0.150/32','100.68.0.0/16'],'Preserve and normalize configured source restrictions');
sshExpect(sshAllowedSources([])===[],'Empty source policy retains existing unrestricted semantics');
sshExpect(in_array(['-p','tcp','-j','REJECT','--reject-with','tcp-reset'],sshGateRules(['SSH_ALLOWED_SOURCES'=>'100.68.10.7'],true),true),'Enforced gate has a final reject instead of falling through to permanent ACCEPT');
sshExpect(in_array(['-s','100.68.10.7/32','-m','set','--match-set',TARA_SSH_SET,'dst','-j','ACCEPT'],sshGateRules(['SSH_ALLOWED_SOURCES'=>'100.68.10.7'],true),true),'Configured sources still require an active timed lease');
try { sshAllowedSources(['SSH_ALLOWED_SOURCES'=>'bad-value']); throw new RuntimeException('Invalid source ignored'); } catch (RuntimeException $e) { sshExpect($e->getMessage()==='Unsupported SSH_ALLOWED_SOURCES entry','Reject invalid source policy'); }
$rules="-P INPUT DROP\n-A INPUT -s 203.0.113.5 -j DROP\n-A INPUT -s 10.100.0.2 -p tcp -m tcp --dport 5822 -j DROP\n-A INPUT -p tcp -m tcp --dport 5822 -m limit --limit 5/min -j LOG --log-prefix TARASEC_SSH_DISABLED_node\n-A INPUT -p tcp -m tcp --dport 5822 -j REJECT --reject-with tcp-reset\n";
sshExpect(managerSshRulePosition($rules,5822)===3,'Keep global and source-specific owner drops before temporary rule');
sshExpect(managerSshRulePosition("-A INPUT -s 100.68.10.7/32 -p tcp -m tcp --dport 5822 -j ACCEPT\n-A INPUT -p tcp -m tcp --dport 5822 -j REJECT --reject-with tcp-reset",5822)===1,'Place gate before source-specific SSH accepts after a firewall rebuild');
try { managerSshRulePosition($rules,22); throw new RuntimeException('Unrecognised port accepted'); } catch (RuntimeException $e) { sshExpect($e->getMessage()==='Configured SSH firewall policy not found','Reject unrecognised firewall'); }
sshExpect(managerSshRemaining("add tarasec_app_ssh 5822 timeout 297\nadd tarasec_app_ssh 22 timeout 900",5822)===297,'Report configured port timeout');
$tmp=tempnam(sys_get_temp_dir(),'ssh-state');
try {
    file_put_contents($tmp,json_encode(['updated'=>time()-31,'enabled'=>true]));
    sshExpect(managerSshPublic($tmp)['enabled']===false,'Stale state is unavailable, not open');
    file_put_contents($tmp,'{broken'); sshExpect(managerSshPublic($tmp)['enabled']===false,'Malformed status fails closed');
    file_put_contents($tmp,"SSH_PORT=5822\nSSH_HONEYPOT=on\nSSH_HONEYPOT_PORT=22\nSSH_HONEYPOT_PORTS=20-25\n");
    sshExpect(sshConfig($tmp)===5822,'Read root configuration');
    file_put_contents($tmp,"SSH_PORT=22\nSSH_HONEYPOT=on\nSSH_HONEYPOT_PORT=22\n");
    try { sshConfig($tmp); throw new RuntimeException('Honeypot port accepted'); } catch (RuntimeException $e) { sshExpect(str_contains($e->getMessage(),'overlaps'),'Reject honeypot collision'); }
} finally { unlink($tmp); }
echo "SSH authorization, bounded duration, request validation, stale-state, firewall order and honeypot isolation passed\n";
if (in_array('--database',$argv,true)) {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli('127.0.0.1','root','test','',3306);
    $db->query('CREATE DATABASE manager_ssh_test');
    try {
        $db->select_db('manager_ssh_test');
        $db->query(file_get_contents(__DIR__.'/manager_ssh.sql'));
        $db->query('CREATE TABLE managerRequest(managerRequestId INT PRIMARY KEY, active BIT, rejectedTime DATETIME, expires DATETIME)');
        $db->query("INSERT INTO managerRequest VALUES (1,b'1',NULL,NULL),(2,b'0',NULL,NULL)");
        $db->query("INSERT INTO managerSshRequest(requestId,managerRequestId,sourceIp,action,seconds) VALUES(REPEAT('a',32),1,'10.100.0.150','open',300),(REPEAT('b',32),2,'10.100.0.4','open',900)");
        $rows=$db->query("SELECT r.*,CAST(m.active AS UNSIGNED) active,m.rejectedTime,m.expires FROM managerSshRequest r LEFT JOIN managerRequest m ON m.managerRequestId=r.managerRequestId WHERE r.state='pending' ORDER BY r.sequenceId LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        sshExpect(sshRequestValid($rows[0]) && !sshRequestValid($rows[1]),'Recheck manager BIT authorization in queued SQL rows');
        $q=$db->prepare('UPDATE managerSshRequest SET state=?,appliedAt=NOW() WHERE sequenceId=?');
        $state='applied'; $id=(int)$rows[0]['sequenceId']; $q->bind_param('si',$state,$id); $q->execute(); $q->close();
        sshExpect($db->query("SELECT state FROM managerSshRequest WHERE sequenceId=$id")->fetch_row()[0]==='applied','Persist worker result');
        echo "SSH queue schema and manager revocation database regression passed\n";
    } finally { $db->query('DROP DATABASE manager_ssh_test'); $db->close(); }
}
