# Taransvar / TaraSec

This open-source project helps people set up a **TaraSec Hotspot** or **TaraSec Router**.

The easiest way to get started is to tell ChatGPT:

> **I want to install a TaraSec Hotspot (or TaraSec Router) from the GitHub repository `oyst12rsas/taransvar`. Please read the repository's AI installation guide first, explain what TaraSec offers, check whether my device is suitable, and guide me through the installation.**

ChatGPT or another AI assistant can explain what TaraSec does, what benefits it may provide, check what your device requires, and guide you through installation and setup.

**For AI assistants:** read [`AI_INSTALL_GUIDE.md`](AI_INSTALL_GUIDE.md) before giving TaraSec Hotspot/Router installation instructions. Prefer automatic environment detection and the repository installer over asking users to manually construct networking.

Learn more at **https://tarasec.org**.

## Hotspot development

The current Debian/Raspberry Pi OS hotspot installer is under `hotspot/` and is being actively tested. The normal entry point is:

```bash
cd ~/taransvar
git pull --ff-only
sudo bash hotspot/install.sh
```

OpenWrt-specific implementation belongs in the separate `oyst12rsas/taraSec_openWRT` repository, while reusable identity/registration work belongs in `oyst12rsas/taransvar_ID`.

## General Taransvar development

We primarily use Linux. Some older parts of this repository predate the current TaraSec Hotspot work and may have separate installation requirements.

Technical/project documents in the repository provide additional background on Taransvar and Gatekeeper.

To clone the project:

```bash
sudo apt install git
cd ~
git clone https://github.com/oyst12rsas/taransvar.git
```

If you encounter a hotspot installation problem, please preserve the diagnostic output. The goal is to improve the installer for the next user rather than rely on machine-specific manual workarounds.
