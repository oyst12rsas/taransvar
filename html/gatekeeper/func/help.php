<?php

function help()
{ ?>
<h2>Getting started with Taransvar Gatekeeper</h2>
<p>There is a PDF document explaining how to use the AB Gatekeeper and how to contribute to our project. We suggest you request that document if you haven't yet.</p>
<p>This database contains configuration info that will be read by the "userserver" program and then sent as a socket message to the "absecurity" kernel module once it starts.</p>
<ul>
<p>To get started, you should register:</p>
<ul>
<li>One or more "<a href="index.php?f=adddomain">Domains</a> that will be blocked by the system.</li>
<li>"<a href="index.php?f=addserver">Servers</a> in your network and required quality of clients.</li>
<li>You can also specifically <a href="index.php?f=addColorListing">white- or blacklists individual IP addresses</a>.</li>
</ul>
<p>
Network setup:<br>
<ul>
<li>DHCP status: sudo service isc-dhcp-server status</li>
<li>Network setup: ifconfig</li>
<li>Cron setup: sudo crontab -u root -e</li>
<li>Setup script: sudo misc/setup_network.pl</li>
<li>Diagnose: sudo perl misc/diagnose.pl</li>
</ul>

</p>

<?php
}

?>
