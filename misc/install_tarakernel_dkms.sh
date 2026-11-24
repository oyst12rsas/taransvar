#!/usr/bin/env bash
# Register the TaraSec kernel module with DKMS. Does not load it or reboot.
set -euo pipefail

# --update   replace an already registered source with this checkout's tarakernel
#            and rebuild it for the running and the next kernel.
# --reload   also load the rebuilt module now (briefly stops taralink).
update=false
reload=false
for arg in "$@"; do
    case "$arg" in
        --update) update=true ;;
        --reload) reload=true ;;
        *) echo "Usage: sudo bash misc/install_tarakernel_dkms.sh [--update] [--reload]" >&2; exit 1 ;;
    esac
done

if (( EUID != 0 )); then
    echo "Run as root: sudo bash misc/install_tarakernel_dkms.sh" >&2
    exit 1
fi

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
kernel="$(uname -r)"
# /vmlinuz may still point at the running kernel while a newer one is pending.
next_kernel="$kernel"
installed_kernels=()
for image in /boot/vmlinuz-*; do
    [[ -f "$image" ]] || continue
    installed_kernels+=("${image##*/vmlinuz-}")
done
if (( ${#installed_kernels[@]} )); then
    newest="$(printf '%s\n' "${installed_kernels[@]}" | sort -V | tail -n 1)"
    if [[ "$(printf '%s\n%s\n' "$kernel" "$newest" | sort -V | tail -n 1)" == "$newest" ]]; then
        next_kernel="$newest"
    fi
fi
version=1.0
source_dir="/usr/src/tarakernel-${version}"

command -v dkms >/dev/null || { echo "Install dkms first" >&2; exit 1; }
command -v make >/dev/null || { echo "Install build-essential first" >&2; exit 1; }
for target in "$kernel" "$next_kernel"; do
    test -f "/lib/modules/${target}/build/Makefile" || {
        echo "Install matching headers for ${target} before continuing" >&2
        exit 1
    }
done

if test -e "$source_dir"; then
    if ! $update; then
        echo "$source_dir already exists. To install this checkout's tarakernel, rerun with --update" >&2
        exit 1
    fi
    dkms remove -m tarakernel -v "$version" --all >/dev/null 2>&1 || true
    rm -rf "$source_dir"
fi

# A module copied in by compile.pl at /lib/modules/<kernel>/tarakernel.ko makes
# DKMS skip its own install for that kernel ("Diff between built and installed").
# Keep it as a backup during the DKMS install; it is restored if DKMS fails.
legacy="/lib/modules/${kernel}/tarakernel.ko"
restore_legacy() {
    if [[ -f "${legacy}.pre-dkms" ]] && ! modinfo -k "$kernel" -n tarakernel >/dev/null 2>&1; then
        mv -f "${legacy}.pre-dkms" "$legacy"
        depmod -a "$kernel"
        echo "DKMS install failed; restored the previous $legacy" >&2
    fi
}
trap restore_legacy EXIT
if [[ -f "$legacy" ]]; then
    mv -f "$legacy" "${legacy}.pre-dkms"
fi

install -d "$source_dir"
cp -a "$repo_root/tarakernel/." "$source_dir/"
make -C "$source_dir" KVER="$kernel" clean
dkms add -m tarakernel -v "$version"
dkms build -m tarakernel -v "$version" -k "$kernel"
dkms install -m tarakernel -v "$version" -k "$kernel"
depmod -a "$kernel"
if [[ "$next_kernel" != "$kernel" ]]; then
    dkms build -m tarakernel -v "$version" -k "$next_kernel"
    dkms install -m tarakernel -v "$version" -k "$next_kernel"
    depmod -a "$next_kernel"
fi

# The check is diagnostic: a remote DB outage or schema mismatch cannot
# prevent the kernel module and its reporting service from starting.
if systemctl cat taralink.service >/dev/null 2>&1; then
    dropin=/etc/systemd/system/taralink.service.d
    install -d "$dropin"
    printf '[Service]\nExecStartPre=-/usr/bin/perl %s/misc/check_db_version.pl\n' "$repo_root" > "$dropin/20-db-version-check.conf"
    systemctl daemon-reload
fi

bash "$repo_root/misc/install_db_migrations.sh"
echo "DKMS module installed for $kernel and selected next kernel $next_kernel."

if ! installed_module="$(modinfo -k "$kernel" -n tarakernel 2>/dev/null)"; then
    echo "No loadable tarakernel module for $kernel after install; running module left in place" >&2
    exit 1
fi
echo "Module for $kernel: $installed_module"

if ! $reload; then
    echo "Current running module was not replaced. Verify: dkms status -m tarakernel; modinfo -n tarakernel"
    echo "To load it now: rerun with --update --reload (briefly stops taralink), or reboot."
    exit 0
fi

# Reload: stop taralink, swap the module, start taralink again (systemd or legacy cron launch).
systemd_taralink=false
if systemctl cat taralink.service >/dev/null 2>&1; then systemd_taralink=true; fi
if $systemd_taralink; then systemctl stop taralink.service; else pkill -x taralink || true; sleep 1; fi
modprobe -r tarakernel 2>/dev/null || true
modprobe tarakernel
if $systemd_taralink; then
    systemctl start taralink.service
elif [[ -x /root/taransvar/taralink ]]; then
    (cd /root/taransvar && setsid nohup /root/taransvar/taralink </dev/null >>/tmp/taralink.log 2>&1 &)
fi
echo "Loaded $(modinfo -n tarakernel) and restarted taralink."
