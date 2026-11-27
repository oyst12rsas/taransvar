#!/usr/bin/env bash
# Point /usr/local/sbin/tarasec at a root-owned TaraSec checkout.
#
# The operations agent runs upgrades as root and refuses code a non-root user
# could modify (trusted() in operations_agent.py). A launcher inside a user's
# home checkout therefore blocks every automatic upgrade. When the given
# checkout is not root-owned, this clones its HTTPS origin into
# /opt/tarasec/src and repoints the launcher there. The user checkout is left
# untouched for development.
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
checkout="$(cd "${1:-$(dirname "$0")/..}" && pwd)"
target=${TARASEC_UPGRADE_SOURCE:-/opt/tarasec/src}
launcher=${TARASEC_LAUNCHER:-/usr/local/sbin/tarasec}

root_owned() {
    local path=$1
    while :; do
        [[ $(stat -c '%u' "$path") == 0 ]] || return 1
        (( 8#$(stat -c '%a' "$path") & 8#022 )) && return 1
        [[ $path == / ]] && return 0
        path=$(dirname "$path")
    done
}

if root_owned "$checkout"; then
    source_dir=$checkout
else
    if [[ -d $target/.git ]]; then
        root_owned "$target" || { echo "$target exists but is not root-owned; fix it manually." >&2; exit 1; }
    else
        url=$(git -C "$checkout" remote get-url origin 2>/dev/null || true)
        [[ $url == https://* ]] || { echo "Checkout origin must be an HTTPS URL to create $target (found: ${url:-none})." >&2; exit 1; }
        ( umask 022
          install -d -m 0755 -o root -g root "$(dirname "$target")"
          git clone -q --branch main "$url" "$target" )
        echo "Created root-owned upgrade source $target from $url"
    fi
    source_dir=$target
fi

current=""; [[ -e $launcher ]] && current=$(readlink -f "$launcher")
if [[ $current != "$source_dir/tarasec" ]]; then
    if [[ -e $launcher || -L $launcher ]] && [[ ! -L $launcher ]]; then
        echo "$launcher is a regular file, not a link; leaving it unchanged." >&2; exit 1
    fi
    ln -sfn "$source_dir/tarasec" "$launcher"
    echo "Upgrade launcher: $launcher -> $source_dir/tarasec${current:+ (was $current)}"
fi
