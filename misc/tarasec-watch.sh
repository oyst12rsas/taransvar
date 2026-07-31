#!/bin/bash
#sudo nano taransvar/misc/tarasec-watch.sh

#RUn in background:
#sudo su
#cd <user>/taransvar/misc
#sudo bash tarasec-watch.sh >/var/log/tarasec-watch-launch.log 2>&1 &
#Check that running: ps aux | grep tarasec-watch

INTERVAL=10
LOG_DIR="/var/log/tarasec-watch"

mkdir -p "$LOG_DIR"
chmod 700 "$LOG_DIR"

while true; do
    timestamp=$(date '+%Y%m%d-%H%M%S')
    logfile="$LOG_DIR/snapshot-$timestamp.log"

    {
        echo "============================================================"
        echo "TIME: $(date --iso-8601=seconds)"
        echo "HOST: $(hostname)"
        echo "============================================================"

        echo
        echo "--- UPTIME / LOAD ---"
        uptime
        cat /proc/loadavg

        echo
        echo "--- TOP CPU THREADS ---"
        ps -eLo \
            pid,ppid,tid,psr,stat,pri,ni,pcpu,pmem,etime,wchan:24,comm,args \
            --sort=-pcpu |
            head -n 41

        echo
        echo "--- MEMORY ---"
        free -h

        echo
        echo "--- VMSTAT ---"
        vmstat 1 2 | tail -n 2

        echo
        echo "--- SOFTIRQS ---"
        cat /proc/softirqs

        echo
        echo "--- INTERRUPTS ---"
        cat /proc/interrupts

        echo
        echo "--- SOCKET SUMMARY ---"
        ss -s

        echo
        echo "--- CONNTRACK ---"
        if [[ -r /proc/sys/net/netfilter/nf_conntrack_count ]]; then
            printf 'count: '
            cat /proc/sys/net/netfilter/nf_conntrack_count

            printf 'maximum: '
            cat /proc/sys/net/netfilter/nf_conntrack_max
        fi

        echo
        echo "--- TARAKERNEL ---"
        lsmod | grep -E '^tarakernel\b' ||
            echo "tarakernel not loaded"

        echo
        echo "--- RECENT KERNEL MESSAGES ---"
        dmesg --ctime 2>/dev/null | tail -n 100

    } >"$logfile" 2>&1

    # Keep only the newest 1000 snapshots.
    find "$LOG_DIR" \
        -maxdepth 1 \
        -type f \
        -name 'snapshot-*.log' \
        -printf '%T@ %p\n' |
        sort -rn |
        tail -n +1001 |
        cut -d' ' -f2- |
        xargs -r rm --

    sleep "$INTERVAL"
done