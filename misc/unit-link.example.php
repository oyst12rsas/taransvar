<?php
// /etc/tarasec/unit-link.php: root:www-data 0640; never commit real values.
return [
    'gateway_id' => '', // Random 16 bytes as lowercase hex; stable for this gateway.
    'subject_key' => '', // Random 32 bytes as hex; keep private, stable and backed up.
    'base_url' => '', // HTTPS origin serving this gateway, e.g. https://gateway.example.org.
    'google_client_id' => '', // Web client ID; register base_url as an authorized JS origin.
    'google_autoload' => '/opt/tarasec-google/vendor/autoload.php',
];
