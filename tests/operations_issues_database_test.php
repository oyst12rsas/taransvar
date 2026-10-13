<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__.'/../html/script/operationsIssues.php';
require __DIR__.'/../html/gatekeeper/func/manualIssues.php';
require __DIR__.'/../html/gatekeeper/func/agentStatusView.php';
require __DIR__.'/../html/gatekeeper/func/operationsStatusView.php';
$conn=new mysqli('127.0.0.1','root',getenv('ISSUE_TEST_PASSWORD') ?: '', '', (int)(getenv('ISSUE_TEST_PORT') ?: 3307));
$conn->query('CREATE DATABASE IF NOT EXISTS tarasec_issue_test'); $conn->select_db('tarasec_issue_test');
$conn->query('DROP TABLE IF EXISTS issueReport'); $conn->query('DROP TABLE IF EXISTS issue'); $conn->query('DROP TABLE IF EXISTS setup');
$conn->query('CREATE TABLE setup (dbVersion INT)'); $conn->query('INSERT INTO setup VALUES (99)');
$item=['key'=>'service:test.service','incarnation'=>str_repeat('a',32),'discovered_at'=>100,'last_seen_at'=>100,'checked_at'=>100,'description'=>'Failed <script>','status'=>'open','verification'=>'service','next_action_at'=>100,'history'=>[['event_id'=>1,'at'=>100,'kind'=>'discovered','detail'=>'Failed']]];
if (storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$item]]) !== []) throw new Exception('Old schema must skip ingestion');
$sql=file_get_contents(__DIR__.'/../misc/install.sql');
$block=explode('#********',explode('#version 100',$sql,2)[1],2)[0];
$block=preg_replace('/^.*\(261013\).*$/m','',$block);
foreach (explode(';',$block) as $statement) if (trim($statement)) $conn->query($statement);
$conn->begin_transaction(); $ack=storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$item]]); $conn->commit();
if (($ack[$item['incarnation']] ?? 0) !== 1) throw new Exception('Missing receipt');
storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$item]]);
if ((int)$conn->query('SELECT COUNT(*) AS n FROM issueReport')->fetch_assoc()['n'] !== 1) throw new Exception('Duplicate event');
$new=$item; $new['status']='solved'; $new['checked_at']=200; $new['solved_at']=200;
$new['history'][]=['event_id'=>2,'at'=>200,'kind'=>'verified_solved','detail'=>'Fresh check passed'];
storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$new]]);
storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$item]]);
if ($conn->query('SELECT status FROM issue')->fetch_assoc()['status'] !== 'solved') throw new Exception('Stale report overwrote resolution');
if ((int)$conn->query('SELECT COUNT(*) AS n FROM issueReport')->fetch_assoc()['n'] !== 2) throw new Exception('Missing lifecycle history');
$other=$item; $other['incarnation']=str_repeat('b',32);
storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$other]]);
if ((int)$conn->query('SELECT COUNT(*) AS n FROM issueReport')->fetch_assoc()['n'] !== 3) throw new Exception('Fresh local state event collided');
$rows=operationsStoredIssues($conn,'100.68.165.190');
$html=operationsStatusDetails(['issues'=>$rows]);
if (strpos($html,'<script>') !== false || strpos($html,'verified_solved') === false) throw new Exception('Unsafe or incomplete history view');
$conn->begin_transaction(); storeOperationsIssues($conn,'100.68.165.190',['issues'=>[['key'=>'reboot:required']+$item]]); $conn->rollback();
if ((int)$conn->query('SELECT COUNT(*) AS n FROM issue')->fetch_assoc()['n'] !== 1) throw new Exception('Rollback failed');
echo "Issue database lifecycle checks passed\n";
if (operationsStoredIssues($conn,'100.68.165.190',false) !== []) throw new Exception('Solved issue leaked into open list');
if (count(operationsStoredIssues($conn,'100.68.165.190',true)) !== 1) throw new Exception('Full list must include solved');
if (operationsStoredIssues($conn,'100.68.165.190',true,64) !== []) throw new Exception('Issue pagination offset ignored');

$conn->query('ALTER TABLE setup ADD adminIP INT UNSIGNED, ADD nickname VARCHAR(64), ADD networkStatus TEXT');
$conn->query('UPDATE setup SET adminIP=INET_ATON("100.68.165.190"),nickname="Standard"');
$session=['manualIssueCsrf'=>str_repeat('c',64),'userid'=>9];
$post=['csrf'=>$session['manualIssueCsrf'],'description'=>'New SSH login succeeds while the gate is closed','nodeIp'=>'1.2.3.4'];
foreach ([[false,$post],[true,array_merge($post,['csrf'=>'wrong'])],[true,array_merge($post,['description'=>'short'])]] as [$admin,$bad]) {
    try { manualIssueSubmit($conn,$admin,$session,$bad); throw new Exception('Invalid submission accepted'); }
    catch (RuntimeException $expected) {}
}
manualIssueSubmit($conn,true,$session,$post);
manualIssueSubmit($conn,true,$session,$post);
$manual=$conn->query('SELECT *,INET_NTOA(nodeIp) ip FROM issue WHERE verification="manual"')->fetch_assoc();
if ($manual['ip'] !== '100.68.165.190' || $manual['status'] !== 'open') throw new Exception('Manual report target or status incorrect');
if ((int)$conn->query('SELECT COUNT(*) n FROM issue WHERE verification="manual"')->fetch_assoc()['n'] !== 1) throw new Exception('Retry duplicated issue');
$id=(int)$manual['issueId'];
if ((int)$conn->query("SELECT COUNT(*) n FROM issueReport WHERE issueId=$id")->fetch_assoc()['n'] !== 1) throw new Exception('Discovery history incorrect');
try {
    manualIssueSubmit($conn,true,$session,$post+['action'=>'verify','key'=>'manual:foreign']);
    throw new Exception('Foreign issue accepted');
} catch (RuntimeException $expected) {}
manualIssueSubmit($conn,true,$session,array_merge($post,['action'=>'verify','key'=>$manual['issueKey'],'description'=>'Fresh new SSH connection rejected with gate closed; reopening permits login.']));
if ($conn->query("SELECT status FROM issue WHERE issueId=$id")->fetch_assoc()['status'] !== 'solved') throw new Exception('Verified resolution not recorded');
echo "Manual issue authorization, target binding, replay and verification checks passed\n";

$stale=['key'=>$manual['issueKey'],'incarnation'=>substr($manual['issueKey'],7),
    'discovered_at'=>100,'last_seen_at'=>microtime(true)+100,'checked_at'=>microtime(true)+100,
    'description'=>'Stale snapshot','status'=>'pending','verification'=>'manual','history'=>[]];
storeOperationsIssues($conn,'100.68.165.190',['issues'=>[$stale]]);
$closed=$conn->query("SELECT status,solvedAt FROM issue WHERE issueId=$id")->fetch_assoc();
if ($closed['status'] !== 'solved' || !$closed['solvedAt']) throw new Exception('Stale worker reopened verified manual issue');

require_once __DIR__.'/../html/gatekeeper/func/issues.php';
function isAdmin() { return !empty($_SESSION['isAdmin']); }
function getConnection() {
    return new mysqli('127.0.0.1','root',getenv('ISSUE_TEST_PASSWORD') ?: '',
        'tarasec_issue_test',(int)(getenv('ISSUE_TEST_PORT') ?: 3307));
}
$_SESSION=['isAdmin'=>false]; $_SERVER['REQUEST_METHOD']='GET'; $_GET=[];
ob_start(); issues(); $html=ob_get_clean();
if (strpos($html,'Administrator login required') === false || strpos($html,'<form') !== false) throw new Exception('Issues page admin gate failed');
$_SESSION=['isAdmin'=>true,'manualIssueCsrf'=>str_repeat('d',64),'userid'=>9];
manualIssueSubmit($conn,true,$_SESSION,['csrf'=>$_SESSION['manualIssueCsrf'],'description'=>'Unresolved manual issue for navigation test']);
ob_start(); issues(); $html=ob_get_clean();
if (strpos($html,'<h1>Open issues</h1>') === false || strpos($html,'Unresolved manual issue for navigation test') === false
    || strpos($html,$manual['description']) !== false || strpos($html,'value="report"') !== false) throw new Exception('Open issues page filtering or navigation failed');
$_GET=['history'=>'all'];
ob_start(); issues(); $html=ob_get_clean();
if (strpos($html,'<h1>Full issue history</h1>') === false || strpos($html,$manual['description']) === false) throw new Exception('Full history omitted solved issue');
$_GET=[];
ob_start(); reportIssue(); $html=ob_get_clean();
if (strpos($html,'value="report"') === false || strpos($html,'Unresolved manual issue for navigation test') !== false) throw new Exception('New report form not separate from list');
echo "Issues navigation and admin rendering checks passed\n";

$workerStatus=json_encode(['operationsAgent'=>['status'=>'monitoring','last_assessment_status'=>'reported']]);
$stmt=$conn->prepare('UPDATE setup SET networkStatus=?'); $stmt->bind_param('s',$workerStatus); $stmt->execute();
$_GET=[]; ob_start(); issues(); $html=ob_get_clean();
if (strpos($html,'Worker: monitoring. Last assessment: reported.') === false || strpos($html,'Evidence: Unresolved manual issue for navigation test') === false) throw new Exception('Issue page lost worker metadata or discovery evidence');
$conn->query('UPDATE setup SET networkStatus=NULL');
ob_start(); issues(); $html=ob_get_clean();
if (strpos($html,'Worker: unknown') !== false || strpos($html,'Worker status is unavailable') === false) throw new Exception('Missing snapshot fabricated unknown worker state');
echo "Worker metadata and manual discovery evidence checks passed\n";
