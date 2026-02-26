
if [ "$(id -u)" -ne 0 ]; then
        echo 'This script must be run by root.\nStart with: sudo bash uninstall.sh' >&2
        exit 1
fi

echo "NOTE! This will delete the Taransvar system from your computer.\n"
read -n 1 -s -p "Press Ctrl-C to break or any key to coontinue"

mysql -e "drop user scriptUsrAces3f3;"
mysql -e "drop user scriptUsrAces3f3@localhost;"
mysql -e "drop database taransvar;"

rm -rf /var/www/html
mkdir /var/www/html

echo "" > /var/spool/cron/crontabs/root
service cron reload

