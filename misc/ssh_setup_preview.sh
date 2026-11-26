#!/usr/bin/env bash

if [[ -t 1 && -n ${TERM:-} ]]; then
    clear
fi

printf 'TaraSec · SSH protection on %s\n\n' "$(hostname)"
printf '  1) Configure SSH protection\n'
printf '  2) Skip SSH protection for now\n\n'

read -r -p 'Choose [1/2]: ' choice || exit 0
case "$choice" in
    1) ;;
    2)
        printf '\nSSH protection skipped in this preview.\n'
        exit 0
        ;;
    *)
        printf '\nChoose 1 or 2.\n' >&2
        exit 1
        ;;
esac

printf '\n'
printf 'You can set up Google Authenticator later, before approving an SSH opening.\n'
read -r -p 'Google account email for SSH approvals: ' google_email || exit 0
read -r -p 'Management SSH port [22]: ' ssh_port || exit 0

printf '\nAllowed sources\n'
printf 'Enter the IP addresses or subnets you use to manage this node, one per line.\n'
printf '  TaraSec NetBird: 100.68.0.0/16\n'
printf '  TaraSec LAN VPN: 10.0.0.0/8\n'
printf '  Example for one NetBird device: 100.68.10.7/32\n'
printf '\n'
printf 'If you do not know your NetBird IP yet, you can add it later.\n'
allowed_sources=()
read -r -p 'Allowed IP or subnet (Enter for no source-IP restriction): ' source_ip || exit 0
if [[ -n $source_ip ]]; then
    allowed_sources+=("$source_ip")
    while read -r -p 'Additional allowed IP or subnet (Enter to finish): ' source_ip; do
        [[ -n $source_ip ]] || break
        allowed_sources+=("$source_ip")
    done
fi

printf '\nChoose the TCP port range for the SSH honeypot.\n'
printf 'The management SSH port can be inside this range, for example 4810 within 1000-5000.\n'
read -r -p 'SSH honeypot port range [1000-5000]: ' honeypot_range || exit 0

printf '\nPreview\n'
printf '  Google account:      %s\n' "$google_email"
printf '  Management SSH port: %s\n' "${ssh_port:-22}"
printf '  Honeypot TCP ports:  %s\n' "${honeypot_range:-1000-5000}"
printf '  Allowed sources:\n'
if (( ${#allowed_sources[@]} )); then
    printf '    - %s\n' "${allowed_sources[@]}"
else
    printf '    No source-IP restriction\n'
fi
printf '\nPreview only. No settings applied.\n'
