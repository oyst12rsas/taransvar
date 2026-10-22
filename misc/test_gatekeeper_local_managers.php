<?php
declare(strict_types=1);
require __DIR__.'/../html/gatekeeper/google_local_managers.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$dir=sys_get_temp_dir().'/gk-managers-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$path=$dir.'/managers.json';
try {
    file_put_contents($path,json_encode([hash('sha256','owner@example.com')]));chmod($path,0640);
    check(gatekeeperLocalManager(' OWNER@example.com ',$path),'normalized local manager');
    check(!gatekeeperLocalManager('other@example.com',$path),'unlisted Google user denied');
    file_put_contents($path,'[]');check(!gatekeeperLocalManager('owner@example.com',$path),'empty list revokes');
    file_put_contents($path,'broken');check(!gatekeeperLocalManager('owner@example.com',$path),'invalid JSON denies');
    file_put_contents($path,json_encode([hash('sha256','owner@example.com')]));chmod($path,0666);clearstatcache();
    check(!gatekeeperLocalManager('owner@example.com',$path),'writable policy denied');
    chmod($path,0640);symlink($path,$dir.'/link');
    check(!gatekeeperLocalManager('owner@example.com',$dir.'/link'),'symlink denied');
    check(!gatekeeperLocalManager('owner@example.com',$dir.'/missing'),'missing policy denied');
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
echo "Gatekeeper local manager checks passed\n";
