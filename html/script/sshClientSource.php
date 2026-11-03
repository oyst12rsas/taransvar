<?php
declare(strict_types=1);
function queueConnectingSource(string $source): string {
    $ip=@inet_pton($source);
    if (!is_string($ip)) throw new RuntimeException('ssh_client_unavailable');
    if (strlen($ip)===16 && substr($ip,0,12)===str_repeat("\0",10)."\xff\xff") $ip=substr($ip,12);
    if (strlen($ip)===4) {
        $n=unpack('N',$ip)[1];
        if (($n&0xff000000)===0x0a000000 || ($n&0xfff00000)===0xac100000
            || ($n&0xffff0000)===0xc0a80000 || ($n&0xffc00000)===0x64400000) return inet_ntop($ip);
    } elseif (strlen($ip)===16 && (ord($ip[0])&0xfe)===0xfc) return inet_ntop($ip);
    throw new RuntimeException('ssh_client_unavailable');
}
