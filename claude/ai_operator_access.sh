#!/bin/bash
# Grant, revoke and audit an AI operator's root access on a Linux host.
#
# The owner keeps a separate admin account. The AI account gets root only from
# one sudoers drop-in, so deleting that file (or running `revoke`) takes root
# away. `audit` then lists anything that could still give the AI access.
#
# Usage (as root, from a root-owned copy; see claude/setup.md):
#   ai_operator_access.sh [--user NAME] grant [--hours N]
#   ai_operator_access.sh [--user NAME] revoke [--lock]
#   ai_operator_access.sh [--user NAME] audit [--since EPOCH|DATE] [--deep]
#   ai_operator_access.sh [--user NAME] status
#
# Exit codes: 0 ok, 1 audit found FAIL items, 2 usage or precondition error.
#
# Test hooks (never set on a real host):
#   AI_ACCESS_ROOT     prefix for every file path (a fake root directory)
#   AI_ACCESS_DRY_RUN  1 = print system commands instead of running them
#   AI_ACCESS_CHECKS   directory with extra audit checks (default: checks.d)
set -euo pipefail

R="${AI_ACCESS_ROOT:-}"
DRY="${AI_ACCESS_DRY_RUN:-0}"
AI_USER="${AI_ACCESS_USER:-claude}"
ADMIN_GROUPS=(sudo admin wheel)
DROPIN=/etc/sudoers.d/90-ai-operator
STATE_DIR=/var/lib/ai-operator
STATE="$STATE_DIR/grant.env"
EXPIRY_UNIT=ai-operator-expiry
SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
CHECKS_DIR="${AI_ACCESS_CHECKS:-$(dirname "$SELF")/checks.d}"

die() { echo "ai-operator: $*" >&2; exit 2; }
run() { if [[ $DRY == 1 ]]; then echo "RUN: $*"; else "$@"; fi; }
p() { printf '%s%s' "$R" "$1"; }
real_host() { [[ -z $R ]]; }

# ---------------------------------------------------------------- accounts --

passwd_field() {  # user field -> value from /etc/passwd
    awk -F: -v u="$1" -v f="$2" '$1 == u { print $f; exit }' "$(p /etc/passwd)"
}
group_members() {  # group -> members, one per line (secondary and primary)
    local gid
    awk -F: -v g="$1" '$1 == g { n = split($4, m, ","); for (i = 1; i <= n; i++) if (m[i] != "") print m[i] }' "$(p /etc/group)"
    gid=$(awk -F: -v g="$1" '$1 == g { print $3; exit }' "$(p /etc/group)")
    [[ -n $gid ]] && awk -F: -v g="$gid" '$4 == g { print $1 }' "$(p /etc/passwd)"
    return 0
}
in_group() { group_members "$2" | grep -qx -- "$1"; }
password_usable() {  # an unlocked password hash means console login is possible
    local hash
    hash=$(awk -F: -v u="$1" '$1 == u { print $2; exit }' "$(p /etc/shadow)" 2>/dev/null || true)
    [[ -n $hash && $hash != [\!*]* ]]
}
owner_accounts() {  # accounts other than the AI that can become root with their own password
    local g u
    {
        password_usable root && echo root
        for g in "${ADMIN_GROUPS[@]}"; do
            while read -r u; do
                [[ -n $u && $u != "$AI_USER" ]] && password_usable "$u" && echo "$u"
            done < <(group_members "$g")
        done
    } | sort -u
}
ai_rule_pattern() {  # regex matching a sudoers line that names the AI account
    printf '(^|[[:space:],])(%s|%%%s|#%s)([[:space:],]|$)' "$AI_USER" "$AI_USER" "$(passwd_field "$AI_USER" 3)"
}
other_ai_sudoers() {  # sudoers.d files (other than the drop-in) with rules naming the AI
    local f pattern
    pattern=$(ai_rule_pattern)
    for f in "$(p /etc/sudoers.d)"/*; do
        [[ -f $f && $f != "$(p "$DROPIN")" ]] || continue
        grep -vE '^[[:space:]]*(#|$|Defaults)' "$f" | grep -qE "$pattern" && echo "$f"
    done
    return 0
}
only_ai_rules() {  # file -> true if every rule line in it names the AI account
    local pattern
    pattern=$(ai_rule_pattern)
    ! grep -vE '^[[:space:]]*(#|$|Defaults)' "$1" | grep -vqE "$pattern"
}
ai_admin_groups() {
    local g
    for g in "${ADMIN_GROUPS[@]}"; do in_group "$AI_USER" "$g" && echo "$g"; done
    return 0
}

# ------------------------------------------------------------------- state --

state_get() {  # key -> digits-only value from the state file, never sourced
    local f
    f="$(p "$STATE")"
    [[ -f $f ]] || return 0
    sed -n "s/^$1=\([0-9]*\)$/\1/p" "$f" | tail -n 1
}
state_set() {
    local f
    f="$(p "$STATE")"
    mkdir -p "$(dirname "$f")"; chmod 0700 "$(dirname "$f")"
    touch "$f"; chmod 0600 "$f"
    printf '%s=%s\n' "$1" "$2" >> "$f"
}

# ---------------------------------------------------------- trust checks --

# On a real host root must not execute files the AI account could have edited.
trusted_path() {
    local f mode uid
    f=$(readlink -f -- "$1")
    while :; do
        read -r uid mode < <(stat -c '%u %a' -- "$f")
        if [[ $uid != 0 ]] || (( 8#$mode & 8#022 )); then return 1; fi
        [[ $f == / ]] && return 0
        f=$(dirname -- "$f")
    done
}
require_root_and_trust() {
    real_host || return 0
    [[ $EUID -eq 0 ]] || die "run as root (sudo) from the owner account"
    trusted_path "$SELF" || die "$SELF is not root-owned and protected from other writers. Run a root-owned copy; see claude/setup.md (Get a trusted copy)."
}

# ------------------------------------------------------------------- grant --

cmd_grant() {
    local hours="" now owners groups g tmp
    while [[ $# -gt 0 ]]; do
        case $1 in
            --hours) [[ $# -ge 2 ]] || die "--hours needs a value"; hours=$2; shift 2 ;;
            *) die "unknown grant option: $1" ;;
        esac
    done
    if [[ -n $hours ]] && ! [[ $hours =~ ^[0-9]+$ && $hours -ge 1 && $hours -le 720 ]]; then
        die "--hours must be a whole number from 1 to 720"
    fi
    [[ -n $(passwd_field "$AI_USER" 1) ]] || die "AI account '$AI_USER' does not exist"
    [[ $(passwd_field "$AI_USER" 3) != 0 ]] || die "AI account '$AI_USER' has uid 0; use an ordinary account"

    owners=$(owner_accounts)
    [[ -n $owners ]] || die "no owner account found: another account must be in sudo/admin/wheel with its own password (or root must have a password). Create one first; see claude/setup.md."

    # Other sudo rules for the AI (cloud-init writes 90-cloud-init-users on cloud
    # VMs) would survive revoke. Check them all before changing anything.
    local others f disabled=""
    others=$(other_ai_sudoers)
    for f in $others; do
        only_ai_rules "$f" || die "${f#$R} also grants other users; remove the '$AI_USER' lines from it yourself (sudo visudo -f ${f#$R}), then run grant again. Nothing changed."
    done
    if grep -vE '^[[:space:]]*(#|$|Defaults)' "$(p /etc/sudoers)" | grep -qE "$(ai_rule_pattern)"; then
        die "/etc/sudoers has a rule for '$AI_USER'; remove it yourself (sudo visudo), then run grant again. Nothing changed."
    fi

    # Group membership would survive deleting the drop-in, so move root access into it.
    groups=$(ai_admin_groups)
    for g in $groups; do run gpasswd -d "$AI_USER" "$g"; done

    tmp=$(mktemp "$(p /etc/sudoers.d)/.ai-operator.XXXXXX")
    cat > "$tmp" <<EOF
# Managed by claude/ai_operator_access.sh. Delete this file to revoke
# the AI operator's root access: sudo rm $DROPIN
$AI_USER ALL=(ALL:ALL) NOPASSWD: ALL
EOF
    chmod 0440 "$tmp"
    if ! visudo -cqf "$tmp"; then rm -f "$tmp"; die "generated sudoers file failed validation; nothing changed"; fi
    mv -f "$tmp" "$(p "$DROPIN")"
    for f in $others; do
        mkdir -p "$(p "$STATE_DIR")/disabled-sudoers"; chmod 0700 "$(p "$STATE_DIR")" "$(p "$STATE_DIR")/disabled-sudoers"
        mv -f -- "$f" "$(p "$STATE_DIR")/disabled-sudoers/"
        disabled+=" ${f#$R}"
    done

    now=$(date +%s)
    rm -f "$(p "$STATE")"
    state_set GRANTED_AT "$now"
    [[ -n $hours ]] && state_set EXPIRES_AT $(( now + hours * 3600 ))
    real_host && run systemctl stop "$EXPIRY_UNIT.timer" >/dev/null 2>&1 || true
    if [[ -n $hours ]]; then
        run systemd-run --quiet --unit="$EXPIRY_UNIT" --on-active="${hours}h" \
            --timer-property=AccuracySec=1min "$SELF" --user "$AI_USER" revoke
    fi

    echo "Granted root to '$AI_USER' via $DROPIN."
    [[ -n $groups ]] && echo "Removed '$AI_USER' from admin group(s): $groups (the drop-in is now its only root access)."
    [[ -n $disabled ]] && echo "Moved other sudo rule file(s) for '$AI_USER' to $STATE_DIR/disabled-sudoers/:$disabled"
    [[ -n $hours ]] && echo "Expires automatically in $hours hour(s)."
    echo "Owner account(s) that keep root: $(echo "$owners" | paste -sd, -)"
    echo "Revoke at any time: sudo $SELF --user $AI_USER revoke   (or: sudo rm $DROPIN)"
}

# ------------------------------------------------------------------ revoke --

cmd_revoke() {
    local lock=false g home q item now
    while [[ $# -gt 0 ]]; do
        case $1 in
            --lock) lock=true; shift ;;
            *) die "unknown revoke option: $1" ;;
        esac
    done
    rm -f "$(p "$DROPIN")"
    for g in $(ai_admin_groups); do run gpasswd -d "$AI_USER" "$g"; done
    if real_host; then
        systemctl stop "$EXPIRY_UNIT.timer" >/dev/null 2>&1 || true
        # Ends the AI's sessions, including root shells started through sudo.
        loginctl terminate-user "$AI_USER" >/dev/null 2>&1 || true
        pkill -KILL -u "$AI_USER" >/dev/null 2>&1 || true
    else
        run loginctl terminate-user "$AI_USER"
        run pkill -KILL -u "$AI_USER"
    fi

    now=$(date +%s)
    if $lock; then
        run usermod -L "$AI_USER"
        run usermod -e 1 "$AI_USER"   # expired accounts cannot log in, even with an SSH key
        home=$(passwd_field "$AI_USER" 6)
        q="$(p "$STATE_DIR")/quarantine-$now"
        if [[ -n $home ]]; then
            for item in .ssh/authorized_keys .ssh/authorized_keys2 .claude .claude.json .config/gh .git-credentials; do
                if [[ -e "$(p "$home")/$item" || -L "$(p "$home")/$item" ]]; then
                    mkdir -p "$q/$(dirname "$item")"; chmod 0700 "$q"
                    mv -- "$(p "$home")/$item" "$q/$item"
                    echo "Quarantined $home/$item -> $STATE_DIR/quarantine-$now/$item"
                fi
            done
        fi
    fi
    state_set REVOKED_AT "$now"
    echo "Revoked root for '$AI_USER'.$($lock && echo " Account locked and expired.")"
    echo
    cmd_audit
}

# ------------------------------------------------------------------- audit --

FAILS=0
say() {  # level message
    printf '%-6s %s\n' "$1" "$2"
    [[ $1 == FAIL ]] && FAILS=$((FAILS + 1))
    return 0
}

WATCH=(
    /etc/sudoers /etc/sudoers.d /etc/passwd /etc/shadow /etc/group /etc/gshadow
    /etc/ssh /etc/pam.d /etc/security /etc/polkit-1
    /etc/crontab /etc/cron.d /etc/cron.hourly /etc/cron.daily /etc/cron.weekly /etc/cron.monthly
    /var/spool/cron /etc/at.allow /etc/at.deny
    /etc/systemd /lib/systemd/system /usr/lib/systemd/system /run/systemd/transient
    /etc/profile /etc/profile.d /etc/bash.bashrc /etc/environment
    /etc/ld.so.preload /etc/ld.so.conf.d /etc/modules-load.d /etc/modprobe.d /etc/rc.local
    /etc/apt/sources.list /etc/apt/sources.list.d /etc/apt/trusted.gpg.d /etc/apt/keyrings
    /etc/netplan /etc/iptables /etc/nftables.conf /etc/sysctl.d /etc/hosts
    /usr/local/bin /usr/local/sbin /usr/local/lib /opt /root
)

audit_sudo() {
    local active=$1 f line level groups pattern
    groups=$(ai_admin_groups)
    if [[ -n $groups ]]; then
        say FAIL "'$AI_USER' is in admin group(s) $(echo $groups) - root without the drop-in. Fix: sudo gpasswd -d $AI_USER <group>"
    fi
    pattern=$(ai_rule_pattern)
    level=FAIL; $active && level=REVIEW
    for f in "$(p /etc/sudoers)" "$(p /etc/sudoers.d)"/*; do
        [[ -f $f ]] || continue
        [[ $f == "$(p "$DROPIN")" ]] && continue
        while IFS= read -r line; do
            [[ $line =~ ^[[:space:]]*(#|$) && ! $line =~ ^[[:space:]]*[#@]include ]] && continue
            if [[ $line =~ ^[[:space:]]*[#@]include ]]; then
                [[ $line =~ [#@]includedir[[:space:]]+/etc/sudoers.d[[:space:]]*$ ]] || say REVIEW "sudoers include ${f#$R}: $line"
            elif [[ $line =~ $pattern && ! $line =~ ^[[:space:]]*Defaults ]]; then
                say "$level" "sudo rule for '$AI_USER' outside the drop-in: ${f#$R}: $line"
            elif [[ ${f#$R} == /etc/sudoers.d/* && ! $line =~ ^[[:space:]]*Defaults ]]; then
                say REVIEW "other sudo rule: ${f#$R}: $line"
            fi
        done < "$f"
    done
    if real_host && command -v sudo >/dev/null; then
        if sudo -l -U "$AI_USER" 2>/dev/null | grep -q 'not allowed to run sudo'; then
            say OK "sudo -l: '$AI_USER' may not run sudo"
        elif ! $active; then
            say FAIL "sudo -l still lists commands for '$AI_USER': $(sudo -l -U "$AI_USER" 2>/dev/null | tail -n +2 | tr -s ' \n' ' ')"
        fi
    fi
}

audit_accounts() {
    local name uid shell hash
    while IFS=: read -r name _ uid _ _ _ shell; do
        if [[ $uid == 0 && $name != root ]]; then
            say FAIL "account '$name' has uid 0 (a second root). Fix: sudo userdel $name, or change its uid"
        fi
        [[ $shell =~ (nologin|false|sync|halt|shutdown)$ ]] && continue
        if password_usable "$name"; then say INFO "login account with password: $name"; fi
    done < "$(p /etc/passwd)"
    hash=$(awk -F: -v u="$AI_USER" '$1 == u { print $2 "|" $8 }' "$(p /etc/shadow)" 2>/dev/null || true)
    [[ -n $hash ]] && say INFO "'$AI_USER' password field: $([[ ${hash%%|*} == '!'* ]] && echo locked || echo usable-or-unset), expiry day: ${hash#*|}"
}

audit_keys() {
    local active=$1 name home f n
    while IFS=: read -r name _ _ _ _ home _; do
        for f in "$(p "$home")/.ssh/authorized_keys" "$(p "$home")/.ssh/authorized_keys2"; do
            [[ -f $f ]] || continue
            n=$(grep -cvE '^[[:space:]]*(#|$)' "$f" || true)
            [[ $n == 0 ]] && continue
            if [[ $name == "$AI_USER" ]]; then
                say REVIEW "$n SSH key(s) let '$AI_USER' log in (${f#$R}). Remove them or use: revoke --lock"
            elif [[ $name == root ]]; then
                say REVIEW "$n SSH key(s) let root log in (${f#$R}); confirm each is yours"
            else
                say INFO "$n SSH key(s) for '$name' (${f#$R})"
            fi
            if command -v ssh-keygen >/dev/null; then
                ssh-keygen -lf "$f" 2>/dev/null | sed "s/^/       /" || true
            fi
        done
    done < "$(p /etc/passwd)"
    local cfg
    for cfg in "$(p /etc/ssh/sshd_config)" "$(p /etc/ssh/sshd_config.d)"/*.conf; do
        [[ -f $cfg ]] || continue
        grep -iE '^[[:space:]]*(AuthorizedKeysCommand|TrustedUserCAKeys|AuthorizedPrincipalsCommand|AuthorizedKeysFile)[[:space:]]' "$cfg" 2>/dev/null |
            grep -viE '[[:space:]](none|\.ssh/authorized_keys( \.ssh/authorized_keys2)?)[[:space:]]*$' |
            while IFS= read -r line; do say REVIEW "sshd key source ${cfg#$R}: $line"; done || true
    done
}

audit_changes() {
    local since=$1 path n
    [[ -n $since ]] || { say INFO "no grant time recorded; pass --since to list files changed during the AI's access"; return; }
    say INFO "files changed since $(date -d "@$since" '+%F %T %Z') (expected during legitimate work; confirm each):"
    for path in "${WATCH[@]}"; do
        [[ -e "$(p "$path")" ]] || continue
        n=0
        while IFS= read -r f; do
            n=$((n + 1))
            (( n <= 40 )) && say REVIEW "changed: ${f#$R}"
        done < <(find "$(p "$path")" -xdev -newermt "@$since" \( -type f -o -type l \) \
                    ! -path "$(p "$STATE_DIR")/*" ! -path "$(p /root/.cache)/*" 2>/dev/null)
        (( n > 40 )) && say REVIEW "... and $((n - 40)) more under $path"
    done
    return 0
}

audit_processes() {
    local active=$1 uid
    real_host || return 0
    uid=$(passwd_field "$AI_USER" 3)
    if pgrep -u "$AI_USER" >/dev/null 2>&1; then
        say "$($active && echo INFO || echo REVIEW)" "processes running as '$AI_USER': $(pgrep -u "$AI_USER" | wc -l)"
    fi
    # Root processes left in the AI's login sessions (started through sudo).
    local leftovers
    leftovers=$(ps -eo pid=,user=,cgroup=,args= 2>/dev/null | awk -v s="user-$uid.slice" '$2 == "root" && index($3, s) { $3 = ""; print }' | head -20)
    if [[ -n $leftovers ]] && ! $active; then
        while IFS= read -r line; do say FAIL "root process in '$AI_USER' session: $line"; done <<< "$leftovers"
    fi
    if command -v systemctl >/dev/null; then
        systemctl list-units --all --no-legend --plain --type=service,timer,scope 'run-*' 2>/dev/null | awk '{print $1}' |
            while read -r unit; do say REVIEW "transient systemd unit (systemd-run): $unit"; done || true
    fi
}

audit_packages() {
    real_host || return 0
    command -v dpkg >/dev/null || { say INFO "dpkg not available; package integrity not checked"; return; }
    say INFO "checking installed package files against dpkg checksums (--deep)..."
    dpkg --verify 2>/dev/null | awk '$2 != "c"' | head -40 |
        while IFS= read -r line; do say REVIEW "package file differs: $line"; done || true
}

audit_plugins() {
    local active=$1 since=$2 f
    [[ -d $CHECKS_DIR ]] || return 0
    if real_host && ! trusted_path "$CHECKS_DIR"; then
        say FAIL "extra checks in $CHECKS_DIR skipped: directory is writable by non-root"
        return 0
    fi
    for f in "$CHECKS_DIR"/*.sh; do
        [[ -f $f ]] || continue
        if real_host && ! trusted_path "$f"; then say FAIL "check $f skipped: writable by non-root"; continue; fi
        while IFS= read -r line; do
            say "${line%% *}" "$(sed 's/^[A-Z]*[[:space:]]*//' <<< "$line")"
        done < <(AI_ACCESS_ROOT="$R" AI_USER="$AI_USER" SINCE="$since" GRANT_ACTIVE="$active" bash "$f")
    done
}

cmd_audit() {
    local since="" deep=false active=false
    while [[ $# -gt 0 ]]; do
        case $1 in
            --since) [[ $# -ge 2 ]] || die "--since needs a value"
                     if [[ $2 =~ ^[0-9]+$ ]]; then since=$2; else since=$(date -d "$2" +%s) || die "bad --since date"; fi
                     shift 2 ;;
            --deep) deep=true; shift ;;
            *) die "unknown audit option: $1" ;;
        esac
    done
    [[ -n $since ]] || since=$(state_get GRANTED_AT)
    [[ -f "$(p "$DROPIN")" ]] && active=true
    echo "== AI operator audit for '$AI_USER' on $(hostname 2>/dev/null || echo host) =="
    if $active; then say INFO "grant ACTIVE ($DROPIN present): '$AI_USER' has root"
    else say OK "no grant drop-in: $DROPIN absent"; fi
    local owners
    owners=$(owner_accounts)
    if [[ -n $owners ]]; then say OK "owner account(s) with root and a password: $(echo "$owners" | paste -sd, -)"
    else say FAIL "no owner account with root and a password; you may be locked out of admin rights"; fi
    audit_sudo "$active"
    audit_accounts
    audit_keys "$active"
    audit_processes "$active"
    audit_changes "$since"
    $deep && audit_packages
    audit_plugins "$active" "$since"
    echo "== $FAILS FAIL item(s). FAIL = AI may still have access; REVIEW = confirm it is yours. =="
    (( FAILS == 0 ))
}

# ------------------------------------------------------------------ status --

cmd_status() {
    local granted expires
    granted=$(state_get GRANTED_AT); expires=$(state_get EXPIRES_AT)
    if [[ -f "$(p "$DROPIN")" ]]; then
        echo "Grant: ACTIVE ($DROPIN)"
        [[ -n $granted ]] && echo "Granted: $(date -d "@$granted" '+%F %T %Z')"
        [[ -n $expires ]] && echo "Expires: $(date -d "@$expires" '+%F %T %Z')"
    else
        echo "Grant: none"
    fi
    echo "AI account: $AI_USER (admin groups: $(ai_admin_groups | paste -sd, - | sed 's/^$/none/'))"
    echo "Owner account(s): $(owner_accounts | paste -sd, - | sed 's/^$/NONE - create one before granting/')"
}

# -------------------------------------------------------------------- main --

while [[ $# -gt 0 && $1 == --* ]]; do
    case $1 in
        --user) [[ $# -ge 2 ]] || die "--user needs a value"; AI_USER=$2; shift 2 ;;
        -h|--help) sed -n '2,20p' "$SELF"; exit 0 ;;
        *) die "unknown option: $1" ;;
    esac
done
[[ $AI_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || die "invalid account name: $AI_USER"
[[ $AI_USER != root ]] || die "the AI account cannot be root"
cmd=${1:-}; shift || true
case $cmd in
    grant)  require_root_and_trust; cmd_grant "$@" ;;
    revoke) require_root_and_trust; cmd_revoke "$@" ;;
    audit)  require_root_and_trust; cmd_audit "$@" ;;
    status) require_root_and_trust; cmd_status "$@" ;;
    *) sed -n '2,20p' "$SELF"; exit 2 ;;
esac
