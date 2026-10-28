<?php
// Exercise the actual SSH API in subprocesses with a fake database and status file.
// No firewall, listener, real credential or production database is touched.
$root = sys_get_temp_dir() . '/tarasec-ssh-api-' . bin2hex(random_bytes(8));
mkdir($root . '/html/script', 0700, true);
mkdir($root . '/sessions', 0700);
try {
    copy(__DIR__ . '/../html/script/managerSsh.php', $root . '/html/script/managerSsh.php');
    copy(__DIR__ . '/../html/script/managerSshApprovalTarget.php', $root . '/html/script/managerSshApprovalTarget.php');
    $common = file_get_contents(__DIR__ . '/../html/script/managerSshCommon.php');
    $common = str_replace("'/run/tarasec-app-ssh/status.json'", var_export($root . '/status.json', true), $common);
    file_put_contents($root . '/html/script/managerSshCommon.php', $common);
    file_put_contents($root . '/status.json', json_encode([
        'updated'=>time(),'enabled'=>true,'port'=>5822,'listener'=>true,'remainingSeconds'=>120
    ]));
    file_put_contents($root . '/html/dbfunc.php', <<<'STUB'
<?php
class FakeResult {
    public function __construct(private mixed $row) {}
    public function fetch_assoc() { return $this->row; }
}
class FakeStatement {
    public function __construct(private mixed $row) {}
    public function bind_param($types, &...$values) {}
    public function execute() {}
    public function get_result() { return new FakeResult($this->row); }
    public function close() {}
}
class FakeDb {
    public function prepare($sql) {
        if (str_starts_with($sql, 'SELECT CAST(active')) {
            return new FakeStatement(['active'=>1,'rejectedTime'=>null,'expires'=>null]);
        }
        if (str_starts_with($sql, 'SELECT action,state')) return new FakeStatement(null);
        // In particular, no opening may reach the queue insertion.
        throw new RuntimeException('Unexpected database operation');
    }
    public function close() {}
}
function getConnection() { return new FakeDb(); }
STUB
    );
    $cases = [
        ['GET', false, [], 401, 'manager_login_required'],
        ['GET', true, [], 200, null],
        ['POST', true, ['action'=>'open','seconds'=>300,'csrf'=>'wrong'], 403, 'csrf_required'],
        ['POST', true, ['action'=>'open','seconds'=>300,'csrf'=>'test-csrf'], 403, 'ssh_mfa_required'],
    ];
    foreach ($cases as $index => [$method, $authenticated, $post, $code, $error]) {
        $runner = "<?php\n"
            . 'session_save_path(' . var_export($root . '/sessions', true) . ');'
            . 'session_id(' . var_export('testcase' . $index, true) . ');session_start();'
            . '$_SESSION=' . var_export($authenticated ? [
                'tarasec_manager_authenticated'=>1,'tarasec_manager_request_id'=>1,
                'tarasec_manager_email'=>'test@example.invalid','manager_ssh_csrf'=>'test-csrf'
            ] : [], true) . ';session_write_close();'
            . '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
            . '$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
            . '$_POST=' . var_export($post, true) . ';'
            . 'register_shutdown_function(function(){echo "\\nHTTP_STATUS=".http_response_code();});'
            . 'require ' . var_export($root . '/html/script/managerSsh.php', true) . ';';
        file_put_contents($root . '/runner.php', $runner);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, $root . '/runner.php'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot launch API test');
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        proc_close($proc);
        [$body, $actualCode] = array_pad(explode("\nHTTP_STATUS=", $out, 2), 2, '');
        $json = json_decode($body, true);
        if ((int)$actualCode !== $code || !is_array($json) || ($json['error'] ?? null) !== $error) {
            throw new RuntimeException('API case failed: ' . $index . ' ' . $err . ' ' . $out);
        }
        if ($code===200 && (($json['approvalTarget'] ?? null)!==null || ($json['approvalUrl'] ?? null)!==null)) {
            throw new RuntimeException('Missing identity must not create a generic approval URL');
        }
        if (str_contains($body, '5822') || array_key_exists('port', $json)) {
            throw new RuntimeException('SSH port leaked from API');
        }
    }
    echo "SSH API rejects manager-only opening before queue writes and omits ports\n";
} finally {
    $paths = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($paths as $path) {
        if ($path->isDir()) rmdir($path->getPathname()); else unlink($path->getPathname());
    }
    rmdir($root);
}
