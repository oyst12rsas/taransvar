<?php
// Isolated endpoint boundary checks: no live DB, sudo or firewall mutation.
$root = sys_get_temp_dir().'/manager-ssh-api-'.bin2hex(random_bytes(5));
mkdir($root.'/html/script', 0700, true);
$stub = <<<'STUB'
<?php
const MYSQLI_REPORT_ERROR=1;
const MYSQLI_REPORT_STRICT=2;
function mysqli_report($flags) {}
function getConnection() { return new FakeConnection(); }
class FakeConnection {
    function prepare($query) {
        foreach (["email=?", "active=b'1'", "rejectedTime IS NULL", "expires>NOW()"] as $guard) {
            if (!str_contains($query, $guard)) throw new RuntimeException('Missing permission guard');
        }
        return new FakeStatement();
    }
    function close() {}
}
class FakeStatement {
    function bind_param($types, &$id, &$email) {}
    function execute() {}
    function get_result() { return $this; }
    function fetch_row() { return ($GLOBALS['fixture']['active'] ?? true) ? [1] : null; }
    function close() {}
}
STUB;
file_put_contents($root.'/html/dbfunc.php', $stub);
file_put_contents($root.'/helper.php', '<?php file_put_contents(__DIR__."/helper-called", implode(" ", array_slice($argv,1))); echo json_encode(["ok"=>true,"ssh"=>["state"=>"closed","canOpen"=>true]]);');
$source = file_get_contents(__DIR__.'/../html/script/managerSsh.php');
$source = str_replace("['/usr/bin/sudo','-n','/usr/local/lib/tarasec/manager_ssh.py',\$action,\$source]", "[PHP_BINARY, ".var_export($root.'/helper.php', true).", \$action, \$source]", $source);
file_put_contents($root.'/html/script/managerSsh.php', $source);
$wrapper = <<<'WRAPPER'
<?php
$fixture = json_decode($argv[1], true);
$_SERVER['REQUEST_METHOD'] = $fixture['method'] ?? 'GET';
$_SERVER['REMOTE_ADDR'] = $fixture['source'] ?? '100.68.1.2';
$_SERVER['HTTP_X_TARASEC_CSRF'] = $fixture['csrf'] ?? '';
$_GET = ['action'=>$fixture['action'] ?? 'status'];
$_POST = ['minutes'=>$fixture['minutes'] ?? null, 'source'=>'100.68.9.9'];
session_save_path(__DIR__);
session_id('fixture'.bin2hex(random_bytes(8)));
session_start();
if ($fixture['authenticated'] ?? true) $_SESSION = ['tarasec_manager_authenticated'=>true,'tarasec_manager_request_id'=>8,'tarasec_manager_email'=>'owner@example.org','manager_ssh_csrf'=>'test-csrf'];
session_write_close();
include __DIR__.'/html/script/managerSsh.php';
WRAPPER;
file_put_contents($root.'/run.php', $wrapper);
function callEndpoint(array $fixture): array {
    global $root;
    @unlink($root.'/helper-called');
    $p = proc_open([PHP_BINARY,'-n',$root.'/run.php',json_encode($fixture)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $result=json_decode($output,true);
    if (!is_array($result)) throw new RuntimeException('Invalid endpoint result: '.$output.' '.$error);
    return $result;
}
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    foreach ([
        [['authenticated'=>false], 'manager_login_required'],
        [['active'=>false], 'manager_access_revoked'],
        [['action'=>'open'], 'post_required'],
        [['action'=>'open','method'=>'POST'], 'invalid_csrf'],
        [['action'=>'open','method'=>'POST','csrf'=>'wrong'], 'invalid_csrf'],
        [['action'=>'open','method'=>'POST','csrf'=>'test-csrf','minutes'=>30], 'invalid_duration'],
        [['source'=>'::1'], 'ipv4_required'],
        [['action'=>'close'], 'invalid_action'],
    ] as [$fixture,$expected]) {
        $r=callEndpoint($fixture);
        check(($r['error']??'')===$expected, $expected);
        check(!file_exists($root.'/helper-called'), 'Denied requests must never execute helper');
    }
    $r=callEndpoint([]);
    check($r['ok'] && $r['csrfToken']==='test-csrf', 'Status returns session CSRF');
    check(file_get_contents($root.'/helper-called')==='status 100.68.1.2', 'Only observed source is used');
    foreach ([5,10,15] as $minutes) {
        $r=callEndpoint(['action'=>'open','method'=>'POST','csrf'=>'test-csrf','minutes'=>$minutes]);
        check($r['ok'], 'Supported opening duration');
        check(file_get_contents($root.'/helper-called')==='open 100.68.1.2 '.$minutes, 'Validated argv only');
    }
    echo "Manager SSH API authorization, CSRF and duration checks passed\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
