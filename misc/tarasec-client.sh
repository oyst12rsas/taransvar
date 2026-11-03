#!/usr/bin/env bash
# Standalone user client. No TaraSec checkout or server code required.
set -euo pipefail
umask 077
config=$HOME/.config/tarasec-client
if [[ -f $config/path ]]; then IFS= read -r saved_path < "$config/path"; export PATH="$saved_path"; fi
service=https://tarasec.org/ops/agent/
key=$HOME/.ssh/id_ed25519_tarasec
install=false
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
: > "$tmp/gateways"
fail() { printf 'TaraSec client: %s\n' "$*" >&2; exit 1; }
while (($#)); do
    case "$1" in
        --gateway|--service|--key)
            (($# >= 2)) || fail "Missing value for $1"
            case "$1" in
                --gateway) printf '%s\n' "$2" >> "$tmp/gateways" ;;
                --service) service=$2 ;; --key) key=$2 ;;
            esac
            shift 2 ;;
        --install) install=true; shift ;;
        --once) shift ;;
        -h|--help)
            printf '%s\n' 'Install once: bash tarasec-client.sh --install --gateway http://GATEWAY' \
                'Poll saved gateways: tarasec-client.sh --once' \
                'Optional: repeat --gateway; --service HTTPS_URL; --key PRIVATE_KEY'
            exit 0 ;;
        *) fail "Unknown option: $1" ;;
    esac
done
for tool in curl jq ssh-keygen; do
    command -v "$tool" >/dev/null || fail "Install $tool. Linux: curl jq openssh-client; Termux: curl jq openssh cronie."
done
if [[ ! -s $tmp/gateways ]]; then
    [[ -r $config/settings.json ]] || fail 'Install once with --gateway to save your gateway addresses.'
    jq -er '.gateways[]' "$config/settings.json" > "$tmp/gateways"
    service=$(jq -er '.service' "$config/settings.json")
    key=$(jq -er '.key' "$config/settings.json")
fi
while IFS= read -r gateway; do
    jq -en --arg url "$gateway" '$url | test("^https?://[A-Za-z0-9.:[\\]-]+/?$")' >/dev/null ||
        fail 'Invalid gateway address; use its NetBird management address.'
done < "$tmp/gateways"
jq -en --arg url "$service" '$url | test("^https://[A-Za-z0-9.-]+(:[0-9]+)?/ops/agent/$")' >/dev/null ||
    fail 'Use an HTTPS /ops/agent/ approval service.'
if $install; then
    command -v crontab >/dev/null || fail 'Install cron first. Termux: pkg install cronie, then run crond.'
    [[ -f $0 ]] || fail 'Download this script to a file before installing it.'
    mkdir -p -m 700 "$config" "$HOME/.local/bin"
    installed=$HOME/.local/bin/tarasec-client
    cp -- "$0" "$installed.new"; chmod 700 "$installed.new"; mv -f -- "$installed.new" "$installed"
    printf '%s\n' "$PATH" > "$config/path"
    jq -Rn --arg service "$service" --arg key "$key" \
        '{service:$service,key:$key,gateways:[inputs]}' < "$tmp/gateways" > "$config/settings.json"
    crontab -l > "$tmp/crontab" 2>/dev/null || :
    sed '/# tarasec-ssh-client$/d' "$tmp/crontab" > "$tmp/new-crontab"
    bash_path=$(command -v bash)
    quoted_bash=$(printf '%s' "$bash_path" | jq -Rrs @sh)
    quoted_client=$(printf '%s' "$installed" | jq -Rrs @sh)
    quoted_log=$(printf '%s' "$config/client.log" | jq -Rrs @sh)
    printf '*/5 * * * * %s %s --once >> %s 2>&1 # tarasec-ssh-client\n' "$quoted_bash" "$quoted_client" "$quoted_log" >> "$tmp/new-crontab"
    crontab "$tmp/new-crontab"
    printf '%s\n' 'Client installed for this user. It checks every five minutes; requests start in the app.' \
        'A dedicated local Ed25519 key is generated only when a request arrives. Final approval remains at TaraSec Ops.'
    exit 0
fi
post() {
    local action=$1 input=$2 output=$3
    curl --silent --show-error --fail --globoff --connect-timeout 5 --max-time 20 --max-filesize 16384 \
        --max-redirs 0 --proto '=https' --header 'Content-Type: application/json' \
        --data-binary "@$input" --output "$output" --url "$service""ssh-device.php?action=$action" ||
        fail 'Approval service unavailable; the next scheduled check will retry.'
    jq -e '.ok == true' "$output" >/dev/null || fail 'Approval service rejected the request.'
}
client_key() {
    if [[ ! -e $key ]]; then
        [[ ! -L $key ]] || fail 'Refusing a dangling private-key symlink.'
        mkdir -p -m 700 -- "$(dirname -- "$key")"
        ssh-keygen -q -t ed25519 -N '' -f "$key" </dev/null
    fi
    ssh-keygen -y -f "$key" </dev/null > "$tmp/public" ||
        fail 'Cannot unlock this key unattended. Use the dedicated client key or configure unattended key access.'
    read -r kind blob _ < "$tmp/public"
    [[ $kind == ssh-ed25519 ]] || fail 'Use an Ed25519 key; existing keys are never overwritten.'
    printf '%s %s\n' "$kind" "$blob" > "$tmp/public"
}
while IFS= read -r gateway; do
    url=$(printf '%s' "$gateway" | sed 's:/$::')
    # Identity comes from the gateway's actual overlay connection, not this body.
    if ! curl --silent --show-error --fail --globoff --connect-timeout 5 --max-time 20 --max-filesize 16384 \
        --max-redirs 0 --proto '=http,https' --request POST --data '{}' \
        --output "$tmp/poll" --url "$url/script/sshClientQueue.php"; then
        printf 'TaraSec client: gateway %s unavailable; will retry next check.\n' "$gateway" >&2
        continue
    fi
    jq -e --arg service "$service" '.ok == true and .approval_service == $service and (.server_ref | test("^[a-f0-9]{64}$"))' "$tmp/poll" >/dev/null ||
        fail 'Gateway queue does not match the configured approval service.'
    if jq -e '.job == null' "$tmp/poll" >/dev/null; then continue; fi
    if jq -e '.job.needs_key == true' "$tmp/poll" >/dev/null; then
        ref=$(jq -r '.server_ref' "$tmp/poll")
        client_key
        label=$(uname -n)
        jq -n --rawfile public "$tmp/public" --arg label "$label" \
            '{public_key:($public | rtrimstr("\n")),device_name:$label}' > "$tmp/claim"
        curl --silent --show-error --fail --globoff --connect-timeout 5 --max-time 20 --max-filesize 16384 \
            --max-redirs 0 --proto '=http,https' --header 'Content-Type: application/json' \
            --data-binary "@$tmp/claim" --output "$tmp/poll" --url "$url/script/sshClientQueue.php" ||
            fail 'Could not collect this device request; will retry next check.'
        jq -e --arg service "$service" --arg ref "$ref" \
            '.ok == true and .approval_service == $service and .server_ref == $ref' "$tmp/poll" >/dev/null ||
            fail 'Gateway changed the server identity while collecting the request.'
        if jq -e '.job == null' "$tmp/poll" >/dev/null; then continue; fi
    fi
    jq -e '.job.server_ref == .server_ref and (.job.pairing_token | test("^[a-f0-9]{64}$")) and
        (.job.ssh_user | test("^[a-z_][a-z0-9_-]{0,31}$"))' "$tmp/poll" >/dev/null || fail 'Invalid queued request.'
    client_key
    jq '{pairing_token:.job.pairing_token}' "$tmp/poll" > "$tmp/request"
    post challenge "$tmp/request" "$tmp/challenge"
    jq -e --slurpfile poll "$tmp/poll" '.server_ref == $poll[0].server_ref and .ssh_user == $poll[0].job.ssh_user and
        .device_name == $poll[0].job.device_name and (.message | type == "string" and length <= 4096)' "$tmp/challenge" >/dev/null ||
        fail 'Challenge does not match the queued target and Linux username.'
    jq -rj '.message' "$tmp/challenge" > "$tmp/message"
    rm -f -- "$tmp/message.sig"
    ssh-keygen -Y sign -f "$key" -n tarasec-ssh-enrollment "$tmp/message" </dev/null
    jq -n --slurpfile poll "$tmp/poll" --rawfile public "$tmp/public" --rawfile signature "$tmp/message.sig" \
        '{pairing_token:$poll[0].job.pairing_token,public_key:($public | rtrimstr("\n")),signature:$signature}' > "$tmp/submit"
    post submit "$tmp/submit" "$tmp/result"
    read -r _ fingerprint _ < <(ssh-keygen -lf "$tmp/public" -E sha256)
    jq -e --arg fingerprint "$fingerprint" --slurpfile poll "$tmp/poll" \
        '.server_ref == $poll[0].server_ref and .fingerprint == $fingerprint' "$tmp/result" >/dev/null ||
        fail 'Registration response does not match this device key.'
    printf 'Client key fingerprint: %s\n' "$fingerprint"
    printf 'Key request submitted for %s. Finalize at %s; installation requires approval.\n' "$gateway" "$service"
done < "$tmp/gateways"

