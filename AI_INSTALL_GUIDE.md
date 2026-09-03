# TaraSec AI installation guide

This file is intended for ChatGPT and other AI assistants helping a user install or test TaraSec from this repository.

## Authoritative source

Use the `main` branch of:

`https://github.com/oyst12rsas/taransvar`

Do not assume an old feature branch is required for hotspot/openNDS installation. The maintained openNDS and TaraSec captive-portal pieces have been consolidated onto `main`.

## Current platform status

### Ubuntu 24.04 / 26.04
Maintained Debian-family installer path. NetworkManager is the supported hotspot networking backend.

### Debian family
Expected to use the same maintained installer path, subject to hardware/AP support and package availability.

### Raspberry Pi OS
The Raspberry/openNDS implementation is present on `main` and is based on the working Cigar installation. It uses the same NetworkManager hotspot architecture plus an openNDS installer with a source-build fallback.

**Important:** Do not describe a platform as release-tested merely because `ndsctl status` works. TaraSec acceptance requires the custom TaraSec portal, not the stock openNDS splash.

The remaining clean-install acceptance sequence is:

1. Reset/uninstall the test host.
2. Reinstall from a clean `main` checkout.
3. Verify the Wi-Fi AP appears.
4. Connect a phone and receive a DHCP address.
5. Verify captive interception presents the **TaraSec Hotspot** ThemeSpec, not the default openNDS page.
6. Verify subscriber login through `/hotspot/portal_login.php`.
7. Verify the TaraSec `access` record is enforced by openNDS BinAuth.
8. Verify normal Internet access after authorization.
9. Verify account/status/logout through `/hotspot/portal_status.php`.
10. Verify Apache port 8080 remains reachable from the hotspot client while openNDS owns/intercepts captive port 80.
11. Verify logout removes TaraSec access/session state and deauthorizes the openNDS client.
12. Verify NetBird enrollment/management separately from customer Internet routing.

Until that sequence passes from a fresh reset, say **implemented on main, pending clean reinstall acceptance test**.

## Main hotspot install

From a fresh repository checkout:

```sh
sudo bash hotspot/distro/install.sh
```

The installer must preserve the interface that currently provides the normal Internet/default route.

The intended roles are:

- WAN/uplink: existing Internet connection; leave it working.
- hotspot/client interface: spare Wi-Fi AP interface.
- NetBird `wt0`/`wt*`: TaraSec management only; not customer Internet.

## openNDS and TaraSec captive portal

Relevant maintained files:

- `misc/install_opennds.sh`
- `misc/setupWifiNicAsHotspot.pl`
- `hotspot/opennds/theme_tarasec.sh`
- `hotspot/opennds/tarasec-access-check`
- `hotspot/opennds/access_policy.pl`
- `hotspot/opennds/custombinauth.sh`
- `hotspot/opennds/tarasec-subscriber-logout`
- `html/hotspot/portal_login.php`
- `html/hotspot/portal_status.php`
- `hotspot/opennds/ACCESS_SYSTEM.md`
- `hotspot/opennds/tarasec-access.sh`

`misc/install_opennds.sh`:

1. accepts a distro openNDS package only if it produces both `ndsctl` and `/usr/lib/opennds/client_params.sh`;
2. otherwise builds the Cigar-validated openNDS 10.1.0 release from source;
3. installs the TaraSec ThemeSpec and enforcement helpers;
4. deploys the hotspot PHP application including captive login/status endpoints;
5. creates an Apache `:8080` captive-login vhost restricted to the hotspot /24;
6. configures openNDS `login_option_enabled '3'` and `themespec_path '/usr/lib/opennds/theme_tarasec.sh'` once the hotspot interface has its client address;
7. configures the legacy generic-Linux openNDS firewall block to allow client access to TCP 8080;
8. verifies openNDS, ThemeSpec configuration, port 8080 and port 2050.

`misc/setupWifiNicAsHotspot.pl`:

- refuses to turn the active WAN interface into the hotspot;
- verifies Wi-Fi AP support;
- uses NetworkManager shared mode for AP/DHCP/DNS/NAT;
- uses NetworkManager's dnsmasq lease file rather than running a competing TaraSec dnsmasq;
- brings the hotspot interface up first;
- invokes `misc/install_opennds.sh` with the actual interface, client address and SSID;
- verifies `ndsctl status`, TaraSec ThemeSpec selection and Apache port 8080.

A successful `ndsctl status` with the default openNDS page is **not** a successful TaraSec hotspot installation.

## Important captive portal port behavior

On the validated Cigar setup, openNDS intercepts hotspot-client HTTP traffic on port 80 and redirects it to its gateway service (normally port 2050). TaraSec's local hotspot application therefore uses Apache port 8080.

Typical separation:

- `<hotspot gateway>:80` -> captive/openNDS interception
- `<hotspot gateway>:2050` -> openNDS gateway/status service
- `<hotspot gateway>:8080/hotspot` -> TaraSec subscriber login/account application

The Apache 8080 vhost must be restricted to the configured hotspot client subnet. Do not expose the captive subscriber application broadly on WAN or NetBird management interfaces.

## Subscriber authorization flow

The maintained flow is:

1. openNDS invokes `theme_tarasec.sh` for a preauthenticated client.
2. `tarasec-access-check` checks the TaraSec `access` table for the TCP client IP.
3. If access is absent, the ThemeSpec presents the subscriber login form and posts to `portal_login.php` on port 8080.
4. `portal_login.php` derives client identity from `REMOTE_ADDR`, validates the posted address only as a consistency check, verifies the subscriber in `radcheck`, evaluates expiry/quota, creates the TaraSec session, and sets `access.hasaccess=1`.
5. The browser returns to openNDS preauth.
6. openNDS stock BinAuth loads TaraSec `custombinauth.sh`, which independently checks the TaraSec policy before authentication and can apply per-client session/rate/quota values.
7. `portal_status.php` shows account information and provides CSRF-protected logout.
8. Logout calls the narrowly permitted `tarasec-subscriber-logout` helper, closes TaraSec session/access state and deauthenticates the openNDS client.

Do not replace this with a cosmetic ThemeSpec that simply grants access. The TaraSec `access` table remains the enforcement source of truth.

## NetBird

`misc/install_netbird_management.sh` performs zero-touch management enrollment through:

`https://tarasec.org/script/hotspotNetbirdEnroll.php`

A new hotspot receives only a one-off setup key generated server-side. The server NetBird PAT must never be stored on the hotspot.

The initial peer belongs to the restricted NetBird bootstrap group and must not automatically receive broad lateral access.

## Payment/entitlement access

Payment providers should create/update TaraSec payment/entitlement records; they should not directly manipulate openNDS firewall state. Subscriber login translates valid TaraSec account entitlement into the local `access` state that openNDS enforces.

## Uninstall/reset for development

Use:

```sh
sudo bash uninstall.sh
```

The default reset removes TaraSec-owned state while preserving generic OS packages. This is the preferred mode when repeatedly testing install/uninstall on the same Ubuntu/Debian/Raspberry machine.

Do not use `--purge-packages` unless the host is disposable/dedicated and removal of Apache/MySQL/etc. is acceptable.

## AI guidance rule

When a user says they want to install or test TaraSec:

1. identify the OS and available network interfaces;
2. preserve whatever interface currently provides Internet;
3. select another AP-capable interface for clients;
4. use the maintained `main` installer rather than old branch-specific instructions;
5. verify the **TaraSec custom portal**, not merely openNDS service health;
6. distinguish hotspot/openNDS, TaraSec subscriber entitlement, TaraSec management/NetBird, and optional payment integration as separate layers;
7. for Raspberry Pi OS and other not-yet-clean-tested platforms, state that the implementation is on `main` but clean reinstall acceptance is pending until explicitly confirmed.
