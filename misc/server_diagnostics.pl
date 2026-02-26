 #Server diagnostics
 
 Apache didn't work anymore... 
 sudo systemctl status apache2 gave this message:
  /etc/apache2/apache2.conf: Syntax error on line 3 of /etc/apache2/mods-enabled/php8.1.load: Cannot load /usr/lib/apache2/modules/libphp8.1.so into server
  Problem was configuration said PHP8.1 was in use, but only PHP8.3 was installed... 
  List installed php packages:
  sudo dpkg --list | grep ' php[0-9]\.[0-9] '
  Solution:
  sudo a2dismod php8.1
  sudo a2enmod php8.3
  systemctl restart apache2
  
