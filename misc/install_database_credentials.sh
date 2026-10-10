#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root' >&2; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
command -v python3 >/dev/null || { echo 'Install python3 first' >&2; exit 1; }
install -d -m 0755 /usr/local/lib/tarasec
install -m 0644 "$root/TaraSecDB.pm" /usr/local/lib/tarasec/TaraSecDB.pm
install -m 0755 "$root/provision_local_database.py" /usr/local/lib/tarasec/provision_local_database.py
python3 /usr/local/lib/tarasec/provision_local_database.py
