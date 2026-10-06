<?php
return [
    // DB server only. Opt in after enrolling independent receivers and testing.
    'enabled' => false,
    'duration_seconds' => 300, 'grace_seconds' => 30,
    'minimum_connections' => 20, 'minimum_malicious' => 5,
    'minimum_receivers' => 2, 'alarm_ratio' => 0.5,
    // Reports are connection counts, not packet counts. No payloads collected.
    // Install migration, deploy web files, and enable observation timers on DB
    // and receivers. Receiver config reuses partner-restrictions.json tokens.
    // Disable here to stop new requests and alarm evaluation immediately.
    // Verify: Gatekeeper Home -> Partner observation; journalctl -u
    // tarasec-partner-observation.service. Alarms never apply firewall changes.
];
