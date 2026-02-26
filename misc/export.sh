#!/bin/bash
SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
#SCRIPT_DIR=$( dirname -- "$( readlink -f -- "$0"; )"; )
echo "Changing to $SCRIPT_DIR"
cd $SCRIPT_DIR

find . -name "*.mod.*" -type f -delete
find . -name "*.mod" -type f -delete
find . -name "*.mod.*" -type f -delete
find . -name "*.o.cmd" -type f -delete
find . -name "*.o" -type f -delete
find . -name "*.symvers" -type f -delete
find . -name "*.order" -type f -delete
#cp /var/www/html/*.* www
#cp /var/www/html/hotspot/*.* hotspot/www
cp /root/wifi/perl/*.* hotspot/perl
cp /home/taransvar/editing/*.* misc
cp backup.sh misc
cp export.sh misc
rm misc/copy.sh

#Standard mariadb-dump includes comments that even create problems when reading the same file...

read -n 1 -s -p "NOTE! this version is not exporting DB... Change if there's changes to the DB"
#mariadb-dump taransvar --no-data --skip-comments --compact | grep -v '^\/\*![0-9]\{5\}.*\/;$' > db/taransvar.sql

#Don't use this.......#mariadb-dump taransvar --no-data --skip-comments > db/taransvar.sql

echo "Before zipping, open dump file and remove remaining comments on top and bottom:"
#echo "nano $SCRIPT_DIR /taransvar.sql"
read -n 1 -s -p "Press a key to generate zip file"

#cp ~/Documents/README misc
rm taransvar.tar.gz
tar cvzf taransvar.tar.gz tarakernel taralink html misc hotspot db
#printf -v snow '%(%Y-%m-%d)T\n' -1
snow=$(date +%y%m%d);
#snow=$(date +%s)
cp taransvar.tar.gz taransvar.$snow.tar.gz



