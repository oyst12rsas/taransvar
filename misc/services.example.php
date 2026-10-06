<?php
// Optional /etc/tarasec/services.php, root:www-data 0640.
// Leave both empty to use tarasec.org. For self-hosting, deploy the compatible
// tarasec_payment identity AND subscriber APIs under the same trusted HTTPS origin.
return [
    'identity_api_base' => '', // e.g. https://accounts.example.org/api/v1/identity
    // Optional administrator service pair, with its separate registered shared secret.
    'admin_api_url' => '', // e.g. https://ops.example.org/ops/agent/api.php
    'admin_sign_in_url' => '', // e.g. https://ops.example.org/ops/agent/gatekeeper.php
    'subscriber_api_base' => '', // e.g. https://accounts.example.org/api/v1/subscriber
];
