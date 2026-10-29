<?php
// Exercise the real renderer without bootstrapping sessions, the DB or AJAX routing.
$source = file_get_contents(__DIR__.'/../html/gatekeeper/ajax.php');
$start = strpos($source, 'function activeUnits()');
$end = strpos($source, "\n\n\$szFile =", $start);
if ($start === false || $end === false) throw new RuntimeException('Renderer missing');
eval(substr($source, $start, $end - $start));
$dispatch = substr($source, strpos($source, 'function units()'), $start - strpos($source, 'function units()'));
if (strpos($dispatch, 'activeUnits();') === false || strpos($dispatch, 'return;') !== false)
    throw new RuntimeException('Units refresh must reach the local renderer');
class CXmlCommand {
    public static $values = [];
    static function setInnerHTML($id, $name, $value) { self::$values[$id] = $value; }
}
class ActiveRows {
    private $rows;
    function __construct($rows) { $this->rows = $rows; }
    function fetch_assoc() { return array_shift($this->rows); }
    function free() {}
}
class ActiveConnection {
    public $rows = [];
    public $fail = false;
    function query($sql) {
        if (strpos($sql, 'FROM unit WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)') === false)
            throw new RuntimeException('Missing observed-unit recency filter');
        if (strpos($sql, 'JOIN dhcp') !== false) throw new RuntimeException('DHCP must not be required');
        return $this->fail ? false : new ActiveRows($this->rows);
    }
}
$db = new ActiveConnection();
function getConnection() { global $db; return $db; }
$db->rows = [['unitId'=>20, 'hostname'=>'phone<script>', 'dhcpClientId'=>'', 'vci'=>'',
    'description'=>'My phone', 'mac'=>'', 'lastSeen'=>'2026-10-29 12:00:00', 'ip'=>'10.10.0.2']];
activeUnits();
$html = CXmlCommand::$values['unitsTbl'];
if (strpos($html, '10.10.0.2') === false || strpos($html, 'phone&lt;script&gt;') === false ||
    strpos($html, 'phone<script>') !== false || strpos($html, 'showPorts&amp;id=20') === false)
    throw new RuntimeException('Routed client rendering/escaping failed');
$db->rows = [];
activeUnits();
if (strpos(CXmlCommand::$values['unitsTbl'], '10.10.0.2') !== false ||
    strpos(CXmlCommand::$values['updateTime'], 'No units observed') === false)
    throw new RuntimeException('Empty refresh must remove old clients and explain the result');
$db->fail = true;
activeUnits();
if (strpos(CXmlCommand::$values['updateTime'], 'Unable to read') === false)
    throw new RuntimeException('Query failures must be visible');
$page = file_get_contents(__DIR__.'/../html/gatekeeper/func/units.php');
if (substr_count($page, 'id="unitsTbl"') !== 1 || strpos($page, 'id ="unitsTbl"') !== false)
    throw new RuntimeException('Client table ID must be unique');
echo "Active units AJAX checks passed\n";
