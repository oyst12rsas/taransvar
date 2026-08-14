<?php

function dbServerMenu()
{
?>
<div class="gk-local-menu">
    <div class="gk-dropdown">
        <button type="button" class="gk-dropdown-button" aria-haspopup="true" aria-expanded="false">DB server ▾</button>
        <div class="gk-dropdown-content">
            <a href="index.php?f=db_syslog">Syslog</a>
            <a href="index.php?f=db_ai">AI</a>
        </div>
    </div>
</div>
<?php
}

function dbServer()
{
    dbServerMenu();

    print "<h1>DB server</h1>";
}

?>