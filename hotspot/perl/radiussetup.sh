


sed -i "s/	files/#	files/" /etc/freeradius/sites-enabled/default
sed -i "s/#	sql/	sql/" /etc/freeradius/sites-enabled/default
sed -i "s/#		sql/		sql/" /etc/freeradius/sites-enabled/default

sed -i "s/	files/#	files/" /etc/freeradius/sites-enabled/inner-tunnel
sed -i "s/#	sql/	sql/" /etc/freeradius/sites-enabled/inner-tunnel
sed -i "s/#		sql/		sql/" /etc/freeradius/sites-enabled/inner-tunnel

sed -i "s/#	$INCLUDE sql.conf/	$INCLUDE sql.conf/" /etc/freeradius/radiusd.conf

sed -i 's/login = "radius"/login = "rad4921"/' /etc/freeradius/sql.conf
sed -i 's/password = "radpass"/password = "radpw8234"/' /etc/freeradius/sql.conf
sed -i 's/radius_db = "radius"/radius_db = "taransvar"/' /etc/freeradius/sql.conf
sed -i 's/# read_groups = yes/read_groups = yes/' /etc/freeradius/sql.conf
sed -i 's/#readclients = yes/readclients = yes/' /etc/freeradius/sql.conf

