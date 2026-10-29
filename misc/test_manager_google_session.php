<?php
declare(strict_types=1);
// Production session guard and endpoints, with disposable sessions and a fake DB.
$root=sys_get_temp_dir().'/manager-google-'.bin2hex(random_bytes(6));
mkdir($root.'/html/script',0700,true);mkdir($root.'/sessions',0700);
function freshExpect(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
try {
    $files=['managerGoogleSession','managerSshApprovalTarget','managerAuth','managerGateway','managerAi','managerThreat','managerOverview','appAssistance','managerSsh','managerSshCommon'];
    foreach($files as $file) {
        $source=file_get_contents(__DIR__.'/../html/script/'.$file.'.php');
        $source=str_replace('/var/lib/tarasec-ssh-approval/target.json',$root.'/target.json',$source);
        file_put_contents($root.'/html/script/'.$file.'.php',$source);
    }
    copy(__DIR__.'/../html/managerRequestState.php',$root.'/html/managerRequestState.php');
    $ref=str_repeat('a',64);
    file_put_contents($root.'/target.json',json_encode(['version'=>1,'server_ref'=>$ref,'approval_url'=>'https://example.test/ops/agent/']));
    file_put_contents($root.'/html/dbfunc.php', <<<'DB'
<?php
class FakeRows {public function fetch_assoc(){return [
    'managerRequestId'=>7,'email'=>'owner@example.com','active'=>1,'rejectedTime'=>null,
    'expires'=>null,'emailVerifiedTime'=>'2026-01-01','gatewayApprovedTime'=>'2026-01-01',
    'credentialHash'=>'synthetic','requestExpired'=>0];}}
class FakeQuery {
    public function bind_param($types,&...$args){}
    public function execute(){}
    public function get_result(){return new FakeRows();}
    public function close(){}
}
class FakeDb {
    public function prepare($sql){
        if(!str_contains($sql,'credentialHash=?'))throw new RuntimeException('Sensitive query reached before fresh Google');
        return new FakeQuery();
    }
    public function close(){}
}
function getConnection(){return new FakeDb();}
DB
    );
    require $root.'/html/script/managerGoogleSession.php';
    $valid=['tarasec_manager_authenticated'=>1,'tarasec_manager_email'=>'owner@example.com',
        'manager_google_email'=>'owner@example.com','manager_google_origin'=>'https://example.test/ops/agent/','manager_google_ref'=>$ref,'manager_google_until'=>time()+900];
    $_SESSION=$valid;freshExpect(managerGoogleSessionActive(),'Fresh exact-node session accepted');
    foreach([
        ['manager_google_until'=>time()-1],['manager_google_ref'=>str_repeat('b',64)],
        ['manager_google_origin'=>'https://different.example/ops/agent/'],['manager_google_email'=>'other@example.com'],['tarasec_manager_authenticated'=>0]
    ] as $change) {$_SESSION=array_replace($valid,$change);freshExpect(!managerGoogleSessionActive(),'Expired, wrong node or wrong account rejected');}
    $_SESSION=['tarasec_manager_authenticated'=>1,'tarasec_manager_email'=>'owner@example.com'];
    freshExpect(!managerGoogleSessionActive(),'Legacy cookie alone is insufficient');
    $call=static function(string $endpoint,array $session,array $post=[])use($root):array {
        $runner='<?php session_save_path('.var_export($root.'/sessions',true).');session_id("freshfixture");session_start();$_SESSION='
            .var_export($session,true).';session_write_close();$_SERVER["REQUEST_METHOD"]='.var_export($post?'POST':'GET',true)
            .';$_POST='.var_export($post,true).';$_REQUEST=$_POST;chdir('.var_export($root.'/html/script',true).');'
            .'register_shutdown_function(function(){echo "\\nSTATUS=".http_response_code();});require '.var_export($root.'/html/script/'.$endpoint.'.php',true).';';
        file_put_contents($root.'/runner.php',$runner);
        $pipes=[];$p=proc_open([PHP_BINARY,$root.'/runner.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
        [$body,$status]=array_pad(explode("\nSTATUS=",$raw),2,'');
        $value=json_decode($body,true);freshExpect(is_array($value),'Valid JSON: '.$endpoint.' '.$err.' '.$raw);
        return [(int)$status,$value];
    };
    foreach(['managerGateway','managerAi','managerThreat','managerOverview','appAssistance','managerSsh'] as $endpoint) {
        foreach([['tarasec_manager_authenticated'=>1],array_replace($valid,['manager_google_until'=>time()-1]),array_replace($valid,['manager_google_ref'=>str_repeat('b',64)])] as $session) {
            [$status,$value]=$call($endpoint,$session);
            freshExpect($status===401 && ($value['ok']??true)===false,'Sensitive API rejects stale/legacy/wrong-node cookie: '.$endpoint);
        }
    }
    [$status,$value]=$call('managerAuth',['tarasec_manager_authenticated'=>1]);
    freshExpect($status===200 && $value['authenticated']===false && $value['manager']===null,'Session endpoint hides identity before fresh verification');
    [$status,$value]=$call('managerAuth',[],['action'=>'login','key'=>'synthetic-credential']);
    freshExpect($status===403 && $value['error']==='manager_google_required'
        && preg_match('/^[a-f0-9]{64}$/D',$value['binding'])===1
        && $value['approvalTarget']['server_ref']===$ref && !isset($value['manager']),'Saved approved credential creates challenge, never management access');
    echo "Fresh management guard, exact node/account, expiry, six sensitive APIs and credential-only denial passed\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){
        if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());
    }
    rmdir($root);
}
