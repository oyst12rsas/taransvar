cd ~/programming
rm backup.tar.gz
find . -name "*.mod.*" -type f -delete
find . -name "*.mod" -type f -delete
find . -name "*.mod.*" -type f -delete
find . -name "*.o.cmd" -type f -delete
find . -name "*.o" -type f -delete
find . -name "*.symvers" -type f -delete
find . -name "*.order" -type f -delete
cp /var/www/html/*.* www
cp backup.sh misc
#cp ~/Documents/README misc
tar cvzf backup.tar.gz taralink tarakernel www misc hotspot
SNOW=$(date +%s)
sudo gpg -c --cipher-algo AES256 backup.tar.gz
mv backup.tar.gz.gpg backup.$SNOW.tar.gz.gpg



