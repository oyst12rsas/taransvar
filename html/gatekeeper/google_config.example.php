<?php
// Copy to /etc/tarasec/gatekeeper-google.php (outside the web root).
// The same random 64-character hex secret is registered by hash in the
// tarasec.org agent approval service configuration.
return [
    'client_id' => 'dbserver1',
    'shared_secret' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    'agent_api' => 'https://tarasec.org/ops/agent/api.php',
    'sign_in_url' => 'https://tarasec.org/ops/agent/gatekeeper.php',
];
