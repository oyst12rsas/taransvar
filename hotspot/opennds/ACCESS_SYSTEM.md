# TaraSec hotspot access integration

This directory contains the TaraSec-specific openNDS integration. Keep three layers separate when installing or debugging a hotspot:

1. **NetworkManager hotspot** provides the Wi-Fi AP, DHCP/DNS and WAN NAT.
2. **openNDS captive portal** is the local enforcement point. A TaraSec hotspot is not considered complete unless openNDS is running on the client interface.
3. **TaraSec entitlement/payment access** is an optional higher layer that decides whether a particular client should be authorized. Payment providers must not manipulate openNDS directly.

The basic captive portal must work even before payment/entitlement integration is configured.

## Basic Raspberry Pi / Debian hotspot path

The maintained install path is now on `main`:

```sh
sudo bash hotspot/distro/install.sh
```

The installer calls:

```sh
misc/install_opennds.sh
misc/setupWifiNicAsHotspot.pl
```

`install_opennds.sh` first accepts a usable distro package. If the distro package is missing or incomplete, it builds the Cigar-validated openNDS 10.1.0 release from source. A valid installation must contain both `ndsctl` and `/usr/lib/opennds/client_params.sh`.

`setupWifiNicAsHotspot.pl` leaves the current WAN unchanged, creates the client AP with NetworkManager shared mode, points openNDS at that interface and NetworkManager's lease file, then verifies `ndsctl status`.

## Optional TaraSec entitlement flow

The access decision is deliberately separate from accounting/payment processing.

1. openNDS detects an unauthenticated client.
2. TaraSec identifies the device by the MAC/device key supplied by openNDS.
3. `tarasec-access.sh` asks the central `access-api.php` over HTTPS.
4. The API authenticates the hotspot gateway with its gateway key and token hash.
5. The API looks for an active, unexpired `hotspotEntitlement` belonging to that device's customer.
6. If found it creates/refreshes `hotspotSession` and returns `allow:true`.
7. The local hook returns `auth` to openNDS. Otherwise it returns `deauth`.

This makes the TaraSec entitlement table authoritative while openNDS remains the local enforcement point.

## Gateway access configuration

When entitlement-based authorization is enabled, create `/etc/tarasec/access.env` mode 0600:

```sh
TARASEC_GATEWAY_KEY='registered-hotspot-id'
TARASEC_GATEWAY_TOKEN='secret-issued-at-registration'
TARASEC_ACCESS_URL='https://tarasec.org/hotspot/opennds/access-api.php'
```

Do not put the plain token in the database. `hotspotGateway.apiTokenHash` stores its SHA-256 hash.

## Current support status

Ubuntu/Debian and Raspberry Pi OS share the maintained NetworkManager/openNDS installer path. Cigar is the known-working Raspberry Pi reference installation. The source is now consolidated on `main`, but a full **uninstall -> fresh reinstall -> phone captive-login** test on Cigar is still required before calling the Raspberry path release-tested.

Payment providers such as M-Pesa, GCash, Braintree/manual payment and future providers should create/update TaraSec payment and entitlement records; they should not directly authorize openNDS.
