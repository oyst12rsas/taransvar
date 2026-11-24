#!/bin/bash
# TaraSec-specific audit check for ai_operator_access.sh.
# Prints "LEVEL message" lines. Environment: AI_ACCESS_ROOT, AI_USER, SINCE
# (epoch of the grant, may be empty) and GRANT_ACTIVE (true/false).
set -uo pipefail
R="${AI_ACCESS_ROOT:-}"
SINCE="${SINCE:-}"
p() { printf '%s%s' "$R" "$1"; }

if [[ ! -d "$(p /etc/tarasec)" ]]; then
    echo "INFO   TaraSec not installed (/etc/tarasec absent); TaraSec checks skipped"
    exit 0
fi

changed() { [[ -n $SINCE && -e "$(p "$1")" && $(stat -c %Y "$(p "$1")") -gt $SINCE ]]; }
setting() { sed -n "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*[\"']\{0,1\}\([^\"']*\)[\"']\{0,1\}[[:space:]]*$/\1/p" "$(p "$1")" 2>/dev/null | tail -n 1; }

# Google managers can approve SSH openings and manage the node from the app.
access=/etc/tarasec/access.conf
if [[ -f "$(p $access)" ]]; then
    hashes=$(setting $access MANAGER_EMAIL_SHA256)
    count=$(tr ',' '\n' <<< "$hashes" | grep -c '[0-9a-f]\{64\}' || true)
    echo "INFO   TaraSec Google managers: $count (hashes in $access)"
    changed $access && echo "REVIEW TaraSec manager list changed during the grant; confirm every manager is yours, then: sudo python3 misc/enroll_google_manager.py --sync"
fi

# The approval service decides which SSH openings and operations are approved.
manager=/etc/tarasec-server-manager.conf
if [[ -f "$(p $manager)" ]]; then
    url=$(setting $manager AGENT_APPROVAL_URL)
    if [[ -n $url && $url != https://tarasec.org/* ]]; then
        echo "REVIEW TaraSec approval service is not tarasec.org: $url ($manager)"
    fi
    changed $manager && echo "REVIEW $manager changed during the grant (approval service settings)"
fi

declare -A hint=(
    [/etc/tarasec/agent-node.token]="node credential replaced or rewritten"
    [/etc/tarasec/agent-enrollment.json]="registration receipt changed"
    [/etc/tarasec/gatekeeper-google.php]="Gatekeeper Google sign-in settings changed"
    [/etc/tarasecfw.conf]="TaraSec firewall settings changed"
    [/etc/netbird/config.json]="NetBird management settings changed"
)
for f in "${!hint[@]}"; do
    changed "$f" && echo "REVIEW $f: ${hint[$f]} during the grant"
done
if [[ -n $SINCE ]]; then
    find "$(p /etc/tarasec)" -xdev -type f -newermt "@$SINCE" 2>/dev/null | while IFS= read -r f; do
        f=${f#$R}
        [[ -n ${hint[$f]:-} || $f == "$access" || $f == */*.before-enrollment-* ]] && continue
        echo "REVIEW changed TaraSec file: $f"
    done
fi

# NetBird lets other peers reach this node over the overlay.
nb="$(p /etc/netbird/config.json)"
if [[ -f $nb ]] && command -v python3 >/dev/null; then
    mgmt=$(python3 -I -c 'import json,sys
u=json.load(open(sys.argv[1])).get("ManagementURL") or {}
print(u.get("Host","") if isinstance(u,dict) else u)' "$nb" 2>/dev/null)
    [[ -n $mgmt ]] && echo "INFO   NetBird management server: $mgmt"
fi
exit 0
