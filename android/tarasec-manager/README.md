# TaraSec Manager Android prototype

This is the first end-to-end manager-app slice for a TaraSec gateway.

## Current flow

1. Enter the gateway base URL.
2. Enter the manager key issued after email verification and local gateway approval.
3. The app POSTs the key to `/script/managerAuth.php?action=login`.
4. The PHP endpoint creates the authenticated manager session.
5. The app keeps the session cookie in memory and calls `/script/managerGateway.php?action=status`.
6. The gateway status endpoint re-checks that the manager request is still active, so revocation takes effect on the next refresh.

The manager key is deliberately not persisted by this prototype. The authenticated cookie is memory-only and disappears when the process is killed.

## Current capabilities

The status endpoint reports gateway name, reachability, server time and manager identity. It also returns a capability map. `threats`, `units` and `notifications` are currently false and are intended to become the next API/app slices.

## Development note

`android:usesCleartextTraffic="true"` is enabled because existing TaraSec test gateways may still be addressed by HTTP/private IP during development. Production manager access should use HTTPS and then remove this allowance.

## Build

Open `android/tarasec-manager` as an Android Studio project. The prototype uses only Android platform APIs; there are no runtime third-party dependencies.
