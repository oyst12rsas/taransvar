#!/bin/bash
#Taransvar Cyber Security
#To install: sudo bash install.sh

if [ "$(id -u)" -ne 0 ]; then
        printf 'This script must be run by root.\nStart with: sudo bash install.sh\n' >&2
        exit 1
fi

#Uses these because some scripts are also run from crontab (can't relate to current user while installing)
mkdir -p /root/setup/log

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
echo "$SCRIPT_DIR"
cd "$SCRIPT_DIR" || exit 1
printf "\nInstalling Taransvar Cyber Solution\n"
read -t 5 -p "Continuing in 5 seconds..."
printf "\n"
# Self-register this server at low trust (no secret). An optional Google email
# (prompted, or TARASEC_OWNER_EMAIL) enables Login with Google; it can be added
# later. A failed registration is retried later and does not stop installation.
if [[ ! -f /etc/tarasec/agent-enrollment.json ]]; then
    if ! command -v python3 >/dev/null; then
        apt-get update && apt-get install -y python3 ca-certificates || exit 1
    fi
    if [[ -z "${TARASEC_OWNER_EMAIL:-}" && -t 0 ]]; then
        printf "Optional: a Google email lets that account sign in to manage this server.\n"
        read -r -p "Google email for Login with Google (Enter to skip, can be added later): " TARASEC_OWNER_EMAIL
    fi
    export TARASEC_OWNER_EMAIL
    # Later retries (sub-installers, approvals step) reuse the email without prompting.
    export TARASEC_SELF_REGISTER_TRIED=1
    python3 "$SCRIPT_DIR/misc/enroll_google_manager.py" --auto || true
fi
printf "Installing required linux packages...\n"

apt-get update -y
apt-get install -y debconf-utils

if [ -d /var/lib/mysql ] && [ "$(ls -A /var/lib/mysql 2>/dev/null)" ]; then
    echo "Existing database data detected — skipping MariaDB install"
else
    apt-get install -y mariadb-server
fi

apt-get install -y apache2 perl libdbd-mysql-perl libmariadb-dev libmnl-dev
apt-get install -y php libapache2-mod-php php-mysql php-curl php-mbstring
apt-get install -y gcc make pkg-config curl libcurl4-openssl-dev libcjson-dev dhcpdump net-tools conntrack
apt-get install -y libdbi-perl libdbd-mysql-perl libjson-perl conntrack dhcpdump isc-dhcp-server
apt-get install -y whois iptables ipset python3 logrotate rsync
python3 "$SCRIPT_DIR/misc/configure_log_retention.py"

echo "wireshark-common wireshark-common/install-setuid boolean false" | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y tshark

# Hotspot packages are installed by hotspot/distro/install.sh only if the owner opts in.

#260307 - install for get() used in lib_cron.pm
apt-get install -y libwww-perl

apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get upgrade -y

printf "Installing the database\n"
printf "The install routine may now generate some error message while trying to create DB user.\n"
mysql -e "CREATE DATABASE IF NOT EXISTS taransvar;"
# Import the schema only into an empty database, so re-running the installer
# neither fails on existing tables nor duplicates the initial setup row.
TABLE_COUNT=$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='taransvar';" 2>/dev/null)

(
    cd misc
    perl createUsers.pl
)

if [ $? -eq 0 ]; then
    printf "Able to create users...\n"
else
    read -n 1 -s -p "Unable to create users... "
    printf "\n"
fi

printf "Now checking if user successfully created.. This sould not generate error..\n"
(
    cd misc
    perl connect.pl
)

if [ $? -eq 0 ]; then
    printf "Successfully connected (database and user seems correct installed)..\n"
else
    read -n 1 -s -p "Unable to connect to DB (probably because failed to create user. (please check)... Press a key to continue (or Ctrl-C to abort)."
    echo ""
fi

# Use crontab(1) so the spool file gets the owner/mode cron requires (0600, group crontab).
CURRENT_CRONTAB=$(crontab -l 2>/dev/null)
NEW_CRONTAB="$CURRENT_CRONTAB"
if ! grep -q crontasks <<< "$CURRENT_CRONTAB" ; then
  NEW_CRONTAB+=$'\n'"* * * * * /usr/bin/perl /root/taransvar/perl/crontasks.pl cron"
fi
if ! grep -q startup.pl <<< "$CURRENT_CRONTAB" ; then
  NEW_CRONTAB+=$'\n'"@reboot /usr/bin/perl /root/taransvar/perl/startup.pl > /root/setup/log/startup.txt 2>&1"
fi
if [[ "$NEW_CRONTAB" != "$CURRENT_CRONTAB" ]]; then
  printf '%s\n' "${NEW_CRONTAB#$'\n'}" | crontab -
fi

if [[ "$TABLE_COUNT" == "0" ]]; then
    mariadb taransvar < db/taransvar.sql
    mariadb taransvar < db/postcreate.sql
    # The base schema is stamped as an older version; apply the remaining
    # migrations now so compile.pl and startup.pl see the current schema.
    python3 "$SCRIPT_DIR/misc/migrate_new_db.py" || {
        read -n 1 -s -p "Database migration failed (see above). Press a key to continue (or Ctrl-C to abort)."
        echo ""
    }
elif [[ -z "$TABLE_COUNT" ]]; then
    read -n 1 -s -p "Unable to query database taransvar — schema NOT imported. Press a key to continue (or Ctrl-C to abort)."
    echo ""
else
    echo "Database taransvar already has tables ($TABLE_COUNT) — skipping schema import."
fi

echo "Database created. Copying files/"
mkdir -p /root/taransvar/perl
rsync -a --exclude '.git' misc/ /root/taransvar/perl/

echo "Copying (rsync) html files..."
rsync -a --exclude '.git' html/ /var/www/html/

printf "\nWe're now about to do network installation.\n\nIf you know how to set this up youself, then you probably want to skip this.\n\n"
read -p "Do you want to run network installation script? (y/n): " answer

if [[ "$answer" == "y" || "$answer" == "Y" ]]; then
    echo "Running network installation..."
    sleep 5
    (
        cd misc
        perl setup_network.pl
    )
else
    echo "Skipping network installation."
fi

(
    cd misc
    perl compile.pl install
)

if [ $? -eq 0 ]; then
    printf "Successfully compiled..\n"
    cp taralink/taralink /root/taransvar
else
    printf "******* ERROR ****** Could not compile taralink...\n"
    read -n 1 -s -p "Ctrl-C to break or press any key to continue"
fi

printf "**** Gatekeeper is now installed ****\n\n"
printf "TaraSec can also use a spare Wi-Fi adapter as a protected hotspot.\n"
printf "The active Internet/uplink interface will not be converted into the hotspot.\n\n"
read -p "Do you want this computer to provide TaraSec Wi-Fi/hotspot access? [y/N]: " answer

case "$answer" in
    [yY][eE][sS]|[yY])
        echo "Installing TaraSec hotspot..."
        # This is an explicit owner opt-in from the general installer. The
        # hotspot-specific installer therefore goes straight to hotspot setup
        # and only asks about an optional test subscriber/quota.
        TARASEC_HOTSPOT_REQUESTED=1 bash "$SCRIPT_DIR/hotspot/distro/install.sh"
        ;;
    *)
        echo "Skipping hotspot installation."
        ;;
esac

(
    cd misc
    perl startup.pl
)

# Deploy the encrypted management network, then the enrolled service/grants.
bash "$SCRIPT_DIR/misc/install_netbird_management.sh" || exit 1
if [[ -f /etc/tarasec/agent-enrollment.json ]]; then
    bash "$SCRIPT_DIR/misc/install_agent_approvals.sh" --sync || exit 1
else
    # Retries low-trust self-registration (deferred is non-fatal), then installs the services.
    bash "$SCRIPT_DIR/misc/install_agent_approvals.sh" || exit 1
    [[ -f /etc/tarasec/agent-enrollment.json ]] ||
        echo "Server not yet registered with TaraSec. Retry: sudo python3 $SCRIPT_DIR/misc/enroll_google_manager.py --auto"
fi

echo "Next: review and verify your node security at https://tarasec.org/safety/#deployment"
echo "Hardening priorities: https://tarasec.org/safety/#hardening-priorities"
echo "Ask your AI assistant to check the next missing control, test it and fix in-scope routine failures."

# Keep upgrade orchestration in this checkout; future changes use one command.
if [[ ! -e /usr/local/sbin/tarasec && ! -L /usr/local/sbin/tarasec ]]; then
    ln -s "$SCRIPT_DIR/tarasec" /usr/local/sbin/tarasec
fi
echo 'Future updates: sudo tarasec upgrade'

