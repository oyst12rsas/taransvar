# Automatic tarakernel rebuilds for Ubuntu kernel updates

DKMS compiles tarakernel for newly installed kernels using their matching
headers. Install it separately on each node **before rebooting into a new
kernel**. It builds and registers the module without starting taralink or
running `compile.pl`; no database migration is performed. Ubuntu recommends
DKMS for modules maintained outside the kernel tree.

On each Ubuntu machine with a generic kernel:

```bash
clear
cd /path/to/taransvar
sudo apt-get install -y dkms build-essential linux-headers-generic
sudo bash misc/install_tarakernel_dkms.sh
dkms status -m tarakernel
modinfo -n tarakernel
```

Run commands on the node being prepared, not on the virtualization host.
For a different kernel flavour, install its matching header metapackage.
Check that the new kernel's headers are available before a reboot. DKMS
automatically builds registered modules when Ubuntu installs a new kernel;
the installer also builds for the currently selected next kernel if that kernel
was installed before DKMS registration. The installed `AUTOINSTALL=yes` configuration also supports its boot-time
autoinstaller. The taralink systemd unit loads the module at startup.

The installer applies reviewed, compatible local database migrations from
version 90 through 98 in order. It also installs a timer that retries after
boot and every five minutes. Each migration updates `setup.dbVersion` only
after its SQL statements succeed. A failure is reported and retried; it does
not stop the separately managed `taralink.service`. No fields or tables are
deleted by these reviewed migrations. Future migrations must be reviewed and
added to the allowed range before they run automatically.

A machine that already installed DKMS before this migration feature can
enable the database updater separately:

```bash
clear
cd /path/to/taransvar
git pull --ff-only
sudo bash misc/install_db_migrations.sh
systemctl status tarasec-db-migrate.service --no-pager
```

The existing read-only version check still runs when `taralink.service`
starts. Run `sudo perl misc/check_db_version.pl` manually to inspect a
mismatch. Inspect migration failures with
`journalctl -u tarasec-db-migrate.service -b`.

The installer registers source version 1.0 once. If it finds an existing
`/usr/src/tarakernel-1.0`, it stops instead of silently replacing registered
source. A future TaraSec module source update needs a deliberate DKMS version
bump and installation of that version. Building a module for a new kernel does
not fix a kernel bug in the module or a module-signing requirement such as
Secure Boot. After reboot, check `systemctl status taralink`,
`lsmod | grep tarakernel`, and `journalctl -u taralink -b`.
