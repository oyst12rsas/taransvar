<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = '/etc/tarasecfw.conf';
$gatewayName = gethostname() ?: 'TaraSec gateway';
$senderIp = getSenderIp();
$values = [];

if (is_readable($configFile)) {
    foreach (file($configFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*(DEMO_NODE|DEMO_NODES|DEMO_NODE_NAMES|DEMO1_NODE|DEMO1_NODE_NAME|DEMO2_SETUP_ID|DEMO4_ROUTER_ID|HOTSPOT_ALLOWED_NETBIRD_NODES|HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS)\s*=\s*["\']?([^"\']*)["\']?\s*$/', $line, $match)) {
            $values[$match[1]] = trim($match[2]);
        }
    }
}

$addresses = array_values(array_filter(array_map('trim', explode(',', $values['DEMO_NODES'] ?? $values['HOTSPOT_ALLOWED_NETBIRD_NODES'] ?? ''))));
$names = array_map('trim', explode(',', $values['DEMO_NODE_NAMES'] ?? ''));
$ports = [];
foreach (explode(',', $values['HOTSPOT_ALLOWED_NETBIRD_TCP_PORTS'] ?? '80,443') as $port) {
    $value = filter_var(trim($port), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($value !== false) $ports[] = (int)$value;
}
$nodes = [];
foreach ($addresses as $index => $address) {
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $nodes[] = ['address' => $address, 'name' => $names[$index] ?? '', 'ports' => array_values(array_unique($ports))];
    }
}

$demoSetups = [];
$selection = [
    'gateway_ip' => $senderIp,
    'gateway_name' => $gatewayName,
    'organisation' => '',
    'demo1' => isset($values['DEMO1_NODE'])
        ? ['name' => $values['DEMO1_NODE_NAME'] ?? $values['DEMO1_NODE'], 'address' => $values['DEMO1_NODE']]
        : null,
    'demo2_setup_id' => isset($values['DEMO2_SETUP_ID']) ? (int)$values['DEMO2_SETUP_ID'] : null,
    'demo4_router_id' => isset($values['DEMO4_ROUTER_ID']) ? (int)$values['DEMO4_ROUTER_ID'] : null
];
try {
    $conn = getConnection();
    $result = $conn->query("SELECT s.demoSshSetupId,s.name,INET_NTOA(s.nodeAIp) node_a,s.nodeAPort,INET_NTOA(n.ip) node_b,n.port nodeBPort,s.challengeTtlSeconds FROM demoSshSetup s JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId WHERE s.active=b'1' AND n.active=b'1' ORDER BY s.demoSshSetupId");
    while ($row = $result->fetch_assoc()) {
        $demoSetups[] = ['id'=>(int)$row['demoSshSetupId'],'name'=>(string)$row['name'],'node_a'=>(string)$row['node_a'],'node_a_port'=>(int)$row['nodeAPort'],'node_b'=>(string)$row['node_b'],'node_b_port'=>(int)$row['nodeBPort'],'expires_in'=>(int)$row['challengeTtlSeconds']];
    }
    if (filter_var($senderIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $stmt=$conn->prepare("SELECT gatewayName,INET_NTOA(demo1ReceiverIp) demo1Ip,demo1ReceiverName,demoSshSetupId,demo4RouterId,organisationLabel FROM gatewayDemoConfiguration WHERE gatewayIp=INET_ATON(?)");
        $stmt->bind_param('s',$senderIp); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row) {
            if ($row['gatewayName'] !== '') $selection['gateway_name']=$row['gatewayName'];
            if ($row['demo1Ip']) $selection['demo1']=[
                'address'=>$row['demo1Ip'],
                'name'=>$row['demo1ReceiverName'] !== '' ? $row['demo1ReceiverName'] : $row['demo1Ip']
            ];
            if ($row['demoSshSetupId'] !== null) $selection['demo2_setup_id']=(int)$row['demoSshSetupId'];
            if ($row['demo4RouterId'] !== null) $selection['demo4_router_id']=(int)$row['demo4RouterId'];
            $selection['organisation']=(string)$row['organisationLabel'];
        }
    }
    $conn->close();
} catch (Throwable $e) {
    error_log('Unable to load demo configuration: ' . $e->getMessage());
}
if ($selection['demo2_setup_id'] === null && count($demoSetups)>0) $selection['demo2_setup_id']=$demoSetups[0]['id'];

$demo1Alternatives=[];
foreach($nodes as $node) {
    if (!array_filter($demo1Alternatives, fn($item) => $item['address']===$node['address'])) {
        $demo1Alternatives[]=['name'=>$node['name'] ?: $node['address'],'address'=>$node['address']];
    }
}

echo json_encode([
    'ok'=>true,
    'gateway'=>$selection['gateway_name'],
    'gateway_ip'=>$senderIp,
    'nodes'=>$nodes,
    'demo_ssh_setups'=>$demoSetups,
    'selection'=>$selection,
    'defaults'=>[
        'demo1'=>null,
        'demo2'=>['node_a'=>['name'=>'Roquefort','address'=>'100.68.176.110'],'node_b'=>['name'=>'Camembert','address'=>'100.68.149.164']]
    ],
    'demo1_alternatives'=>$demo1Alternatives,
    'network_policy'=>['lan_to_netbird'=>'allowed','netbird_to_lan'=>'established_only','tagging'=>'performed_by_destination_nodes'],
    'demo_node'=>in_array(strtolower($values['DEMO_NODE'] ?? '0'), ['1','yes','true','on'], true),
    'configured'=>is_readable($configFile),
    'server_time'=>gmdate('c')
], JSON_UNESCAPED_SLASHES);
