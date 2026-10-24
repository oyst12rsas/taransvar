<?php
require_once __DIR__.'/../../script/upgradeRolloutLib.php';
function upgradeRollout() {
    if (!isAdmin()) { print '<p>Administrator login required.</p>';return; }
    $db=getConnection();$row=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($row['central'])) {print '<p>Manage rollout on the central DB server.</p>';return;}
    $dir=upgradeRolloutFiles();
    if (!is_dir($dir)) {print '<p>Deploy the Gatekeeper upgrade on the central DB server first.</p>';return;}
    $_SESSION['rollout_csrf']??=bin2hex(random_bytes(32));
    $esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
    if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
        if (!hash_equals($_SESSION['rollout_csrf'],(string)($_POST['csrf']??''))) {print '<p>Invalid request token.</p>';return;}
        $lock=fopen($dir.'/lock','c');flock($lock,LOCK_EX);
        try {
            $p=json_decode(file_get_contents($dir.'/policy.json'),true,512,JSON_THROW_ON_ERROR);
            $action=$_POST['action']??'';
            if ($action==='pause') $p['paused']=true;
            elseif (in_array($action,['resume','reset'],true)) {
                $tests=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)($_POST['canaries']??''))))));
                if (!$tests||count($tests)>16) throw new RuntimeException('Select between one and sixteen test units.');
                foreach($tests as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('Use IPv4 addresses for test units.');
                if ($tests!==($p['canaries']??[])||$action==='reset') $p['generation']=(int)($p['generation']??0)+1;
                $p['canaries']=$tests;$p['paused']=false;
            } else throw new RuntimeException('Unknown action');
            $tmp=tempnam($dir,'policy-');file_put_contents($tmp,json_encode($p,JSON_THROW_ON_ERROR));chmod($tmp,0660);rename($tmp,$dir.'/policy.json');
        } catch(Throwable $e) {print '<p>'.$esc($e->getMessage()).'</p>';}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    $p=json_decode(file_get_contents($dir.'/policy.json'),true)??[];
    $s=is_file($dir.'/state.json')?json_decode(file_get_contents($dir.'/state.json'),true):[];
    print '<h1>Daily upgrade rollout</h1><p>Daily upgrade window: 03:00–03:10 Europe/Oslo. Test units receive the candidate first. Fleet units wait for 24 hours of fresh, healthy test reports. Tasks marked being_tested run only on designated test units. Tasks requiring manual installation or local testing are skipped and flagged red.</p>';
    print '<p>Status: '.(!empty($p['paused'])?'Paused':(!empty($s['failed'])?'Held after test failure':'Running')).'</p>';
    print '<p>Candidate: '.$esc($s['candidate_revision']??'none').'<br>Fleet release: '.$esc($s['stable_revision']??'not yet promoted').'</p>';
    print '<form method="post"><input type="hidden" name="csrf" value="'.$esc($_SESSION['rollout_csrf']).'"><label>Test-unit IPv4 addresses, separated by commas <input name="canaries" value="'.$esc(implode(',',$p['canaries']??[])).'" size="60"></label><p><button name="action" value="resume">Save and resume</button> <button name="action" value="pause">Pause all upgrades</button> <button name="action" value="reset">Discard failed candidate and test latest</button></p></form>';
    print '<p>Roles are retained on each unit. Pause prevents subsequent dispatch; it does not undo an upgrade already running. Missing reports, pending setup or failed checks cannot promote a release.</p>';
}
