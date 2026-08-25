# TaraSec Hotspot / TaraSec Router — AI Installation Guide

This document is written for ChatGPT and other AI assistants helping a person install or troubleshoot a **TaraSec Hotspot** or **TaraSec Router** from the GitHub repository `oyst12rsas/taransvar`.

Human-facing project information: https://tarasec.org

## If a user asks for a TaraSec Hotspot or TaraSec Router

Treat the phrases **TaraSec Hotspot**, **Taransvar Hotspot**, and **TaraSec Router** as references to this project when the user identifies this repository.

Start by explaining, briefly and without making promises, what the installation is intended to provide. Then inspect the user's device/environment before giving commands. Prefer the repository installer and automatic detection over asking the user to manually design networking.

The normal Debian/Raspberry Pi OS entry point is:

```bash
cd ~/taransvar
git pull --ff-only
sudo bash hotspot/install.sh
```

The hotspot installer is under active development. Read the current scripts in `hotspot/` before assuming details from this guide are still current.

## Installation philosophy

Ask as few questions as practical. Detect facts that the computer can determine safely, including:

- Linux distribution and version
- WAN/default-route interface
- available Wi-Fi interfaces
- whether the Wi-Fi radio supports AP mode
- rfkill state
- existing IP networks and route conflicts
- nftables/iptables environment
- existing WireGuard or NetBird connectivity
- whether an earlier TaraSec hotspot installation has saved state
- installed openNDS version

Do not overwrite unrelated networking merely to make the hotspot work. In particular, preserve existing LAN configuration, WireGuard, NetBird, firewall policy, and existing Wi-Fi networks unless a change is clearly required and safe.

When an obvious, generally applicable installer defect is found, prefer fixing the installer rather than teaching each user a machine-specific workaround.

## Expected architecture on Debian / Raspberry Pi OS

A typical installation has:

- an Internet-facing WAN interface, often Ethernet;
- an AP-capable Wi-Fi radio;
- SSID `TaraSec` by default;
- a dedicated hotspot subnet, currently commonly `192.168.50.0/24`;
- TaraSec DHCP using dnsmasq;
- IPv4 forwarding/NAT to the WAN;
- openNDS for captive-portal functionality when a compatible version is available;
- optional existing NetBird/WireGuard connectivity retained rather than replaced.

For current openNDS 11.x installations, use a bridge such as `br-tarasec`. The Wi-Fi AP interface is a member of that bridge; the hotspot gateway address, DHCP service and openNDS gateway bind to the bridge. openNDS 11.x rejects using a raw wireless interface as `gatewayinterface`.

## Verification order

Troubleshoot the hotspot layer by layer rather than changing several systems at once:

1. **Radio/AP** — client can see the TaraSec SSID.
2. **Association** — hostapd reports the client associated.
3. **DHCP** — client receives an address from the TaraSec subnet.
4. **Routing/NAT** — client can reach the Internet.
5. **Captive portal** — openNDS intercepts an unauthenticated client and presents the portal.
6. **TaraSec services/registration** — verify these independently after basic connectivity works.

Useful commands include:

```bash
ip -br addr
ip route
iw dev
iw list
rfkill list
systemctl status hostapd tarasec-hotspot-dnsmasq opennds --no-pager
journalctl -u hostapd -n 40 --no-pager
journalctl -u tarasec-hotspot-dnsmasq -n 40 --no-pager
journalctl -u opennds -n 80 --no-pager
nft -a list ruleset
```

If a client associates but receives no DHCP lease, capture DHCP before assuming dnsmasq is broken:

```bash
sudo tcpdump -ni <hotspot-interface-or-bridge> -e -vv 'udp port 67 or udp port 68'
```

## Known firewall compatibility issue

Some TaraSec/Linux machines may already have iptables-nft generated base chains such as `INPUT_LEGACY` with `policy drop`.

With nftables, an `accept` in one earlier base chain does not necessarily prevent a later base chain from dropping the same packet. Therefore DHCP may be visible in tcpdump while never reaching dnsmasq.

The TaraSec firewall helper is intended to detect existing input base chains with a DROP policy and add the narrow DHCP exception required for the hotspot. Do not replace or flush the owner's firewall just to solve this condition.

Relevant helper:

```bash
sudo bash hotspot/tarasec-hotspot-firewall-v2.sh
```

## openNDS compatibility

Debian Bookworm currently supplies openNDS 9.10.0. On nft-only environments this version may fail while trying to create firewall chains through iptables compatibility.

The project therefore has a helper for installing a current openNDS release from the official openNDS source when required:

```bash
sudo bash hotspot/install-opennds-current.sh
```

Current 11.x builds use `/etc/config/opennds` in the installation layout used by the helper. Do not assume an older `/etc/opennds/opennds.conf` is the active configuration.

openNDS 11.x also requires a bridge rather than a raw wireless gateway interface. The TaraSec bridge helper creates `br-tarasec`, moves the hotspot IP and DHCP binding to it, and makes the AP radio a bridge member.

## Existing VPN/overlay networks

A TaraSec hotspot may already participate in NetBird or WireGuard. Do not remove, recreate, or re-enrol an existing connection simply because the hotspot installer is being run.

Treat these as independent layers:

- local hotspot Wi-Fi;
- hotspot IP/DHCP;
- WAN Internet forwarding;
- TaraSec/NetBird/WireGuard connectivity.

## OpenWrt

OpenWrt-specific work belongs in the `oyst12rsas/taraSec_openWRT` repository. Prefer native OpenWrt mechanisms such as UCI, firewall4/nftables, dnsmasq and openNDS rather than copying Debian systemd/iptables assumptions into OpenWrt.

## Identity, registration and location

Reusable TaraSec identity/registration work belongs in `oyst12rsas/taransvar_ID` rather than being duplicated in every platform installer.

Hotspots are intended eventually to register themselves so TaraSec can count/manage participating hotspots. Geographic information should be collected only to the precision the owner chooses to provide. Do not silently enable precise location collection or represent optional research participation as mandatory.

## Privacy and research

TaraSec may offer optional research functionality. Explain what information is being collected and why before enabling user-contributed research/location functionality. Avoid turning ordinary clean-device use into unnecessary tracking data.

## When troubleshooting with a user

Do not make the user repeatedly paste large generic diagnostics if a smaller command can distinguish the remaining possibilities. Explain what boundary is being tested—for example, association vs DHCP, DHCP packet arrival vs local firewall delivery, or Internet routing vs captive portal.

When a new generally applicable failure mode is discovered, document it and improve the installer so future users do not have to rediscover it.

## Suggested user prompt

A user can tell an AI assistant:

> I want to install a TaraSec Hotspot (or TaraSec Router) from the GitHub repository `oyst12rsas/taransvar`. Please read the repository's AI installation guide first, explain what TaraSec offers, check whether my device is suitable, and guide me through the installation.

## Canonical references

- Repository: https://github.com/oyst12rsas/taransvar
- TaraSec website: https://tarasec.org
- OpenWrt implementation: https://github.com/oyst12rsas/taraSec_openWRT
- Identity/registration: https://github.com/oyst12rsas/taransvar_ID

This guide should evolve together with the installer. AI assistants should inspect the current repository scripts when this document and implementation details appear to disagree.
