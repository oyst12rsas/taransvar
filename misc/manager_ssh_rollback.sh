#!/usr/bin/env bash
set -euo pipefail
systemctl disable --now tarasec-manager-ssh.timer
systemctl stop tarasec-manager-ssh.service
php /usr/local/share/tarasec/manager-ssh/misc/manager_ssh_worker.php --rollback-gate
systemctl disable --now tarasec-manager-ssh-rollback.timer
echo 'Automatic rollback restored the pre-existing SSH path. Manager SSH worker is disabled.'
