# Successful administrative SSH activity in the app

On each installation managed through the app, deploy the updated web tree and run:

```bash
sudo bash misc/install_ssh_login_report.sh
```

The authorized active manager session is checked by managerOverview.php before activity is returned. Status / Units shows the selected installation's last 50 successful SSH authentications observed in a bounded 24-hour journal window (maximum 2,000 sshd records). Includes UTC time, account, source address and method. Partial key authentication followed by accepted password on the same process/boot/account/source/port is shown as publickey+password. Otherwise the logged final method is shown without inferring earlier factors.

This is activity history on opening/refreshing Status / Units, not background push notifications or automatic unexpected-source classification. It does not expose login details through public or shared partner status. Linked clients without manager access cannot read it.

Set /etc/tarasec/ssh-login-report.conf:

```ini
SSH_LOGIN_REPORT_ENABLED=no
```

The API immediately returns only report disabled. Run the service once to remove cached events immediately; otherwise the next timer run replaces them. This switch controls app reporting, not the system's journal/rsyslog retention. Setting yes re-enables collection. Config is preserved by the installer; no SSH/firewall settings are modified. Collection requires persistent/readable sshd journal messages and shows unavailable/stale rather than treating missing data as no logins.
