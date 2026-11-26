#!/usr/bin/env bash

print_ssh_test_command() {
    local port=$1 address=$2 login=$3
    printf '  ssh -o ControlMaster=no -o ControlPath=none -p %s %q\n' "$port" "$login@$address"
}

diagnose_ssh_hardening() {
    if [[ $EUID != 0 ]]; then
        printf 'Run diagnostics with sudo from the open console.\n' >&2
        return 1
    fi
    hostname
    printf '\nNetBird IPv4 addresses\n'
    ip -4 -o addr show dev wt0 2>/dev/null || true
    printf '\nSSH listeners\n'
    ss -ltnp | awk 'NR == 1 || /sshd|ssh-honeypot/'
    printf '\nGoogle SSH policy\n'
    grep -E '^[[:space:]]*SSH_GOOGLE_REOPEN_ENABLED[[:space:]]*=' /etc/tarasec-server-manager.conf || true
    printf '\nApproval worker and timer\n'
    systemctl show -p ActiveState -p Result tarasec-agent-approvals.service tarasec-agent-approvals.timer
    printf '\nGoogle SSH firewall chains\n'
    iptables -nvL TARASEC_SSH_GOOGLE 2>/dev/null || true
    ip6tables -nvL TARASEC_SSH_GOOGLE 2>/dev/null || true
}

if [[ $# -eq 1 && $1 == --diagnose ]]; then
    diagnose_ssh_hardening
    exit $?
fi

resume_ssh_activation() {
    local helper_dir
    helper_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || return 1
    if [[ $EUID != 0 || ! -t 0 || -n ${SSH_CONNECTION:-} || -n ${SSH_TTY:-} ]]; then
        printf 'Resume from the VM or physical console with sudo.\n' >&2
        return 1
    fi
    if [[ ! -r /var/lib/tarasec/ssh-install-choices.json ]]; then
        printf 'Save SSH setup choices with --stage first.\n' >&2
        return 1
    fi
    printf '\nTo resume these saved choices later, run from the checkout:\n'
    printf '  sudo bash misc/ssh_setup_preview.sh --activate\n'
    if [[ ! -f /etc/tarasec/agent-enrollment.json ]]; then
        local owner_email
        owner_email=$(python3 -c 'import json; print(json.load(open("/var/lib/tarasec/ssh-install-choices.json"))["email"])') || return 1
        bash "$helper_dir/install_agent_approvals.sh" --browser-email "$owner_email" || return 1
    fi
    local current_ports requested_port address login port
    address=$(ip -4 -o addr show dev wt0 2>/dev/null | awk '{sub(/\/.*/, "", $4); print $4; exit}')
    if [[ -z $address ]]; then
        address=$(hostname -f) || return 1
        printf '\nNo NetBird IPv4 address detected. The example uses the server hostname.\n'
    fi
    login=${SUDO_USER:-}
    if [[ -z $login || $login == root ]]; then
        login=YOUR_LINUX_USER
    fi
    printf '\nSSH hardening changes how new logins reach this server.\n'
    printf 'Keep this VM or physical console open throughout the process.\n'
    printf 'For guided troubleshooting, you can install Codex CLI or Claude Code on your workstation before continuing.\n'
    printf 'If something goes wrong, run this from the open console to inspect the SSH configuration:\n'
    printf '  sudo bash misc/ssh_setup_preview.sh --diagnose\n'
    current_ports=$(set -o pipefail; sshd -T | awk '/^port / {print $2}') || return 1
    requested_port=$(python3 -c 'import json; print(json.load(open("/var/lib/tarasec/ssh-install-choices.json"))["port"])') || return 1
    printf '\nOpenSSH currently configured on TCP: %s\n' "$current_ports"
    printf 'Requested management port: TCP/%s. The saved settings have not been applied by this wizard yet.\n' "$requested_port"
    printf '\nAfter you enable and approve the first SSH window, use a new terminal on your workstation.\n'
    printf 'Use your Linux login on this server if it differs from the one shown.\n'
    while IFS= read -r port; do
        print_ssh_test_command "$port" "$address" "$login"
    done <<< "$current_ports"
    printf '\nThe wizard will now enable Google-controlled SSH on this server. Keep this console open.\n'
    python3 "$helper_dir/standard_upgrade.py" upgrade --configure-ssh-window || return 1
    printf '\nLocal SSH gate setup is complete. Open or refresh Machine control:\n'
    local machine_name approvals_url
    machine_name=$(python3 -c 'import json; print(json.load(open("/etc/tarasec/agent-enrollment.json"))["nickname"])') || return 1
    approvals_url=$(python3 -c 'import json; print(json.load(open("/etc/tarasec/agent-enrollment.json"))["approval_url"].rsplit("/", 1)[0] + "/")') || return 1
    printf '  %s — %s\n' "$machine_name" "$approvals_url"
    printf 'When Open SSH temporarily becomes available, approve a window with TOTP.\n'
    printf 'Test a new login on the current port before applying the saved port with automatic rollback.\n'
    python3 "$helper_dir/ssh_port_setup.py" --from-install || return 1
    printf '\nAfter approving a fresh SSH window, test the new port from your workstation:\n'
    print_ssh_test_command "$requested_port" "$address" "$login"
}

if [[ $# -eq 1 && $1 == --activate ]]; then
    resume_ssh_activation
    exit $?
fi

mode=${1:---preview}
case "$mode" in
    --preview|--stage) ;;
    *) exit 2 ;;
esac
if [[ $# -gt 2 || ( $# -eq 2 && ( $mode != --stage || $2 != --configure ) ) ]]; then
    printf 'Usage: %s [--preview | --stage [--configure]]\n' "$0" >&2
    exit 2
fi
if [[ $mode == --stage ]]; then
    [[ $EUID == 0 ]] || exit 1
    command -v python3 >/dev/null || { printf 'Install Python 3 before staging SSH settings.\n' >&2; exit 1; }
fi

if [[ ${2:-} != --configure && -t 1 && -n ${TERM:-} ]]; then
    clear
fi

printf 'TaraSec · SSH protection on %s\n\n' "$(hostname)"
if [[ ${2:-} != --configure ]]; then
    printf '  1) Configure SSH protection\n'
    printf '  2) Skip SSH protection for now\n\n'

    read -r -p 'Choose [1/2]: ' choice || exit 1
    case "$choice" in
        1) ;;
        2)
            printf '\nSSH protection setup skipped.\n'
            exit 0
            ;;
        *)
            printf '\nChoose 1 or 2.\n' >&2
            exit 1
            ;;
    esac

fi
printf '\n'
printf 'You can set up Google Authenticator later, before approving an SSH opening.\n'
read -r -p 'Google account email for SSH approvals: ' google_email || exit 1
read -r -p 'Management SSH port [4810]: ' ssh_port || exit 1

printf '\nAllowed sources\n'
printf 'Enter the IP addresses or subnets you use to manage this node, one per line.\n'
printf '  TaraSec NetBird: 100.68.0.0/16\n'
printf '  TaraSec LAN VPN: 10.0.0.0/8\n'
printf '  Example for one NetBird device: 100.68.10.7/32\n'
printf '\n'
printf 'If you do not know your NetBird IP yet, you can add it later.\n'
allowed_sources=()
read -r -p 'Allowed IP or subnet (Enter for no source-IP restriction): ' source_ip || exit 1
if [[ -n $source_ip ]]; then
    allowed_sources+=("$source_ip")
    while read -r -p 'Additional allowed IP or subnet (Enter to finish): ' source_ip; do
        [[ -n $source_ip ]] || break
        allowed_sources+=("$source_ip")
    done
fi

printf '\nChoose the TCP port range for the SSH honeypot.\n'
printf 'The management SSH port can be inside this range, for example 4810 within 1000-5000.\n'
read -r -p 'SSH honeypot port range [1000-5000]: ' honeypot_range || exit 1

printf '\nSSH setup choices\n'
printf '  Google account:      %s\n' "$google_email"
printf '  Management SSH port: %s\n' "${ssh_port:-4810}"
printf '  Honeypot TCP ports:  %s\n' "${honeypot_range:-1000-5000}"
printf '  Allowed sources:\n'
if (( ${#allowed_sources[@]} )); then
    printf '    - %s\n' "${allowed_sources[@]}"
else
    printf '    No source-IP restriction\n'
fi
if [[ $mode == --preview ]]; then
    printf '\nPreview only. No settings applied.\n'
    exit 0
fi

read -r -p 'Save these choices for SSH activation? [Y/n]: ' confirm || exit 1
[[ -z $confirm || $confirm == [yY] || $confirm == [yY][eE][sS] ]] || exit 1

python3 - "$google_email" "${ssh_port:-4810}" "${honeypot_range:-1000-5000}" "${allowed_sources[@]}" <<'PY'
import ipaddress
import json
import os
from pathlib import Path
import re
import sys
import tempfile

email, port, spec, *sources = sys.argv[1:]
try:
    if not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", email):
        raise ValueError("Enter a valid Google account email.")
    if not re.fullmatch(r"[0-9]{1,5}", port) or not 1 <= int(port) <= 65535 or int(port) == 22:
        raise ValueError("Choose a management port from 1 to 65535. TCP/22 is reserved for the honeypot.")
    limits = re.fullmatch(r"([0-9]{1,5})-([0-9]{1,5})", spec)
    if limits is None or not 1 <= int(limits[1]) <= int(limits[2]) <= 65535:
        raise ValueError("Enter a valid honeypot range, for example 1000-5000.")
    networks = [ipaddress.ip_network(source, strict=False) for source in sources]
    if len(networks) > 64 or any(network.prefixlen == 0 for network in networks):
        raise ValueError("Use up to 64 allowed sources. Leave the list blank for no source-IP restriction.")
except ValueError as error:
    raise SystemExit(str(error))

directory = Path("/var/lib/tarasec")
directory.mkdir(mode=0o755, parents=True, exist_ok=True)
with tempfile.NamedTemporaryFile(mode="w", dir=directory, delete=False) as pending:
    try:
        json.dump({"email": email, "port": int(port), "range": spec,
                   "sources": [str(network) for network in networks]}, pending)
        pending.close()
        os.replace(pending.name, directory / "ssh-install-choices.json")
    finally:
        Path(pending.name).unlink(missing_ok=True)
PY
result=$?
(( result == 0 )) || exit "$result"
printf '\nSSH choices saved. The management port and honeypot settings have not been applied yet.\n'
if [[ -t 0 ]]; then
    resume_ssh_activation
else
    printf 'To continue from the VM or physical console, run from the checkout:\n'
    printf '  sudo bash misc/ssh_setup_preview.sh --activate\n'
fi
