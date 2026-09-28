#!/usr/bin/env bash
# Register the TaraSec kernel module with DKMS. Does not load it or reboot.
set -euo pipefail

if (( EUID != 0 )); then
    echo "Run as root: sudo bash misc/install_tarakernel_dkms.sh" >&2
    exit 1
fi

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
kernel="$(uname -r)"
version=1.0
source_dir="/usr/src/tarakernel-${version}"

command -v dkms >/dev/null || { echo "Install dkms first" >&2; exit 1; }
command -v make >/dev/null || { echo "Install build-essential first" >&2; exit 1; }
test -f "/lib/modules/${kernel}/build/Makefile" || {
    echo "Install matching headers for ${kernel} before continuing" >&2
    exit 1
}

if test -e "$source_dir"; then
    echo "$source_dir already exists; inspect the registered source before reinstalling" >&2
    exit 1
fi

install -d "$source_dir"
cp -a "$repo_root/tarakernel/." "$source_dir/"
make -C "$source_dir" KVER="$kernel" clean
dkms add -m tarakernel -v "$version"
dkms build -m tarakernel -v "$version" -k "$kernel"
dkms install -m tarakernel -v "$version" -k "$kernel"
depmod -a "$kernel"

# The check is diagnostic: a remote DB outage or schema mismatch cannot
# prevent the kernel module and its reporting service from starting.
if systemctl cat taralink.service >/dev/null 2>&1; then
    dropin=/etc/systemd/system/taralink.service.d
    install -d "$dropin"
    printf '[Service]\nExecStartPre=-/usr/bin/perl %s/misc/check_db_version.pl\n' "$repo_root" > "$dropin/20-db-version-check.conf"
    systemctl daemon-reload
fi

/usr/bin/perl "$repo_root/misc/check_db_version.pl" || true
echo "DKMS module for $kernel installed; future kernel builds need matching headers."
echo "Current running module was not replaced. Verify: dkms status -m tarakernel; modinfo -n tarakernel"
