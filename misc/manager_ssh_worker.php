<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require_once __DIR__.'/../html/script/managerSshCommon.php';

function sshRun(array $args, bool $allowFailure=false): string {
    $pipes=[];
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot execute firewall command');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]); $code=proc_close($p);
    if ($code!==0 && !$allowFailure) throw new RuntimeException('Firewall command failed: '.$args[0].': '.trim(substr($err,0,400)));
    return $code===0 ? $out : '';
}
function sshReadConfig(string $path): array {
    $cfg=[];
    foreach (file($path,FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/D',$line,$m)) $cfg[$m[1]]=trim($m[2],"\"'");
    }
    return $cfg;
}
function sshAllowedSources(array $cfg): array {
    $sources=[];
    foreach (explode(',', $cfg['SSH_ALLOWED_SOURCES'] ?? '') as $source) {
        $source=preg_replace('/\s+/', '', $source);
        if ($source==='') continue;
        $parts=explode('/',$source);
        $bits=isset($parts[1]) ? filter_var($parts[1],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>32]]) : 32;
        if (count($parts)>2 || !filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || $bits===false) throw new RuntimeException('Unsupported SSH_ALLOWED_SOURCES entry');
        $mask=$bits===0 ? 0 : (-1 << (32-$bits));
        $sources[]=long2ip(ip2long($parts[0]) & $mask).'/'.$bits;
    }
    if (count($sources)>64) throw new RuntimeException('Too many SSH_ALLOWED_SOURCES entries');
    return array_values(array_unique($sources));
}
function sshGateRules(array $cfg, bool $enforced): array {
    $rules=[];
    if ($enforced) {
        $rules[]=['-s','127.0.0.0/8','-i','lo','-j','ACCEPT'];
        $rules[]=['-m','conntrack','--ctstate','RELATED,ESTABLISHED','-j','ACCEPT'];
        if (in_array(strtolower($cfg['SSH_RECOVERY_PROTECT'] ?? 'on'),['on','1','yes','true'],true)) {
            $recovery=sshAllowedSources(['SSH_ALLOWED_SOURCES'=>$cfg['SSH_RECOVERY_SOURCES'] ?? '']);
            foreach ($recovery as $source) $rules[]=array_merge($source==='0.0.0.0/0' ? [] : ['-s',$source],['-j','ACCEPT']);
        }
    }
    foreach (sshAllowedSources($cfg) ?: ['0.0.0.0/0'] as $source) {
        $args=$source==='0.0.0.0/0' ? [] : ['-s',$source];
        if ($enforced) $args=array_merge($args,['-m','set','--match-set',TARA_SSH_SET,'dst']);
        $rules[]=array_merge($args,['-j','ACCEPT']);
    }
    if ($enforced) $rules[]=['-p','tcp','-j','REJECT','--reject-with','tcp-reset'];
    return $rules;
}
function sshRemoveOwnedJumps(string $rules, string $binary='/usr/sbin/iptables'): void {
    foreach (explode("\n",$rules) as $line) {
        if (!str_starts_with($line,'-A INPUT ') || (!str_contains($line,'tarasec-app-ssh-window') && !str_contains($line,'tarasec-app-ssh-ipv6'))) continue;
        $args=str_getcsv($line,' ','"','\\'); $args[0]='-D';
        sshRun(array_merge([$binary,'-w','5'],$args));
    }
}
function sshInstallGate(array $cfg, int $port, bool $enforced): void {
    $chain='TARASEC_APP_SSH';
    sshRun(['/usr/sbin/iptables','-w','5','-N',$chain],true);
    $sourceRules=sshGateRules($cfg,$enforced);
    $expected='-N '.$chain;
    foreach ($sourceRules as $args) $expected.="\n-A ".$chain.' '.implode(' ',$args);
    if (trim(sshRun(['/usr/sbin/iptables','-w','5','-S',$chain]))!==$expected) {
        // Guard against fall-through while replacing the chain. A dedicated
        // temporary chain keeps new connections denied throughout reconstruction.
        $guard='TARASEC_APP_SSH_GUARD';
        sshRun(['/usr/sbin/iptables','-w','5','-N',$guard],true);
        $guardRules=array_values(array_filter($sourceRules,fn($args)=>!in_array('--match-set',$args,true)));
        if (!$enforced) $guardRules=[['-m','conntrack','--ctstate','RELATED,ESTABLISHED','-j','ACCEPT'],['-p','tcp','-j','REJECT','--reject-with','tcp-reset']];
        $guardExpected='-N '.$guard;
        foreach ($guardRules as $args) $guardExpected.="\n-A ".$guard.' '.implode(' ',$args);
        if (trim(sshRun(['/usr/sbin/iptables','-w','5','-S',$guard]))!==$guardExpected) {
            sshRun(['/usr/sbin/iptables','-w','5','-F',$guard]);
            foreach ($guardRules as $args) sshRun(array_merge(['/usr/sbin/iptables','-w','5','-A',$guard],$args));
        }
        $before=sshRun(['/usr/sbin/iptables','-w','5','-S',$chain]);
        if ($enforced && !str_contains($before,'-A '.$chain.' -j '.$guard)) sshRun(['/usr/sbin/iptables','-w','5','-I',$chain,'1','-j',$guard]);
        // Delete the previous rules individually; flushing would briefly bypass the gate.
        $previous=explode("\n",trim(sshRun(['/usr/sbin/iptables','-w','5','-S',$chain])));
        foreach ($previous as $line) {
            if (!str_starts_with($line,'-A '.$chain.' ') || $line==='-A '.$chain.' -j '.$guard) continue;
            $args=str_getcsv($line,' ','"','\\'); $args[0]='-D'; sshRun(array_merge(['/usr/sbin/iptables','-w','5'],$args));
        }
        foreach ($sourceRules as $args) sshRun(array_merge(['/usr/sbin/iptables','-w','5','-A',$chain],$args));
        if ($enforced || str_contains($before,'-A '.$chain.' -j '.$guard)) sshRun(['/usr/sbin/iptables','-w','5','-D',$chain,'-j',$guard]);
    }
    $rule=['-p','tcp','-m','tcp','--dport',(string)$port];
    if (!$enforced) $rule=array_merge($rule,['-m','set','--match-set',TARA_SSH_SET,'dst']);
    $rule=array_merge($rule,['-m','comment','--comment','tarasec-app-ssh-window','-j',$chain]);
    $rules=sshRun(['/usr/sbin/iptables','-w','5','-S','INPUT']);
    $tagged=array_values(array_filter(explode("\n",trim($rules)),fn($line)=>str_starts_with($line,'-A INPUT ') && str_contains($line,'tarasec-app-ssh-window')));
    if ($tagged!==['-A INPUT '.implode(' ',$rule)]) {
        sshRemoveOwnedJumps($rules);
        $position=managerSshRulePosition(sshRun(['/usr/sbin/iptables','-w','5','-S','INPUT']),$port);
        sshRun(array_merge(['/usr/sbin/iptables','-w','5','-I','INPUT',(string)$position],$rule));
    }
}
function sshIpv6Gate(int $port): void {
    // The supported conf source list is IPv4 only. Do not leave an IPv6 bypass
    // to the same sshd listener; retain loopback and already established sessions.
    $rule=['!','-s','::1/128','-p','tcp','-m','tcp','--dport',(string)$port,'-m','conntrack','--ctstate','NEW','-m','comment','--comment','tarasec-app-ssh-ipv6','-j','REJECT','--reject-with','tcp-reset'];
    $rules=sshRun(['/usr/sbin/ip6tables','-w','5','-S','INPUT']);
    $tagged=array_values(array_filter(explode("\n",trim($rules)),fn($line)=>str_starts_with($line,'-A INPUT ') && str_contains($line,'tarasec-app-ssh-ipv6')));
    if ($tagged!==['-A INPUT '.implode(' ',$rule)]) {
        sshRemoveOwnedJumps($rules,'/usr/sbin/ip6tables');
        sshRun(array_merge(['/usr/sbin/ip6tables','-w','5','-I','INPUT','1'],$rule));
    }
}
function sshGateReady(mysqli $db, int $port): void {
    $remaining=managerSshRemaining(sshRun(['/usr/sbin/ipset','save',TARA_SSH_SET]),$port);
    $row=$db->query("SELECT r.*,CAST(m.active AS UNSIGNED) active,m.rejectedTime,m.expires FROM managerSshRequest r JOIN managerRequest m ON m.managerRequestId=r.managerRequestId WHERE r.state='applied' ORDER BY r.sequenceId DESC LIMIT 1")->fetch_assoc();
    if ($remaining<60 || !$row || $row['action']!=='open' || !managerSshAuthorized($row)
        || strtotime((string)$row['appliedAt'])<time()-900) throw new RuntimeException('Open SSH for 5 minutes in the app, wait for confirmation, then rerun this installer. At least 60 seconds must remain.');
    echo "App-authorized reopening verified; active kernel lease has $remaining seconds remaining.\n";
}
function sshConfig(string $path): int {
    $cfg=sshReadConfig($path);
    sshAllowedSources($cfg); // Never silently discard an owner source restriction.
    $port=filter_var($cfg['SSH_PORT'] ?? '22',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
    if ($port===false) throw new RuntimeException('Invalid configured SSH port');
    if (in_array(strtolower($cfg['SSH_HONEYPOT'] ?? 'off'),['on','1','yes','true'],true)) {
        foreach (preg_split('/[,\s]+/',$cfg['SSH_HONEYPOT_PORTS'] ?? ($cfg['SSH_HONEYPOT_PORT'] ?? '22')) as $range) {
            $parts=explode('-',$range);
            if ($port >= (int)$parts[0] && $port <= (int)($parts[1] ?? $parts[0])) throw new RuntimeException('SSH port overlaps honeypot');
        }
        if ($port===(int)($cfg['SSH_HONEYPOT_PORT'] ?? 22) || $port===(int)($cfg['SSH_HONEYPOT_DEMO_PORT'] ?? 0)) throw new RuntimeException('SSH port overlaps honeypot');
    }
    return $port;
}
function sshListening(int $port): bool {
    // Require the actual sshd process, not a honeypot or an unrelated listener.
    $out=sshRun(['/usr/bin/ss','-H','-lntp','sport = :'.$port]);
    return str_contains($out,'"sshd"');
}
function sshPublish(array $state): void {
    $path=TARA_SSH_STATUS; $tmp=tempnam(dirname($path),'.status-');
    if ($tmp===false) throw new RuntimeException('Cannot publish SSH state');
    try {
        if (file_put_contents($tmp,json_encode(['updated'=>time()]+$state,JSON_THROW_ON_ERROR)."\n")===false) throw new RuntimeException('Cannot write SSH state');
        chmod($tmp,0644); if (!rename($tmp,$path)) throw new RuntimeException('Cannot publish SSH state');
    } finally { if (file_exists($tmp)) unlink($tmp); }
}
function sshRequestValid(array $row): bool {
    return managerSshAuthorized($row) && managerSshSource((string)$row['sourceIp'])
        && strtotime((string)$row['createdAt']) >= time()-30
        && strtotime((string)$row['createdAt']) <= time()+5
        && (($row['action']==='open' && managerSshDuration((int)$row['seconds'])) || ($row['action']==='close' && (int)$row['seconds']===0));
}
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) {
    try {
        if (posix_geteuid()!==0) throw new RuntimeException('Root worker required');
        if (in_array('--rollback-gate',$argv,true)) {
            sshRemoveOwnedJumps(sshRun(['/usr/sbin/iptables','-w','5','-S','INPUT']));
            sshRemoveOwnedJumps(sshRun(['/usr/sbin/ip6tables','-w','5','-S','INPUT']),'/usr/sbin/ip6tables');
            if (is_file('/etc/tarasec/manager-ssh-gate.enabled')) unlink('/etc/tarasec/manager-ssh-gate.enabled');
            sshRun(['/usr/sbin/ipset','flush',TARA_SSH_SET],true);
            if (is_dir(dirname(TARA_SSH_STATUS))) sshPublish(['enabled'=>false,'gateEnforced'=>false,'remainingSeconds'=>0,'error'=>'ssh_gate_rolled_back']);
            echo "Timed gate removed; pre-existing SSH firewall policy restored.\n"; exit;
        }
        $port=sshConfig('/etc/tarasecfw.conf');
        $rules=sshRun(['/usr/sbin/iptables','-w','5','-S','INPUT']);
        $position=managerSshRulePosition($rules,$port);
        $listener=sshListening($port);
        if (in_array('--check',$argv,true)) {
            if (!$listener) throw new RuntimeException('Configured port has no sshd listener');
            sshRun(['/usr/sbin/ipset','--version']);
            sshRun(['/usr/sbin/ip6tables','-w','5','-S','INPUT']);
            echo "SSH preflight passed: configured listener and firewall policy found; no access changed.\n"; exit;
        }
        require_once __DIR__.'/../html/dbfunc.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db=getConnection();
        if (!(int)$db->query("SELECT GET_LOCK('tarasec:manager-ssh',0)")->fetch_row()[0]) exit;
        sshRun(['/usr/sbin/ipset','create',TARA_SSH_SET,'bitmap:port','range','1-65535','timeout','900','-exist']);
        $enabled=is_file('/etc/tarasec/manager-ssh.enabled') && $listener;
        if (!$enabled) {
            sshRun(['/usr/sbin/ipset','flush',TARA_SSH_SET]);
            $db->query("UPDATE managerSshRequest SET state='rejected',appliedAt=NOW() WHERE state='pending'");
            sshPublish(['enabled'=>false,'port'=>$port,'listener'=>$listener,'remainingSeconds'=>0, 'error'=>'ssh_disabled_by_owner']); exit;
        }
        $enforced=is_file('/etc/tarasec/manager-ssh-gate.enabled');
        sshInstallGate(sshReadConfig('/etc/tarasecfw.conf'),$port,$enforced);
        if ($enforced) sshIpv6Gate($port);
        $rows=$db->query("SELECT r.*,CAST(m.active AS UNSIGNED) active,m.rejectedTime,m.expires FROM managerSshRequest r LEFT JOIN managerRequest m ON m.managerRequestId=r.managerRequestId WHERE r.state='pending' ORDER BY r.sequenceId LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $state='rejected';
            if (sshRequestValid($row)) {
                $key=(string)$port;
                if ($row['action']==='open') {
                    // Authorization expiry also caps the kernel lease.
                    $seconds=(int)$row['seconds'];
                    if (!empty($row['expires'])) $seconds=min($seconds,strtotime($row['expires'])-time());
                    if ($seconds>0) { sshRun(['/usr/sbin/ipset','add',TARA_SSH_SET,$key,'timeout',(string)$seconds,'-exist']); $state='applied'; }
                } else { sshRun(['/usr/sbin/ipset','del',TARA_SSH_SET,$key,'-exist']); $state='applied'; }
            }
            $q=$db->prepare("UPDATE managerSshRequest SET state=?,appliedAt=NOW() WHERE sequenceId=? AND state='pending'");
            $q->bind_param('si',$state,$row['sequenceId']); $q->execute(); $q->close();
        }
        // Revocation cancels the most recently applied global opening.
        $rows=$db->query("SELECT r.*,CAST(m.active AS UNSIGNED) active,m.rejectedTime,m.expires FROM managerSshRequest r LEFT JOIN managerRequest m ON m.managerRequestId=r.managerRequestId WHERE r.state='applied' AND r.action='open' AND r.appliedAt>DATE_SUB(NOW(),INTERVAL 16 MINUTE) AND NOT EXISTS (SELECT 1 FROM managerSshRequest newer WHERE newer.state='applied' AND newer.sequenceId>r.sequenceId)")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) if (!managerSshAuthorized($row)) sshRun(['/usr/sbin/ipset','del',TARA_SSH_SET,(string)$port,'-exist']);
        $remaining=managerSshRemaining(sshRun(['/usr/sbin/ipset','save',TARA_SSH_SET]),$port);
        sshPublish(['enabled'=>true,'port'=>$port,'listener'=>true,'gateEnforced'=>$enforced,'remainingSeconds'=>$remaining]);
        $db->query("DELETE FROM managerSshRequest WHERE state<>'pending' AND createdAt<DATE_SUB(NOW(),INTERVAL 7 DAY)");
        $db->close();
    } catch (Throwable $e) {
        error_log('Manager SSH worker: '.$e->getMessage());
        // An invalid owner policy or failed application cancels temporary access.
        // Never leave an older source list granting access after a failed refresh.
        if (!in_array('--check',$argv,true)) {
            try { sshRun(['/usr/sbin/ipset','flush',TARA_SSH_SET]); } catch (Throwable $ignored) {}
        }
        if (!in_array('--check',$argv,true) && is_dir(dirname(TARA_SSH_STATUS))) sshPublish(['enabled'=>false,'error'=>'ssh_worker_unavailable','remainingSeconds'=>0]);
        exit(1);
    }
}
