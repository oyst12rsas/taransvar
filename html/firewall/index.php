<?php
// Firewall UI - SERVICE-ORIENTED CONFIG BUILDER

/*What’s in it now:

Allow SSH
Allow DHCP
Enable forwarding
Internal IP address
NAT + IP range
hostapd toggle + SSID + interface
IPsec section with peer/local/remote IDs, PSK, and remote networks
generated companion setup file for connected units
honeypot port-forwarding
other server forwarding rules
remote syslog incoming/outgoing
rollback timer
admin source
DNS servers
notes

A few things I think you should also include:

WAN interface and LAN interface selection
DHCP range, lease time, and gateway
DNS forwarding or local resolver choice
allowed management services besides SSH, like HTTPS or WireGuard
IPsec encryption profile selection
per-peer multiple tunnels
syslog protocol and transport, UDP/TCP/TLS
log levels and what to log: accepted, dropped, forwarded, NATed
captive portal enable/disable
VLAN support
static routes
default policies for input/forward/output
per-service source restrictions
fail-safe “keep current SSH session alive” confirmation
schedule or timer-based rules
IDS/honeypot event forwarding to SOC
rate limiting and brute-force protection
MAC allow/block lists for Wi-Fi
backup/restore/export/import of config
validation warnings before saving

The biggest missing pieces for your setup are probably:

interface selection
DHCP settings
static routes
logging detail controls
VLANs
management access restrictions
VPN profile details
*/


$configFile = "/etc/taransvar-firewall.conf";
$backupFile = "/etc/taransvar-firewall.conf.bak";
$message = "";

function bool_post($key) {
    return isset($_POST[$key]) ? 1 : 0;
}

function parse_ini_like_config($text) {
    $cfg = [
        'internal_ip' => '192.168.50.1',
        'nat_enabled' => 0,
        'nat_range' => '192.168.50.0/24',
        'allow_ssh' => 1,
        'allow_dhcp' => 1,
        'forward_enabled' => 1,
        'hostapd_enabled' => 0,
        'hostapd_ssid' => 'TaransvarWiFi',
        'hostapd_interface' => 'wlan0',
        'remote_syslog_in' => 0,
        'remote_syslog_out' => 0,
        'remote_syslog_server' => '10.10.10.10',
        'remote_syslog_port' => '514',
        'vpn_enabled' => 0,
        'vpn_remote_nets' => "10.47.1.0/24",
        'vpn_peer_name' => 'partner1',
        'vpn_local_id' => '81.88.19.252',
        'vpn_remote_id' => 'partner1',
        'vpn_psk' => 'change-me',
        'honeypot_forward_enabled' => 0,
        'honeypot_wan_port' => '2222',
        'honeypot_lan_ip' => '10.10.10.11',
        'honeypot_lan_port' => '22',
        'server_forward_enabled' => 0,
        'server_forward_rules' => "tcp:8080:10.10.10.2:80",
        'dns_enabled' => 1,
        'dns_servers' => '1.1.1.1,8.8.8.8',
        'rollback_enabled' => 1,
        'rollback_seconds' => '90',
        'admin_source' => '0.0.0.0/0',
        'notes' => ''
    ];

    $lines = explode("\n", $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (array_key_exists($k, $cfg)) {
                $cfg[$k] = $v;
            }
        }
    }
    return $cfg;
}

function generate_companion_setup($cfg) {
    $lines = [];
    $lines[] = '# Connected unit setup';
    $lines[] = 'INTERNAL_IP=' . $cfg['internal_ip'];
    $lines[] = 'DEFAULT_GATEWAY=' . $cfg['internal_ip'];
    $lines[] = 'DNS_SERVERS=' . $cfg['dns_servers'];
    if ((int)$cfg['vpn_enabled']) {
        $lines[] = 'IPSEC_PEER=' . $cfg['vpn_peer_name'];
        $lines[] = 'IPSEC_LOCAL_ID=' . $cfg['vpn_local_id'];
        $lines[] = 'IPSEC_REMOTE_ID=' . $cfg['vpn_remote_id'];
        $lines[] = 'IPSEC_REMOTE_NETS=' . str_replace("\r", '', $cfg['vpn_remote_nets']);
    }
    if ((int)$cfg['remote_syslog_out']) {
        $lines[] = 'REMOTE_SYSLOG=' . $cfg['remote_syslog_server'] . ':' . $cfg['remote_syslog_port'];
    }
    return implode("\n", $lines);
}

function build_config($cfg) {
    $out = [];
    $out[] = '# Taransvar Firewall Config';
    $out[] = 'internal_ip=' . $cfg['internal_ip'];
    $out[] = 'admin_source=' . $cfg['admin_source'];
    $out[] = 'allow_ssh=' . $cfg['allow_ssh'];
    $out[] = 'allow_dhcp=' . $cfg['allow_dhcp'];
    $out[] = 'forward_enabled=' . $cfg['forward_enabled'];
    $out[] = 'nat_enabled=' . $cfg['nat_enabled'];
    $out[] = 'nat_range=' . $cfg['nat_range'];
    $out[] = 'dns_enabled=' . $cfg['dns_enabled'];
    $out[] = 'dns_servers=' . $cfg['dns_servers'];
    $out[] = 'hostapd_enabled=' . $cfg['hostapd_enabled'];
    $out[] = 'hostapd_ssid=' . $cfg['hostapd_ssid'];
    $out[] = 'hostapd_interface=' . $cfg['hostapd_interface'];
    $out[] = 'vpn_enabled=' . $cfg['vpn_enabled'];
    $out[] = 'vpn_peer_name=' . $cfg['vpn_peer_name'];
    $out[] = 'vpn_local_id=' . $cfg['vpn_local_id'];
    $out[] = 'vpn_remote_id=' . $cfg['vpn_remote_id'];
    $out[] = 'vpn_psk=' . $cfg['vpn_psk'];
    $out[] = 'vpn_remote_nets=' . str_replace("\n", ',', str_replace("\r", '', $cfg['vpn_remote_nets']));
    $out[] = 'honeypot_forward_enabled=' . $cfg['honeypot_forward_enabled'];
    $out[] = 'honeypot_wan_port=' . $cfg['honeypot_wan_port'];
    $out[] = 'honeypot_lan_ip=' . $cfg['honeypot_lan_ip'];
    $out[] = 'honeypot_lan_port=' . $cfg['honeypot_lan_port'];
    $out[] = 'server_forward_enabled=' . $cfg['server_forward_enabled'];
    $out[] = 'server_forward_rules=' . str_replace("\n", ';', str_replace("\r", '', $cfg['server_forward_rules']));
    $out[] = 'remote_syslog_in=' . $cfg['remote_syslog_in'];
    $out[] = 'remote_syslog_out=' . $cfg['remote_syslog_out'];
    $out[] = 'remote_syslog_server=' . $cfg['remote_syslog_server'];
    $out[] = 'remote_syslog_port=' . $cfg['remote_syslog_port'];
    $out[] = 'rollback_enabled=' . $cfg['rollback_enabled'];
    $out[] = 'rollback_seconds=' . $cfg['rollback_seconds'];
    $out[] = 'notes=' . str_replace("\n", ' | ', str_replace("\r", '', $cfg['notes']));
    return implode("\n", $out);
}

$currentConfig = file_exists($configFile) ? file_get_contents($configFile) : '';
$cfg = parse_ini_like_config($currentConfig);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = [
        'internal_ip' => $_POST['internal_ip'] ?? '192.168.50.1',
        'nat_enabled' => bool_post('nat_enabled'),
        'nat_range' => $_POST['nat_range'] ?? '192.168.50.0/24',
        'allow_ssh' => bool_post('allow_ssh'),
        'allow_dhcp' => bool_post('allow_dhcp'),
        'forward_enabled' => bool_post('forward_enabled'),
        'hostapd_enabled' => bool_post('hostapd_enabled'),
        'hostapd_ssid' => $_POST['hostapd_ssid'] ?? 'TaransvarWiFi',
        'hostapd_interface' => $_POST['hostapd_interface'] ?? 'wlan0',
        'remote_syslog_in' => bool_post('remote_syslog_in'),
        'remote_syslog_out' => bool_post('remote_syslog_out'),
        'remote_syslog_server' => $_POST['remote_syslog_server'] ?? '10.10.10.10',
        'remote_syslog_port' => $_POST['remote_syslog_port'] ?? '514',
        'vpn_enabled' => bool_post('vpn_enabled'),
        'vpn_remote_nets' => $_POST['vpn_remote_nets'] ?? '10.47.1.0/24',
        'vpn_peer_name' => $_POST['vpn_peer_name'] ?? 'partner1',
        'vpn_local_id' => $_POST['vpn_local_id'] ?? '81.88.19.252',
        'vpn_remote_id' => $_POST['vpn_remote_id'] ?? 'partner1',
        'vpn_psk' => $_POST['vpn_psk'] ?? 'change-me',
        'honeypot_forward_enabled' => bool_post('honeypot_forward_enabled'),
        'honeypot_wan_port' => $_POST['honeypot_wan_port'] ?? '2222',
        'honeypot_lan_ip' => $_POST['honeypot_lan_ip'] ?? '10.10.10.11',
        'honeypot_lan_port' => $_POST['honeypot_lan_port'] ?? '22',
        'server_forward_enabled' => bool_post('server_forward_enabled'),
        'server_forward_rules' => $_POST['server_forward_rules'] ?? 'tcp:8080:10.10.10.2:80',
        'dns_enabled' => bool_post('dns_enabled'),
        'dns_servers' => $_POST['dns_servers'] ?? '1.1.1.1,8.8.8.8',
        'rollback_enabled' => bool_post('rollback_enabled'),
        'rollback_seconds' => $_POST['rollback_seconds'] ?? '90',
        'admin_source' => $_POST['admin_source'] ?? '0.0.0.0/0',
        'notes' => $_POST['notes'] ?? ''
    ];

    $newConfig = build_config($cfg);

    if (isset($_POST['save'])) {
        file_put_contents($configFile, $newConfig);
        $message = 'Config file written.';
    }

    if (isset($_POST['apply'])) {
        if (file_exists($configFile)) {
            copy($configFile, $backupFile);
        }
        file_put_contents($configFile, $newConfig);
        $message = 'Config file written. Apply hook placeholder only — compiler/backend should consume this config.';
    }

    if (isset($_POST['rollback']) && file_exists($backupFile)) {
        copy($backupFile, $configFile);
        $cfg = parse_ini_like_config(file_get_contents($configFile));
        $message = 'Rollback done.';
    }
}

$companionSetup = generate_companion_setup($cfg);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Firewall Config Builder</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f8; padding:20px; color:#111827; }
        .wrap { max-width: 1280px; margin: 0 auto; }
        .card { background:white; padding:20px; margin-bottom:15px; border-radius:10px; box-shadow: 0 1px 6px rgba(0,0,0,0.06); }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap:15px; }
        .row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
        label { font-size:14px; font-weight:600; display:block; margin-bottom:5px; }
        input[type="text"], input[type="password"], textarea { width:100%; padding:9px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box; }
        textarea { min-height: 90px; font-family: monospace; }
        .check { display:flex; align-items:center; gap:8px; margin:6px 18px 6px 0; }
        button { padding:10px 15px; border:none; border-radius:6px; cursor:pointer; }
        .apply { background:#2563eb; color:white; }
        .save { background:#6b7280; color:white; }
        .rollback { background:#dc2626; color:white; }
        h2, h3 { margin-top:0; }
        pre { background:#0f172a; color:#e5e7eb; padding:14px; border-radius:8px; overflow:auto; }
        .full { grid-column: 1 / -1; }
        .hint { color:#6b7280; font-size:13px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <h2>Firewall Config Builder</h2>
        <p>This writes a structured config file for your own compiler/backend — not raw iptables or nft syntax.</p>
    </div>

    <?php if ($message): ?>
    <div class="card"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="grid">
            <div class="card">
                <h3>Basic network</h3>
                <label>Internal IP address</label>
                <input type="text" name="internal_ip" value="<?php echo htmlspecialchars($cfg['internal_ip']); ?>">
                <label style="margin-top:12px;">Admin source</label>
                <input type="text" name="admin_source" value="<?php echo htmlspecialchars($cfg['admin_source']); ?>">
                <div class="row" style="margin-top:12px;">
                    <label class="check"><input type="checkbox" name="allow_ssh" <?php if ($cfg['allow_ssh']) echo 'checked'; ?>> Allow SSH</label>
                    <label class="check"><input type="checkbox" name="allow_dhcp" <?php if ($cfg['allow_dhcp']) echo 'checked'; ?>> Allow DHCP</label>
                    <label class="check"><input type="checkbox" name="forward_enabled" <?php if ($cfg['forward_enabled']) echo 'checked'; ?>> Enable forwarding</label>
                    <label class="check"><input type="checkbox" name="dns_enabled" <?php if ($cfg['dns_enabled']) echo 'checked'; ?>> DNS enabled</label>
                </div>
                <label>DNS servers</label>
                <input type="text" name="dns_servers" value="<?php echo htmlspecialchars($cfg['dns_servers']); ?>">
            </div>

            <div class="card">
                <h3>NAT</h3>
                <label class="check"><input type="checkbox" name="nat_enabled" <?php if ($cfg['nat_enabled']) echo 'checked'; ?>> Enable NAT</label>
                <label>IP range / subnet</label>
                <input type="text" name="nat_range" value="<?php echo htmlspecialchars($cfg['nat_range']); ?>">
                <p class="hint">Example: 192.168.50.0/24</p>

                <h3 style="margin-top:22px;">hostapd</h3>
                <label class="check"><input type="checkbox" name="hostapd_enabled" <?php if ($cfg['hostapd_enabled']) echo 'checked'; ?>> Enable hostapd</label>
                <label>SSID</label>
                <input type="text" name="hostapd_ssid" value="<?php echo htmlspecialchars($cfg['hostapd_ssid']); ?>">
                <label style="margin-top:12px;">Wi‑Fi interface</label>
                <input type="text" name="hostapd_interface" value="<?php echo htmlspecialchars($cfg['hostapd_interface']); ?>">
            </div>

            <div class="card">
                <h3>IPsec / VPN</h3>
                <label class="check"><input type="checkbox" name="vpn_enabled" <?php if ($cfg['vpn_enabled']) echo 'checked'; ?>> Enable IPsec setup</label>
                <label>Peer name</label>
                <input type="text" name="vpn_peer_name" value="<?php echo htmlspecialchars($cfg['vpn_peer_name']); ?>">
                <label style="margin-top:12px;">Local ID</label>
                <input type="text" name="vpn_local_id" value="<?php echo htmlspecialchars($cfg['vpn_local_id']); ?>">
                <label style="margin-top:12px;">Remote ID</label>
                <input type="text" name="vpn_remote_id" value="<?php echo htmlspecialchars($cfg['vpn_remote_id']); ?>">
                <label style="margin-top:12px;">PSK</label>
                <input type="password" name="vpn_psk" value="<?php echo htmlspecialchars($cfg['vpn_psk']); ?>">
                <label style="margin-top:12px;">Remote networks (one per line)</label>
                <textarea name="vpn_remote_nets"><?php echo htmlspecialchars($cfg['vpn_remote_nets']); ?></textarea>
            </div>

            <div class="card">
                <h3>Port forwarding</h3>
                <label class="check"><input type="checkbox" name="honeypot_forward_enabled" <?php if ($cfg['honeypot_forward_enabled']) echo 'checked'; ?>> Honeypot port forwarding</label>
                <label>WAN port → honeypot LAN IP:port</label>
                <div class="row">
                    <input type="text" name="honeypot_wan_port" value="<?php echo htmlspecialchars($cfg['honeypot_wan_port']); ?>" placeholder="WAN port">
                    <input type="text" name="honeypot_lan_ip" value="<?php echo htmlspecialchars($cfg['honeypot_lan_ip']); ?>" placeholder="LAN IP">
                    <input type="text" name="honeypot_lan_port" value="<?php echo htmlspecialchars($cfg['honeypot_lan_port']); ?>" placeholder="LAN port">
                </div>
                <label class="check" style="margin-top:12px;"><input type="checkbox" name="server_forward_enabled" <?php if ($cfg['server_forward_enabled']) echo 'checked'; ?>> Other server forwards</label>
                <label>Other server forward rules</label>
                <textarea name="server_forward_rules"><?php echo htmlspecialchars($cfg['server_forward_rules']); ?></textarea>
                <p class="hint">Format suggestion: tcp:8080:10.10.10.2:80 — one per line</p>
            </div>

            <div class="card">
                <h3>Remote syslog</h3>
                <div class="row">
                    <label class="check"><input type="checkbox" name="remote_syslog_in" <?php if ($cfg['remote_syslog_in']) echo 'checked'; ?>> Incoming logging</label>
                    <label class="check"><input type="checkbox" name="remote_syslog_out" <?php if ($cfg['remote_syslog_out']) echo 'checked'; ?>> Outgoing logging</label>
                </div>
                <label>Remote syslog server</label>
                <input type="text" name="remote_syslog_server" value="<?php echo htmlspecialchars($cfg['remote_syslog_server']); ?>">
                <label style="margin-top:12px;">Remote syslog port</label>
                <input type="text" name="remote_syslog_port" value="<?php echo htmlspecialchars($cfg['remote_syslog_port']); ?>">
            </div>

            <div class="card">
                <h3>Safety and extras</h3>
                <label class="check"><input type="checkbox" name="rollback_enabled" <?php if ($cfg['rollback_enabled']) echo 'checked'; ?>> Enable rollback timer</label>
                <label>Rollback seconds</label>
                <input type="text" name="rollback_seconds" value="<?php echo htmlspecialchars($cfg['rollback_seconds']); ?>">
                <label style="margin-top:12px;">Notes</label>
                <textarea name="notes"><?php echo htmlspecialchars($cfg['notes']); ?></textarea>
            </div>

            <div class="card full">
                <button class="save" name="save">Save Config File</button>
                <button class="apply" name="apply">Save / Apply Placeholder</button>
                <button class="rollback" name="rollback">Rollback</button>
            </div>
        </div>
    </form>

    <div class="grid">
        <div class="card">
            <h3>Generated Config Preview</h3>
            <pre><?php echo htmlspecialchars(build_config($cfg)); ?></pre>
        </div>
        <div class="card">
            <h3>Generated Setup File for Connected Units</h3>
            <pre><?php echo htmlspecialchars($companionSetup); ?></pre>
        </div>
    </div>

</div>
</body>
</html>
