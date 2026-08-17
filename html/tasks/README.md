# TaraSec shared task board

Small shared development board for humans and automation/ChatGPT.

## Server setup

Create a private data directory and secret key outside the web root:

```bash
sudo mkdir -p /var/lib/tarasec-taskboard
sudo chown www-data:www-data /var/lib/tarasec-taskboard
sudo chmod 750 /var/lib/tarasec-taskboard

openssl rand -hex 24 | sudo tee /etc/tarasec-taskboard.key >/dev/null
sudo chown root:www-data /etc/tarasec-taskboard.key
sudo chmod 640 /etc/tarasec-taskboard.key
```

The board is publicly readable at `/tasks/`. Writes require the secret key.
Use HTTPS only.

## Write examples

Change status:

```text
/tasks/?key=SECRET&action=status&id=B4&status=Working&by=Oystein
```

Update one text field (`description`, `chatgpt`, or `oystein`):

```text
/tasks/?key=SECRET&action=set&id=B4&field=chatgpt&value=Investigating+conntrack&by=ChatGPT
```

Add a task:

```text
/tasks/?key=SECRET&action=add&id=B6&group=TaraSec+basic+system+and+AI&description=New+task&by=Oystein
```

Successful writes return exactly `ok`.

## Security notes

The key is intentionally not committed to GitHub. Because write operations use a key in the URL for simplicity, HTTPS is mandatory and the key may still appear in browser history or web-server access logs. For a stronger future version, move writes to POST with an authorization header while keeping the same task data format.
