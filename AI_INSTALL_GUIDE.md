# TaraSec AI installation guide

This file is intended for ChatGPT and other AI assistants helping a user install or test TaraSec from this repository.

## Authoritative source

Use the `main` branch of:

`https://github.com/oyst12rsas/taransvar`

Do not assume an old feature branch is required for hotspot/openNDS installation. The maintained openNDS pieces have been consolidated onto `main`.

## Current platform status

### Ubuntu 24.04 / 26.04
Maintained Debian-family installer path. NetworkManager is the supported hotspot networking backend.

### Debian family
Expected to use the same maintained installer path, subject to hardware/AP support and package availability.

### Raspberry Pi OS
The Raspberry/openNDS implementation is now present on `main` and is based on the working Cigar installation. It uses the same NetworkManager hotspot architecture plus an openNDS installer with a source-build fallback.

**Important:** Do not describe Raspberry Pi OS as release-tested yet. The remaining acceptance test is:

1. On the known-working Raspberry Pi reference (`cigar`), run the TaraSec uninstall/reset.
2. Reinstall from a clean `main` checkout.
3. Verify the Wi-Fi AP appears.
4. Connect a phone and receive a DHCP address.
5. Verify captive portal interception/openNDS splash/login.
6. Verify normal Internet access after authorization.
7. Verify the local TaraSec API remains reachable on port 8080 while openNDS owns/intercepts captive port 80.
8. Verify NetBird enrollment/management separately from customer Internet routing.

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

## openNDS

Relevant maintained files:

- `misc/install_opennds.sh`
- `misc/setupWifiNicAsHotspot.pl`
- `hotspot/opennds/ACCESS_SYSTEM.md`
- `hotspot/opennds/schema.sql`
- `hotspot/opennds/tarasec-access.sh`
- `hotspot/opennds/access-api.php`

`misc/install_opennds.sh`:

1. accepts a distro openNDS package only if it produces both `ndsctl` and `/usr/lib/opennds/client_params.sh`;
2. otherwise builds the Cigar-validated openNDS 10.1.0 release from source;
3. verifies the required helper layout.

`misc/setupWifiNicAsHotspot.pl`:

- refuses to turn the active WAN interface into the hotspot;
- verifies Wi-Fi AP support;
- uses NetworkManager shared mode for AP/DHCP/DNS/NAT;
- uses NetworkManager's dnsmasq lease file rather than running a competing TaraSec dnsmasq;
- configures `/etc/config/opennds` with the correct gateway interface/name/lease file;
- starts and verifies openNDS with `ndsctl status`.

## Important captive portal port behavior

On the validated Cigar setup, openNDS intercepts hotspot-client HTTP traffic on port 80 and redirects it to its gateway service (normally port 2050). Therefore TaraSec's local app/status API uses Apache port 8080.

Do not diagnose `http://192.168.50.1/script/...` port-80 failures as an Apache failure before checking openNDS nftables rules.

Typical separation:

- `192.168.50.1:80` -> captive/openNDS interception
- `192.168.50.1:2050` -> openNDS gateway
- `192.168.50.1:8080` -> TaraSec local API/application path

## NetBird

`misc/install_netbird_management.sh` performs zero-touch management enrollment through:

`https://tarasec.org/script/hotspotNetbirdEnroll.php`

A new hotspot receives only a one-off setup key generated server-side. The server NetBird PAT must never be stored on the hotspot.

The initial peer belongs to the restricted NetBird bootstrap group and must not automatically receive broad lateral access.

## Payment/entitlement access

Do not confuse a working captive portal with the optional TaraSec entitlement/payment authorization layer.

Basic openNDS captive enforcement should work first. The higher layer described in `hotspot/opennds/ACCESS_SYSTEM.md` can then decide whether a subscriber/device is authorized based on TaraSec entitlement records.

Payment providers should create/update TaraSec payment/entitlement records; they should not directly manipulate openNDS firewall state.

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
5. distinguish basic hotspot/openNDS, TaraSec management/NetBird, and payment/entitlement as separate layers;
6. for Raspberry Pi OS, state that the implementation is on `main` but the clean reinstall acceptance test is still pending until explicitly confirmed.
