<?php
// Load the real AJAX module without index.php, as ajax.php does.
chdir(__DIR__.'/../html/gatekeeper');
$pSystem = (object)['cCommands' => []];
require_once __DIR__.'/../html/gatekeeper/ajax/units_partners.php';
class EmptyRows {
    public $num_rows = 0;
    function fetch_assoc() { return null; }
    function free() {}
}
class FakeConnection {
    function query($sql) {
        if (strpos($sql, 'from setup') !== false) throw new RuntimeException('reached-status');
        return new EmptyRows();
    }
}
function getConnection() { return new FakeConnection(); }
foreach ([[], ['isAdmin' => 0], ['isAdmin' => 1]] as $session) {
    $_SESSION = $session;
    if (unitActivityIsAdmin() !== (($session['isAdmin'] ?? 0) == 1)) {
        throw new RuntimeException('Incorrect admin authorization');
    }
    if (!unitActivityIsAdmin() && unitActivityOverview(null) !== '') {
        throw new RuntimeException('Nonadmin disclosure');
    }
    try {
        units_partners();
        throw new RuntimeException('Expected status sentinel');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'reached-status') throw $e;
    }
}
echo "Standalone Units AJAX authorization checks passed\n";
