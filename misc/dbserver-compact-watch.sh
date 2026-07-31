#!/bin/bash
#sudo nano /usr/local/sbin/dbserver-compact-watch.sh

LOG="/var/log/dbserver-compact-watch.log"

printf '%s\n' \
    "timestamp load1 mem_available_mb mariadb_mb netbird_mb conntrack top_cpu top_thread" \
    >> "$LOG"

while sleep 1; do
    timestamp=$(date '+%F_%T')

    load1=$(awk '{print $1}' /proc/loadavg)

    mem_available_mb=$(
        awk '/MemAvailable:/ {printf "%.0f", $2 / 1024}' /proc/meminfo
    )

    mariadb_mb=$(
        ps -C mariadbd -o rss= 2>/dev/null |
        awk '{sum += $1} END {printf "%.1f", sum / 1024}'
    )
    [[ -n "$mariadb_mb" ]] || mariadb_mb=0

    netbird_mb=$(
        ps -C netbird -o rss= 2>/dev/null |
        awk '{sum += $1} END {printf "%.1f", sum / 1024}'
    )
    [[ -n "$netbird_mb" ]] || netbird_mb=0

    if [[ -r /proc/sys/net/netfilter/nf_conntrack_count ]]; then
        conntrack=$(cat /proc/sys/net/netfilter/nf_conntrack_count)
    else
        conntrack=0
    fi

    read -r top_cpu top_thread < <(
        ps -eLo pcpu=,comm= --sort=-pcpu |
        awk 'NR == 1 {print $1, $2}'
    )

    printf '%s %s %s %s %s %s %s %s\n' \
        "$timestamp" \
        "$load1" \
        "$mem_available_mb" \
        "$mariadb_mb" \
        "$netbird_mb" \
        "$conntrack" \
        "$top_cpu" \
        "$top_thread" \
        >> "$LOG"
done

#sudo chmod +x /usr/local/sbin/dbserver-compact-watch.sh
#sudo nohup dbserver-compact-watcher.sh >/var/log/dbserver-compact-watch-launch.log 2>&1 &

#ls -lh /var/log/dbserver-compact-watch.log
#tail -n 10 /var/log/dbserver-compact-watch.log

#This one works: root@dbserver:/home/oystein/taransvar/misc# sudo nohup bash dbserver-compact-watcher.sh 2>&1 &
